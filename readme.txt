=== Blue Lens Analytics ===
Contributors: bernardogak
Tags: analytics, privacy, seo audit, cookieless, heatmap
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first, self-hosted analytics with an SEO site audit. Cookieless by default, no IPs stored, and works on cached pages.

== Description ==

Blue Lens Analytics shows how visitors find your website and what they do there, while keeping all data in your own WordPress database.

**Privacy by design**

* Cookieless by default. Visitors are counted with an anonymous code that changes daily; nothing is stored on their device.
* IP addresses are never stored. Emails, phone numbers and tokens are stripped from URLs and event data automatically.
* Optional enhanced mode recognises returning visitors, only after consent (WP Consent API supported).
* Honours Global Privacy Control, and optionally Do Not Track.
* No data is sent to the plugin authors or any third party.

**Everything visitors do**

* Page views, engaged time, scroll depth, single-page-app navigation.
* Traffic sources: search, paid search, social, email, referral, AI assistants (ChatGPT, Perplexity, Gemini, Claude) and OTA/listing sites (Booking.com, TripAdvisor, SafariBookings, Expedia).
* Outbound links, file downloads, phone/email/WhatsApp clicks, CTA buttons, site search (including zero results), 404 pages.
* Form starts, abandonment (last field reached) and submissions for Contact Form 7, Gravity Forms, WPForms, Fluent Forms, Elementor Forms, Formidable and Ninja Forms. Field values are never recorded.
* YouTube, Vimeo and HTML5 video engagement.
* Autocapture of clicks, rage clicks, dead clicks and copy events, plus click heatmaps.
* Optional JavaScript errors and Core Web Vitals.
* A separate log of search-engine and AI crawler visits.

**Site Audit and On-Page SEO**

* Crawls your own pages from your server and checks more than 35 technical and on-page factors: broken pages and links, redirects, duplicate titles and descriptions, mixed content, missing H1, thin content, image alt text, canonical and noindex tags, structured data, social sharing tags, orphan pages, robots.txt, XML sitemap, HTTPS and more.
* A site health score, errors, warnings and notices, a crawled-pages breakdown and health history.
* On-Page SEO ideas grouped as strategy, technical SEO, content, user experience, semantic and SERP features, with your most visited pages listed first.
* Plain-language fixes for every issue, page details with an "Edit in WordPress" link, CSV export and an optional weekly audit.

**Local Ads by Bernard reporting**

* When Local Ads by Bernard is active, an Ads page shows impressions, clicks and CTR by day, ad and campaign.
* With Local Ads 1.0.1 or later, ad clicks are linked to visits, so you see which channels and pages lead to clicks and how many ad clickers convert.

**Beautiful, customisable dashboards**

* Overview, Acquisition, Audience, Content, Engagement, Heatmaps, Site Audit, On-Page SEO and Ads pages, with a clean sidebar layout.
* Light and dark themes, six accent colours, compact or comfortable density.
* Choose your metric tiles and which cards appear, in your order. Saved per user.
* Real-time visitors, period comparisons, a table view for every chart, and CSV export.
* Share dashboards with editors or clients without giving them settings access.

**Fast and compatible**

* Core script under 4 KB gzipped, deferred, never render-blocking.
* Works with full-page caching and optimisation plugins (WP Rocket, LiteSpeed Cache, W3 Total Cache, SiteGround Optimizer, Autoptimize, Cloudflare).
* Works with Gutenberg, Elementor, Divi, Bricks, WPML and Polylang. Multisite ready.

**For developers**

JavaScript (`BlueLens.track()`) and PHP (`blue_lens_track()`) APIs, a REST API, and actions and filters at every stage. See the developer guide: https://github.com/Bernard-Ogak/blue_lens_analytics/blob/main/docs/developer-guide.md.

== Installation ==

1. Upload the `blue-lens-analytics` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New Plugin → Upload Plugin.
2. Activate the plugin.
3. Visit your site in a private window, then open Blue Lens → Dashboard and press Refresh to see your visit.

Optional: set up a free GeoIP database (MaxMind GeoLite2 or DB-IP Lite) for country, region and city. See the installation guide: https://github.com/Bernard-Ogak/blue_lens_analytics/blob/main/docs/installation.md.

== Frequently Asked Questions ==

= Do I need a cookie banner for this plugin? =

In the default cookieless mode Blue Lens sets no cookies and stores nothing on the visitor's device. Enhanced mode uses a cookie only after consent. Check with your legal adviser for your situation.

= Why don't I see my own visits? =

Administrators and editors are excluded by default. Test in a private window.

= Does it store IP addresses? =

No. IPs are used in memory for the anonymous daily code, exclusions and location lookup, then discarded.

= Does it record what people type in forms? =

Never. Only form IDs, field names reached and successful submissions.

= Does it work with page caching? =

Yes. Cached pages contain no visitor-specific data or nonces.

