<?php

namespace ContentFactory\Contract;

defined( 'ABSPATH' ) || exit;

final class ContractAuditor {
	public function __construct( private ?BlockRegistrySnapshot $registry_snapshot = null ) {
		$this->registry_snapshot ??= new BlockRegistrySnapshot();
	}

	/**
	 * Audit a compiled profile without changing runtime state.
	 *
	 * Optional context keys:
	 * - registry: captured Registry map;
	 * - fieldConsumers: section => semantic field names;
	 * - emittedAttributes: block name => emitted attribute names;
	 * - profiles: other profile arrays for duplicate-target detection;
	 * - compatibleProfileIds: profile IDs matching the same configured site.
	 */
	public function audit( array $profile, array $context = array() ): CompatibilityReport {
		$report = new CompatibilityReport();
		if ( ! array_key_exists( 'policies', $context ) && is_array( $profile['policies'] ?? null ) ) {
			$context['policies'] = $profile['policies'];
		}
		$sections = is_array( $profile['sections'] ?? null ) ? $profile['sections'] : array();
		$block_names = $this->block_names( $profile );
		$registry = is_array( $context['registry'] ?? null ) ? $context['registry'] : $this->registry_snapshot->capture( $block_names );

		foreach ( $sections as $section_type => $definition ) {
			if ( ! is_string( $section_type ) || ! is_array( $definition ) ) {
				$report->add( ValidationIssue::error( 'INVALID_SECTION_DEFINITION', '/sections', 'Определение semantic section должно быть объектом.', '', '', 'section object' ) );
				continue;
			}
			$this->audit_section( $section_type, $definition, $registry, $context, $report );
		}
		$this->audit_root_blueprint( $profile, $registry, $report );
		$this->audit_page_types( $profile, $sections, $report );
		$this->audit_registry_contracts( $profile, $registry, $report );
		$this->audit_emitted_attributes( $profile, $context, $report );
		$this->audit_profiles( $profile, $context, $report );
		return $report;
	}

	private function audit_section( string $type, array $definition, array $registry, array $context, CompatibilityReport $report ): void {
		$base = '/sections/' . $this->pointer_escape( $type );
		$schema = is_array( $definition['schema'] ?? null ) ? $definition['schema'] : array();
		$properties = is_array( $schema['properties'] ?? null ) ? array_keys( $schema['properties'] ) : array();
		$required = is_array( $schema['required'] ?? null ) ? array_values( $schema['required'] ) : array();

		if ( isset( $definition['allowedData'] ) && ! $this->same_set( $properties, (array) $definition['allowedData'] ) ) {
			$report->add( ValidationIssue::error( 'ALLOWED_DATA_SCHEMA_DRIFT', $base . '/allowedData', 'allowedData расходится с keys(schema.properties).', '', $type, implode( ', ', $properties ) ) );
		}
		if ( isset( $definition['requiredData'] ) && ! $this->same_set( $required, (array) $definition['requiredData'] ) ) {
			$report->add( ValidationIssue::error( 'REQUIRED_DATA_SCHEMA_DRIFT', $base . '/requiredData', 'requiredData расходится со schema.required.', '', $type, implode( ', ', $required ) ) );
		}

		$this->audit_collection_policy( $type, $schema, $context['policies'] ?? null, $base, $report );
		$this->audit_defaults( $definition, $schema, $base, $type, $report );
		$this->audit_field_consumers( $type, $definition, $properties, $context, $base, $report );

		$block_name = is_string( $definition['blockName'] ?? null ) ? $definition['blockName'] : '';
		if ( '' === $block_name || ! isset( $registry[ $block_name ] ) ) {
			$report->add( ValidationIssue::error( 'BLOCK_NOT_REGISTERED', $base . '/blockName', 'Mapped Gutenberg block отсутствует в Registry.', '', $type, $block_name ) );
		} else {
			$this->audit_mapping_targets( $definition, $block_name, $registry[ $block_name ], $base, $type, $report );
			if ( isset( $definition['allowedChildren'] ) ) {
				$this->audit_allowed_children( (array) $definition['allowedChildren'], $block_name, $registry, $base . '/allowedChildren', $type, $report );
			}
		}

		$child_name = is_string( $definition['childBlockName'] ?? null ) ? $definition['childBlockName'] : '';
		if ( '' === $child_name ) {
			return;
		}
		if ( ! isset( $registry[ $child_name ] ) ) {
			$report->add( ValidationIssue::error( 'CHILD_BLOCK_NOT_REGISTERED', $base . '/childBlockName', 'Mapped child block отсутствует в Registry.', '', $type, $child_name ) );
			return;
		}
		$parents = is_array( $registry[ $child_name ]['parent'] ?? null ) ? $registry[ $child_name ]['parent'] : array();
		if ( ! in_array( $block_name, $parents, true ) ) {
			$report->add( ValidationIssue::error( 'BLOCK_PARENT_CONFLICT', $base . '/childBlockName', 'Child block не разрешает mapped parent.', '', $type, $block_name ) );
		}
		$allowed = is_array( $registry[ $block_name ]['allowedBlocks'] ?? null ) ? $registry[ $block_name ]['allowedBlocks'] : array();
		if ( $allowed && ! in_array( $child_name, $allowed, true ) ) {
			$report->add( ValidationIssue::error( 'BLOCK_ALLOWED_CHILD_CONFLICT', $base . '/childBlockName', 'Parent allowedBlocks не содержит mapped child.', '', $type, $child_name ) );
		}
		$this->audit_child_mapping_targets( $definition, $child_name, $registry[ $child_name ], $base, $type, $report );
	}

