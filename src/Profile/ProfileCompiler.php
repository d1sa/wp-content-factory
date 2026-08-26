<?php

namespace ContentFactory\Profile;

use ContentFactory\Engine\MapperDefinitionRegistry;
use ContentFactory\Engine\TransformRegistry;
use ContentFactory\Validation\PageSpecSchemaRegistry;
use ContentFactory\VersionRegistry;

defined( 'ABSPATH' ) || exit;

/** Pure request-local compiler from the minimal authoring format to CompiledProfile. */
final class ProfileCompiler {
	/** @var array<string,CompiledProfile> */
	private static array $cache = array();

	public function __construct(
		private ?CanonicalJson $canonical = null,
		private ?MapperDefinitionRegistry $named_mappers = null
	) {
		$this->canonical     ??= new CanonicalJson();
		$this->named_mappers ??= new MapperDefinitionRegistry();
	}

	public function compile_file( string $path ): CompiledProfile {
		$signature = $path . ':' . ( is_file( $path ) ? (string) filemtime( $path ) . ':' . (string) filesize( $path ) : 'missing' );
		if ( isset( self::$cache[ $signature ] ) ) {
			return self::$cache[ $signature ];
		}
		$json = is_readable( $path ) ? file_get_contents( $path ) : false;
		if ( false === $json ) {
			throw new \RuntimeException( 'Profile definition недоступен: ' . $path );
		}
		try {
			$definition = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new \RuntimeException( 'Profile definition содержит некорректный JSON.', 0, $error );
		}
		if ( ! is_array( $definition ) || array_is_list( $definition ) ) {
			throw new \RuntimeException( 'Profile definition должен быть объектом.' );
		}
		return self::$cache[ $signature ] = $this->compile( $definition );
	}

	public function compile( array $definition ): CompiledProfile {
		$this->validate_definition( $definition );
		$identity      = $definition['identity'];
		$compatibility = $definition['compatibility'];
		$bindings      = array();
		$sections      = array();
		$contracts     = array();
		foreach ( $definition['sections'] as $type => $section ) {
			$binding = $section['binding'];
			$bindings[ $type ] = $binding;
			$root_attributes = $this->binding_attributes( $binding, $section['schema'] );
			$compiled = array(
				'blockName'        => $binding['blockName'],
				'allowedData'      => array_keys( $section['schema']['properties'] ?? array() ),
				'requiredData'     => array_values( $section['schema']['required'] ?? array() ),
				'schema'           => $section['schema'],
				'mappingAttributes'=> array_keys( $root_attributes ),
			);
			$contracts[ $binding['blockName'] ] = array( 'attributes' => $root_attributes );
			if ( 'generic' !== ( $binding['mapper'] ?? 'generic' ) ) {
				$contracts = array_replace( $contracts, $this->named_mappers->additional_contracts( (string) $binding['mapper'] ) );
			}
			if ( isset( $binding['repeat'] ) ) {
				$repeat = $binding['repeat'];
				$child_attributes = $this->generic_attributes( $repeat['attributes'] ?? array(), $section['schema'] );
				$compiled['childBlockName'] = $repeat['blockName'];
				$compiled['childMappingAttributes'] = array_keys( $child_attributes );
				$contracts[ $repeat['blockName'] ] = array( 'parent' => array( $binding['blockName'] ), 'attributes' => $child_attributes );
			}
			if ( isset( $binding['allowedChildren'] ) ) {
				$compiled['allowedChildren'] = $binding['allowedChildren'];
			}
			$sections[ $type ] = $compiled;
		}

		$root_blueprint = $definition['composition']['rootBlueprint'];
		foreach ( $root_blueprint as &$root ) {
			if ( isset( $root['mapper'] ) ) {
				$attributes = $this->named_mappers->attributes( (string) $root['mapper'] );
				$root['mappingAttributes'] = array_keys( $attributes );
				$contracts[ $root['blockName'] ] = array( 'attributes' => $attributes );
				unset( $root['mapper'] );
			}
		}
		unset( $root );
		$policies = $definition['policies'] ?? array();
		$policies['registryContracts'] = $contracts;
		$configuration = array(
			'identity' => $identity,
			'siteDefaultsVersion' => $definition['siteDefaults']['version'],
			'compatibility' => $compatibility['theme'],
			'postDefaults' => $definition['postDefaults'],
			'pageTypes' => $definition['pageTypes'],
			'sections' => $sections,
			'rootBlueprint' => $root_blueprint,
			'siteDefaults' => $definition['siteDefaults']['values'],
			'assets' => $definition['assets'],
			'policies' => $policies,
		);
		$contract = $this->contract_projection( $configuration );
		return new CompiledProfile( $configuration, $contract, $this->canonical->hash( $contract ), $bindings );
	}

	private function contract_projection( array $configuration ): array {
		$policies = $configuration['policies'];
		unset( $policies['registryContracts'] );
		$sections = array();
		foreach ( $configuration['sections'] as $type => $definition ) {
			$sections[ $type ] = array_intersect_key(
				$definition,
				array_flip( array( 'blockName', 'schema', 'mappingAttributes', 'childBlockName', 'childMappingAttributes', 'allowedChildren', 'validationOnly', 'control', 'extension' ) )
			);
		}
		return array(
			'identity' => $configuration['identity'],
			'compatibility' => $configuration['compatibility'],
			'pageSpecVersion' => PageSpecSchemaRegistry::CURRENT_VERSION,
			'postDefaults' => $configuration['postDefaults'],
			'pageTypes' => $configuration['pageTypes'],
			'sections' => $sections,
			'rootBlueprint' => $configuration['rootBlueprint'],
			'siteDefaultsVersion' => $configuration['siteDefaultsVersion'],
			'siteDefaults' => $configuration['siteDefaults'],
			'assets' => $configuration['assets'],
			'policies' => $policies,
		);
	}

