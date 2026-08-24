<?php

declare( strict_types=1 );

const CF_SNAPSHOT_WP_LOAD = '/var/www/html/wp-load.php';

if ( PHP_SAPI !== 'cli' || ! in_array( '--update', $argv, true ) ) {
	fwrite( STDERR, "Snapshots are immutable during normal tests. Use: php tests/update-snapshots.php --update\n" );
	exit( 2 );
}
if ( ! is_readable( CF_SNAPSHOT_WP_LOAD ) ) {
	fwrite( STDERR, 'WordPress not found at ' . CF_SNAPSHOT_WP_LOAD . PHP_EOL );
	exit( 2 );
}

define( 'WP_USE_THEMES', false );
require_once CF_SNAPSHOT_WP_LOAD;
require_once __DIR__ . '/SnapshotArtifacts.php';

$adapter    = new ContentFactory\Adapter\PotolkiInnerAdapter();
$serializer = new ContentFactory\Build\GutenbergSerializer();
$expected_dir = __DIR__ . '/fixtures/expected';
if ( ! is_dir( $expected_dir ) && ! mkdir( $expected_dir, 0775, true ) && ! is_dir( $expected_dir ) ) {
	throw new RuntimeException( 'Could not create expected fixture directory.' );
}

$baseline = array(
	'profileId'      => $adapter->id(),
	'profileVersion' => $adapter->version(),
	'manifestHash'   => $adapter->manifest_hash(),
	'selfCheck'      => $adapter->self_check()->jsonSerialize(),
	'fixtures'       => array(),
);
foreach ( array( 'service-detail', 'service-category' ) as $fixture_name ) {
	$fixture_path = __DIR__ . '/fixtures/golden/' . $fixture_name . '.json';
	$spec = json_decode( (string) file_get_contents( $fixture_path ), true, 64, JSON_THROW_ON_ERROR );
	$output = CF_Snapshot_Artifacts::output( $adapter, $serializer, $spec, CF_Snapshot_Artifacts::context( $adapter, $spec ) );
	file_put_contents(
		$expected_dir . '/' . $fixture_name . '.blocks.json',
		wp_json_encode( $output['blocks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL
	);
	file_put_contents( $expected_dir . '/' . $fixture_name . '.post-content.html', $output['postContent'] . PHP_EOL );
	$validation = $adapter->validate( $spec, CF_Snapshot_Artifacts::context( $adapter, $spec ) );
	$baseline['fixtures'][ $fixture_name ] = array(
		'status'     => $validation->status(),
		'issues'     => array_map( static fn( $issue ): array => $issue->jsonSerialize(), $validation->issues() ),
		'blockCount' => count( $output['blocks'] ),
	);
}
file_put_contents(
	$expected_dir . '/baseline.json',
	wp_json_encode( $baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL
);

$corpus_file = __DIR__ . '/fixtures/regression-corpus/pagespec.zip';
if ( is_readable( $corpus_file ) ) {
	$entries = ( new ContentFactory\Import\ZipImporter( new ContentFactory\Import\JsonImporter() ) )->import_file( $corpus_file );
	if ( is_wp_error( $entries ) ) {
		throw new RuntimeException( $entries->get_error_message() );
	}
	$specs = array();
	foreach ( $entries as $entry ) {
		$data = $entry['data'];
		$pages = isset( $data['pages'] ) ? $data['pages'] : ( array_is_list( $data ) ? $data : array( $data ) );
		array_push( $specs, ...$pages );
	}
	$hashes = array(
		'corpusCount' => count( $specs ),
		'fixtureOrigin' => 'sanitized PageSpec corpus; fixture URLs are injected at test time',
		'pages' => CF_Snapshot_Artifacts::corpus_hashes( $adapter, $serializer, $specs ),
	);
	file_put_contents(
		__DIR__ . '/fixtures/regression-corpus/expected-hashes.json',
		wp_json_encode( $hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL
	);
}

echo "Snapshot artifacts updated explicitly.\n";
