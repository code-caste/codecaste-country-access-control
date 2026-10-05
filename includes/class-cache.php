<?php
/**
 * IP to country lookup cache.
 *
 * @package GeoLockdown
 */

namespace GeoLockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-layer cache for geolocation results.
 */
class Cache {

	/**
	 * In-request static cache.
	 *
	 * @var array<string, array<string, string|null>>
	 */
	private static $request_cache = array();

	/**
	 * Cache group name.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'geo_lockdown';

	/**
	 * Get cached country lookup result.
	 *
	 * @param string $ip IP address.
	 * @return array<string, string|null>|null Null when cache miss.
	 */
	public static function get( $ip ) {
		if ( isset( self::$request_cache[ $ip ] ) ) {
			return self::$request_cache[ $ip ];
		}

		$key    = self::cache_key( $ip );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached && is_array( $cached ) ) {
			self::$request_cache[ $ip ] = $cached;
			return $cached;
		}

		$transient = get_transient( $key );
		if ( is_array( $transient ) ) {
			self::$request_cache[ $ip ] = $transient;
			wp_cache_set( $key, $transient, self::CACHE_GROUP );
			return $transient;
		}

		return null;
	}

	/**
	 * Store country lookup result.
	 *
	 * @param string               $ip     IP address.
	 * @param array<string, string|null> $result Lookup result.
	 */
	public static function set( $ip, array $result ) {
		self::$request_cache[ $ip ] = $result;

		$settings = Plugin::get_settings();
		$ttl      = max( 60, (int) $settings['cache_ttl'] );
		$key      = self::cache_key( $ip );

		wp_cache_set( $key, $result, self::CACHE_GROUP, $ttl );
		set_transient( $key, $result, $ttl );
	}

	/**
	 * Flush all cached lookups.
	 */
	public static function flush() {
		self::$request_cache = array();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required: no WP API exists to flush transients by prefix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_geo_lockdown_%',
				'_transient_timeout_geo_lockdown_%'
			)
		);

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}
	}

	/**
	 * Build a cache key for an IP.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	private static function cache_key( $ip ) {
		return 'geo_lockdown_' . md5( $ip );
	}
}
