<?php

namespace ContentFactory\Engine;

use ContentFactory\Resolve\LinkResolver;

defined( 'ABSPATH' ) || exit;

final class InlineRichTextRenderer {
	public function __construct( private LinkResolver $links ) {}

	public function render( string $text, array $context, bool $allow_links = true ): string {
		$html = esc_html( $text );
		if ( $allow_links ) {
			$html = preg_replace_callback(
				'/\[([^\]\n]+)\]\(([^)\s]+)\)/u',
				function ( array $match ) use ( $context ): string {
					$url = html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$link = str_starts_with( $url, '/' )
						? array( 'kind' => 'path', 'path' => $url )
						: array( 'kind' => 'external', 'url' => $url, 'newTab' => false );
					$resolved = $this->resolve( $link, $context );
					return '<a href="' . esc_url( $resolved['url'] ) . '">' . $match[1] . '</a>';
				},
				$html
			);
		}
		$html = preg_replace( '/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', (string) $html );
		$html = preg_replace( '/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', (string) $html );
		return nl2br( (string) $html, false );
	}

	public function resolve( array $link, array $context ): array {
		$resolved = $this->links->resolve( $link, $context );
		if ( is_wp_error( $resolved ) || '' === ( $resolved['url'] ?? '' ) || '#' === $resolved['url'] ) {
			throw new \RuntimeException( is_wp_error( $resolved ) ? $resolved->get_error_message() : 'Ссылка не разрешилась.' );
		}
		return $resolved;
	}
}
