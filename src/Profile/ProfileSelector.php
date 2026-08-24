<?php

namespace ContentFactory\Profile;

use ContentFactory\Adapter\AdapterRegistry;
use ContentFactory\Adapter\ThemeAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class ProfileSelector {
	public function __construct( private AdapterRegistry $registry ) {}

	public function select( array $spec = array() ): ThemeAdapterInterface|\WP_Error {
		$target = $spec['target'] ?? null;
		if ( ! is_array( $target ) ) {
			return new \WP_Error( 'target_profile_unavailable', 'PageSpec должен содержать точный target с profileId и siteKey.' );
		}
		$profile_id = is_string( $target['profileId'] ?? null ) ? $target['profileId'] : '';
		$site_key   = is_string( $target['siteKey'] ?? null ) ? $target['siteKey'] : '';
		if ( '' === $profile_id || '' === $site_key ) {
			return new \WP_Error( 'target_profile_unavailable', 'target должен содержать точные profileId и siteKey.' );
		}
		$matches = array_values(
			array_filter(
				$this->registry->all(),
				static fn( ThemeAdapterInterface $adapter ): bool => $adapter->id() === $profile_id
					&& $adapter->compiled_profile()->site_key() === $site_key
					&& $adapter->supports_current_theme()
			)
		);
		if ( 1 !== count( $matches ) ) {
			return new \WP_Error( 1 < count( $matches ) ? 'ambiguous_profile' : 'target_profile_unavailable', 1 < count( $matches ) ? 'Для target найдено несколько профилей.' : 'Точный target profile недоступен или несовместим с темой.' );
		}
		return $matches[0];
	}
}
