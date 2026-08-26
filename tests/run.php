<?php

declare( strict_types=1 );

const CF_TEST_WP_LOAD = '/var/www/html/wp-load.php';

if ( ! is_readable( CF_TEST_WP_LOAD ) ) {
	fwrite( STDERR, 'FAIL bootstrap: WordPress not found at ' . CF_TEST_WP_LOAD . PHP_EOL );
	exit( 2 );
}

define( 'WP_USE_THEMES', false );
require_once CF_TEST_WP_LOAD;
require_once __DIR__ . '/SnapshotArtifacts.php';

$plugin_file = dirname( __DIR__ ) . '/content-factory.php';
if ( ! defined( 'CONTENT_FACTORY_DIR' ) ) {
	require_once $plugin_file;
}

final class CF_Test_Failure extends RuntimeException {}
final class CF_Test_Skip extends RuntimeException {}

final class CF_Test_Runner {
	private int $passed = 0;
	private int $failed = 0;
	private int $skipped = 0;

	public function test( string $name, callable $test ): void {
		try {
			$test();
			++$this->passed;
			$this->line( 'PASS', $name );
		} catch ( CF_Test_Skip $exception ) {
			++$this->skipped;
			$this->line( 'SKIP', $name . ': ' . $exception->getMessage() );
		} catch ( Throwable $exception ) {
			++$this->failed;
			$this->line( 'FAIL', $name . ': ' . $exception->getMessage() );
		}
	}

	public function finish(): never {
		echo PHP_EOL . sprintf(
			'Summary: %d PASS, %d FAIL, %d SKIP%s',
			$this->passed,
			$this->failed,
			$this->skipped,
			PHP_EOL
		);
		exit( $this->failed > 0 ? 1 : 0 );
	}

	private function line( string $status, string $message ): void {
		echo sprintf( '%-4s %s%s', $status, $message, PHP_EOL );
	}
}

function cf_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new CF_Test_Failure( $message );
	}
}

function cf_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new CF_Test_Failure(
			$message . '; expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual )
		);
	}
}

function cf_skip( string $message ): never {
	throw new CF_Test_Skip( $message );
}

/** @return string[] */
function cf_issue_codes( ContentFactory\Contract\CompatibilityReport $report ): array {
	$codes = array();
	foreach ( $report->issues() as $issue ) {
		$data = $issue->jsonSerialize();
		if ( isset( $data['code'] ) ) {
			$codes[] = (string) $data['code'];
		}
	}
	return $codes;
}

function cf_assert_issue( ContentFactory\Contract\CompatibilityReport $report, string $code ): void {
	cf_assert( in_array( $code, cf_issue_codes( $report ), true ), 'Expected issue ' . $code . ', got: ' . implode( ', ', cf_issue_codes( $report ) ) );
}

/** @return array<string,mixed> */
function cf_core_spec(): array {
	return array(
		'schemaVersion' => ContentFactory\Validation\PageSpecSchemaRegistry::CURRENT_VERSION,
		'sourceId'      => 'cf-test-core-valid',
		'pageType'      => 'service-detail',
		'generatedAgainst' => array( 'profileId' => 'potolki-inner', 'profileVersion' => '2.0.0', 'manifestHash' => 'sha256:' . str_repeat( 'a', 64 ) ),
		'target' => array( 'siteKey' => 'potolkinaveka40', 'profileId' => 'potolki-inner' ),
		'post'          => array(
			'title' => 'Contract test page',
			'slug'  => 'contract-test-page',
		),
		'seo'           => array(
			'title'       => 'Contract test SEO title',
			'description' => 'Contract test SEO description.',
		),
		'sections'      => array(
			array(
				'id'   => 'intro',
				'type' => 'article',
				'data' => array( 'title' => 'Core validation only' ),
			),
		),
	);
}

/** @return array<string,mixed> */
function cf_load_fixture( string $name ): array {
	$path = __DIR__ . '/fixtures/golden/' . $name . '.json';
	cf_assert( is_readable( $path ), 'Golden fixture is missing: ' . $path );
	$json = file_get_contents( $path );
	cf_assert( is_string( $json ), 'Golden fixture could not be read: ' . $path );
	try {
		$data = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
	} catch ( JsonException $exception ) {
		throw new CF_Test_Failure( 'Invalid golden fixture JSON: ' . $exception->getMessage() );
	}
	cf_assert( is_array( $data ) && ! array_is_list( $data ), 'Golden fixture must be a PageSpec object.' );
	return $data;
}

function cf_load_json_file( string $path ): array {
	cf_assert( is_readable( $path ), 'Expected JSON artifact is missing: ' . $path );
	try {
		$data = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	} catch ( JsonException $exception ) {
		throw new CF_Test_Failure( 'Invalid expected artifact JSON: ' . $exception->getMessage() );
	}
	cf_assert( is_array( $data ), 'Expected artifact must decode to an array.' );
	return $data;
}

/** @return array<string,mixed> */
function cf_adapter_context( array $spec ): array {
	$anchors = array();
	foreach ( $spec['sections'] ?? array() as $section ) {
		if ( is_array( $section ) && is_string( $section['id'] ?? null ) ) {
			$anchors[] = $section['id'];
		}
	}
	return array(
		'anchors'       => $anchors,
		'parent_id'     => 0,
		'expected_path' => '/' . sanitize_title( (string) ( $spec['post']['slug'] ?? 'fixture' ) ) . '/',
		'batch_ids'     => array(),
		'batch_paths'   => array(),
		'source_urls'   => array(),
	);
}

function cf_find_node( array $nodes, string $name ): ?ContentFactory\Contract\BlockNode {
	foreach ( $nodes as $node ) {
		if ( ! $node instanceof ContentFactory\Contract\BlockNode ) {
			continue;
		}
		if ( $node->name() === $name ) {
			return $node;
		}
		$found = cf_find_node( $node->children(), $name );
		if ( null !== $found ) {
			return $found;
		}
	}
	return null;
}

function cf_replace_path_links( mixed &$value ): void {
	if ( ! is_array( $value ) ) {
		return;
	}
	if ( 'path' === ( $value['kind'] ?? null ) ) {
		$value = array( 'kind' => 'tel', 'value' => '+79208939883' );
		return;
	}
	foreach ( $value as &$child ) {
		cf_replace_path_links( $child );
	}
}

/** @param array<string,string> $entries */
function cf_with_zip( array $entries, callable $callback ): void {
	if ( ! class_exists( 'ZipArchive' ) ) {
		cf_skip( 'ZipArchive is unavailable' );
	}
	$path = tempnam( sys_get_temp_dir(), 'cf-test-zip-' );
	cf_assert( is_string( $path ), 'Could not allocate a temporary ZIP path.' );
	$zip    = new ZipArchive();
	$opened = false;
	try {
		cf_assert( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'Could not create a temporary ZIP.' );
		$opened = true;
		foreach ( $entries as $name => $contents ) {
			cf_assert( $zip->addFromString( $name, $contents ), 'Could not add ZIP entry ' . $name );
		}
		cf_assert( $zip->close(), 'Could not close the temporary ZIP.' );
		$opened = false;
		$callback( $path );
	} finally {
		if ( $opened ) {
			$zip->close();
		}
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}
}

$runner     = new CF_Test_Runner();
$core       = new ContentFactory\Validation\CorePageSpecValidator();
$hierarchy  = new ContentFactory\Resolve\HierarchyResolver();
$links      = new ContentFactory\Resolve\LinkResolver();
$json       = new ContentFactory\Import\JsonImporter();
$zip        = new ContentFactory\Import\ZipImporter( $json );
$serializer = new ContentFactory\Build\GutenbergSerializer();

$runner->test(
	'bootstrap exposes the potolki adapter',
	static function (): void {
		cf_assert( class_exists( 'ContentFactory\\Adapter\\PotolkiInnerAdapter' ), 'PotolkiInnerAdapter is not available through the plugin autoloader.' );
	}
);

$runner->test(
	'administrator capabilities recover after a role update',
	static function (): void {
		$role = get_role( 'administrator' );
		if ( ! $role instanceof WP_Role ) {
			cf_skip( 'Administrator role is unavailable' );
		}

		$capabilities = array(
			'content_factory_import_pages',
			'content_factory_publish_pages',
		);
		$original = array();
		foreach ( $capabilities as $capability ) {
			$original[ $capability ] = $role->capabilities[ $capability ] ?? null;
		}

		try {
			$role->remove_cap( 'content_factory_import_pages' );
			ContentFactory\Plugin::ensure_administrator_capabilities();
			foreach ( $capabilities as $capability ) {
				cf_assert( $role->has_cap( $capability ), 'Administrator capability was not restored: ' . $capability );
			}
		} finally {
			foreach ( $original as $capability => $grant ) {
				if ( null === $grant ) {
					$role->remove_cap( $capability );
				} else {
					$role->add_cap( $capability, (bool) $grant );
				}
			}
		}
	}
);

$runner->test(
	'central version registry matches plugin headers, schemas, and profile authoring data',
	static function () use ( $plugin_file ): void {
		$plugin_source = (string) file_get_contents( $plugin_file );
		cf_assert( 1 === preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $plugin_source, $match ), 'Plugin Version header is missing.' );
		cf_assert_same( ContentFactory\VersionRegistry::PLUGIN, $match[1], 'Plugin header version' );
		cf_assert_same( ContentFactory\VersionRegistry::PLUGIN, CONTENT_FACTORY_VERSION, 'Runtime plugin version constant' );
		cf_assert_same( ContentFactory\VersionRegistry::PAGE_SPEC, ContentFactory\Validation\PageSpecSchemaRegistry::CURRENT_VERSION, 'PageSpec registry version' );

		$page_schema = cf_load_json_file( CONTENT_FACTORY_DIR . 'schemas/pagespec-' . ContentFactory\VersionRegistry::PAGE_SPEC . '.schema.json' );
		cf_assert_same( ContentFactory\VersionRegistry::PAGE_SPEC, $page_schema['properties']['schemaVersion']['const'] ?? '', 'PageSpec schema identity' );

		$contract_schema = cf_load_json_file( CONTENT_FACTORY_DIR . 'schemas/contract-bundle-' . ContentFactory\VersionRegistry::CONTRACT_BUNDLE . '.schema.json' );
		cf_assert_same( ContentFactory\VersionRegistry::CONTRACT_BUNDLE, $contract_schema['properties']['contractVersion']['const'] ?? '', 'Contract Bundle schema identity' );
		cf_assert_same( ContentFactory\VersionRegistry::PAGE_SPEC, $contract_schema['properties']['pageSpecVersion']['const'] ?? '', 'Contract Bundle PageSpec identity' );

		$profile_schema = cf_load_json_file( CONTENT_FACTORY_DIR . 'schemas/theme-profile-' . ContentFactory\VersionRegistry::THEME_PROFILE_SCHEMA . '.schema.json' );
		cf_assert_same( ContentFactory\VersionRegistry::THEME_PROFILE_SCHEMA, $profile_schema['properties']['profileSchemaVersion']['const'] ?? '', 'Theme profile schema identity' );
		$profile = cf_load_json_file( CONTENT_FACTORY_DIR . 'adapters/potolki-inner/profile.json' );
		cf_assert_same( ContentFactory\VersionRegistry::THEME_PROFILE_SCHEMA, $profile['profileSchemaVersion'] ?? '', 'Profile definition schema version' );
	}
);

