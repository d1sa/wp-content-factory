<?php

namespace ContentFactory\Resolve;

defined( 'ABSPATH' ) || exit;

final class HierarchyResolver {
	public function resolve_parent( array $spec, array $batch_ids = array() ): int|\WP_Error {
		$parent = $spec['post']['parent'] ?? null;
		if ( ! $parent ) {
			return 0;
		}
		if ( isset( $parent['sourceId'] ) ) {
			if ( isset( $batch_ids[ $parent['sourceId'] ] ) ) {
				return (int) $batch_ids[ $parent['sourceId'] ];
			}
			$posts = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'draft', 'publish', 'pending', 'private' ),
					'meta_key'       => '_content_factory_source_id',
					'meta_value'     => $parent['sourceId'],
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			return $posts ? (int) $posts[0] : new \WP_Error( 'missing_parent', 'Родитель по sourceId не найден.' );
		}
		$path = trim( (string) ( $parent['path'] ?? '' ), '/' );
		$page = get_page_by_path( $path, OBJECT, 'page' );
		return $page ? (int) $page->ID : new \WP_Error( 'missing_parent', 'Родитель по path не найден.' );
	}

	/** @return array<int,array>\WP_Error */
	public function sort_batch( array $specs ): array|\WP_Error {
		$by_id = array();
		foreach ( $specs as $index => $spec ) {
			if ( ! is_array( $spec ) || array_is_list( $spec ) ) {
				return new \WP_Error( 'invalid_page_item', 'Каждый элемент batch должен быть объектом PageSpec.', array( 'status' => 422, 'index' => $index ) );
			}
			$id = $spec['sourceId'] ?? '';
			if ( ! is_string( $id ) || '' === $id ) {
				return new \WP_Error( 'invalid_source_id', 'Каждая batch-страница должна иметь строковый sourceId.', array( 'status' => 422, 'index' => $index ) );
			}
			if ( isset( $by_id[ $id ] ) ) {
				return new \WP_Error( 'duplicate_source_id', 'В batch повторяется sourceId: ' . $id );
			}
			$by_id[ $id ] = $spec;
		}
		$state = array();
		$out   = array();
		$visit = function ( string $id ) use ( &$visit, &$state, &$out, $by_id ): bool {
			if ( 1 === ( $state[ $id ] ?? 0 ) ) {
				return false;
			}
			if ( 2 === ( $state[ $id ] ?? 0 ) ) {
				return true;
			}
			$state[ $id ] = 1;
			$parent_id = $by_id[ $id ]['post']['parent']['sourceId'] ?? '';
			if ( $parent_id && isset( $by_id[ $parent_id ] ) && ! $visit( $parent_id ) ) {
				return false;
			}
			$state[ $id ] = 2;
			$out[] = $by_id[ $id ];
			return true;
		};
		foreach ( array_keys( $by_id ) as $id ) {
			if ( ! $visit( $id ) ) {
				return new \WP_Error( 'parent_cycle', 'Обнаружен цикл родительских зависимостей.' );
			}
		}
		return $out;
	}
}
