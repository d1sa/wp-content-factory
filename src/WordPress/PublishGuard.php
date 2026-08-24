<?php

namespace ContentFactory\WordPress;

defined( 'ABSPATH' ) || exit;

final class PublishGuard {
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'guard_publish' ), 20, 2 );
		add_action( 'save_post_page', array( $this, 'invalidate_manual_edit' ), 20, 3 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function guard_publish( array $data, array $postarr ): array {
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( DraftManager::is_internal_save() || 'publish' !== ( $data['post_status'] ?? '' ) || ! $post_id || ! get_post_meta( $post_id, '_content_factory_source_id', true ) ) {
			return $data;
		}
		if ( 'manager_only' === get_option( 'content_factory_publish_policy', 'manager_only' ) || 'valid' !== get_post_meta( $post_id, '_content_factory_validation_status', true ) ) {
			$data['post_status'] = 'draft';
			set_transient( 'content_factory_publish_blocked_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
		}
		return $data;
	}

	public function invalidate_manual_edit( int $post_id, \WP_Post $post, bool $update ): void {
		if ( ! $update || DraftManager::is_internal_save() || wp_is_post_revision( $post_id ) || ! get_post_meta( $post_id, '_content_factory_source_id', true ) ) {
			return;
		}
		update_post_meta( $post_id, '_content_factory_validation_status', 'stale' );
	}

	public function notice(): void {
		$key = 'content_factory_publish_blocked_' . get_current_user_id();
		if ( get_transient( $key ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Публикация managed page заблокирована. Используйте Content Factory после повторной проверки.', 'content-factory' ) . '</p></div>';
		}
	}
}