= Every visitor looks the same. Why? =

Your site is probably behind a CDN or proxy. Set the `ip_header` setting (see https://github.com/Bernard-Ogak/blue_lens_analytics/blob/main/docs/installation.md).

= Is my data sent anywhere? =

No. Everything stays in your WordPress database. The optional GeoIP download sends no visitor data.

= What happens when I delete the plugin? =

Your data is kept unless you enabled "delete data on uninstall" first.

== Screenshots ==

1. Overview: key metrics with trends, traffic trend with period comparison and real-time visitors, in the clean light theme.
2. Site Audit: site health score, errors, warnings and notices with changes since the last audit, crawled pages and the issue list.
3. On-Page SEO: ideas by category, the most common ideas, and the most visited pages to optimise first.
4. Ads: Local Ads impressions, clicks and CTR, plus visits with an ad click and how many converted.
5. Site Audit page details: every issue on a page with how to fix it, and the page's facts.
6. Acquisition: channel performance, sources, campaigns and referring websites.
7. Engagement: forms, contacts, downloads, outbound links and every event.
8. Overview in the dark theme.
9. Settings: the Site audit section, with plain-language explanations.

== Changelog ==

= 0.5.0 =
* Added: Site Audit with a health score, errors, warnings and notices, crawled pages, internal link checks, site-wide checks (robots.txt, sitemap, HTTPS, search engine visibility, soft 404s) and health history.
* Added: On-Page SEO ideas by category, and top pages to optimise ranked by real visits.
* Added: Local Ads by Bernard integration: an Ads page with impressions, clicks and CTR, plus ad clicks by channel and page, and ad-click conversions.
* Changed: new sidebar layout and a clean light theme by default; dark theme still available.
* Changed: admin footer credit on Blue Lens screens.

= 0.4.0 =
* Added: dashboards (Overview, Acquisition, Audience, Content, Engagement, Heatmaps) with dark/light themes and per-user customisation.
* Added: settings screen, CSV export, real-time card, heatmap viewer, report sharing capability.
* Added: hourly daily summaries in the site time zone and automatic data retention.

= 0.3.0 =
* Added: automatic events (outbound, downloads, contact clicks, CTAs, site search, 404s), form analytics with server-side hooks for 7 form plugins, video tracking, autocapture, rage/dead clicks, click heatmaps, optional JS errors and Web Vitals.
* Added: GPC action setting; WP Consent API cookie registration.
* Changed: plain-permalink paths keep page identifiers; keyboard clicks excluded from rage clicks and heatmaps.

= 0.2.0 =
* Added: cookieless tracker, collection endpoint, sessions, channel grouping, GeoIP, bot filtering, crawler log, PHP tracking API, cache compatibility.

= 0.1.0 =
* Initial foundation: settings, capability, migrations, core tables, multisite, opt-in uninstall.

== Upgrade Notice ==

= 0.5.0 =
Adds Site Audit, On-Page SEO ideas and Local Ads reporting. Two database tables are added automatically.

= 0.4.0 =
Adds the dashboards and settings screen. Summaries of your existing data are built automatically in the background.

= 0.3.0 =
Adds form, click, video and heatmap tracking. The database updates automatically on the next page load.

== External services ==

Blue Lens Analytics does not contact any external service by default. Only if a site administrator chooses a GeoIP provider in the settings does it download a location database from one of these services:

**MaxMind GeoLite2** (used when `geoip_provider` is set to `maxmind`)

* What it is for: downloading the GeoLite2 City database, used to look up visitors' approximate country, region and city on your own server.
* What is sent and when: once a week, and when the administrator clicks "Update GeoIP database now", the site sends the administrator's MaxMind account ID and licence key to `download.maxmind.com`. No visitor data is ever sent.
* Terms: https://www.maxmind.com/en/geolite2/eula
* Privacy policy: https://www.maxmind.com/en/privacy-policy

**DB-IP Lite** (used when `geoip_provider` is set to `dbip`)

* What it is for: downloading the free IP to City Lite database for the same purpose.
* What is sent and when: once a month, and when the administrator clicks "Update GeoIP database now", the site requests the database file from `download.db-ip.com`. Only a standard download request is made; no visitor data or credentials are sent.
* Licence (CC BY 4.0) and terms: https://db-ip.com/db/lite.php
* Privacy policy: https://db-ip.com/privacy.php

== Privacy ==

Blue Lens Analytics stores analytics data only in your WordPress database. It does not store IP addresses, form contents or other directly identifying data, and it sends no data to external services unless you enable an optional GeoIP download. See https://github.com/Bernard-Ogak/blue_lens_analytics/blob/main/docs/privacy.md for full details and privacy-policy template text.

== Author ==

Bernard Ogak, Creative Bay: https://www.creativebay.co.ke

Source code and issues: https://github.com/Bernard-Ogak/blue_lens_analytics
