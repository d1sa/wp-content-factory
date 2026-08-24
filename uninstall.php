<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$operations_table = $wpdb->prefix . 'content_factory_operations';
$pages_table      = $wpdb->prefix . 'content_factory_operation_pages';

// Table names are derived exclusively from WordPress' trusted table prefix.
$wpdb->query( "DROP TABLE IF EXISTS `{$pages_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$operations_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$option_patterns = array(
	$wpdb->esc_like( 'content_factory_' ) . '%',
	$wpdb->esc_like( '_transient_content_factory_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_content_factory_' ) . '%',
);

foreach ( $option_patterns as $pattern ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$pattern
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
}

wp_clear_scheduled_hook( 'content_factory_cleanup_logs' );
