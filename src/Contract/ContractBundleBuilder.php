<?php

namespace ContentFactory\Contract;

use ContentFactory\Profile\CompiledProfile;
use ContentFactory\Validation\PageSpecSchemaRegistry;
use ContentFactory\VersionRegistry;

defined( 'ABSPATH' ) || exit;

final class ContractBundleBuilder {
	private const REQUIRED_SOURCE_KEYS = array(
		'identity',
		'semanticProfileSchema',
		'pageTypes',
		'safeSiteDefaults',
		'assets',
		'policies',
		'examples',
		'conversionGuidance',
	);
	private const SECRET_KEYS = array(
		'password', 'passwd', 'cookie', 'nonce', 'applicationpassword', 'token',
		'secret', 'authorization', 'credential', 'privatekey', 'privatepath',
		'apikey', 'accesskey', 'authkey', 'accesstoken', 'refreshtoken', 'sessiontoken',
		'clientsecret', 'webhooksecret', 'bearertoken',
	);

	public function __construct( private PageSpecSchemaRegistry $schemas ) {}

	/**
	 * @param array{examples?:array,conversionGuidance?:mixed} $supplement
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build( CompiledProfile $profile, CompatibilityReport $self_check, array $supplement = array() ): array|\WP_Error {
		$source = $this->compiled_profile_source( $profile, $supplement );
		$missing = array_values( array_diff( self::REQUIRED_SOURCE_KEYS, array_keys( $source ) ) );
		if ( $missing ) {
			return new \WP_Error( 'contract_bundle_source_incomplete', 'Источник Contract Bundle неполон.', array( 'status' => 500, 'missing' => $missing ) );
		}
		$identity = $source['identity'];
		if ( ! is_array( $identity ) || array_is_list( $identity ) ) {
			return new \WP_Error( 'contract_bundle_identity_invalid', 'Identity Contract Bundle должна быть объектом.', array( 'status' => 500 ) );
		}
		foreach ( array( 'siteKey', 'profileId', 'profileVersion', 'siteDefaultsVersion', 'manifestHash' ) as $field ) {
			if ( ! is_string( $identity[ $field ] ?? null ) || '' === trim( $identity[ $field ] ) ) {
				return new \WP_Error( 'contract_bundle_identity_invalid', 'Identity Contract Bundle неполна.', array( 'status' => 500, 'field' => $field ) );
			}
		}

		$page_spec_schema = $this->schemas->get( $this->schemas->current_version() );
		if ( is_wp_error( $page_spec_schema ) ) {
			return $page_spec_schema;
		}
		$self_check_summary = $this->self_check_summary( $self_check );
		$bundle = array(
			'contractVersion'       => VersionRegistry::CONTRACT_BUNDLE,
			'pageSpecVersion'       => $this->schemas->current_version(),
			'identity'              => $identity,
			'pageSpecSchema'        => $page_spec_schema,
			'semanticProfileSchema' => $source['semanticProfileSchema'],
			'pageTypes'             => $source['pageTypes'],
			'siteDefaults'          => $source['safeSiteDefaults'],
			'assets'                => $this->public_assets( $source['assets'] ),
			'policies'              => $source['policies'],
			'examples'              => $source['examples'],
			'conversionGuidance'    => $this->conversion_guidance( $source['conversionGuidance'] ),
			'selfCheck'             => $self_check_summary,
		);
		$unsafe = $this->unsafe_path( $bundle );
		if ( null !== $unsafe ) {
			return new \WP_Error( 'contract_bundle_unsafe', 'Contract Bundle содержит закрытые данные или private path.', array( 'status' => 500, 'path' => $unsafe ) );
		}
		$bundle['contractHash'] = $this->hash( $bundle );
		return $bundle;
	}

	/** @param array<string,mixed> $bundle */
	public function etag( array $bundle ): string {
		$hash = is_string( $bundle['contractHash'] ?? null ) ? $bundle['contractHash'] : $this->hash( $bundle );
		return '"' . $hash . '"';
	}

