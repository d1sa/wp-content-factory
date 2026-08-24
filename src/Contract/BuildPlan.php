<?php

namespace ContentFactory\Contract;

defined( 'ABSPATH' ) || exit;

final class BuildPlan {
	/** @param BlockNode[] $block_tree */
	public function __construct( private array $block_tree, private string $post_content ) {}
	public function block_tree(): array { return $this->block_tree; }
	public function post_content(): string { return $this->post_content; }
}
