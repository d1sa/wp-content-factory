<?php

namespace ContentFactory\Adapter;

use ContentFactory\Contract\BlockNode;
use ContentFactory\Contract\CompatibilityReport;
use ContentFactory\Contract\ContractAuditor;
use ContentFactory\Contract\ValidationIssue;
use ContentFactory\Engine\DeclarativeSectionMapper;
use ContentFactory\Engine\MapperDefinitionRegistry;
use ContentFactory\Engine\TransformRegistry;
use ContentFactory\Profile\CompiledProfile;
use ContentFactory\Profile\ProfileCompiler;
use ContentFactory\Resolve\LinkResolver;
use ContentFactory\Validation\PageSpecSchemaRegistry;
use ContentFactory\Validation\SemanticSchemaValidator;

defined( 'ABSPATH' ) || exit;

final class PotolkiInnerAdapter implements ThemeAdapterInterface {
	private const PROFILE_ID = 'potolki-inner';

	private array $manifest;
	private LinkResolver $link_resolver;
	private CompiledProfile $profile;
	private SemanticSchemaValidator $semantic_validator;

	public function __construct( ?LinkResolver $link_resolver = null ) {
		$this->link_resolver = $link_resolver ?? new LinkResolver();
		$this->profile            = ( new ProfileCompiler() )->compile_file( dirname( __DIR__, 2 ) . '/adapters/potolki-inner/profile.json' );
		$this->manifest           = $this->profile->configuration();
		$this->semantic_validator = new SemanticSchemaValidator();
	}

	public function compiled_profile(): CompiledProfile { return $this->profile; }

	public function id(): string {
		return self::PROFILE_ID;
	}

	public function version(): string {
		return $this->profile->version();
	}

	public function supports_current_theme(): bool {
		if ( ! function_exists( 'get_stylesheet' ) ) {
			return false;
		}
		return in_array( get_stylesheet(), $this->manifest['compatibility']['stylesheet'] ?? array(), true );
	}

	public function manifest_hash(): string {
		return $this->profile->canonical_hash();
	}

	public function self_check(): CompatibilityReport {
		$report = new CompatibilityReport();
		if ( ! $this->supports_current_theme() ) {
			$report->add( ValidationIssue::error( 'THEME_MISMATCH', '/theme', 'Активная тема несовместима с адаптером potolki-inner.', '', '', implode( ', ', $this->manifest['compatibility']['stylesheet'] ?? array() ) ) );
		} elseif ( function_exists( 'wp_get_theme' ) ) {
			$current = (string) wp_get_theme()->get( 'Version' );
			$minimum = (string) ( $this->manifest['compatibility']['minVersion'] ?? '0.0.0' );
			if ( '' !== $current && version_compare( $current, $minimum, '<' ) ) {
				$report->add( ValidationIssue::error( 'THEME_VERSION', '/theme/version', 'Версия активной темы ниже минимальной.', '', '', '>=' . $minimum ) );
			}
		}

		$this->check_registry( array_keys( $this->registry_contracts() ), $report );
		foreach ( array_keys( $this->manifest['assets'] ?? array() ) as $ref ) {
			if ( ! $this->theme_asset_exists( (string) $ref, array() ) ) {
				$report->add( ValidationIssue::error( 'MISSING_THEME_ASSET', '/assets/' . $this->pointer_escape( (string) $ref ), 'Файл themeAsset из профиля не найден.', '', '', (string) $ref ) );
			}
		}
		$template = (string) ( $this->manifest['postDefaults']['pageTemplate'] ?? '' );
		if ( function_exists( 'wp_get_theme' ) ) {
			$templates = wp_get_theme()->get_page_templates( null, 'page' );
			if ( '' === $template || ( ! array_key_exists( $template, $templates ) && ! in_array( $template, $templates, true ) ) ) {
				$report->add( ValidationIssue::error( 'PAGE_TEMPLATE_MISSING', '/postDefaults/pageTemplate', 'Шаблон страницы профиля отсутствует в активной теме.', '', '', $template ) );
			}
		}
		if ( ! defined( 'WPSEO_VERSION' ) && ! class_exists( 'WPSEO_Options' ) ) {
			$report->add( ValidationIssue::error( 'YOAST_UNAVAILABLE', '/requirements/yoast', 'Yoast SEO должен быть активирован для полного импорта.', '', '', 'active Yoast SEO' ) );
		}
		$field_consumers = array();
		foreach ( $this->manifest['sections'] ?? array() as $type => $definition ) {
			$field_consumers[ $type ] = $this->binding_consumers( (string) $type );
		}
		$report->merge( ( new ContractAuditor() )->audit( $this->manifest, array( 'fieldConsumers' => $field_consumers ) ) );
		return $report
			->set_context( 'profileId', $this->id() )
			->set_context( 'profileVersion', $this->version() )
			->set_context( 'siteDefaultsVersion', $this->profile->defaults_version() )
			->set_context( 'manifestHash', $this->manifest_hash() );
	}

	public function validate( array $spec, array $context = array() ): CompatibilityReport {
		$report    = new CompatibilityReport();
		$source_id = is_string( $spec['sourceId'] ?? null ) ? $spec['sourceId'] : '';
		$schema_version = is_string( $spec['schemaVersion'] ?? null ) ? $spec['schemaVersion'] : '';
		if ( PageSpecSchemaRegistry::CURRENT_VERSION !== $schema_version ) {
			$report->add( ValidationIssue::error( 'UNSUPPORTED_SCHEMA_VERSION', '/schemaVersion', 'Версия PageSpec не поддерживается профилем.', $source_id, '', PageSpecSchemaRegistry::CURRENT_VERSION ) );
		}
		$this->validate_target( $spec, $source_id, $report );
		$this->validate_generated_against( $spec, $source_id, $report );

		$page_type = is_string( $spec['pageType'] ?? null ) ? $spec['pageType'] : '';
		$config    = $this->manifest['pageTypes'][ $page_type ] ?? null;
		if ( ! is_array( $config ) ) {
			$report->add( ValidationIssue::error( 'UNSUPPORTED_PAGE_TYPE', '/pageType', 'Тип страницы не поддерживается адаптером.', $source_id, '', implode( ', ', array_keys( $this->manifest['pageTypes'] ?? array() ) ) ) );
			return $report;
		}

		$sections = $spec['sections'] ?? array();
		if ( ! is_array( $sections ) || ! array_is_list( $sections ) ) {
			return $report->add( ValidationIssue::error( 'INVALID_SECTIONS', '/sections', 'sections должен быть массивом.', $source_id, '', 'array' ) );
		}

		$link_context = $this->link_context( $spec, $context );
		$counts       = array_fill_keys( array_keys( $this->manifest['sections'] ), 0 );
		$questions    = array();
		$used_blocks  = array( 'potolki/inner-content' => true );
		foreach ( $sections as $index => $section ) {
			if ( ! is_array( $section ) || array_is_list( $section ) ) {
				continue;
			}
			$type       = is_string( $section['type'] ?? null ) ? $section['type'] : '';
			$section_id = is_string( $section['id'] ?? null ) ? $section['id'] : '';
			$path       = '/sections/' . $index;
			if ( ! isset( $this->manifest['sections'][ $type ] ) ) {
				$report->add( ValidationIssue::error( 'UNSUPPORTED_SECTION_TYPE', $path . '/type', 'Тип секции не поддерживается адаптером.', $source_id, $section_id, implode( ', ', array_keys( $this->manifest['sections'] ) ) ) );
				continue;
			}
			++$counts[ $type ];
			$definition = $this->manifest['sections'][ $type ];
			$used_blocks[ $definition['blockName'] ] = true;
			if ( isset( $definition['childBlockName'] ) ) {
				$used_blocks[ $definition['childBlockName'] ] = true;
			}
			if ( 'article' === $type ) {
				foreach ( $section['data']['body'] ?? array() as $body_node ) {
					$body_type = is_array( $body_node ) ? ( $body_node['type'] ?? '' ) : '';
					$body_blocks = array(
						'paragraph' => array( 'core/paragraph' ),
						'heading'   => array( 'core/heading' ),
						'list'      => array( 'core/list', 'core/list-item' ),
						'buttons'   => array( 'core/buttons', 'core/button' ),
					);
					foreach ( $body_blocks[ $body_type ] ?? array() as $core_block ) {
						$used_blocks[ $core_block ] = true;
					}
				}
			}
			$this->validate_section( $type, $section, $path, $source_id, $link_context, $questions, $report, $context );
			$report->merge( $this->semantic_validator->validate( $section['data'] ?? array(), $definition['schema'] ?? array(), $path . '/data', $source_id, $section_id ) );
		}

		if ( $sections && 'hero' !== ( $sections[0]['type'] ?? '' ) ) {
			$report->add( ValidationIssue::error( 'SECTION_ORDER', '/sections/0/type', 'Hero должен быть первой semantic-секцией.', $source_id, '', 'hero' ) );
		}
		foreach ( $config['occurrences'] as $type => $range ) {
			$count   = $counts[ $type ] ?? 0;
			$minimum = (int) ( $range['min'] ?? 0 );
			$maximum = array_key_exists( 'max', $range ) ? (int) $range['max'] : null;
			if ( $count < $minimum || ( null !== $maximum && $count > $maximum ) ) {
				$expected = null === $maximum ? $minimum . '+' : $minimum . '..' . $maximum;
				$report->add( ValidationIssue::error( 'SECTION_COUNT', '/sections', sprintf( 'Недопустимое количество секций %s: %d.', $type, $count ), $source_id, '', $expected ) );
			}
		}

		$manual_parent_link = ( $counts['parent-link'] ?? 0 ) > 0;
		$has_parent         = ! empty( $spec['post']['parent'] );
		if ( $manual_parent_link && ! $has_parent ) {
			$report->add( ValidationIssue::error( 'PARENT_LINK_WITHOUT_PARENT', '/sections', 'parent-link нельзя разрешить без post.parent.', $source_id ) );
		}
		if ( $has_parent ) {
			$used_blocks['potolki/inner-parent-link'] = true;
			$this->validate_parent_resolution( $spec['post']['parent'], '/post/parent', $source_id, $link_context, $report );
		}

		$this->check_registry( array_keys( $used_blocks ), $report );
		$this->validate_hero_title_similarity( $spec, $source_id, $report );
		return $report;
	}

