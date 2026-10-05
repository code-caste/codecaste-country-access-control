<?php
/**
 * Country Access Control settings page.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View template: variables are passed from class-admin.php render_page().
 *
 * @package GeoLockdown
 *
 * @var array<string, mixed>  $settings
 * @var string                $client_ip
 * @var string                $test_ip
 * @var string                $diag_ip
 * @var array<string, mixed>  $diag
 * @var array<string, mixed>  $db_info
 * @var array<string, string> $countries
 * @var array<int, object>    $logs
 * @var int                   $log_count
 * @var Access_Controller     $controller
 * @var string                $bypass_reason
 * @var string                $remote_addr
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GeoLockdown\Countries;
use GeoLockdown\Country_Checker;

settings_errors( 'geo_lockdown' );
?>
<div class="wrap codecaste-country-access-control-wrap">
	<h1><?php esc_html_e( 'Country Access Control', 'geo-lockdown' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Restrict front-end access by visitor country using a local IP database. No external APIs are used at runtime.', 'geo-lockdown' ); ?>
	</p>

	<div class="codecaste-country-access-control-grid">
		<div class="codecaste-country-access-control-main">
			<form method="post" action="">
				<?php wp_nonce_field( 'geo_lockdown_save_settings', 'geo_lockdown_nonce' ); ?>

				<div class="codecaste-country-access-control-card">
					<h2><?php esc_html_e( 'General', 'geo-lockdown' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable Country Access Control', 'geo-lockdown' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
									<?php esc_html_e( 'Turn on country-based access control for front-end visitors', 'geo-lockdown' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Access Mode', 'geo-lockdown' ); ?></th>
							<td>
								<label><input type="radio" name="mode" value="allow" <?php checked( 'allow', $settings['mode'] ); ?> /> <?php esc_html_e( 'Allow selected countries only', 'geo-lockdown' ); ?></label><br />
								<label><input type="radio" name="mode" value="block" <?php checked( 'block', $settings['mode'] ); ?> /> <?php esc_html_e( 'Block selected countries', 'geo-lockdown' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="codecaste-country-access-control-countries"><?php esc_html_e( 'Countries', 'geo-lockdown' ); ?></label></th>
							<td>
								<div class="codecaste-country-access-control-country-search-wrap">
									<input type="text" id="codecaste-country-access-control-country-search" class="regular-text" placeholder="<?php esc_attr_e( 'Type to filter countries...', 'geo-lockdown' ); ?>" autocomplete="off" />
								</div>
								<select id="codecaste-country-access-control-countries" name="countries[]" multiple size="12" class="codecaste-country-access-control-country-select">
									<?php foreach ( $countries as $code => $name ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( in_array( $code, (array) $settings['countries'], true ) ); ?>>
											<?php echo esc_html( $name . ' (' . $code . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Hold Ctrl or Cmd to select multiple countries. Use the search box to filter by name or code.', 'geo-lockdown' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="codecaste-country-access-control-card">
					<h2><?php esc_html_e( 'Blocked Visitor Response', 'geo-lockdown' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Response Type', 'geo-lockdown' ); ?></th>
							<td>
								<label><input type="radio" name="block_response" value="403" <?php checked( '403', $settings['block_response'] ); ?> /> <?php esc_html_e( 'HTTP 403 with message', 'geo-lockdown' ); ?></label>
								<p class="description" style="margin:2px 0 8px 24px;"><?php esc_html_e( "Shows the plugin's standard message, not editable here.", 'geo-lockdown' ); ?></p>
								<label><input type="radio" name="block_response" value="message" <?php checked( 'message', $settings['block_response'] ); ?> /> <?php esc_html_e( 'Custom message (403)', 'geo-lockdown' ); ?></label><br />
								<label><input type="radio" name="block_response" value="redirect" <?php checked( 'redirect', $settings['block_response'] ); ?> /> <?php esc_html_e( 'Redirect to URL', 'geo-lockdown' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="codecaste-country-access-control-message"><?php esc_html_e( 'Custom Blocked Message', 'geo-lockdown' ); ?></label></th>
							<td>
								<textarea id="codecaste-country-access-control-message" name="block_message" rows="3" class="large-text"><?php echo esc_textarea( (string) $settings['block_message'] ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="codecaste-country-access-control-redirect"><?php esc_html_e( 'Redirect URL', 'geo-lockdown' ); ?></label></th>
							<td>
								<input id="codecaste-country-access-control-redirect" type="url" name="block_redirect_url" value="<?php echo esc_attr( (string) $settings['block_redirect_url'] ); ?>" class="regular-text" placeholder="https://example.com/blocked" />
							</td>
						</tr>
					</table>
				</div>

				<div class="codecaste-country-access-control-card">
					<h2><?php esc_html_e( 'Safety and Bypass', 'geo-lockdown' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Administrator Bypass', 'geo-lockdown' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="admin_bypass" value="1" <?php checked( ! empty( $settings['admin_bypass'] ) ); ?> />
									<?php esc_html_e( 'Allow logged-in administrators to bypass country restrictions', 'geo-lockdown' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Disable this when testing blocks. Use a private/incognito window while logged out.', 'geo-lockdown' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cloudflare Support', 'geo-lockdown' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="trust_cloudflare" value="1" <?php checked( ! empty( $settings['trust_cloudflare'] ) ); ?> />
									<?php esc_html_e( 'Use the Cloudflare connecting IP header when present', 'geo-lockdown' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Validated against official Cloudflare proxy IP ranges to prevent header spoofing.', 'geo-lockdown' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'System Bypasses', 'geo-lockdown' ); ?></th>
							<td>
								<label><input type="checkbox" name="bypass_rest_api" value="1" <?php checked( ! empty( $settings['bypass_rest_api'] ) ); ?> /> <?php esc_html_e( 'REST API', 'geo-lockdown' ); ?></label><br />
								<label><input type="checkbox" name="bypass_ajax" value="1" <?php checked( ! empty( $settings['bypass_ajax'] ) ); ?> /> <?php esc_html_e( 'AJAX', 'geo-lockdown' ); ?></label><br />
								<label><input type="checkbox" name="bypass_wp_cron" value="1" <?php checked( ! empty( $settings['bypass_wp_cron'] ) ); ?> /> <?php esc_html_e( 'WP-Cron', 'geo-lockdown' ); ?></label><br />
								<label><input type="checkbox" name="bypass_xmlrpc" value="1" <?php checked( ! empty( $settings['bypass_xmlrpc'] ) ); ?> /> <?php esc_html_e( 'XML-RPC', 'geo-lockdown' ); ?></label>
								<p class="description"><?php esc_html_e( 'wp-admin, wp-login.php, and WordPress Site Health checks are always bypassed.', 'geo-lockdown' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Search Crawlers', 'geo-lockdown' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="bypass_googlebot" value="1" <?php checked( ! empty( $settings['bypass_googlebot'] ) ); ?> />
									<?php esc_html_e( 'Allow verified Googlebot (reverse DNS)', 'geo-lockdown' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When a visitor would be geo-blocked, verify Google crawlers by reverse DNS and forward confirmation back to the same IP. User-Agent strings alone never bypass the geo block.', 'geo-lockdown' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="codecaste-country-access-control-proxies"><?php esc_html_e( 'Trusted Proxies', 'geo-lockdown' ); ?></label></th>
							<td>
								<textarea id="codecaste-country-access-control-proxies" name="trusted_proxies" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings['trusted_proxies'] ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One IP or CIDR (e.g. 192.168.1.0/24 or 2001:db8::/32) per line. Proxy headers are only trusted when REMOTE_ADDR matches an entry here.', 'geo-lockdown' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="codecaste-country-access-control-header"><?php esc_html_e( 'Trusted Proxy Header', 'geo-lockdown' ); ?></label></th>
							<td>
								<input id="codecaste-country-access-control-header" type="text" name="trusted_proxy_header" value="<?php echo esc_attr( (string) $settings['trusted_proxy_header'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Example: HTTP_CF_CONNECTING_IP for Cloudflare or HTTP_X_FORWARDED_FOR for custom proxies.', 'geo-lockdown' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="codecaste-country-access-control-card">
					<h2><?php esc_html_e( 'Performance and Logging', 'geo-lockdown' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="codecaste-country-access-control-cache"><?php esc_html_e( 'Cache TTL (seconds)', 'geo-lockdown' ); ?></label></th>
							<td>
								<input id="codecaste-country-access-control-cache" type="number" min="60" step="1" name="cache_ttl" value="<?php echo esc_attr( (int) $settings['cache_ttl'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Logging', 'geo-lockdown' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="logging_enabled" value="1" <?php checked( ! empty( $settings['logging_enabled'] ) ); ?> />
									<?php esc_html_e( 'Log blocked requests', 'geo-lockdown' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="codecaste-country-access-control-retention"><?php esc_html_e( 'Log Retention (days)', 'geo-lockdown' ); ?></label></th>
							<td>
								<input id="codecaste-country-access-control-retention" type="number" min="1" step="1" name="log_retention_days" value="<?php echo esc_attr( (int) $settings['log_retention_days'] ); ?>" />
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Save Settings', 'geo-lockdown' ), 'primary', 'geo_lockdown_save_settings' ); ?>
			</form>
		</div>

		<div class="codecaste-country-access-control-sidebar">
			<div class="codecaste-country-access-control-card">
				<h2><?php esc_html_e( 'Diagnostics', 'geo-lockdown' ); ?></h2>
				<dl class="codecaste-country-access-control-dl">
					<dt><?php esc_html_e( 'Current IP', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( $client_ip ? $client_ip : __( 'Unavailable', 'geo-lockdown' ) ); ?></dd>

					<dt><?php esc_html_e( 'Server REMOTE_ADDR', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( $remote_addr ? $remote_addr : __( 'Unavailable', 'geo-lockdown' ) ); ?></dd>

					<?php if ( $bypass_reason ) : ?>
						<dt><?php esc_html_e( 'Bypass Active', 'geo-lockdown' ); ?></dt>
						<dd><strong><?php echo esc_html( $bypass_reason ); ?></strong></dd>
					<?php endif; ?>

					<dt><?php esc_html_e( 'Detected Country', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( $diag['country_name'] ? $diag['country_name'] : __( 'Unknown', 'geo-lockdown' ) ); ?></dd>

					<dt><?php esc_html_e( 'Country Code', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( $diag['country_code'] ? $diag['country_code'] : __( 'N/A', 'geo-lockdown' ) ); ?></dd>

					<dt><?php esc_html_e( 'Lookup Timing', 'geo-lockdown' ); ?></dt>
					<dd><code><?php echo esc_html( sprintf( '%.3f ms', $lookup_ms ) ); ?></code></dd>

					<dt><?php esc_html_e( 'Access Mode', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( 'block' === $settings['mode'] ? __( 'Block List', 'geo-lockdown' ) : __( 'Allow List', 'geo-lockdown' ) ); ?></dd>

					<dt><?php esc_html_e( 'Configured Countries', 'geo-lockdown' ); ?></dt>
					<dd>
						<?php
						if ( empty( $settings['countries'] ) ) {
							esc_html_e( 'None selected', 'geo-lockdown' );
						} else {
							echo esc_html( implode( ', ', array_map( array( Countries::class, 'get_name' ), (array) $settings['countries'] ) ) );
						}
						?>
					</dd>

					<dt><?php esc_html_e( 'Final Decision', 'geo-lockdown' ); ?></dt>
					<dd><strong><?php echo esc_html( Country_Checker::decision_label( $diag ) ); ?></strong></dd>
				</dl>

				<form method="get" action="">
					<input type="hidden" name="page" value="codecaste-country-access-control" />
					<p>
						<label for="codecaste-country-access-control-test-ip"><strong><?php esc_html_e( 'Test IP Address', 'geo-lockdown' ); ?></strong></label><br />
						<input id="codecaste-country-access-control-test-ip" type="text" name="test_ip" value="<?php echo esc_attr( $test_ip ); ?>" class="regular-text" placeholder="8.8.8.8" />
					</p>
					<?php submit_button( __( 'Run Test', 'geo-lockdown' ), 'secondary', '', false ); ?>
				</form>

				<?php if ( $test_ip && $diag_ip === $test_ip ) : ?>
					<div class="codecaste-country-access-control-test-result">
						<p><strong><?php esc_html_e( 'Test Result', 'geo-lockdown' ); ?></strong></p>
						<p><?php echo esc_html( sprintf( 'IP: %s', $diag_ip ) ); ?></p>
						<p><?php echo esc_html( sprintf( 'Country: %s', $diag['country_name'] ? $diag['country_name'] : __( 'Unknown', 'geo-lockdown' ) ) ); ?></p>
						<p><?php echo esc_html( sprintf( 'ISO Code: %s', $diag['country_code'] ? $diag['country_code'] : __( 'N/A', 'geo-lockdown' ) ) ); ?></p>
						<p><?php echo esc_html( sprintf( 'Decision: %s', Country_Checker::decision_label( $diag ) ) ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<div class="codecaste-country-access-control-card">
				<h2><?php esc_html_e( 'GeoIP Database', 'geo-lockdown' ); ?></h2>
				<dl class="codecaste-country-access-control-dl">
					<dt><?php esc_html_e( 'Status', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( ! empty( $db_info['ready'] ) ? __( 'Ready', 'geo-lockdown' ) : __( 'Not loaded', 'geo-lockdown' ) ); ?></dd>
					<dt><?php esc_html_e( 'Dataset', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( ! empty( $db_info['is_full'] ) ? __( 'Full imported database', 'geo-lockdown' ) : __( 'Limited starter database', 'geo-lockdown' ) ); ?></dd>

					<dt><?php esc_html_e( 'Storage', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( (string) $db_info['storage'] ); ?></dd>
					<dt><?php esc_html_e( 'Version', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( $db_info['version'] ? $db_info['version'] : __( 'N/A', 'geo-lockdown' ) ); ?></dd>
					<dt><?php esc_html_e( 'IPv4 Ranges', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( (int) $db_info['ipv4_count'] ) ); ?></dd>
					<dt><?php esc_html_e( 'IPv6 Ranges', 'geo-lockdown' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( (int) $db_info['ipv6_count'] ) ); ?></dd>
					<?php if ( ! empty( $db_info['last_update'] ) ) : ?>
						<dt><?php esc_html_e( 'Last Import Update', 'geo-lockdown' ); ?></dt>
						<dd><?php echo esc_html( (string) $db_info['last_update'] ); ?></dd>
					<?php endif; ?>
					<?php if ( ! empty( $db_info['last_duration'] ) ) : ?>
						<dt><?php esc_html_e( 'Import Duration', 'geo-lockdown' ); ?></dt>
						<dd><?php echo esc_html( $db_info['last_duration'] . ' s' ); ?></dd>
					<?php endif; ?>
				</dl>

				<form method="post" action="" style="margin-bottom:12px;">
					<?php wp_nonce_field( 'geo_lockdown_download_latest', 'geo_lockdown_download_nonce' ); ?>
					<?php submit_button( __( 'Download Latest Database', 'geo-lockdown' ), 'primary', 'geo_lockdown_download_latest', false ); ?>
					<p class="description"><?php esc_html_e( 'Recommended. Downloads the current free DB-IP Country Lite CSV directly from db-ip.com over HTTPS and imports it. No site or visitor data is sent — only the public database file is fetched. This plugin does not ship a bundled copy.', 'geo-lockdown' ); ?></p>
				</form>

				<form method="post" action="" style="margin-top:12px;">
					<?php wp_nonce_field( 'geo_lockdown_flush_cache', 'geo_lockdown_cache_nonce' ); ?>
					<?php submit_button( __( 'Clear Lookup Cache', 'geo-lockdown' ), 'secondary', 'geo_lockdown_flush_cache', false ); ?>
				</form>
			</div>

			<div class="codecaste-country-access-control-card">
				<h2><?php esc_html_e( 'IP Geolocation & VPN Notice', 'geo-lockdown' ); ?></h2>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'Country enforcement is based on local IP range mapping. If a visitor uses a VPN, proxy, or Tor exit node, the request is evaluated against the registered country of that exit IP address.', 'geo-lockdown' ); ?>
				</p>
			</div>

			<?php if ( ! empty( $settings['logging_enabled'] ) ) : ?>
				<div class="codecaste-country-access-control-card">
					<h2><?php esc_html_e( 'Recent Block Logs', 'geo-lockdown' ); ?> (<?php echo esc_html( number_format_i18n( $log_count ) ); ?>)</h2>
					<?php if ( empty( $logs ) ) : ?>
						<p><?php esc_html_e( 'No blocked requests logged yet.', 'geo-lockdown' ); ?></p>
					<?php else : ?>
						<div class="codecaste-country-access-control-log-table-wrap">
							<table class="widefat striped codecaste-country-access-control-log-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Time (UTC)', 'geo-lockdown' ); ?></th>
										<th><?php esc_html_e( 'IP', 'geo-lockdown' ); ?></th>
										<th><?php esc_html_e( 'Country', 'geo-lockdown' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $logs as $log ) : ?>
										<tr>
											<td><?php echo esc_html( (string) $log->created_at ); ?></td>
											<td><?php echo esc_html( (string) $log->ip ); ?></td>
											<td><?php echo esc_html( (string) $log->country_code ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
					<form method="post" action="" style="margin-top:12px;">
						<?php wp_nonce_field( 'geo_lockdown_clear_logs', 'geo_lockdown_logs_nonce' ); ?>
						<?php submit_button( __( 'Clear Logs', 'geo-lockdown' ), 'delete', 'geo_lockdown_clear_logs', false ); ?>
					</form>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
