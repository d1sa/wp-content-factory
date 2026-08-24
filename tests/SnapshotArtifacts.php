<?php

declare( strict_types=1 );

final class CF_Snapshot_Artifacts {
	/** @return array<string,mixed> */
	public static function context( ContentFactory\Adapter\ThemeAdapterInterface $adapter, array $spec, array $extra = array() ): array {
		$asset_urls = array();
		foreach ( array_keys( $adapter->compiled_profile()->assets() ) as $ref ) {
			$asset_urls[ $ref ] = 'https://fixtures.example.test/theme/' . rawurlencode( (string) $ref ) . '.jpg';
		}
		$anchors = array_values(
			array_filter(
				array_map(
					static fn( array $section ): string => is_string( $section['id'] ?? null ) ? $section['id'] : '',
					$spec['sections'] ?? array()
				)
			)
		);
		return array_merge(
			array(
				'anchors'          => $anchors,
				'parent_id'        => 0,
				'expected_path'    => '/' . sanitize_title( (string) ( $spec['post']['slug'] ?? 'fixture' ) ) . '/',
				'batch_ids'        => array(),
				'batch_paths'      => array(),
				'source_urls'      => array(),
				'theme_asset_urls' => $asset_urls,
			),
			$extra
		);
	}

	/** @param array<int,array<string,mixed>> $specs */
	public static function planned_paths( array $specs ): array {
		$by_id = array_column( $specs, null, 'sourceId' );
		$paths = array();
		$path_for = static function ( string $source_id ) use ( &$path_for, &$paths, $by_id ): string {
			if ( isset( $paths[ $source_id ] ) ) {
				return $paths[ $source_id ];
			}
			$spec   = $by_id[ $source_id ];
			$slug   = trim( (string) ( $spec['post']['slug'] ?? '' ), '/' );
			$parent = (string) ( $spec['post']['parent']['sourceId'] ?? '' );
			if ( '' !== $parent && isset( $by_id[ $parent ] ) ) {
				return $paths[ $source_id ] = rtrim( $path_for( $parent ), '/' ) . '/' . $slug . '/';
			}
			if ( ! empty( $spec['post']['parent']['path'] ) ) {
				return $paths[ $source_id ] = '/' . trim( (string) $spec['post']['parent']['path'], '/' ) . '/' . $slug . '/';
			}
			return $paths[ $source_id ] = '/' . $slug . '/';
		};
		foreach ( array_keys( $by_id ) as $source_id ) {
			$path_for( (string) $source_id );
		}
		return $paths;
	}

	/** @return array{blocks:array<int,array<string,mixed>>,postContent:string} */
	public static function output( ContentFactory\Adapter\ThemeAdapterInterface $adapter, ContentFactory\Build\GutenbergSerializer $serializer, array $spec, array $context ): array {
		$report = $adapter->validate( $spec, $context );
		if ( $report->has_errors() ) {
			throw new RuntimeException( 'Snapshot fixture is incompatible: ' . implode( ', ', self::issue_codes( $report ) ) );
		}
		$tree = $adapter->build( $spec, $context );
		return array(
			'blocks'      => array_map( static fn( ContentFactory\Contract\BlockNode $node ): array => $node->to_wp_block(), $tree ),
			'postContent' => $serializer->serialize( $tree ),
		);
	}

	/** @param array<int,array<string,mixed>> $specs */
	public static function corpus_hashes( ContentFactory\Adapter\ThemeAdapterInterface $adapter, ContentFactory\Build\GutenbergSerializer $serializer, array $specs ): array {
		$paths = self::planned_paths( $specs );
		$urls  = array_map( static fn( string $path ): string => 'https://fixtures.example.test' . $path, $paths );
		$ids   = array_fill_keys( array_keys( $paths ), 0 );
		$hashes = array();
		foreach ( $specs as $spec ) {
			$source_id = (string) $spec['sourceId'];
			$context = self::context(
				$adapter,
				$spec,
				array(
					'batch_ids'    => $ids,
					'batch_paths'  => $paths,
					'source_urls'  => $urls,
					'expected_path'=> $paths[ $source_id ],
				)
			);
			$output = self::output( $adapter, $serializer, $spec, $context );
			$hashes[ $source_id ] = array(
				'blockTreeSha256'  => hash( 'sha256', wp_json_encode( self::canonicalize( $output['blocks'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
				'postContentSha256'=> hash( 'sha256', $output['postContent'] ),
			);
		}
		ksort( $hashes, SORT_STRING );
		return $hashes;
	}

	public static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::canonicalize( $child );
		}
		return $value;
	}

	private static function issue_codes( ContentFactory\Contract\CompatibilityReport $report ): array {
		return array_map( static fn( $issue ): string => (string) ( $issue->jsonSerialize()['code'] ?? '' ), $report->issues() );
	}
}
