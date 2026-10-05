<?php
/**
 * Local MySQL GeoIP database engine.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- All queries target custom GeoIP tables with no WP API alternative.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- GeoIP lookups are cached in Cache class; schema operations cannot be cached.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- This class manages custom GeoIP table DDL intentionally.
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix + class constants, never user input.
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are built from $wpdb->prefix and class constants, never from user input.
 * phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- set_time_limit(0) is required for large CSV imports to prevent timeout.
 *
 * @package GeoLockdown
 */

namespace GeoLockdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs local IP-to-country lookups using WordPress database tables.
 */
class Geo_Database {

	const META_TABLE          = 'geo_lockdown_meta';
	const IPV4_TABLE          = 'geo_lockdown_geoip4';
	const IPV6_TABLE          = 'geo_lockdown_geoip6';
	const STAGING_META_TABLE  = 'geo_lockdown_meta_staging';
	const STAGING_IPV4_TABLE  = 'geo_lockdown_geoip4_staging';
	const STAGING_IPV6_TABLE  = 'geo_lockdown_geoip6_staging';
	const BATCH_SIZE          = 1000;

	/**
	 * Singleton instance.
	 *
	 * @var Geo_Database|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return Geo_Database
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Create GeoIP database tables.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$meta    = self::meta_table();
		$ipv4    = self::ipv4_table();
		$ipv6    = self::ipv6_table();

		dbDelta(
			"CREATE TABLE {$meta} (
				id tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
				version varchar(20) NOT NULL DEFAULT '',
				ipv4_count bigint(20) unsigned NOT NULL DEFAULT 0,
				ipv6_count bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$ipv4} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				start_ip bigint(20) unsigned NOT NULL,
				end_ip bigint(20) unsigned NOT NULL,
				country_code char(2) NOT NULL,
				country_name varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY start_ip (start_ip)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$ipv6} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				start_ip varbinary(16) NOT NULL,
				end_ip varbinary(16) NOT NULL,
				country_code char(2) NOT NULL,
				country_name varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY start_ip (start_ip)
			) {$charset};"
		);
	}

	/**
	 * Create clean staging tables for database replacement.
	 */
	public static function create_staging_tables() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$meta    = self::staging_meta_table();
		$ipv4    = self::staging_ipv4_table();
		$ipv6    = self::staging_ipv6_table();