$runner->test(
	'core accepts a minimal valid contract',
	static function () use ( $core ): void {
		cf_assert( ! $core->validate( cf_core_spec() )->has_errors(), 'Valid core PageSpec was rejected.' );
	}
);

$runner->test(
	'sourceId links keep hierarchical URLs for managed drafts',
	static function () use ( $links ): void {
		$suffix    = strtolower( wp_generate_password( 8, false, false ) );
		$source_id = 'cf-test-draft-link-' . $suffix;
		$parent_id = 0;
		$child_id  = 0;
		try {
			$parent_id = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => 'Link parent',
					'post_name'   => 'cf-link-parent-' . $suffix,
				),
				true
			);
			cf_assert( ! is_wp_error( $parent_id ), 'Could not create link-test parent.' );
			$child_id = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => 'Link child',
					'post_name'   => 'cf-link-child-' . $suffix,
					'post_parent' => $parent_id,
				),
				true
			);
			cf_assert( ! is_wp_error( $child_id ), 'Could not create link-test child.' );
			update_post_meta( $child_id, '_content_factory_source_id', $source_id );
			$resolved = $links->resolve( array( 'kind' => 'page', 'sourceId' => $source_id ) );
			cf_assert( ! is_wp_error( $resolved ), 'Managed draft sourceId link did not resolve.' );
			$expected = home_url( '/cf-link-parent-' . $suffix . '/cf-link-child-' . $suffix . '/' );
			cf_assert_same( $expected, $resolved['url'], 'Managed draft hierarchical sourceId URL' );
		} finally {
			if ( $child_id && ! is_wp_error( $child_id ) ) {
				wp_delete_post( $child_id, true );
			}
			if ( $parent_id && ! is_wp_error( $parent_id ) ) {
				wp_delete_post( $parent_id, true );
			}
		}
	}
);

$runner->test(
	'core rejects missing required data',
	static function () use ( $core ): void {
		$spec = cf_core_spec();
		unset( $spec['post'] );
		cf_assert( $core->validate( $spec )->has_errors(), 'Missing post was accepted.' );
	}
);

$runner->test(
	'core rejects unknown fields',
	static function () use ( $core ): void {
		$spec            = cf_core_spec();
		$spec['mystery'] = 'must not be ignored';
		cf_assert_issue( $core->validate( $spec ), 'UNKNOWN_FIELD' );
	}
);

$runner->test(
	'core rejects a publish status',
	static function () use ( $core ): void {
		$spec           = cf_core_spec();
		$spec['status'] = 'publish';
		cf_assert_issue( $core->validate( $spec ), 'FORBIDDEN_FIELD' );
	}
);

$runner->test(
	'core distinguishes explicit null from a valid value',
	static function () use ( $core ): void {
		$spec                 = cf_core_spec();
		$spec['seo']['title'] = null;
		cf_assert_issue( $core->validate( $spec ), 'REQUIRED_FIELD' );
	}
);

$runner->test(
	'core rejects invalid sourceId',
	static function () use ( $core ): void {
		$spec             = cf_core_spec();
		$spec['sourceId'] = 'UPPER CASE';
		cf_assert_issue( $core->validate( $spec ), 'INVALID_SOURCE_ID' );
	}
);

$runner->test(
	'core rejects invalid slug',
	static function () use ( $core ): void {
		$spec                 = cf_core_spec();
		$spec['post']['slug'] = '../unsafe';
		cf_assert_issue( $core->validate( $spec ), 'INVALID_SLUG' );
	}
);

$runner->test(
	'core rejects null sections',
	static function () use ( $core ): void {
		$spec             = cf_core_spec();
		$spec['sections'] = null;
		cf_assert_issue( $core->validate( $spec ), 'INVALID_SECTIONS' );
	}
);

$runner->test(
	'core rejects non-string optional metadata',
	static function () use ( $core ): void {
		$spec = cf_core_spec();
		$spec['post']['categoryLabel'] = array( 'broken' );
		$spec['seo']['primaryKeyword'] = 42;
		$spec['generatedAgainst'] = array( 'profileId' => array() );
		$report = $core->validate( $spec );
		cf_assert( $report->has_errors(), 'Non-string optional metadata was accepted.' );
		cf_assert_issue( $report, 'INVALID_TYPE' );
		cf_assert( count( $report->issues() ) >= 3, 'Expected all metadata failures.' );
	}
);

$runner->test(
	'core warns only above the 85-character SEO title recommendation',
	static function () use ( $core ): void {
		$spec = cf_core_spec();
		$spec['seo']['title'] = str_repeat( 'а', 85 );
		cf_assert( ! in_array( 'SEO_TITLE_LENGTH', cf_issue_codes( $core->validate( $spec ) ), true ), 'An 85-character SEO Title produced a warning.' );
		$spec['seo']['title'] .= 'б';
		cf_assert_issue( $core->validate( $spec ), 'SEO_TITLE_LENGTH' );
	}
);

$runner->test(
	'core accepts only the registered PageSpec version with complete identity',
	static function () use ( $core ): void {
		$current = cf_core_spec();
		cf_assert( ! $core->validate( $current )->has_errors(), 'Complete current PageSpec identity was rejected.' );
		$old = $current;
		$old['schemaVersion'] = '1.0';
		cf_assert_issue( $core->validate( $old ), 'UNSUPPORTED_SCHEMA_VERSION' );
		unset( $current['target'], $current['generatedAgainst'] );
		$missing = $core->validate( $current );
		cf_assert_issue( $missing, 'REQUIRED_FIELD' );
		$current['target'] = array( 'siteKey' => 'potolkinaveka40', 'profileId' => 'potolki-inner' );
		$current['generatedAgainst'] = array( 'profileId' => 'potolki-inner', 'profileVersion' => '2.0.0', 'manifestHash' => 'sha256:' . str_repeat( 'a', 64 ) );
		cf_assert( ! $core->validate( $current )->has_errors(), 'Complete current PageSpec identity was rejected.' );
		$current['generatedAgainst']['manifestHash'] = str_repeat( 'a', 64 );
		cf_assert_issue( $core->validate( $current ), 'INVALID_FORMAT' );
	}
);

$adapter = null;
if ( class_exists( 'ContentFactory\\Adapter\\PotolkiInnerAdapter' ) ) {
	$adapter = new ContentFactory\Adapter\PotolkiInnerAdapter( $links );
}

$runner->test(
	'adapter resolves the profile modal trigger without a WordPress page',
	static function () use ( $adapter ): void {
		$spec = cf_load_fixture( 'service-detail' );
		$spec['sections'][0]['data']['primaryAction']['link'] = array( 'kind' => 'path', 'path' => '/forma-obratnoj-svyaz/' );
		$report = $adapter->validate( $spec, CF_Snapshot_Artifacts::context( $adapter, $spec ) );
		cf_assert( ! in_array( 'INVALID_PATH', cf_issue_codes( $report ), true ), 'Modal trigger path format was rejected.' );
		cf_assert( ! in_array( 'UNRESOLVED_LINK', cf_issue_codes( $report ), true ), 'Profile modal trigger path was unresolved.' );
	}
);

$runner->test(
	'adapter names a button whose required link is missing',
	static function () use ( $adapter ): void {
		$spec = cf_load_fixture( 'service-category' );
		$last = count( $spec['sections'] ) - 1;
		$label = (string) $spec['sections'][ $last ]['data']['primaryAction']['label'];
		unset( $spec['sections'][ $last ]['data']['primaryAction']['link'] );
		$report = $adapter->validate( $spec, CF_Snapshot_Artifacts::context( $adapter, $spec ) );
		$messages = array_map( static fn( $issue ): string => (string) ( $issue->jsonSerialize()['message'] ?? '' ), $report->issues() );
		cf_assert( in_array( sprintf( 'Блок «Финальный CTA»: для кнопки «%s» требуется ссылка.', $label ), $messages, true ), 'Required-link issue did not name the block and button.' );
	}
);

$runner->test(
	'adapter self-check matches the active theme registry',
	static function () use ( $adapter ): void {
		cf_assert( null !== $adapter, 'Adapter is unavailable.' );
		$report = $adapter->self_check();
		cf_assert( ! $report->has_errors(), 'Adapter self-check errors: ' . implode( ', ', cf_issue_codes( $report ) ) );
	}
);

$runner->test(
	'contract auditor catches executable consumer and allowedBlocks drift',
	static function () use ( $adapter ): void {
		$manifest = $adapter->compiled_profile()->configuration();
		$consumers = array(
			'hero' => array( 'kicker', 'title', 'lead', 'primaryAction', 'benefits', 'image', 'badge', 'note' ),
			'article' => array( 'title', 'accent', 'body' ), 'catalog' => array( 'kicker', 'title', 'items' ),
			'steps' => array( 'kicker', 'title', 'items' ), 'faq' => array( 'kicker', 'title', 'items' ),
			'parent-link' => array( 'label', 'linkLabel' ),
			'cta' => array( 'variant', 'kicker', 'title', 'text', 'benefits', 'primaryAction', 'secondaryAction' ),
		);
		$manifest['sections']['hero']['schema']['properties']['orphan'] = array( 'type' => 'string' );
		$manifest['sections']['hero']['allowedData'][] = 'orphan';
		$auditor = new ContentFactory\Contract\ContractAuditor();
		$report = $auditor->audit( $manifest, array( 'fieldConsumers' => $consumers ) );
		cf_assert_issue( $report, 'SEMANTIC_FIELD_WITHOUT_CONSUMER' );

		$clean = $adapter->compiled_profile()->configuration();
		$names = array_keys( $clean['policies']['registryContracts'] ?? array() );
		$registry = ( new ContentFactory\Contract\BlockRegistrySnapshot() )->capture( $names );
		$registry['potolki/inner-article']['allowedBlocks'] = array();
		$report = $auditor->audit( $clean, array( 'fieldConsumers' => $consumers, 'registry' => $registry ) );
		cf_assert_issue( $report, 'BLOCK_ALLOWED_CHILDREN_DRIFT' );
	}
);

