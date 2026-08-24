<?php

namespace ContentFactory\Validation;

defined( 'ABSPATH' ) || exit;

final class PageSpecSchemaRegistry {
	public const CURRENT_VERSION = '1.1';

	private string $schema_dir;
	/** @var array<string,array<string,mixed>> */
	private array $cache = array();

	public function __construct( ?string $schema_dir = null ) {
		$this->schema_dir = rtrim( $schema_dir ?? CONTENT_FACTORY_DIR . 'schemas', '/\\' );
	}

	/** @return string[] */
	public function versions(): array {
		return array( self::CURRENT_VERSION );
	}

	public function current_version(): string {
		return self::CURRENT_VERSION;
	}

	public function supports( string $version ): bool {
		return in_array( $version, $this->versions(), true );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get( string $version ): array|\WP_Error {
		if ( ! $this->supports( $version ) ) {
			return new \WP_Error(
				'unsupported_pagespec_version',
				'Версия PageSpec не поддерживается.',
				array( 'status' => 404, 'version' => $version, 'supportedVersions' => $this->versions() )
			);
		}
		if ( isset( $this->cache[ $version ] ) ) {
			return $this->cache[ $version ];
		}

		$file = $this->schema_dir . '/pagespec-' . $version . '.schema.json';
		if ( ! is_readable( $file ) ) {
			return new \WP_Error( 'pagespec_schema_unavailable', 'Schema PageSpec не читается.', array( 'status' => 500, 'version' => $version ) );
		}
		try {
			$schema = json_decode( (string) file_get_contents( $file ), true, 128, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			return new \WP_Error( 'pagespec_schema_invalid', 'Schema PageSpec содержит некорректный JSON.', array( 'status' => 500, 'version' => $version, 'detail' => $error->getMessage() ) );
		}
		if ( ! is_array( $schema ) || array_is_list( $schema ) || $version !== ( $schema['properties']['schemaVersion']['const'] ?? null ) ) {
			return new \WP_Error( 'pagespec_schema_invalid', 'Schema PageSpec имеет неверную identity.', array( 'status' => 500, 'version' => $version ) );
		}
		$this->cache[ $version ] = $schema;
		return $schema;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function for_spec( array $spec ): array|\WP_Error {
		$version = is_string( $spec['schemaVersion'] ?? null ) ? $spec['schemaVersion'] : '';
		return $this->get( $version );
	}
}