	private function audit_root_blueprint( array $profile, array $registry, CompatibilityReport $report ): void {
		foreach ( is_array( $profile['rootBlueprint'] ?? null ) ? $profile['rootBlueprint'] : array() as $index => $root ) {
			if ( ! is_array( $root ) || ! is_string( $root['blockName'] ?? null ) ) {
				continue;
			}
			$block_name = $root['blockName'];
			$base = '/rootBlueprint/' . $index;
			if ( ! isset( $registry[ $block_name ] ) ) {
				$report->add( ValidationIssue::error( 'ROOT_BLOCK_NOT_REGISTERED', $base . '/blockName', 'Root blueprint block отсутствует в Registry.', '', '', $block_name ) );
				continue;
			}
			if ( isset( $root['allowedChildren'] ) ) {
				$this->audit_allowed_children( (array) $root['allowedChildren'], $block_name, $registry, $base . '/allowedChildren', '', $report );
			}
		}
	}

	private function audit_allowed_children( array $declared, string $block_name, array $registry, string $path, string $section_type, CompatibilityReport $report ): void {
		$declared = array_values( array_unique( array_filter( $declared, 'is_string' ) ) );
		$actual = is_array( $registry[ $block_name ]['allowedBlocks'] ?? null ) ? array_values( $registry[ $block_name ]['allowedBlocks'] ) : array();
		if ( ! $this->same_set( $declared, $actual ) ) {
			$report->add( ValidationIssue::error( 'BLOCK_ALLOWED_CHILDREN_DRIFT', $path, 'allowedChildren профиля расходится с block.json allowedBlocks.', '', $section_type, implode( ', ', $actual ) ) );
		}
		foreach ( $declared as $child_name ) {
			if ( ! isset( $registry[ $child_name ] ) ) {
				$report->add( ValidationIssue::error( 'ALLOWED_CHILD_NOT_REGISTERED', $path, 'Разрешённый child block отсутствует в Registry.', '', $section_type, $child_name ) );
			}
		}
	}