$runner->test(
	'Contract Bundle rejects common secret keys and serves strict 1.1 examples',
	static function () use ( $adapter ): void {
		$builder = new ContentFactory\Contract\ContractBundleBuilder( new ContentFactory\Validation\PageSpecSchemaRegistry() );
		$configuration = $adapter->compiled_profile()->configuration();
		$configuration['siteDefaults']['apiKey'] = 'must-not-leak';
		$unsafe_profile = new ContentFactory\Profile\CompiledProfile(
			$configuration,
			$adapter->compiled_profile()->contract(),
			$adapter->compiled_profile()->canonical_hash()
		);
		cf_assert( is_wp_error( $builder->build( $unsafe_profile, $adapter->self_check() ) ), 'Contract Bundle accepted apiKey.' );

		$admin = get_user_by( 'login', 'admin' );
		cf_assert( $admin instanceof WP_User, 'Admin user is unavailable for REST contract test.' );
		$original_user = get_current_user_id();
		wp_set_current_user( $admin->ID );
		try {
			$request = new WP_REST_Request( 'GET', '/content-factory/v1/contract' );
			$request->set_param( 'siteKey', 'potolkinaveka40' );
			$request->set_param( 'profileId', 'potolki-inner' );
			$response = rest_do_request( $request );
		} finally {
			wp_set_current_user( $original_user );
		}
		$data = $response->get_data();
		cf_assert_same( 200, $response->get_status(), 'Contract endpoint status' );
		cf_assert( isset( $data['assets']['services-types']['path'] ), 'Contract Bundle omitted current public theme assets.' );
		cf_assert_same( 'assets/images/services-types.jpg', $data['assets']['services-types']['path'], 'Contract Bundle asset path' );
		cf_assert_same( 404, rest_do_request( new WP_REST_Request( 'GET', '/content-factory/v1/manifest' ) )->get_status(), 'Removed manifest endpoint status' );
		cf_assert_same( 404, rest_do_request( new WP_REST_Request( 'GET', '/content-factory/v1/schema/pagespec' ) )->get_status(), 'Removed schema endpoint status' );
		foreach ( $data['examples'] ?? array() as $example ) {
			cf_assert_same( '1.1', $example['schemaVersion'] ?? '', 'Contract example PageSpec version' );
			cf_assert_same( $data['identity']['manifestHash'], $example['generatedAgainst']['manifestHash'] ?? '', 'Contract example manifest hash' );
			cf_assert( ! is_wp_error( rest_validate_value_from_schema( $example, $data['pageSpecSchema'], 'example' ) ), 'Contract example does not pass advertised PageSpec schema.' );
		}
		$asset_variants = $data['pageSpecSchema']['$defs']['asset']['oneOf'] ?? array();
		cf_assert( $asset_variants && ! array_filter( $asset_variants, static fn( array $variant ): bool => isset( $variant['properties']['kind'] ) ), 'PageSpec 1.1 still advertises asset.kind.' );
		cf_assert( count( array_filter( $asset_variants, static fn( array $variant ): bool => isset( $variant['properties']['source'] ) ) ) === count( $asset_variants ), 'PageSpec 1.1 asset variants do not use runtime source.' );
	}
);

$runner->test(
	'baseline profile self-check and normalized fixture issues stay reviewable',
	static function () use ( $adapter ): void {
		$baseline = cf_load_json_file( __DIR__ . '/fixtures/expected/baseline.json' );
		cf_assert_same( $baseline['profileId'] ?? '', $adapter->id(), 'Baseline profile ID' );
		cf_assert_same( $baseline['profileVersion'] ?? '', $adapter->version(), 'Baseline profile version' );
		cf_assert_same( $baseline['manifestHash'] ?? '', $adapter->manifest_hash(), 'Baseline manifest hash' );
		cf_assert_same( $baseline['selfCheck'] ?? array(), $adapter->self_check()->jsonSerialize(), 'Baseline self-check' );
		foreach ( array( 'service-detail', 'service-category' ) as $fixture_name ) {
			$spec   = cf_load_fixture( $fixture_name );
			$report = $adapter->validate( $spec, CF_Snapshot_Artifacts::context( $adapter, $spec ) );
			$actual = array(
				'status'     => $report->status(),
				'issues'     => array_map( static fn( $issue ): array => $issue->jsonSerialize(), $report->issues() ),
				'blockCount' => 2,
			);
			cf_assert_same( $baseline['fixtures'][ $fixture_name ] ?? array(), $actual, $fixture_name . ' normalized baseline issues' );
		}
	}
);

$runner->test(
	'adapter exposes verified SEO catalog assets',
	static function () use ( $adapter ): void {
		cf_assert( null !== $adapter, 'Adapter is unavailable.' );
		$assets = $adapter->compiled_profile()->assets();
		$expected = array(
			'hero-benefit-canvas',
			'gallery-niche',
			'process-cornice',
			'lighting-gx53',
			'lighting-chandelier',
			'lighting-track',
			'lighting-linear',
			'lighting-led',
			'gallery-lighting',
			'process-profile',
			'2process-profile-a',
			'gallery-shadow',
			'gallery-floating',
		);
		foreach ( $expected as $ref ) {
			cf_assert( isset( $assets[ $ref ]['path'] ), 'Verified SEO asset is missing from manifest: ' . $ref );
			cf_assert( is_readable( get_theme_file_path( $assets[ $ref ]['path'] ) ), 'Verified SEO asset file is missing: ' . $ref );
		}
	}
);

$runner->test(
	'adapter accepts exact target and generatedAgainst metadata',
	static function () use ( $adapter ): void {
		cf_assert( null !== $adapter, 'Adapter is unavailable.' );
		$spec = cf_load_fixture( 'service-detail' );
		$profile = $adapter->compiled_profile();
		$spec['generatedAgainst'] = array(
			'profileId'      => $adapter->id(),
			'profileVersion' => $adapter->version(),
			'manifestHash'   => $adapter->manifest_hash(),
		);
		$spec['target'] = array(
			'siteKey'   => $profile->site_key(),
			'profileId' => $adapter->id(),
		);
		$report = $adapter->validate( $spec, cf_adapter_context( $spec ) );
		cf_assert( ! $report->has_errors(), 'Exact metadata was rejected: ' . implode( ', ', cf_issue_codes( $report ) ) );
	}
);

$runner->test(
	'adapter accepts unbounded content sections, steps and FAQ items',
	static function () use ( $adapter, $links ): void {
		cf_assert( null !== $adapter, 'Adapter is unavailable.' );
		$detail   = cf_load_fixture( 'service-detail' );
		$category = cf_load_fixture( 'service-category' );
		$sections = array( $detail['sections'][0] );

		for ( $index = 1; $index <= 12; ++$index ) {
			$article                  = $detail['sections'][1];
			$article['id']            = 'article-' . $index;
			$article['data']['title'] = 'Тематический раздел ' . $index;
			$sections[]               = $article;
		}
		for ( $index = 1; $index <= 2; ++$index ) {
			$catalog       = $category['sections'][1];
			$catalog['id'] = 'catalog-' . $index;
			$sections[]    = $catalog;
		}
		for ( $section_index = 1; $section_index <= 2; ++$section_index ) {
			$steps                  = $detail['sections'][2];
			$steps['id']            = 'steps-' . $section_index;
			$steps['data']['items'] = array();
			for ( $item_index = 1; $item_index <= 12; ++$item_index ) {
				$steps['data']['items'][] = array( 'title' => 'Этап ' . $item_index, 'text' => 'Описание этапа ' . $section_index . '.' . $item_index . '.' );
			}
			$sections[] = $steps;
		}
		$sections[] = $detail['sections'][4];
		$sections[] = array( 'id' => 'parent-navigation', 'type' => 'parent-link', 'data' => array() );
		for ( $section_index = 1; $section_index <= 2; ++$section_index ) {
			$faq                  = $detail['sections'][3];
			$faq['id']            = 'faq-' . $section_index;
			$faq['data']['items'] = array();
			for ( $item_index = 1; $item_index <= 12; ++$item_index ) {
				$faq['data']['items'][] = array( 'question' => 'Вопрос ' . $section_index . '.' . $item_index . '?', 'answer' => 'Ответ ' . $section_index . '.' . $item_index . '.' );
			}
			$sections[] = $faq;
		}
		$detail['sections'] = $sections;

		$report = $adapter->validate( $detail, cf_adapter_context( $detail ) );
		cf_assert( ! $report->has_errors(), 'Unbounded content was rejected: ' . implode( ', ', cf_issue_codes( $report ) ) );
		$tree = $adapter->build( $detail, cf_adapter_context( $detail ) );
		$names = array_map( static fn( ContentFactory\Contract\BlockNode $node ): string => $node->name(), $tree[1]->children() );
		cf_assert_same( 12, count( array_filter( $names, static fn( string $name ): bool => 'potolki/inner-article' === $name ) ), 'Unbounded article count' );
		cf_assert_same( 2, count( array_filter( $names, static fn( string $name ): bool => 'potolki/inner-catalog' === $name ) ), 'Unbounded catalog count' );
		cf_assert_same( 2, count( array_filter( $names, static fn( string $name ): bool => 'potolki/inner-steps' === $name ) ), 'Unbounded steps section count' );
		cf_assert_same( 2, count( array_filter( $names, static fn( string $name ): bool => 'potolki/inner-faq' === $name ) ), 'Unbounded FAQ section count' );
		cf_assert_same( 1, count( array_filter( $names, static fn( string $name ): bool => 'potolki/inner-cta' === $name ) ), 'Theme allows one CTA' );
		cf_assert_same( 1, count( array_filter( $names, static fn( string $name ): bool => 'potolki/inner-parent-link' === $name ) ), 'Explicit parent link after CTA is not duplicated' );
		cf_assert_same( 'potolki/inner-faq', end( $names ), 'CTA position follows the source instead of being forced to the end' );

		$declarative_children = $tree[1]->children();
		$step_nodes = array_values( array_filter( $declarative_children, static fn( ContentFactory\Contract\BlockNode $node ): bool => 'potolki/inner-steps' === $node->name() ) );
		$faq_nodes = array_values( array_filter( $declarative_children, static fn( ContentFactory\Contract\BlockNode $node ): bool => 'potolki/inner-faq' === $node->name() ) );
		$catalog_nodes = array_values( array_filter( $declarative_children, static fn( ContentFactory\Contract\BlockNode $node ): bool => 'potolki/inner-catalog' === $node->name() ) );
		cf_assert_same( array( 'steps-1', 'steps-2' ), array_map( static fn( ContentFactory\Contract\BlockNode $node ): string => (string) ( $node->attrs()['sectionId'] ?? '' ), $step_nodes ), 'Declarative engine preserved repeated steps IDs' );
		cf_assert_same( array( 12, 12 ), array_map( static fn( ContentFactory\Contract\BlockNode $node ): int => count( $node->children() ), $step_nodes ), 'Declarative engine preserved all repeated step items' );
		cf_assert_same( array( 'faq-1', 'faq-2' ), array_map( static fn( ContentFactory\Contract\BlockNode $node ): string => (string) ( $node->attrs()['sectionId'] ?? '' ), $faq_nodes ), 'Declarative engine preserved repeated FAQ IDs' );
		cf_assert_same( array( 12, 12 ), array_map( static fn( ContentFactory\Contract\BlockNode $node ): int => count( $node->children() ), $faq_nodes ), 'Declarative engine preserved all repeated FAQ items' );
		cf_assert_same( array( 'catalog-1', 'catalog-2' ), array_map( static fn( ContentFactory\Contract\BlockNode $node ): string => (string) ( $node->attrs()['sectionId'] ?? '' ), $catalog_nodes ), 'Declarative engine preserved repeated catalog IDs' );
		cf_assert_same( array( 3, 3 ), array_map( static fn( ContentFactory\Contract\BlockNode $node ): int => count( $node->children() ), $catalog_nodes ), 'Declarative engine preserved all repeated catalog cards' );
	}
);

