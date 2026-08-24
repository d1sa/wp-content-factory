<?php

namespace ContentFactory\Contract;

defined( 'ABSPATH' ) || exit;

final class BlockRegistrySnapshot {
	/**
	 * @param string[] $block_names Empty means all registered blocks.
	 * @return array<string,array<string,mixed>>
	 */
	public function capture( array $block_names = array() ): array {
		if ( ! class_exists( '\\WP_Block_Type_Registry' ) ) {
			return array();
		}
		$registered = \WP_Block_Type_Registry::get_instance()->get_all_registered();
		if ( $block_names ) {
			$registered = array_intersect_key( $registered, array_flip( array_values( array_unique( $block_names ) ) ) );
		}
		$snapshot = array();
		foreach ( $registered as $name => $type ) {
			$attributes = is_array( $type->attributes ?? null ) ? $type->attributes : array();
			$parent = is_array( $type->parent ?? null ) ? array_values( $type->parent ) : array();
			$allowed = is_array( $type->allowed_blocks ?? null ) ? array_values( $type->allowed_blocks ) : array();
			$snapshot[ $name ] = array(
				'attributes'    => $attributes,
				'parent'        => $parent,
				'allowedBlocks' => $allowed,
			);
		}
		ksort( $snapshot, SORT_STRING );
		return $snapshot;
	}
}
