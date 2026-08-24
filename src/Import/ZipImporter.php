<?php

namespace ContentFactory\Import;

defined( 'ABSPATH' ) || exit;

final class ZipImporter {
	public const MAX_FILES             = 100;
	public const MAX_UNCOMPRESSED_SIZE = 20971520;

	public function __construct( private ?JsonImporter $json_importer = null ) {
		$this->json_importer ??= new JsonImporter();
	}

	/**
	 * Read JSON PageSpecs from a ZIP without extracting files to disk.
	 *
	 * @return array<int,array{filename:string,data:array}>|\WP_Error
	 */
	public function import_file( string $file_path ): array|\WP_Error {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return $this->error(
				'content_factory_zip_unavailable',
				__( 'ZIP support is not available on this server.', 'content-factory' ),
				500,
				$file_path
			);
		}

		if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			return $this->error(
				'content_factory_zip_file_unreadable',
				__( 'The ZIP file does not exist or cannot be read.', 'content-factory' ),
				400,
				$file_path
			);
		}

		$zip         = new \ZipArchive();
		$open_result = $zip->open( $file_path, \ZipArchive::RDONLY );
		if ( true !== $open_result ) {
			return $this->error(
				'content_factory_zip_invalid',
				__( 'The ZIP archive is invalid or cannot be opened.', 'content-factory' ),
				400,
				$file_path,
				array( 'zipCode' => $open_result )
			);
		}

		try {
			$inspection = $this->inspect( $zip, $file_path );
			if ( is_wp_error( $inspection ) ) {
				return $inspection;
			}

			$documents   = array();
			$actual_total = 0;
			foreach ( $inspection as $entry ) {
				if ( $entry['skip'] ) {
					continue;
				}

				$stream = $zip->getStream( $entry['archive_name'] );
				if ( false === $stream ) {
					return $this->error(
						'content_factory_zip_entry_unreadable',
						sprintf(
							/* translators: %s: archive entry name. */
							__( 'The ZIP entry "%s" cannot be read.', 'content-factory' ),
							$entry['display_name']
						),
						400,
						$entry['display_name']
					);
				}

				try {
					$contents = $this->read_entry( $stream, $entry['display_name'] );
				} finally {
					fclose( $stream );
				}

				if ( is_wp_error( $contents ) ) {
					return $contents;
				}

				$actual_total += strlen( $contents );
				if ( $actual_total > self::MAX_UNCOMPRESSED_SIZE ) {
					return $this->uncompressed_size_error( $file_path );
				}

				$decoded = $this->json_importer->decode( $contents, $entry['display_name'] );
				if ( is_wp_error( $decoded ) ) {
					return $decoded;
				}

				$documents[] = array(
					'filename' => $entry['display_name'],
					'data'     => $decoded,
				);
			}

			if ( array() === $documents ) {
				return $this->error(
					'content_factory_zip_empty',
					__( 'The ZIP archive does not contain any JSON files.', 'content-factory' ),
					400,
					$file_path
				);
			}

			return $documents;
		} finally {
			$zip->close();
		}
	}

	/**
	 * @return array<int,array{filename:string,data:array}>|\WP_Error
	 */
	public function import( string $file_path ): array|\WP_Error {
		return $this->import_file( $file_path );
	}

	/**
	 * @return array<int,array{archive_name:string,display_name:string,skip:bool}>|\WP_Error
	 */
	private function inspect( \ZipArchive $zip, string $file_path ): array|\WP_Error {
		$entries            = array();
		$file_count         = 0;
		$uncompressed_total = 0;

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );
			if ( false === $stat || ! isset( $stat['name'], $stat['size'] ) ) {
				return $this->error(
					'content_factory_zip_entry_invalid',
					__( 'A ZIP entry has invalid metadata.', 'content-factory' ),
					400,
					$file_path
				);
			}

			$archive_name = (string) $stat['name'];
			$safe_name    = $this->validate_entry_name( $archive_name, $file_path );
			if ( is_wp_error( $safe_name ) ) {
				return $safe_name;
			}

			$is_directory = str_ends_with( $safe_name, '/' );
			if ( $this->is_symlink( $zip, $index ) ) {
				return $this->error(
					'content_factory_zip_unsafe_entry',
					sprintf(
						/* translators: %s: archive entry name. */
						__( 'The ZIP entry "%s" is a symbolic link and is not allowed.', 'content-factory' ),
						$safe_name
					),
					400,
					$safe_name
				);
			}

			if ( $is_directory ) {
				continue;
			}

			$file_count++;
			if ( $file_count > self::MAX_FILES ) {
				return $this->error(
					'content_factory_zip_too_many_files',
					sprintf(
						/* translators: %d: maximum number of archive files. */
						__( 'The ZIP archive contains more than %d files.', 'content-factory' ),
						self::MAX_FILES
					),
					413,
					$file_path,
					array( 'maxFiles' => self::MAX_FILES )
				);
			}

			$entry_size = (int) $stat['size'];
			if ( $entry_size < 0 ) {
				return $this->error(
					'content_factory_zip_entry_invalid',
					__( 'A ZIP entry reports an invalid uncompressed size.', 'content-factory' ),
					400,
					$safe_name
				);
			}

			$uncompressed_total += $entry_size;
			if ( $uncompressed_total > self::MAX_UNCOMPRESSED_SIZE ) {
				return $this->uncompressed_size_error( $file_path );
			}

			$skip = $this->is_hidden_or_system_entry( $safe_name );
			if ( ! $skip && 'json' !== strtolower( pathinfo( $safe_name, PATHINFO_EXTENSION ) ) ) {
				return $this->error(
					'content_factory_zip_non_json_entry',
					sprintf(
						/* translators: %s: archive entry name. */
						__( 'The ZIP entry "%s" is not a JSON file.', 'content-factory' ),
						$safe_name
					),
					400,
					$safe_name,
					array( 'expectedExtension' => '.json' )
				);
			}

			if ( ! $skip && $entry_size > JsonImporter::MAX_FILE_SIZE ) {
				return $this->error(
					'content_factory_json_file_too_large',
					sprintf(
						/* translators: 1: archive entry name, 2: maximum size in bytes. */
						__( 'The JSON entry "%1$s" exceeds the maximum size of %2$d bytes.', 'content-factory' ),
						$safe_name,
						JsonImporter::MAX_FILE_SIZE
					),
					413,
					$safe_name,
					array( 'maxSize' => JsonImporter::MAX_FILE_SIZE )
				);
			}

			$entries[] = array(
				'archive_name' => $archive_name,
				'display_name' => $safe_name,
				'skip'         => $skip,
			);
		}

		return $entries;
	}

	/**
	 * @return string|\WP_Error
	 */
	private function validate_entry_name( string $entry_name, string $file_path ): string|\WP_Error {
		if (
			'' === $entry_name ||
			str_contains( $entry_name, "\0" ) ||
			str_contains( $entry_name, '\\' ) ||
			str_starts_with( $entry_name, '/' ) ||
			str_starts_with( $entry_name, '//' ) ||
			1 === preg_match( '/^[A-Za-z]:\//', $entry_name )
		) {
			return $this->unsafe_path_error( $entry_name, $file_path );
		}

		$path = rtrim( $entry_name, '/' );
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return $this->unsafe_path_error( $entry_name, $file_path );
			}
		}

		return $entry_name;
	}

	private function is_hidden_or_system_entry( string $entry_name ): bool {
		$segments = explode( '/', trim( $entry_name, '/' ) );
		foreach ( $segments as $segment ) {
			if ( str_starts_with( $segment, '.' ) || '__MACOSX' === $segment ) {
				return true;
			}
		}

		$file_name = strtolower( end( $segments ) ?: '' );
		return in_array( $file_name, array( 'thumbs.db', 'desktop.ini' ), true ) ||
			in_array( strtolower( $segments[0] ?? '' ), array( 'system volume information', '$recycle.bin' ), true );
	}

	private function is_symlink( \ZipArchive $zip, int $index ): bool {
		$operating_system = 0;
		$attributes       = 0;
		if ( ! $zip->getExternalAttributesIndex( $index, $operating_system, $attributes ) ) {
			return false;
		}

		return 0120000 === ( ( $attributes >> 16 ) & 0170000 );
	}

	/**
	 * @param resource $stream
	 * @return string|\WP_Error
	 */
	private function read_entry( $stream, string $entry_name ): string|\WP_Error {
		$contents = '';

		while ( ! feof( $stream ) ) {
			$remaining = ( JsonImporter::MAX_FILE_SIZE + 1 ) - strlen( $contents );
			if ( $remaining <= 0 ) {
				break;
			}

			$chunk = fread( $stream, min( 8192, $remaining ) );
			if ( false === $chunk || ( '' === $chunk && ! feof( $stream ) ) ) {
				return $this->error(
					'content_factory_zip_entry_read_failed',
					sprintf(
						/* translators: %s: archive entry name. */
						__( 'The ZIP entry "%s" could not be read completely.', 'content-factory' ),
						$entry_name
					),
					400,
					$entry_name
				);
			}

			$contents .= $chunk;
		}

		if ( strlen( $contents ) > JsonImporter::MAX_FILE_SIZE || ! feof( $stream ) ) {
			return $this->error(
				'content_factory_json_file_too_large',
				sprintf(
					/* translators: 1: archive entry name, 2: maximum size in bytes. */
					__( 'The JSON entry "%1$s" exceeds the maximum size of %2$d bytes.', 'content-factory' ),
					$entry_name,
					JsonImporter::MAX_FILE_SIZE
				),
				413,
				$entry_name,
				array( 'maxSize' => JsonImporter::MAX_FILE_SIZE )
			);
		}

		return $contents;
	}

	private function unsafe_path_error( string $entry_name, string $file_path ): \WP_Error {
		return $this->error(
			'content_factory_zip_unsafe_path',
			sprintf(
				/* translators: %s: archive entry name. */
				__( 'The ZIP entry "%s" contains an unsafe path.', 'content-factory' ),
				$this->display_name( $entry_name )
			),
			400,
			$file_path
		);
	}

	private function uncompressed_size_error( string $file_path ): \WP_Error {
		return $this->error(
			'content_factory_zip_too_large',
			sprintf(
				/* translators: %d: maximum total uncompressed size in bytes. */
				__( 'The ZIP archive exceeds the maximum uncompressed size of %d bytes.', 'content-factory' ),
				self::MAX_UNCOMPRESSED_SIZE
			),
			413,
			$file_path,
			array( 'maxUncompressedSize' => self::MAX_UNCOMPRESSED_SIZE )
		);
	}

	private function error( string $code, string $message, int $status, string $source_name, array $extra_data = array() ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array_merge(
				array(
					'status' => $status,
					'file'   => $this->display_name( $source_name ),
				),
				$extra_data
			)
		);
	}

	private function display_name( string $source_name ): string {
		$normalized = str_replace( '\\', '/', $source_name );
		return basename( $normalized );
	}
}