$runner->test(
	'adapter treats generated version and hash mismatch as warnings',
	static function () use ( $adapter ): void {
		cf_assert( null !== $adapter, 'Adapter is unavailable.' );
		$spec = cf_load_fixture( 'service-detail' );
		$spec['generatedAgainst'] = array(
			'profileId'      => $adapter->id(),
			'profileVersion' => '99.0.0',
			'manifestHash'   => str_repeat( '0', 64 ),
		);
		$report = $adapter->validate( $spec, cf_adapter_context( $spec ) );
		cf_assert( ! $report->has_errors(), 'Advisory generatedAgainst mismatch blocked the fixture.' );
		cf_assert( 'compatible_with_warnings' === $report->status(), 'Expected compatibility warnings for generatedAgainst mismatch.' );
	}
);

$runner->test(
	'pipeline rejects canonical paths that differ from the resolved permalink',
	static function () use ( $adapter, $hierarchy, $serializer ): void {
		$registry = new ContentFactory\Adapter\AdapterRegistry();
		$registry->register( $adapter );
		$pipeline = new ContentFactory\Service\ContentPipeline( $registry, new ContentFactory\Validation\CorePageSpecValidator(), $hierarchy, $serializer );
		$spec = cf_load_fixture( 'service-detail' );
		unset( $spec['post']['parent'] );
		cf_replace_path_links( $spec );
		foreach ( array( 'https://example.com/matovye/', 'https://example.com/matovye/?tracking=1' ) as $canonical ) {
			$spec['seo']['canonical'] = $canonical;
			cf_assert_issue( $pipeline->process( $spec ), 'CANONICAL_MISMATCH' );
		}
	}
);

$runner->test(
	'pipeline rejects slugs WordPress would rewrite on publish',
	static function () use ( $adapter, $hierarchy, $serializer ): void {
		$registry = new ContentFactory\Adapter\AdapterRegistry();
		$registry->register( $adapter );
		$pipeline = new ContentFactory\Service\ContentPipeline( $registry, new ContentFactory\Validation\CorePageSpecValidator(), $hierarchy, $serializer );
		$spec = cf_load_fixture( 'service-detail' );
		unset( $spec['post']['parent'] );
		cf_replace_path_links( $spec );
		$spec['sourceId'] = 'cf-test-reserved-feed';
		$spec['post']['slug'] = 'feed';
		cf_assert_issue( $pipeline->process( $spec ), 'PUBLISH_SLUG_CONFLICT' );
	}
);

$runner->test(
	'adapter blocks a mismatched target',
	static function () use ( $adapter ): void {
		cf_assert( null !== $adapter, 'Adapter is unavailable.' );
		$spec = cf_load_fixture( 'service-detail' );
		$spec['target'] = array( 'siteKey' => 'wrong-site', 'profileId' => $adapter->id() );
		cf_assert( $adapter->validate( $spec, cf_adapter_context( $spec ) )->has_errors(), 'Wrong target site was accepted.' );
	}
);

$runner->test(
	'adapter rejects non-image media attachments',
	static function () use ( $adapter ): void {
		$attachment_id = wp_insert_attachment( array( 'post_title' => 'CF PDF fixture', 'post_status' => 'inherit', 'post_mime_type' => 'application/pdf', 'guid' => home_url( '/wp-content/uploads/cf-fixture.pdf' ) ) );
		cf_assert( is_int( $attachment_id ) && $attachment_id > 0, 'Could not create attachment fixture.' );
		try {
			$spec = cf_load_fixture( 'service-detail' );
			$spec['sections'][0]['data']['image'] = array( 'source' => 'mediaId', 'id' => $attachment_id, 'alt' => 'PDF must fail' );
			$report = $adapter->validate( $spec, cf_adapter_context( $spec ) );
			cf_assert_issue( $report, 'INVALID_ASSET_MIME' );
		} finally {
			wp_delete_attachment( $attachment_id, true );
		}
	}
);

$golden_tree = null;
$golden_spec = null;
$runner->test(
	'adapter builds both golden fixtures',
	static function () use ( $adapter, &$golden_tree, &$golden_spec ): void {
		cf_assert( null !== $adapter, 'Adapter is unavailable.' );
		$expected_children = array(
			'service-detail'   => array( 'potolki/inner-article', 'potolki/inner-steps', 'potolki/inner-faq', 'potolki/inner-parent-link', 'potolki/inner-cta' ),
			'service-category' => array( 'potolki/inner-catalog', 'potolki/inner-faq', 'potolki/inner-cta' ),
		);
		foreach ( array( 'service-detail', 'service-category' ) as $fixture ) {
			$spec   = cf_load_fixture( $fixture );
			$report = $adapter->validate( $spec, cf_adapter_context( $spec ) );
			cf_assert( ! $report->has_errors(), $fixture . ' validation failed: ' . implode( ', ', cf_issue_codes( $report ) ) );
			$tree = $adapter->build( $spec, cf_adapter_context( $spec ) );
			cf_assert( count( $tree ) === 2, $fixture . ' must have exactly two root blocks.' );
			cf_assert_same( 'potolki/inner-hero', $tree[0]->name(), $fixture . ' root block 0' );
			cf_assert_same( 'potolki/inner-content', $tree[1]->name(), $fixture . ' root block 1' );
			cf_assert_same(
				$expected_children[ $fixture ],
				array_map( static fn( ContentFactory\Contract\BlockNode $node ): string => $node->name(), $tree[1]->children() ),
				$fixture . ' inner-content children'
			);
			if ( 'service-detail' === $fixture ) {
				$golden_spec = $spec;
				$golden_tree = $tree;
			}
		}
	}
);

$runner->test(
	'Gutenberg serialization survives parse and round-trip',
	static function () use ( $serializer, &$golden_tree ): void {
		cf_assert( is_array( $golden_tree ), 'Golden tree was not built.' );
		$content = $serializer->serialize( $golden_tree );
		$parsed  = array_values( array_filter( parse_blocks( $content ), static fn( array $block ): bool => null !== $block['blockName'] ) );
		cf_assert_same( 2, count( $parsed ), 'Parsed root block count' );
		cf_assert_same( 'potolki/inner-hero', $parsed[0]['blockName'], 'Parsed first block' );
		cf_assert_same( 'potolki/inner-content', $parsed[1]['blockName'], 'Parsed second block' );
		cf_assert( true === $serializer->round_trip( $golden_tree, $content ), 'Serializer round-trip failed.' );
	}
);

$runner->test(
	'hero supports more than two lead paragraphs without changing two-paragraph output',
	static function () use ( $adapter, $serializer ): void {
		cf_assert( null !== $adapter, 'Adapter is unavailable.' );
		$base_spec = cf_load_fixture( 'service-detail' );
		$base_tree = $adapter->build( $base_spec, cf_adapter_context( $base_spec ) );
		cf_assert_same( 0, count( $base_tree[0]->children() ), 'Two-paragraph hero must keep compact serialization' );
		cf_assert( ! array_key_exists( 'hasLeadBlocks', $base_tree[0]->attrs() ), 'Two-paragraph hero unexpectedly opted into lead blocks.' );

		$expanded_spec = $base_spec;
		$expanded_spec['sections'][0]['data']['lead'][] = 'Третий абзац остаётся внутри первого экрана.';
		$report = $adapter->validate( $expanded_spec, cf_adapter_context( $expanded_spec ) );
		cf_assert( ! $report->has_errors(), 'Expanded hero validation failed: ' . implode( ', ', cf_issue_codes( $report ) ) );
		$tree = $adapter->build( $expanded_spec, cf_adapter_context( $expanded_spec ) );
		cf_assert_same( true, $tree[0]->attrs()['hasLeadBlocks'] ?? false, 'Expanded hero did not opt into lead blocks' );
		cf_assert_same(
			array( 'core/paragraph', 'core/paragraph', 'core/paragraph' ),
			array_map( static fn( ContentFactory\Contract\BlockNode $node ): string => $node->name(), $tree[0]->children() ),
			'Expanded hero paragraph children'
		);
		$content = $serializer->serialize( $tree );
		cf_assert( true === $serializer->round_trip( $tree, $content ), 'Expanded hero serializer round-trip failed.' );
		$html = do_blocks( $content );
		preg_match( '#<div class="inner-hero__lead">(.*?)</div>#s', $html, $lead_match );
		$lead_html = (string) ( $lead_match[1] ?? '' );
		cf_assert_same( 3, substr_count( $lead_html, 'class="wp-block-paragraph"' ), 'Expanded hero rendered paragraph count' );
		cf_assert( str_contains( $lead_html, 'Третий абзац остаётся внутри первого экрана.' ), 'Expanded hero lost the third paragraph during render.' );
	}
);

