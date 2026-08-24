<?php

namespace ContentFactory\Engine;

use ContentFactory\Profile\CompiledProfile;

defined( 'ABSPATH' ) || exit;

final class AssetResolver {
	public function __construct( private CompiledProfile $profile ) {}

	public function resolve( ?array $asset, array $context, string $fallback_ref = '' ): array {
		if ( null === $asset ) {
			$asset = array( 'source' => 'themeAsset', 'ref' => $fallback_ref, 'alt' => '' );
		}
		$source = (string) ( $asset['source'] ?? '' );
		if ( 'themeAsset' === $source ) {
			$ref = (string) ( $asset['ref'] ?? '' );
			$url = $context['theme_asset_urls'][ $ref ] ?? '';
			if ( '' === $url && function_exists( 'get_theme_file_uri' ) ) {
				$url = get_theme_file_uri( (string) ( $this->profile->assets()[ $ref ]['path'] ?? '' ) );
			}
			if ( '' === $url ) {
				throw new \RuntimeException( 'themeAsset не удалось преобразовать в URL: ' . $ref );
			}
			return array( 'id' => 0, 'url' => $url, 'alt' => (string) ( $asset['alt'] ?? '' ) );
		}
		if ( 'mediaId' === $source ) {
			$id  = (int) ( $asset['id'] ?? 0 );
			$url = $context['media_assets'][ $id ] ?? ( function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $id ) : '' );
			if ( ! $url ) {
				throw new \RuntimeException( 'Attachment mediaId не найден.' );
			}
			return array( 'id' => $id, 'url' => $url, 'alt' => (string) ( $asset['alt'] ?? '' ) );
		}
		if ( 'mediaUrl' === $source ) {
			$url = (string) ( $asset['url'] ?? '' );
			$id  = (int) ( $context['media_urls'][ $url ] ?? ( function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $url ) : 0 ) );
			if ( $id <= 0 ) {
				throw new \RuntimeException( 'mediaUrl не удалось сопоставить attachment.' );
			}
			return array( 'id' => $id, 'url' => $url, 'alt' => (string) ( $asset['alt'] ?? '' ) );
		}
		throw new \RuntimeException( 'Asset descriptor не поддерживается policy адаптера.' );
	}
}