	private function audit_collection_policy( string $type, array $schema, mixed $policies, string $base, CompatibilityReport $report ): void {
		if ( ! is_array( $policies ) ) {
			return;
		}
		$policy_key = array( 'steps' => 'stepsItems', 'faq' => 'faqItems', 'catalog' => 'catalogItems' )[ $type ] ?? '';
		if ( '' === $policy_key || ! is_array( $policies[ $policy_key ] ?? null ) ) {
			return;
		}
		$items_schema = is_array( $schema['properties']['items'] ?? null ) ? $schema['properties']['items'] : array();
		foreach ( array( 'min' => 'minItems', 'max' => 'maxItems' ) as $policy_field => $schema_field ) {
			if ( array_key_exists( $policy_field, $policies[ $policy_key ] ) && array_key_exists( $schema_field, $items_schema ) && (int) $policies[ $policy_key ][ $policy_field ] !== (int) $items_schema[ $schema_field ] ) {
				$report->add( ValidationIssue::error( 'COLLECTION_POLICY_SCHEMA_DRIFT', $base . '/schema/properties/items/' . $schema_field, 'Ограничение items расходится с policy профиля.', '', $type, (string) $policies[ $policy_key ][ $policy_field ] ) );
			}
		}
	}

	private function audit_field_consumers( string $type, array $definition, array $properties, array $context, string $base, CompatibilityReport $report ): void {
		$consumers = array();
		if ( is_array( $context['fieldConsumers'][ $type ] ?? null ) ) {
			$consumers = $context['fieldConsumers'][ $type ];
		} elseif ( is_array( $definition['consumers'] ?? null ) ) {
			$consumers = $definition['consumers'];
		}
		$exempt = array_merge(
			(array) ( $definition['validationOnly'] ?? array() ),
			(array) ( $definition['control'] ?? array() ),
			(array) ( $definition['extension'] ?? array() ),
			array_keys( is_array( $definition['ignored'] ?? null ) ? $definition['ignored'] : array() )
		);
		if ( ! $consumers && $properties ) {
			$report->add( ValidationIssue::warning( 'FIELD_CONSUMER_INVENTORY_MISSING', $base, 'Для section нет исполняемого consumer inventory; silent-loss audit неполон.', '', $type, 'bindings, consumers or explicit exemptions' ) );
			return;
		}
		foreach ( array_diff( $properties, array_unique( array_merge( $consumers, $exempt ) ) ) as $field ) {
			$report->add( ValidationIssue::error( 'SEMANTIC_FIELD_WITHOUT_CONSUMER', $base . '/schema/properties/' . $this->pointer_escape( (string) $field ), 'Semantic field не имеет builder consumer и не помечено явным исключением.', '', $type, 'mapped or explicitly classified field' ) );
		}
	}

	private function audit_mapping_targets( array $definition, string $block_name, array $registry_block, string $base, string $type, CompatibilityReport $report ): void {
		$targets = is_array( $definition['mappingAttributes'] ?? null ) ? $definition['mappingAttributes'] : array();
		$attributes = is_array( $registry_block['attributes'] ?? null ) ? $registry_block['attributes'] : array();
		foreach ( $targets as $attribute ) {
			if ( is_string( $attribute ) && ! array_key_exists( $attribute, $attributes ) ) {
				$report->add( ValidationIssue::error( 'MAPPING_TARGET_MISSING', $base . '/mappingAttributes', 'Mapping target отсутствует в Registry.', '', $type, $block_name . '.' . $attribute ) );
			}
		}
	}

	private function audit_child_mapping_targets( array $definition, string $block_name, array $registry_block, string $base, string $type, CompatibilityReport $report ): void {
		$targets = is_array( $definition['childMappingAttributes'] ?? null ) ? $definition['childMappingAttributes'] : array();
		$attributes = is_array( $registry_block['attributes'] ?? null ) ? $registry_block['attributes'] : array();
		foreach ( $targets as $attribute ) {
			if ( is_string( $attribute ) && ! array_key_exists( $attribute, $attributes ) ) {
				$report->add( ValidationIssue::error( 'CHILD_MAPPING_TARGET_MISSING', $base . '/childMappingAttributes', 'Child mapping target отсутствует в Registry.', '', $type, $block_name . '.' . $attribute ) );
			}
		}
	}