$runner->test(
	'golden fixtures match immutable Block Tree and post_content snapshots',
	static function () use ( $adapter, $serializer ): void {
		cf_assert( null !== $adapter, 'Adapter is unavailable.' );
		foreach ( array( 'service-detail', 'service-category' ) as $fixture_name ) {
			$spec     = cf_load_fixture( $fixture_name );
			$output   = CF_Snapshot_Artifacts::output( $adapter, $serializer, $spec, CF_Snapshot_Artifacts::context( $adapter, $spec ) );
			$blocks   = cf_load_json_file( __DIR__ . '/fixtures/expected/' . $fixture_name . '.blocks.json' );
			$content  = (string) file_get_contents( __DIR__ . '/fixtures/expected/' . $fixture_name . '.post-content.html' );
			cf_assert_same( $blocks, $output['blocks'], $fixture_name . ' exact Block Tree snapshot' );
			cf_assert_same( rtrim( $content, "\r\n" ), $output['postContent'], $fixture_name . ' exact post_content snapshot' );
		}
	}
);

$runner->test(
	'snapshot comparison detects attribute and innerHTML mutations',
	static function () use ( $adapter, $serializer ): void {
		$spec     = cf_load_fixture( 'service-detail' );
		$output   = CF_Snapshot_Artifacts::output( $adapter, $serializer, $spec, CF_Snapshot_Artifacts::context( $adapter, $spec ) );
		$expected = cf_load_json_file( __DIR__ . '/fixtures/expected/service-detail.blocks.json' );
		$attribute_mutation = $output['blocks'];
		$attribute_mutation[0]['attrs']['title'] .= ' mutation';
		cf_assert( $expected !== $attribute_mutation, 'Attribute mutation did not break the snapshot.' );
		$html_mutation = $output['blocks'];
		$html_mutation[1]['innerHTML'] .= '<!-- mutation -->';
		cf_assert( $expected !== $html_mutation, 'innerHTML mutation did not break the snapshot.' );
	}
);

$runner->test(
	'49-page regression corpus keeps exact Block Tree and post_content hashes',
	static function () use ( $adapter, $serializer, $zip ): void {
		$entries = $zip->import_file( __DIR__ . '/fixtures/regression-corpus/pagespec.zip' );
		cf_assert( is_array( $entries ), 'Regression corpus ZIP could not be read.' );
		$specs = array();
		foreach ( $entries as $entry ) {
			$data  = $entry['data'];
			$pages = isset( $data['pages'] ) ? $data['pages'] : ( array_is_list( $data ) ? $data : array( $data ) );
			array_push( $specs, ...$pages );
		}
		$expected = cf_load_json_file( __DIR__ . '/fixtures/regression-corpus/expected-hashes.json' );
		cf_assert_same( 49, count( $specs ), 'Regression corpus PageSpec count' );
		cf_assert_same( 49, (int) ( $expected['corpusCount'] ?? 0 ), 'Expected regression corpus count' );
		cf_assert_same( $expected['pages'], CF_Snapshot_Artifacts::corpus_hashes( $adapter, $serializer, $specs ), 'Regression corpus hashes' );
	}
);

$runner->test(
	'steps are numbered and FAQ opens only its first item',
	static function () use ( &$golden_tree ): void {
		cf_assert( is_array( $golden_tree ), 'Golden tree was not built.' );
		$steps = cf_find_node( $golden_tree, 'potolki/inner-steps' );
		$faq   = cf_find_node( $golden_tree, 'potolki/inner-faq' );
		cf_assert( null !== $steps, 'Golden fixture has no steps block.' );
		cf_assert( null !== $faq, 'Golden fixture has no FAQ block.' );
		foreach ( $steps->children() as $index => $step ) {
			cf_assert_same( (string) ( $index + 1 ), $step->attrs()['number'] ?? null, 'Step number at index ' . $index );
		}
		cf_assert( count( $faq->children() ) >= 2, 'FAQ fixture must contain at least two items.' );
		foreach ( $faq->children() as $index => $item ) {
			cf_assert_same( 0 === $index, $item->attrs()['isOpen'] ?? null, 'FAQ isOpen at index ' . $index );
		}
	}
);

$runner->test(
	'hierarchy sorts parents before children',
	static function () use ( $hierarchy ): void {
		$child  = array( 'sourceId' => 'child', 'post' => array( 'parent' => array( 'sourceId' => 'parent' ) ) );
		$parent = array( 'sourceId' => 'parent', 'post' => array() );
		$sorted = $hierarchy->sort_batch( array( $child, $parent ) );
		cf_assert( is_array( $sorted ), 'Valid hierarchy was rejected.' );
		cf_assert_same( array( 'parent', 'child' ), array_column( $sorted, 'sourceId' ), 'Hierarchy order' );
	}
);

$runner->test(
	'hierarchy rejects cycles',
	static function () use ( $hierarchy ): void {
		$result = $hierarchy->sort_batch(
			array(
				array( 'sourceId' => 'a', 'post' => array( 'parent' => array( 'sourceId' => 'b' ) ) ),
				array( 'sourceId' => 'b', 'post' => array( 'parent' => array( 'sourceId' => 'a' ) ) ),
			)
		);
		cf_assert( is_wp_error( $result ) && 'parent_cycle' === $result->get_error_code(), 'Parent cycle was not rejected.' );
	}
);

$runner->test(
	'hierarchy rejects duplicate sourceIds',
	static function () use ( $hierarchy ): void {
		$result = $hierarchy->sort_batch( array( array( 'sourceId' => 'same' ), array( 'sourceId' => 'same' ) ) );
		cf_assert( is_wp_error( $result ) && 'duplicate_source_id' === $result->get_error_code(), 'Duplicate sourceId was not rejected.' );
	}
);

$runner->test(
	'batch preview rejects duplicate paths and propagates parent failures',
	static function () use ( $adapter, $hierarchy, $serializer ): void {
		cf_assert( null !== $adapter, 'Adapter is unavailable.' );
		$registry = new ContentFactory\Adapter\AdapterRegistry();
		$registry->register( $adapter );
		$pipeline = new ContentFactory\Service\ContentPipeline( $registry, new ContentFactory\Validation\CorePageSpecValidator(), $hierarchy, $serializer );
		$drafts = new ContentFactory\WordPress\DraftManager( $pipeline, $registry, new ContentFactory\WordPress\HashManager(), new ContentFactory\WordPress\YoastAdapter() );
		$batch = new ContentFactory\Import\BatchRunner( $hierarchy, $pipeline, $drafts );

		$first = cf_load_fixture( 'service-category' );
		$first['sourceId'] = 'cf-test-batch-dup-a';
		$first['post']['slug'] = 'cf-test-batch-duplicate';
		$second = $first;
		$second['sourceId'] = 'cf-test-batch-dup-b';
		$duplicates = $batch->validate( array( $first, $second ) );
		cf_assert( is_array( $duplicates ), 'Duplicate path preview returned a batch-level error.' );
		cf_assert_issue( $duplicates[0]['report'], 'DUPLICATE_BATCH_PATH' );
		cf_assert_issue( $duplicates[1]['report'], 'DUPLICATE_BATCH_PATH' );

		$parent = cf_load_fixture( 'service-category' );
		$parent['sourceId'] = 'cf-test-invalid-parent';
		$parent['post']['slug'] = 'cf-test-invalid-parent';
		$parent['target'] = array( 'siteKey' => 'wrong-site', 'profileId' => $adapter->id() );
		$child = cf_load_fixture( 'service-detail' );
		$child['sourceId'] = 'cf-test-dependent-child';
		$child['post']['slug'] = 'cf-test-dependent-child';
		$child['post']['parent'] = array( 'sourceId' => $parent['sourceId'] );
		$dependency = $batch->validate( array( $child, $parent ) );
		cf_assert( is_array( $dependency ), 'Dependency preview returned a batch-level error.' );
		$child_result = array_values( array_filter( $dependency, static fn( array $result ): bool => 'cf-test-dependent-child' === $result['sourceId'] ) )[0];
		cf_assert_issue( $child_result['report'], 'BATCH_PARENT_INCOMPATIBLE' );
	}
);

$runner->test(
	'batch preview propagates incompatible sourceId link targets',
	static function () use ( $adapter, $hierarchy, $serializer ): void {
		$registry = new ContentFactory\Adapter\AdapterRegistry();
		$registry->register( $adapter );
		$pipeline = new ContentFactory\Service\ContentPipeline( $registry, new ContentFactory\Validation\CorePageSpecValidator(), $hierarchy, $serializer );
		$drafts = new ContentFactory\WordPress\DraftManager( $pipeline, $registry, new ContentFactory\WordPress\HashManager(), new ContentFactory\WordPress\YoastAdapter() );
		$batch = new ContentFactory\Import\BatchRunner( $hierarchy, $pipeline, $drafts );
		$target = cf_load_fixture( 'service-detail' );
		cf_replace_path_links( $target );
		$target['sourceId'] = 'cf-test-link-target';
		$target['post']['slug'] = 'cf-test-link-target';
		$target['target'] = array( 'siteKey' => 'wrong-site', 'profileId' => $adapter->id() );
		$source = cf_load_fixture( 'service-detail' );
		cf_replace_path_links( $source );
		$source['sourceId'] = 'cf-test-link-source';
		$source['post']['slug'] = 'cf-test-link-source';
		$source['sections'][1]['data']['body'][3]['items'][0]['link'] = array( 'kind' => 'page', 'sourceId' => $target['sourceId'] );
		$results = $batch->validate( array( $source, $target ) );
		$source_result = array_values( array_filter( $results, static fn( array $row ): bool => 'cf-test-link-source' === $row['sourceId'] ) )[0];
		cf_assert_issue( $source_result['report'], 'BATCH_LINK_TARGET_INCOMPATIBLE' );
	}
);

