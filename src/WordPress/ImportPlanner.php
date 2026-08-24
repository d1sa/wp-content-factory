<?php

namespace ContentFactory\WordPress;

use ContentFactory\Profile\CompiledProfile;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the action an import would take without changing WordPress state.
 *
 * This is the single owner of create/update/no-change conflict decisions used
 * by both validation previews and DraftManager.
 */
final class ImportPlanner {
	public function __construct( private HashManager $hashes ) {}

	/** @return array<string,mixed> */
	public function plan( array $spec, array $context, CompiledProfile $profile ): array {
		$source_id = (string) ( $spec['sourceId'] ?? '' );
		$managed   = $this->managed_posts( $source_id );
		$source_hash = $this->hashes->source_hash( $spec );

		if ( count( $managed ) > 1 ) {
			return $this->result( 'conflict', null, $source_hash, 'duplicate_source_id' );
		}

		$existing = $managed[0] ?? null;
		if ( $existing instanceof \WP_Post && 'publish' === $existing->post_status ) {
			return $this->result( 'blocked_published', $existing, $source_hash, 'published_managed_page' );
		}

		$expected_path = (string) ( $context['resolved']['expectedPath'] ?? '' );
		if ( '' !== $expected_path ) {
			$path_post = get_page_by_path( trim( $expected_path, '/' ), OBJECT, 'page' );
			if ( $path_post instanceof \WP_Post && ( ! $existing || (int) $existing->ID !== (int) $path_post->ID ) ) {
				return $this->result( 'conflict', $path_post, $source_hash, 'path_conflict' );
			}
		}

		if ( ! $existing instanceof \WP_Post ) {
			return $this->result( 'create', null, $source_hash );
		}

		$stored_source_hash = (string) get_post_meta( $existing->ID, '_content_factory_source_hash', true );
		if ( hash_equals( $stored_source_hash, $source_hash ) && $this->matches_validated_source( $existing, $spec, $context, $profile ) ) {
			return $this->result( 'no_change', $existing, $source_hash );
		}

		return $this->result( 'update_draft', $existing, $source_hash );
	}

	public function find_by_source_id( string $source_id ): ?\WP_Post {
		$posts = $this->managed_posts( $source_id, 1 );
		return $posts[0] ?? null;
	}

	private function matches_validated_source( \WP_Post $post, array $spec, array $context, CompiledProfile $profile ): bool {
		$planned_content = (string) ( $context['postContent'] ?? '' );
		$parent_id      = (int) ( $context['resolved']['parentId'] ?? 0 );
		$manifest       = $profile->configuration();
		$template       = (string) ( $manifest['postDefaults']['pageTemplate'] ?? '' );
		$profile_id      = $profile->id();
		$profile_version = $profile->version();
		$defaults_version = $profile->defaults_version();
		$manifest_hash   = $profile->canonical_hash();

		return 'valid' === get_post_meta( $post->ID, '_content_factory_validation_status', true )
			&& $profile_id === (string) get_post_meta( $post->ID, '_content_factory_profile_id', true )
			&& $profile_version === (string) get_post_meta( $post->ID, '_content_factory_profile_version', true )
			&& $defaults_version === (string) get_post_meta( $post->ID, '_content_factory_site_defaults_version', true )
			&& $manifest_hash === (string) get_post_meta( $post->ID, '_content_factory_manifest_hash', true )
			&& $planned_content === $post->post_content
			&& sanitize_text_field( $spec['post']['title'] ?? '' ) === $post->post_title
			&& sanitize_title( $spec['post']['slug'] ?? '' ) === $post->post_name
			&& $parent_id === (int) $post->post_parent
			&& $template === get_page_template_slug( $post->ID )
			&& sanitize_text_field( $spec['seo']['title'] ?? '' ) === get_post_meta( $post->ID, '_yoast_wpseo_title', true )
			&& sanitize_textarea_field( $spec['seo']['description'] ?? '' ) === get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true )
			&& $this->hashes->content_hash( $post->post_content ) === get_post_meta( $post->ID, '_content_factory_content_hash', true );
	}

	/** @return \WP_Post[] */
	private function managed_posts( string $source_id, int $limit = 2 ): array {
		if ( '' === $source_id ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'draft', 'publish', 'pending', 'private' ),
				'meta_key'       => '_content_factory_source_id',
				'meta_value'     => $source_id,
				'posts_per_page' => $limit,
			)
		);
	}

	/** @return array<string,mixed> */
	private function result( string $action, ?\WP_Post $post, string $source_hash, string $reason = '' ): array {
		$result = array(
			'action'     => $action,
			'postId'     => $post ? (int) $post->ID : 0,
			'sourceHash' => $source_hash,
		);
		if ( $post ) {
			$result['status']   = $post->post_status;
			$result['editLink'] = get_edit_post_link( $post->ID, 'raw' );
		}
		if ( '' !== $reason ) {
			$result['reason'] = $reason;
		}
		return $result;
	}
}
