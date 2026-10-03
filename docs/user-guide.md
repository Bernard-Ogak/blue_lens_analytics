# User guide

This guide is for site owners and administrators. For code-level details, see [developer-guide.md](developer-guide.md).

## Contents

1. [How Blue Lens works](#how-blue-lens-works)
2. [Using the dashboard](#using-the-dashboard)
3. [What is tracked](#what-is-tracked)
4. [Privacy modes and signals](#privacy-modes-and-signals)
5. [Traffic channels](#traffic-channels)
6. [Forms](#forms)
7. [Tracking your own buttons (CTAs)](#tracking-your-own-buttons-ctas)
8. [Heatmaps and autocapture](#heatmaps-and-autocapture)
9. [Site Audit](#site-audit)
10. [On-Page SEO](#on-page-seo)
11. [Ads (Local Ads by Bernard)](#ads-local-ads-by-bernard)
12. [Search engine and AI crawler log](#search-engine-and-ai-crawler-log)
13. [Excluding visits](#excluding-visits)
14. [Settings reference](#settings-reference)
15. [Changing settings](#changing-settings)
16. [The Status screen](#the-status-screen)
17. [Troubleshooting](#troubleshooting)

---

## How Blue Lens works

1. Each page includes a small, deferred script (under 4 KB gzipped) and a short block of page information (post ID, post type, categories, author, template, page builder, language).
2. As visitors browse, the script sends small batches of events to your own site, at `/wp-json/blue-lens/v1/collect`.
3. WordPress checks each batch, removes anything that looks like personal data, and stores it in Blue Lens's own database tables.
4. Extra features (links, forms, video, autocapture, heatmaps) live in separate small scripts that load after the page has finished loading, and only when switched on.

Nothing is sent to any third party. Your analytics data never leaves your server.

Every hour, Blue Lens adds up the day's activity into daily summaries (in your site's time zone, set under **Settings → General**). The dashboards read those summaries, so they stay fast however much traffic you have. Press **Refresh** in the dashboard to update today's figures immediately.

## Using the dashboard

Open **Blue Lens → Dashboard**. The sidebar on the left lists the pages, grouped into **Analytics**, **SEO**, **Advertising** (when Local Ads by Bernard is active) and **Manage**. The header shows the page you are on and the controls that apply to it.

| Page | What it answers |
|---|---|
| **Overview** | How is the site doing? Key metrics with trends, traffic over time, who is on the site right now, channels, top sources, pages, countries, devices and conversions. |
| **Acquisition** | Where do visitors come from, and which sources bring results? Channels, sources, campaigns, referring websites, landing pages, and search engine and AI crawler visits. |
| **Audience** | Who are the visitors? Countries, regions and cities, devices, screen sizes, browsers, operating systems, languages, new vs returning. |
| **Content** | Which pages work? Views, reading time, entrances, exits and conversions, grouped by page, content type, author, category or tag, or content age. |
| **Engagement** | What do visitors do? Forms started, abandoned and submitted, contact taps, downloads, videos, searches, rage and dead clicks, and every event. |
| **Heatmaps** | Where do people click? Pick a page and a device to see clicks over a live preview of the page. |
| **Site Audit** | Is the site technically healthy? A health score, errors, warnings and notices, crawled pages and how to fix each issue. See [Site Audit](#site-audit). |
| **On-Page SEO** | What should I improve on each page? Ideas by category and the most visited pages to optimise first. See [On-Page SEO](#on-page-seo). |
| **Ads** | How are my Local Ads popups doing? Impressions, clicks, CTR and the visits behind them. See [Ads](#ads-local-ads-by-bernard). |

**Controls**
- **Date range:** presets (today, last 7/28/30/90 days, this or last month, year to date, last 12 months) or a custom range.
- **Comparison:** against the previous period of the same length, the same period last year, or none. Metric tiles show the change with an arrow; green means better, and for bounce rate lower is better.
- **Refresh:** recalculates today's figures now.
- **Table view** (grid icon on a card) shows the numbers behind any chart; **Export** (download icon) saves a CSV that opens in Excel or Google Sheets.
- **Theme** (moon/sun icon) switches between light (the default) and dark.

The date range and comparison don't apply to Site Audit and On-Page SEO, which always show the latest audit.

**Customize** (top right) lets each user choose:
- light or dark theme, accent colour and density (comfortable or compact);
- subtle (the default) or vivid (coloured) metric tiles;
- which metric tiles appear on the Overview, up to 8, in the order picked;
- which cards appear on each page, and their order;
- the date range and comparison the dashboard opens with.

Choices are saved to your user account, so each person keeps their own layout. **Reset to defaults** undoes everything.

**Sharing reports with staff or clients.** Administrators can see everything. To let other roles see the dashboards *without* changing settings, add this to a small plugin or your theme's `functions.php`, then deactivate and reactivate Blue Lens once:

```php
add_filter( 'blue_lens_report_roles', fn( $roles ) => array_merge( $roles, [ 'editor', 'shop_manager' ] ) );
```

## What is tracked

### Visits (sessions)

A **session** is one visit: a series of page views with gaps of less than 30 minutes (adjustable). A new advertising campaign link also starts a new session. For each session Blue Lens records:

- landing and exit page, number of pages, duration and **engaged time** (time the page was visible and the visitor active)
- **engaged session** flag: more than 10 seconds engaged, 2 or more pages, or a conversion
- traffic source: referring website, UTM campaign tags, [channel](#traffic-channels), and the type of ad click (Google, Microsoft, Meta, TikTok)
- device type, browser and operating system (major versions only), screen-size group, browser language, time zone
- country, region and city (with a [GeoIP database](installation.md#5-optional-location-data-geoip))
- new vs returning visitor (enhanced mode only)

### Events

| Event | When it is recorded | Details kept |
|---|---|---|
| `page_view` | A page loads (including single-page-app navigation) | path, title, post/page information |
| `page_engagement` | The visitor leaves or hides the page | engaged seconds, scroll depth (25/50/75/100%) |
| `outbound_click` | A link to another website is clicked | domain and path |
| `file_download` | A link to a PDF, ZIP, DOCX etc. is clicked | file name and type |
| `contact_click` | A phone, email, SMS, WhatsApp, Messenger or Telegram link is clicked | method and link text (numbers masked) |
| `cta_click` or your own name | A [tagged button](#tracking-your-own-buttons-ctas) is clicked | your label and properties |
| `site_search` | Someone uses your site search | search term (dropped if it looks like personal data), result count, zero-result flag |
| `page_not_found` | A 404 page is shown | where the visitor came from |
| `form_start` | A visitor starts filling in a form | form ID, first field name |
| `form_abandon` | A started form is left unsubmitted | form ID, last field reached, number of fields touched |
| `form_submit` | A form is submitted successfully | form ID, title, number of fields |
| `video_start` / `video_progress` / `video_complete` | YouTube, Vimeo or HTML5 video plays; 25/50/75% watched; finished | provider, video ID, title |
| `click` | Any button, link or control is clicked (autocapture) | element type, visible label, short element identifier |
| `rage_click` | Three quick clicks on the same spot | element identifier and label |
| `dead_click` | A button is clicked but nothing happens | element identifier and label |
| `copy_text` | Text is copied from the page | number of characters and whether it looked like a phone number, email or text (never the text itself) |
| `js_error` | A JavaScript error happens (off by default) | message (personal data masked), file, line |
| `web_vitals` | Page-speed measurements (off by default) | LCP, INP, CLS |
| `ad_impression` / `ad_click` | A Local Ads by Bernard popup is shown or clicked (Local Ads 1.0.1+) | the advertisement ID |

**Never recorded:** anything typed into a form, passwords, IP addresses, names, email addresses, phone numbers, payment details or ID numbers. See [privacy.md](privacy.md).

## Privacy modes and signals

### Cookieless mode (default)

Visitors are counted using an anonymous code, calculated from a shortened IP address, the browser type and a secret that changes every day. The code can't be reversed and changes at midnight UTC, so the same person can't be followed from one day to the next. Nothing is stored on the visitor's device, so **no cookie consent is needed for this mode in most jurisdictions**. Ask your legal adviser about your own situation.

Trade-offs: you can't tell new from returning visitors, and a visit that runs past midnight UTC counts as two sessions.

### Enhanced mode (with consent)

Set `privacy_mode` to `enhanced`. **Only after a visitor gives statistics consent**, Blue Lens sets a first-party cookie, `bla_vid`, containing a random ID. It lasts 13 months and is removed immediately if consent is withdrawn. This adds:

- returning-visitor recognition across days
- storage of the full ad click ID (needed later for Google Ads / Meta conversion imports)

How consent is detected, in order:

1. `BlueLens.consent(true)` / `BlueLens.consent(false)` called by your cookie banner, or
2. the **WP Consent API** "statistics" category. This is supported by Complianz, CookieYes, Cookiebot, Borlabs and others through their WP Consent API integration.

Without consent, enhanced mode behaves exactly like cookieless mode.

### Global Privacy Control (GPC)

GPC is a browser signal meaning "do not sell or share my data". With `respect_gpc` on (the default):

- `gpc_action: cookieless` (default): the visitor is always treated as cookieless, with no cookie, no stored ad click ID, and exclusion from any future ad-platform exports.
- `gpc_action: stop`: nothing is tracked for that visitor.

### Do Not Track (DNT)

DNT is an older signal that browsers have largely abandoned. It is ignored by default. Turn on `respect_dnt` to stop tracking visitors who send it.

### Visitor opt-out

You can offer an opt-out link on your privacy page:

```html
<a href="#" onclick="BlueLens.optOut(); alert('You have opted out of analytics on this site.'); return false;">Opt out of analytics</a>
```

The choice is remembered in the visitor's browser. `BlueLens.optIn()` reverses it.

## Traffic channels

Each session gets one channel:

| Channel | Examples |
|---|---|
| `organic_search` | Google, Bing, DuckDuckGo, Yandex, Ecosia |
| `paid_search` | `gclid`/`msclkid` links, or `utm_medium=cpc` |
| `paid_social` | `utm_medium=paid_social`, or `cpc` from Facebook/Instagram/TikTok |
| `social` | Facebook, Instagram, X, LinkedIn, YouTube, TikTok, WhatsApp, Reddit |
| `ai_assistant` | ChatGPT, Perplexity, Gemini, Claude, Copilot |
| `ota_listing` | Booking.com, Expedia, TripAdvisor, Airbnb, Viator, GetYourGuide, SafariBookings, TourRadar |
| `email` | `utm_medium=email`, Gmail/Outlook webmail |
| `display` | `utm_medium=display/banner/cpm` |
| `affiliate` | `utm_medium=affiliate` |
| `referral` | any other website |
| `direct` | no referrer and no campaign tags |

**Tip:** tag every link you control (newsletters, social bios, partner sites, QR codes) with UTM parameters, e.g. `?utm_source=newsletter&utm_medium=email&utm_campaign=october-offers`.

## Forms

- **Supported form plugins** (Contact Form 7, Gravity Forms, WPForms, Fluent Forms, Elementor Pro Forms, Formidable, Ninja Forms): submissions are recorded by the server only when the form plugin reports success. Failed validation and spam blocks are not counted.
- **Other forms:** starts and abandonments are tracked in the browser, and a submission is counted when the form is sent.
- Form IDs look like `cf7:42`, `gf:3`, `wpforms:7`, `elementor:a1b2c3` or `html:contact-form`, so you can match them to your forms.
- Submissions count as **conversions** by default. From version 0.6, onboarding lets you choose which forms are conversions (for example, excluding newsletter sign-ups).
- Logged-in administrators and editors testing forms are not counted.

## Tracking your own buttons (CTAs)

Add a `data-bla-event` attribute to any element:

```html
<button data-bla-event="brochure_request">Get the brochure</button>

<a href="/book/" data-bla-event="book_now_click"
   data-bla-props='{"tour":"mara-3-day","position":"hero"}'>Book now</a>

<div data-bla-event="" data-bla-category="cta">Generic CTA (recorded as cta_click)</div>
```

- Event names use lowercase letters, numbers and underscores (`brochure_request`).
- `data-bla-props` takes JSON properties (up to 25 keys; text up to 200 characters).
- **Page builders:** in Elementor, Divi, Bricks or Gutenberg, add the attribute in the element's "Custom attributes" or "HTML attributes" panel (e.g. Elementor → Advanced → Attributes: `data-bla-event|book_now_click`).
- **No access to the HTML?** Add CSS selectors to the `cta_selectors` setting, e.g. `.book-now` or `#enquire-button`. Matching clicks are recorded as `cta_click`.

## Heatmaps and autocapture

- **Autocapture** (`autocapture`, on by default) records clicks on buttons, links and other controls, with their visible label and a short identifier such as `#main-nav > a.menu-link`. It is capped at 100 events per page view. Add `data-bla-ignore` to any area that should never be captured.
- **Heatmaps** (`heatmaps`, on by default) store each click as a point on a grid (1% of page width × 20 px of height), separately for desktop, tablet and mobile. Points are stored as daily totals with no link to the visitor. Keyboard presses are not counted.
- View them under **Blue Lens → Dashboard → Heatmaps**: choose a device and a page, and the clicks are drawn over a live preview (blue = few clicks, red = many). The preview is loaded with `?bla_heatmap=1`, which is never tracked.

## Site Audit

**Blue Lens → Dashboard → Site Audit** checks your website the way a search engine sees it and lists what to fix.

### Running an audit

Click **Run first audit** (later **Re-run audit**). Only users who can manage Blue Lens can start an audit; anyone who can view reports can read the results.

1. Blue Lens lists the URLs to check from WordPress itself: the home page, published content of every public post type (most recently updated first, password-protected content skipped) and the 30 busiest category and tag archives, up to **Most pages per audit** (`audit_max_pages`, default 250).
2. It requests each page from your own server as an anonymous visitor (user agent `BlueLensAudit`), without following redirects, and analyses the HTML.
3. It checks internal links that point to pages outside that list (up to 300) to find broken links.
4. It adds the checks that compare pages (duplicates, orphans, broken links, visits), runs the site-wide checks and calculates the health score.

The work runs in short steps in the background, and faster while the Site Audit screen is open. A progress bar shows the current stage and you can **Cancel** at any time. Nothing is sent to outside services, the audit's own requests are not counted as visits or in the crawler log, and the last 10 audits are kept.

To run an audit every week automatically, turn on **Settings → Site audit → Run an audit every week** (`audit_weekly`).

### Reading the results

- **Site health** (0–100%): `100 × (1 − (pages with errors + 0.3 × pages with only warnings) ÷ pages crawled)`, minus 10 points for each site-wide error and 3 for each site-wide warning. The change since the previous audit is shown under the gauge.
- **Issues found**: how many errors, warnings and notices were found, with the change since the previous audit. Click a line to show that group in the issue list.
- **Crawled pages**: the number of pages checked, split into healthy, with warnings, with errors, redirects and broken (no response, or a 4xx/5xx status). Click a group to filter the pages table.
- **Issues**: switch between errors, warnings and notices. Expand an issue to see how to fix it and which pages have it; click a page to open its details.
- **Crawled pages** table: status, server response time, words, how many crawled pages link to it ("Links in"), its errors, warnings and notices, and page views in the last 28 days. Export it as CSV with the download icon.
- **Page details**: every issue on the page with how to fix it, plus the title, meta description, H1, word count, images without alt text, canonical URL, structured data and page views. **Edit in WordPress** opens the post or page.
- **Health over time** appears after your second audit.

### What is checked

| Severity | Checks |
|---|---|
| **Errors** | Page returns 4xx or 5xx · page could not be fetched · missing title tag · duplicate title tag · broken internal links · insecure (HTTP) images, scripts, styles or frames on an HTTPS page · search engines discouraged (Settings → Reading) · robots.txt blocks the whole site |
| **Warnings** | Missing or duplicate meta description · title over 60 or under 20 characters · missing H1 · fewer than 250 words (posts and pages only) · images without alt text · server response over 1.5 seconds · HTML over 1 MB · no mobile viewport tag · missing `lang` attribute · orphan page (no crawled page links to it) · no HTTPS · no XML sitemap (`wp-sitemap.xml`, `sitemap_index.xml` or `sitemap.xml`) · plain permalinks · missing pages do not return 404 · PHP errors shown to visitors (`WP_DEBUG_DISPLAY`) |
| **Notices** | Redirects · noindex · no canonical URL, or canonical pointing elsewhere · more than one H1 · over 600 words without H2 subheadings · meta description outside 70–160 characters · no structured data (JSON-LD or microdata) · no `og:title`/`og:image` · title and H1 share no words · no links to other pages on the site · no visits in the last 28 days (only once Blue Lens has 28 days of data) · no robots.txt |

Notes:
- The audit sees what an anonymous visitor sees. Pages behind a login, or blocked by a security plugin or firewall for requests from the server itself, show as broken or "could not be fetched".
- Response times are measured from your own server, so they show how fast WordPress builds the page, not network speed for visitors.
- Many WordPress themes need an SEO plugin to output meta descriptions, social tags and structured data; those notices disappear once one is configured.

## On-Page SEO

**Blue Lens → Dashboard → On-Page SEO** turns the latest audit into a to-do list for each page. It needs at least one completed audit.

- **On-page SEO ideas**: every issue found is an idea, grouped as **strategy** (orphan pages, no internal links, no recent visits), **technical SEO**, **content** (titles, headings, word count), **user experience** (speed, mobile, mixed content), **semantic** (alt text, language, title/H1 focus) and **SERP features** (meta descriptions, structured data, social tags). Below the chart are the most common ideas and how many pages have them.
- **Top pages to optimise**: your ten most visited pages (page views in the last 28 days, from Blue Lens) that have ideas. Fixing these first helps the most visitors. Click a page or its number of ideas to open the page details.
- One card per category lists its ideas; expand one to see how to fix it and which pages it affects.

## Ads (Local Ads by Bernard)

When [Local Ads by Bernard](https://github.com/Bernard-Ogak/local-ad) is active, an **Ads** page appears under **Advertising**. It uses the dashboard's date range.

- **Ad performance**: impressions, clicks and click-through rate, read from Local Ads' own statistics, so they always match the Local Ads screens. **Open Local Ads** goes to its Analytics screen.
- **Ad trend**: impressions or clicks per day, with a table view and CSV export.
- **Advertisements** and **Campaigns**: impressions, clicks and CTR for each ad and campaign. Export the per-ad table as CSV.

With Local Ads **1.0.1 or later**, Blue Lens also records an `ad_impression` and `ad_click` event each time a popup is shown or clicked, as part of the visitor's session. This adds:

- **Visits with an ad click** and **Of those, converted** (visits with an ad click that also had a conversion, such as a form submission);
- **Ad clicks by channel**: which traffic sources click your ads;
- **Pages where ads are seen**: impressions, clicks and CTR per page.

These ad events follow the same rules as all other Blue Lens tracking: they respect privacy mode, consent, GPC and exclusions, and they only store the ad ID. They start from the moment both versions are active, so earlier ad activity appears in the Local Ads totals only. To stop recording them, add `add_filter( 'blue_lens_local_ads_tracking', '__return_false' );`.

## Search engine and AI crawler log

Crawlers don't run JavaScript, so Blue Lens counts them on the server. It records daily hits per crawler and page, plus whether the page returned a 404. It recognises Google, Bing, Yandex, Baidu, DuckDuckGo, Apple, OpenAI (GPTBot, ChatGPT-User, OAI-SearchBot), Anthropic (ClaudeBot, Claude-User, Claude-SearchBot), Perplexity, Common Crawl, ByteDance, Amazon, Meta, Ahrefs, Semrush, Majestic and link-preview bots.

Notes: pages served straight from a full-page cache never reach WordPress, so those crawler hits aren't counted. Crawlers are identified by the name they announce; fake bots are not detected. Turn off with `log_crawlers`.

All bots are always excluded from visitor analytics.

## Excluding visits

| Setting | Effect |
|---|---|
| `excluded_roles` | Logged-in users with these roles are never tracked (default: administrator, editor) |
| `excluded_ips` | Office or staff IP addresses or ranges, e.g. `197.232.10.4` or `10.0.0.0/8` |
| `excluded_paths` | Pages to skip, `*` as wildcard, e.g. `/checkout/*` or `/my-account/*` |

## Settings reference

| Setting | Default | Description |
|---|---|---|
| `tracking_enabled` | `true` | Master switch |
| `privacy_mode` | `cookieless` | `cookieless` or `enhanced` (cookie after consent) |
| `respect_gpc` | `true` | Honour Global Privacy Control |
| `gpc_action` | `cookieless` | `cookieless` or `stop` for GPC visitors |
| `respect_dnt` | `false` | Stop tracking visitors who send Do Not Track |
| `excluded_roles` | administrator, editor | Roles never tracked |
| `excluded_ips` | – | IPs / CIDR ranges never tracked |
| `excluded_paths` | – | Path patterns never tracked |
| `session_timeout_minutes` | `30` | Inactivity that ends a session (5–240) |
| `heartbeat_seconds` | `30` | How often engaged time is sent while reading (0 = only on exit) |
| `spa_tracking` | `true` | Track page changes in single-page apps |
| `track_links` | `true` | Outbound, download, contact and CTA clicks, site search, 404s |
| `track_forms` | `true` | Form start, abandon, submit |
| `track_video` | `true` | YouTube, Vimeo and HTML5 video |
| `autocapture` | `true` | All clicks, rage and dead clicks, copy |
| `heatmaps` | `true` | Click heatmaps |
| `track_errors` | `false` | JavaScript errors |
| `web_vitals` | `false` | LCP / INP / CLS page-speed measurements |
| `download_extensions` | pdf, docx, zip… | File types counted as downloads |
| `cta_selectors` | – | CSS selectors counted as CTA clicks |
| `log_crawlers` | `true` | Search engine / AI crawler log |
| `ip_header` | `remote_addr` | Where to read the visitor IP ([proxy set-up](installation.md#6-sites-behind-a-proxy-or-cdn)) |
| `geoip_provider` | `none` | `none`, `maxmind` or `dbip` |
| `maxmind_account_id`, `maxmind_license_key` | – | MaxMind credentials (key shown masked) |
| `base_currency` | USD (or WooCommerce currency) | Currency that reported values are converted to |
| `retention_raw_months` | `13` | How long detailed events are kept; older data is deleted daily |
| `retention_aggregate_months` | `0` | How long summaries are kept (0 = forever) |
| `audit_max_pages` | `250` | Most pages checked per site audit (10–2000) |
| `audit_weekly` | `false` | Run a site audit automatically every week |
| `delete_data_on_uninstall` | `false` | Remove all data when the plugin is deleted |

## Changing settings

Open **Blue Lens → Settings**. Settings are grouped into General, Privacy, What to track, Exclusions, Location, Site audit, Advanced, and Data & uninstall, each with a short explanation. Nothing changes until you press **Save changes**, and you are warned before leaving the page with unsaved changes.

For automated set-ups, the same settings can be changed with WP-CLI or the REST API:

**WP-CLI**

```bash
wp option patch update blue_lens_settings privacy_mode enhanced
wp option patch update blue_lens_settings geoip_provider dbip
wp option patch update blue_lens_settings excluded_ips --format=json '["197.232.10.4","10.0.0.0/8"]'
wp option get blue_lens_settings --format=json
```

**REST API** (logged in as an administrator, e.g. from the browser console on any admin page):

```js
fetch( '/wp-json/blue-lens/v1/settings', {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },
	body: JSON.stringify( { privacy_mode: 'enhanced', respect_dnt: true } ),
} ).then( ( r ) => r.json() ).then( console.log );
```

Invalid values are corrected automatically, and unknown setting names are rejected.

## The Status screen

**Blue Lens → Status** shows: plugin and database versions, whether tracking is on, privacy mode, background jobs (Action Scheduler), sessions and events in the last 24 hours, GeoIP status (with an **Update now** button), a proxy/CDN warning, and whether each database table exists.

## Troubleshooting

| Problem | What to check |
|---|---|
| No visits recorded | Test in a private window: administrators and editors are excluded. Check `tracking_enabled`, and that your IP isn't in `excluded_ips`. |
| Visits recorded, but everyone looks like one visitor | Your site is behind a proxy/CDN: set `ip_header` ([guide](installation.md#6-sites-behind-a-proxy-or-cdn)). |
| Bounced visits missing | An optimisation plugin is delaying the script until interaction: add the exclusions from [installation.md](installation.md#7-caching-and-optimisation-plugins). |
| Tracking script not on the page | View the page source and look for `id="bla-data"`. If missing: you are logged in with an excluded role, the page is a preview/builder editor, or a filter disabled tracking. |
| Collection requests return 403/404 | A security plugin or firewall is blocking the REST API. Allow `POST /wp-json/blue-lens/v1/collect` (or `?rest_route=/blue-lens/v1/collect`). |
| "Could not update its database" notice | The notice shows the database error. The update retries every 10 minutes; check your database user can `CREATE` and `ALTER` tables. |
| GeoIP shows an error | For MaxMind, check the account ID and licence key. Make sure `wp-content/uploads` is writable. |
| Form submissions not counted | Check `track_forms`. For unsupported form plugins using AJAX without a real `submit` event, add `data-bla-event="form_submit"` to the submit button. |
| YouTube videos not tracked | Videos added after the page loads (lightboxes) aren't detected yet. Embedded iframes present on page load are. |

Still stuck? See [faq.md](faq.md), ask in the plugin's WordPress.org support forum, or contact Creative Bay at https://www.creativebay.co.ke (support hours are in [TERMS.md](../TERMS.md#6-support)).
