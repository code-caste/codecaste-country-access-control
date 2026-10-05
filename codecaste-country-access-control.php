<?php
/**
 * Plugin Name:       Country Access Control by CodeCaste
 * Plugin URI:        https://github.com/code-caste/codecaste-country-access-control
 * Description:       Restrict website access by visitor country using a local IP geolocation database. No external APIs required.
 * Version:           1.0.1
 * Author:            CodeCaste
 * Author URI:        https://www.codecaste.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       geo-lockdown
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 *
 * @package GeoLockdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GEO_LOCKDOWN_VERSION', '1.0.1' );
define( 'GEO_LOCKDOWN_STARTER_VERSION', '3' );
define( 'GEO_LOCKDOWN_PLUGIN_FILE', __FILE__ );
define( 'GEO_LOCKDOWN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GEO_LOCKDOWN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GEO_LOCKDOWN_STARTER_CSV', GEO_LOCKDOWN_PLUGIN_DIR . 'database/source/starter-country-ranges.csv' );

/**
 * PSR-4-style autoloader for GeoLockdown namespace.
 *
 * @param string $class_name Class to load.
 */
$geo_lockdown_autoloader = function ( $class_name ) {
	if ( strpos( $class_name, 'GeoLockdown\\' ) !== 0 ) {
		return;
	}

	$relative = substr( $class_name, strlen( 'GeoLockdown\\' ) );
	$parts    = explode( '\\', $relative );
	$file     = 'class-' . str_replace( '_', '-', strtolower( array_pop( $parts ) ) ) . '.php';

	$sub_path = 'includes/';
	if ( ! empty( $parts ) ) {
		$first = strtolower( $parts[0] );
		if ( 'admin' === $first ) {
			$sub_path = 'admin/';
			array_shift( $parts );
		}
		if ( ! empty( $parts ) ) {
			$sub_path .= implode( '/', array_map( 'strtolower', $parts ) ) . '/';
		}
	}

	$path = GEO_LOCKDOWN_PLUGIN_DIR . $sub_path . $file;
	if ( is_readable( $path ) ) {
		require_once $path;
	}
};

spl_autoload_register( $geo_lockdown_autoloader );

register_activation_hook( __FILE__, array( 'GeoLockdown\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GeoLockdown\\Plugin', 'deactivate' ) );

GeoLockdown\Plugin::get_instance();
