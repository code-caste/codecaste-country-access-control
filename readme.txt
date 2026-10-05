=== Country Access Control by CodeCaste ===
Contributors: codecaste, bhushan-gohel
Tags: geolocation, security, country, access control, geo blocking
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Restrict front-end access by visitor country using a local IP database. No external APIs are used at runtime.

== Description ==

= The issue we kept running into =

On client sites, “block visitors from these countries” sounds simple — until you look at how most tools do it. Many geolocation plugins phone home on every request, or they lean on a third-party lookup API. That means extra latency, another service in the critical path, and a privacy story that is harder to explain to a client.

We also saw the other failure mode: the rule looks correct in settings, but a full-page cache (LiteSpeed, WP Rocket, Cloudflare HTML cache, host caching) keeps serving a previously allowed page to someone who should be blocked — or the reverse.

= What we think about it =

Country access control has to be local-first. The decision for a visitor should happen on *your* server, against data you control, without a round trip to an external API on every page view.

And because the response depends on *who* is visiting, page caching cannot treat every visitor as if they got the same HTML. If your cache layer ignores that, geo rules will look broken even when the plugin logic is fine.

= Why we built this =

Country Access Control (the product we also call Geo Lockdown) is our answer for WordPress shops that need country allow/block rules without shipping visitor IPs to a lookup API at runtime.

Lookups run against IP range data stored in your site’s MySQL/MariaDB tables. You choose when to refresh that data. Admin, login, and common WordPress endpoints stay reachable so you do not lock yourself out while testing.

= What you get =

* Allow-list or block-list country modes with a searchable country selector
* Local IPv4 and IPv6 IP-to-country lookups (no external API call while enforcing)
* MySQL/MariaDB storage — no PHP SQLite3 extension required
* Administrator bypass to help prevent lockouts
* Automatic bypass for wp-admin, wp-login.php, REST API, AJAX, WP-Cron, and XML-RPC
* Verified Googlebot bypass using reverse DNS and forward confirmation (User-Agent alone never bypasses)
* Configurable trusted proxy / CDN headers, with Cloudflare connecting-IP validation against official Cloudflare ranges
* Custom blocked message (403) or redirect to a URL you choose
* Optional blocked-request logging
* IP lookup cache with configurable TTL, plus a one-click Clear Lookup Cache action
* Diagnostics panel and a Test IP tool so you can see what the plugin decides before you go live
* Optional one-click download of the free DB-IP Country Lite dataset, imported through staging tables and an atomic swap

= How to use it =

1. Install and activate the plugin.
2. Go to **Settings → Country Access Control**.
3. Open the Diagnostics panel and confirm the detected IP looks right (especially if you use Cloudflare or another proxy).
4. Click **Download Latest Database** so you are not relying on the limited starter dataset in production.
5. Choose Allow or Block mode, select the countries, set the blocked response, then enable Country Access Control.
6. Save settings, then purge your site/page cache (see below) and test from a real visitor perspective — private/incognito while logged out is the easiest check.

= Things to take care of (please read this) =

**1. Download a full GeoIP database before you trust the results**

The plugin ships with a small starter dataset so it activates cleanly. That is enough to explore settings — not enough for production accuracy. Use **Download Latest Database** under the GeoIP Database panel. Nothing about your site or visitors is sent; only the public CSV file is fetched when an administrator clicks the button.

**2. Page caching and CDNs**

When Country Access Control is enabled, the plugin marks front-end responses as non-cacheable for common cache plugins (including LiteSpeed Cache, WP Rocket, and WP Super Cache style APIs) and purges those caches when you save settings.

That still does not cover every stack. If your host, CDN, or another plugin caches full HTML at the edge, you can get stale allow/block decisions until that cache is cleared or told not to cache HTML for the public site.

After you enable the plugin or change countries / response settings:

* Purge the page cache in LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, SiteGround Optimizer, or whatever you use
* If Cloudflare (or another CDN) caches HTML, purge that cache too — or exclude the public HTML pages from cache while geo rules are active
* Use **Clear Lookup Cache** in the plugin sidebar if you just updated the GeoIP database and want fresh lookups immediately

If rules still look wrong after a purge, open Diagnostics, run a Test IP, and confirm the detected IP / country before digging into theme or security-plugin conflicts.

**3. Proxies, Cloudflare, and real visitor IPs**

