<?php
/**
 * Block event logger.
 *
 * @package GeoLockdown
 */

namespace GeoLockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional logging for blocked requests.
 */
class Logger {

	/**
	 * Table name without prefix.
	 *
	 * @var string
	 */
	const TABLE = 'geo_lockdown_logs';

	/**
	 * Create logs table.
	 */
	public static function create_table() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			country_code varchar(2) NOT NULL DEFAULT '',
			decision varchar(20) NOT NULL DEFAULT '',
			request_uri text NOT NULL,
			user_agent text NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY ip (ip)
		) {$charset};";

		dbDelta( $sql );

		if ( ! wp_next_scheduled( 'geo_lockdown_cleanup_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'geo_lockdown_cleanup_logs' );
		}
	}

	/**
	 * Get full table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Insert a log row.
	 *
	 * @param array<string, string> $entry Log data.
	 */
	public static function log( array $entry ) {
		global $wpdb;

		$settings = Plugin::get_settings();
		if ( empty( $settings['logging_enabled'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required: logs are written to a custom table.
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'   => current_time( 'mysql', true ),
				'ip'           => isset( $entry['ip'] ) ? sanitize_text_field( $entry['ip'] ) : '',
				'country_code' => isset( $entry['country_code'] ) ? strtoupper( sanitize_text_field( $entry['country_code'] ) ) : '',
				'decision'     => isset( $entry['decision'] ) ? sanitize_key( $entry['decision'] ) : 'blocked',
				'request_uri'  => isset( $entry['request_uri'] ) ? sanitize_text_field( $entry['request_uri'] ) : '',
				'user_agent'   => isset( $entry['user_agent'] ) ? sanitize_text_field( $entry['user_agent'] ) : '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Delete logs older than retention setting.
	 */
	public static function cleanup_old_logs() {
		global $wpdb;

		$settings = Plugin::get_settings();
		$days     = max( 1, (int) $settings['log_retention_days'] );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required: deleting old log rows from a custom table.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s',
				$cutoff
			)
		);
	}

	/**
	 * Clear all logs.
	 */
	public static function clear_logs() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Required: TRUNCATE on a custom table.
		$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
	}

	/**
	 * Fetch recent logs.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public static function get_recent( $limit = 50 ) {
		global $wpdb;

		$limit = max( 1, min( 200, (int) $limit ) );
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom log table query.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	/**
	 * Count total log rows.
	 *
	 * @return int
	 */
	public static function count_logs() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Required: COUNT on a custom table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
