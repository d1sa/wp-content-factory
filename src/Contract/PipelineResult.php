<?php

namespace ContentFactory\Contract;

use ContentFactory\Profile\CompiledProfile;

defined( 'ABSPATH' ) || exit;

final class PipelineResult {
	public function __construct(
		private CompatibilityReport $report,
		private array $normalized_spec,
		private array $resolved = array(),
		private ?BuildPlan $build_plan = null,
		private array $defaults_applied = array(),
		private ?array $conflict = null,
		private ?CompiledProfile $profile = null
	) {}

	public function report(): CompatibilityReport { return $this->report; }
	public function normalized_spec(): array { return $this->normalized_spec; }
	public function resolved(): array { return $this->resolved; }
	public function build_plan(): ?BuildPlan { return $this->build_plan; }
	public function defaults_applied(): array { return $this->defaults_applied; }
	public function conflict(): ?array { return $this->conflict; }
	public function profile(): ?CompiledProfile { return $this->profile; }
}
