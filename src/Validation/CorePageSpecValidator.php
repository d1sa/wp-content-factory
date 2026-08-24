<?php

namespace ContentFactory\Validation;

use ContentFactory\Contract\CompatibilityReport;
use ContentFactory\Contract\ValidationIssue;

defined( 'ABSPATH' ) || exit;

final class CorePageSpecValidator {
	private const TOP_LEVEL = array( 'schemaVersion', 'sourceId', 'pageType', 'generatedAgainst', 'target', 'post', 'seo', 'sections' );

	public function validate( mixed $spec ): CompatibilityReport {
		$report = new CompatibilityReport();
		if ( ! is_array( $spec ) || array_is_list( $spec ) ) {
			return $report->add( ValidationIssue::error( 'INVALID_ENVELOPE', '/', 'PageSpec должен быть JSON-объектом.', '', '', 'object' ) );
		}

		$source_id = is_string( $spec['sourceId'] ?? null ) ? $spec['sourceId'] : '';
		$this->reject_unknown( $spec, self::TOP_LEVEL, '', $report, $source_id );
		foreach ( array( 'schemaVersion', 'sourceId', 'pageType', 'post', 'seo', 'sections' ) as $field ) {
			if ( ! array_key_exists( $field, $spec ) ) {
				$report->add( ValidationIssue::error( 'REQUIRED_FIELD', '/' . $field, 'Обязательное поле отсутствует.', $source_id, '', $field ) );
			}
		}
		if ( isset( $spec['status'] ) ) {
			$report->add( ValidationIssue::error( 'FORBIDDEN_FIELD', '/status', 'Статус публикации не принимается PageSpec.', $source_id, '', 'field omitted', 'Удалите status; импорт всегда создаёт draft.' ) );
		}
		if ( '1.0' !== ( $spec['schemaVersion'] ?? null ) ) {
			$report->add( ValidationIssue::error( 'UNSUPPORTED_SCHEMA_VERSION', '/schemaVersion', 'Версия PageSpec не поддерживается.', $source_id, '', '1.0' ) );
		}
		if ( ! is_string( $source_id ) || ! preg_match( '/^[a-z0-9][a-z0-9._-]{2,159}$/', $source_id ) ) {
			$report->add( ValidationIssue::error( 'INVALID_SOURCE_ID', '/sourceId', 'sourceId имеет недопустимый формат.', $source_id, '', '^[a-z0-9][a-z0-9._-]{2,159}$' ) );
		}
		if ( ! is_string( $spec['pageType'] ?? null ) || '' === trim( $spec['pageType'] ?? '' ) ) {
			$report->add( ValidationIssue::error( 'INVALID_PAGE_TYPE', '/pageType', 'pageType должен быть непустой строкой.', $source_id, '', 'non-empty string' ) );
		}
		$this->validate_post( $spec['post'] ?? null, $source_id, $report );
		$this->validate_seo( $spec['seo'] ?? null, $source_id, $report );
		$this->validate_metadata( $spec, $source_id, $report );
		$this->validate_sections( $spec['sections'] ?? null, $source_id, $report );

		return $report;
	}

