<?php
/**
 * Verified search crawler detection via reverse DNS.
 *
 * @package GeoLockdown
 */

namespace GeoLockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Confirm Googlebot (and related Google crawlers) by reverse + forward DNS.
 *
 * User-Agent strings are intentionally ignored: they are trivial to spoof and
 * must never bypass geo restrictions on their own.
 *
 * Verification follows Google's documented process:
 * 1. Reverse DNS lookup on the client IP
 * 2. Confirm the hostname ends in an allowed Google domain suffix
 * 3. Forward DNS lookup and confirm it resolves back to the same IP
 */
class Crawler_Verifier {

	/**
	 * Allowed hostname suffixes for verified Google crawlers.
	 *
	 * @var array<int, array<int, string>> Hostname labels, joined with dots at match time.
	 */
	private static $google_host_suffixes = array(
		array( 'googlebot', 'com' ),
		array( 'google', 'com' ),
		array( 'googleusercontent', 'com' ),
	);

	/**
	 * In-request cache of verification results keyed by IP.
	 *
	 * @var array<string, bool>
	 */
	private static $request_cache = array();

	/**
	 * Positive verification cache TTL in seconds (24 hours).
	 *
	 * @var int
	 */
	const POSITIVE_TTL = 86400;

	/**
	 * Negative verification cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const NEGATIVE_TTL = 3600;

	/**
	 * Whether the given IP is a verified Google crawler.
	 *
	 * @param string $ip Client IP address.
	 * @return bool
	 */
	public static function is_verified_googlebot( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip || ! IP_Detector::is_valid_ip_any( $ip ) ) {
			return false;
		}

		/**
		 * Short-circuit Googlebot verification (tests / advanced overrides).
		 *
		 * Return true/false to force a result, or null to continue with DNS checks.
		 *
		 * @param bool|null $verified Forced result, or null for normal verification.
		 * @param string    $ip       Client IP.
		 */
		$forced = apply_filters( 'geo_lockdown_is_verified_googlebot', null, $ip );
		if ( null !== $forced ) {
			return (bool) $forced;
		}

		if ( isset( self::$request_cache[ $ip ] ) ) {
			return self::$request_cache[ $ip ];
		}

		$cached = self::get_cached( $ip );
		if ( null !== $cached ) {
			self::$request_cache[ $ip ] = $cached;
			return $cached;
		}

		$verified = self::verify_via_dns( $ip );
		self::set_cached( $ip, $verified );
		self::$request_cache[ $ip ] = $verified;

