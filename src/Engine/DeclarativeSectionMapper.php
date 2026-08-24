<?php

namespace ContentFactory\Engine;

use ContentFactory\Contract\BlockNode;

defined( 'ABSPATH' ) || exit;

final class DeclarativeSectionMapper implements SectionMapperInterface {
	public function __construct( private array $binding, private TransformRegistry $transforms ) {}

	public function map( array $section, array $context = array() ): BlockNode {
		$scope = array( 'section' => $section, 'data' => $section['data'] ?? array(), 'spec' => $context['spec'] ?? array() );
		$attributes = $this->attributes( $this->binding['attributes'] ?? array(), $scope, $context );
		$children   = array();
		$repeat     = $this->binding['repeat'] ?? null;
		if ( is_array( $repeat ) ) {
			$items = $this->transforms->apply( array( 'transform' => 'repeat', 'source' => (string) ( $repeat['source'] ?? '' ) ), $scope, $context );
			foreach ( is_array( $items ) ? $items : array() as $index => $item ) {
				$child_scope = $scope + array( 'item' => $item, 'index' => $index );
				$child_scope['item']  = $item;
				$child_scope['index'] = $index;
				$children[] = new BlockNode(
					(string) $repeat['blockName'],
					$this->attributes( $repeat['attributes'] ?? array(), $child_scope, $context )
				);
			}
		}
		return new BlockNode( (string) $this->binding['blockName'], $attributes, $children );
	}

	private function attributes( array $definitions, array $scope, array $context ): array {
		$output = array();
		foreach ( $definitions as $attribute => $expression ) {
			if ( ! is_array( $expression ) ) {
				throw new \InvalidArgumentException( 'Binding attribute должен быть transform descriptor: ' . $attribute );
			}
			$output[ $attribute ] = $this->transforms->apply( $expression, $scope, $context );
		}
		return $output;
	}
}