	private function validate_post( mixed $post, string $source_id, CompatibilityReport $report ): void {
		if ( ! is_array( $post ) || array_is_list( $post ) ) {
			$report->add( ValidationIssue::error( 'INVALID_TYPE', '/post', 'post должен быть объектом.', $source_id, '', 'object' ) );
			return;
		}
		$this->reject_unknown( $post, array( 'title', 'slug', 'parent', 'categoryLabel' ), '/post', $report, $source_id );
		foreach ( array( 'title', 'slug' ) as $field ) {
			if ( ! isset( $post[ $field ] ) || ! is_string( $post[ $field ] ) || '' === trim( $post[ $field ] ) ) {
				$report->add( ValidationIssue::error( 'REQUIRED_FIELD', '/post/' . $field, 'Обязательное поле должно быть непустой строкой.', $source_id, '', 'non-empty string' ) );
			}
		}
		if ( isset( $post['slug'] ) && ( ! is_string( $post['slug'] ) || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $post['slug'] ) ) ) {
			$report->add( ValidationIssue::error( 'INVALID_SLUG', '/post/slug', 'Slug имеет недопустимый формат.', $source_id, '', '^[a-z0-9][a-z0-9-]*$' ) );
		}
		$this->validate_optional_string( $post, 'categoryLabel', '/post/categoryLabel', $source_id, $report );
		if ( isset( $post['parent'] ) ) {
			$parent = $post['parent'];
			if ( ! is_array( $parent ) || array_is_list( $parent ) ) {
				$report->add( ValidationIssue::error( 'INVALID_PARENT', '/post/parent', 'parent должен быть объектом.', $source_id, '', '{sourceId} or {path}' ) );
			} else {
				$this->reject_unknown( $parent, array( 'sourceId', 'path' ), '/post/parent', $report, $source_id );
				$has_source = isset( $parent['sourceId'] ) && is_string( $parent['sourceId'] ) && '' !== $parent['sourceId'];
				$has_path   = isset( $parent['path'] ) && is_string( $parent['path'] ) && '' !== $parent['path'];
				if ( $has_source === $has_path ) {
					$report->add( ValidationIssue::error( 'INVALID_PARENT', '/post/parent', 'Укажите ровно один способ разрешения родителя.', $source_id, '', 'sourceId xor path' ) );
				}
			}
		}
	}

	private function validate_seo( mixed $seo, string $source_id, CompatibilityReport $report ): void {
		if ( ! is_array( $seo ) || array_is_list( $seo ) ) {
			$report->add( ValidationIssue::error( 'INVALID_TYPE', '/seo', 'seo должен быть объектом.', $source_id, '', 'object' ) );
			return;
		}
		$this->reject_unknown( $seo, array( 'title', 'description', 'primaryKeyword', 'canonical' ), '/seo', $report, $source_id );
		foreach ( array( 'title', 'description' ) as $field ) {
			if ( ! isset( $seo[ $field ] ) || ! is_string( $seo[ $field ] ) || '' === trim( $seo[ $field ] ) ) {
				$report->add( ValidationIssue::error( 'REQUIRED_FIELD', '/seo/' . $field, 'SEO-поле должно быть непустой строкой.', $source_id, '', 'non-empty string' ) );
			}
		}
		if ( isset( $seo['title'] ) && is_string( $seo['title'] ) && mb_strlen( $seo['title'] ) > 65 ) {
			$report->add( ValidationIssue::warning( 'SEO_TITLE_LENGTH', '/seo/title', 'SEO Title длиннее рекомендуемых 65 символов.', $source_id, '', '<= 65 characters' ) );
		}
		if ( isset( $seo['description'] ) && is_string( $seo['description'] ) && mb_strlen( $seo['description'] ) > 170 ) {
			$report->add( ValidationIssue::warning( 'SEO_DESCRIPTION_LENGTH', '/seo/description', 'SEO Description длиннее рекомендуемых 170 символов.', $source_id, '', '<= 170 characters' ) );
		}
		if ( isset( $seo['canonical'] ) && ( ! is_string( $seo['canonical'] ) || ! wp_http_validate_url( $seo['canonical'] ) ) ) {
			$report->add( ValidationIssue::error( 'INVALID_CANONICAL', '/seo/canonical', 'canonical должен быть безопасным абсолютным HTTP(S) URL.', $source_id, '', 'https://…' ) );
		}
		$this->validate_optional_string( $seo, 'primaryKeyword', '/seo/primaryKeyword', $source_id, $report );
	}

	private function validate_metadata( array $spec, string $source_id, CompatibilityReport $report ): void {
		foreach ( array( 'generatedAgainst', 'target' ) as $key ) {
			if ( isset( $spec[ $key ] ) && ( ! is_array( $spec[ $key ] ) || array_is_list( $spec[ $key ] ) ) ) {
				$report->add( ValidationIssue::error( 'INVALID_TYPE', '/' . $key, $key . ' должен быть объектом.', $source_id, '', 'object' ) );
			}
		}
		if ( isset( $spec['generatedAgainst'] ) && is_array( $spec['generatedAgainst'] ) ) {
			$this->reject_unknown( $spec['generatedAgainst'], array( 'profileId', 'profileVersion', 'manifestHash' ), '/generatedAgainst', $report, $source_id );
			foreach ( array( 'profileId', 'profileVersion', 'manifestHash' ) as $field ) {
				$this->validate_optional_string( $spec['generatedAgainst'], $field, '/generatedAgainst/' . $field, $source_id, $report );
			}
		}
		if ( isset( $spec['target'] ) && is_array( $spec['target'] ) ) {
			$this->reject_unknown( $spec['target'], array( 'siteKey', 'profileId' ), '/target', $report, $source_id );
			foreach ( array( 'siteKey', 'profileId' ) as $field ) {
				$this->validate_optional_string( $spec['target'], $field, '/target/' . $field, $source_id, $report );
			}
		}
	}

	private function validate_optional_string( array $data, string $field, string $path, string $source_id, CompatibilityReport $report ): void {
		if ( array_key_exists( $field, $data ) && ! is_string( $data[ $field ] ) ) {
			$report->add( ValidationIssue::error( 'INVALID_TYPE', $path, $field . ' должен быть строкой.', $source_id, '', 'string' ) );
		}
	}

	private function validate_sections( mixed $sections, string $source_id, CompatibilityReport $report ): void {
		if ( ! is_array( $sections ) || ! array_is_list( $sections ) || ! $sections ) {
			$report->add( ValidationIssue::error( 'INVALID_SECTIONS', '/sections', 'sections должен быть непустым массивом.', $source_id, '', 'non-empty array' ) );
			return;
		}
		$ids = array();
		foreach ( $sections as $index => $section ) {
			$base = '/sections/' . $index;
			if ( ! is_array( $section ) || array_is_list( $section ) ) {
				$report->add( ValidationIssue::error( 'INVALID_TYPE', $base, 'Секция должна быть объектом.', $source_id, '', 'object' ) );
				continue;
			}
			$this->reject_unknown( $section, array( 'id', 'type', 'data' ), $base, $report, $source_id );
			$section_id = is_string( $section['id'] ?? null ) ? $section['id'] : '';
			foreach ( array( 'id', 'type', 'data' ) as $field ) {
				if ( ! array_key_exists( $field, $section ) ) {
					$report->add( ValidationIssue::error( 'REQUIRED_FIELD', $base . '/' . $field, 'Обязательное поле секции отсутствует.', $source_id, $section_id, $field ) );
				}
			}
			if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $section_id ) ) {
				$report->add( ValidationIssue::error( 'INVALID_SECTION_ID', $base . '/id', 'ID секции имеет недопустимый формат.', $source_id, $section_id, '^[a-z0-9][a-z0-9-]*$' ) );
			} elseif ( isset( $ids[ $section_id ] ) ) {
				$report->add( ValidationIssue::error( 'DUPLICATE_SECTION_ID', $base . '/id', 'ID секции повторяется.', $source_id, $section_id, 'unique id' ) );
			}
			$ids[ $section_id ] = true;
			if ( ! is_string( $section['type'] ?? null ) || '' === trim( $section['type'] ?? '' ) ) {
				$report->add( ValidationIssue::error( 'INVALID_SECTION_TYPE', $base . '/type', 'Тип секции должен быть непустой строкой.', $source_id, $section_id, 'non-empty string' ) );
			}
			if ( ! is_array( $section['data'] ?? null ) || array_is_list( $section['data'] ?? array() ) ) {
				$report->add( ValidationIssue::error( 'INVALID_TYPE', $base . '/data', 'data секции должен быть объектом.', $source_id, $section_id, 'object' ) );
			}
		}
	}

	private function reject_unknown( array $data, array $allowed, string $base, CompatibilityReport $report, string $source_id, string $section_id = '' ): void {
		foreach ( array_diff( array_keys( $data ), $allowed ) as $field ) {
			$report->add( ValidationIssue::error( 'UNKNOWN_FIELD', $base . '/' . $this->pointer_escape( (string) $field ), 'Неизвестное поле не может быть сохранено без потери.', $source_id, $section_id, implode( ', ', $allowed ), 'Удалите поле или исправьте его имя.' ) );
		}
	}

	private function pointer_escape( string $value ): string {
		return str_replace( array( '~', '/' ), array( '~0', '~1' ), $value );
	}
}
