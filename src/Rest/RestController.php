<?php

namespace ContentFactory\Rest;

use ContentFactory\Adapter\AdapterRegistry;
use ContentFactory\Adapter\ThemeAdapterInterface;
use ContentFactory\Contract\ContractBundleBuilder;
use ContentFactory\Import\BatchRunner;
use ContentFactory\Import\JsonImporter;
use ContentFactory\Import\ZipImporter;
use ContentFactory\Log\OperationLogger;
use ContentFactory\Profile\CompiledProfile;
use ContentFactory\Profile\ProfileSelector;
use ContentFactory\Service\ContentPipeline;
use ContentFactory\Validation\PageSpecSchemaRegistry;
use ContentFactory\WordPress\DraftManager;
use ContentFactory\WordPress\PublishManager;
use ContentFactory\WordPress\HashManager;

defined( 'ABSPATH' ) || exit;

final class RestController {
	private const NS = 'content-factory/v1';

	public function __construct(
		private AdapterRegistry $adapters,
		private ContentPipeline $pipeline,
		private DraftManager $drafts,
		private BatchRunner $batch,
		private PublishManager $publisher,
		private JsonImporter $json,
		private ZipImporter $zip,
		private ?OperationLogger $logger = null
	) {}

	public function register(): void {
		register_rest_route( self::NS, '/contract', array( 'methods' => 'GET', 'callback' => array( $this, 'contract' ), 'permission_callback' => array( $this, 'can_import' ) ) );
		register_rest_route( self::NS, '/validate', array( 'methods' => 'POST', 'callback' => array( $this, 'validate' ), 'permission_callback' => array( $this, 'can_import' ) ) );
		register_rest_route( self::NS, '/pages', array( array( 'methods' => 'GET', 'callback' => array( $this, 'pages' ), 'permission_callback' => array( $this, 'can_import' ) ), array( 'methods' => 'POST', 'callback' => array( $this, 'create' ), 'permission_callback' => array( $this, 'can_import' ) ) ) );
		register_rest_route( self::NS, '/pages/batch', array( 'methods' => 'POST', 'callback' => array( $this, 'create_batch' ), 'permission_callback' => array( $this, 'can_import' ) ) );
		register_rest_route( self::NS, '/pages/publish-selected', array( 'methods' => 'POST', 'callback' => array( $this, 'publish' ), 'permission_callback' => array( $this, 'can_publish' ) ) );
		register_rest_route( self::NS, '/pages/(?P<sourceId>[a-z0-9][a-z0-9._-]{2,159})', array( 'methods' => 'GET', 'callback' => array( $this, 'page' ), 'permission_callback' => array( $this, 'can_import' ) ) );
		register_rest_route( self::NS, '/pages/(?P<sourceId>[a-z0-9][a-z0-9._-]{2,159})/revalidate', array( 'methods' => 'POST', 'callback' => array( $this, 'revalidate' ), 'permission_callback' => array( $this, 'can_import' ) ) );
		register_rest_route( self::NS, '/operations', array( 'methods' => 'GET', 'callback' => array( $this, 'operations' ), 'permission_callback' => array( $this, 'can_import' ) ) );
		register_rest_route( self::NS, '/operations/cleanup', array( 'methods' => 'DELETE', 'callback' => array( $this, 'cleanup_operations' ), 'permission_callback' => array( $this, 'can_import' ) ) );
		register_rest_route( self::NS, '/operations/(?P<operationId>[A-Za-z0-9_-]+)', array( 'methods' => 'GET', 'callback' => array( $this, 'operation' ), 'permission_callback' => array( $this, 'can_import' ) ) );
	}

	public function can_import(): bool {
		return current_user_can( 'content_factory_import_pages' );
	}

	public function can_publish(): bool {
		return current_user_can( 'content_factory_publish_pages' ) && current_user_can( 'publish_pages' );
	}

