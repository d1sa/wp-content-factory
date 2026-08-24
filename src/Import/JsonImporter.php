<?php

namespace ContentFactory\Import;

defined( 'ABSPATH' ) || exit;

final class JsonImporter {
	public const MAX_FILE_SIZE = 1048576;
	public const MAX_DEPTH     = 64;

	/**
	 * Read and decode a PageSpec JSON file.
	 *
	 * @return array|\WP_Error
	 */
	public function import_file( string $file_path ): array|\WP_Error {
		if ( ! is_file( $file_path ) ) {
			return $this->error(
				'content_factory_json_file_not_found',
				__( 'The JSON file does not exist.', 'content-factory' ),
				400,
				$file_path
			);
		}

		if ( ! is_readable( $file_path ) ) {
			return $this->error(
				'content_factory_json_file_unreadable',
				__( 'The JSON file cannot be read.', 'content-factory' ),
				400,
				$file_path
			);
		}

		$size = filesize( $file_path );
		if ( false !== $size && $size > self::MAX_FILE_SIZE ) {
			return $this->too_large_error( $file_path );
		}

		$stream = fopen( $file_path, 'rb' );
		if ( false === $stream ) {
			return $this->error(
				'content_factory_json_file_unreadable',
				__( 'The JSON file cannot be opened for reading.', 'content-factory' ),
				400,
				$file_path
			);
		}

		try {
			$contents = $this->read_stream( $stream, $file_path );
		} finally {
			fclose( $stream );
		}

		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		return $this->decode( $contents, basename( $file_path ) );
	}

	/**
	 * Alias for callers that treat an importer as a callable file reader.
	 *
	 * @return array|\WP_Error
	 */
	public function import( string $file_path ): array|\WP_Error {
		return $this->import_file( $file_path );
	}

	/**
	 * Decode already bounded JSON bytes, for example a streamed ZIP entry.
	 *
	 * @return array|\WP_Error
	 */
	public function decode( string $json, string $source_name = '' ): array|\WP_Error {
		if ( strlen( $json ) > self::MAX_FILE_SIZE ) {
			return $this->too_large_error( $source_name );
		}

		if ( 1 !== preg_match( '//u', $json ) ) {
			return $this->error(
				'content_factory_json_invalid_utf8',
				__( 'The JSON document must be valid UTF-8.', 'content-factory' ),
				400,
				$source_name
			);
		}

		try {
			$decoded = json_decode( $json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			$code    = JSON_ERROR_DEPTH === $exception->getCode()
				? 'content_factory_json_depth_exceeded'
				: 'content_factory_json_invalid';
			$message = JSON_ERROR_DEPTH === $exception->getCode()
				? sprintf(
					/* translators: %d: maximum JSON nesting depth. */
					__( 'The JSON document exceeds the maximum nesting depth of %d.', 'content-factory' ),
					self::MAX_DEPTH
				)
				: sprintf(
					/* translators: %s: JSON parser error. */
					__( 'The JSON document is invalid: %s', 'content-factory' ),
					$exception->getMessage()
				);

			return $this->error( $code, $message, 400, $source_name );
		}

		if ( ! is_array( $decoded ) ) {
			return $this->error(
				'content_factory_json_invalid_top_level',
				__( 'The JSON top level must be an object or an array of PageSpec objects.', 'content-factory' ),
				400,
				$source_name
			);
		}

		return $decoded;
	}

	/**
	 * @param resource $stream
	 * @return string|\WP_Error
	 */
	private function read_stream( $stream, string $source_name ): string|\WP_Error {
		$contents = '';

		while ( ! feof( $stream ) ) {
			$remaining = ( self::MAX_FILE_SIZE + 1 ) - strlen( $contents );
			if ( $remaining <= 0 ) {
				break;
			}

			$chunk = fread( $stream, min( 8192, $remaining ) );
			if ( false === $chunk ) {
				return $this->error(
					'content_factory_json_read_failed',
					__( 'The JSON file could not be read completely.', 'content-factory' ),
					400,
					$source_name
				);
			}

			if ( '' === $chunk && ! feof( $stream ) ) {
				return $this->error(
					'content_factory_json_read_failed',
					__( 'The JSON file stopped responding while it was being read.', 'content-factory' ),
					400,
					$source_name
				);
			}

			$contents .= $chunk;
		}

		if ( strlen( $contents ) > self::MAX_FILE_SIZE || ! feof( $stream ) ) {
			return $this->too_large_error( $source_name );
		}

		return $contents;
	}

	private function too_large_error( string $source_name ): \WP_Error {
		return new \WP_Error(
			'content_factory_json_file_too_large',
			sprintf(
				/* translators: %d: maximum file size in bytes. */
				__( 'The JSON document exceeds the maximum size of %d bytes.', 'content-factory' ),
				self::MAX_FILE_SIZE
			),
			array(
				'status'  => 413,
				'file'    => $this->display_name( $source_name ),
				'maxSize' => self::MAX_FILE_SIZE,
			)
		);
	}

	private function error( string $code, string $message, int $status, string $source_name ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status' => $status,
				'file'   => $this->display_name( $source_name ),
			)
		);
	}

	private function display_name( string $source_name ): string {
		return '' === $source_name ? '' : basename( str_replace( '\\', '/', $source_name ) );
	}
}