$runner->test(
	'atomic batch creates zero drafts when one PageSpec is incompatible',
	static function () use ( $adapter, $hierarchy, $serializer ): void {
		$admin = get_user_by( 'login', 'admin' );
		cf_assert( $admin instanceof WP_User, 'Admin user is unavailable.' );
		$original_user = get_current_user_id();
		$suffix = strtolower( wp_generate_password( 8, false, false ) );
		$valid_id   = 'cf-atomic-valid-' . $suffix;
		$invalid_id = 'cf-atomic-invalid-' . $suffix;
		try {
			wp_set_current_user( $admin->ID );
			$registry = new ContentFactory\Adapter\AdapterRegistry();
			$registry->register( $adapter );
			$pipeline = new ContentFactory\Service\ContentPipeline( $registry, new ContentFactory\Validation\CorePageSpecValidator(), $hierarchy, $serializer );
			$drafts = new ContentFactory\WordPress\DraftManager( $pipeline, $registry, new ContentFactory\WordPress\HashManager(), new ContentFactory\WordPress\YoastAdapter() );
			$batch  = new ContentFactory\Import\BatchRunner( $hierarchy, $pipeline, $drafts );

			$valid = cf_load_fixture( 'service-detail' );
			cf_replace_path_links( $valid );
			$valid['sourceId']     = $valid_id;
			$valid['post']['slug'] = $valid_id;
			unset( $valid['post']['parent'] );
			$invalid = $valid;
			$invalid['sourceId']     = $invalid_id;
			$invalid['post']['slug'] = $invalid_id;
			$invalid['target']['siteKey'] = 'wrong-site';

			$result = $batch->run( array( $valid, $invalid ), true );
			cf_assert( is_array( $result ), 'Atomic batch returned a batch-level error.' );
			cf_assert_same( 0, $result['counts']['created'] ?? -1, 'Atomic created count' );
			cf_assert_same( 2, $result['counts']['failed'] ?? -1, 'Atomic failed count' );
			cf_assert( null === $drafts->find_by_source_id( $valid_id ), 'Atomic validation failure created the compatible draft.' );
			cf_assert( null === $drafts->find_by_source_id( $invalid_id ), 'Atomic validation failure created the incompatible draft.' );
		} finally {
			foreach ( array( $valid_id, $invalid_id ) as $source_id ) {
				$posts = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => '_content_factory_source_id', 'meta_value' => $source_id, 'fields' => 'ids', 'posts_per_page' => -1 ) );
				foreach ( $posts as $post_id ) {
					wp_delete_post( $post_id, true );
				}
			}
			wp_set_current_user( $original_user );
		}
	}
);

$runner->test(
	'batch allows mutual links and rolls back dependents after runtime failure',
	static function () use ( $adapter, $hierarchy, $serializer ): void {
		$admin = get_user_by( 'login', 'admin' );
		cf_assert( $admin instanceof WP_User, 'Admin user is unavailable.' );
		$original_user = get_current_user_id();
		$a_id = 'cf-test-cycle-a';
		$b_id = 'cf-test-cycle-b';
		$hook = null;
		try {
			wp_set_current_user( $admin->ID );
			$registry = new ContentFactory\Adapter\AdapterRegistry();
			$registry->register( $adapter );
			$pipeline = new ContentFactory\Service\ContentPipeline( $registry, new ContentFactory\Validation\CorePageSpecValidator(), $hierarchy, $serializer );
			$drafts = new ContentFactory\WordPress\DraftManager( $pipeline, $registry, new ContentFactory\WordPress\HashManager(), new ContentFactory\WordPress\YoastAdapter() );
			$batch = new ContentFactory\Import\BatchRunner( $hierarchy, $pipeline, $drafts );
			$a = cf_load_fixture( 'service-detail' );
			$b = cf_load_fixture( 'service-detail' );
			foreach ( array( &$a, &$b ) as &$spec ) {
				unset( $spec['post']['parent'] );
				cf_replace_path_links( $spec );
			}
			unset( $spec );
			$a['sourceId'] = $a_id;
			$a['post']['slug'] = $a_id;
			$b['sourceId'] = $b_id;
			$b['post']['slug'] = $b_id;
			$a['sections'][1]['data']['body'][3]['items'][0]['link'] = array( 'kind' => 'page', 'sourceId' => $b_id );
			$b['sections'][1]['data']['body'][3]['items'][0]['link'] = array( 'kind' => 'page', 'sourceId' => $a_id );
			$preview = $batch->validate( array( $a, $b ) );
			cf_assert( is_array( $preview ) && ! $preview[0]['report']->has_errors() && ! $preview[1]['report']->has_errors(), 'Mutual link batch was rejected.' );
			$throw_once = true;
			$hook = static function ( int $post_id, WP_Post $post ) use ( $a_id, &$throw_once ): void {
				if ( $throw_once && $a_id === $post->post_name ) {
					$throw_once = false;
					throw new RuntimeException( 'Synthetic batch target failure' );
				}
			};
			add_action( 'save_post_page', $hook, 99, 2 );
			$result = $batch->run( array( $b, $a ), true );
			remove_action( 'save_post_page', $hook, 99 );
			$hook = null;
			cf_assert( is_array( $result ), 'Runtime batch returned a batch-level error.' );
			$by_source = array_column( $result['results'], null, 'sourceId' );
			cf_assert_same( 'error', $by_source[ $a_id ]['action'] ?? '', 'Synthetic failing page action' );
			cf_assert_same( 'rolled_back', $by_source[ $b_id ]['action'] ?? '', 'Dependent mutual-link page action' );
			cf_assert( true === ( $by_source[ $b_id ]['rollback'] ?? false ), 'Dependent page rollback failed.' );
			cf_assert( null === $drafts->find_by_source_id( $a_id ) && null === $drafts->find_by_source_id( $b_id ), 'Runtime batch left a broken managed draft.' );
		} finally {
			if ( $hook ) {
				remove_action( 'save_post_page', $hook, 99 );
			}
			foreach ( array( $a_id, $b_id ) as $source_id ) {
				$posts = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => '_content_factory_source_id', 'meta_value' => $source_id, 'fields' => 'ids', 'posts_per_page' => -1 ) );
				foreach ( $posts as $post_id ) {
					wp_delete_post( $post_id, true );
				}
			}
			wp_set_current_user( $original_user );
		}
	}
);

$runner->test(
	'batch creates a parent before a catalog-linked child',
	static function () use ( $adapter, $hierarchy, $serializer ): void {
		$admin = get_user_by( 'login', 'admin' );
		cf_assert( $admin instanceof WP_User, 'Admin user is unavailable.' );
		$original_user = get_current_user_id();
		$parent_id = 'cf-test-parent-catalog';
		$child_id  = 'cf-test-parent-detail';
		try {
			wp_set_current_user( $admin->ID );
			$registry = new ContentFactory\Adapter\AdapterRegistry();
			$registry->register( $adapter );
			$pipeline = new ContentFactory\Service\ContentPipeline( $registry, new ContentFactory\Validation\CorePageSpecValidator(), $hierarchy, $serializer );
			$drafts = new ContentFactory\WordPress\DraftManager( $pipeline, $registry, new ContentFactory\WordPress\HashManager(), new ContentFactory\WordPress\YoastAdapter() );
			$batch = new ContentFactory\Import\BatchRunner( $hierarchy, $pipeline, $drafts );

			$parent = cf_load_fixture( 'service-category' );
			$parent['sourceId'] = $parent_id;
			$parent['post']['slug'] = $parent_id;
			$parent['sections'][1]['data']['items'] = array( $parent['sections'][1]['data']['items'][0] );
			$parent['sections'][1]['data']['items'][0]['action']['link'] = array( 'kind' => 'page', 'sourceId' => $child_id );
			$last = count( $parent['sections'] ) - 1;
			$parent['sections'][ $last ]['data'] = array(
				'variant' => 'form',
				'title' => 'Тестовая форма',
				'text' => 'Проверка порядка родителя и дочерней страницы.',
				'primaryAction' => array( 'label' => 'Отправить' ),
			);

			$child = cf_load_fixture( 'service-detail' );
			cf_replace_path_links( $child );
			$child['sourceId'] = $child_id;
			$child['post']['slug'] = $child_id;
			$child['post']['parent'] = array( 'sourceId' => $parent_id );

			$result = $batch->run( array( $child, $parent ), true );
			cf_assert( is_array( $result ), 'Parent/child batch returned an error.' );
			cf_assert_same( 2, $result['counts']['created'] ?? 0, 'Created parent/child draft count' );
			$parent_post = $drafts->find_by_source_id( $parent_id );
			$child_post  = $drafts->find_by_source_id( $child_id );
			cf_assert( $parent_post instanceof WP_Post && $child_post instanceof WP_Post, 'Parent/child drafts were not created.' );
			cf_assert_same( $parent_post->ID, (int) $child_post->post_parent, 'Resolved child parent ID' );
		} finally {
			foreach ( array( $child_id, $parent_id ) as $source_id ) {
				$posts = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'meta_key' => '_content_factory_source_id', 'meta_value' => $source_id, 'fields' => 'ids', 'posts_per_page' => -1 ) );
				foreach ( $posts as $post_id ) {
					wp_delete_post( $post_id, true );
				}
			}
			wp_set_current_user( $original_user );
		}
	}
);

$runner->test(
	'link resolver rejects unsafe URLs and paths',
	static function () use ( $links ): void {
		$external = $links->resolve( array( 'kind' => 'external', 'url' => 'javascript:alert(1)' ) );
		$path     = $links->resolve( array( 'kind' => 'path', 'path' => '/javascript:alert(1)' ) );
		cf_assert( is_wp_error( $external ) && 'invalid_external_url' === $external->get_error_code(), 'Unsafe external URL was accepted.' );
		cf_assert( is_wp_error( $path ) && 'invalid_path' === $path->get_error_code(), 'Unsafe internal path was accepted.' );
	}
);

$runner->test(
	'link resolver requires existing or planned internal paths',
	static function () use ( $links ): void {
		$missing = $links->resolve( array( 'kind' => 'path', 'path' => '/cf-never-exists/' ), array( 'verify_targets' => true ) );
		$planned = $links->resolve( array( 'kind' => 'path', 'path' => '/cf-planned/' ), array( 'verify_targets' => true, 'batch_paths' => array( 'planned' => '/cf-planned/' ) ) );
		cf_assert( is_wp_error( $missing ) && 'missing_path_link' === $missing->get_error_code(), 'Missing internal path was accepted.' );
		cf_assert( str_contains( $missing->get_error_message(), '/cf-never-exists/' ), 'Missing-path error omitted the unresolved URL.' );
		cf_assert( is_array( $planned ) && '/cf-planned/' === $planned['url'], 'Planned batch path was rejected.' );
	}
);

