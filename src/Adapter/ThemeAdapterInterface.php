<?php

namespace ContentFactory\Adapter;

use ContentFactory\Contract\BlockNode;
use ContentFactory\Contract\CompatibilityReport;
use ContentFactory\Profile\CompiledProfile;

defined( 'ABSPATH' ) || exit;

interface ThemeAdapterInterface {
	public function id(): string;
	public function version(): string;
	public function supports_current_theme(): bool;
	public function compiled_profile(): CompiledProfile;
	public function self_check(): CompatibilityReport;
	public function validate( array $spec, array $context = array() ): CompatibilityReport;
	/** @return BlockNode[] */
	public function build( array $spec, array $context = array() ): array;
}
