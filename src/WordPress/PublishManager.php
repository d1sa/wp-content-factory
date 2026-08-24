<?php

namespace ContentFactory\WordPress;

use ContentFactory\Adapter\AdapterRegistry;
use ContentFactory\Service\ContentPipeline;

defined( 'ABSPATH' ) || exit;

final class PublishManager {
	public function __construct(
		private ContentPipeline $pipeline,
		private AdapterRegistry $adapters,
		private HashManager $hashes
	) {}

	public function publish_selected( array $source_ids, bool $confirmed ): array|\WP_Error {
		if ( ! current_user_can( 'content_factory_publish_pages' ) || ! current_user_can( 'publish_pages' ) ) {
			return new \WP_Error( 'forbidden', 'Недостаточно прав для публикации.', array( 'status' => 403 ) );
		}
		if ( ! $confirmed || ! $source_ids ) {
			return new \WP_Error( 'confirmation_required', 'Подтвердите проверку хотя бы одной страницы.', array( 'status' => 400 ) );
		}
		$results = array();
		foreach ( array_unique( array_map( 'sanitize_text_field', $source_ids ) ) as $source_id ) {
			$posts = get_posts( array( 'post_type' => 'page', 'post_status' => 'draft', 'meta_key' => '_content_factory_source_id', 'meta_value' => $source_id, 'posts_per_page' => 1 ) );
			$post = $posts[0] ?? null;
			if ( ! $post ) {
				$results[] = array( 'sourceId' => $source_id, 'status' => 'error', 'message' => 'Managed draft не найден.' );
				continue;
			}
			$stored_hash = (string) get_post_meta( $post->ID, '_content_factory_validation_hash', true );
			if ( 'valid' !== get_post_meta( $post->ID, '_content_factory_validation_status', true ) || '' === $stored_hash ) {
				$results[] = array( 'sourceId' => $source_id, 'status' => 'error', 'message' => 'Validation устарела или невалидна.' );
				continue;
			}
			$spec = json_decode( (string) get_post_meta( $post->ID, '_content_factory_source_spec', true ), true );
			$adapter = $this->adapters->active();
			if ( ! is_array( $spec ) || ! $adapter ) {
				$results[] = array( 'sourceId' => $source_id, 'status' => 'error', 'message' => 'Исходный PageSpec или активный adapter недоступен.' );
				continue;
			}
			$report = $this->pipeline->process( $spec );
			$seo_title = (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true );
			$seo_description = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			$planned_content = (string) ( $report->context()['postContent'] ?? '' );
			$current_hash = $this->hashes->validation_hash(
				array(
					'title' => $post->post_title,
					'slug' => $post->post_name,
					'parentId' => (int) $post->post_parent,
					'template' => get_page_template_slug( $post->ID ),
					'content' => $post->post_content,
					'seoTitle' => $seo_title,
					'seoDescription' => $seo_description,
					'profileId' => $adapter->id(),
					'profileVersion' => $adapter->version(),
					'siteDefaultsVersion' => $adapter->manifest()['siteDefaultsVersion'] ?? '1',
				)
			);
			if ( $report->has_errors() || '' === $seo_title || '' === $seo_description || $planned_content !== $post->post_content || ! parse_blocks( $post->post_content ) || ! hash_equals( $stored_hash, $current_hash ) ) {
				update_post_meta( $post->ID, '_content_factory_validation_status', 'stale' );
				$results[] = array( 'sourceId' => $source_id, 'postId' => $post->ID, 'status' => 'error', 'message' => 'Финальная проверка обнаружила изменения content, SEO или validation hash.', 'issues' => $report->issues(), 'compatibility_status' => $report->status() );
				continue;
			}
			$publish_exception = null;
			try {
				DraftManager::set_internal_save( true );
				$result = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ), true );
			} catch ( \Throwable $error ) {
				$publish_exception = $error;
				$result = new \WP_Error( 'publish_failed', $error->getMessage() );
			} finally {
				DraftManager::set_internal_save( false );
			}
			$expected_slug = sanitize_title( $spec['post']['slug'] ?? '' );
			$expected_uri  = trim( (string) ( $report->context()['resolved']['expectedPath'] ?? '' ), '/' );
			if ( ! is_wp_error( $result ) && 'publish' === get_post_status( $post->ID ) && ( $expected_slug !== get_post_field( 'post_name', $post->ID ) || $expected_uri !== trim( get_page_uri( $post->ID ), '/' ) ) ) {
				$result = new \WP_Error( 'publish_path_changed', 'WordPress изменил slug или hierarchical path во время публикации.' );
			}
			if ( is_wp_error( $result ) && 'publish' === get_post_status( $post->ID ) ) {
				try {
					DraftManager::set_internal_save( true );
					wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft', 'post_name' => $expected_slug ), true );
				} catch ( \Throwable ) {
					// The actual status is checked below even when a hook interrupts rollback.
				} finally {
					DraftManager::set_internal_save( false );
				}
			}
			if ( is_wp_error( $result ) && 'publish' === get_post_status( $post->ID ) ) {
				$published = $this->published_result( $source_id, $post, $report, $seo_title, $seo_description );
				$published += array( 'message' => 'Страница фактически опубликована, но WordPress hook завершился ошибкой и rollback не сработал.', 'rollback' => false, 'exception' => null !== $publish_exception );
				$results[] = $published;
				continue;
			}
			if ( is_wp_error( $result ) || 'publish' !== get_post_status( $post->ID ) ) {
				$message = is_wp_error( $result ) ? $result->get_error_message() : 'Хук WordPress не позволил сохранить статус publish.';
				$results[] = array( 'sourceId' => $source_id, 'postId' => $post->ID, 'status' => 'error', 'message' => $message, 'rollback' => 'draft' === get_post_status( $post->ID ), 'exception' => null !== $publish_exception );
				continue;
			}
			$results[] = $this->published_result( $source_id, $post, $report, $seo_title, $seo_description );
		}
		return $results;
	}

	private function published_result( string $source_id, \WP_Post $post, $report, string $seo_title, string $seo_description ): array {
		return array(
			'sourceId' => $source_id, 'postId' => $post->ID, 'status' => 'published', 'url' => get_permalink( $post->ID ),
			'compatibility_status' => $report->status(), 'issues' => $report->issues(),
			'old_source_hash' => (string) get_post_meta( $post->ID, '_content_factory_source_hash', true ),
			'new_source_hash' => (string) get_post_meta( $post->ID, '_content_factory_source_hash', true ),
			'old_content_hash' => (string) get_post_meta( $post->ID, '_content_factory_content_hash', true ),
			'new_content_hash' => (string) get_post_meta( $post->ID, '_content_factory_content_hash', true ),
			'resolved_parent_id' => (int) $post->post_parent, 'resolved_path' => '/' . trim( get_page_uri( $post ), '/' ) . '/',
			'yoast_result' => array( 'title' => $seo_title, 'description' => $seo_description, 'verified' => true ),
		);
	}
}
