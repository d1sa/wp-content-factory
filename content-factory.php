<?php
/**
 * Plugin Name: Content Factory
 * Description: Validates semantic PageSpec JSON and creates reviewable Gutenberg page drafts.
 * Version: 2.1.2
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Potolki
 * Text Domain: content-factory
 */

defined( 'ABSPATH' ) || exit;

define( 'CONTENT_FACTORY_FILE', __FILE__ );
define( 'CONTENT_FACTORY_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONTENT_FACTORY_URL', plugin_dir_url( __FILE__ ) );

require_once CONTENT_FACTORY_DIR . 'src/VersionRegistry.php';

define( 'CONTENT_FACTORY_VERSION', ContentFactory\VersionRegistry::PLUGIN );

if ( ! function_exists( 'array_is_list' ) ) {
	function array_is_list( array $array ): bool { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
		$index = 0;
		foreach ( $array as $key => $_value ) {
			if ( $key !== $index++ ) {
				return false;
			}
		}
		return true;
	}
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'ContentFactory\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file     = CONTENT_FACTORY_DIR . 'src/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'ContentFactory\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ContentFactory\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		ContentFactory\Plugin::instance()->boot();
	}
);