		self::drop_staging_tables();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE {$meta} (
				id tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
				version varchar(20) NOT NULL DEFAULT '',
				ipv4_count bigint(20) unsigned NOT NULL DEFAULT 0,
				ipv6_count bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id)
			) {$charset};"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE {$ipv4} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				start_ip bigint(20) unsigned NOT NULL,
				end_ip bigint(20) unsigned NOT NULL,
				country_code char(2) NOT NULL,
				country_name varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY start_ip (start_ip)
			) {$charset};"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE {$ipv6} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				start_ip varbinary(16) NOT NULL,
				end_ip varbinary(16) NOT NULL,
				country_code char(2) NOT NULL,
				country_name varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY start_ip (start_ip)
			) {$charset};"
		);
	}

	/**
	 * Drop staging tables on cleanup or failure.
	 */
	public static function drop_staging_tables() {
		global $wpdb;

		$tables = array(
			self::staging_meta_table(),
			self::staging_ipv4_table(),
			self::staging_ipv6_table(),
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * Validate staging database integrity before atomic swap.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_staging_data() {
		global $wpdb;

		$s_meta = self::staging_meta_table();
		$s_ipv4 = self::staging_ipv4_table();
		$s_ipv6 = self::staging_ipv6_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$meta = $wpdb->get_row( "SELECT ipv4_count, ipv6_count FROM {$s_meta} LIMIT 1", ARRAY_A );
		if ( ! is_array( $meta ) ) {
			return new \WP_Error( 'geo_lockdown_staging_invalid_meta', __( 'Database staging failed: missing metadata record.', 'geo-lockdown' ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$v4_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s_ipv4}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$v6_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s_ipv6}" );
		$total    = $v4_count + $v6_count;

		if ( 0 === $total ) {
			return new \WP_Error( 'geo_lockdown_staging_empty', __( 'Database staging failed: no valid IP range records were imported.', 'geo-lockdown' ) );
		}

		if ( $v4_count > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$invalid_v4 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s_ipv4} WHERE start_ip > end_ip OR country_code = ''" );
			if ( $invalid_v4 > 0 ) {
				return new \WP_Error( 'geo_lockdown_staging_corrupt_v4', __( 'Database staging failed: detected invalid IPv4 range boundaries.', 'geo-lockdown' ) );
			}
		}

		if ( $v6_count > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$invalid_v6 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$s_ipv6} WHERE start_ip > end_ip OR country_code = ''" );
			if ( $invalid_v6 > 0 ) {
				return new \WP_Error( 'geo_lockdown_staging_corrupt_v6', __( 'Database staging failed: detected invalid IPv6 range boundaries.', 'geo-lockdown' ) );
			}
		}

		return true;
	}

	/**
	 * Atomically replace active database tables with verified staging tables.
	 *
	 * @return bool
	 */
	public static function swap_staging_tables() {
		global $wpdb;

		self::create_tables();

		$meta     = self::meta_table();
		$ipv4     = self::ipv4_table();
		$ipv6     = self::ipv6_table();
		$s_meta   = self::staging_meta_table();
		$s_ipv4   = self::staging_ipv4_table();
		$s_ipv6   = self::staging_ipv6_table();
		$old_meta = $wpdb->prefix . 'geo_lockdown_meta_old';
		$old_ipv4 = $wpdb->prefix . 'geo_lockdown_geoip4_old';
		$old_ipv6 = $wpdb->prefix . 'geo_lockdown_geoip6_old';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$old_meta}, {$old_ipv4}, {$old_ipv6}" );

		$swap_sql = "RENAME TABLE
			{$meta} TO {$old_meta}, {$s_meta} TO {$meta},
			{$ipv4} TO {$old_ipv4}, {$s_ipv4} TO {$ipv4},
			{$ipv6} TO {$old_ipv6}, {$s_ipv6} TO {$ipv6}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$renamed = $wpdb->query( $swap_sql );
		if ( false !== $renamed ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$old_meta}, {$old_ipv4}, {$old_ipv6}" );
			return true;
		}

		return false;
	}

	/**
	 * Detect if a file is GZIP compressed via magic bytes (0x1F 0x8B) or filename extension.
	 *
	 * @param string $file_path File path.
	 * @param string $original_filename Original filename.
	 * @return bool
	 */
	public static function is_gzip_file( $file_path, $original_filename = '' ) {
		if ( ! is_readable( $file_path ) ) {
			return false;
		}

		global $wp_filesystem;

		if ( function_exists( 'WP_Filesystem' ) ) {
			if ( empty( $wp_filesystem ) ) {
				WP_Filesystem();
			}
			if ( ! empty( $wp_filesystem ) ) {
				$content = $wp_filesystem->get_contents( $file_path );
				if ( false !== $content && strlen( $content ) >= 2 ) {
					if ( "\x1f\x8b" === substr( $content, 0, 2 ) ) {
						return true;
					}
				}
				return '' !== $original_filename && (bool) preg_match( '/\.gz$/i', $original_filename );
			}
		}

		// Fallback for CLI context or when WP_Filesystem is unavailable.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem is unavailable in this CLI/bootstrap context; failure is checked via the return value below.
		$fp = @fopen( $file_path, 'rb' );
		if ( $fp ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading 2 raw bytes to check the GZIP magic number; WP_Filesystem has no equivalent partial-read API.
			$bytes = fread( $fp, 2 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the native fopen() above.
			fclose( $fp );
			if ( "\x1f\x8b" === $bytes ) {
				return true;
			}
		}

		return '' !== $original_filename && (bool) preg_match( '/\.gz$/i', $original_filename );
	}

	/**
	 * Import bundled starter CSV when tables are empty.
	 *
	 * @return bool
	 */
	public static function maybe_import_bundled_data() {
		self::create_tables();

		$meta = self::get_meta();
		if ( ! empty( $meta['ipv4_count'] ) || ! empty( $meta['ipv6_count'] ) ) {
			return true;
		}

		return ! is_wp_error( self::import_starter_database() );
	}

	/**
	 * Whether geolocation data is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		$meta = self::get_meta();
		return ! empty( $meta['ipv4_count'] ) || ! empty( $meta['ipv6_count'] );
	}

	/**
	 * Get database metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_info() {
		$meta = self::get_meta();

		return array(
			'storage'        => __( 'WordPress database', 'geo-lockdown' ),
			'ready'          => $this->is_available(),
			'version'        => isset( $meta['version'] ) ? (string) $meta['version'] : '',
			'ipv4_count'     => isset( $meta['ipv4_count'] ) ? (int) $meta['ipv4_count'] : 0,
			'ipv6_count'     => isset( $meta['ipv6_count'] ) ? (int) $meta['ipv6_count'] : 0,
			'is_full'        => self::is_full_dataset( $meta ),
			'last_duration'  => get_option( 'geo_lockdown_last_import_duration', '' ),
			'last_update'    => get_option( 'geo_lockdown_last_import_time', '' ),
		);
	}

	/**
	 * Whether the installed dataset is a full-size dataset (imported, not the small starter set).
	 *
	 * @param array<string, mixed>|null $meta Optional meta row.
	 * @return bool
	 */
	public static function is_full_dataset( $meta = null ) {
		$meta  = is_array( $meta ) ? $meta : self::get_meta();
		$total = (int) $meta['ipv4_count'] + (int) $meta['ipv6_count'];
		return $total >= 50000;
	}

	/**
	 * Resolve country for an IP address.
	 *
	 * @param string $ip Valid IP address.
	 * @return array{country_code: string|null, country_name: string|null}
	 */
	public function lookup( $ip ) {
		$cached = Cache::get( $ip );
		if ( null !== $cached ) {
			return $cached;
		}

		$result = array(
			'country_code' => null,
			'country_name' => null,
		);

		if ( ! IP_Detector::is_valid_ip_any( $ip ) || ! $this->is_available() ) {
			Cache::set( $ip, $result );
			return $result;
		}

		if ( IP_Detector::is_ipv6( $ip ) ) {
			$result = $this->lookup_ipv6( $ip );
		} else {
			$result = $this->lookup_ipv4( $ip );
		}

		Cache::set( $ip, $result );
		return $result;
	}

	/**
	 * IPv4 lookup using indexed range search.
	 *
	 * @param string $ip IPv4 address.
	 * @return array{country_code: string|null, country_name: string|null}
	 */
	private function lookup_ipv4( $ip ) {
		global $wpdb;

		$uint = IP_Detector::ipv4_to_uint( $ip );
		if ( null === $uint ) {
			return array(
				'country_code' => null,
				'country_name' => null,
			);
		}

		$table = self::ipv4_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT country_code, country_name, end_ip FROM {$table} WHERE start_ip <= %s ORDER BY start_ip DESC LIMIT 1",
				$uint
			),
			ARRAY_A
		);

		if ( is_array( $row ) && isset( $row['end_ip'] ) ) {
			if ( IP_Detector::compare_uint( (string) $uint, (string) $row['end_ip'] ) <= 0 ) {
				return array(
					'country_code' => isset( $row['country_code'] ) ? strtoupper( (string) $row['country_code'] ) : null,
					'country_name' => isset( $row['country_name'] ) ? (string) $row['country_name'] : null,
				);
			}
		}

		return array(
			'country_code' => null,
			'country_name' => null,
		);
	}

	/**
	 * IPv6 lookup using indexed range search.
	 *
	 * @param string $ip IPv6 address.
	 * @return array{country_code: string|null, country_name: string|null}
	 */
	private function lookup_ipv6( $ip ) {
		global $wpdb;

		$packed = IP_Detector::ip_to_packed( $ip );
		if ( null === $packed ) {
			return array(
				'country_code' => null,
				'country_name' => null,
			);
		}

		$table = self::ipv6_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT country_code, country_name, end_ip FROM {$table} WHERE start_ip <= %s ORDER BY start_ip DESC LIMIT 1",
				$packed
			),
			ARRAY_A
		);

		if ( is_array( $row ) && isset( $row['end_ip'] ) ) {
			if ( strcmp( $packed, (string) $row['end_ip'] ) <= 0 ) {
				return array(
					'country_code' => isset( $row['country_code'] ) ? strtoupper( (string) $row['country_code'] ) : null,
					'country_name' => isset( $row['country_name'] ) ? (string) $row['country_name'] : null,
				);
			}
		}

		return array(
			'country_code' => null,
			'country_name' => null,
		);
	}

	/**
	 * Safe database import using staging tables and atomic swap.
	 *
	 * @param string $csv_path          Absolute path to uploaded CSV file.
	 * @param string $original_filename Original filename.
	 * @return true|\WP_Error
	 */
	public function import_csv( $csv_path, $original_filename = '' ) {
		$start_time = microtime( true );

		if ( ! is_readable( $csv_path ) ) {
			return new \WP_Error( 'geo_lockdown_unreadable', __( 'The uploaded database file could not be read.', 'geo-lockdown' ) );
		}

		$real_csv = realpath( $csv_path );
		if ( false === $real_csv ) {
			return new \WP_Error( 'geo_lockdown_invalid_path', __( 'Invalid database file path.', 'geo-lockdown' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
			set_time_limit( 0 );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		self::create_staging_tables();

		$result = $this->load_csv_into_staging( $real_csv, $original_filename );
		if ( is_wp_error( $result ) ) {
			self::drop_staging_tables();
			return $result;
		}

		$validation = self::validate_staging_data();
		if ( is_wp_error( $validation ) ) {
			self::drop_staging_tables();
			return $validation;
		}

		$swapped = self::swap_staging_tables();
		if ( ! $swapped ) {
			self::drop_staging_tables();
			return new \WP_Error( 'geo_lockdown_swap_failed', __( 'Failed to replace GeoIP database tables atomically.', 'geo-lockdown' ) );
		}

		$duration = round( microtime( true ) - $start_time, 2 );
		update_option( 'geo_lockdown_last_import_duration', (string) $duration, false );
		update_option( 'geo_lockdown_last_import_time', current_time( 'mysql', true ), false );

		Cache::flush();
		return true;
	}

	/**
	 * Import the lightweight starter CSV dataset.
	 *
	 * @return true|\WP_Error
	 */
	public static function import_starter_database() {
		$starter_file = defined( 'GEO_LOCKDOWN_STARTER_CSV' ) ? \GEO_LOCKDOWN_STARTER_CSV : \GEO_LOCKDOWN_PLUGIN_DIR . 'database/source/starter-country-ranges.csv';
		if ( ! is_readable( $starter_file ) ) {
			return new \WP_Error( 'geo_lockdown_missing_starter', __( 'The starter GeoIP database file is missing from the plugin.', 'geo-lockdown' ) );
		}

		return self::get_instance()->import_csv( $starter_file, 'starter-country-ranges.csv' );
	}

	/**
	 * Download and import the latest DB-IP Country Lite CSV from db-ip.com.
	 *
	 * This plugin does not bundle any third-party GeoIP database. When the
	 * administrator explicitly clicks "Download Latest Database", this method
	 * fetches the current month's free IP-to-Country Lite CSV.GZ file directly
	 * from https://download.db-ip.com/ (falling back to the previous two
	 * months if the current month has not been published yet) and imports it
	 * through the same staging-table/atomic-swap pipeline used for manual
	 * CSV uploads. No site or visitor data is ever sent to db-ip.com.
	 *
	 * @return true|\WP_Error
	 */
	public static function download_latest_database() {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return new \WP_Error( 'geo_lockdown_no_http', __( 'The WordPress HTTP API is unavailable.', 'geo-lockdown' ) );
		}

		$now        = time();
		$last_error = __( 'Could not reach db-ip.com to download the database.', 'geo-lockdown' );
		$max_back   = 3; // Try this month, then up to 2 months back, in case the current month is not published yet.

		for ( $i = 0; $i < $max_back; $i++ ) {
			$stamp    = strtotime( "-{$i} months", $now );
			$filename = sprintf( 'dbip-country-lite-%s.csv.gz', gmdate( 'Y-m', $stamp ) );
			$url      = 'https://download.db-ip.com/free/' . $filename;

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 60,
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$last_error = sprintf(
					/* translators: 1: HTTP status code, 2: filename attempted. */
					__( 'db-ip.com returned HTTP %1$d for %2$s.', 'geo-lockdown' ),
					$code,
					$filename
				);
				continue;
			}

			$body = wp_remote_retrieve_body( $response );
			if ( '' === $body || strlen( $body ) < 1024 ) {
				$last_error = __( 'The downloaded database file was empty or unexpectedly small.', 'geo-lockdown' );
				continue;
			}

			$tmp_file = wp_tempnam( $filename );
			if ( ! $tmp_file ) {
				return new \WP_Error( 'geo_lockdown_tmp_failed', __( 'Could not create a temporary file to store the download.', 'geo-lockdown' ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_put_contents -- Writing the already-fetched HTTP response body to a WP-provided temp path; WP_Filesystem offers no advantage here.
			$written = file_put_contents( $tmp_file, $body );
			unset( $body );

			if ( false === $written ) {
				wp_delete_file( $tmp_file );
				return new \WP_Error( 'geo_lockdown_write_failed', __( 'Could not write the downloaded database to a temporary file.', 'geo-lockdown' ) );
			}

			if ( ! self::is_gzip_file( $tmp_file, $filename ) ) {
				wp_delete_file( $tmp_file );
				$last_error = __( 'The downloaded file was not a valid GZIP database.', 'geo-lockdown' );
				continue;
			}

			$result = self::get_instance()->import_csv( $tmp_file, $filename );
			wp_delete_file( $tmp_file );

			return $result;
		}

		return new \WP_Error( 'geo_lockdown_download_failed', $last_error );
	}

	/**
	 * Parse CSV and insert rows into staging database tables.
	 *
	 * @param string $csv_path          CSV file path.
	 * @param string $original_filename Original filename.
	 * @return true|\WP_Error
	 */
	private function load_csv_into_staging( $csv_path, $original_filename = '' ) {
		$handle_info = $this->open_csv_handle( $csv_path, $original_filename );
		if ( false === $handle_info || empty( $handle_info['handle'] ) ) {
			return new \WP_Error( 'geo_lockdown_csv_open', __( 'Could not open the CSV file or decompress GZIP data.', 'geo-lockdown' ) );
		}

		$handle     = $handle_info['handle'];
		$ipv4_count = 0;
		$ipv6_count = 0;
		$row_num    = 0;
		$ipv4_batch = array();
		$ipv6_batch = array();

		while ( ( $row = $this->read_csv_row( $handle ) ) !== false ) {
			++$row_num;

			if ( empty( $row ) || ( 1 === $row_num && $this->looks_like_header( $row ) ) ) {
				continue;
			}

			if ( count( $row ) < 3 ) {
				$this->close_csv_handle( $handle_info );
				return new \WP_Error(
					'geo_lockdown_csv_format',
					sprintf(
						/* translators: %d: CSV row number */
						__( 'Invalid CSV format on row %d. Expected start_ip,end_ip,country_code.', 'geo-lockdown' ),
						$row_num
					)
				);
			}

			$parsed = $this->parse_csv_row( $row );
			if ( null === $parsed ) {
				continue;
			}

			if ( 'ipv6' === $parsed['type'] ) {
				$ipv6_batch[] = $parsed;
				if ( count( $ipv6_batch ) >= self::BATCH_SIZE ) {
					$this->insert_ipv6_staging_batch( $ipv6_batch );
					$ipv6_count += count( $ipv6_batch );
					$ipv6_batch  = array();
				}
			} else {
				$ipv4_batch[] = $parsed;
				if ( count( $ipv4_batch ) >= self::BATCH_SIZE ) {
					$this->insert_ipv4_staging_batch( $ipv4_batch );
					$ipv4_count += count( $ipv4_batch );
					$ipv4_batch  = array();
				}
			}
		}

		$this->close_csv_handle( $handle_info );

		if ( ! empty( $ipv4_batch ) ) {
			$this->insert_ipv4_staging_batch( $ipv4_batch );
			$ipv4_count += count( $ipv4_batch );
		}

		if ( ! empty( $ipv6_batch ) ) {
			$this->insert_ipv6_staging_batch( $ipv6_batch );
			$ipv6_count += count( $ipv6_batch );
		}

		if ( 0 === $ipv4_count && 0 === $ipv6_count ) {
			return new \WP_Error( 'geo_lockdown_csv_empty', __( 'No valid IP ranges were found in the CSV file.', 'geo-lockdown' ) );
		}

		self::save_staging_meta( gmdate( 'Y-m-d' ), $ipv4_count, $ipv6_count );
		return true;
	}

	/**
	 * Parse one CSV row into a normalized insert payload.
	 *
	 * @param array<int, string> $row CSV row.
	 * @return array<string, mixed>|null
	 */
	private function parse_csv_row( array $row ) {
		$start = trim( (string) $row[0], " \t\n\r\0\x0B\"" );
		$end   = trim( (string) $row[1], " \t\n\r\0\x0B\"" );
		$code  = strtoupper( substr( sanitize_text_field( (string) $row[2] ), 0, 2 ) );
		$name  = isset( $row[3] ) ? sanitize_text_field( (string) $row[3] ) : Countries::get_name( $code );

		if ( 'ZZ' === $code || 2 !== strlen( $code ) || ! IP_Detector::is_valid_ip_any( $start ) || ! IP_Detector::is_valid_ip_any( $end ) ) {
			return null;
		}

		if ( IP_Detector::is_ipv6( $start ) !== IP_Detector::is_ipv6( $end ) ) {
			return null;
		}

		if ( IP_Detector::is_ipv6( $start ) ) {
			$start_bin = IP_Detector::ip_to_packed( $start );
			$end_bin   = IP_Detector::ip_to_packed( $end );
			if ( null === $start_bin || null === $end_bin ) {
				return null;
			}

			return array(
				'type'         => 'ipv6',
				'start_ip'     => $start_bin,
				'end_ip'       => $end_bin,
				'country_code' => $code,
				'country_name' => $name,
			);
		}

		$start_uint = IP_Detector::ipv4_to_uint( $start );
		$end_uint   = IP_Detector::ipv4_to_uint( $end );
		if ( null === $start_uint || null === $end_uint ) {
			return null;
		}

		return array(
			'type'         => 'ipv4',
			'start_ip'     => $start_uint,
			'end_ip'       => $end_uint,
			'country_code' => $code,
			'country_name' => $name,
		);
	}

	/**
	 * Insert a batch of IPv4 rows into staging table.
	 *
	 * @param array<int, array<string, mixed>> $batch Batch rows.
	 */
	private function insert_ipv4_staging_batch( array $batch ) {
		global $wpdb;

		$table  = self::staging_ipv4_table();
		$values = array();

		foreach ( $batch as $row ) {
			$values[] = $wpdb->prepare(
				'(%s, %s, %s, %s)',
				(string) $row['start_ip'],
				(string) $row['end_ip'],
				(string) $row['country_code'],
				(string) $row['country_name']
			);
		}

		if ( empty( $values ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "INSERT INTO {$table} (start_ip, end_ip, country_code, country_name) VALUES " . implode( ',', $values ) );
	}

	/**
	 * Insert a batch of IPv6 rows into staging table.
	 *
	 * @param array<int, array<string, mixed>> $batch Batch rows.
	 */
	private function insert_ipv6_staging_batch( array $batch ) {
		global $wpdb;

		$table  = self::staging_ipv6_table();
		$values = array();

		foreach ( $batch as $row ) {
			$values[] = $wpdb->prepare(
				'(%s, %s, %s, %s)',
				(string) $row['start_ip'],
				(string) $row['end_ip'],
				(string) $row['country_code'],
				(string) $row['country_name']
			);
		}

		if ( empty( $values ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "INSERT INTO {$table} (start_ip, end_ip, country_code, country_name) VALUES " . implode( ',', $values ) );
	}

	/**
	 * Detect CSV header row.
	 *
	 * @param array<int, string> $row CSV row.
	 * @return bool
	 */
	private function looks_like_header( array $row ) {
		$first = strtolower( trim( (string) $row[0], " \t\n\r\0\x0B\"" ) );
		return in_array( $first, array( 'start', 'start_ip', 'ip_start', 'ip range start' ), true );
	}

	/**
	 * Open a plain or gzipped CSV file safely.
	 *
	 * @param string $csv_path          File path.
	 * @param string $original_filename Original filename.
	 * @return array{handle: resource, is_gz: bool}|false
	 */
	private function open_csv_handle( $csv_path, $original_filename = '' ) {
		$is_gz = self::is_gzip_file( $csv_path, $original_filename );

		if ( $is_gz ) {
			if ( ! function_exists( 'gzopen' ) ) {
				return false;
			}
			$handle = @gzopen( $csv_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is checked via the false return value below.
			return false !== $handle ? array( 'handle' => $handle, 'is_gz' => true ) : false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Streaming a large CSV row-by-row requires a native file handle; WP_Filesystem has no streaming API. Failure is checked via the return value below.
		$handle = @fopen( $csv_path, 'rb' );
		return false !== $handle ? array( 'handle' => $handle, 'is_gz' => false ) : false;
	}

	/**
	 * Read one CSV row from a plain or gzipped file stream.
	 *
	 * @param resource $handle File handle resource.
	 * @return array<int, string>|false
	 */
	private function read_csv_row( $handle ) {
		return fgetcsv( $handle, 0, ',', '"', '\\' );
	}

	/**
	 * Close a CSV or GZIP file stream handle.
	 *
	 * @param array{handle: resource, is_gz: bool} $handle_info Stream info.
	 */
	private function close_csv_handle( array $handle_info ) {
		if ( empty( $handle_info['handle'] ) ) {
			return;
		}

		if ( ! empty( $handle_info['is_gz'] ) && function_exists( 'gzclose' ) ) {
			@gzclose( $handle_info['handle'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Closing an already-invalid handle is harmless; nothing depends on the outcome.
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged -- Paired with the native fopen() above; closing an already-invalid handle is harmless.
		@fclose( $handle_info['handle'] );
	}

	/**
	 * Get metadata row.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_meta() {
		global $wpdb;

		$table = self::meta_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT version, ipv4_count, ipv6_count FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );

		if ( ! is_array( $row ) ) {
			return array(
				'version'    => '',
				'ipv4_count' => 0,
				'ipv6_count' => 0,
			);
		}

		return $row;
	}

	/**
	 * Save metadata row for staging table.
	 *
	 * @param string $version    Database version label.
	 * @param int    $ipv4_count IPv4 range count.
	 * @param int    $ipv6_count IPv6 range count.
	 */
	public static function save_staging_meta( $version, $ipv4_count, $ipv6_count ) {
		global $wpdb;

		$table = self::staging_meta_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$wpdb->insert(
			$table,
			array(
				'version'    => sanitize_text_field( (string) $version ),
				'ipv4_count' => max( 0, (int) $ipv4_count ),
				'ipv6_count' => max( 0, (int) $ipv6_count ),
			),
			array( '%s', '%d', '%d' )
		);
	}

	/**
	 * Save metadata row for active table.
	 *
	 * @param string $version    Database version label.
	 * @param int    $ipv4_count IPv4 range count.
	 * @param int    $ipv6_count IPv6 range count.
	 */
	public static function save_meta( $version, $ipv4_count, $ipv6_count ) {
		global $wpdb;

		$table = self::meta_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$wpdb->insert(
			$table,
			array(
				'version'    => sanitize_text_field( (string) $version ),
				'ipv4_count' => max( 0, (int) $ipv4_count ),
				'ipv6_count' => max( 0, (int) $ipv6_count ),
			),
			array( '%s', '%d', '%d' )
		);
	}

	/**
	 * Remove all GeoIP rows from active tables.
	 */
	public static function truncate_geoip_tables() {
		global $wpdb;

		$ipv4 = self::ipv4_table();
		$ipv6 = self::ipv6_table();
		$meta = self::meta_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$ipv4}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$ipv6}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$meta}" );
	}

	/**
	 * Drop GeoIP tables on uninstall.
	 */
	public static function drop_tables() {
		global $wpdb;

		self::drop_staging_tables();

		$tables = array(
			self::meta_table(),
			self::ipv4_table(),
			self::ipv6_table(),
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * Meta table name.
	 *
	 * @return string
	 */
	public static function meta_table() {
		global $wpdb;
		return $wpdb->prefix . self::META_TABLE;
	}

	/**
	 * IPv4 table name.
	 *
	 * @return string
	 */
	public static function ipv4_table() {
		global $wpdb;
		return $wpdb->prefix . self::IPV4_TABLE;
	}

	/**
	 * IPv6 table name.
	 *
	 * @return string
	 */
	public static function ipv6_table() {
		global $wpdb;
		return $wpdb->prefix . self::IPV6_TABLE;
	}

	/**
	 * Staging meta table name.
	 *
	 * @return string
	 */
	public static function staging_meta_table() {
		global $wpdb;
		return $wpdb->prefix . self::STAGING_META_TABLE;
	}

	/**
	 * Staging IPv4 table name.
	 *
	 * @return string
	 */
	public static function staging_ipv4_table() {
		global $wpdb;
		return $wpdb->prefix . self::STAGING_IPV4_TABLE;
	}

	/**
	 * Staging IPv6 table name.
	 *
	 * @return string
	 */
	public static function staging_ipv6_table() {
		global $wpdb;
		return $wpdb->prefix . self::STAGING_IPV6_TABLE;
	}
}