		return $verified;
	}

	/**
	 * Run reverse + forward DNS verification for an IP.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	public static function verify_via_dns( $ip ) {
		$hostname = self::reverse_lookup( $ip );
		if ( '' === $hostname || ! self::hostname_is_google( $hostname ) ) {
			return false;
		}

		$forward_ips = self::forward_lookup( $hostname );
		return self::forward_resolves_to_ip( $ip, $forward_ips );
	}

	/**
	 * Whether a hostname belongs to an allowed Google crawler domain.
	 *
	 * Matching is suffix-based with a leading-dot boundary so that
	 * a hostname that merely ends with the same letters (no dot boundary) is rejected.
	 *
	 * @param string $hostname Hostname from reverse DNS.
	 * @return bool
	 */
	public static function hostname_is_google( $hostname ) {
		$hostname = strtolower( rtrim( (string) $hostname, '.' ) );
		if ( '' === $hostname || false !== strpos( $hostname, ' ' ) ) {
			return false;
		}

		foreach ( self::$google_host_suffixes as $labels ) {
			$bare   = implode( '.', $labels );
			$suffix = '.' . $bare;
			if ( $hostname === $bare || substr( $hostname, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any forward-resolved IP matches the original client IP.
	 *
	 * @param string             $ip          Original client IP.
	 * @param array<int, string> $forward_ips IPs from forward DNS.
	 * @return bool
	 */
	public static function forward_resolves_to_ip( $ip, array $forward_ips ) {
		$normalized = self::normalize_ip( $ip );
		if ( '' === $normalized ) {
			return false;
		}

		foreach ( $forward_ips as $candidate ) {
			if ( $normalized === self::normalize_ip( (string) $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reverse DNS lookup for an IP.
	 *
	 * @param string $ip Client IP.
	 * @return string Hostname or empty string on failure.
	 */
	public static function reverse_lookup( $ip ) {
		/**
		 * Override reverse DNS hostname (tests).
		 *
		 * @param string|null $hostname Forced hostname, or null to perform a real lookup.
		 * @param string      $ip       Client IP.
		 */
		$forced = apply_filters( 'geo_lockdown_googlebot_reverse_lookup', null, $ip );
		if ( null !== $forced ) {
			return is_string( $forced ) ? rtrim( $forced, '.' ) : '';
		}

		if ( ! function_exists( 'gethostbyaddr' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS may fail or be unavailable on some hosts.
		$result = @gethostbyaddr( $ip );
		if ( ! is_string( $result ) || '' === $result || $result === $ip ) {
			return '';
		}

		return rtrim( $result, '.' );
	}

	/**
	 * Forward DNS lookup for a hostname (A and AAAA).
	 *
	 * @param string $hostname Hostname.
	 * @return array<int, string>
	 */
	public static function forward_lookup( $hostname ) {
		/**
		 * Override forward DNS results (tests).
		 *
		 * @param array<int, string>|null $ips      Forced IP list, or null for a real lookup.
		 * @param string                  $hostname Hostname.
		 */
		$forced = apply_filters( 'geo_lockdown_googlebot_forward_lookup', null, $hostname );
		if ( null !== $forced ) {
			return is_array( $forced ) ? array_values( array_filter( array_map( 'strval', $forced ) ) ) : array();
		}

		$ips = array();

		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failures are expected for non-crawler hosts.
			$records = @dns_get_record( $hostname, DNS_A + DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( isset( $record['type'], $record['ip'] ) && 'A' === $record['type'] ) {
						$ips[] = (string) $record['ip'];
					}
					if ( isset( $record['type'], $record['ipv6'] ) && 'AAAA' === $record['type'] ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}

		if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failures are expected for non-crawler hosts.
			$v4 = @gethostbynamel( $hostname );
			if ( is_array( $v4 ) ) {
				foreach ( $v4 as $ip ) {
					$ips[] = (string) $ip;
				}
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Normalize an IP for reliable equality checks (IPv6 compression).
	 *
	 * @param string $ip IP address.
	 * @return string Normalized IP or empty string.
	 */
	public static function normalize_ip( $ip ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() emits a warning for malformed input; the false return is checked below.
		$packed = @inet_pton( (string) $ip );
		if ( false === $packed ) {
			return '';
		}

		$normalized = inet_ntop( $packed );
		return false === $normalized ? '' : $normalized;
	}

	/**
	 * Read a cached verification result.
	 *
	 * @param string $ip Client IP.
	 * @return bool|null Null on cache miss.
	 */
	private static function get_cached( $ip ) {
		$key   = self::cache_key( $ip );
		$value = get_transient( $key );

		// get_transient() returns false on a miss; only '0'/'1' are real hits.
		if ( false === $value ) {
			return null;
		}
		if ( '1' === $value || 1 === $value || true === $value ) {
			return true;
		}
		if ( '0' === $value || 0 === $value ) {
			return false;
		}

		return null;
	}

	/**
	 * Store a verification result.
	 *
	 * @param string $ip       Client IP.
	 * @param bool   $verified Verification result.
	 */
	private static function set_cached( $ip, $verified ) {
		$ttl = $verified ? self::POSITIVE_TTL : self::NEGATIVE_TTL;
		set_transient( self::cache_key( $ip ), $verified ? '1' : '0', $ttl );
	}

	/**
	 * Build transient key for crawler verification.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private static function cache_key( $ip ) {
		return 'geo_lockdown_crawler_' . md5( $ip );
	}

	/**
	 * Clear in-request cache (tests).
	 */
	public static function clear_request_cache() {
		self::$request_cache = array();
	}
}