$runner->test(
	'link resolver accepts only explicitly declared virtual paths',
	static function () use ( $links ): void {
		$link = array( 'kind' => 'path', 'path' => '/forma-obratnoj-svyaz/' );
		$missing = $links->resolve( $link, array( 'verify_targets' => true ) );
		$virtual = $links->resolve( $link, array( 'verify_targets' => true, 'virtual_paths' => array( '/forma-obratnoj-svyaz/' ) ) );
		cf_assert( is_wp_error( $missing ) && 'missing_path_link' === $missing->get_error_code(), 'Undeclared virtual path was accepted.' );
		cf_assert( is_array( $virtual ) && '/forma-obratnoj-svyaz/' === $virtual['url'], 'Declared virtual path was rejected.' );
	}
);

$runner->test(
	'REST handlers reject malformed JSON envelopes',
	static function () use ( $adapter, $hierarchy, $serializer, $json, $zip ): void {
		$registry = new ContentFactory\Adapter\AdapterRegistry();
		$registry->register( $adapter );
		$pipeline = new ContentFactory\Service\ContentPipeline( $registry, new ContentFactory\Validation\CorePageSpecValidator(), $hierarchy, $serializer );
		$drafts = new ContentFactory\WordPress\DraftManager( $pipeline, $registry, new ContentFactory\WordPress\HashManager(), new ContentFactory\WordPress\YoastAdapter() );
		$batch = new ContentFactory\Import\BatchRunner( $hierarchy, $pipeline, $drafts );
		$controller = new ContentFactory\Rest\RestController( $registry, $pipeline, $drafts, $batch, new ContentFactory\WordPress\PublishManager( $pipeline, $registry, new ContentFactory\WordPress\HashManager() ), $json, $zip );
		$scalar = new WP_REST_Request( 'POST', '/' );
		$scalar->set_header( 'content-type', 'application/json' );
		$scalar->set_body( '1' );
		cf_assert( is_wp_error( $controller->create( $scalar ) ), 'Scalar create body did not return WP_Error.' );
		$items = new WP_REST_Request( 'POST', '/' );
		$items->set_header( 'content-type', 'application/json' );
		$items->set_body( '{"pages":[1],"confirmed":true}' );
		$result = $controller->create_batch( $items );
		cf_assert( is_wp_error( $result ) && 422 === ( $result->get_error_data()['status'] ?? 0 ), 'Scalar batch item did not return structured 422.' );
		$large = new WP_REST_Request( 'POST', '/' );
		$large->set_header( 'content-type', 'application/json' );
		$large->set_body( wp_json_encode( array( 'pages' => array_fill( 0, 101, cf_core_spec() ), 'confirmed' => false ) ) );
		$unlimited = $controller->create_batch( $large );
		cf_assert( ! is_wp_error( $unlimited ) || 'batch_page_limit' !== $unlimited->get_error_code(), 'REST still limits the PageSpec count.' );
		cf_with_zip(
			array( 'page.json' => wp_json_encode( cf_core_spec() ) ),
			static function ( string $path ) use ( $controller ): void {
				$upload = new WP_REST_Request( 'POST', '/' );
				$upload->set_file_params(
					array(
						'file' => array(
							'name' => 'batch.zip',
							'type' => 'application/zip',
							'tmp_name' => $path,
							'error' => 0,
							'size' => filesize( $path ),
						),
					)
				);
				$unconfirmed = $controller->create_batch( $upload );
				cf_assert( is_wp_error( $unconfirmed ) && 'confirmation_required' === $unconfirmed->get_error_code(), 'ZIP batch bypassed confirmation.' );
				$upload->set_param( 'confirmed', 'true' );
				$upload->set_param( 'validatedHash', 'sha256:' . str_repeat( '0', 64 ) );
				$changed = $controller->create_batch( $upload );
				cf_assert( is_wp_error( $changed ) && 'package_changed' === $changed->get_error_code(), 'Import accepted a file that differed from the validated package hash.' );
				$upload->set_param( 'validatedHash', '' );
				$upload->set_param( 'confirmed', 'true' );
				$confirmed = $controller->create_batch( $upload );
				cf_assert( is_array( $confirmed ) && 1 === ( $confirmed['counts']['failed'] ?? 0 ), 'Confirmed ZIP batch was not parsed and validated.' );
			}
		);
	}
);

$runner->test(
	'REST summary validates the 49-page ZIP without internal build data',
	static function () use ( $adapter, $hierarchy, $serializer, $json, $zip ): void {
		$registry = new ContentFactory\Adapter\AdapterRegistry();
		$registry->register( $adapter );
		$pipeline = new ContentFactory\Service\ContentPipeline( $registry, new ContentFactory\Validation\CorePageSpecValidator(), $hierarchy, $serializer );
		$drafts = new ContentFactory\WordPress\DraftManager( $pipeline, $registry, new ContentFactory\WordPress\HashManager(), new ContentFactory\WordPress\YoastAdapter() );
		$batch = new ContentFactory\Import\BatchRunner( $hierarchy, $pipeline, $drafts );
		$controller = new ContentFactory\Rest\RestController( $registry, $pipeline, $drafts, $batch, new ContentFactory\WordPress\PublishManager( $pipeline, $registry, new ContentFactory\WordPress\HashManager() ), $json, $zip );
		$path = __DIR__ . '/fixtures/regression-corpus/pagespec.zip';
		$request = new WP_REST_Request( 'POST', '/' );
		$request->set_param( 'detail', 'summary' );
		$request->set_file_params(
			array(
				'file' => array(
					'name' => 'pagespec.zip',
					'type' => 'application/zip',
					'tmp_name' => $path,
					'error' => 0,
					'size' => filesize( $path ),
				),
			)
		);
		$response = $controller->validate( $request );
		cf_assert( $response instanceof WP_REST_Response, 'Summary validation did not return WP_REST_Response.' );
		$data = $response->get_data();
		cf_assert_same( 'summary', $data['detail'] ?? '', 'Validation detail mode' );
		cf_assert_same( 49, $data['counts']['total'] ?? 0, 'Summary corpus count' );
		cf_assert( is_string( $data['packageHash'] ?? null ) && str_starts_with( $data['packageHash'], 'sha256:' ), 'Summary package hash is missing.' );
		cf_assert( strlen( wp_json_encode( $data ) ) < 200000, 'Summary response exceeds the 200 kB regression limit.' );
		foreach ( $data['results'] as $row ) {
			cf_assert( ! array_key_exists( 'postContent', $row ), 'Summary leaked postContent.' );
			cf_assert( ! array_key_exists( 'plannedBlockTree', $row ), 'Summary leaked plannedBlockTree.' );
			cf_assert( ! array_key_exists( 'normalizedSpec', $row ), 'Summary leaked normalizedSpec.' );
			cf_assert( in_array( $row['plannedAction'] ?? '', array( 'create', 'update_draft', 'no_change', 'blocked_published', 'conflict' ), true ), 'Summary has an unknown planned action.' );
			cf_assert( '' !== ( $row['profileId'] ?? '' ) && '' !== ( $row['manifestHash'] ?? '' ), 'Summary profile identity is incomplete.' );
		}
	}
);

$runner->test(
	'operation logger accepts prefixed manifest hashes',
	static function (): void {
		global $wpdb;
		$logger = new ContentFactory\Log\OperationLogger();
		$id = $logger->start( 'test_manifest_hash', array( 'manifest_hash' => 'sha256:' . str_repeat( 'a', 64 ) ) );
		cf_assert( is_string( $id ), 'Could not start operation log.' );
		try {
			$row = $logger->get( $id );
			cf_assert_same( str_repeat( 'a', 64 ), $row['manifest_hash'] ?? '', 'Stored manifest hash' );
		} finally {
			$wpdb->delete( $wpdb->prefix . 'content_factory_operations', array( 'operation_id' => $id ), array( '%s' ) );
		}
	}
);

$runner->test(
	'JSON importer accepts objects and rejects invalid inputs',
	static function () use ( $json ): void {
		cf_assert_same( array( 'ok' => true ), $json->decode( '{"ok":true}', 'valid.json' ), 'Valid JSON decode' );
		cf_assert( is_wp_error( $json->decode( '{', 'invalid.json' ) ), 'Broken JSON was accepted.' );
		cf_assert( is_wp_error( $json->decode( '"scalar"', 'scalar.json' ) ), 'Scalar JSON top level was accepted.' );
		cf_assert( is_wp_error( $json->decode( "{\"bad\":\"\xFF\"}", 'utf8.json' ) ), 'Invalid UTF-8 was accepted.' );
		$large = str_repeat( ' ', ContentFactory\Import\JsonImporter::MAX_FILE_SIZE + 1 );
		$result = $json->decode( $large, 'large.json' );
		cf_assert( is_wp_error( $result ) && 'content_factory_json_file_too_large' === $result->get_error_code(), 'Oversized JSON was accepted.' );
	}
);

$runner->test(
	'ZIP importer reads JSON without extraction',
	static function () use ( $zip ): void {
		cf_with_zip(
			array( 'spec.json' => '{"sourceId":"zip-smoke"}' ),
			static function ( string $path ) use ( $zip ): void {
				$result = $zip->import_file( $path );
				cf_assert( is_array( $result ) && 1 === count( $result ), 'Safe ZIP was not imported.' );
				cf_assert_same( 'spec.json', $result[0]['filename'], 'ZIP filename' );
			}
		);
	}
);

$runner->test(
	'ZIP importer rejects traversal and non-JSON entries',
	static function () use ( $zip ): void {
		cf_with_zip(
			array( '../escape.json' => '{}' ),
			static function ( string $path ) use ( $zip ): void {
				$result = $zip->import_file( $path );
				cf_assert( is_wp_error( $result ) && 'content_factory_zip_unsafe_path' === $result->get_error_code(), 'ZIP traversal was accepted.' );
			}
		);
		cf_with_zip(
			array( 'script.php' => '<?php echo 1;' ),
			static function ( string $path ) use ( $zip ): void {
				$result = $zip->import_file( $path );
				cf_assert( is_wp_error( $result ) && 'content_factory_zip_non_json_entry' === $result->get_error_code(), 'Non-JSON ZIP entry was accepted.' );
			}
		);
	}
);

