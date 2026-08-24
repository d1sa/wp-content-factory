<?php

namespace ContentFactory\Engine;

use ContentFactory\Profile\CompiledProfile;
use ContentFactory\Resolve\LinkResolver;

defined( 'ABSPATH' ) || exit;

final class TransformRegistry {
	private const IDS = array( 'direct', 'default', 'coalesce', 'repeat', 'index', 'link', 'asset', 'plainText', 'inlineRichText', 'literal' );
	private InlineRichTextRenderer $rich_text;
	private AssetResolver $assets;

	public function __construct( LinkResolver $links, CompiledProfile $profile ) {
		$this->rich_text = new InlineRichTextRenderer( $links );
		$this->assets    = new AssetResolver( $profile );
	}

	public static function ids(): array { return self::IDS; }

	public function apply( array $expression, array $scope, array $context = array() ): mixed {
		$id = (string) ( $expression['transform'] ?? 'direct' );
		if ( ! in_array( $id, self::IDS, true ) ) {
			throw new \InvalidArgumentException( 'Неизвестный transform: ' . $id );
		}
		if ( 'literal' === $id ) {
			return $expression['value'] ?? null;
		}
		if ( 'index' === $id ) {
			$index = (int) ( $scope['index'] ?? 0 );
			return 'firstBoolean' === ( $expression['mode'] ?? '' ) ? 0 === $index : (string) ( $index + 1 );
		}
		if ( 'coalesce' === $id || 'default' === $id ) {
			$sources = 'default' === $id ? array( $expression['source'] ?? '' ) : ( $expression['sources'] ?? array() );
			foreach ( $sources as $source ) {
				[ $found, $value ] = $this->read( $scope, (string) $source );
				if ( $found && null !== $value ) {
					return $value;
				}
			}
			return $expression['fallback'] ?? null;
		}
		[ $found, $value ] = $this->read( $scope, (string) ( $expression['source'] ?? '' ) );
		if ( ! $found ) {
			$value = null;
		}
		if ( 'direct' === $id || 'repeat' === $id ) {
			return $value;
		}
		if ( 'plainText' === $id ) {
			return (string) $value;
		}
		if ( 'inlineRichText' === $id ) {
			return $this->rich_text->render( (string) $value, $context, true === ( $expression['allowLinks'] ?? false ) );
		}
		if ( 'link' === $id ) {
			$resolved = $this->rich_text->resolve( is_array( $value ) ? $value : array(), $context );
			return $resolved[ (string) ( $expression['part'] ?? 'url' ) ] ?? '';
		}
		$resolved = $this->assets->resolve( is_array( $value ) ? $value : null, $context, (string) ( $expression['fallbackRef'] ?? '' ) );
		return $resolved[ (string) ( $expression['part'] ?? 'url' ) ] ?? '';
	}

	private function read( array $scope, string $path ): array {
		if ( '' === $path ) {
			return array( false, null );
		}
		$value = $scope;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return array( false, null );
			}
			$value = $value[ $part ];
		}
		return array( true, $value );
	}
}
