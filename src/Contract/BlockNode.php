<?php

namespace ContentFactory\Contract;

defined( 'ABSPATH' ) || exit;

final class BlockNode implements \JsonSerializable {
	/** @param BlockNode[] $inner_blocks */
	public function __construct(
		private string $block_name,
		private array $attrs = array(),
		private array $inner_blocks = array(),
		private string $inner_html = '',
		private string $prefix_html = '',
		private string $suffix_html = ''
	) {}

	public function name(): string {
		return $this->block_name;
	}

	public function attrs(): array {
		return $this->attrs;
	}

	/** @return BlockNode[] */
	public function children(): array {
		return $this->inner_blocks;
	}

	public function to_wp_block(): array {
		$children = array_map( static fn( self $child ): array => $child->to_wp_block(), $this->inner_blocks );
		if ( $children ) {
			$inner_content = array( $this->prefix_html );
			foreach ( $children as $_child ) {
				$inner_content[] = null;
			}
			$inner_content[] = $this->suffix_html;
		} elseif ( '' !== $this->inner_html ) {
			$inner_content = array( $this->inner_html );
		} else {
			$inner_content = array();
		}
		return array(
			'blockName'    => $this->block_name,
			'attrs'        => $this->attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => $children ? $this->prefix_html . $this->suffix_html : $this->inner_html,
			'innerContent' => $inner_content,
		);
	}

	public function jsonSerialize(): array {
		return array(
			'blockName'   => $this->block_name,
			'attrs'       => $this->attrs,
			'innerBlocks' => $this->inner_blocks,
		);
	}
}

