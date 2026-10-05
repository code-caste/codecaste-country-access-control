<?php
/**
 * Admin settings and tools.
 *
 * @package GeoLockdown
 */

namespace GeoLockdown\Admin;

use GeoLockdown\Access_Controller;
use GeoLockdown\Countries;
use GeoLockdown\Country_Checker;
use GeoLockdown\Geo_Database;
use GeoLockdown\IP_Detector;
use GeoLockdown\Logger;
use GeoLockdown\Plugin;
use GeoLockdown\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI controller.
 */
class Admin {

	/**
	 * Singleton instance.
	 *
	 * @var Admin|null
	 */
	private static $instance = null;

	/**
	 * Settings page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Get singleton.
	 *
	 * @return Admin
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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'requirement_notices' ) );
	}

	/**
	 * Show admin notices for missing requirements.
	 */
	public function requirement_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'settings_page_codecaste-country-access-control' !== $screen->id ) {
			return;
		}

		if ( ! Geo_Database::get_instance()->is_available() ) {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'Country Access Control has no GeoIP data loaded yet. Download the latest database below.', 'geo-lockdown' );
			echo '</p></div>';
			return;
		}

		$db_info = Geo_Database::get_instance()->get_info();
		if ( empty( $db_info['is_full'] ) ) {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'Country Access Control is using a limited starter IP database. Download the latest full database below for accurate VPN and country detection.', 'geo-lockdown' );
			echo '</p></div>';
		}
	}

	/**
	 * Register settings page.
	 */
	public function register_menu() {
		$this->page_hook = add_options_page(
			__( 'Country Access Control', 'geo-lockdown' ),
			__( 'Country Access Control', 'geo-lockdown' ),
			'manage_options',
			'geo-lockdown',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'codecaste-country-access-control-admin',
			\GEO_LOCKDOWN_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			\GEO_LOCKDOWN_VERSION
		);

		wp_enqueue_script(
			'codecaste-country-access-control-admin',
			\GEO_LOCKDOWN_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			\GEO_LOCKDOWN_VERSION,
			true
		);
	}

	/**
	 * Handle form submissions.
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['geo_lockdown_save_settings'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			check_admin_referer( 'geo_lockdown_save_settings', 'geo_lockdown_nonce' );
			$this->save_settings_from_post();
		}

		if ( isset( $_POST['geo_lockdown_clear_logs'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			check_admin_referer( 'geo_lockdown_clear_logs', 'geo_lockdown_logs_nonce' );
			Logger::clear_logs();
			add_settings_error( 'geo_lockdown', 'logs_cleared', __( 'Logs cleared.', 'geo-lockdown' ), 'updated' );
		}

		if ( isset( $_POST['geo_lockdown_flush_cache'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			check_admin_referer( 'geo_lockdown_flush_cache', 'geo_lockdown_cache_nonce' );
			Cache::flush();
			add_settings_error( 'geo_lockdown', 'cache_flushed', __( 'Lookup cache cleared.', 'geo-lockdown' ), 'updated' );
		}

		if ( isset( $_POST['geo_lockdown_download_latest'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			check_admin_referer( 'geo_lockdown_download_latest', 'geo_lockdown_download_nonce' );
			$result = Geo_Database::download_latest_database();
			if ( is_wp_error( $result ) ) {
				add_settings_error( 'geo_lockdown', 'download_failed', $result->get_error_message(), 'error' );
			} else {
				update_option( 'geo_lockdown_starter_version', \GEO_LOCKDOWN_STARTER_VERSION, false );
				add_settings_error( 'geo_lockdown', 'download_installed', __( 'The latest DB-IP Country Lite database was downloaded and installed successfully.', 'geo-lockdown' ), 'updated' );
			}
		}

	}

	/**
	 * Save settings from POST data.
	 */
	/**
	 * Save settings from POST data.
	 */
	private function save_settings_from_post() {
		$defaults = Plugin::default_options();
		$current  = Plugin::get_settings();

		// Nonce already verified in handle_actions() via check_admin_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$raw_countries = isset( $_POST['countries'] ) ? (array) wp_unslash( $_POST['countries'] ) : array();
		$countries     = array_map( 'sanitize_key', $raw_countries );

		$raw_trusted = isset( $_POST['trusted_proxies'] )
			? array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( (string) $_POST['trusted_proxies'] ) ) ) ) )
			: array();

		$valid_trusted   = array();
		$invalid_trusted = array();

		foreach ( $raw_trusted as $entry ) {
			if ( IP_Detector::is_valid_ip_or_cidr( $entry ) ) {
				$valid_trusted[] = $entry;
			} else {
				$invalid_trusted[] = $entry;
			}
		}

		if ( ! empty( $invalid_trusted ) ) {
			add_settings_error(
				'geo_lockdown',
				'invalid_proxies',
				sprintf(
					/* translators: %s: Comma-separated list of invalid IP/CIDR entries */
					__( 'The following trusted proxy IP/CIDR entries were invalid and ignored: %s', 'geo-lockdown' ),
					implode( ', ', array_map( 'esc_html', $invalid_trusted ) )
				),
				'warning'
			);
		}

		$block_response       = sanitize_key( wp_unslash( (string) ( $_POST['block_response'] ?? '403' ) ) );
		$trusted_proxy_header = sanitize_text_field( wp_unslash( (string) ( $_POST['trusted_proxy_header'] ?? $defaults['trusted_proxy_header'] ) ) );

		$settings = array(
			'enabled'              => ! empty( $_POST['enabled'] ),
			'mode'                 => ( isset( $_POST['mode'] ) && 'block' === sanitize_key( (string) $_POST['mode'] ) ) ? 'block' : 'allow',
			'countries'            => Countries::sanitize_codes( $countries ),
			'block_response'       => $this->sanitize_block_response( $block_response ),
			'block_message'        => isset( $_POST['block_message'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['block_message'] ) ) : $defaults['block_message'],
			'block_redirect_url'   => isset( $_POST['block_redirect_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['block_redirect_url'] ) ) : '',
			'logging_enabled'      => ! empty( $_POST['logging_enabled'] ),
			'log_retention_days'   => isset( $_POST['log_retention_days'] ) ? max( 1, (int) $_POST['log_retention_days'] ) : 30,
			'cache_ttl'            => isset( $_POST['cache_ttl'] ) ? max( 60, (int) $_POST['cache_ttl'] ) : 3600,
			'admin_bypass'         => ! empty( $_POST['admin_bypass'] ),
			'trust_cloudflare'     => ! empty( $_POST['trust_cloudflare'] ),
			'trusted_proxies'      => array_values( array_unique( $valid_trusted ) ),
			'trusted_proxy_header' => IP_Detector::sanitize_header_name( $trusted_proxy_header ),
			'bypass_rest_api'      => ! empty( $_POST['bypass_rest_api'] ),
			'bypass_wp_cron'       => ! empty( $_POST['bypass_wp_cron'] ),
			'bypass_xmlrpc'        => ! empty( $_POST['bypass_xmlrpc'] ),
			'bypass_ajax'          => ! empty( $_POST['bypass_ajax'] ),
			'bypass_googlebot'     => ! empty( $_POST['bypass_googlebot'] ),
		);

		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		Plugin::save_settings( $settings );
		$this->purge_page_cache();
		add_settings_error( 'geo_lockdown', 'settings_saved', __( 'Settings saved.', 'geo-lockdown' ), 'updated' );
	}

	/**
	 * Purge any full-page cache plugin so no stale, incorrectly-cached
	 * allow/block decision can keep being served after settings change.
	 * Safe to call even when a given cache plugin isn't installed - each
	 * branch only fires if that plugin's function/class actually exists.
	 */
	private function purge_page_cache() {
		// LiteSpeed Cache.
		if ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
			\LiteSpeed\Purge::purge_all();
		} elseif ( has_action( 'litespeed_purge_all' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook defined by the LiteSpeed Cache plugin, not a custom hook introduced here.
			do_action( 'litespeed_purge_all' );
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// SiteGround Optimizer / other plugins that hook this generic action.
		do_action( 'geo_lockdown_purge_cache' );

		// Generic WordPress object cache.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Sanitize block response type.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_block_response( $value ) {
		$value   = sanitize_key( (string) $value );
		$allowed = array( '403', 'redirect', 'message' );
		return in_array( $value, $allowed, true ) ? $value : '403';
	}

	/**
	 * Render settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = Plugin::get_settings();
		$client_ip  = IP_Detector::get_client_ip();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only diagnostic tool, no state change.
		$test_ip    = isset( $_GET['test_ip'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['test_ip'] ) ) : '';
		$diag_ip    = IP_Detector::is_valid_ip_any( $test_ip ) ? $test_ip : $client_ip;

		$start_time    = microtime( true );
		$diag          = Country_Checker::evaluate( $diag_ip, $settings );
		$lookup_ms     = round( ( microtime( true ) - $start_time ) * 1000, 3 );

		$db_info       = Geo_Database::get_instance()->get_info();
		$countries     = Countries::all();
		$logs          = Logger::get_recent( 25 );
		$log_count     = Logger::count_logs();
		$controller    = Access_Controller::get_instance();
		$bypass_reason = $controller->get_bypass_reason();
		$remote_addr   = IP_Detector::get_server_value( 'REMOTE_ADDR' );

		include \GEO_LOCKDOWN_PLUGIN_DIR . 'admin/views/settings-page.php';
	}
}