	private function validate_definition( array $definition ): void {
		if ( defined( 'CONTENT_FACTORY_DIR' ) && function_exists( 'rest_validate_value_from_schema' ) ) {
			$schema_path = CONTENT_FACTORY_DIR . 'schemas/theme-profile-' . VersionRegistry::THEME_PROFILE_SCHEMA . '.schema.json';
			$schema = is_readable( $schema_path ) ? json_decode( (string) file_get_contents( $schema_path ), true ) : null;
			if ( is_array( $schema ) ) {
				$valid = rest_validate_value_from_schema( $definition, $schema, 'profile' );
				if ( is_wp_error( $valid ) ) {
					throw new \RuntimeException( 'Profile definition не проходит authoring schema: ' . $valid->get_error_message() );
				}
			}
		}
		foreach ( array( 'profileSchemaVersion', 'identity', 'compatibility', 'postDefaults', 'pageTypes', 'sections', 'composition', 'siteDefaults', 'assets' ) as $key ) {
			if ( ! array_key_exists( $key, $definition ) ) {
				throw new \RuntimeException( 'Profile definition не содержит ' . $key . '.' );
			}
		}
		if ( VersionRegistry::THEME_PROFILE_SCHEMA !== $definition['profileSchemaVersion'] ) {
			throw new \RuntimeException( 'Неподдерживаемая версия profile definition.' );
		}
		foreach ( array( 'profileId', 'profileVersion', 'siteKey' ) as $key ) {
			if ( ! is_string( $definition['identity'][ $key ] ?? null ) || '' === trim( $definition['identity'][ $key ] ) ) {
				throw new \RuntimeException( 'Profile identity.' . $key . ' обязателен.' );
			}
		}
		if ( ! is_string( $definition['siteDefaults']['version'] ?? null ) || '' === trim( $definition['siteDefaults']['version'] ) ) {
			throw new \RuntimeException( 'siteDefaults.version обязателен.' );
		}
		foreach ( $definition['sections'] as $type => $section ) {
			if ( ! is_array( $section['schema'] ?? null ) || 'object' !== ( $section['schema']['type'] ?? null ) || ! is_array( $section['binding'] ?? null ) ) {
				throw new \RuntimeException( 'Неполная section definition: ' . $type );
			}
			$this->validate_binding( $section['binding'], '/sections/' . $type . '/binding' );
		}
	}

	private function validate_binding( array $binding, string $path ): void {
		if ( ! is_string( $binding['blockName'] ?? null ) || '' === $binding['blockName'] ) {
			throw new \RuntimeException( $path . '/blockName обязателен.' );
		}
		$mapper = (string) ( $binding['mapper'] ?? 'generic' );
		if ( 'generic' !== $mapper ) {
			$this->named_mappers->attributes( $mapper );
			return;
		}
		foreach ( $binding['attributes'] ?? array() as $attribute => $expression ) {
			$this->validate_expression( $expression, $path . '/attributes/' . $attribute );
		}
		if ( isset( $binding['repeat'] ) ) {
			$this->validate_expression( array( 'transform'=>'repeat', 'source'=>$binding['repeat']['source'] ?? '' ), $path . '/repeat/source' );
			foreach ( $binding['repeat']['attributes'] ?? array() as $attribute => $expression ) {
				$this->validate_expression( $expression, $path . '/repeat/attributes/' . $attribute );
			}
		}
	}

	private function validate_expression( mixed $expression, string $path ): void {
		if ( ! is_array( $expression ) || ! in_array( $expression['transform'] ?? 'direct', TransformRegistry::ids(), true ) ) {
			throw new \RuntimeException( 'Неизвестный transform в ' . $path . '.' );
		}
	}

	private function binding_attributes( array $binding, array $schema ): array {
		$mapper = (string) ( $binding['mapper'] ?? 'generic' );
		return 'generic' === $mapper
			? $this->generic_attributes( $binding['attributes'] ?? array(), $schema )
			: $this->named_mappers->attributes( $mapper );
	}

	private function generic_attributes( array $attributes, array $schema ): array {
		$output = array();
		foreach ( $attributes as $name => $expression ) {
			$output[ $name ] = $this->expression_type( $expression, $schema );
		}
		return $output;
	}

	private function expression_type( array $expression, array $schema ): string|array {
		$id = (string) ( $expression['transform'] ?? 'direct' );
		if ( 'index' === $id ) {
			return 'firstBoolean' === ( $expression['mode'] ?? '' ) ? 'boolean' : 'string';
		}
		if ( in_array( $id, array( 'link', 'plainText', 'inlineRichText' ), true ) ) {
			return 'string';
		}
		if ( 'asset' === $id ) {
			return 'id' === ( $expression['part'] ?? '' ) ? 'number' : 'string';
		}
		$source = (string) ( $expression['source'] ?? ( $expression['sources'][0] ?? '' ) );
		if ( 'section.id' === $source ) {
			return 'string';
		}
		if ( str_starts_with( $source, 'data.' ) ) {
			$field = explode( '.', substr( $source, 5 ) )[0];
			return (string) ( $schema['properties'][ $field ]['type'] ?? 'string' );
		}
		if ( str_starts_with( $source, 'item.' ) ) {
			$field = explode( '.', substr( $source, 5 ) )[0];
			return (string) ( $schema['properties']['items']['items']['properties'][ $field ]['type'] ?? 'string' );
		}
		return is_bool( $expression['fallback'] ?? null ) ? 'boolean' : ( is_int( $expression['fallback'] ?? null ) ? 'number' : 'string' );
	}
}