	/** @return BlockNode[] */
	public function build( array $spec, array $context = array() ): array {
		$validation = $this->validate( $spec, $context );
		if ( $validation->has_errors() ) {
			throw new \RuntimeException( 'Нельзя построить Block Tree: PageSpec несовместим с адаптером potolki-inner.' );
		}

		$link_context   = $this->link_context( $spec, $context );
		$mapper_context = $link_context;
		$mapper_context['spec'] = $spec;
		$transforms         = new TransformRegistry( $this->link_resolver, $this->profile );
		$sections           = $spec['sections'];
		$hero               = $this->build_hero( $sections[0], $link_context, $context );
		$children           = array();
		$article_no         = 0;
		$has_parent         = ! empty( $spec['post']['parent'] );
		$manual_parent_link = (bool) array_filter( $sections, static fn( array $section ): bool => 'parent-link' === ( $section['type'] ?? '' ) );
		$parent_link_added  = false;
		foreach ( $sections as $section ) {
			$type = $section['type'];
			if ( 'hero' === $type ) {
				continue;
			}
			if ( 'article' === $type ) {
				++$article_no;
				$children[] = $this->build_article( $section, $article_no, $link_context );
			} elseif ( in_array( $type, array( 'catalog', 'steps', 'faq' ), true ) ) {
				$children[] = $this->build_generic_section( $section, $mapper_context, $transforms );
			} elseif ( 'parent-link' === $type ) {
				$parent_link_added = true;
				$children[] = $this->build_parent_link( $section['data'], $spec['post']['parent'], $link_context );
			} elseif ( 'cta' === $type ) {
				if ( $has_parent && ! $manual_parent_link && ! $parent_link_added ) {
					$children[] = $this->build_parent_link( array(), $spec['post']['parent'], $link_context );
					$parent_link_added = true;
				}
				$children[] = $this->build_cta( $section, $link_context );
			}
		}

		$defaults = $this->manifest['siteDefaults'];
		$content  = new BlockNode(
			'potolki/inner-content',
			array(
				'readingTime'  => $this->reading_time( $spec ),
				'categoryLabel' => (string) ( $spec['post']['categoryLabel'] ?? $defaults['categoryLabel'] ),
				'tocLabel'      => (string) $defaults['tocLabel'],
			),
			$children
		);
		return array( $hero, $content );
	}

	private function build_generic_section( array $section, array $context, TransformRegistry $transforms ): BlockNode {
		$type    = (string) ( $section['type'] ?? '' );
		$binding = $this->profile->binding( $type );
		if ( ! is_array( $binding ) || 'generic' !== ( $binding['mapper'] ?? '' ) ) {
			throw new \RuntimeException( 'Для semantic-секции не найден generic binding: ' . $type );
		}
		return ( new DeclarativeSectionMapper( $binding, $transforms ) )->map( $section, $context );
	}

	private function validate_target( array $spec, string $source_id, CompatibilityReport $report ): void {
		$target = $spec['target'] ?? null;
		if ( ! is_array( $target ) ) {
			return;
		}
		if ( isset( $target['siteKey'] ) && $this->profile->site_key() !== $target['siteKey'] ) {
			$report->add( ValidationIssue::error( 'TARGET_SITE_MISMATCH', '/target/siteKey', 'PageSpec предназначен для другого сайта.', $source_id, '', $this->profile->site_key() ) );
		}
		if ( isset( $target['profileId'] ) && $this->id() !== $target['profileId'] ) {
			$report->add( ValidationIssue::error( 'TARGET_PROFILE_MISMATCH', '/target/profileId', 'PageSpec предназначен для другого адаптера.', $source_id, '', $this->id() ) );
		}
	}

	private function validate_generated_against( array $spec, string $source_id, CompatibilityReport $report ): void {
		$against = $spec['generatedAgainst'] ?? null;
		if ( ! is_array( $against ) ) {
			return;
		}
		$expected = array( 'profileId' => $this->id(), 'profileVersion' => $this->version(), 'manifestHash' => $this->manifest_hash() );
		foreach ( $expected as $key => $value ) {
			if ( isset( $against[ $key ] ) && $value !== $against[ $key ] ) {
				$report->add( ValidationIssue::warning( 'GENERATED_AGAINST_MISMATCH', '/generatedAgainst/' . $key, 'Снимок generatedAgainst отличается от текущего профиля; фактический контракт проверен повторно.', $source_id, '', $value ) );
			}
		}
	}

	private function validate_section( string $type, array $section, string $path, string $source_id, array $link_context, array &$questions, CompatibilityReport $report, array $context ): void {
		$data       = is_array( $section['data'] ?? null ) && ! array_is_list( $section['data'] ) ? $section['data'] : array();
		$section_id = is_string( $section['id'] ?? null ) ? $section['id'] : '';
		$definition = $this->manifest['sections'][ $type ];
		$this->reject_unknown( $data, $definition['allowedData'], $path . '/data', $source_id, $section_id, $report );
		foreach ( $definition['requiredData'] as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				$report->add( ValidationIssue::error( 'REQUIRED_FIELD', $path . '/data/' . $field, 'Обязательное поле секции отсутствует.', $source_id, $section_id, $field ) );
			}
		}

