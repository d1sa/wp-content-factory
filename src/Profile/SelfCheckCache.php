<?php

namespace ContentFactory\Profile;

use ContentFactory\Adapter\ThemeAdapterInterface;
use ContentFactory\Contract\CompatibilityReport;

defined( 'ABSPATH' ) || exit;

final class SelfCheckCache {
	/** @var array<int,CompatibilityReport> */
	private array $reports = array();

	public function get( ThemeAdapterInterface $adapter ): CompatibilityReport {
		$key = spl_object_id( $adapter );
		return $this->reports[ $key ] ??= $adapter->self_check();
	}
}
