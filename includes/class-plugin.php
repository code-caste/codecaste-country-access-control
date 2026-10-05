<?php
/**
 * Core plugin bootstrap.
 *
 * @package GeoLockdown
 */

namespace GeoLockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin orchestrator.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 1 );
	}

	/**
	 * Initialize components.
	 */
	public function init() {
		Geo_Database::create_tables();

		if ( is_admin() ) {
			Geo_Database::maybe_import_bundled_data();
			self::maybe_upgrade_starter_database();
		}

		add_action( 'geo_lockdown_cleanup_logs', array( 'GeoLockdown\\Logger', 'cleanup_old_logs' ) );

		Access_Controller::get_instance();

		if ( is_admin() ) {
			Admin\Admin::get_instance();
		}
	}

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		Geo_Database::create_tables();
		Logger::create_table();
		self::set_default_options();

		$meta = Geo_Database::get_meta();
		if ( empty( $meta['ipv4_count'] ) && empty( $meta['ipv6_count'] ) ) {
			Geo_Database::import_starter_database();
			update_option( 'geo_lockdown_starter_version', GEO_LOCKDOWN_STARTER_VERSION, false );
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'geo_lockdown_cleanup_logs' );
	}

	/**
	 * Set default options on first activation.
	 */
	private static function set_default_options() {
		$defaults = self::default_options();

		if ( false === get_option( 'geo_lockdown_settings' ) ) {
			add_option( 'geo_lockdown_settings', $defaults, '', 'no' );
		}
	}

	/**
	 * Default plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_options() {
		return array(
			'enabled'              => false,
			'mode'                 => 'allow',
			'countries'            => array(),
			'block_response'       => '403',
			'block_message'        => __( 'Access to this website is not available in your country.', 'geo-lockdown' ),
			'block_redirect_url'   => '',
			'logging_enabled'      => false,
			'log_retention_days'   => 30,
			'cache_ttl'            => 3600,
			'admin_bypass'         => true,
			'trust_cloudflare'     => true,
			'trusted_proxies'      => array(),
			'trusted_proxy_header' => 'HTTP_CF_CONNECTING_IP',
			'bypass_rest_api'      => true,
			'bypass_wp_cron'       => true,
			'bypass_xmlrpc'        => true,
			'bypass_ajax'          => true,
			'bypass_googlebot'     => true,
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$stored = get_option( 'geo_lockdown_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::default_options(), $stored );
	}

	/**
	 * Persist settings.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 */
	public static function save_settings( array $settings ) {
		update_option( 'geo_lockdown_settings', $settings, false );
	}

	/**
	 * Refresh bundled starter data when the starter dataset version changes.
	 */
	public static function maybe_upgrade_starter_database() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$installed = get_option( 'geo_lockdown_starter_version', '' );
		if ( \GEO_LOCKDOWN_STARTER_VERSION === $installed ) {
			return;
		}

		update_option( 'geo_lockdown_starter_version', \GEO_LOCKDOWN_STARTER_VERSION, false );

		$meta  = Geo_Database::get_meta();
		$total = (int) $meta['ipv4_count'] + (int) $meta['ipv6_count'];

		if ( 0 === $total ) {
			Geo_Database::import_starter_database();
		}
	}
}
