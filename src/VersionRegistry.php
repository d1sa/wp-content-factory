<?php

namespace ContentFactory;

defined( 'ABSPATH' ) || exit;

/** Central registry for plugin-owned protocol and storage versions. */
final class VersionRegistry {
	public const PLUGIN              = '2.1.2';
	public const REST_NAMESPACE       = 'content-factory/v1';
	public const CONTRACT_BUNDLE      = '1.0';
	public const PAGE_SPEC            = '1.1';
	public const THEME_PROFILE_SCHEMA = '1.0';
	public const OPERATION_LOG_DB      = '1.0.0';

	private function __construct() {}
}
