# Developer documentation

## Contents

1. [Architecture](#architecture)
2. [JavaScript API](#javascript-api)
3. [PHP API](#php-api)
4. [Event schema](#event-schema)
5. [REST API](#rest-api)
6. [Actions and filters](#actions-and-filters)
7. [Database](#database)
8. [Background jobs](#background-jobs)
9. [Site audit internals](#site-audit-internals)
10. [Local Ads integration](#local-ads-integration)
11. [Building and testing](#building-and-testing)

---

## Architecture

```
blue-lens-analytics/
├── blue-lens-analytics.php    Bootstrap: constants, requirement check, autoloader, hooks
├── uninstall.php              Opt-in data removal
├── src/
│   ├── Core/                  Plugin container, settings, installer, migrations, jobs, tables
│   ├── Tracking/              Collector, REST endpoint, sessions, UA parsing, bots, channels, GeoIP, heatmaps, tracker loader
│   ├── Events/                Event registry, validator, writer, currency conversion
│   ├── Privacy/               PII scrubber, daily salt, visitor hashing
│   ├── Modules/Forms/         Server-side form plugin integrations
│   ├── Modules/Audit/         Site audit: catalog of checks, page analyser, crawler/runner, report queries, REST
│   ├── Modules/Ads/           Local Ads by Bernard integration: event registration, browser bridge, report
│   ├── Aggregation/           Daily rollups, retention, report queries, site-time helpers
│   ├── Admin/                 Dashboard screens, status screen, preferences, settings/reports REST controllers
│   └── functions.php          Public PHP API (blue_lens_track)
├── assets/tracker/src/        tracker.js (core), auto.js (links/forms/video/errors/vitals), capture.js (autocapture/heatmaps)
├── assets/integrations/       local-ads.js (bridge from Local Ads' localads:track event to BlueLens.track)
├── assets/admin/              Dashboard app (js/app.js, js/charts.js, css/app.css) on wp-element; no build step
├── bin/build-zip.ps1          Builds dist/blue-lens-analytics.zip
└── tests/                     PHPUnit (tests/Integration) and Jest (tests/js)
```

Namespace `BlueLens\Analytics`, PSR-4 from `src/`. Services are registered in `Core\Plugin` and resolved through a small container:

```php
$collector = blue_lens()->container()->get( \BlueLens\Analytics\Tracking\Collector::class );
```

Replace or add services on `blue_lens_register_services`.

## JavaScript API

The global `BlueLens` object is available once the deferred tracker has run. To call it earlier, queue calls with a stub:

```html
<script>
window.BlueLens = window.BlueLens || { q: [] };
[ 'track', 'consent', 'pageview' ].forEach( function ( m ) {
	BlueLens[ m ] = BlueLens[ m ] || function () { BlueLens.q.push( [ m ].concat( [].slice.call( arguments ) ) ); };
} );
</script>
```

| Method | Description |
|---|---|
| `BlueLens.track( name, props )` | Records an event. `props` may contain `category`, `module`, `entity_type`, `entity_id`, `value`, `currency`, `lead_ref`, `attributes`; other keys become attributes. |
| `BlueLens.pageview()` | Records a page view manually (SPA tracking already does this automatically). |
| `BlueLens.consent( granted )` | Tells Blue Lens the statistics-consent state (enhanced mode). |
| `BlueLens.optOut()` / `BlueLens.optIn()` | Visitor opt-out stored in the browser. |
| `BlueLens.use( fn )` | Registers an extension. `fn` receives `{ track, flush, cfg, ctx(), on( 'page'|'hide'|'event', cb ), heat( x, y ), disabled() }`. |

```js
BlueLens.track( 'booking_enquiry', {
	category: 'conversion',
	module: 'hotel',
	entity_type: 'room',
	entity_id: 482,
	value: 1200,
	currency: 'USD',
	check_in: '2026-12-20',
	nights: 4,
	guests_adults: 2,
} );
```

## PHP API

```php
blue_lens_track( 'purchase', [
	'category'    => 'conversion',
	'module'      => 'ecommerce',
	'entity_type' => 'order',
	'entity_id'   => $order->get_id(),
	'value'       => (float) $order->get_total(),
	'currency'    => $order->get_currency(),
	'attributes'  => [ 'items' => $order->get_item_count() ],
] );
```

- Call it after `plugins_loaded`. It returns `true` when stored.
- When called during the visitor's own request (checkout, AJAX form), the event is linked to their current session. From webhooks, cron or WP-CLI it is stored without a session. Pass `'session_key' => '<32 hex>'` to link it explicitly.
- Logged-in users with excluded roles are skipped.

## Event schema

| Field | Rule |
|---|---|
| `event` | `^[a-z][a-z0-9_]{1,63}$` |
| `category`, `module`, `entity_type` | slug, ≤ 32 chars (registered events override the category) |
| `entity_id` | integer or `^[A-Za-z0-9_\-.:]{1,64}$` |
| `value` | number, abs < 10¹² (converted to `base_currency` when a rate exists in `bla_fx_rates`) |
| `currency` | ISO 4217; defaults to `base_currency` |
| `lead_ref` | `^BLA-[A-Z0-9]{5,10}$` |
| `attributes` | ≤ 25 keys `^[a-z][a-z0-9_]{0,39}$`; scalars or lists of ≤ 10 scalars; strings ≤ 200 chars; PII-like keys and values dropped |

Registered core events and their categories are listed in `Events\EventRegistry`. Add your own with the `blue_lens_event_registry` filter.

## REST API

Namespace `blue-lens/v1`.

| Route | Method | Auth | Purpose |
|---|---|---|---|
| `/collect` | POST | Public | Tracker beacons. Body is JSON sent as `text/plain`, max 16 KB. Always answers `204` (`400` bad JSON, `413` too large). |
| `/settings` | GET | `manage_blue_lens` | Current settings (secrets masked) |
| `/settings` | POST/PUT/PATCH | `manage_blue_lens` | Partial update; unknown keys → `400` |
| `/reports/overview` | GET | `view_blue_lens_reports` | Totals, derived rates, daily series; `compare` = `previous`, `year` or `none` |
| `/reports/dimension` | GET | `view_blue_lens_reports` | Top values of a session dimension (`channel`, `source`, `medium`, `campaign`, `referrer`, `landing_page`, `country`, `region`, `city`, `device`, `browser`, `os`, `language`, `viewport`, `visitor_type`) |
| `/reports/content` | GET | `view_blue_lens_reports` | `group` = `page`, `post_type`, `author`, `age` or `tax:{taxonomy}` |
| `/reports/events` | GET | `view_blue_lens_reports` | Event totals |
| `/reports/crawlers` | GET | `view_blue_lens_reports` | Crawler hits and 404s |
| `/reports/heatmap-pages`, `/reports/heatmap` | GET | `view_blue_lens_reports` | Heatmap pages and cells (`device` = `all`, `desktop`, `tablet` or `mobile`) |
| `/reports/realtime` | GET | `view_blue_lens_reports` | Last 5/30 minutes from raw data |
| `/reports/refresh` | POST | `view_blue_lens_reports` | Re-aggregates today |
| `/preferences` | GET/POST | `view_blue_lens_reports` | Current user's dashboard preferences |
| `/reports/local-ads` | GET | `view_blue_lens_reports` | Local Ads totals, daily series, per ad and campaign, and the Blue Lens `visits` block (ad events by channel and page, visits with an ad click, converting visits). `{ "available": false }` when Local Ads is not active |
| `/audit` | GET | `view_blue_lens_reports` | `running` (progress or `null`), `latest` (health, counts, crawl breakdown, issues, ideas, top pages) and `history` (last 10 audits) |
| `/audit/issue` | GET | `view_blue_lens_reports` | Pages with one issue: `code` (required), `run` (default latest) |
| `/audit/pages` | GET | `view_blue_lens_reports` | Crawled pages: `filter` = `all`, `healthy`, `warnings`, `errors`, `redirects` or `broken`; `run` |
| `/audit/page` | GET | `view_blue_lens_reports` | One crawled page with facts and issues: `id` |
| `/audit/start` | POST | `manage_blue_lens` | Starts an audit, or returns the one running |
| `/audit/step` | POST | `manage_blue_lens` | Advances run `run` by about 6 seconds of work; returns progress |
| `/audit/cancel` | POST | `manage_blue_lens` | Cancels run `run` |

All `/reports/*` routes except `realtime` and `refresh` take `from` and `to` (site-local `Y-m-d`, at most 800 days). CSV export: `admin-post.php?action=blue_lens_export&report=daily,dimension,content,events,local_ads,audit&arg=…&from=…&to=…&_wpnonce=…` (`audit` exports the latest audit's pages and ignores the dates).

Cookie-authenticated requests need the `X-WP-Nonce` header (`wp_rest` nonce).

### Collect payload

```json
{
  "v": 1,
  "u": "https://example.com/tours/mara/?utm_source=google",
  "r": "https://www.google.com/",
  "ti": "Masai Mara Safari",
  "tz": "Africa/Nairobi",
  "lg": "en-KE",
  "vw": 390,
  "vid": "0f3c…(32 hex, enhanced mode with consent only)",
  "c": { "k": "singular", "p": 42, "t": "tour" },
  "hb": 15,
  "hm": [ [ 48, 31 ] ],
  "e": [ { "n": "page_view", "a": { "pv": "ab12cd34" }, "d": 120 } ]
}
```

## Actions and filters

### Actions

| Hook | Arguments | When |
|---|---|---|
| `blue_lens_loaded` | `Plugin` | Hooks registered |
| `blue_lens_register_services` | `Container` | Before boot; replace services |
| `blue_lens_installed` | `string $version` | Install/upgrade finished on a site |
| `blue_lens_before_migration` / `blue_lens_after_migration` | `int $version, Migration` | Around each migration |
| `blue_lens_settings_updated` | `array $settings` | Settings saved |
| `blue_lens_after_collect` | `array $rows, int $session_id` | Events stored |
| `blue_lens_form_submitted` | `string $form_id, string $plugin` | A supported form plugin reported success |
| `blue_lens_config_changed` | `string $key, int $version` | Versioned config activated |
| `blue_lens_deactivated` | – | Plugin deactivated on a site |
| `blue_lens_day_aggregated` | `string $day` | A day's summaries were rebuilt |
| `blue_lens_retention_completed` | `array $deleted` | A retention run finished |
| `blue_lens_audit_started` | `int $run_id` | A site audit started |
| `blue_lens_audit_completed` | `int $run_id, int $health` | A site audit finished |

### Filters

| Hook | Filters |
|---|---|
| `blue_lens_settings_schema` | Settings fields (add your own) |
| `blue_lens_before_collect` | Raw payload (`null` drops it) |
| `blue_lens_validate_event` | Normalised event (`null` drops it) |
| `blue_lens_event_registry` | Known events → category/module/conversion |
| `blue_lens_new_session` | Session row before insert |
| `blue_lens_channel_rules` / `blue_lens_channel` | Channel grouping rules / result |
| `blue_lens_is_bot` | Bot decision |
| `blue_lens_geo_lookup` | Short-circuit geo lookup (custom provider) |
| `blue_lens_allowed_hosts` | Hosts accepted by `/collect` (multi-domain sites) |
| `blue_lens_path_query_params` | Query parameters kept in stored paths |
| `blue_lens_should_track` | Whether the tracker loads on this request |
| `blue_lens_page_context` / `blue_lens_page_builder` | Embedded page context / detected builder |
| `blue_lens_tracker_data` / `blue_lens_tracker_chunks` | Data block / extra scripts for the tracker |
| `blue_lens_capability_roles` | Roles granted `manage_blue_lens` |
| `blue_lens_report_roles` | Roles granted `view_blue_lens_reports` (dashboards only) |
| `blue_lens_migrations` / `blue_lens_tables` | Register migrations / tables |
| `blue_lens_recurring_jobs` | Recurring background jobs (hook → seconds) |
| `blue_lens_network_activation_limit` | Sites installed synchronously on network activation |
| `blue_lens_audit_urls` | URLs an audit crawls (`url`, `path`, `post_id`, `source`), and the limit |
| `blue_lens_audit_issues` | Audit issue catalog: severity, category, title and fix text per code |
| `blue_lens_audit_site_checks` | Site-wide audit results (code → detail) |
| `blue_lens_local_ads_tracking` | Whether Local Ads impressions and clicks are recorded (default `true`) |
| `blue_lens_github_updates` | Whether to check GitHub for new releases (default `true`) |

Example: allow a WPML language domain.

```php
add_filter( 'blue_lens_allowed_hosts', fn( $hosts ) => [ ...$hosts, 'fr.example.com' ] );
```

## Database

All tables use `{$wpdb->prefix}bla_`. Times are UTC. Binary keys are 16-byte hashes. JSON columns are `LONGTEXT` (MariaDB-compatible).

| Table | Purpose | Key columns |
|---|---|---|
| `bla_sessions` | One row per visit | `session_key`, `visitor_key`, `channel`, UTM, device, geo, counters |
| `bla_events` | Raw events | PK `(id, occurred_at)` (partition-ready), `event_name`, `session_id`, `attributes`, `context` |
| `bla_leads` | Lead pipeline (from 0.7) | `lead_ref`, `status`, values, attribution |
| `bla_scan_results` | Site-scan findings (from 0.6) | `signal_key`, `confidence`, `evidence` |
| `bla_config` | Versioned configuration | `(config_key, version)`, `is_active` |
| `bla_fx_rates` | Daily exchange rates | `(rate_date, base_currency, quote_currency)` |
| `bla_crawler_daily` | Crawler hits per day/path | `(day, crawler, path_hash)` |
| `bla_heatmap_daily` | Click counts per day/page/device/cell | `(day, path_hash, device, x_bucket, y_bucket)` |
| `bla_daily_traffic` | Daily totals (site time zone) | `day` |
| `bla_daily_dimensions` | Daily session metrics per dimension value | `(day, dimension, value_hash)` |
| `bla_daily_content` | Daily metrics per page | `(day, path_hash)` |
| `bla_daily_events` | Daily counts per event | `(day, event_name)` |
| `bla_audit_runs` | One row per site audit | `status`, `phase`, `health`, `errors`, `warnings`, `notices`, `site_checks`, `summary` (JSON) |
| `bla_audit_pages` | One row per crawled URL per audit | `(run_id, checked)`, `status_code`, `response_ms`, `word_count`, `inlinks`, `issues` and `facts` (JSON) |

Schema changes are numbered migrations in `src/Core/Migrations`. The applied version is stored in `blue_lens_db_version`.

## Background jobs

Jobs use Action Scheduler (group `blue-lens`) when available, otherwise WP-Cron. Recurring jobs: `blue_lens_aggregate` (hourly: today, yesterday, any days skipped since the previous run (up to 31) and 14 days of back-fill), `blue_lens_retention` (daily), `blue_lens_geoip_update` (weekly) and, when `audit_weekly` is on, `blue_lens_audit_weekly`. A running audit queues `blue_lens_audit_step` (one-off, with the run ID) until it finishes. View them under **Tools → Scheduled Actions**, filtered by group `blue-lens`.

## Site audit internals

`Modules\Audit\SiteAudit` runs an audit in phases stored on the `bla_audit_runs` row:

1. **start()** lists URLs with `collect_urls()` (home, published content of public post types by last modified date, the 30 busiest public term archives; filter `blue_lens_audit_urls`) and inserts them as unchecked `bla_audit_pages` rows. Only one audit runs at a time; runs that stop advancing for 6 hours are marked `failed`. The 10 most recent runs are kept.
2. **pages**: `step()` fetches unchecked rows with `wp_remote_request()` (no redirects, 15 s timeout, 3 MB limit, user agent `BlueLensAudit/{version}`, no cookies) and passes the response to `PageAnalyzer::analyze()`, which parses it with `DOMDocument` and returns `facts` and page-level `issues` (code → detail).
3. **links**: internal links found on crawled pages but not crawled themselves (up to 300) are checked with `HEAD` (falling back to `GET` on 403/405/501).
4. **finalize**: adds cross-page issues (`title_duplicate`, `meta_description_duplicate`, `broken_internal_links`, `orphan_page`, `no_recent_visits`), runs `site_checks()` and computes the scores.

Each `step()` holds a lock (option `blue_lens_audit_lock`, 60 s) and works for a time budget: 6 s from `POST /audit/step`, 20 s from the background job. Health = `100 × (1 − (pages with errors + 0.3 × pages with only warnings) ÷ pages)`, minus 10 per site-wide error and 3 per site-wide warning, clamped to 0–100.

Issue definitions live in `AuditCatalog::all()`. To add your own site-wide check:

```php
add_filter( 'blue_lens_audit_issues', function ( $issues ) {
	$issues['no_privacy_page'] = [ 'severity' => 'warning', 'category' => 'technical', 'scope' => 'site', 'title' => 'No privacy policy page', 'fix' => 'Choose one under Settings → Privacy.' ];
	return $issues;
} );
add_filter( 'blue_lens_audit_site_checks', function ( $found ) {
	if ( ! (int) get_option( 'wp_page_for_privacy_policy' ) ) {
		$found['no_privacy_page'] = 1;
	}
	return $found;
} );
```

Categories (used for On-Page SEO ideas): `strategy`, `technical`, `content`, `ux`, `semantic`, `serp`.

## Local Ads integration

`Modules\Ads\LocalAdsReport::available()` is true when Local Ads by Bernard's `Local_Ads_Analytics` API is loaded.

- **Totals, trend, ads and campaigns** come from `Local_Ads_Analytics::totals()`, `daily()`, `by_ad()` and `by_campaign()`, so they match the Local Ads screens exactly.
- **Visit insights** come from Blue Lens events. Local Ads 1.0.1+ dispatches `localads:track` on `document` with `detail: { id, type }` when a popup is shown (`impression`) or its link is clicked (`click`). `assets/integrations/local-ads.js` (loaded only where both the tracker and Local Ads run) turns these into `BlueLens.track( 'ad_impression' | 'ad_click', { entity_type: 'local_ad', entity_id } )` and flushes clicks immediately, because they usually navigate away. Both events are registered with category `advertising`, module `local_ads` and are not conversions.

## Building and testing

```bash
composer install            # PHP dependencies + dev tools
npm install                 # JS tools

npm run build               # minified tracker scripts + gzip size budgets
npm run test:js             # Jest (jsdom)
npm run lint:js             # ESLint (WordPress rules)

cp tests/wp-tests-config-sample.php tests/wp-tests-config.php   # point at an EMPTY database
composer test               # PHPUnit integration tests
vendor/bin/phpunit --group uninstall   # destructive uninstall tests
composer lint               # PHPCS (WordPress Coding Standards)
composer analyse            # PHPStan level 6
```

Size budgets (gzipped): `tracker.min.js` 5 KB, `auto.min.js` 5 KB, `capture.min.js` 3 KB.

### Building a release ZIP

```powershell
powershell -ExecutionPolicy Bypass -File bin/build-zip.ps1
```

`bin/build-zip.ps1` writes `dist/blue-lens-analytics.zip` with a single top-level `blue-lens-analytics/` folder and forward-slash entry names (it does not use `Compress-Archive`, which writes backslashes). It leaves out `docs/`, `bin/`, `dist/`, `tests/`, `.wordpress-org/`, Git files, `README.md`, `CHANGELOG.md`, dev tool configuration, `node_modules/` and `vendor/`. The tracker source (`assets/tracker/src/`) and `package.json` stay in, so the minified scripts can be rebuilt. Without `assets/tracker/build/`, the plugin serves the readable source scripts.

Release checklist: bump the version in the plugin header, `BLA_VERSION`, `readme.txt` (Stable tag and changelog) and `CHANGELOG.md`; run `php -l` on every PHP file; build the ZIP; install it with **Plugins → Add New Plugin → Upload Plugin** on a test site with `WP_DEBUG` on and open every Blue Lens screen; tag `vX.Y.Z` and attach the ZIP to a GitHub release. The asset must be named exactly `blue-lens-analytics.zip`: installed sites only offer releases that have it.

### Updates from GitHub

`Core\GitHubUpdater` delivers releases to installed sites. The `Update URI: https://github.com/Bernard-Ogak/blue_lens_analytics` header makes WordPress skip WordPress.org for this plugin and call the `update_plugins_github.com` filter instead; the updater answers it, for its own basename only, with the version from the latest release's `tag_name` (leading `v` removed) and the URL of its `blue-lens-analytics.zip` asset. Core compares versions, so an equal version is listed as up to date and automatic updates can be switched on. `plugins_api` is filtered for the slug `blue-lens-analytics` to show the release notes under **View details**.

The release is read from `https://api.github.com/repos/Bernard-Ogak/blue_lens_analytics/releases/latest` (drafts and pre-releases are never returned) and cached in the site transient `blue_lens_github_release` for 12 hours, or 1 hour after a failure. `?force-check=1` from **Check again** bypasses the cache. Return `false` from `blue_lens_github_updates` to disable the check.
