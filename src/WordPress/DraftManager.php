<?php

namespace ContentFactory\WordPress;

use ContentFactory\Adapter\AdapterRegistry;
use ContentFactory\Log\OperationLogger;
use ContentFactory\Service\ContentPipeline;

defined( 'ABSPATH' ) || exit;

final class DraftManager {
	private const META_KEYS = array(
		'_content_factory_source_id', '_content_factory_schema_version', '_content_factory_profile_id',
		'_content_factory_profile_version', '_content_factory_manifest_hash', '_content_factory_source_hash',
		'_content_factory_content_hash', '_content_factory_validation_hash', '_content_factory_validation_status',
		'_content_factory_generated_at', '_content_factory_validated_at', '_content_factory_import_user_id',
		'_content_factory_source_spec', '_content_factory_warning_count', '_yoast_wpseo_title', '_yoast_wpseo_metadesc',
		'_wp_page_template',
	);

	private static bool $internal_save = false;

	public function __construct(
		private ContentPipeline $pipeline,
		private AdapterRegistry $adapters,
		private HashManager $hashes,
		private YoastAdapter $yoast,
		private ?OperationLogger $logger = null
	) {}

	public static function is_internal_save(): bool {
		return self::$internal_save;
	}

	public static function set_internal_save( bool $value ): void {
		self::$internal_save = $value;
	}

