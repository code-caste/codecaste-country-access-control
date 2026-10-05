<?php
/**
 * Uninstall cleanup.
 *
 * @package GeoLockdown
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'geo_lockdown_settings' );

require_once dirname( __FILE__ ) . '/includes/class-geo-database.php';

GeoLockdown\Geo_Database::drop_tables();

global $wpdb;

$geo_lockdown_log_table = $wpdb->prefix . 'geo_lockdown_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Required: uninstall must drop tables directly; table name is prefix + constant string.
$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $geo_lockdown_log_table ) );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required: clear plugin transients on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_geo_lockdown_%',
		'_transient_timeout_geo_lockdown_%'
	)
);

wp_clear_scheduled_hook( 'geo_lockdown_cleanup_logs' );
