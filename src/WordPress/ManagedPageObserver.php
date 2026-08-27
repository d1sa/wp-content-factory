<?php

namespace ContentFactory\WordPress;

defined( 'ABSPATH' ) || exit;

final class ManagedPageObserver {
	public function register(): void {
		add_action( 'save_post_page', array( $this, 'invalidate_validation' ), 20, 3 );
	}

	public function invalidate_validation( int $post_id, \WP_Post $post, bool $update ): void {
		if ( ! $update || DraftManager::is_internal_save() || wp_is_post_revision( $post_id ) || ! get_post_meta( $post_id, '_content_factory_source_id', true ) ) {
			return;
		}
		update_post_meta( $post_id, '_content_factory_validation_status', 'stale' );
	}
}