	private function audit_defaults( array $definition, array $schema, string $base, string $type, CompatibilityReport $report ): void {
		$defaults = is_array( $definition['defaults'] ?? null ) ? $definition['defaults'] : array();
		foreach ( $defaults as $field => $value ) {
			$field_schema = $schema['properties'][ $field ] ?? null;
			if ( ! is_array( $field_schema ) || ! $this->value_matches_schema( $value, $field_schema ) ) {
				$report->add( ValidationIssue::error( 'DEFAULT_SCHEMA_CONFLICT', $base . '/defaults/' . $this->pointer_escape( (string) $field ), 'Default несовместим со schema semantic field.', '', $type, 'schema-compatible default' ) );
			}
		}
	}

	private function audit_page_types( array $profile, array $sections, CompatibilityReport $report ): void {
		foreach ( is_array( $profile['pageTypes'] ?? null ) ? $profile['pageTypes'] : array() as $page_type => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$references = array_keys( is_array( $definition['occurrences'] ?? null ) ? $definition['occurrences'] : array() );
			foreach ( is_array( $definition['recipe'] ?? null ) ? $definition['recipe'] : array() as $row ) {
				if ( is_array( $row ) && is_string( $row['section'] ?? null ) ) {
					$references[] = $row['section'];
				}
			}
			foreach ( array_unique( $references ) as $section ) {
				if ( ! array_key_exists( $section, $sections ) ) {
					$report->add( ValidationIssue::error( 'PAGE_RECIPE_UNKNOWN_SECTION', '/pageTypes/' . $this->pointer_escape( (string) $page_type ), 'Page recipe ссылается на неизвестную semantic section.', '', '', (string) $section ) );
				}
			}
		}
	}

	private function audit_registry_contracts( array $profile, array $registry, CompatibilityReport $report ): void {
		$contracts = is_array( $profile['policies']['registryContracts'] ?? null ) ? $profile['policies']['registryContracts'] : array();
		foreach ( $contracts as $block_name => $contract ) {
			$base = '/policies/registryContracts/' . $this->pointer_escape( (string) $block_name );
			if ( ! isset( $registry[ $block_name ] ) ) {
				$report->add( ValidationIssue::error( 'REGISTRY_CONTRACT_BLOCK_MISSING', $base, 'Registry contract ссылается на незарегистрированный block.', '', '', (string) $block_name ) );
				continue;
			}
			foreach ( is_array( $contract['attributes'] ?? null ) ? $contract['attributes'] : array() as $attribute => $expected ) {
				$actual = $registry[ $block_name ]['attributes'][ $attribute ] ?? null;
				$expected = is_array( $expected ) ? $expected : array( 'type' => $expected );
				if ( ! is_array( $actual ) ) {
					$report->add( ValidationIssue::error( 'REGISTRY_CONTRACT_ATTRIBUTE_MISSING', $base . '/attributes/' . $this->pointer_escape( (string) $attribute ), 'Registry contract attribute отсутствует в Registry.', '', '', (string) ( $expected['type'] ?? '' ) ) );
					continue;
				}
				if ( isset( $expected['type'] ) && ( $actual['type'] ?? null ) !== $expected['type'] ) {
					$report->add( ValidationIssue::error( 'REGISTRY_CONTRACT_TYPE_DRIFT', $base . '/attributes/' . $this->pointer_escape( (string) $attribute ), 'Тип registry contract расходится с Registry.', '', '', (string) $expected['type'] ) );
				}
				if ( isset( $expected['enum'] ) && ! $this->same_set( (array) $expected['enum'], (array) ( $actual['enum'] ?? array() ) ) ) {
					$report->add( ValidationIssue::error( 'REGISTRY_CONTRACT_ENUM_DRIFT', $base . '/attributes/' . $this->pointer_escape( (string) $attribute ), 'Enum registry contract расходится с Registry.', '', '', implode( ', ', (array) $expected['enum'] ) ) );
				}
			}
			if ( isset( $contract['parent'] ) && ! $this->same_set( (array) $contract['parent'], (array) ( $registry[ $block_name ]['parent'] ?? array() ) ) ) {
				$report->add( ValidationIssue::error( 'REGISTRY_CONTRACT_PARENT_DRIFT', $base . '/parent', 'Parent registry contract расходится с Registry.', '', '', implode( ', ', (array) $contract['parent'] ) ) );
			}
		}
	}

