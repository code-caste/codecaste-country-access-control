<?php
/**
 * Client IP detection with trusted proxy support.
 *
 * @package GeoLockdown
 */

namespace GeoLockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect and validate visitor IP addresses.
 */
class IP_Detector {

	/**
	 * Common proxy headers checked when the site runs behind a CDN or load balancer.
	 *
	 * @var array<int, string>
	 */
	private static $proxy_headers = array(
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_REAL_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_FORWARDED',
		'HTTP_CLIENT_IP',
	);

	/**
	 * Official Cloudflare proxy IPv4 and IPv6 ranges.
	 *
	 * @var array<int, string>
	 */
	private static $cloudflare_ranges = array(
		// IPv4
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		// IPv6
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Get the visitor IP address.
	 *
	 * @return string Empty string when no valid IP is found.
	 */
	public static function get_client_ip() {
		$settings        = Plugin::get_settings();
		$remote          = self::get_server_value( 'REMOTE_ADDR' );
		$trusted_proxies = isset( $settings['trusted_proxies'] ) && is_array( $settings['trusted_proxies'] )
			? $settings['trusted_proxies']
			: array();

		$is_cf_remote      = ! empty( $settings['trust_cloudflare'] ) && self::is_cloudflare_ip( $remote );
		$is_trusted_remote = self::is_trusted_proxy( $remote, $trusted_proxies );

		if ( $is_cf_remote ) {
			$cf_ip = self::get_header_ip( 'HTTP_CF_CONNECTING_IP' );
			if ( '' !== $cf_ip ) {
				return self::filter_client_ip( $cf_ip );
			}
		}

		if ( $is_trusted_remote ) {
			$header = self::sanitize_header_name( (string) $settings['trusted_proxy_header'] );
			if ( $header ) {
				$candidate = self::get_header_ip( $header );
				if ( '' !== $candidate ) {
					return self::filter_client_ip( $candidate );
				}
			}
		}

		if ( self::is_valid_ip( $remote ) ) {
			return self::filter_client_ip( $remote );
		}

		if ( self::is_private_or_local_ip( $remote ) ) {
			if ( empty( $trusted_proxies ) || self::is_trusted_proxy( $remote, $trusted_proxies ) ) {
				$proxy_ip = self::get_ip_from_proxy_headers();
				if ( '' !== $proxy_ip ) {
					return self::filter_client_ip( $proxy_ip );
				}
			}
			return self::filter_client_ip( $remote );
		}

		return '';
	}

	/**
	 * Check whether an IP is an official Cloudflare proxy IP.
	 *
	 * @param string $ip Remote address.
	 * @return bool
	 */
	public static function is_cloudflare_ip( $ip ) {
		return self::is_trusted_proxy( $ip, self::$cloudflare_ranges );
	}

	/**
	 * Validate CIDR format string or standalone IP.
	 *
	 * @param string $entry Candidate IP or CIDR.
	 * @return bool
	 */
	public static function is_valid_ip_or_cidr( $entry ) {
		$entry = trim( (string) $entry );
		if ( '' === $entry ) {
			return false;
		}

		if ( false !== strpos( $entry, '/' ) ) {
			$parts = explode( '/', $entry, 2 );
			if ( 2 !== count( $parts ) ) {
				return false;
			}
			$ip   = $parts[0];
			$mask = $parts[1];
			if ( ! self::is_valid_ip_any( $ip ) || ! ctype_digit( (string) $mask ) ) {
				return false;
			}
			$mask     = (int) $mask;
			$max_bits = self::is_ipv6( $ip ) ? 128 : 32;
			return $mask >= 0 && $mask <= $max_bits;
		}

		return self::is_valid_ip_any( $entry );
	}

	/**
	 * Get a public IP from a specific server header.
	 *
	 * @param string $header Header key.
	 * @return string
	 */
	public static function get_header_ip( $header ) {
		return self::extract_public_ip_from_header( self::get_server_value( $header ) );
	}

	/**
	 * Try common proxy headers in order.
	 *
	 * @return string
	 */
	public static function get_ip_from_proxy_headers() {
		foreach ( self::$proxy_headers as $header ) {
			$ip = self::get_header_ip( $header );
			if ( '' !== $ip ) {
				return $ip;
			}
		}

		return '';
	}

	/**
	 * Apply filter to a resolved client IP.
	 *
	 * @param string $ip Detected IP.
	 * @return string
	 */
	private static function filter_client_ip( $ip ) {
		/**
		 * Filter the detected client IP address.
		 *
		 * @param string $ip Detected IP.
		 */
		return (string) apply_filters( 'geo_lockdown_client_ip', $ip );
	}

	/**
	 * Read a sanitized value from $_SERVER.
	 *
	 * @param string $key Server key.
	 * @return string
	 */
	public static function get_server_value( $key ) {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value = wp_unslash( (string) $_SERVER[ $key ] );
		return sanitize_text_field( $value );
	}

	/**
	 * Validate IPv4 or IPv6 public address.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_valid_ip( $ip ) {
		if ( '' === $ip ) {
			return false;
		}

		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Validate IP including private/reserved ranges (for admin test tool).
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_valid_ip_any( $ip ) {
		if ( '' === $ip ) {
			return false;
		}

		return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * Whether an IP is private or local.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_private_or_local_ip( $ip ) {
		if ( ! self::is_valid_ip_any( $ip ) ) {
			return false;
		}

		return ! self::is_valid_ip( $ip );
	}

	/**
	 * Determine whether IP is IPv6.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_ipv6( $ip ) {
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
	}

	/**
	 * Convert IPv4 to unsigned integer string for numeric range comparisons.
	 *
	 * @param string $ip IPv4 address.
	 * @return string|null
	 */
	public static function ipv4_to_uint( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return null;
		}

		return sprintf( '%u', ip2long( $ip ) );
	}

