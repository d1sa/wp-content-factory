<?php

namespace ContentFactory\Adapter;

defined( 'ABSPATH' ) || exit;

final class AdapterRegistry {
	/** @var array<string,ThemeAdapterInterface> */
	private array $adapters = array();
	/** @var ThemeAdapterInterface[] */
	private array $registration_conflicts = array();

	public function register( ThemeAdapterInterface $adapter ): void {
		if ( isset( $this->adapters[ $adapter->id() ] ) ) {
			$this->registration_conflicts[] = $adapter;
			return;
		}
		$this->adapters[ $adapter->id() ] = $adapter;
	}

	public function get( string $id ): ?ThemeAdapterInterface {
		foreach ( $this->registration_conflicts as $adapter ) {
			if ( $adapter->id() === $id ) {
				return null;
			}
		}
		return $this->adapters[ $id ] ?? null;
	}

	/** @return ThemeAdapterInterface[] */
	public function compatible(): array {
		return array_values( array_filter( $this->all(), static fn( ThemeAdapterInterface $adapter ): bool => $adapter->supports_current_theme() ) );
	}

	public function all(): array {
		return array_merge( $this->adapters, $this->registration_conflicts );
	}
}
