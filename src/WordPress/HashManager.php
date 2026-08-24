<?php

namespace ContentFactory\WordPress;

defined( 'ABSPATH' ) || exit;

final class HashManager {
	public function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->canonicalize( $child );
		}
		return $value;
	}

	public function source_hash( array $spec ): string {
		return hash( 'sha256', wp_json_encode( $this->canonicalize( $spec ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public function content_hash( string $content ): string {
		return hash( 'sha256', trim( str_replace( "\r\n", "\n", $content ) ) );
	}

	public function validation_hash( array $values ): string {
		return hash( 'sha256', wp_json_encode( $this->canonicalize( $values ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}

