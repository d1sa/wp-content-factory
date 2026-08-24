<?php

namespace ContentFactory\Resolve;

defined( 'ABSPATH' ) || exit;

final class LinkResolver {
	public function resolve( array $link, array $context = array() ): array|\WP_Error {
		$kind = $link['kind'] ?? '';
		switch ( $kind ) {
			case 'anchor':
				$anchor = $link['anchor'] ?? '';
				if ( ! is_string( $anchor ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $anchor ) ) {
					return new \WP_Error( 'invalid_anchor', 'Некорректный anchor.' );
				}
				if ( ! in_array( $anchor, $context['anchors'] ?? array(), true ) ) {
					return new \WP_Error( 'missing_anchor', 'Anchor отсутствует в итоговой странице.' );
				}
				return array( 'url' => '#' . $anchor, 'target' => '', 'rel' => '' );
			case 'page':
				$source_id = $link['sourceId'] ?? '';
				$url       = $context['source_urls'][ $source_id ] ?? '';
				if ( '' === $url ) {
					$post_id = $this->find_post_id( (string) $source_id );
					$url     = $post_id ? $this->page_url( $post_id ) : '';
				}
				return $url ? array( 'url' => $url, 'target' => '', 'rel' => '' ) : new \WP_Error( 'missing_page_link', 'Страница ссылки по sourceId не найдена.' );
			case 'path':
				$path = $link['path'] ?? '';
				$host = is_string( $path ) ? wp_parse_url( $path, PHP_URL_HOST ) : null;
				if ( ! is_string( $path ) || ! str_starts_with( $path, '/' ) || preg_match( '#^(?:/)?(?:javascript|data|vbscript):#i', $path ) || ( null !== $host && false !== $host ) ) {
					return new \WP_Error( 'invalid_path', 'Внутренний path небезопасен.' );
				}
				if ( ! empty( $context['verify_targets'] ) && ! $this->path_exists( $path, $context ) ) {
					return new \WP_Error( 'missing_path_link', 'Внутренний path не существует и не создаётся текущим batch.' );
				}
				return array( 'url' => $path, 'target' => '', 'rel' => '' );
			case 'external':
				$url = esc_url_raw( $link['url'] ?? '', array( 'http', 'https' ) );
				if ( ! $url || ! wp_http_validate_url( $url ) ) {
					return new \WP_Error( 'invalid_external_url', 'Внешний URL должен использовать HTTP(S).' );
				}
				$new_tab = true === ( $link['newTab'] ?? false );
				return array( 'url' => $url, 'target' => $new_tab ? '_blank' : '', 'rel' => $new_tab ? 'noopener noreferrer' : '' );
			case 'tel':
				$value = preg_replace( '/[^0-9+]/', '', (string) ( $link['value'] ?? '' ) );
				return preg_match( '/^\+?[0-9]{7,15}$/', $value ) ? array( 'url' => 'tel:' . $value, 'target' => '', 'rel' => '' ) : new \WP_Error( 'invalid_phone', 'Некорректная телефонная ссылка.' );
			case 'mailto':
				$value = sanitize_email( $link['value'] ?? '' );
				return is_email( $value ) ? array( 'url' => 'mailto:' . $value, 'target' => '', 'rel' => '' ) : new \WP_Error( 'invalid_email', 'Некорректная email-ссылка.' );
			default:
				return new \WP_Error( 'unknown_link_kind', 'Неизвестный тип ссылки.' );
		}
	}

	private function path_exists( string $path, array $context ): bool {
		$normalized = '/' . trim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' ) . '/';
		foreach ( $context['batch_paths'] ?? array() as $planned ) {
			if ( $normalized === '/' . trim( (string) $planned, '/' ) . '/' ) {
				return true;
			}
		}
		if ( '/' === $normalized ) {
			return true;
		}
		return null !== get_page_by_path( trim( $normalized, '/' ), OBJECT, 'page' );
	}

	private function find_post_id( string $source_id ): int {
		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'draft', 'publish', 'pending', 'private' ),
				'meta_key'       => '_content_factory_source_id',
				'meta_value'     => $source_id,
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return (int) ( $posts[0] ?? 0 );
	}

	private function page_url( int $post_id ): string {
		$uri = trim( (string) get_page_uri( $post_id ), '/' );
		return '' !== $uri ? home_url( '/' . $uri . '/' ) : '';
	}
}
