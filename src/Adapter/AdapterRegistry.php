<?php

namespace ContentFactory\Adapter;

defined( 'ABSPATH' ) || exit;

final class AdapterRegistry {
	/** @var array<string,ThemeAdapterInterface> */
	private array $adapters = array();

	public function register( ThemeAdapterInterface $adapter ): void {
		$this->adapters[ $adapter->id() ] = $adapter;
	}

	public function get( string $id ): ?ThemeAdapterInterface {
		return $this->adapters[ $id ] ?? null;
	}

	public function active(): ?ThemeAdapterInterface {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->supports_current_theme() ) {
				return $adapter;
			}
		}
		return null;
	}

	public function all(): array {
		return $this->adapters;
	}
}