If the site sits behind Cloudflare or another reverse proxy, enable Cloudflare support and/or configure trusted proxies and the correct connecting-IP header. Otherwise the plugin may see the proxy IP instead of the visitor, and country decisions will be wrong.

**4. Do not test blocks while logged in as an administrator**

Administrator bypass is on by default (and wp-admin / wp-login are always bypassed). Turn administrator bypass off only while testing, and use a private window while logged out.

**5. Honest limitation**

This is IP-to-country mapping, not a GPS check. VPNs, proxies, Tor, and similar tools can present an IP from another country. No IP-only system can guarantee a person’s physical location.

== Installation ==

1. Upload the `codecaste-country-access-control` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu
3. Go to **Settings → Country Access Control**
4. Download the latest GeoIP database, choose Allow or Block mode, select countries, then enable Country Access Control
5. Purge your page/CDN cache and verify with Diagnostics / a logged-out browser session

== External services ==

This plugin can optionally connect to one external service, **DB-IP** (db-ip.com), solely to let you download an updated GeoIP dataset.

**What it is and what it's used for:** DB-IP publishes a free, monthly-updated "IP to Country Lite" CSV database under a Creative Commons Attribution 4.0 license. When you click **Download Latest Database** under **Settings → Country Access Control**, the plugin fetches that public CSV.GZ file directly from `https://download.db-ip.com/` and imports it into your site's own database. Country lookups for your visitors always run locally afterward — DB-IP is never contacted while your site is actually enforcing geo-restrictions, only when an administrator explicitly requests a database update.

**What data is sent:** Nothing about your site, its visitors, or their IP addresses is sent to DB-IP. The request is a plain HTTP GET for a public, static file; no query parameters, cookies, or identifying data are included.

**When it's used:** Only when an administrator manually clicks "Download Latest Database." It never runs automatically, on a schedule, or in the background.

Service links: [db-ip.com](https://db-ip.com) · [Free Lite database & license terms](https://db-ip.com/db/download/ip-to-country-lite)

== Frequently Asked Questions ==

= Does this plugin call an external API for every visitor? =

No. Country lookups run against your local MySQL/MariaDB data. The only documented external connection is the optional, administrator-triggered DB-IP database download.

= Will page caching break country blocking? =

It can, if a full-page or CDN cache serves the same HTML to every visitor. The plugin asks common WordPress cache plugins not to store those responses and purges them when settings change — but you should still purge host/CDN caches after enabling or changing rules. See “Things to take care of” above.

= Does this require the PHP SQLite extension? =

No. GeoIP data is stored in MySQL/MariaDB tables, which every WordPress site already uses.

= Can I update the IP database? =

Yes. Click **Download Latest Database** under **Settings → Country Access Control** to fetch the current free DB-IP Country Lite CSV.

= Will I lock myself out of wp-admin? =

No. wp-admin and wp-login.php are always bypassed. Logged-in administrators can also bypass restrictions by default.

= Will Googlebot be blocked outside the allowed countries? =

Not when **Allow verified Googlebot** is enabled (default). Crawlers are verified by reverse DNS hostname and forward confirmation back to the same IP. A spoofed Googlebot User-Agent alone does not bypass the geo block.

= Does it support Cloudflare or reverse proxies? =

Yes. Trusted proxy/CDN headers can be configured, and Cloudflare source ranges are validated before those headers are trusted.

== Screenshots ==

1. Full settings screen — General rules, blocked response, safety bypasses, performance, diagnostics, and GeoIP database status
2. General — enable Country Access Control, choose allow/block mode, select countries, and set the blocked visitor response
3. Safety and Bypass — administrator bypass, Cloudflare support, system bypasses, Googlebot verification, and trusted proxies
4. Performance and Logging — Cache TTL, blocked-request logging, and log retention
5. Diagnostics — current IP, detected country, lookup timing, final decision, and Test IP tool
6. GeoIP Database — download the latest free DB-IP Country Lite dataset and clear the lookup cache

== Changelog ==

= 1.0.1 =
* Fix: Redirect response type failed silently for bare-domain URLs (e.g. https://www.google.com/) because the loop-prevention check treated their empty path as "no URL configured"
* Fix: Redirect response type used wp_safe_redirect(), which blocks any target outside the site's own domain; switched to wp_redirect() since the URL is admin-configured
* Fix: "HTTP 403 with message" and "Custom message (403)" incorrectly shared the same message field; each option now behaves independently
* Removed manual CSV database upload feature; database updates now go only through Download Latest Database

= 1.0.0 =
* Initial release
