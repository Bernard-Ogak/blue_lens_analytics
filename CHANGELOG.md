# Changelog

All notable changes to Blue Lens Analytics. The format follows [Keep a Changelog](https://keepachangelog.com/), and versions follow [Semantic Versioning](https://semver.org/).

## [0.5.0] — 2026-10-03

### Added
- **Site Audit** (Blue Lens → Dashboard → Site Audit). Crawls the site's own pages from the server (home page, published content of every public post type and the busiest category and tag archives, up to `audit_max_pages`, default 250) and checks them:
  - Page errors: 4xx and 5xx responses, pages that cannot be fetched, missing or duplicate title tags, broken internal links, insecure (HTTP) resources on HTTPS pages.
  - Page warnings: missing or duplicate meta descriptions, titles that are too long or too short, missing H1, fewer than 250 words, images without alt text, slow server response (over 1.5 s), HTML over 1 MB, no mobile viewport tag, missing language attribute, orphan pages.
  - Notices: redirects, noindex, missing or foreign canonical, several H1s, long text without subheadings, meta description length, no structured data, no social sharing tags, title and H1 with no shared words, no internal links, and no visits in the last 28 days (from Blue Lens data).
  - Site-wide checks: search engines discouraged, robots.txt blocking everything or missing, no XML sitemap, no HTTPS, plain permalinks, missing pages that do not return 404, PHP errors shown to visitors.
  - A health score, errors, warnings and notices with changes since the previous audit, a crawled-pages breakdown, expandable issues with how to fix them and the affected pages, a crawled-pages table, page details with an "Edit in WordPress" link, health history and CSV export.
  - Audits run in short steps in the background (Action Scheduler or WP-Cron) and faster while the screen is open. Optional weekly audit (`audit_weekly`). The last 10 audits are kept.
- **On-Page SEO** page: ideas grouped as strategy, technical SEO, content, user experience, semantic and SERP features, the most common ideas, and the ten most visited pages that have ideas.
- **Local Ads by Bernard integration**: an Ads page (shown when Local Ads is active) with impressions, clicks and CTR by day, advertisement and campaign, read from Local Ads' own statistics; and, with Local Ads 1.0.1+, `ad_impression` and `ad_click` events linked to visits, giving ad clicks by channel and page, visits with an ad click and how many of them converted. CSV export of ad performance.
- Site audit card on the Overview page.
- Tables `bla_audit_runs` and `bla_audit_pages` (migration 5).
- REST endpoints `GET /reports/local-ads`, `GET /audit`, `GET /audit/issue`, `GET /audit/pages`, `GET /audit/page`, `POST /audit/start`, `POST /audit/step`, `POST /audit/cancel`.
- Filters `blue_lens_local_ads_tracking`, `blue_lens_audit_issues`, `blue_lens_audit_urls`, `blue_lens_audit_site_checks`; actions `blue_lens_audit_started`, `blue_lens_audit_completed`.
- Settings `audit_max_pages` and `audit_weekly`, with a Site audit section on the settings screen.
- Admin footer credit on Blue Lens screens only.

### Changed
- New dashboard layout: a left sidebar grouped into Analytics, SEO, Advertising and Manage, and a page header with the date controls.
- Clean light theme and subtle metric tiles are now the default for users who have not chosen their own; the dark theme is still available. Flatter cards, hairline borders and a deeper blue accent.
- The site audit's own requests are not counted in the search engine and AI crawler log.
- Plugin URI points to the GitHub repository and Author URI to creativebay.co.ke.
- Documentation files renamed to lowercase (`docs/user-guide.md`, `docs/developer-guide.md`, `docs/installation.md`, `docs/privacy.md`, `docs/faq.md`); the dashboard and readme.txt link to them on GitHub, because `docs/` is not part of the release ZIP.

## [0.4.0] — 2026-09-29

### Added
- **Dashboards** (Blue Lens → Dashboard): Overview, Acquisition, Audience, Content, Engagement and Heatmaps pages, built on WordPress's bundled React and components, with no third-party chart library.
  - Overview: key metric tiles with trends and period comparison, traffic trend chart, real-time visitors with a live activity feed, channels, top sources, pages, countries, devices and conversions.
  - Acquisition: channel performance table, sources, campaigns, referring websites, landing pages, and search/AI crawler activity with 404s found by crawlers.
  - Audience: countries, regions and cities (with flags), devices, screen sizes, browsers, operating systems, languages, new vs returning.
  - Content: performance by page, content type, author, any public taxonomy and content age; entry and exit pages.
  - Engagement: forms, contacts, downloads, outbound links, video, search, rage and dead clicks, plus a full events table.
  - Heatmaps: click heatmaps overlaid on a live preview of each page, per device.
- **Dark and light themes**, six accent colours, comfortable or compact density, vivid or subtle metric tiles, and a choice of which metric tiles and which dashboard cards to show and in what order. Saved per user.
- Date range presets and custom ranges, comparison with the previous period or last year, table view for every chart, and CSV export for every report.
- **Settings screen** (Blue Lens → Settings) covering every option, with explanations and unsaved-change protection.
- Daily summary tables (`bla_daily_traffic`, `bla_daily_dimensions`, `bla_daily_content`, `bla_daily_events`; migration 4), rebuilt hourly in the site's time zone and back-filled automatically.
- Automatic data retention (default 13 months of detailed data), run daily in small batches.
- `view_blue_lens_reports` capability so reports can be shared with other roles (e.g. editors or shop managers) without settings access, via the `blue_lens_report_roles` filter.
- REST endpoints under `blue-lens/v1/reports/*` and `/preferences`.

### Changed
- The Status screen moved to Blue Lens → Status.
- Heatmap previews (`?bla_heatmap=1`) are never tracked and hide the admin bar.

## [0.3.0] — 2026-09-29

### Added
- Automatic events: outbound links, file downloads, phone/email/SMS/WhatsApp/Messenger/Telegram clicks, CTA clicks via `data-bla-event` or configured CSS selectors, site search (with zero-result flag), 404 pages.
- Form analytics: `form_start`, `form_abandon` (last field reached), `form_submit`. Server-side success hooks for Contact Form 7, Gravity Forms, WPForms, Fluent Forms, Elementor Pro Forms, Formidable and Ninja Forms; browser-side submit for plain HTML forms. Field values are never recorded.
- Video tracking for YouTube, Vimeo and HTML5 video (start, 25/50/75%, complete).
- Autocapture of clicks on interactive elements, rage clicks, dead clicks and copy events (kind and length only).
- Click heatmaps stored as daily per-page, per-device grid counts (new table `bla_heatmap_daily`, migration 3).
- Optional JavaScript error and Core Web Vitals (LCP, INP, CLS) events.
- `gpc_action` setting: Global Privacy Control can switch visitors to cookieless mode (default) or stop tracking.
- The enhanced-mode cookie `bla_vid` is registered with the WP Consent API.
- Server-side events from logged-in users with excluded roles are skipped.

### Changed
- Stored page paths keep content-identifying query parameters (`?page_id=`, `?p=`…) on sites without pretty permalinks.
- Event JSON is stored without escaped slashes or Unicode (smaller rows).
- Keyboard-activated clicks no longer count as rage clicks or heatmap points.

## [0.2.0] — 2026-09-29

### Added
- Core tracker (under 4 KB gzipped): page views, engaged time with heartbeats, scroll depth, single-page-app navigation, `sendBeacon` with `fetch(keepalive)` fallback.
- Cookieless visitor codes from a daily-rotating salt; optional enhanced mode with a consented first-party ID.
- Public collection endpoint `POST /blue-lens/v1/collect` with host checks, payload limits, rate limiting and a per-session event cap.
- Sessions with channel grouping (including AI assistants and OTA/listing sites), UTM and click-ID capture, device/browser/OS parsing, GeoIP (MaxMind GeoLite2 or DB-IP Lite) and Cloudflare country fallback.
- WordPress page context: post, type, taxonomies, author, dates, template, page builder, language.
- PII scrubbing of URLs, titles and event properties.
- Bot filtering and a search-engine/AI crawler log (`bla_crawler_daily`, migration 2).
- `blue_lens_track()` PHP API for server-side events.
- Cache-plugin compatibility (WP Rocket, LiteSpeed, SiteGround Optimizer, Autoptimize, Cloudflare Rocket Loader).

## [0.1.0] — 2026-09-29

### Added
- Plugin foundation: bootstrap with requirement checks, service container, settings with schema validation, `manage_blue_lens` capability, versioned database migrations with locking, core tables, multisite support, opt-in uninstall.