		switch ( $type ) {
			case 'hero':
				$this->validate_hero( $data, $path, $source_id, $section_id, $link_context, $report, $context );
				break;
			case 'article':
				$this->validate_article( $data, $path, $source_id, $section_id, $link_context, $report );
				break;
			case 'catalog':
				$this->validate_catalog( $data, $path, $source_id, $section_id, $link_context, $report, $context );
				break;
			case 'steps':
				$this->validate_steps( $data, $path, $source_id, $section_id, $link_context, $report );
				break;
			case 'faq':
				$this->validate_faq( $data, $path, $source_id, $section_id, $link_context, $questions, $report );
				break;
			case 'parent-link':
				$this->validate_optional_strings( $data, array( 'label', 'linkLabel' ), $path . '/data', $source_id, $section_id, $report );
				break;
			case 'cta':
				$this->validate_cta( $data, $path, $source_id, $section_id, $link_context, $report );
				break;
		}
	}

	private function validate_hero( array $data, string $path, string $source_id, string $section_id, array $link_context, CompatibilityReport $report, array $context ): void {
		$this->validate_required_string( $data, 'title', $path . '/data', $source_id, $section_id, $report );
		$this->validate_optional_strings( $data, array( 'kicker' ), $path . '/data', $source_id, $section_id, $report );
		$lead = $data['lead'] ?? null;
		if ( ! $this->is_string_list( $lead, 1, PHP_INT_MAX ) ) {
			$report->add( ValidationIssue::error( 'INVALID_HERO_LEAD', $path . '/data/lead', 'lead должен содержать хотя бы один непустой абзац.', $source_id, $section_id, '1+ strings' ) );
		} else {
			foreach ( $lead as $index => $text ) {
				$this->validate_inline_text( $text, $path . '/data/lead/' . $index, $source_id, $section_id, $link_context, true, $report );
			}
		}
		$this->validate_action( $data['primaryAction'] ?? null, $path . '/data/primaryAction', $source_id, $section_id, true, $link_context, $report );
		if ( isset( $data['benefits'] ) && ! $this->is_string_list( $data['benefits'], 0, 3, true ) ) {
			$report->add( ValidationIssue::error( 'INVALID_BENEFITS', $path . '/data/benefits', 'Hero benefits должен быть массивом до трёх непустых строк.', $source_id, $section_id, '0..3 strings' ) );
		}
		foreach ( array( 'badge' => array( 'value', 'text' ), 'note' => array( 'title', 'text' ) ) as $field => $allowed ) {
			if ( isset( $data[ $field ] ) ) {
				$this->validate_string_object( $data[ $field ], $allowed, $path . '/data/' . $field, $source_id, $section_id, $report );
			}
		}
		if ( isset( $data['image'] ) ) {
			$this->validate_asset( $data['image'], $path . '/data/image', $source_id, $section_id, true, $report, $context );
		} else {
			$fallback = (string) ( $this->manifest['policies']['heroImageFallback'] ?? '' );
			if ( '' === $fallback || ! $this->theme_asset_exists( $fallback, $context ) ) {
				$report->add( ValidationIssue::error( 'MISSING_REQUIRED_ASSET', $path . '/data/image', 'Изображение hero отсутствует и fallback недоступен.', $source_id, $section_id ) );
			} else {
				$report->add( ValidationIssue::warning( 'ASSET_FALLBACK', $path . '/data/image', 'Для hero будет использовано fallback-изображение из профиля.', $source_id, $section_id, $fallback ) );
			}
		}
	}

	private function validate_article( array $data, string $path, string $source_id, string $section_id, array $link_context, CompatibilityReport $report ): void {
		$this->validate_required_string( $data, 'title', $path . '/data', $source_id, $section_id, $report );
		if ( isset( $data['accent'] ) && ! is_bool( $data['accent'] ) ) {
			$report->add( ValidationIssue::error( 'INVALID_TYPE', $path . '/data/accent', 'accent должен быть boolean.', $source_id, $section_id, 'boolean' ) );
		}
		$body = $data['body'] ?? null;
		if ( ! is_array( $body ) || ! array_is_list( $body ) || ! $body ) {
			$report->add( ValidationIssue::error( 'INVALID_ARTICLE_BODY', $path . '/data/body', 'body должен быть непустым массивом структурированных узлов.', $source_id, $section_id, 'non-empty array' ) );
			return;
		}
		foreach ( $body as $index => $node ) {
			$this->validate_body_node( $node, $path . '/data/body/' . $index, $source_id, $section_id, $link_context, $report );
		}
	}

	private function validate_body_node( mixed $node, string $path, string $source_id, string $section_id, array $link_context, CompatibilityReport $report ): void {
		if ( ! is_array( $node ) || array_is_list( $node ) ) {
			$report->add( ValidationIssue::error( 'INVALID_BODY_NODE', $path, 'Узел body должен быть объектом.', $source_id, $section_id, 'object' ) );
			return;
		}
		$type = $node['type'] ?? '';
		if ( 'paragraph' === $type ) {
			$this->reject_unknown( $node, array( 'type', 'text' ), $path, $source_id, $section_id, $report );
			$this->validate_required_string( $node, 'text', $path, $source_id, $section_id, $report );
			$this->validate_inline_text( $node['text'] ?? '', $path . '/text', $source_id, $section_id, $link_context, true, $report );
		} elseif ( 'heading' === $type ) {
			$this->reject_unknown( $node, array( 'type', 'level', 'text' ), $path, $source_id, $section_id, $report );
			$this->validate_required_string( $node, 'text', $path, $source_id, $section_id, $report );
			if ( ! is_int( $node['level'] ?? null ) || ! in_array( $node['level'], $this->manifest['policies']['articleHeadingLevels'], true ) ) {
				$report->add( ValidationIssue::error( 'INVALID_HEADING_LEVEL', $path . '/level', 'В article разрешены только H3 и H4.', $source_id, $section_id, '3 or 4' ) );
			}
			$this->validate_inline_text( $node['text'] ?? '', $path . '/text', $source_id, $section_id, $link_context, true, $report );
		} elseif ( 'list' === $type ) {
			$this->reject_unknown( $node, array( 'type', 'style', 'items' ), $path, $source_id, $section_id, $report );
			if ( ! in_array( $node['style'] ?? null, array( 'ordered', 'unordered' ), true ) ) {
				$report->add( ValidationIssue::error( 'INVALID_LIST_STYLE', $path . '/style', 'style списка должен быть ordered или unordered.', $source_id, $section_id, 'ordered|unordered' ) );
			}
			if ( ! $this->is_string_list( $node['items'] ?? null, 1, PHP_INT_MAX ) ) {
				$report->add( ValidationIssue::error( 'INVALID_LIST_ITEMS', $path . '/items', 'items списка должен быть непустым массивом строк.', $source_id, $section_id ) );
			} else {
				foreach ( $node['items'] as $index => $text ) {
					$this->validate_inline_text( $text, $path . '/items/' . $index, $source_id, $section_id, $link_context, true, $report );
				}
			}
		} elseif ( 'buttons' === $type ) {
			$this->reject_unknown( $node, array( 'type', 'items' ), $path, $source_id, $section_id, $report );
			if ( ! is_array( $node['items'] ?? null ) || ! array_is_list( $node['items'] ) || ! $node['items'] ) {
				$report->add( ValidationIssue::error( 'INVALID_BUTTONS', $path . '/items', 'buttons.items должен быть непустым массивом.', $source_id, $section_id ) );
			} else {
				foreach ( $node['items'] as $index => $item ) {
					$this->validate_action( $item, $path . '/items/' . $index, $source_id, $section_id, true, $link_context, $report );
				}
			}
		} else {
			$report->add( ValidationIssue::error( 'UNSUPPORTED_BODY_NODE', $path . '/type', 'Тип узла body не поддерживается.', $source_id, $section_id, 'paragraph, heading, list, buttons' ) );
		}
	}

	private function validate_catalog( array $data, string $path, string $source_id, string $section_id, array $link_context, CompatibilityReport $report, array $context ): void {
		$this->validate_required_string( $data, 'title', $path . '/data', $source_id, $section_id, $report );
		$this->validate_optional_strings( $data, array( 'kicker' ), $path . '/data', $source_id, $section_id, $report );
		$items = $data['items'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) < (int) $this->manifest['policies']['catalogItems']['min'] ) {
			$report->add( ValidationIssue::error( 'INVALID_CATALOG_ITEMS', $path . '/data/items', 'Каталог должен содержать хотя бы одну карточку.', $source_id, $section_id, 'min 1' ) );
			return;
		}
		foreach ( $items as $index => $item ) {
			$item_path = $path . '/data/items/' . $index;
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				$report->add( ValidationIssue::error( 'INVALID_CATALOG_ITEM', $item_path, 'Карточка каталога должна быть объектом.', $source_id, $section_id ) );
				continue;
			}
			$this->reject_unknown( $item, array( 'title', 'text', 'action', 'image' ), $item_path, $source_id, $section_id, $report );
			$this->validate_required_string( $item, 'title', $item_path, $source_id, $section_id, $report );
			$this->validate_required_string( $item, 'text', $item_path, $source_id, $section_id, $report );
			$this->validate_inline_text( $item['title'] ?? '', $item_path . '/title', $source_id, $section_id, $link_context, false, $report );
			$this->validate_inline_text( $item['text'] ?? '', $item_path . '/text', $source_id, $section_id, $link_context, false, $report );
			$this->validate_action( $item['action'] ?? null, $item_path . '/action', $source_id, $section_id, true, $link_context, $report );
			if ( ! array_key_exists( 'image', $item ) ) {
				$report->add( ValidationIssue::error( 'MISSING_REQUIRED_ASSET', $item_path . '/image', 'Изображение карточки обязательно.', $source_id, $section_id ) );
			} else {
				$this->validate_asset( $item['image'], $item_path . '/image', $source_id, $section_id, false, $report, $context );
			}
		}
	}

	private function validate_steps( array $data, string $path, string $source_id, string $section_id, array $link_context, CompatibilityReport $report ): void {
		$this->validate_required_string( $data, 'title', $path . '/data', $source_id, $section_id, $report );
		$this->validate_optional_strings( $data, array( 'kicker' ), $path . '/data', $source_id, $section_id, $report );
		$items = $data['items'] ?? null;
		$min   = (int) $this->manifest['policies']['stepsItems']['min'];
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) < $min ) {
			$report->add( ValidationIssue::error( 'INVALID_STEPS_COUNT', $path . '/data/items', 'Раздел этапов должен содержать хотя бы один элемент.', $source_id, $section_id, $min . '+' ) );
			return;
		}
		foreach ( $items as $index => $item ) {
			$item_path = $path . '/data/items/' . $index;
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				$report->add( ValidationIssue::error( 'INVALID_STEP', $item_path, 'Этап должен быть объектом.', $source_id, $section_id ) );
				continue;
			}
			$this->reject_unknown( $item, array( 'title', 'text' ), $item_path, $source_id, $section_id, $report );
			$this->validate_required_string( $item, 'title', $item_path, $source_id, $section_id, $report );
			$this->validate_required_string( $item, 'text', $item_path, $source_id, $section_id, $report );
			$this->validate_inline_text( $item['text'] ?? '', $item_path . '/text', $source_id, $section_id, $link_context, true, $report );
		}
	}

	private function validate_faq( array $data, string $path, string $source_id, string $section_id, array $link_context, array &$questions, CompatibilityReport $report ): void {
		$this->validate_required_string( $data, 'title', $path . '/data', $source_id, $section_id, $report );
		$this->validate_optional_strings( $data, array( 'kicker' ), $path . '/data', $source_id, $section_id, $report );
		$items = $data['items'] ?? null;
		$min   = (int) $this->manifest['policies']['faqItems']['min'];
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) < $min ) {
			$report->add( ValidationIssue::error( 'INVALID_FAQ_COUNT', $path . '/data/items', 'Раздел FAQ должен содержать хотя бы один вопрос.', $source_id, $section_id, $min . '+' ) );
			return;
		}
		foreach ( $items as $index => $item ) {
			$item_path = $path . '/data/items/' . $index;
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				$report->add( ValidationIssue::error( 'INVALID_FAQ_ITEM', $item_path, 'Элемент FAQ должен быть объектом.', $source_id, $section_id ) );
				continue;
			}
			$this->reject_unknown( $item, array( 'question', 'answer' ), $item_path, $source_id, $section_id, $report );
			$this->validate_required_string( $item, 'question', $item_path, $source_id, $section_id, $report );
			$this->validate_required_string( $item, 'answer', $item_path, $source_id, $section_id, $report );
			$this->validate_inline_text( $item['answer'] ?? '', $item_path . '/answer', $source_id, $section_id, $link_context, true, $report );
			$normalized = $this->lower( trim( (string) ( $item['question'] ?? '' ) ) );
			if ( '' !== $normalized && isset( $questions[ $normalized ] ) ) {
				$report->add( ValidationIssue::error( 'DUPLICATE_FAQ_QUESTION', $item_path . '/question', 'Вопрос FAQ повторяется внутри страницы.', $source_id, $section_id, 'unique question' ) );
			}
			$questions[ $normalized ] = true;
		}
	}

	private function validate_cta( array $data, string $path, string $source_id, string $section_id, array $link_context, CompatibilityReport $report ): void {
		$this->validate_required_string( $data, 'title', $path . '/data', $source_id, $section_id, $report );
		$this->validate_required_string( $data, 'text', $path . '/data', $source_id, $section_id, $report );
		$this->validate_optional_strings( $data, array( 'kicker' ), $path . '/data', $source_id, $section_id, $report );
		$this->validate_inline_text( $data['text'] ?? '', $path . '/data/text', $source_id, $section_id, $link_context, true, $report );
		$variant = $data['variant'] ?? 'form';
		if ( ! in_array( $variant, array( 'form', 'links' ), true ) ) {
			$report->add( ValidationIssue::error( 'INVALID_CTA_VARIANT', $path . '/data/variant', 'CTA variant должен быть form или links.', $source_id, $section_id, 'form|links' ) );
		}
		if ( isset( $data['benefits'] ) && ! $this->is_string_list( $data['benefits'], 0, 2, true ) ) {
			$report->add( ValidationIssue::error( 'INVALID_BENEFITS', $path . '/data/benefits', 'CTA benefits должен быть массивом до двух непустых строк.', $source_id, $section_id, '0..2 strings' ) );
		}
		$this->validate_action( $data['primaryAction'] ?? null, $path . '/data/primaryAction', $source_id, $section_id, 'links' === $variant, $link_context, $report );
		if ( 'form' === $variant && is_array( $data['primaryAction'] ?? null ) && array_key_exists( 'link', $data['primaryAction'] ) ) {
			$report->add( ValidationIssue::error( 'UNUSED_CTA_LINK', $path . '/data/primaryAction/link', 'Form CTA не использует URL основной кнопки.', $source_id, $section_id, 'field omitted' ) );
		}
		if ( 'links' === $variant ) {
			$this->validate_action( $data['secondaryAction'] ?? null, $path . '/data/secondaryAction', $source_id, $section_id, true, $link_context, $report, false );
		} elseif ( isset( $data['secondaryAction'] ) ) {
			$report->add( ValidationIssue::error( 'UNUSED_SECONDARY_ACTION', $path . '/data/secondaryAction', 'secondaryAction разрешён только для links CTA.', $source_id, $section_id, 'field omitted' ) );
		}
	}

	private function validate_action( mixed $action, string $path, string $source_id, string $section_id, bool $link_required, array $link_context, CompatibilityReport $report, bool $supports_target = true ): void {
		if ( ! is_array( $action ) || array_is_list( $action ) ) {
			$report->add( ValidationIssue::error( 'INVALID_ACTION', $path, 'Action должен быть объектом.', $source_id, $section_id, 'object' ) );
			return;
		}
		$this->reject_unknown( $action, array( 'label', 'link' ), $path, $source_id, $section_id, $report );
		$this->validate_required_string( $action, 'label', $path, $source_id, $section_id, $report );
		if ( $link_required && ! array_key_exists( 'link', $action ) ) {
			$report->add( ValidationIssue::error( 'REQUIRED_LINK', $path . '/link', 'Для action требуется разрешимая ссылка.', $source_id, $section_id ) );
			return;
		}
		if ( isset( $action['link'] ) ) {
			$resolved = $this->validate_link( $action['link'], $path . '/link', $source_id, $section_id, $link_context, $report );
			if ( is_array( $resolved ) && ! $supports_target && '' !== ( $resolved['target'] ?? '' ) ) {
				$report->add( ValidationIssue::error( 'UNSUPPORTED_LINK_TARGET', $path . '/link/newTab', 'Этот block mapping не поддерживает target/rel; newTab приведёт к потере данных.', $source_id, $section_id, 'newTab=false' ) );
			}
		}
	}

	private function validate_link( mixed $link, string $path, string $source_id, string $section_id, array $link_context, CompatibilityReport $report ): ?array {
		if ( ! is_array( $link ) || array_is_list( $link ) ) {
			$report->add( ValidationIssue::error( 'INVALID_LINK', $path, 'Link должен быть объектом.', $source_id, $section_id, 'object' ) );
			return null;
		}
		$kind    = $link['kind'] ?? '';
		$allowed = array(
			'anchor' => array( 'kind', 'anchor' ),
			'page' => array( 'kind', 'sourceId' ),
			'path' => array( 'kind', 'path' ),
			'external' => array( 'kind', 'url', 'newTab' ),
			'tel' => array( 'kind', 'value' ),
			'mailto' => array( 'kind', 'value' ),
		);
		if ( ! isset( $allowed[ $kind ] ) ) {
			$report->add( ValidationIssue::error( 'UNKNOWN_LINK_KIND', $path . '/kind', 'Неизвестный тип ссылки.', $source_id, $section_id, implode( ', ', array_keys( $allowed ) ) ) );
			return null;
		}
		$this->reject_unknown( $link, $allowed[ $kind ], $path, $source_id, $section_id, $report );
		if ( 'path' === $kind ) {
			$value = $link['path'] ?? '';
			if ( is_string( $value ) && ! str_contains( $value, '?' ) && ! str_contains( $value, '#' ) && ! str_ends_with( $value, '/' ) ) {
				$report->add( ValidationIssue::error( 'INVALID_PATH', $path . '/path', 'Внутренний path без query/anchor должен оканчиваться символом /.', $source_id, $section_id, '/path/' ) );
			}
		}
		if ( 'external' === $kind && isset( $link['newTab'] ) && ! is_bool( $link['newTab'] ) ) {
			$report->add( ValidationIssue::error( 'INVALID_TYPE', $path . '/newTab', 'newTab должен быть boolean.', $source_id, $section_id, 'boolean' ) );
			return null;
		}
		$resolved = $this->link_resolver->resolve( $link, $link_context );
		if ( is_wp_error( $resolved ) ) {
			$report->add( ValidationIssue::error( 'UNRESOLVED_LINK', $path, $resolved->get_error_message(), $source_id, $section_id, 'resolvable link' ) );
			return null;
		}
		if ( '' === ( $resolved['url'] ?? '' ) || '#' === $resolved['url'] ) {
			$report->add( ValidationIssue::error( 'UNRESOLVED_LINK', $path, 'Ссылка разрешилась в пустой или placeholder URL.', $source_id, $section_id ) );
			return null;
		}
		return $resolved;
	}

	private function validate_asset( mixed $asset, string $path, string $source_id, string $section_id, bool $alt_required, CompatibilityReport $report, array $context ): void {
		if ( ! is_array( $asset ) || array_is_list( $asset ) ) {
			$report->add( ValidationIssue::error( 'INVALID_ASSET', $path, 'Asset descriptor должен быть объектом.', $source_id, $section_id, 'object' ) );
			return;
		}
		$source  = $asset['source'] ?? '';
		$allowed = array(
			'themeAsset' => array( 'source', 'ref', 'alt' ),
			'mediaId' => array( 'source', 'id', 'alt' ),
			'mediaUrl' => array( 'source', 'url', 'alt' ),
			'externalUrl' => array( 'source', 'url', 'alt' ),
			'none' => array( 'source', 'alt' ),
		);
		if ( ! isset( $allowed[ $source ] ) ) {
			$report->add( ValidationIssue::error( 'UNKNOWN_ASSET_SOURCE', $path . '/source', 'Неизвестный source изображения.', $source_id, $section_id, implode( ', ', array_keys( $allowed ) ) ) );
			return;
		}
		$this->reject_unknown( $asset, $allowed[ $source ], $path, $source_id, $section_id, $report );
		$alt = $asset['alt'] ?? null;
		if ( ! is_string( $alt ) || ( $alt_required && '' === trim( $alt ) ) ) {
			$report->add( ValidationIssue::error( 'INVALID_IMAGE_ALT', $path . '/alt', 'Для содержательного изображения требуется непустой alt.', $source_id, $section_id, $alt_required ? 'non-empty string' : 'string' ) );
		}
		if ( 'themeAsset' === $source ) {
			$ref = is_string( $asset['ref'] ?? null ) ? $asset['ref'] : '';
			if ( '' === $ref || ! isset( $this->manifest['assets'][ $ref ] ) ) {
				$report->add( ValidationIssue::error( 'UNKNOWN_THEME_ASSET', $path . '/ref', 'themeAsset.ref отсутствует в профиле.', $source_id, $section_id ) );
			} elseif ( ! $this->theme_asset_exists( $ref, $context ) ) {
				$report->add( ValidationIssue::error( 'UNRESOLVED_ASSET', $path, 'Файл themeAsset недоступен.', $source_id, $section_id, $ref ) );
			}
		} elseif ( 'mediaId' === $source ) {
			$id  = $asset['id'] ?? null;
			$url = is_int( $id ) && isset( $context['media_assets'][ $id ] ) ? $context['media_assets'][ $id ] : '';
			if ( is_int( $id ) && $id > 0 && '' === $url && function_exists( 'wp_get_attachment_url' ) ) {
				$url = wp_get_attachment_url( $id );
			}
			if ( ! is_int( $id ) || $id <= 0 || ! $url ) {
				$report->add( ValidationIssue::error( 'UNRESOLVED_ASSET', $path, 'Attachment mediaId не найден.', $source_id, $section_id ) );
			} elseif ( function_exists( 'get_post_mime_type' ) && ! str_starts_with( (string) get_post_mime_type( $id ), 'image/' ) ) {
				$report->add( ValidationIssue::error( 'INVALID_ASSET_MIME', $path, 'Attachment mediaId не является изображением.', $source_id, $section_id, 'image/*' ) );
			}
		} elseif ( 'mediaUrl' === $source ) {
			$url = is_string( $asset['url'] ?? null ) ? $asset['url'] : '';
			$id  = $context['media_urls'][ $url ] ?? 0;
			if ( ! $id && function_exists( 'attachment_url_to_postid' ) ) {
				$id = attachment_url_to_postid( $url );
			}
			if ( ! $id ) {
				$report->add( ValidationIssue::error( 'UNRESOLVED_ASSET', $path, 'mediaUrl не является доступным attachment текущего сайта.', $source_id, $section_id ) );
			} elseif ( function_exists( 'get_post_mime_type' ) && ! str_starts_with( (string) get_post_mime_type( $id ), 'image/' ) ) {
				$report->add( ValidationIssue::error( 'INVALID_ASSET_MIME', $path, 'Attachment mediaUrl не является изображением.', $source_id, $section_id, 'image/*' ) );
			}
		} elseif ( 'externalUrl' === $source ) {
			$report->add( ValidationIssue::error( 'EXTERNAL_ASSET_FORBIDDEN', $path, 'Загрузка внешних изображений запрещена policy адаптера.', $source_id, $section_id ) );
		} elseif ( 'none' === $source ) {
			$report->add( ValidationIssue::error( 'MISSING_REQUIRED_ASSET', $path, 'Для этой секции изображение обязательно.', $source_id, $section_id ) );
		}
	}

	private function validate_inline_text( mixed $text, string $path, string $source_id, string $section_id, array $link_context, bool $allow_links, CompatibilityReport $report ): void {
		if ( ! is_string( $text ) ) {
			return;
		}
		if ( preg_match( '/<[^>]*>/u', $text ) || str_contains( $text, '```' ) || preg_match( '/!\[[^]]*\]\([^)]*\)/u', $text ) ) {
			$report->add( ValidationIssue::error( 'UNSAFE_RICH_TEXT', $path, 'Raw HTML, fenced code и Markdown-изображения запрещены.', $source_id, $section_id ) );
		}
		if ( preg_match_all( '/\[([^\]\n]+)\]\(([^)\s]+)\)/u', $text, $matches, PREG_SET_ORDER ) ) {
			if ( ! $allow_links ) {
				$report->add( ValidationIssue::error( 'NESTED_CARD_LINK', $path, 'Текст link-card не может содержать вложенные ссылки.', $source_id, $section_id ) );
				return;
			}
			foreach ( $matches as $match ) {
				$url  = $match[2];
				$link = str_starts_with( $url, '/' ) ? array( 'kind' => 'path', 'path' => $url ) : array( 'kind' => 'external', 'url' => $url, 'newTab' => false );
				$this->validate_link( $link, $path, $source_id, $section_id, $link_context, $report );
			}
		}
	}

	private function validate_parent_resolution( mixed $parent, string $path, string $source_id, array $link_context, CompatibilityReport $report ): void {
		if ( ! is_array( $parent ) ) {
			return;
		}
		$link = isset( $parent['sourceId'] )
			? array( 'kind' => 'page', 'sourceId' => $parent['sourceId'] )
			: array( 'kind' => 'path', 'path' => $parent['path'] ?? '' );
		$this->validate_link( $link, $path, $source_id, '', $link_context, $report );
	}

	private function validate_hero_title_similarity( array $spec, string $source_id, CompatibilityReport $report ): void {
		$post_title = (string) ( $spec['post']['title'] ?? '' );
		$hero_title = '';
		foreach ( $spec['sections'] ?? array() as $section ) {
			if ( 'hero' === ( $section['type'] ?? '' ) ) {
				$hero_title = (string) ( $section['data']['title'] ?? '' );
				break;
			}
		}
		if ( '' === $post_title || '' === $hero_title ) {
			return;
		}
		$percent = 0.0;
		similar_text( $this->lower( $post_title ), $this->lower( $hero_title ), $percent );
		if ( $percent < 45.0 ) {
			$report->add( ValidationIssue::warning( 'HERO_TITLE_MISMATCH', '/sections/0/data/title', 'Hero title подозрительно сильно отличается от post.title.', $source_id, 'hero', 'semantically similar title' ) );
		}
	}

	private function build_hero( array $section, array $link_context, array $context ): BlockNode {
		$data     = $section['data'];
		$defaults = $this->manifest['siteDefaults'];
		$link     = $this->resolve_link_or_throw( $data['primaryAction']['link'], $link_context );
		$image    = $this->resolve_asset_or_throw( $data['image'] ?? null, $context, (string) $defaults['hero']['fallbackImageRef'] );
		$benefits = $data['benefits'] ?? $defaults['hero']['benefits'];
		$badge    = $data['badge'] ?? $defaults['hero']['badge'];
		$note     = $data['note'] ?? $defaults['hero']['note'];
		$lead     = $data['lead'];
		$lead_children = array();
		if ( count( $lead ) > 2 ) {
			foreach ( $lead as $paragraph ) {
				$lead_children[] = new BlockNode( 'core/paragraph', array(), array(), '<p>' . $this->inline_html( (string) $paragraph, $link_context ) . '</p>' );
			}
		}
		$attributes = array(
			'kicker'      => (string) ( $data['kicker'] ?? '' ),
			'title'       => (string) $data['title'],
			'lead1'       => $this->inline_html( (string) $lead[0], $link_context ),
			'lead2'       => isset( $lead[1] ) ? $this->inline_html( (string) $lead[1], $link_context ) : '',
			'buttonLabel' => (string) $data['primaryAction']['label'],
			'buttonUrl'   => $link['url'],
			'buttonTarget' => $link['target'],
			'buttonRel'   => $link['rel'],
			'phoneCaption' => (string) $defaults['phone']['caption'],
			'phoneLabel'  => (string) $defaults['phone']['label'],
			'phoneUrl'    => (string) $defaults['phone']['url'],
			'benefit1'    => (string) ( $benefits[0] ?? '' ),
			'benefit2'    => (string) ( $benefits[1] ?? '' ),
			'benefit3'    => (string) ( $benefits[2] ?? '' ),
			'imageId'     => (int) $image['id'],
			'imageUrl'    => (string) $image['url'],
			'imageAlt'    => (string) $image['alt'],
			'badgeValue'  => (string) ( $badge['value'] ?? '' ),
			'badgeText'   => (string) ( $badge['text'] ?? '' ),
			'noteTitle'   => (string) ( $note['title'] ?? '' ),
			'noteText'    => (string) ( $note['text'] ?? '' ),
			'noteUrl'     => (string) ( $note['url'] ?? '/forma-obratnoj-svyaz' ),
		);
		if ( $lead_children ) {
			$attributes['hasLeadBlocks'] = true;
		}
		return new BlockNode(
			'potolki/inner-hero',
			$attributes,
			$lead_children
		);
	}

	private function build_article( array $section, int $number, array $link_context ): BlockNode {
		$data     = $section['data'];
		$children = array();
		foreach ( $data['body'] as $node ) {
			$children[] = $this->build_body_node( $node, $link_context );
		}
		return new BlockNode(
			'potolki/inner-article',
			array(
				'sectionId'    => (string) $section['id'],
				'sectionIndex' => str_pad( (string) $number, 2, '0', STR_PAD_LEFT ),
				'title'        => (string) $data['title'],
				'accent'       => (bool) ( $data['accent'] ?? false ),
			),
			$children
		);
	}

	private function build_body_node( array $node, array $link_context ): BlockNode {
		$type = $node['type'];
		if ( 'paragraph' === $type ) {
			return new BlockNode( 'core/paragraph', array(), array(), '<p>' . $this->inline_html( $node['text'], $link_context ) . '</p>' );
		}
		if ( 'heading' === $type ) {
			$level = (int) $node['level'];
			return new BlockNode( 'core/heading', array( 'level' => $level ), array(), '<h' . $level . ' class="wp-block-heading">' . $this->inline_html( $node['text'], $link_context ) . '</h' . $level . '>' );
		}
		if ( 'list' === $type ) {
			$ordered = 'ordered' === $node['style'];
			$tag     = $ordered ? 'ol' : 'ul';
			$items   = array_map( fn( string $item ): BlockNode => new BlockNode( 'core/list-item', array(), array(), '<li>' . $this->inline_html( $item, $link_context ) . '</li>' ), $node['items'] );
			return new BlockNode( 'core/list', $ordered ? array( 'ordered' => true ) : array(), $items, '', '<' . $tag . ' class="wp-block-list">', '</' . $tag . '>' );
		}
		$buttons = array();
		foreach ( $node['items'] as $item ) {
			$link       = $this->resolve_link_or_throw( $item['link'], $link_context );
			$link_attrs = ' href="' . esc_url( $link['url'] ) . '"';
			if ( '' !== $link['target'] ) {
				$link_attrs .= ' target="' . esc_attr( $link['target'] ) . '"';
			}
			if ( '' !== $link['rel'] ) {
				$link_attrs .= ' rel="' . esc_attr( $link['rel'] ) . '"';
			}
			$html      = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $link_attrs . '>' . esc_html( $item['label'] ) . '</a></div>';
			$buttons[] = new BlockNode( 'core/button', array(), array(), $html );
		}
		return new BlockNode( 'core/buttons', array(), $buttons, '', '<div class="wp-block-buttons">', '</div>' );
	}

	private function build_parent_link( array $data, array $parent, array $link_context ): BlockNode {
		$defaults = $this->manifest['siteDefaults']['parentLink'];
		$link     = isset( $parent['sourceId'] )
			? array( 'kind' => 'page', 'sourceId' => $parent['sourceId'] )
			: array( 'kind' => 'path', 'path' => $parent['path'] );
		$resolved = $this->resolve_link_or_throw( $link, $link_context );
		return new BlockNode( 'potolki/inner-parent-link', array( 'label' => (string) ( $data['label'] ?? $defaults['label'] ), 'linkLabel' => (string) ( $data['linkLabel'] ?? $defaults['linkLabel'] ), 'linkUrl' => (string) $resolved['url'] ) );
	}

	private function build_cta( array $section, array $link_context ): BlockNode {
		$data     = $section['data'];
		$defaults = $this->manifest['siteDefaults']['cta'];
		$variant  = (string) ( $data['variant'] ?? 'form' );
		$benefits = $data['benefits'] ?? $defaults['benefits'];
		$primary  = array( 'url' => '', 'target' => '', 'rel' => '' );
		$secondary = array( 'url' => '' );
		if ( 'links' === $variant ) {
			$primary   = $this->resolve_link_or_throw( $data['primaryAction']['link'], $link_context );
			$secondary = $this->resolve_link_or_throw( $data['secondaryAction']['link'], $link_context );
		}
		return new BlockNode(
			'potolki/inner-cta',
			array(
				'sectionId'       => (string) $section['id'],
				'variant'         => $variant,
				'kicker'          => (string) ( $data['kicker'] ?? $defaults['kicker'] ),
				'title'           => (string) $data['title'],
				'text'            => $this->inline_html( (string) $data['text'], $link_context ),
				'benefit1'        => (string) ( $benefits[0] ?? '' ),
				'benefit2'        => (string) ( $benefits[1] ?? '' ),
				'nameLabel'       => (string) $defaults['nameLabel'],
				'namePlaceholder' => (string) $defaults['namePlaceholder'],
				'phoneLabel'      => (string) $defaults['phoneLabel'],
				'phonePlaceholder' => (string) $defaults['phonePlaceholder'],
				'buttonLabel'     => (string) $data['primaryAction']['label'],
				'buttonUrl'       => (string) $primary['url'],
				'buttonTarget'    => (string) $primary['target'],
				'buttonRel'       => (string) $primary['rel'],
				'secondaryLabel'  => (string) ( $data['secondaryAction']['label'] ?? '' ),
				'secondaryUrl'    => (string) $secondary['url'],
				'phoneCtaLabel'   => (string) $defaults['phoneCtaLabel'],
				'phoneCtaUrl'     => (string) $defaults['phoneCtaUrl'],
				'formNote'        => (string) $defaults['formNote'],
				'ymCounterId'     => (string) $defaults['ymCounterId'],
				'ymGoal'          => (string) $defaults['ymGoal'],
			)
		);
	}

	private function inline_html( string $text, array $link_context, bool $allow_links = true ): string {
		$html = esc_html( $text );
		if ( $allow_links ) {
			$html = preg_replace_callback(
				'/\[([^\]\n]+)\]\(([^)\s]+)\)/u',
				function ( array $match ) use ( $link_context ): string {
					$url      = html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$link     = str_starts_with( $url, '/' ) ? array( 'kind' => 'path', 'path' => $url ) : array( 'kind' => 'external', 'url' => $url, 'newTab' => false );
					$resolved = $this->resolve_link_or_throw( $link, $link_context );
					return '<a href="' . esc_url( $resolved['url'] ) . '">' . $match[1] . '</a>';
				},
				$html
			);
		}
		$html = preg_replace( '/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', (string) $html );
		$html = preg_replace( '/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', (string) $html );
		return nl2br( (string) $html, false );
	}

	private function resolve_link_or_throw( array $link, array $context ): array {
		$resolved = $this->link_resolver->resolve( $link, $context );
		if ( is_wp_error( $resolved ) || '' === ( $resolved['url'] ?? '' ) || '#' === $resolved['url'] ) {
			$message = is_wp_error( $resolved ) ? $resolved->get_error_message() : 'Ссылка не разрешилась.';
			throw new \RuntimeException( $message );
		}
		return $resolved;
	}

	private function resolve_asset_or_throw( ?array $asset, array $context, string $fallback_ref = '' ): array {
		if ( null === $asset ) {
			$asset = array( 'source' => 'themeAsset', 'ref' => $fallback_ref, 'alt' => '' );
		}
		$source = $asset['source'];
		if ( 'themeAsset' === $source ) {
			$ref = (string) $asset['ref'];
			$url = $context['theme_asset_urls'][ $ref ] ?? '';
			if ( '' === $url && function_exists( 'get_theme_file_uri' ) ) {
				$url = get_theme_file_uri( (string) $this->manifest['assets'][ $ref ]['path'] );
			}
			if ( '' === $url ) {
				throw new \RuntimeException( 'themeAsset не удалось преобразовать в URL: ' . $ref );
			}
			return array( 'id' => 0, 'url' => $url, 'alt' => (string) ( $asset['alt'] ?? '' ) );
		}
		if ( 'mediaId' === $source ) {
			$id  = (int) $asset['id'];
			$url = $context['media_assets'][ $id ] ?? ( function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $id ) : '' );
			if ( ! $url ) {
				throw new \RuntimeException( 'Attachment mediaId не найден.' );
			}
			return array( 'id' => $id, 'url' => $url, 'alt' => (string) ( $asset['alt'] ?? '' ) );
		}
		if ( 'mediaUrl' === $source ) {
			$url = (string) $asset['url'];
			$id  = (int) ( $context['media_urls'][ $url ] ?? ( function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $url ) : 0 ) );
			if ( $id <= 0 ) {
				throw new \RuntimeException( 'mediaUrl не удалось сопоставить attachment.' );
			}
			return array( 'id' => $id, 'url' => $url, 'alt' => (string) ( $asset['alt'] ?? '' ) );
		}
		throw new \RuntimeException( 'Asset descriptor не поддерживается policy адаптера.' );
	}

	private function reading_time( array $spec ): string {
		$strings = array();
		$this->collect_content_strings( $spec['sections'] ?? array(), $strings );
		$text  = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( implode( ' ', $strings ) ) : strip_tags( implode( ' ', $strings ) );
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$wpm   = max( 1, (int) $this->manifest['siteDefaults']['readingWordsPerMinute'] );
		return max( 1, (int) ceil( count( $words ?: array() ) / $wpm ) ) . ' мин. чтения';
	}

	private function collect_content_strings( mixed $value, array &$strings, string $key = '' ): void {
		if ( is_string( $value ) && ! in_array( $key, array( 'kind', 'path', 'url', 'ref', 'source', 'sourceId' ), true ) ) {
			$strings[] = $value;
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child ) {
				$this->collect_content_strings( $child, $strings, is_string( $child_key ) ? $child_key : '' );
			}
		}
	}

	private function link_context( array $spec, array $context ): array {
		$context['anchors'] = array_values( array_unique( array_merge( $context['anchors'] ?? array(), array_filter( array_map( static fn( array $section ): string => is_string( $section['id'] ?? null ) ? $section['id'] : '', is_array( $spec['sections'] ?? null ) ? $spec['sections'] : array() ) ) ) ) );
		if ( isset( $context['parent_url'], $spec['post']['parent']['sourceId'] ) ) {
			$context['source_urls'][ $spec['post']['parent']['sourceId'] ] = $context['parent_url'];
		}
		return $context;
	}

	private function check_registry( array $block_names, CompatibilityReport $report ): void {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			$report->add( ValidationIssue::error( 'BLOCK_REGISTRY_UNAVAILABLE', '/blocks', 'WP_Block_Type_Registry недоступен.' ) );
			return;
		}
		$contracts = $this->registry_contracts();
		$registry  = \WP_Block_Type_Registry::get_instance();
		foreach ( array_unique( $block_names ) as $block_name ) {
			if ( ! isset( $contracts[ $block_name ] ) ) {
				continue;
			}
			$type = $registry->get_registered( $block_name );
			if ( ! $type ) {
				$report->add( ValidationIssue::error( 'BLOCK_NOT_REGISTERED', '/blocks/' . $this->pointer_escape( $block_name ), 'Gutenberg block не зарегистрирован.', '', '', $block_name ) );
				continue;
			}
			foreach ( $contracts[ $block_name ]['attributes'] ?? array() as $attribute => $expected ) {
				$expected = is_array( $expected ) ? $expected : array( 'type' => $expected );
				$actual   = $type->attributes[ $attribute ] ?? null;
				if ( ! is_array( $actual ) ) {
					$report->add( ValidationIssue::error( 'BLOCK_ATTRIBUTE_MISSING', '/blocks/' . $this->pointer_escape( $block_name ) . '/attributes/' . $attribute, 'Mapped attribute отсутствует в Registry.', '', '', $expected['type'] ) );
					continue;
				}
				if ( ( $actual['type'] ?? '' ) !== $expected['type'] ) {
					$report->add( ValidationIssue::error( 'BLOCK_ATTRIBUTE_TYPE', '/blocks/' . $this->pointer_escape( $block_name ) . '/attributes/' . $attribute, 'Тип mapped attribute несовместим.', '', '', $expected['type'] ) );
				}
				if ( isset( $expected['enum'] ) && array_values( $actual['enum'] ?? array() ) !== array_values( $expected['enum'] ) ) {
					$report->add( ValidationIssue::error( 'BLOCK_ATTRIBUTE_ENUM', '/blocks/' . $this->pointer_escape( $block_name ) . '/attributes/' . $attribute, 'Enum mapped attribute несовместим.', '', '', implode( ', ', $expected['enum'] ) ) );
				}
			}
			$expected_parent = $contracts[ $block_name ]['parent'] ?? array();
			$actual_parent   = is_array( $type->parent ?? null ) ? $type->parent : array();
			if ( $expected_parent && array_values( $expected_parent ) !== array_values( $actual_parent ) ) {
				$report->add( ValidationIssue::error( 'BLOCK_PARENT_CONFLICT', '/blocks/' . $this->pointer_escape( $block_name ) . '/parent', 'Parent declaration блока противоречит профилю.', '', '', implode( ', ', $expected_parent ) ) );
			}
		}
	}

	private function registry_contracts(): array {
		return $this->manifest['policies']['registryContracts'] ?? array();
	}

	private function theme_asset_exists( string $ref, array $context ): bool {
		if ( isset( $context['theme_asset_urls'][ $ref ] ) ) {
			return '' !== (string) $context['theme_asset_urls'][ $ref ];
		}
		$path = $this->manifest['assets'][ $ref ]['path'] ?? '';
		if ( '' === $path ) {
			return false;
		}
		if ( function_exists( 'get_theme_file_path' ) ) {
			return is_readable( get_theme_file_path( $path ) );
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return is_readable( WP_CONTENT_DIR . '/themes/potolki-wp/' . $path );
		}
		return false;
	}

	private function validate_required_string( array $data, string $field, string $base, string $source_id, string $section_id, CompatibilityReport $report ): void {
		if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) || '' === trim( $data[ $field ] ) ) {
			$report->add( ValidationIssue::error( 'REQUIRED_FIELD', $base . '/' . $field, 'Поле должно быть непустой строкой.', $source_id, $section_id, 'non-empty string' ) );
		}
	}

	private function validate_optional_strings( array $data, array $fields, string $base, string $source_id, string $section_id, CompatibilityReport $report ): void {
		foreach ( $fields as $field ) {
			if ( isset( $data[ $field ] ) && ! is_string( $data[ $field ] ) ) {
				$report->add( ValidationIssue::error( 'INVALID_TYPE', $base . '/' . $field, 'Поле должно быть строкой.', $source_id, $section_id, 'string' ) );
			}
		}
	}

	private function validate_string_object( mixed $value, array $fields, string $path, string $source_id, string $section_id, CompatibilityReport $report ): void {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			$report->add( ValidationIssue::error( 'INVALID_TYPE', $path, 'Поле должно быть объектом.', $source_id, $section_id, 'object' ) );
			return;
		}
		$this->reject_unknown( $value, $fields, $path, $source_id, $section_id, $report );
		$this->validate_optional_strings( $value, $fields, $path, $source_id, $section_id, $report );
	}

	private function is_string_list( mixed $value, int $min, int $max, bool $allow_empty_list = false ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) < $min || count( $value ) > $max ) {
			return false;
		}
		if ( ! $value && $allow_empty_list ) {
			return true;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === trim( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return string[] */
	private function binding_consumers( string $section_type ): array {
		$binding = $this->profile->binding( $section_type );
		if ( ! is_array( $binding ) ) {
			return array();
		}
		$mapper = (string) ( $binding['mapper'] ?? 'generic' );
		if ( 'generic' !== $mapper ) {
			return ( new MapperDefinitionRegistry() )->consumer_fields( $mapper );
		}
		$fields = array();
		$walk = function ( mixed $value ) use ( &$walk, &$fields ): void {
			if ( ! is_array( $value ) ) {
				return;
			}
			foreach ( array_merge( array( $value['source'] ?? null ), is_array( $value['sources'] ?? null ) ? $value['sources'] : array() ) as $source ) {
				if ( is_string( $source ) && preg_match( '/^data\.([A-Za-z0-9_-]+)/', $source, $matches ) ) {
					$fields[] = $matches[1];
				}
			}
			foreach ( $value as $child ) {
				$walk( $child );
			}
		};
		$walk( $binding );
		return array_values( array_unique( $fields ) );
	}

	private function reject_unknown( array $data, array $allowed, string $base, string $source_id, string $section_id, CompatibilityReport $report ): void {
		foreach ( array_diff( array_keys( $data ), $allowed ) as $field ) {
			$report->add( ValidationIssue::error( 'UNKNOWN_FIELD', $base . '/' . $this->pointer_escape( (string) $field ), 'Неизвестное поле не может быть сохранено без потери.', $source_id, $section_id, implode( ', ', $allowed ) ) );
		}
	}

	private function pointer_escape( string $value ): string {
		return str_replace( array( '~', '/' ), array( '~0', '~1' ), $value );
	}

	private function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
