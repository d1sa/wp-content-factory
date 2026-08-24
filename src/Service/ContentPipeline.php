<?php

namespace ContentFactory\Service;

use ContentFactory\Adapter\AdapterRegistry;
use ContentFactory\Build\GutenbergSerializer;
use ContentFactory\Contract\CompatibilityReport;
use ContentFactory\Contract\ValidationIssue;
use ContentFactory\Resolve\HierarchyResolver;
use ContentFactory\Validation\CorePageSpecValidator;

defined( 'ABSPATH' ) || exit;

final class ContentPipeline {
	public function __construct(
		private AdapterRegistry $adapters,
		private CorePageSpecValidator $core,
		private HierarchyResolver $hierarchy,
		private GutenbergSerializer $serializer
	) {}

	public function process( array $spec, array $context = array() ): CompatibilityReport {
		$adapter = $this->adapters->active();
		if ( ! $adapter ) {
			return ( new CompatibilityReport() )->add( ValidationIssue::error( 'NO_ACTIVE_ADAPTER', '/', 'Для активной темы не найден адаптер.', $spec['sourceId'] ?? '', '', 'registered compatible adapter' ) );
		}
		[ $spec, $migrations ] = $this->apply_aliases( $spec, $adapter->manifest()['aliases'] ?? array() );
		$report = $this->core->validate( $spec );
		$report->set_context( 'migrations', $migrations );
		$report->set_context( 'defaultsApplied', $this->collect_defaults( $spec, $adapter->manifest() ) );
		foreach ( $migrations as $migration ) {
			$report->add( ValidationIssue::info( 'ALIAS_APPLIED', $migration['from'], 'Применён однозначный alias из manifest.', $spec['sourceId'] ?? '', '', $migration['to'] ) );
		}
		if ( $report->has_errors() ) {
			return $report;
		}
		$context['verify_targets'] = true;
		$report->merge( $adapter->validate( $spec, $context ) );
		$parent_id = $this->hierarchy->resolve_parent( $spec, $context['batch_ids'] ?? array() );
		if ( is_wp_error( $parent_id ) ) {
			$report->add( ValidationIssue::error( 'UNRESOLVED_PARENT', '/post/parent', $parent_id->get_error_message(), $spec['sourceId'] ?? '', '', 'existing parent or compatible batch parent' ) );
			$parent_id = 0;
		}
		$parent_source = $spec['post']['parent']['sourceId'] ?? '';
		if ( ! $parent_id && $parent_source && isset( $context['batch_paths'][ $parent_source ] ) ) {
			$expected_path = rtrim( $context['batch_paths'][ $parent_source ], '/' ) . '/' . sanitize_title( $spec['post']['slug'] ?? '' ) . '/';
		} else {
			$expected_path = $this->expected_path( $spec, $parent_id );
		}
		$report->set_context( 'resolved', array( 'parentId' => $parent_id, 'expectedPath' => $expected_path ) );
		$context['parent_id']     = $parent_id;
		$context['expected_path'] = $expected_path;
		$context['anchors']       = array_values( array_filter( array_map( static fn( array $section ): string => (string) ( $section['id'] ?? '' ), $spec['sections'] ?? array() ) ) );
		$this->validate_wordpress_conflicts( $spec, $adapter->manifest(), $expected_path, $parent_id, $report );
		if ( isset( $spec['seo']['canonical'] ) && is_string( $spec['seo']['canonical'] ) ) {
			$expected_canonical = home_url( $expected_path );
			if ( $this->normalize_url( $spec['seo']['canonical'] ) !== $this->normalize_url( $expected_canonical ) ) {
				$report->add( ValidationIssue::error( 'CANONICAL_MISMATCH', '/seo/canonical', 'canonical не совпадает с ожидаемым permalink.', $spec['sourceId'] ?? '', '', $expected_canonical ) );
			}
		}

		if ( $report->has_errors() ) {
			return $report;
		}
		try {
			$tree    = $adapter->build( $spec, $context );
			$content = $this->serializer->serialize( $tree );
			$round   = $this->serializer->round_trip( $tree, $content );
			if ( is_wp_error( $round ) ) {
				$report->add( ValidationIssue::error( 'GUTENBERG_ROUND_TRIP', '/sections', $round->get_error_message(), $spec['sourceId'] ?? '', '', 'lossless parse/serialize round-trip' ) );
			} else {
				$render_error = $this->test_render( $content );
				if ( is_wp_error( $render_error ) ) {
					$report->add( ValidationIssue::error( 'GUTENBERG_RENDER_FAILED', '/sections', $render_error->get_error_message(), $spec['sourceId'] ?? '', '', 'render without PHP errors or warnings' ) );
				} else {
					$report->set_context( 'plannedBlockTree', $tree );
					$report->set_context( 'postContent', $content );
				}
			}
		} catch ( \Throwable $error ) {
			$report->add( ValidationIssue::error( 'BUILD_FAILED', '/sections', $error->getMessage(), $spec['sourceId'] ?? '', '', 'valid semantic sections' ) );
		}
		$report->set_context( 'normalizedSpec', $spec );
		return $report;
	}

