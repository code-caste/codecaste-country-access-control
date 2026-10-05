<?php
/**
 * Country allow/block decision engine.
 *
 * @package GeoLockdown
 */

namespace GeoLockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluate whether a visitor should be allowed.
 */
class Country_Checker {

	/**
	 * Evaluate access for an IP address.
	 *
	 * @param string                    $ip       IP address.
	 * @param array<string, mixed>|null $settings Optional settings override.
	 * @return array{
	 *   allowed: bool,
	 *   country_code: string|null,
	 *   country_name: string|null,
	 *   reason: string
	 * }
	 */
	public static function evaluate( $ip, $settings = null ) {
		$settings = is_array( $settings ) ? $settings : Plugin::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return self::result( true, null, null, 'disabled' );
		}

		if ( '' === $ip || ! IP_Detector::is_valid_ip_any( $ip ) ) {
			$countries = Countries::sanitize_codes( $settings['countries'] );
			$mode      = 'block' === $settings['mode'] ? 'block' : 'allow';
			if ( 'allow' === $mode && ! empty( $countries ) && ! empty( $settings['enabled'] ) ) {
				return self::result( false, null, null, 'invalid_ip' );
			}
			return self::result( true, null, null, 'invalid_ip' );
		}

		$lookup = Geo_Database::get_instance()->lookup( $ip );
		$code   = isset( $lookup['country_code'] ) ? $lookup['country_code'] : null;
		$name   = isset( $lookup['country_name'] ) ? $lookup['country_name'] : null;

		$countries = Countries::sanitize_codes( $settings['countries'] );
		$mode      = 'block' === $settings['mode'] ? 'block' : 'allow';

		if ( null === $code ) {
			$name = $name ? $name : __( 'Unknown', 'geo-lockdown' );
			if ( 'allow' === $mode && ! empty( $countries ) ) {
				return self::result( false, null, $name, 'unknown_country' );
			}
			return self::result( true, null, $name, 'unknown_country' );
		}

		if ( empty( $countries ) ) {
			return self::result( true, $code, $name, 'no_countries_configured' );
		}

		$in_list = in_array( $code, $countries, true );

		if ( 'allow' === $mode ) {
			$allowed = $in_list;
			$reason  = $allowed ? 'allow_list_match' : 'allow_list_blocked';
		} else {
			$allowed = ! $in_list;
			$reason  = $allowed ? 'block_list_allowed' : 'block_list_blocked';
		}

		return self::result( $allowed, $code, $name, $reason );
	}

	/**
	 * Build a normalized result array.
	 *
	 * @param bool        $allowed      Whether access is allowed.
	 * @param string|null $country_code ISO country code.
	 * @param string|null $country_name Country name.
	 * @param string      $reason       Decision reason slug.
	 * @return array{
	 *   allowed: bool,
	 *   country_code: string|null,
	 *   country_name: string|null,
	 *   reason: string
	 * }
	 */
	private static function result( $allowed, $country_code, $country_name, $reason ) {
		return array(
			'allowed'      => (bool) $allowed,
			'country_code' => $country_code,
			'country_name' => $country_name,
			'reason'       => (string) $reason,
		);
	}

	/**
	 * Human-readable decision label.
	 *
	 * @param array{allowed: bool} $result Evaluation result.
	 * @return string
	 */
	public static function decision_label( array $result ) {
		return ! empty( $result['allowed'] )
			? __( 'ALLOWED', 'geo-lockdown' )
			: __( 'BLOCKED', 'geo-lockdown' );
	}
}
