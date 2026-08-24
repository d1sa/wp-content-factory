<?php

namespace ContentFactory\Profile;

defined( 'ABSPATH' ) || exit;

/** Immutable request-local representation shared by validation and build. */
final class CompiledProfile {
	public function __construct(
		private array $configuration,
		private array $contract,
		private string $canonical_hash,
		private array $section_bindings = array()
	) {}

	public function id(): string { return (string) ( $this->configuration['identity']['profileId'] ?? '' ); }
	public function version(): string { return (string) ( $this->configuration['identity']['profileVersion'] ?? '' ); }
	public function site_key(): string { return (string) ( $this->configuration['identity']['siteKey'] ?? '' ); }
	public function defaults_version(): string { return (string) ( $this->configuration['siteDefaultsVersion'] ?? '' ); }
	public function canonical_hash(): string { return $this->canonical_hash; }
	public function configuration(): array { return $this->configuration; }
	public function contract(): array { return $this->contract; }
	public function page_types(): array { return $this->configuration['pageTypes'] ?? array(); }
	public function sections(): array { return $this->configuration['sections'] ?? array(); }
	public function site_defaults(): array { return $this->configuration['siteDefaults'] ?? array(); }
	public function assets(): array { return $this->configuration['assets'] ?? array(); }
	public function post_defaults(): array { return $this->configuration['postDefaults'] ?? array(); }
	public function compatibility(): array { return $this->configuration['compatibility'] ?? array(); }
	public function binding( string $section_type ): ?array {
		return isset( $this->section_bindings[ $section_type ] ) && is_array( $this->section_bindings[ $section_type ] )
			? $this->section_bindings[ $section_type ]
			: null;
	}
}
