<?php

namespace ContentFactory\Log;

use ContentFactory\VersionRegistry;

defined( 'ABSPATH' ) || exit;

final class OperationLogger {
	private const DB_VERSION             = VersionRegistry::OPERATION_LOG_DB;
	private const DEFAULT_RETENTION_DAYS = 90;
	private const MAX_JSON_BYTES         = 262144;

	private \wpdb $database;
	private string $operations_table;
	private string $pages_table;

	public function __construct( ?\wpdb $database = null ) {
		global $wpdb;

		$this->database         = $database ?? $wpdb;
		$this->operations_table = $this->database->prefix . 'content_factory_operations';
		$this->pages_table      = $this->database->prefix . 'content_factory_operation_pages';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$operations      = $wpdb->prefix . 'content_factory_operations';
		$pages           = $wpdb->prefix . 'content_factory_operation_pages';

		$sql = "CREATE TABLE {$operations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_id varchar(64) NOT NULL,
			action varchar(64) NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			auth_source varchar(32) NOT NULL DEFAULT '',
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			batch_id varchar(160) NOT NULL DEFAULT '',
			profile_id varchar(100) NOT NULL DEFAULT '',
			profile_version varchar(64) NOT NULL DEFAULT '',
			manifest_hash char(64) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'running',
			result_counts_json longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_id (operation_id),
			KEY status_started (status, started_at),
			KEY batch_id (batch_id),
			KEY actor_user_id (actor_user_id)
		) {$charset_collate};