	/** @param array<string,mixed> $value */
	public function hash( array $value ): string {
		unset( $value['contractHash'] );
		$json = wp_json_encode( $this->canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return 'sha256:' . hash( 'sha256', (string) $json );
	}

	private function self_check_summary( CompatibilityReport $self_check ): array {
		$data = $self_check->jsonSerialize();
		$issues = array();
		foreach ( is_array( $data['issues'] ?? null ) ? $data['issues'] : array() as $issue ) {
			$serialized = $issue instanceof \JsonSerializable ? $issue->jsonSerialize() : $issue;
			if ( is_array( $serialized ) ) {
				$issues[] = array_intersect_key( $serialized, array_flip( array( 'code', 'severity', 'path', 'message', 'expected', 'suggestion', 'docsRef' ) ) );
			}
		}
		return array( 'status' => (string) ( $data['status'] ?? 'incompatible' ), 'issues' => $issues );
	}

	private function conversion_guidance( mixed $guidance ): array {
		if ( is_string( $guidance ) ) {
			$guidance = array( $guidance );
		}
		if ( ! is_array( $guidance ) || ! array_is_list( $guidance ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$guidance,
				static fn( mixed $item ): bool => is_string( $item ) && '' !== trim( $item )
			)
		);
	}

	private function compiled_profile_source( CompiledProfile $profile, array $supplement ): array {
		$contract = $profile->contract();
		$semantic_sections = array();
		foreach ( $profile->sections() as $type => $definition ) {
			if ( is_string( $type ) && is_array( $definition['schema'] ?? null ) ) {
				$semantic_sections[ $type ] = $definition['schema'];
			}
		}
		return array(
			'identity' => array(
				'siteKey'            => $profile->site_key(),
				'profileId'           => $profile->id(),
				'profileVersion'      => $profile->version(),
				'siteDefaultsVersion' => $profile->defaults_version(),
				'manifestHash'        => $profile->canonical_hash(),
			),
			'semanticProfileSchema' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => $semantic_sections,
			),
			'pageTypes'          => $profile->page_types(),
			'safeSiteDefaults'   => $profile->site_defaults(),
			'assets'             => $profile->assets(),
			'policies'           => is_array( $contract['policies'] ?? null ) ? $contract['policies'] : array(),
			'examples'           => is_array( $supplement['examples'] ?? null ) ? $supplement['examples'] : array(),
			'conversionGuidance' => $supplement['conversionGuidance'] ?? array( 'Use pageTypes and semanticProfileSchema from this Contract Bundle.' ),
		);
	}

	private function public_assets( mixed $assets ): array|object {
		if ( is_object( $assets ) ) {
			$assets = get_object_vars( $assets );
		}
		if ( ! is_array( $assets ) || array_is_list( $assets ) ) {
			return (object) array();
		}
		$public = array();
		foreach ( $assets as $ref => $asset ) {
			if ( ! is_string( $ref ) || ! is_array( $asset ) || array_is_list( $asset ) ) {
				continue;
			}
			$row = array_intersect_key( $asset, array_flip( array( 'path', 'label', 'mimeType', 'width', 'height' ) ) );
			if ( isset( $row['path'] ) ) {
				$scheme = is_string( $row['path'] ) ? wp_parse_url( $row['path'], PHP_URL_SCHEME ) : null;
				if ( ! is_string( $row['path'] ) || str_starts_with( $row['path'], '/' ) || str_contains( $row['path'], '..' ) || ( is_string( $scheme ) && '' !== $scheme ) ) {
					continue;
				}
			}
			$public[ $ref ] = $row;
		}
		return $public ?: (object) array();
	}

	private function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->canonicalize( $child );
		}
		return $value;
	}

	private function unsafe_path( mixed $value, string $path = '' ): ?string {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$key_text = is_string( $key ) ? strtolower( preg_replace( '/[^a-z0-9]/i', '', $key ) ) : (string) $key;
				$child_path = $path . '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), (string) $key );
				if ( $this->unsafe_key( $key_text ) ) {
					return $child_path;
				}
				$unsafe = $this->unsafe_path( $child, $child_path );
				if ( null !== $unsafe ) {
					return $unsafe;
				}
			}
			return null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( preg_match( '#(?:^|["\s])/(?:Users|home|private|var/www|srv)/#i', $value ) ) {
			return $path;
		}
		$parts = wp_parse_url( $value );
		if ( is_array( $parts ) && ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) ) {
			return $path;
		}
		return null;
	}

	private function unsafe_key( string $key ): bool {
		return in_array( $key, self::SECRET_KEYS, true )
			|| str_contains( $key, 'password' )
			|| str_contains( $key, 'secret' )
			|| str_contains( $key, 'credential' )
			|| str_ends_with( $key, 'token' )
			|| str_ends_with( $key, 'apikey' )
			|| str_ends_with( $key, 'privatekey' );
	}
}
