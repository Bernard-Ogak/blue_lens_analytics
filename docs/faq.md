# Frequently asked questions

## General

**Is Blue Lens a replacement for Google Analytics?**
For most WordPress sites, yes. It covers visits, sources, campaigns, content, conversions, forms and site interactions, with the data on your own server. You can also run both side by side while you compare.

**Will it slow my site down?**
No, not noticeably. The core script is under 4 KB gzipped and loads deferred, so it never blocks rendering. The feature scripts load only after the page has finished loading. Sending data uses the browser's background beacon.

**Does it work with page caching (WP Rocket, LiteSpeed, Cloudflare…)?**
Yes. Cached pages contain no visitor-specific data or nonces, so they can be cached indefinitely. See the [installation guide](installation.md#7-caching-and-optimisation-plugins) if your optimiser delays JavaScript.

**Does it work with Elementor, Divi, Bricks, Gutenberg…?**
Yes. Page-builder editor previews are never tracked, and the builder used on each page is recorded. To tag buttons, use the builder's custom-attribute field (see [CTAs](user-guide.md#tracking-your-own-buttons-ctas)).

**Does it work with WPML or Polylang?**
Yes. The page language is recorded with every page view. If languages use separate domains, add them with the `blue_lens_allowed_hosts` filter.

**Is multisite supported?**
Yes. Each site has its own tables and settings. Network activation sets up existing and new sites automatically.

**Where are the dashboards?**
Under **Blue Lens → Dashboard**: Overview, Acquisition, Audience, Content, Engagement and Heatmaps, in dark or light theme. Use **Customize** to choose your colours, metric tiles and cards.

**Can my client or marketing team see the reports without changing settings?**
Yes. Grant the `view_blue_lens_reports` capability to their role with the `blue_lens_report_roles` filter (see the [user guide](user-guide.md#using-the-dashboard)). They get the dashboards but not Settings.

**Why are today's numbers a little behind?**
Summaries are rebuilt every hour. Press **Refresh** on the dashboard to update today immediately. The Real-time card is always live.

## Privacy

**Do I need a cookie banner for Blue Lens?**
In the default cookieless mode Blue Lens sets no cookies and stores nothing on the visitor's device, so it doesn't need consent under cookie rules in most places. Enhanced mode sets a cookie and needs consent, and Blue Lens waits for it. Your other plugins may still need a banner. Ask your legal adviser.

**Is it GDPR / Kenya DPA / CCPA compliant?**
It is built to make compliance straightforward: no IP storage, no personal data in analytics, daily-rotating anonymous codes, data on your own server, and privacy signals honoured. Compliance also depends on how you configure it and on your privacy policy. See [privacy.md](privacy.md), which includes template text.

**Can I see which specific person visited?**
No. Blue Lens is designed so you can't. It shows behaviour and trends, not identities. From version 0.7, enquiries get a reference ID so you can connect a booking back to the marketing that produced it, without storing the enquirer's name or email in analytics.

**Does it record what people type into forms?**
Never. It records only form IDs, field names reached and whether the form was submitted. Search terms are kept only if they don't look like an email address or phone number.

**What happens to IP addresses?**
They are used in memory to create the daily anonymous code, to check your excluded IPs and to look up the approximate location, and then they are discarded. They never reach the database.

**Where is the data stored? Is it sent anywhere?**
Only in your WordPress database. No analytics or visitor data is sent to the plugin's authors or to third parties. The only outgoing connections are the check for new versions on GitHub and the optional GeoIP download, and neither sends visitor data.

## Tracking

**Why don't I see my own visits?**
Administrators and editors are excluded by default. Test in a private window, or change `excluded_roles`.

**Why does every visitor look like the same person?**
Your site is probably behind a CDN or proxy. Set `ip_header` (see the [installation guide](installation.md#6-sites-behind-a-proxy-or-cdn)).

**How are bots handled?**
Known bots, crawlers, headless browsers and automation tools are excluded from visitor analytics. Search-engine and AI crawlers are counted separately in the crawler log.

**Can I track a custom action, like "brochure requested"?**
Yes. Add `data-bla-event="brochure_request"` to the button, add its CSS selector to `cta_selectors`, or call `BlueLens.track('brochure_request')` from JavaScript. See the [user guide](user-guide.md#tracking-your-own-buttons-ctas).

**Can I track WooCommerce orders or bookings?**
Server-side events can already be recorded with `blue_lens_track()` (see [developer-guide.md](developer-guide.md#php-api)). Built-in WooCommerce and booking-plugin integrations arrive with the industry profiles in version 0.5.

**Are phone and WhatsApp clicks tracked?**
Yes. `tel:`, `mailto:`, SMS, WhatsApp (`wa.me`, `api.whatsapp.com`), Messenger and Telegram links are recorded as `contact_click`. Copying a phone number from the page is also recorded (without the number).

**Does it record screen videos of visits (session replay)?**
No. That was a deliberate choice: recordings collect far more data than needed and capture what visitors type and see. Click heatmaps, autocapture, rage clicks and dead clicks give the usability insight without the privacy cost.

## Data management

**How long is data kept?**
Detailed events are kept for 13 months by default (`retention_raw_months`), deleted automatically every day. Daily summaries are kept until you delete them.

**How much database space does it use?**
Roughly 0.5–1 KB per event. A site with 1,000 visits a day at about 10 events per visit uses around 150–300 MB per year of raw events. Summaries are much smaller. High-traffic sites can keep less raw history.

**What happens when I delete the plugin?**
Your data is kept, unless you turned on `delete_data_on_uninstall` first. In that case everything is removed permanently.

**Can I export my data?**
Yes. Every dashboard card has an export button that downloads a CSV for the selected date range. WP-CLI export follows in a later release. The data is also in standard MySQL tables, so you can query it directly.

## Site audit and SEO

**Does the site audit use an outside service, like Semrush or Ahrefs?**
No. Blue Lens requests your pages from your own server and checks them locally. Nothing is sent anywhere. Because of that it has no data about other websites: it does not show keyword rankings, backlinks, domain authority or competitors.

**Why does an audit show many "missing meta description" or "no structured data" findings?**
Most themes only output these through an SEO plugin. Once an SEO plugin is set up and the pages have descriptions, the next audit stops reporting them.

**Will an audit slow my site down?**
Pages are fetched one after another in short steps, so the load is similar to one visitor browsing quietly. You can lower **Most pages per audit** under Settings → Site audit.

**Some pages show as broken or "could not be fetched", but they work in my browser.**
A security plugin, firewall or host may block requests the server makes to itself, or the pages need a login. Allow requests with the user agent `BlueLensAudit` from your server's own IP address.

**How is site health calculated?**
See the [user guide](user-guide.md#reading-the-results).

## Social media and reviews

**Will it monitor my Facebook, Instagram, Google and TripAdvisor pages?**
Yes, from versions 0.8–0.9: followers, posts, engagement, reviews and comments with sentiment. It connects through each platform's official API, using developer apps that you create for your own accounts. Some platforms (Meta, LinkedIn, TikTok, Google) require approval before an app can go live, and the X API is paid.

**Will it scrape competitors' pages?**
No. Scraping breaks the platforms' terms of service and can create legal risk, so Blue Lens only uses official APIs for accounts you own.