	private function test_render( string $content ): true|\WP_Error {
		try {
			set_error_handler(
				static function ( int $severity, string $message, string $file, int $line ): never {
					throw new \ErrorException( $message, 0, $severity, $file, $line );
				}
			);
			do_blocks( $content );
			return true;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'render_failed', $error->getMessage() );
		} finally {
			restore_error_handler();
		}
	}

	private function normalize_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = '/' . trim( (string) ( $parts['path'] ?? '/' ), '/' ) . '/';
		$user   = isset( $parts['user'] ) ? rawurlencode( (string) $parts['user'] ) . ( isset( $parts['pass'] ) ? ':' . rawurlencode( (string) $parts['pass'] ) : '' ) . '@' : '';
		$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		return $scheme . '://' . $user . $host . $port . $path . $query . $fragment;
	}

	private function apply_aliases( array $spec, mixed $aliases ): array {
		if ( ! is_array( $aliases ) ) {
			return array( $spec, array() );
		}
		$migrations = array();
		foreach ( $aliases as $from => $to ) {
			if ( ! is_string( $from ) || ! is_string( $to ) ) {
				continue;
			}
			$from_parts = explode( '.', $from );
			$to_parts   = explode( '.', $to );
			if ( count( $from_parts ) < 2 || count( $to_parts ) < 2 || $from_parts[0] !== $to_parts[0] ) {
				continue;
			}
			if ( ! isset( $spec['sections'] ) || ! is_array( $spec['sections'] ) ) {
				continue;
			}
			foreach ( $spec['sections'] as &$section ) {
				if ( ! is_array( $section ) || ( $section['type'] ?? '' ) !== $from_parts[0] ) {
					continue;
				}
				if ( ! is_array( $section['data'] ?? null ) || array_is_list( $section['data'] ) ) {
					continue;
				}
				if ( $this->move_alias_value( $section['data'], array_slice( $from_parts, 1 ), array_slice( $to_parts, 1 ) ) ) {
					$migrations[] = array( 'from' => $from, 'to' => $to, 'sectionId' => $section['id'] ?? '' );
				}
			}
			unset( $section );
		}
		return array( $spec, $migrations );
	}

	private function collect_defaults( array $spec, array $manifest ): array {
		$defaults = array();
		if ( ! array_key_exists( 'categoryLabel', $spec['post'] ?? array() ) ) {
			$defaults[] = array( 'path' => '/post/categoryLabel', 'value' => $manifest['siteDefaults']['categoryLabel'] ?? '' );
		}
		foreach ( $spec['sections'] ?? array() as $index => $section ) {
			if ( ! is_array( $section ) || ! is_array( $section['data'] ?? null ) ) {
				continue;
			}
			$type = $section['type'] ?? '';
			$fields = 'hero' === $type ? array( 'benefits', 'badge', 'note' ) : ( 'cta' === $type ? array( 'variant', 'kicker', 'benefits' ) : array() );
			foreach ( $fields as $field ) {
				if ( ! array_key_exists( $field, $section['data'] ) ) {
					$value = $manifest['siteDefaults'][ $type ][ $field ] ?? ( 'variant' === $field ? 'form' : null );
					if ( null !== $value ) {
						$defaults[] = array( 'path' => '/sections/' . $index . '/data/' . $field, 'value' => $value );
					}
				}
			}
		}
		return $defaults;
	}

	private function move_alias_value( array &$data, array $from, array $to ): bool {
		if ( 1 === count( $from ) && 1 === count( $to ) && array_key_exists( $from[0], $data ) && ! array_key_exists( $to[0], $data ) ) {
			$data[ $to[0] ] = $data[ $from[0] ];
			unset( $data[ $from[0] ] );
			return true;
		}
		if ( 2 === count( $from ) && 2 === count( $to ) && str_ends_with( $from[0], '[]' ) && $from[0] === $to[0] ) {
			$key = substr( $from[0], 0, -2 );
			if ( ! is_array( $data[ $key ] ?? null ) || ! array_is_list( $data[ $key ] ) ) {
				return false;
			}
			$moved = false;
			foreach ( $data[ $key ] as &$item ) {
				if ( is_array( $item ) && array_key_exists( $from[1], $item ) && ! array_key_exists( $to[1], $item ) ) {
					$item[ $to[1] ] = $item[ $from[1] ];
					unset( $item[ $from[1] ] );
					$moved = true;
				}
			}
			unset( $item );
			return $moved;
		}
		return false;
	}

	private function expected_path( array $spec, int $parent_id ): string {
		$slug = sanitize_title( $spec['post']['slug'] ?? '' );
		if ( $parent_id ) {
			$parent_uri = get_page_uri( $parent_id );
			return '/' . trim( $parent_uri . '/' . $slug, '/' ) . '/';
		}
		return '/' . trim( $slug, '/' ) . '/';
	}

	private function validate_wordpress_conflicts( array $spec, array $manifest, string $expected_path, int $parent_id, CompatibilityReport $report ): void {
		$source_id = (string) ( $spec['sourceId'] ?? '' );
		$managed = get_posts(
			array(
				'post_type' => 'page', 'post_status' => array( 'draft', 'publish', 'pending', 'private' ),
				'meta_key' => '_content_factory_source_id', 'meta_value' => $source_id,
				'posts_per_page' => 2,
			)
		);
		if ( count( $managed ) > 1 ) {
			$report->add( ValidationIssue::error( 'SOURCE_ID_CONFLICT', '/sourceId', 'Несколько WordPress pages используют один sourceId.', $source_id, '', 'unique managed page', 'Устраните конфликт managed metadata вручную.' ) );
		} elseif ( $managed && 'publish' === $managed[0]->post_status ) {
			$report->add( ValidationIssue::error( 'PUBLISHED_CONFLICT', '/sourceId', 'Опубликованная managed page не может быть перезаписана импортом.', $source_id, '', 'managed draft', 'Создайте отдельный workflow ручного обновления опубликованной страницы.' ) );
			$report->set_context( 'conflict', array( 'type' => 'published', 'postId' => $managed[0]->ID, 'editLink' => get_edit_post_link( $managed[0]->ID, 'raw' ) ) );
		}
		$requested_slug = sanitize_title( $spec['post']['slug'] ?? '' );
		$publish_slug = wp_unique_post_slug( $requested_slug, (int) ( $managed[0]->ID ?? 0 ), 'publish', 'page', $parent_id );
		if ( $publish_slug !== $requested_slug ) {
			$report->add( ValidationIssue::error( 'PUBLISH_SLUG_CONFLICT', '/post/slug', 'WordPress изменит slug при публикации из-за reserved name, attachment или другого конфликта.', $source_id, '', $publish_slug, 'Выберите slug, который WordPress сохранит без изменений.' ) );
		}

		$path_post = get_page_by_path( trim( $expected_path, '/' ), OBJECT, 'page' );
		if ( $path_post && ( ! $managed || (int) $managed[0]->ID !== (int) $path_post->ID ) ) {
			$report->add( ValidationIssue::error( 'PATH_CONFLICT', '/post/slug', 'Итоговый hierarchical path уже занят WordPress-страницей ID ' . $path_post->ID . '.', $source_id, '', 'unique path', 'Выберите другой slug/parent или устраните конфликт.' ) );
			$report->set_context( 'conflict', array( 'type' => 'path', 'postId' => $path_post->ID, 'editLink' => get_edit_post_link( $path_post->ID, 'raw' ) ) );
		}

		$template = (string) ( $manifest['postDefaults']['pageTemplate'] ?? '' );
		$templates = function_exists( 'wp_get_theme' ) ? wp_get_theme()->get_page_templates( null, 'page' ) : array();
		if ( '' === $template || ( ! array_key_exists( $template, $templates ) && ! in_array( $template, $templates, true ) ) ) {
			$report->add( ValidationIssue::error( 'PAGE_TEMPLATE_MISSING', '/post', 'Шаблон страницы адаптера отсутствует в активной теме.', $source_id, '', $template ) );
		}
		if ( ! defined( 'WPSEO_VERSION' ) && ! class_exists( 'WPSEO_Options' ) ) {
			$report->add( ValidationIssue::error( 'YOAST_UNAVAILABLE', '/seo', 'Yoast SEO должен быть активирован для импорта.', $source_id, '', 'active Yoast SEO' ) );
		}
	}
}