$runner->test(
	'conditional WordPress draft create and idempotency',
	static function () use ( $plugin_file, $adapter, $hierarchy, $serializer, &$golden_spec ): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! is_plugin_active( plugin_basename( $plugin_file ) ) ) {
			cf_skip( 'Content Factory is not active' );
		}
		if ( ! is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
			cf_skip( 'Yoast SEO is not active' );
		}
		cf_assert( null !== $adapter && is_array( $golden_spec ), 'Adapter fixture is unavailable.' );

		$admin = get_user_by( 'login', 'admin' );
		if ( ! $admin instanceof WP_User || ! user_can( $admin, 'content_factory_import_pages' ) ) {
			cf_skip( 'No import-capable admin user is available' );
		}

		$original_user = get_current_user_id();
		$source_id     = 'cf-test-' . strtolower( wp_generate_password( 10, false, false ) );
		$slug          = str_replace( '_', '-', $source_id );
		$post_ids      = array();
		try {
			wp_set_current_user( $admin->ID );
			$spec                 = $golden_spec;
			$spec['sourceId']     = $source_id;
			$spec['post']['slug'] = $slug;
			unset( $spec['post']['parent'] );
			cf_replace_path_links( $spec );
			$expected_cta_text = '';
			foreach ( $spec['sections'] as &$section ) {
				if ( 'cta' === ( $section['type'] ?? '' ) ) {
					$section['data']['text'] .= "\n\nRegression newline.";
					$expected_cta_text = $section['data']['text'];
					break;
				}
			}
			unset( $section );

			$registry = new ContentFactory\Adapter\AdapterRegistry();
			$registry->register( $adapter );
			$pipeline = new ContentFactory\Service\ContentPipeline(
				$registry,
				new ContentFactory\Validation\CorePageSpecValidator(),
				$hierarchy,
				$serializer
			);
			$drafts = new ContentFactory\WordPress\DraftManager(
				$pipeline,
				$registry,
				new ContentFactory\WordPress\HashManager(),
				new ContentFactory\WordPress\YoastAdapter(),
				new ContentFactory\Log\OperationLogger()
			);
			$preflight = $pipeline->process( $spec );
			cf_assert( ! $preflight->has_errors(), 'Planner preflight failed.' );
			cf_assert_same( 'create', $drafts->plan( $preflight->context()['normalizedSpec'] ?? $spec, $preflight->context() )['action'] ?? '', 'Planner action before initial import' );

			$created = $drafts->import( $spec );
			cf_assert( ! is_wp_error( $created ), 'Draft import failed: ' . ( is_wp_error( $created ) ? $created->get_error_message() : '' ) );
			$post_id    = (int) ( $created['postId'] ?? 0 );
			$post_ids[] = $post_id;
			cf_assert_same( 'created', $created['action'] ?? null, 'Initial import action' );
			cf_assert_same( 'draft', get_post_status( $post_id ), 'Imported post status' );
			cf_assert_same( $source_id, get_post_meta( $post_id, '_content_factory_source_id', true ), 'Stored sourceId' );
			cf_assert_same( $adapter->compiled_profile()->defaults_version(), get_post_meta( $post_id, '_content_factory_site_defaults_version', true ), 'Stored site defaults version' );
			$stored_spec = json_decode( (string) get_post_meta( $post_id, '_content_factory_source_spec', true ), true );
			cf_assert( is_array( $stored_spec ), 'Stored source PageSpec JSON was corrupted.' );
			$stored_cta = array_values( array_filter( $stored_spec['sections'], static fn( array $section ): bool => 'cta' === ( $section['type'] ?? '' ) ) )[0];
			cf_assert_same( $expected_cta_text, $stored_cta['data']['text'] ?? '', 'Stored PageSpec preserves newlines' );
			cf_assert_same( 'no_change', $drafts->plan( $created['report']->context()['normalizedSpec'] ?? $spec, $created['report']->context() )['action'] ?? '', 'Planner action after initial import' );

			$again = $drafts->import( $spec );
			cf_assert( ! is_wp_error( $again ), 'Repeat import failed.' );
			cf_assert_same( 'no_change', $again['action'] ?? null, 'Repeat import action' );
			cf_assert_same( $post_id, (int) ( $again['postId'] ?? 0 ), 'Repeat import post ID' );

			delete_post_meta( $post_id, '_content_factory_site_defaults_version' );
			$profile_drift = $pipeline->process_result( $spec );
			cf_assert_same( 'update_draft', $drafts->plan( $profile_drift->normalized_spec(), $profile_drift->report()->context(), $profile_drift->profile() )['action'] ?? '', 'Planner action for profile metadata drift' );
			update_post_meta( $post_id, '_content_factory_site_defaults_version', $adapter->compiled_profile()->defaults_version() );

			$guard = new ContentFactory\WordPress\PublishGuard();
			$blocked_data = $guard->guard_publish( array( 'post_status' => 'publish' ), array( 'ID' => $post_id ) );
			cf_assert_same( 'draft', $blocked_data['post_status'], 'Managed native publish guard' );
			$regular_data = $guard->guard_publish( array( 'post_status' => 'publish' ), array( 'ID' => 0 ) );
			cf_assert_same( 'publish', $regular_data['post_status'], 'Unmanaged publish remains untouched' );

			update_post_meta( $post_id, '_yoast_wpseo_title', 'tampered SEO' );
			$drift_report = $pipeline->process( $spec );
			cf_assert_same( 'update_draft', $drafts->plan( $drift_report->context()['normalizedSpec'] ?? $spec, $drift_report->context() )['action'] ?? '', 'Planner action for a drifted draft' );
			$repaired = $drafts->import( $spec );
			cf_assert( ! is_wp_error( $repaired ), 'Drift repair import failed.' );
			cf_assert_same( 'updated', $repaired['action'] ?? null, 'Drifted draft must be updated instead of no_change' );
			cf_assert_same( $spec['seo']['title'], get_post_meta( $post_id, '_yoast_wpseo_title', true ), 'Yoast drift repair' );

			$changed_spec = $spec;
			$changed_spec['post']['title'] = 'Title that must be rolled back';
			$throw_update = true;
			$update_hook = static function ( int $saved_id, WP_Post $saved_post ) use ( $post_id, &$throw_update ): void {
				if ( $throw_update && $saved_id === $post_id && 'Title that must be rolled back' === $saved_post->post_title ) {
					$throw_update = false;
					throw new RuntimeException( 'Synthetic post-update hook failure' );
				}
			};
			add_action( 'save_post_page', $update_hook, 99, 2 );
			try {
				$interrupted = $drafts->import( $changed_spec );
			} finally {
				remove_action( 'save_post_page', $update_hook, 99 );
			}
			cf_assert( is_wp_error( $interrupted ) && true === ( $interrupted->get_error_data()['rollback'] ?? false ), 'Interrupted draft update did not report successful rollback.' );
			cf_assert_same( $spec['post']['title'], get_post( $post_id )->post_title, 'Interrupted draft title rollback' );
			cf_assert_same( 'template-full-width.php', get_page_template_slug( $post_id ), 'Interrupted draft template rollback' );

			$publisher = new ContentFactory\WordPress\PublishManager( $pipeline, $registry, new ContentFactory\WordPress\HashManager() );
			$throw_publish = true;
			$publish_hook = static function ( int $saved_id, WP_Post $saved_post ) use ( $post_id, &$throw_publish ): void {
				if ( $throw_publish && $saved_id === $post_id && 'publish' === $saved_post->post_status ) {
					$throw_publish = false;
					throw new RuntimeException( 'Synthetic publish hook failure' );
				}
			};
			add_action( 'save_post_page', $publish_hook, 99, 2 );
			try {
				$interrupted_publish = $publisher->publish_selected( array( $source_id ), true );
			} finally {
				remove_action( 'save_post_page', $publish_hook, 99 );
			}
			cf_assert( is_array( $interrupted_publish ) && 'error' === ( $interrupted_publish[0]['status'] ?? '' ) && true === ( $interrupted_publish[0]['rollback'] ?? false ), 'Interrupted publish did not roll back to draft.' );
			cf_assert_same( 'draft', get_post_status( $post_id ), 'Interrupted publish status rollback' );
			$rewrite_once = true;
			$slug_filter = static function ( array $data, array $postarr ) use ( $post_id, &$rewrite_once ): array {
				if ( $rewrite_once && (int) ( $postarr['ID'] ?? 0 ) === $post_id && 'publish' === ( $data['post_status'] ?? '' ) ) {
					$rewrite_once = false;
					$data['post_name'] = 'synthetic-rewritten-slug';
				}
				return $data;
			};
			add_filter( 'wp_insert_post_data', $slug_filter, 99, 2 );
			try {
				$rewritten_publish = $publisher->publish_selected( array( $source_id ), true );
			} finally {
				remove_filter( 'wp_insert_post_data', $slug_filter, 99 );
			}
			cf_assert( is_array( $rewritten_publish ) && 'error' === ( $rewritten_publish[0]['status'] ?? '' ) && true === ( $rewritten_publish[0]['rollback'] ?? false ), 'Publish-time slug rewrite was not rolled back.' );
			cf_assert_same( 'draft', get_post_status( $post_id ), 'Rewritten publish status rollback' );
			cf_assert_same( $slug, get_post_field( 'post_name', $post_id ), 'Rewritten publish slug rollback' );
			$published = $publisher->publish_selected( array( $source_id ), true );
			cf_assert( is_array( $published ) && 'published' === ( $published[0]['status'] ?? '' ), 'Publish Manager did not publish the valid draft.' );
			cf_assert_same( 'publish', get_post_status( $post_id ), 'Published post status' );
			cf_assert_same( 'blocked_published', $drafts->plan( $created['report']->context()['normalizedSpec'] ?? $spec, $created['report']->context() )['action'] ?? '', 'Planner action for a published managed page' );
			$conflict = $drafts->import( $spec );
			cf_assert( is_wp_error( $conflict ) && 'published_conflict' === $conflict->get_error_code(), 'Published managed page was not protected from reimport.' );
		} finally {
			$found = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'any',
					'meta_key'       => '_content_factory_source_id',
					'meta_value'     => $source_id,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				)
			);
			foreach ( array_unique( array_merge( $post_ids, array_map( 'intval', $found ) ) ) as $post_id ) {
				if ( $post_id > 0 ) {
					wp_delete_post( $post_id, true );
				}
			}
			wp_set_current_user( $original_user );
		}
	}
);

$runner->finish();
