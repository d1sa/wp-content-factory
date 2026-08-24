<?php

namespace ContentFactory\Profile;

defined( 'ABSPATH' ) || exit;

/** Deterministic JSON encoding for contract identity. */
final class CanonicalJson {
	public function encode( mixed $value ): string {
		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $this->normalize( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: json_encode( $this->normalize( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			throw new \RuntimeException( 'Не удалось сериализовать canonical profile JSON.' );
		}
		return $encoded;
	}

	public function hash( mixed $value ): string {
		return 'sha256:' . hash( 'sha256', $this->encode( $value ) );
	}

	public function normalize( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$properties = get_object_vars( $value );
			ksort( $properties, SORT_STRING );
			$normalized = new \stdClass();
			foreach ( $properties as $key => $child ) {
				$normalized->{$key} = $this->normalize( $child );
			}
			return $normalized;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( fn( mixed $child ): mixed => $this->normalize( $child ), $value );
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->normalize( $child );
		}
		return $value;
	}
}
