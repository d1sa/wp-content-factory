<?php

namespace ContentFactory\Validation;

use ContentFactory\Contract\CompatibilityReport;
use ContentFactory\Contract\ValidationIssue;

defined( 'ABSPATH' ) || exit;

/** Documented structural subset; contextual rules remain named PHP rules. */
final class SemanticSchemaValidator {
	public function validate( mixed $value, array $schema, string $path, string $source_id = '', string $section_id = '' ): CompatibilityReport {
		$report = new CompatibilityReport();
		$this->walk( $value, $schema, $path, $source_id, $section_id, $report );
		return $report;
	}

	private function walk( mixed $value, array $schema, string $path, string $source_id, string $section_id, CompatibilityReport $report ): void {
		$type = $schema['type'] ?? null;
		if ( is_string( $type ) && ! $this->matches( $value, $type ) ) {
			$report->add( ValidationIssue::error( 'SCHEMA_TYPE', $path, 'Значение не соответствует semantic schema.', $source_id, $section_id, $type ) );
			return;
		}
		if ( isset( $schema['const'] ) && $value !== $schema['const'] ) {
			$report->add( ValidationIssue::error( 'SCHEMA_CONST', $path, 'Значение не совпадает с const semantic schema.', $source_id, $section_id, (string) $schema['const'] ) );
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$report->add( ValidationIssue::error( 'SCHEMA_ENUM', $path, 'Значение отсутствует в enum semantic schema.', $source_id, $section_id, implode( ', ', $schema['enum'] ) ) );
		}
		if ( is_string( $value ) ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
			if ( isset( $schema['minLength'] ) && $length < (int) $schema['minLength'] ) { $report->add( ValidationIssue::error( 'SCHEMA_MIN_LENGTH', $path, 'Строка короче semantic schema.', $source_id, $section_id, '>=' . (int) $schema['minLength'] ) ); }
			if ( isset( $schema['maxLength'] ) && $length > (int) $schema['maxLength'] ) { $report->add( ValidationIssue::error( 'SCHEMA_MAX_LENGTH', $path, 'Строка длиннее semantic schema.', $source_id, $section_id, '<=' . (int) $schema['maxLength'] ) ); }
			if ( isset( $schema['pattern'] ) && ! preg_match( '~' . str_replace( '~', '\\~', $schema['pattern'] ) . '~u', $value ) ) { $report->add( ValidationIssue::error( 'SCHEMA_PATTERN', $path, 'Строка не совпадает с pattern semantic schema.', $source_id, $section_id, $schema['pattern'] ) ); }
		}
		if ( is_array( $value ) && array_is_list( $value ) ) {
			$count = count( $value );
			if ( isset( $schema['minItems'] ) && $count < (int) $schema['minItems'] ) { $report->add( ValidationIssue::error( 'SCHEMA_MIN_ITEMS', $path, 'Недостаточно элементов semantic schema.', $source_id, $section_id, '>=' . (int) $schema['minItems'] ) ); }
			if ( isset( $schema['maxItems'] ) && $count > (int) $schema['maxItems'] ) { $report->add( ValidationIssue::error( 'SCHEMA_MAX_ITEMS', $path, 'Слишком много элементов semantic schema.', $source_id, $section_id, '<=' . (int) $schema['maxItems'] ) ); }
			foreach ( $value as $index => $item ) { if ( is_array( $schema['items'] ?? null ) ) { $this->walk( $item, $schema['items'], $path . '/' . $index, $source_id, $section_id, $report ); } }
		}
		if ( is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ) && ( 'object' === $type || isset( $schema['properties'] ) ) ) {
			foreach ( $schema['required'] ?? array() as $required ) { if ( ! array_key_exists( $required, $value ) ) { $report->add( ValidationIssue::error( 'SCHEMA_REQUIRED', $path . '/' . $required, 'Обязательное semantic-поле отсутствует.', $source_id, $section_id, $required ) ); } }
			if ( false === ( $schema['additionalProperties'] ?? true ) ) { foreach ( array_diff( array_keys( $value ), array_keys( $schema['properties'] ?? array() ) ) as $unknown ) { $report->add( ValidationIssue::error( 'SCHEMA_UNKNOWN_FIELD', $path . '/' . $unknown, 'Semantic schema не разрешает поле.', $source_id, $section_id ) ); } }
			foreach ( $schema['properties'] ?? array() as $key => $child_schema ) { if ( array_key_exists( $key, $value ) ) { $this->walk( $value[ $key ], $child_schema, $path . '/' . $key, $source_id, $section_id, $report ); } }
		}
	}

	private function matches( mixed $value, string $type ): bool {
		return match ( $type ) {
			'object' => is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ), 'array' => is_array( $value ) && array_is_list( $value ),
			'string' => is_string( $value ), 'number' => is_int( $value ) || is_float( $value ), 'integer' => is_int( $value ), 'boolean' => is_bool( $value ),
			default => true,
		};
	}
}
