<?php

namespace ContentFactory\Build;

use ContentFactory\Contract\BlockNode;

defined( 'ABSPATH' ) || exit;

final class GutenbergSerializer {
	/** @param BlockNode[] $nodes */
	public function serialize( array $nodes ): string {
		return implode( "\n\n", array_map( static fn( BlockNode $node ): string => serialize_block( $node->to_wp_block() ), $nodes ) );
	}

	/** @param BlockNode[] $expected */
	public function round_trip( array $expected, string $content ): bool|\WP_Error {
		$parsed = parse_blocks( $content );
		$parsed = array_values( array_filter( $parsed, static fn( array $block ): bool => null !== $block['blockName'] ) );
		if ( count( $parsed ) !== count( $expected ) ) {
			return new \WP_Error( 'round_trip_root_count', 'Round-trip изменил число корневых блоков.' );
		}
		foreach ( $expected as $index => $node ) {
			$error = $this->compare_node( $node, $parsed[ $index ], '/' . $index );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}
		$again = serialize_blocks( $parsed );
		$again_parsed = array_values( array_filter( parse_blocks( $again ), static fn( array $block ): bool => null !== $block['blockName'] ) );
		if ( count( $again_parsed ) !== count( $parsed ) ) {
			return new \WP_Error( 'round_trip_reserialize', 'Повторная сериализация Gutenberg потеряла блоки.' );
		}
		return true;
	}

	private function compare_node( BlockNode $expected, array $actual, string $path ): bool|\WP_Error {
		if ( $expected->name() !== $actual['blockName'] ) {
			return new \WP_Error( 'round_trip_block_name', 'Не совпало имя блока в ' . $path );
		}
		foreach ( $expected->attrs() as $key => $value ) {
			if ( ! array_key_exists( $key, $actual['attrs'] ) || $actual['attrs'][ $key ] !== $value ) {
				return new \WP_Error( 'round_trip_attribute', 'Потерян attribute ' . $key . ' в ' . $path );
			}
		}
		$children = $expected->children();
		if ( count( $children ) !== count( $actual['innerBlocks'] ) ) {
			return new \WP_Error( 'round_trip_child_count', 'Не совпало число дочерних блоков в ' . $path );
		}
		foreach ( $children as $index => $child ) {
			$error = $this->compare_node( $child, $actual['innerBlocks'][ $index ], $path . '/innerBlocks/' . $index );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}
		return true;
	}
}