	public function contract( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$adapter = $this->selected_adapter( $request );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}
		$profile = $adapter->compiled_profile();
		$builder = new ContractBundleBuilder( new PageSpecSchemaRegistry() );
		$bundle = $builder->build( $profile, $adapter->self_check(), $this->contract_supplement( $profile ) );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}
		$etag = $builder->etag( $bundle );
		if ( trim( (string) $request->get_header( 'if-none-match' ) ) === $etag ) {
			$response = new \WP_REST_Response( null, 304 );
			$response->header( 'ETag', $etag );
			return $response;
		}
		$response = new \WP_REST_Response( $bundle );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'private, max-age=0, must-revalidate' );
		return $response;
	}

	public function validate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$loaded = $this->request_specs( $request );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$specs = $loaded['specs'];
		$results = $this->batch->validate( $specs );
		if ( is_wp_error( $results ) ) {
			return $results;
		}
		$detail = 'full' === $request->get_param( 'detail' ) ? 'full' : 'summary';
		$api_results = array();
		foreach ( $results as $result ) {
			$index    = (int) ( $result['index'] ?? 0 );
			$filename = $loaded['files'][ $index ] ?? ( $loaded['files'][0] ?? 'request.json' );
			$api_results[] = 'summary' === $detail
				? $this->validation_summary( $result, $filename )
				: array_merge(
					array(
						'index'         => $index,
						'filename'      => $filename,
						'sourceId'      => $result['sourceId'] ?? '',
						'title'         => $result['title'] ?? '',
						'plannedAction' => $result['plannedAction'] ?? 'conflict',
						'profileId'     => ( $result['profile'] ?? null ) instanceof CompiledProfile ? $result['profile']->id() : '',
						'profileVersion'=> ( $result['profile'] ?? null ) instanceof CompiledProfile ? $result['profile']->version() : '',
						'manifestHash'  => ( $result['profile'] ?? null ) instanceof CompiledProfile ? $result['profile']->canonical_hash() : '',
					),
					$result['report']->jsonSerialize()
				);
		}
		$payload = array(
			'detail'      => $detail,
			'packageHash' => $loaded['packageHash'] ?? '',
			'files'       => $loaded['files'],
			'results'     => $api_results,
			'counts'      => $this->validation_counts( $results ),
		);
		if ( 1 === count( $results ) ) {
			$payload = array_merge( $payload, $api_results[0] );
		}
		return new \WP_REST_Response( $payload );
	}

	public function create( \WP_REST_Request $request ): array|\WP_Error {
		$body = $this->request_json( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! is_array( $body ) || array_is_list( $body ) ) {
			return new \WP_Error( 'invalid_body', 'Ожидался JSON-объект PageSpec.', array( 'status' => 400 ) );
		}
		$spec = $body['page'] ?? $body;
		if ( ! is_array( $spec ) || array_is_list( $spec ) ) {
			return new \WP_Error( 'invalid_body', 'Ожидался PageSpec.', array( 'status' => 400 ) );
		}
		$operation_id = $this->start_operation( 'page_import', $spec );
		$result = $this->drafts->import( $spec );
		if ( $operation_id ) {
			if ( is_wp_error( $result ) ) {
				$this->logger->log_page( $operation_id, array_merge( array( 'sourceId' => $spec['sourceId'] ?? '', 'action' => 'import', 'result' => 'error', 'compatibility_status' => 'incompatible' ), $this->safe_error_log( $result ) ) );
				$this->logger->finish( $operation_id, 'failed', array( 'total' => 1, 'failed' => 1 ) );
			} else {
				$this->logger->log_page( $operation_id, array_merge( $result, array( 'result' => 'success', 'compatibility_status' => $result['report']->status() ) ) );
				$this->logger->finish( $operation_id, 'completed', array( 'total' => 1, $result['action'] => 1 ) );
				$result['operationId'] = $operation_id;
			}
		}
		return $result;
	}

	public function create_batch( \WP_REST_Request $request ): array|\WP_Error {
		$files = $request->get_file_params();
		if ( isset( $files['file']['tmp_name'] ) ) {
			$loaded = $this->request_specs( $request );
			if ( is_wp_error( $loaded ) ) {
				return $loaded;
			}
			$expected_hash = (string) $request->get_param( 'validatedHash' );
			if ( '' !== $expected_hash && ( '' === ( $loaded['packageHash'] ?? '' ) || ! hash_equals( $expected_hash, $loaded['packageHash'] ) ) ) {
				return new \WP_Error( 'package_changed', 'Выбранный пакет отличается от ранее проверенного файла.', array( 'status' => 409, 'expectedHash' => $expected_hash, 'actualHash' => $loaded['packageHash'] ?? '' ) );
			}
			$result = $this->batch->run( $loaded['specs'], $this->is_confirmed( $request->get_param( 'confirmed' ) ) );
			return is_wp_error( $result ) || 'summary' !== $request->get_param( 'detail' ) ? $result : $this->import_summary( $result );
		}
		$body = $this->request_json( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! is_array( $body ) || array_is_list( $body ) || ! isset( $body['pages'] ) || ! is_array( $body['pages'] ) || ! array_is_list( $body['pages'] ) ) {
			return new \WP_Error( 'invalid_body', 'pages должен быть массивом PageSpec.', array( 'status' => 400 ) );
		}
		foreach ( $body['pages'] as $index => $spec ) {
			if ( ! is_array( $spec ) || array_is_list( $spec ) ) {
				return new \WP_Error( 'invalid_page_item', 'Каждый элемент pages должен быть JSON-объектом PageSpec.', array( 'status' => 422, 'index' => $index ) );
			}
		}
		$result = $this->batch->run( $body['pages'], true === ( $body['confirmed'] ?? false ) );
		return is_wp_error( $result ) || 'summary' !== ( $body['detail'] ?? '' ) ? $result : $this->import_summary( $result );
	}

	public function pages( \WP_REST_Request $request ): array {
		$query = new \WP_Query( array( 'post_type' => 'page', 'post_status' => array( 'draft', 'publish', 'pending', 'private' ), 'meta_key' => '_content_factory_source_id', 'posts_per_page' => min( 100, max( 1, (int) ( $request['per_page'] ?: 50 ) ) ), 'paged' => max( 1, (int) ( $request['page'] ?: 1 ) ), 'orderby' => 'modified', 'order' => 'DESC' ) );
		$items = array_map( array( $this, 'page_data' ), $query->posts );
		return array( 'items' => $items, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages );
	}

	public function page( \WP_REST_Request $request ): array|\WP_Error {
		$post = $this->drafts->find_by_source_id( $request['sourceId'] );
		return $post ? $this->page_data( $post ) : new \WP_Error( 'not_found', 'Managed page не найдена.', array( 'status' => 404 ) );
	}

	public function revalidate( \WP_REST_Request $request ): array|\WP_Error {
		$post = $this->drafts->find_by_source_id( $request['sourceId'] );
		if ( ! $post ) {
			return new \WP_Error( 'not_found', 'Managed page не найдена.', array( 'status' => 404 ) );
		}
		$spec = json_decode( (string) get_post_meta( $post->ID, '_content_factory_source_spec', true ), true );
		if ( ! is_array( $spec ) ) {
			return new \WP_Error( 'source_spec_missing', 'Исходный PageSpec не сохранён.', array( 'status' => 409 ) );
		}
		$pipeline_result = $this->pipeline->process_result( $spec );
		$report = $pipeline_result->report();
		$profile = $pipeline_result->profile();
		$seo_title = (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true );
		$seo_description = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		$stored_hash = (string) get_post_meta( $post->ID, '_content_factory_validation_hash', true );
		$current_hash = $profile ? ( new HashManager() )->validation_hash(
			array(
				'title' => $post->post_title, 'slug' => $post->post_name, 'parentId' => (int) $post->post_parent,
				'template' => get_page_template_slug( $post->ID ), 'content' => $post->post_content,
				'seoTitle' => $seo_title, 'seoDescription' => $seo_description,
				'profileId' => $profile->id(), 'profileVersion' => $profile->version(),
				'siteDefaultsVersion' => $profile->defaults_version(),
			)
		) : '';
		$profile_matches = $profile
			&& $profile->id() === (string) get_post_meta( $post->ID, '_content_factory_profile_id', true )
			&& $profile->version() === (string) get_post_meta( $post->ID, '_content_factory_profile_version', true )
			&& $profile->defaults_version() === (string) get_post_meta( $post->ID, '_content_factory_site_defaults_version', true )
			&& $profile->canonical_hash() === (string) get_post_meta( $post->ID, '_content_factory_manifest_hash', true );
		$matches = ! $report->has_errors() && $profile_matches
			&& ( $pipeline_result->build_plan()?->post_content() ?? null ) === $post->post_content
			&& sanitize_text_field( $spec['seo']['title'] ?? '' ) === $seo_title
			&& sanitize_textarea_field( $spec['seo']['description'] ?? '' ) === $seo_description
			&& '' !== $stored_hash && '' !== $current_hash && hash_equals( $stored_hash, $current_hash );
		update_post_meta( $post->ID, '_content_factory_validation_status', $matches ? 'valid' : 'stale' );
		update_post_meta( $post->ID, '_content_factory_validated_at', gmdate( 'c' ) );
		return array( 'sourceId' => $request['sourceId'], 'status' => $matches ? 'valid' : 'stale', 'report' => $report, 'contentMatchesSource' => $matches );
	}

	public function publish( \WP_REST_Request $request ): array|\WP_Error {
		$body = $this->request_json( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! is_array( $body ) || array_is_list( $body ) || ! isset( $body['sourceIds'] ) || ! is_array( $body['sourceIds'] ) || ! array_is_list( $body['sourceIds'] ) ) {
			return new \WP_Error( 'invalid_body', 'sourceIds должен быть массивом строк.', array( 'status' => 400 ) );
		}
		foreach ( $body['sourceIds'] as $index => $source_id ) {
			if ( ! is_string( $source_id ) || ! preg_match( '/^[a-z0-9][a-z0-9._-]{2,159}$/', $source_id ) ) {
				return new \WP_Error( 'invalid_source_id', 'Каждый sourceId должен быть валидной строкой.', array( 'status' => 422, 'index' => $index ) );
			}
		}
		$source_ids = $body['sourceIds'];
		$operation_id = $this->start_operation( 'publish_selected' );
		$result = $this->publisher->publish_selected( $source_ids, true === ( $body['confirmed'] ?? false ) );
		if ( $operation_id ) {
			$counts = array( 'total' => count( $source_ids ), 'published' => 0, 'failed' => 0 );
			foreach ( is_array( $result ) ? $result : array() as $row ) {
				$key = 'published' === ( $row['status'] ?? '' ) ? 'published' : 'failed';
				++$counts[ $key ];
				$this->logger->log_page( $operation_id, array_merge( $row, array( 'action' => 'publish', 'result' => $row['status'] ?? 'error', 'published_at' => 'published' === ( $row['status'] ?? '' ) ? current_time( 'mysql', true ) : null ) ) );
			}
			if ( is_wp_error( $result ) ) {
				$counts['failed'] = count( $source_ids );
			}
			$status = $counts['failed'] ? ( $counts['published'] ? 'partial' : 'failed' ) : 'completed';
			$this->logger->finish( $operation_id, $status, $counts );
		}
		return $result;
	}

	public function operation( \WP_REST_Request $request ): array|\WP_Error {
		if ( ! $this->logger ) {
			return new \WP_Error( 'logging_unavailable', 'Operation log недоступен.', array( 'status' => 503 ) );
		}
		$result = $this->logger->get( sanitize_text_field( $request['operationId'] ) );
		return $result ?: new \WP_Error( 'not_found', 'Operation не найдена.', array( 'status' => 404 ) );
	}

	public function operations( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->logger ) {
			return new \WP_Error( 'logging_unavailable', 'Operation log недоступен.', array( 'status' => 503 ) );
		}
		$filters = array_intersect_key( $request->get_params(), array_flip( array( 'operation_id', 'action', 'actor_user_id', 'batch_id', 'profile_id', 'status', 'source_id', 'sourceId', 'post_id', 'postId', 'result', 'date_from', 'date_to', 'limit', 'offset' ) ) );
		$response = new \WP_REST_Response( array( 'items' => $this->logger->list( $filters ), 'filters' => $filters ) );
		if ( 'download' === $request->get_param( 'format' ) ) {
			$response->header( 'Content-Disposition', 'attachment; filename="content-factory-operations.json"' );
		}
		return $response;
	}

	public function cleanup_operations( \WP_REST_Request $request ): array|\WP_Error {
		if ( ! $this->logger ) {
			return new \WP_Error( 'logging_unavailable', 'Operation log недоступен.', array( 'status' => 503 ) );
		}
		$days = null !== $request->get_param( 'retentionDays' ) ? absint( $request->get_param( 'retentionDays' ) ) : null;
		$deleted = $this->logger->cleanup( $days );
		return is_wp_error( $deleted ) ? $deleted : array( 'deleted' => $deleted, 'retentionDays' => $days ?? absint( get_option( 'content_factory_log_retention_days', 90 ) ) );
	}

	private function request_specs( \WP_REST_Request $request ): array|\WP_Error {
		$files = $request->get_file_params();
		if ( isset( $files['file']['tmp_name'] ) ) {
			$name = sanitize_file_name( $files['file']['name'] ?? 'upload.json' );
			$digest = is_readable( $files['file']['tmp_name'] ) ? hash_file( 'sha256', $files['file']['tmp_name'] ) : false;
			$package_hash = is_string( $digest ) && '' !== $digest ? 'sha256:' . $digest : '';
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( 'zip' === $ext ) {
				$entries = $this->zip->import_file( $files['file']['tmp_name'] );
				if ( is_wp_error( $entries ) ) { return $entries; }
				$specs = array();
				$spec_files = array();
				foreach ( $entries as $entry ) {
					$normalized = $this->normalize_specs( $entry['data'] );
					if ( is_wp_error( $normalized ) ) {
						return $normalized;
					}
					array_push( $specs, ...$normalized );
					array_push( $spec_files, ...array_fill( 0, count( $normalized ), $entry['filename'] ) );
				}
				return array( 'files' => $spec_files, 'specs' => $specs, 'packageHash' => $package_hash );
			}
			if ( 'json' !== $ext ) {
				return new \WP_Error( 'invalid_file_type', 'Разрешены только JSON и ZIP.', array( 'status' => 415 ) );
			}
			$data = $this->json->import_file( $files['file']['tmp_name'] );
			if ( is_wp_error( $data ) ) { return $data; }
			$normalized = $this->normalize_specs( $data );
			return is_wp_error( $normalized ) ? $normalized : array( 'files' => array( $name ), 'specs' => $normalized, 'packageHash' => $package_hash );
		}
		$body = $this->request_json( $request );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$specs = $body['pages'] ?? ( $body['page'] ?? $body );
		$normalized = $this->normalize_specs( $specs );
		return is_wp_error( $normalized ) ? $normalized : array( 'files' => array( 'request.json' ), 'specs' => $normalized, 'packageHash' => '' );
	}

	private function normalize_specs( mixed $data ): array|\WP_Error {
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_body', 'Ожидался PageSpec или массив PageSpec.', array( 'status' => 400 ) );
		}
		$specs = isset( $data['pages'] ) ? $data['pages'] : ( array_is_list( $data ) ? $data : array( $data ) );
		if ( ! is_array( $specs ) || ! array_is_list( $specs ) || ! $specs ) {
			return new \WP_Error( 'invalid_pages', 'Список PageSpec должен быть непустым массивом.', array( 'status' => 400 ) );
		}
		foreach ( $specs as $index => $spec ) {
			if ( ! is_array( $spec ) || array_is_list( $spec ) ) {
				return new \WP_Error( 'invalid_page_item', 'Каждый PageSpec должен быть JSON-объектом.', array( 'status' => 422, 'index' => $index ) );
			}
		}
		return $specs;
	}

	private function request_json( \WP_REST_Request $request ): array|\WP_Error {
		$raw = $request->get_body();
		if ( '' !== $raw ) {
			$decoded = $this->json->decode( $raw, 'request.json' );
			return is_wp_error( $decoded ) ? $decoded : $decoded;
		}
		$decoded = $request->get_json_params();
		return is_array( $decoded ) ? $decoded : new \WP_Error( 'invalid_body', 'Ожидался JSON-объект или массив PageSpec.', array( 'status' => 400 ) );
	}

	private function is_confirmed( mixed $value ): bool {
		return true === $value || 1 === $value || in_array( $value, array( '1', 'true' ), true );
	}

	private function validation_summary( array $result, string $filename ): array {
		$report  = $result['report'];
		$context = $report->context();
		$spec    = $context['normalizedSpec'] ?? ( $result['spec'] ?? array() );
		$profile = $result['profile'] ?? null;

		return array(
			'index'          => (int) ( $result['index'] ?? 0 ),
			'filename'       => $filename,
			'sourceId'       => (string) ( $result['sourceId'] ?? ( $spec['sourceId'] ?? '' ) ),
			'title'          => (string) ( $result['title'] ?? ( $spec['post']['title'] ?? '' ) ),
			'expectedPath'   => (string) ( $context['resolved']['expectedPath'] ?? '' ),
			'status'         => $report->status(),
			'plannedAction'  => (string) ( $result['plannedAction'] ?? 'conflict' ),
			'counts'         => array(
				'sections' => is_array( $spec['sections'] ?? null ) ? count( $spec['sections'] ) : 0,
				'links'    => $this->descriptor_count( $spec['sections'] ?? array(), 'link' ),
				'assets'   => $this->descriptor_count( $spec['sections'] ?? array(), 'asset' ),
			),
			'issues'          => $report->issues(),
			'profileId'       => $profile instanceof CompiledProfile ? $profile->id() : '',
			'profileVersion'  => $profile instanceof CompiledProfile ? $profile->version() : '',
			'manifestHash'    => $profile instanceof CompiledProfile ? $profile->canonical_hash() : '',
		);
	}

	private function descriptor_count( mixed $value, string $type ): int {
		if ( ! is_array( $value ) ) {
			return 0;
		}
		$count = 0;
		if ( 'link' === $type && isset( $value['kind'] ) && in_array( $value['kind'], array( 'anchor', 'page', 'path', 'external', 'tel', 'mailto' ), true ) ) {
			++$count;
		}
		if ( 'asset' === $type && isset( $value['source'] ) && in_array( $value['source'], array( 'themeAsset', 'mediaId', 'mediaUrl', 'externalUrl', 'none' ), true ) ) {
			++$count;
		}
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$count += $this->descriptor_count( $child, $type );
			}
		}
		return $count;
	}

	private function import_summary( array $result ): array {
		$rows = array();
		foreach ( $result['results'] ?? array() as $row ) {
			$rows[] = array_intersect_key(
				$row,
				array_flip( array( 'sourceId', 'action', 'status', 'postId', 'editLink', 'previewLink', 'error', 'rollback' ) )
			);
		}
		return array(
			'operationId' => $result['operationId'] ?? null,
			'counts'      => $result['counts'] ?? array(),
			'results'     => $rows,
		);
	}

	private function validation_counts( array $results ): array {
		$counts = array( 'total' => count( $results ), 'compatible' => 0, 'compatible_with_warnings' => 0, 'incompatible' => 0 );
		foreach ( $results as $result ) { ++$counts[ $result['report']->status() ]; }
		return $counts;
	}

	private function page_data( \WP_Post $post ): array {
		return array(
			'postId' => $post->ID, 'title' => get_the_title( $post ), 'sourceId' => get_post_meta( $post->ID, '_content_factory_source_id', true ),
			'path' => '/' . trim( get_page_uri( $post ), '/' ) . '/', 'status' => $post->post_status,
			'validationStatus' => get_post_meta( $post->ID, '_content_factory_validation_status', true ),
			'profileId' => get_post_meta( $post->ID, '_content_factory_profile_id', true ),
			'profileVersion' => get_post_meta( $post->ID, '_content_factory_profile_version', true ),
			'siteDefaultsVersion' => get_post_meta( $post->ID, '_content_factory_site_defaults_version', true ),
			'manifestHash' => get_post_meta( $post->ID, '_content_factory_manifest_hash', true ),
			'lastImport' => get_post_meta( $post->ID, '_content_factory_generated_at', true ), 'lastValidation' => get_post_meta( $post->ID, '_content_factory_validated_at', true ),
			'warningsCount' => (int) get_post_meta( $post->ID, '_content_factory_warning_count', true ), 'editLink' => get_edit_post_link( $post->ID, 'raw' ), 'previewLink' => get_preview_post_link( $post->ID ),
		);
	}

	private function start_operation( string $action, array $spec = array() ): ?string {
		if ( ! $this->logger ) { return null; }
		$selected = ( new ProfileSelector( $this->adapters ) )->select( $spec );
		$context = array();
		if ( ! is_wp_error( $selected ) ) {
			$profile = $selected->compiled_profile();
			$context = array( 'profile_id' => $profile->id(), 'profile_version' => $profile->version(), 'manifest_hash' => $profile->canonical_hash() );
		}
		$started = $this->logger->start( $action, $context );
		return is_wp_error( $started ) ? null : $started;
	}

	private function selected_adapter( ?\WP_REST_Request $request = null ): ThemeAdapterInterface|\WP_Error {
		$site_key = $request ? trim( (string) $request->get_param( 'siteKey' ) ) : '';
		$profile_id = $request ? trim( (string) $request->get_param( 'profileId' ) ) : '';
		if ( '' === $site_key || '' === $profile_id ) {
			return new \WP_Error( 'incomplete_profile_target', 'siteKey и profileId обязательны.', array( 'status' => 400 ) );
		}
		$spec = array( 'target' => array( 'siteKey' => $site_key, 'profileId' => $profile_id ) );
		$selected = ( new ProfileSelector( $this->adapters ) )->select( $spec );
		if ( is_wp_error( $selected ) ) {
			$status = in_array( $selected->get_error_code(), array( 'ambiguous_profile', 'target_profile_unavailable' ), true ) ? 409 : 404;
			return new \WP_Error( $selected->get_error_code(), $selected->get_error_message(), array( 'status' => $status ) );
		}
		return $selected;
	}

	private function contract_supplement( CompiledProfile $profile ): array {
		if ( 'potolki-inner' !== $profile->id() ) {
			return array();
		}
		$examples = array();
		foreach ( array( 'service-detail', 'service-category' ) as $name ) {
			$file = CONTENT_FACTORY_DIR . 'tests/fixtures/golden/' . $name . '.json';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$value = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $value ) && ! array_is_list( $value ) ) {
				$value['schemaVersion'] = '1.1';
				$value['target'] = array( 'siteKey' => $profile->site_key(), 'profileId' => $profile->id() );
				$value['generatedAgainst'] = array( 'profileId' => $profile->id(), 'profileVersion' => $profile->version(), 'manifestHash' => $profile->canonical_hash() );
				$examples[] = $value;
			}
		}
		return array(
			'examples' => $examples,
			'conversionGuidance' => array(
				'Use only semantic section types and page recipes declared by this bundle.',
				'Copy target and generatedAgainst identity from the same bundle snapshot.',
				'Validate the full Content Pack before creating WordPress drafts.',
			),
		);
	}

	private function safe_error_log( \WP_Error $error ): array {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$report = $data['report'] ?? null;
		return array(
			'issues' => $report instanceof \ContentFactory\Contract\CompatibilityReport ? $report->issues() : array(),
			'defaults_applied' => $report instanceof \ContentFactory\Contract\CompatibilityReport ? ( $report->context()['defaultsApplied'] ?? array() ) : array(),
			'rollback_result' => array( 'rollback' => true === ( $data['rollback'] ?? false ), 'status' => absint( $data['status'] ?? 0 ), 'errorCode' => $error->get_error_code() ),
		);
	}
}