		CREATE TABLE {$pages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			operation_id varchar(64) NOT NULL,
			source_id varchar(160) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(64) NOT NULL DEFAULT '',
			result varchar(32) NOT NULL DEFAULT '',
			old_source_hash char(64) NOT NULL DEFAULT '',
			new_source_hash char(64) NOT NULL DEFAULT '',
			old_content_hash char(64) NOT NULL DEFAULT '',
			new_content_hash char(64) NOT NULL DEFAULT '',
			compatibility_status varchar(32) NOT NULL DEFAULT '',
			issues_json longtext NULL,
			defaults_json longtext NULL,
			resolved_parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resolved_path varchar(1000) NOT NULL DEFAULT '',
			yoast_result_json longtext NULL,
			rollback_result_json longtext NULL,
			created_at datetime NULL,
			updated_at datetime NULL,
			published_at datetime NULL,
			logged_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY operation_id (operation_id),
			KEY source_id (source_id),
			KEY post_id (post_id),
			KEY result (result),
			KEY logged_at (logged_at)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'content_factory_db_version', self::DB_VERSION, false );
		if ( false === get_option( 'content_factory_log_retention_days', false ) ) {
			add_option( 'content_factory_log_retention_days', self::DEFAULT_RETENTION_DAYS, '', false );
		}
	}

	/**
	 * @param array<string,mixed> $context Operation metadata, never a PageSpec or request payload.
	 */
	public function start( string $action, array $context = array() ): string|\WP_Error {
		$action = $this->clean_key( $action, 64 );
		if ( '' === $action ) {
			return new \WP_Error( 'content_factory_log_invalid_action', 'Operation action is required.' );
		}

		$operation_id = wp_generate_uuid4();
		$inserted     = $this->database->insert(
			$this->operations_table,
			array(
				'operation_id'       => $operation_id,
				'action'             => $action,
				'actor_user_id'      => absint( $context['actor_user_id'] ?? get_current_user_id() ),
				'auth_source'        => $this->clean_key( (string) ( $context['auth_source'] ?? 'wordpress' ), 32 ),
				'started_at'         => current_time( 'mysql', true ),
				'batch_id'           => $this->clean_text( (string) ( $context['batch_id'] ?? '' ), 160 ),
				'profile_id'         => $this->clean_text( (string) ( $context['profile_id'] ?? '' ), 100 ),
				'profile_version'    => $this->clean_text( (string) ( $context['profile_version'] ?? '' ), 64 ),
				'manifest_hash'      => $this->clean_hash( (string) ( $context['manifest_hash'] ?? '' ) ),
				'status'             => 'running',
				'result_counts_json' => $this->encode_json( array() ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'content_factory_log_start_failed', 'Could not create the operation log.', $this->database->last_error );
		}

		return $operation_id;
	}

	/** @param array<string,int|numeric-string> $result_counts */
	public function finish( string $operation_id, string $status, array $result_counts = array() ): bool|\WP_Error {
		$operation_id = $this->clean_operation_id( $operation_id );
		$status       = $this->clean_key( $status, 32 );
		if ( '' === $operation_id || '' === $status ) {
			return new \WP_Error( 'content_factory_log_invalid_finish', 'Operation ID and status are required.' );
		}

		$updated = $this->database->update(
			$this->operations_table,
			array(
				'finished_at'         => current_time( 'mysql', true ),
				'status'              => $status,
				'result_counts_json'  => $this->encode_json( $this->sanitize_counts( $result_counts ) ),
			),
			array( 'operation_id' => $operation_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'content_factory_log_finish_failed', 'Could not finish the operation log.', $this->database->last_error );
		}
		if ( 0 === $updated ) {
			$exists = $this->database->get_var(
				$this->database->prepare(
					"SELECT operation_id FROM {$this->operations_table} WHERE operation_id = %s LIMIT 1",
					$operation_id
				)
			);
			if ( ! is_string( $exists ) ) {
				return new \WP_Error( 'content_factory_log_not_found', 'Operation log not found.' );
			}
		}

		return true;
	}

	/** @param array<string,mixed> $entry */
	public function log_page( string $operation_id, array $entry ): int|\WP_Error {
		$operation_id = $this->clean_operation_id( $operation_id );
		if ( '' === $operation_id ) {
			return new \WP_Error( 'content_factory_log_invalid_operation_id', 'A valid operation ID is required.' );
		}

		$data = array(
			'operation_id'          => $operation_id,
			'source_id'             => $this->clean_text( (string) ( $entry['source_id'] ?? $entry['sourceId'] ?? '' ), 160 ),
			'post_id'               => absint( $entry['post_id'] ?? $entry['postId'] ?? 0 ),
			'action'                => $this->clean_key( (string) ( $entry['action'] ?? '' ), 64 ),
			'result'                => $this->clean_key( (string) ( $entry['result'] ?? '' ), 32 ),
			'old_source_hash'       => $this->clean_hash( (string) ( $entry['old_source_hash'] ?? '' ) ),
			'new_source_hash'       => $this->clean_hash( (string) ( $entry['new_source_hash'] ?? '' ) ),
			'old_content_hash'      => $this->clean_hash( (string) ( $entry['old_content_hash'] ?? '' ) ),
			'new_content_hash'      => $this->clean_hash( (string) ( $entry['new_content_hash'] ?? '' ) ),
			'compatibility_status'  => $this->clean_key( (string) ( $entry['compatibility_status'] ?? '' ), 32 ),
			'issues_json'           => $this->encode_json( $entry['issues'] ?? array() ),
			'defaults_json'         => $this->encode_json( $entry['defaults_applied'] ?? $entry['defaults'] ?? array() ),
			'resolved_parent_id'    => absint( $entry['resolved_parent_id'] ?? 0 ),
			'resolved_path'         => $this->clean_text( (string) ( $entry['resolved_path'] ?? '' ), 1000 ),
			'yoast_result_json'     => $this->encode_json( $entry['yoast_result'] ?? array() ),
			'rollback_result_json'  => $this->encode_json( $entry['rollback_result'] ?? array() ),
			'created_at'            => $this->clean_datetime( $entry['created_at'] ?? null ),
			'updated_at'            => $this->clean_datetime( $entry['updated_at'] ?? null ),
			'published_at'          => $this->clean_datetime( $entry['published_at'] ?? null ),
			'logged_at'             => current_time( 'mysql', true ),
		);

		$inserted = $this->database->insert(
			$this->pages_table,
			$data,
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'content_factory_log_page_failed', 'Could not create the page log.', $this->database->last_error );
		}

		return (int) $this->database->insert_id;
	}

	/** @return array<string,mixed>|null */
	public function get( string $operation_id ): ?array {
		$operation_id = $this->clean_operation_id( $operation_id );
		if ( '' === $operation_id ) {
			return null;
		}

		$operation = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM {$this->operations_table} WHERE operation_id = %s LIMIT 1",
				$operation_id
			),
			ARRAY_A
		);
		if ( ! is_array( $operation ) ) {
			return null;
		}

		$pages = $this->database->get_results(
			$this->database->prepare(
				"SELECT id, operation_id, source_id, post_id, action, result, old_source_hash, new_source_hash, old_content_hash, new_content_hash, compatibility_status, issues_json, defaults_json, resolved_parent_id, resolved_path, yoast_result_json, rollback_result_json, created_at, updated_at, published_at, logged_at FROM {$this->pages_table} WHERE operation_id = %s ORDER BY id ASC",
				$operation_id
			),
			ARRAY_A
		);

		$operation          = $this->hydrate_operation( $operation );
		$operation['pages'] = array_map( array( $this, 'hydrate_page' ), is_array( $pages ) ? $pages : array() );

		return $operation;
	}

	/**
	 * Filters: operation_id, action, actor_user_id, batch_id, profile_id, status,
	 * source_id/sourceId, post_id/postId, result, date_from, date_to, limit, offset.
	 *
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function list( array $filters = array() ): array {
		$where  = array( '1=1' );
		$params = array();
		$join   = '';

		$operation_filters = array(
			'operation_id' => array( 'o.operation_id', 64, false ),
			'action'       => array( 'o.action', 64, true ),
			'batch_id'     => array( 'o.batch_id', 160, false ),
			'profile_id'   => array( 'o.profile_id', 100, false ),
			'status'       => array( 'o.status', 32, true ),
		);
		foreach ( $operation_filters as $key => [ $column, $length, $is_key ] ) {
			if ( ! isset( $filters[ $key ] ) || '' === (string) $filters[ $key ] ) {
				continue;
			}
			$value    = $is_key ? $this->clean_key( (string) $filters[ $key ], $length ) : $this->clean_text( (string) $filters[ $key ], $length );
			$where[]  = "{$column} = %s";
			$params[] = $value;
		}

		if ( isset( $filters['actor_user_id'] ) ) {
			$where[]  = 'o.actor_user_id = %d';
			$params[] = absint( $filters['actor_user_id'] );
		}

		$source_id = $filters['source_id'] ?? $filters['sourceId'] ?? '';
		$post_id   = $filters['post_id'] ?? $filters['postId'] ?? null;
		if ( '' !== (string) $source_id || null !== $post_id || ! empty( $filters['result'] ) ) {
			$join = " INNER JOIN {$this->pages_table} p ON p.operation_id = o.operation_id";
			if ( '' !== (string) $source_id ) {
				$where[]  = 'p.source_id = %s';
				$params[] = $this->clean_text( (string) $source_id, 160 );
			}
			if ( null !== $post_id ) {
				$where[]  = 'p.post_id = %d';
				$params[] = absint( $post_id );
			}
			if ( ! empty( $filters['result'] ) ) {
				$where[]  = 'p.result = %s';
				$params[] = $this->clean_key( (string) $filters['result'], 32 );
			}
		}

		foreach ( array( 'date_from' => '>=', 'date_to' => '<=' ) as $key => $operator ) {
			$date = $this->clean_datetime( $filters[ $key ] ?? null );
			if ( null !== $date ) {
				$where[]  = "o.started_at {$operator} %s";
				$params[] = $date;
			}
		}

		$limit  = min( 200, max( 1, absint( $filters['limit'] ?? 50 ) ) );
		$offset = max( 0, absint( $filters['offset'] ?? 0 ) );
		$sql    = "SELECT DISTINCT o.* FROM {$this->operations_table} o{$join} WHERE " . implode( ' AND ', $where ) . ' ORDER BY o.started_at DESC, o.id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		$rows = $this->database->get_results( $this->database->prepare( $sql, ...$params ), ARRAY_A );

		return array_map( array( $this, 'hydrate_operation' ), is_array( $rows ) ? $rows : array() );
	}

	public function cleanup( ?int $retention_days = null ): int|\WP_Error {
		if ( ! wp_doing_cron() && ! current_user_can( 'content_factory_import_pages' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'content_factory_log_cleanup_forbidden', 'You are not allowed to clear Content Factory logs.' );
		}

		$days   = $retention_days ?? absint( get_option( 'content_factory_log_retention_days', self::DEFAULT_RETENTION_DAYS ) );
		$days   = min( 3650, max( 1, $days ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );

		$deleted_pages = $this->database->query(
			$this->database->prepare(
				"DELETE p FROM {$this->pages_table} p INNER JOIN {$this->operations_table} o ON o.operation_id = p.operation_id WHERE COALESCE(o.finished_at, o.started_at) < %s",
				$cutoff
			)
		);
		if ( false === $deleted_pages ) {
			return new \WP_Error( 'content_factory_log_cleanup_failed', 'Could not delete expired page logs.', $this->database->last_error );
		}

		$deleted_operations = $this->database->query(
			$this->database->prepare(
				"DELETE FROM {$this->operations_table} WHERE COALESCE(finished_at, started_at) < %s",
				$cutoff
			)
		);
		if ( false === $deleted_operations ) {
			return new \WP_Error( 'content_factory_log_cleanup_failed', 'Could not delete expired operation logs.', $this->database->last_error );
		}

		return (int) $deleted_pages + (int) $deleted_operations;
	}

	/** @param array<string,mixed> $row */
	private function hydrate_operation( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['actor_user_id']  = (int) $row['actor_user_id'];
		$row['result_counts']  = $this->decode_json( $row['result_counts_json'] ?? null );
		unset( $row['result_counts_json'] );

		return $row;
	}

	/** @param array<string,mixed> $row */
	private function hydrate_page( array $row ): array {
		$row['id']                 = (int) $row['id'];
		$row['post_id']            = (int) $row['post_id'];
		$row['resolved_parent_id'] = (int) $row['resolved_parent_id'];
		$json_fields               = array(
			'issues_json'          => 'issues',
			'defaults_json'        => 'defaults_applied',
			'yoast_result_json'    => 'yoast_result',
			'rollback_result_json' => 'rollback_result',
		);
		foreach ( $json_fields as $stored => $public ) {
			$row[ $public ] = $this->decode_json( $row[ $stored ] ?? null );
			unset( $row[ $stored ] );
		}
		return $row;
	}

	private function clean_operation_id( string $value ): string {
		$value = substr( sanitize_text_field( $value ), 0, 64 );
		return preg_match( '/^[A-Za-z0-9._:-]+$/', $value ) ? $value : '';
	}

	private function clean_key( string $value, int $length ): string {
		return substr( sanitize_key( $value ), 0, $length );
	}

	private function clean_text( string $value, int $length ): string {
		return mb_substr( sanitize_text_field( $value ), 0, $length );
	}

	private function clean_hash( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( str_starts_with( $value, 'sha256:' ) ) {
			$value = substr( $value, 7 );
		}
		return preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private function clean_datetime( mixed $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value ? $value : null;
	}

	/** @param array<string,int|numeric-string> $counts */
	private function sanitize_counts( array $counts ): array {
		$clean = array();
		foreach ( $counts as $key => $value ) {
			$key = $this->clean_key( (string) $key, 64 );
			if ( '' !== $key && is_numeric( $value ) ) {
				$clean[ $key ] = max( 0, (int) $value );
			}
		}
		return $clean;
	}

	private function encode_json( mixed $value ): string {
		$value = $this->redact_sensitive_values( $value );
		$json  = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return '{"loggingError":"json_encode_failed"}';
		}
		if ( strlen( $json ) > self::MAX_JSON_BYTES ) {
			return '{"truncated":true,"reason":"structured_log_limit"}';
		}
		return $json;
	}

	private function decode_json( mixed $value ): mixed {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : array();
	}

	private function redact_sensitive_values( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) || null === $value ? $value : '[unsupported]';
		}

		$redacted = array();
		foreach ( $value as $key => $child ) {
			if ( is_string( $key ) && preg_match( '/(?:authorization|cookie|nonce|pass(?:word)?|secret|token|api[_-]?key|application[_-]?password|payload|raw[_-]?(?:body|request))/i', $key ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}
			$redacted[ $key ] = $this->redact_sensitive_values( $child );
		}
		return $redacted;
	}
}