	/**
	 * Convert IP to packed binary (16 bytes for IPv6, padded IPv4 mapped form).
	 *
	 * @param string $ip IP address.
	 * @return string|null
	 */
	public static function ip_to_packed( $ip ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() emits a warning for malformed input; the false return is checked below.
		$packed = @inet_pton( $ip );
		return false === $packed ? null : $packed;
	}

	/**
	 * Extract the first public IP from a comma-separated header value.
	 *
	 * @param string $header_value Header contents.
	 * @return string
	 */
	public static function extract_public_ip_from_header( $header_value ) {
		if ( '' === $header_value ) {
			return '';
		}

		$parts = explode( ',', $header_value );
		foreach ( $parts as $part ) {
			$candidate = trim( $part );
			if ( self::is_valid_ip( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Extract the first valid IP from a comma-separated header value.
	 *
	 * @param string $header_value Header contents.
	 * @return string
	 */
	public static function extract_ip_from_header( $header_value ) {
		if ( '' === $header_value ) {
			return '';
		}

		$parts = explode( ',', $header_value );
		foreach ( $parts as $part ) {
			$candidate = trim( $part );
			if ( self::is_valid_ip_any( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Normalize a proxy header name to a $_SERVER key.
	 *
	 * @param string $header Header name.
	 * @return string
	 */
	public static function sanitize_header_name( $header ) {
		$header = strtoupper( trim( $header ) );
		if ( '' === $header ) {
			return '';
		}

		if ( 0 !== strpos( $header, 'HTTP_' ) ) {
			$header = 'HTTP_' . str_replace( '-', '_', $header );
		}

		return preg_replace( '/[^A-Z0-9_]/', '', $header );
	}

	/**
	 * Check whether an IP belongs to a configured trusted proxy list.
	 *
	 * @param string             $ip      Remote address.
	 * @param array<int, string> $proxies Trusted proxy IPs or CIDR ranges.
	 * @return bool
	 */
	public static function is_trusted_proxy( $ip, array $proxies ) {
		if ( empty( $proxies ) || ! self::is_valid_ip_any( $ip ) ) {
			return false;
		}

		foreach ( $proxies as $proxy ) {
			$proxy = trim( (string) $proxy );
			if ( '' === $proxy ) {
				continue;
			}

			if ( false !== strpos( $proxy, '/' ) ) {
				if ( self::ip_in_cidr( $ip, $proxy ) ) {
					return true;
				}
			} elseif ( $ip === $proxy ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if IP is within a CIDR range.
	 *
	 * @param string $ip   IP address.
	 * @param string $cidr CIDR notation.
	 * @return bool
	 */
	public static function ip_in_cidr( $ip, $cidr ) {
		$parts = explode( '/', $cidr, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}

		list( $subnet, $mask ) = $parts;
		$mask = (int) $mask;

		if ( ! self::is_valid_ip_any( $subnet ) || ! self::is_valid_ip_any( $ip ) ) {
			return false;
		}

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );

		if ( false === $ip_bin || false === $subnet_bin ) {
			return false;
		}

		$max_bits = 8 * strlen( $ip_bin );
		if ( $mask < 0 || $mask > $max_bits ) {
			return false;
		}

		$bytes = intdiv( $mask, 8 );
		$bits  = $mask % 8;

		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $bits ) {
			return true;
		}

		$mask_byte = ( 0xFF << ( 8 - $bits ) ) & 0xFF;
		return ( ord( $ip_bin[ $bytes ] ) & $mask_byte ) === ( ord( $subnet_bin[ $bytes ] ) & $mask_byte );
	}

	/**
	 * Compare two unsigned 32-bit integer strings.
	 *
	 * @param string $left  Left operand.
	 * @param string $right Right operand.
	 * @return int -1, 0, or 1.
	 */
	public static function compare_uint( $left, $right ) {
		$left  = (string) $left;
		$right = (string) $right;

		if ( function_exists( 'bccomp' ) ) {
			return (int) bccomp( $left, $right );
		}

		$left  = str_pad( $left, 10, '0', STR_PAD_LEFT );
		$right = str_pad( $right, 10, '0', STR_PAD_LEFT );

		return strcmp( $left, $right ) <=> 0;
	}
}