	public function import( array $spec, array $context = array() ): array|\WP_Error {
		if ( ! current_user_can( 'content_factory_import_pages' ) ) {
			return new \WP_Error( 'forbidden', 'Недостаточно прав для импорта.', array( 'status' => 403 ) );
		}
		if ( ! $this->yoast->available() ) {
			return new \WP_Error( 'yoast_unavailable', 'Yoast SEO должен быть активирован перед импортом.', array( 'status' => 409 ) );
		}
		$report = $this->pipeline->process( $spec, $context );
		if ( $report->has_errors() ) {
			$conflict = $report->context()['conflict'] ?? null;
			if ( is_array( $conflict ) ) {
				return new \WP_Error( ( $conflict['type'] ?? '' ) . '_conflict', 'WordPress page конфликтует с импортом.', array_merge( array( 'status' => 409, 'report' => $report ), $conflict ) );
			}
			return new \WP_Error( 'incompatible_pagespec', 'PageSpec несовместим.', array( 'status' => 422, 'report' => $report ) );
		}
		$spec    = $report->context()['normalizedSpec'] ?? $spec;
		$adapter = $this->adapters->active();
		$ctx     = $report->context();
		$content = (string) ( $ctx['postContent'] ?? '' );
		$parent  = (int) ( $ctx['resolved']['parentId'] ?? 0 );
		$source_hash = $this->hashes->source_hash( $spec );
		$existing = $this->find_by_source_id( $spec['sourceId'] );
		$old_source_hash  = $existing ? (string) get_post_meta( $existing->ID, '_content_factory_source_hash', true ) : '';
		$old_content_hash = $existing ? (string) get_post_meta( $existing->ID, '_content_factory_content_hash', true ) : '';
		if ( $existing && 'publish' === $existing->post_status ) {
			return new \WP_Error( 'published_conflict', 'Опубликованная managed page не может быть перезаписана.', array( 'status' => 409, 'postId' => $existing->ID, 'editLink' => get_edit_post_link( $existing->ID, 'raw' ) ) );
		}
		if ( $existing && $source_hash === get_post_meta( $existing->ID, '_content_factory_source_hash', true ) && $this->matches_validated_source( $existing, $spec, $ctx, $adapter ) ) {
			return $this->result( 'no_change', $existing->ID, $report, array( 'old_source_hash' => $old_source_hash, 'new_source_hash' => $source_hash, 'old_content_hash' => $old_content_hash, 'new_content_hash' => $old_content_hash ) );
		}
		$conflict = get_page_by_path( trim( $ctx['resolved']['expectedPath'] ?? '', '/' ), OBJECT, 'page' );
		if ( $conflict && ( ! $existing || (int) $existing->ID !== (int) $conflict->ID ) ) {
			return new \WP_Error( 'path_conflict', 'Итоговый hierarchical path уже занят.', array( 'status' => 409, 'postId' => $conflict->ID, 'editLink' => get_edit_post_link( $conflict->ID, 'raw' ) ) );
		}

		$post_data = array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => sanitize_text_field( $spec['post']['title'] ),
			'post_name'    => sanitize_title( $spec['post']['slug'] ),
			'post_parent'  => $parent,
			'post_content' => $content,
			'page_template'=> $adapter->manifest()['postDefaults']['pageTemplate'],
		);
		$snapshot = $existing ? $this->snapshot( $existing->ID ) : null;
		if ( $existing ) {
			$post_data['ID'] = $existing->ID;
		}
		try {
			self::$internal_save = true;
			$post_id = wp_insert_post( wp_slash( $post_data ), true );
		} catch ( \Throwable $error ) {
			$rollback = $this->rollback_interrupted_write( $snapshot, (string) ( $ctx['resolved']['expectedPath'] ?? '' ), $post_data );
			$post_id = new \WP_Error( 'post_write_failed', $error->getMessage(), array( 'status' => 500, 'rollback' => $rollback ) );
		} finally {
			self::$internal_save = false;
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$action = $existing ? 'updated' : 'created';
		try {
			$yoast_result = $this->yoast->save( $post_id, $spec['seo'] );
			if ( is_wp_error( $yoast_result ) ) {
				throw new \RuntimeException( $yoast_result->get_error_message() );
			}
			$content_hash = $this->hashes->content_hash( $content );
			$validation_hash = $this->hashes->validation_hash(
				array(
					'title' => $post_data['post_title'], 'slug' => $post_data['post_name'], 'parentId' => $parent,
					'template' => $post_data['page_template'], 'content' => $content, 'seoTitle' => $spec['seo']['title'],
					'seoDescription' => $spec['seo']['description'], 'profileId' => $adapter->id(),
					'profileVersion' => $adapter->version(), 'siteDefaultsVersion' => $adapter->manifest()['siteDefaultsVersion'] ?? '1',
				)
			);
			$now = gmdate( 'c' );
			$meta = array(
				'_content_factory_source_id' => $spec['sourceId'],
				'_content_factory_schema_version' => $spec['schemaVersion'],
				'_content_factory_profile_id' => $adapter->id(),
				'_content_factory_profile_version' => $adapter->version(),
				'_content_factory_manifest_hash' => $adapter->manifest_hash(),
				'_content_factory_source_hash' => $source_hash,
				'_content_factory_content_hash' => $content_hash,
				'_content_factory_validation_hash' => $validation_hash,
				'_content_factory_validation_status' => 'valid',
				'_content_factory_generated_at' => $now,
				'_content_factory_validated_at' => $now,
				'_content_factory_import_user_id' => get_current_user_id(),
				'_content_factory_source_spec' => wp_json_encode( $spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'_content_factory_warning_count' => count( array_filter( $report->issues(), static fn( $issue ): bool => 'warning' === $issue->severity() ) ),
			);
			foreach ( $meta as $key => $value ) {
				$write_value = '_content_factory_source_spec' === $key ? wp_slash( $value ) : $value;
				if ( false === update_post_meta( $post_id, $key, $write_value ) && (string) get_post_meta( $post_id, $key, true ) !== (string) $value ) {
					throw new \RuntimeException( 'Не удалось сохранить metadata: ' . $key );
				}
			}
			$audit = array(
				'old_source_hash' => $old_source_hash,
				'new_source_hash' => $source_hash,
				'old_content_hash' => $old_content_hash,
				'new_content_hash' => $content_hash,
				'resolved_parent_id' => $parent,
				'resolved_path' => (string) ( $ctx['resolved']['expectedPath'] ?? '' ),
				'yoast_result' => $yoast_result,
				'created_at' => $existing ? null : current_time( 'mysql', true ),
				'updated_at' => $existing ? current_time( 'mysql', true ) : null,
			);
			return $this->result( $action, $post_id, $report, $audit );
		} catch ( \Throwable $error ) {
			$rollback = false;
			try {
				self::$internal_save = true;
				$rollback = $snapshot ? $this->restore( $snapshot ) : ( false !== wp_delete_post( $post_id, true ) );
			} finally {
				self::$internal_save = false;
			}
			return new \WP_Error( 'import_rolled_back', $error->getMessage(), array( 'status' => 500, 'rollback' => $rollback ) );
		}
	}

	public function find_by_source_id( string $source_id ): ?\WP_Post {
		$posts = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'draft', 'publish', 'pending', 'private' ), 'meta_key' => '_content_factory_source_id', 'meta_value' => $source_id, 'posts_per_page' => 1 ) );
		return $posts[0] ?? null;
	}

	public function capture_state( string $source_id ): ?array {
		$post = $this->find_by_source_id( $source_id );
		return $post ? $this->snapshot( $post->ID ) : null;
	}

	public function rollback_to_state( ?array $state, int $post_id ): bool {
		try {
			self::$internal_save = true;
			if ( $state ) {
				return $this->restore( $state );
			}
			try {
				wp_delete_post( $post_id, true );
			} catch ( \Throwable ) {
				// A hook may throw after deletion; verify the database state below.
			}
			return null === get_post( $post_id );
		} finally {
			self::$internal_save = false;
		}
	}

	private function result( string $action, int $post_id, $report, array $audit = array() ): array {
		return array_merge( array( 'action' => $action, 'sourceId' => get_post_meta( $post_id, '_content_factory_source_id', true ), 'postId' => $post_id, 'status' => get_post_status( $post_id ), 'editLink' => get_edit_post_link( $post_id, 'raw' ), 'previewLink' => get_preview_post_link( $post_id ), 'report' => $report, 'issues' => $report->issues(), 'migrations' => $report->context()['migrations'] ?? array(), 'defaults_applied' => $report->context()['defaultsApplied'] ?? array() ), $audit );
	}

	private function snapshot( int $post_id ): array {
		$post = get_post( $post_id, ARRAY_A );
		$meta = array();
		foreach ( self::META_KEYS as $key ) {
			$meta[ $key ] = get_post_meta( $post_id, $key, false );
		}
		return array( 'post' => $post, 'meta' => $meta );
	}

	private function restore( array $snapshot ): bool {
		try {
			$restored = wp_update_post( wp_slash( $snapshot['post'] ), true );
		} catch ( \Throwable ) {
			$restored = 0;
		}
		if ( is_wp_error( $restored ) || ! $this->post_matches_snapshot( (int) $snapshot['post']['ID'], $snapshot['post'] ) ) {
			return false;
		}
		$post_id = (int) $snapshot['post']['ID'];
		foreach ( self::META_KEYS as $key ) {
			delete_post_meta( $post_id, $key );
			foreach ( $snapshot['meta'][ $key ] ?? array() as $value ) {
				$restored_value = maybe_unserialize( $value );
				if ( '_content_factory_source_spec' === $key && is_string( $restored_value ) ) {
					$restored_value = wp_slash( $restored_value );
				}
				if ( false === add_post_meta( $post_id, $key, $restored_value ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private function post_matches_snapshot( int $post_id, array $snapshot ): bool {
		$current = get_post( $post_id, ARRAY_A );
		if ( ! is_array( $current ) ) {
			return false;
		}
		foreach ( array( 'post_title', 'post_name', 'post_parent', 'post_content', 'post_status', 'page_template' ) as $field ) {
			if ( 'page_template' === $field ) {
				continue;
			}
			if ( (string) ( $current[ $field ] ?? '' ) !== (string) ( $snapshot[ $field ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	private function rollback_interrupted_write( ?array $snapshot, string $expected_path, array $post_data ): bool {
		if ( $snapshot ) {
			return $this->restore( $snapshot );
		}
		$candidate = get_page_by_path( trim( $expected_path, '/' ), OBJECT, 'page' );
		if ( ! $candidate ) {
			return true;
		}
		$matches_attempt = 'draft' === $candidate->post_status
			&& (string) $post_data['post_name'] === $candidate->post_name
			&& (string) $post_data['post_title'] === $candidate->post_title;
		if ( ! $matches_attempt ) {
			return false;
		}
		try {
			wp_delete_post( $candidate->ID, true );
		} catch ( \Throwable ) {
			// A delete hook may throw after the database mutation.
		}
		return null === get_post( $candidate->ID );
	}

	private function matches_validated_source( \WP_Post $post, array $spec, array $context, $adapter ): bool {
		$planned_content = (string) ( $context['postContent'] ?? '' );
		$parent_id = (int) ( $context['resolved']['parentId'] ?? 0 );
		$template = (string) ( $adapter->manifest()['postDefaults']['pageTemplate'] ?? '' );
		return 'valid' === get_post_meta( $post->ID, '_content_factory_validation_status', true )
			&& $planned_content === $post->post_content
			&& sanitize_text_field( $spec['post']['title'] ) === $post->post_title
			&& sanitize_title( $spec['post']['slug'] ) === $post->post_name
			&& $parent_id === (int) $post->post_parent
			&& $template === get_page_template_slug( $post->ID )
			&& sanitize_text_field( $spec['seo']['title'] ) === get_post_meta( $post->ID, '_yoast_wpseo_title', true )
			&& sanitize_textarea_field( $spec['seo']['description'] ) === get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true )
			&& $this->hashes->content_hash( $post->post_content ) === get_post_meta( $post->ID, '_content_factory_content_hash', true );
	}
}
