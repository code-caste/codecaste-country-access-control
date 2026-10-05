<?php
/**
 * Request access controller.
 *
 * @package GeoLockdown
 */

namespace GeoLockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforce geo restrictions as early as possible.
 */
class Access_Controller {

	/**
	 * Singleton instance.
	 *
	 * @var Access_Controller|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return Access_Controller
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
		add_action( 'plugins_loaded', array( $this, 'maybe_block_request' ), 2 );
	}

	/**
	 * Evaluate the current request and block when required.
	 */
	public function maybe_block_request() {
		$settings = Plugin::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		// The response for this URL depends on the visitor's country, so it must
		// never be stored or served by a full-page cache (LiteSpeed, WP Rocket,
		// W3 Total Cache, WP Super Cache, etc.). Mark every request as
		// non-cacheable *before* any bypass/allow/block decision is made, so a
		// page cached during one decision can never be replayed for a visitor
		// who should get a different decision.
		$this->prevent_page_caching();

		if ( $this->should_bypass_request() ) {
			return;
		}

		$ip     = IP_Detector::get_client_ip();
		$result = Country_Checker::evaluate( $ip, $settings );

		if ( ! empty( $result['allowed'] ) ) {
			return;
		}

		// Only run crawler DNS checks when the request would otherwise be blocked.
		if ( $this->should_bypass_verified_crawler( $ip, $settings ) ) {
			return;
		}

		if ( ! empty( $settings['logging_enabled'] ) ) {
			Logger::log(
				array(
					'ip'           => $ip,
					'country_code' => isset( $result['country_code'] ) ? $result['country_code'] : '',
					'decision'     => 'blocked',
					'request_uri'  => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '',
					'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				)
			);
		}

		$this->send_block_response( $settings );
	}

	/**
	 * Tell every common full-page cache plugin to skip caching this request.
	 *
	 * Full-page caches normally serve a stored static copy of a page before
	 * WordPress (and this plugin) ever runs again, which is what lets a
	 * previously-cached "allowed" response get served to a visitor who should
	 * actually be blocked (or vice versa). Setting these constants/headers as
	 * early as possible prevents the *current* response from being cached, so
	 * every future visitor always gets a fresh, correctly-evaluated decision.
	 */
	private function prevent_page_caching() {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- These are pre-established constants recognized by WordPress core (DONOTCACHEPAGE, DONOTCACHEOBJECT) and by third-party cache plugins (LSCACHE_NO_CACHE, DONOTROCKETOPTIMIZE) as their public "skip cache" API. They are conventionally unprefixed so any plugin can set them; redefining them under this plugin's own prefix would mean the cache plugins reading them would never see them.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// LiteSpeed Cache.
		if ( ! defined( 'LSCACHE_NO_CACHE' ) ) {
			define( 'LSCACHE_NO_CACHE', true );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook defined by the LiteSpeed Cache plugin, not a custom hook introduced here.
		do_action( 'litespeed_control_set_nocache', 'Country Access Control: response varies by visitor country' );

		// WP Rocket.
		if ( ! defined( 'DONOTROCKETOPTIMIZE' ) ) {
			define( 'DONOTROCKETOPTIMIZE', true );
		}

		// WP Super Cache.
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/**
	 * Determine whether the current request should bypass geo checks.
	 *
	 * @return bool
	 */
	public function should_bypass_request() {
		$settings = Plugin::get_settings();

		if ( $this->is_admin_area_request() ) {
			return true;
		}

		if ( ! empty( $settings['admin_bypass'] ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! empty( $settings['bypass_wp_cron'] ) && ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return true;
		}

		if ( ! empty( $settings['bypass_ajax'] ) && wp_doing_ajax() ) {
			return true;
		}

		if ( ! empty( $settings['bypass_rest_api'] ) && self::is_rest_request() ) {
			return true;
		}

		if ( ! empty( $settings['bypass_xmlrpc'] ) && self::is_xmlrpc_request() ) {
			return true;
		}

		if ( self::is_health_check_request() ) {
			return true;
		}

		/**
		 * Filter whether the current request bypasses geo lockdown.
		 *
		 * @param bool $bypass Current bypass state.
		 */
		return (bool) apply_filters( 'geo_lockdown_bypass', false );
	}

	/**
	 * Allow verified Google crawlers past a geo block (reverse DNS, not User-Agent).
	 *
	 * @param string               $ip       Client IP.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return bool
	 */
	public function should_bypass_verified_crawler( $ip, array $settings ) {
		if ( empty( $settings['bypass_googlebot'] ) ) {
			return false;
		}

		if ( '' === $ip ) {
			return false;
		}

		return Crawler_Verifier::is_verified_googlebot( $ip );
	}

	/**
	 * Human-readable reason when the current request bypasses geo checks.
	 *
	 * @return string Empty string when geo checks apply.
	 */
	public function get_bypass_reason() {
		$settings = Plugin::get_settings();

		if ( $this->is_admin_area_request() ) {
			return __( 'WordPress admin or login area', 'geo-lockdown' );
		}

		if ( ! empty( $settings['admin_bypass'] ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return __( 'Logged-in administrator bypass', 'geo-lockdown' );
		}

		if ( ! empty( $settings['bypass_wp_cron'] ) && ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return 'WP-Cron';
		}

		if ( ! empty( $settings['bypass_ajax'] ) && wp_doing_ajax() ) {
			return 'AJAX';
		}

		if ( ! empty( $settings['bypass_rest_api'] ) && self::is_rest_request() ) {
			return 'REST API';
		}

		if ( ! empty( $settings['bypass_xmlrpc'] ) && self::is_xmlrpc_request() ) {
			return 'XML-RPC';
		}

		if ( self::is_health_check_request() ) {
			return __( 'WordPress site health check', 'geo-lockdown' );
		}

		if ( ! empty( $settings['bypass_googlebot'] ) ) {
			$ip = IP_Detector::get_client_ip();
			if ( '' !== $ip && Crawler_Verifier::is_verified_googlebot( $ip ) ) {
				return __( 'Verified Googlebot (reverse DNS)', 'geo-lockdown' );
			}
		}

		return '';
	}

	/**
	 * Detect WordPress health check requests.
	 *
	 * @return bool
	 */
	public static function is_health_check_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' !== $uri ) {
			if ( false !== strpos( $uri, 'wp-site-health' ) || false !== strpos( $uri, 'site-health' ) ) {
				return true;
			}
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' !== $user_agent && false !== strpos( strtolower( $user_agent ), 'wordpress/' ) && false !== strpos( strtolower( $user_agent ), 'health-check' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether request targets wp-admin or wp-login.
	 *
	 * @return bool
	 */
	private function is_admin_area_request() {
		if ( is_admin() ) {
			return true;
		}

		$script = isset( $_SERVER['PHP_SELF'] ) ? basename( sanitize_text_field( wp_unslash( (string) $_SERVER['PHP_SELF'] ) ) ) : '';
		if ( 'wp-login.php' === $script ) {
			return true;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' !== $uri ) {
			$path = wp_parse_url( $uri, PHP_URL_PATH );
			if ( is_string( $path ) ) {
				$path = trim( $path, '/' );
				if ( 'wp-admin' === $path || 0 === strpos( $path, 'wp-admin/' ) || 'wp-login.php' === basename( $path ) ) {
					return true;
				}

				if ( function_exists( 'wp_login_url' ) ) {
					$custom_login_path = wp_parse_url( wp_login_url(), PHP_URL_PATH );
					if ( is_string( $custom_login_path ) && '' !== $custom_login_path ) {
						$custom_login_path = trim( $custom_login_path, '/' );
						if ( '' !== $custom_login_path && $path === $custom_login_path ) {
							return true;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Detect REST API requests.
	 *
	 * @return bool
	 */
	public static function is_rest_request() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return false;
		}

		$rest_prefix = rest_get_url_prefix();
		$path        = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return false;
		}

		$trimmed_prefix = trim( $rest_prefix, '/' );
		if ( $path === '/' . $trimmed_prefix || $path === $trimmed_prefix ) {
			return true;
		}

		$needle = '/' . $trimmed_prefix . '/';
		return false !== strpos( $path, $needle );
	}

	/**
	 * Detect XML-RPC requests.
	 *
	 * @return bool
	 */
	public static function is_xmlrpc_request() {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}

		$script = isset( $_SERVER['PHP_SELF'] ) ? basename( sanitize_text_field( wp_unslash( (string) $_SERVER['PHP_SELF'] ) ) ) : '';
		return 'xmlrpc.php' === $script;
	}

	/**
	 * Send configured block response and exit safely without redirect loops.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 */
	private function send_block_response( array $settings ) {
		$response    = isset( $settings['block_response'] ) ? (string) $settings['block_response'] : '403';
		$message     = isset( $settings['block_message'] ) ? (string) $settings['block_message'] : '';
		$redirect    = isset( $settings['block_redirect_url'] ) ? esc_url_raw( (string) $settings['block_redirect_url'] ) : '';
		$current_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		if ( 'redirect' === $response && '' !== $redirect ) {
			$validated_redirect = wp_http_validate_url( $redirect );
			if ( $validated_redirect ) {
				$parsed_red  = wp_parse_url( $validated_redirect );
				$site_host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				$red_host    = isset( $parsed_red['host'] ) ? (string) $parsed_red['host'] : '';
				$same_domain = ( '' !== $site_host && strtolower( $red_host ) === strtolower( $site_host ) );

				if ( ! $same_domain ) {
					// A different domain can never trigger this plugin's own block-and-
					// redirect logic again, so there is no loop risk to guard against.
					wp_redirect( $validated_redirect, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Admin-configured destination, validated above; wp_safe_redirect() would incorrectly block legitimate external redirect targets.
					exit;
				}

				// Same-domain redirect: only skip if it points at the exact page
				// already being blocked, which is the actual loop condition.
				$parsed_curr = wp_parse_url( $current_uri );
				$red_path    = isset( $parsed_red['path'] ) ? rtrim( $parsed_red['path'], '/' ) : '';
				$curr_path   = isset( $parsed_curr['path'] ) ? rtrim( $parsed_curr['path'], '/' ) : '';

				if ( $red_path !== $curr_path ) {
					wp_redirect( $validated_redirect, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Admin-configured destination, validated above; wp_safe_redirect() would incorrectly block legitimate external redirect targets.
					exit;
				}
			}
		}

		$status  = 403;
		$body    = ( 'message' === $response && '' !== $message ) ? $message : __( 'Access to this website is not available in your country.', 'geo-lockdown' );
		$nocache = array(
			'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'        => 'no-cache',
		);

		if ( ! headers_sent() ) {
			status_header( $status );
			nocache_headers();
			foreach ( $nocache as $header => $value ) {
				header( $header . ': ' . $value );
			}
			header( 'Content-Type: text/html; charset=UTF-8' );
		}

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Access Denied', 'geo-lockdown' ) . '</title></head><body>';
		echo '<div style="max-width:600px;margin:80px auto;padding:24px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#333;background:#fff;">';
		echo '<h1 style="margin-top:0;font-size:24px;color:#d32f2f;">' . esc_html__( 'Access Restricted', 'geo-lockdown' ) . '</h1>';
		echo '<p style="font-size:16px;line-height:1.5;">' . esc_html( $body ) . '</p>';
		echo '</div></body></html>';
		exit;
	}
}