	private function audit_emitted_attributes( array $profile, array $context, CompatibilityReport $report ): void {
		if ( ! is_array( $context['emittedAttributes'] ?? null ) ) {
			return;
		}
		$inventories = array();
		foreach ( is_array( $profile['sections'] ?? null ) ? $profile['sections'] : array() as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			if ( is_string( $definition['blockName'] ?? null ) ) {
				$inventories[ $definition['blockName'] ] = array_merge( $inventories[ $definition['blockName'] ] ?? array(), (array) ( $definition['mappingAttributes'] ?? array() ) );
			}
			if ( is_string( $definition['childBlockName'] ?? null ) ) {
				$inventories[ $definition['childBlockName'] ] = array_merge( $inventories[ $definition['childBlockName'] ] ?? array(), (array) ( $definition['childMappingAttributes'] ?? array() ) );
			}
		}
		foreach ( $context['emittedAttributes'] as $block_name => $attributes ) {
			foreach ( array_diff( (array) $attributes, array_unique( $inventories[ $block_name ] ?? array() ) ) as $attribute ) {
				$report->add( ValidationIssue::error( 'OUTPUT_ATTRIBUTE_NOT_IN_MAPPING_INVENTORY', '/output/' . $this->pointer_escape( (string) $block_name ) . '/' . $this->pointer_escape( (string) $attribute ), 'Builder output attribute отсутствует в mapping inventory.', '', '', (string) $attribute ) );
			}
		}
	}

	private function audit_profiles( array $profile, array $context, CompatibilityReport $report ): void {
		$profiles = array_merge( array( $profile ), is_array( $context['profiles'] ?? null ) ? $context['profiles'] : array() );
		$seen = array();
		foreach ( $profiles as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$site = (string) ( $candidate['identity']['siteKey'] ?? '' );
			$id = (string) ( $candidate['identity']['profileId'] ?? '' );
			if ( '' === $site || '' === $id ) {
				continue;
			}
			$key = $site . '|' . $id;
			if ( isset( $seen[ $key ] ) ) {
				$report->add( ValidationIssue::error( 'DUPLICATE_PROFILE_TARGET', '/profiles', 'Несколько profile providers объявляют один target.', '', '', $site . '/' . $id ) );
			}
			$seen[ $key ] = true;
		}
		$compatible = array_values( array_unique( array_filter( (array) ( $context['compatibleProfileIds'] ?? array() ), 'is_string' ) ) );
		if ( count( $compatible ) > 1 ) {
			$report->add( ValidationIssue::error( 'AMBIGUOUS_PROFILE', '/profiles', 'Несколько профилей совместимы с настроенным target.', '', '', implode( ', ', $compatible ) ) );
		}
	}

	private function block_names( array $profile ): array {
		$names = array_keys( is_array( $profile['policies']['registryContracts'] ?? null ) ? $profile['policies']['registryContracts'] : array() );
		foreach ( is_array( $profile['sections'] ?? null ) ? $profile['sections'] : array() as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			foreach ( array( $definition['blockName'] ?? '', $definition['childBlockName'] ?? '' ) as $name ) {
				if ( is_string( $name ) && '' !== $name ) {
					$names[] = $name;
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	private function value_matches_schema( mixed $value, array $schema ): bool {
		$type = $schema['type'] ?? null;
		$valid = match ( $type ) {
			'string'  => is_string( $value ),
			'boolean' => is_bool( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) && ! array_is_list( $value ),
			default   => true,
		};
		return $valid && ( ! isset( $schema['enum'] ) || in_array( $value, (array) $schema['enum'], true ) );
	}

	private function same_set( array $left, array $right ): bool {
		sort( $left );
		sort( $right );
		return $left === $right;
	}

	private function pointer_escape( string $value ): string {
		return str_replace( array( '~', '/' ), array( '~0', '~1' ), $value );
	}
}
