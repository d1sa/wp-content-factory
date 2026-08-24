<?php

namespace ContentFactory\WordPress;

defined( 'ABSPATH' ) || exit;

final class YoastAdapter {
	public function available(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}

	public function save( int $post_id, array $seo ): bool|\WP_Error {
		if ( ! $this->available() ) {
			return new \WP_Error( 'yoast_unavailable', 'Yoast SEO должен быть активирован перед импортом.' );
		}
		$title       = sanitize_text_field( $seo['title'] ?? '' );
		$description = sanitize_textarea_field( $seo['description'] ?? '' );
		if ( '' === $title || '' === $description ) {
			return new \WP_Error( 'yoast_fields_missing', 'SEO Title и SEO Description обязательны.' );
		}
		update_post_meta( $post_id, '_yoast_wpseo_title', $title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
		if ( $title !== get_post_meta( $post_id, '_yoast_wpseo_title', true ) || $description !== get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ) {
			return new \WP_Error( 'yoast_readback_failed', 'Не удалось подтвердить сохранение метаданных Yoast.' );
		}
		return true;
	}
}

