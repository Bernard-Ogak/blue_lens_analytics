# Privacy and data protection

Blue Lens Analytics is designed so you can measure your website while collecting as little personal data as possible. This page explains exactly what is processed, so you can meet your obligations under laws such as the EU/UK GDPR, the Kenya Data Protection Act 2019, and the California Consumer Privacy Act (CCPA/CPRA).

> This page is information, not legal advice. As the website owner you are the **data controller** and decide how Blue Lens is configured. Have your privacy policy reviewed by a qualified adviser.

## Where data is stored

All analytics data is stored **in your own WordPress database**, in tables named `{prefix}bla_*`. Blue Lens does not send analytics data to the plugin's authors or to any third party.

Outgoing connections:

| Connection | When | Data sent |
|---|---|---|
| GitHub API (`api.github.com`) | About twice a day, and on **Check again** under Dashboard → Updates (turn off with the `blue_lens_github_updates` filter) | A request for the latest release, with the plugin version in the user agent. No site address, analytics or visitor data. |
| MaxMind or DB-IP download server | You enable a GeoIP provider (weekly/monthly) | Your MaxMind account ID and licence key (MaxMind only). No visitor data. |

Future versions will add optional connections (social media APIs, AI sentiment scoring, ad-platform conversion exports). Each will be off by default and documented here.

## What is and isn't stored

| Stored | Not stored |
|---|---|
| Pages viewed, titles, time on page, scroll depth | IP addresses (used in memory for the visitor code and location lookup, then discarded) |
| Referring website, UTM tags, ad click type | Names, email addresses, phone numbers, postal addresses |
| Country, region, city (optional) | Anything typed into forms, including search boxes (a search term is kept only if it doesn't look like personal data) |
| Browser, operating system (major version), device type, screen-size group, language, time zone | Passwords, payment or card data, passport/ID numbers |
| Clicks on buttons and links, with visible labels (emails and numbers masked) | The text a visitor copies |
| Form IDs, field **names** reached, submissions | Form field **values** |
| An anonymous visitor code (see below) | Browser fingerprints, cross-site identifiers |
| Enhanced mode only, after consent: first-party ID cookie, full ad click ID | Any cookie in cookieless mode |

Local Ads by Bernard popups (Local Ads 1.0.1+): when a popup is shown or clicked, Blue Lens stores an `ad_impression` or `ad_click` event with the advertisement's ID only, under the same rules as every other event.

The site audit stores information about your own pages (URL, title, status, word count, meta tags and the issues found). It requests pages as an anonymous visitor from your own server and stores no visitor data. It contacts no outside service.

Every URL, page title, campaign tag and event property is automatically checked for email addresses, phone numbers and long tokens. These are masked (`[email]`, `[phone]`, `[token]`) or dropped before storage. Event properties named like personal data (`email`, `phone`, `name`, `passport`, `address`, etc.) are always rejected.

## The anonymous visitor code

**Cookieless mode (default):** a 128-bit code is calculated as a keyed hash of the visitor's shortened IP address (the last part removed), browser user-agent and your website address. The key is a random secret that is replaced every day at midnight UTC, and the old secret is deleted. So:

- the code can't be turned back into an IP address;
- the same visitor gets a different code each day, and days can't be linked;
- nothing is stored on the visitor's device.

Regulators differ on whether such codes are personal data. Treat them as pseudonymous personal data for the day they exist. Legitimate interest is the usual legal basis.

**Enhanced mode (optional):** after a visitor gives statistics consent, a random ID is stored in the first-party cookie `bla_vid` (13 months) and hashed with your site's secret before storage. Withdrawing consent deletes the cookie. The legal basis is consent.

## Privacy signals

- **Global Privacy Control:** respected by default. The visitor is treated as cookieless and excluded from any future ad-platform exports. It can be set to stop tracking entirely.
- **Do Not Track:** optional. When enabled, no tracking.
- **Opt-out:** `BlueLens.optOut()` stores an opt-out flag in the visitor's browser (see the [User guide](user-guide.md#visitor-opt-out)).
- **Staff:** administrators and editors are excluded by default.

## Retention

- Detailed events and sessions: 13 months by default (`retention_raw_months`). Deleted automatically every day, in small batches.
- Daily summaries (reports, heatmaps, crawler log): kept until you delete them (`retention_aggregate_months`).
- Everything is removed on uninstall if `delete_data_on_uninstall` is on.

## Data subject requests

Cookieless data can't be linked to a person, so there is normally nothing to export or erase for a named individual. For enhanced mode, personal-data export and erasure through WordPress's **Tools → Export/Erase Personal Data** is planned for version 0.11. Until then, deleting a visitor's `bla_vid` cookie (or their withdrawing consent) stops any further linking.

## Cookie declaration

| Cookie | Set when | Purpose | Duration | Category |
|---|---|---|---|---|
| `bla_vid` | Enhanced mode **and** statistics consent | Recognises returning visitors | 13 months | Statistics / analytics |

`bla_vid` is registered with the WP Consent API automatically, so compatible cookie banners list it. Blue Lens also uses the browser's localStorage key `bla_optout`, only if a visitor opts out. This storage exists to honour the opt-out, so it is strictly necessary.

## Template text for your privacy policy

Adapt this to your configuration, and delete the enhanced-mode paragraph if you use the default cookieless mode.

---

**Website analytics**

We use Blue Lens Analytics, a self-hosted analytics tool, to understand how visitors use our website so we can improve it. The data is stored on our own web server and is not shared with third parties.

We record the pages you visit, how long you stay, how you reached our site (for example a search engine, social network or campaign link), the links, buttons and forms you interact with (but never what you type), your approximate location (country, region and city, from a shortened version of your IP address), and your browser, operating system and device type. Your IP address is not stored.

By default no cookies are used. Visits are counted with an anonymous code that changes every day and can't be traced back to you. Our legal basis is our legitimate interest in running and improving our website.

*[If enhanced mode is enabled:]* If you consent to statistics cookies, we also set a cookie called `bla_vid` for 13 months so we can recognise return visits. You can withdraw consent at any time through our cookie settings, and the cookie will be deleted.

We respect the Global Privacy Control signal. You can also [opt out of analytics on this site](#) *(link calling `BlueLens.optOut()`)*.

Detailed analytics records are deleted after 13 months. For questions, contact *[your contact details]*.

---

## Security of the data

- All database access uses prepared statements. Admin endpoints require the `manage_blue_lens` capability and WordPress REST nonces.
- The public collection endpoint accepts only same-site pages, limits payload size and rate, and stores nothing when it rejects a request.
- The GeoIP folder is protected from direct web access on Apache (`.htaccess`). On Nginx, deny `/wp-content/uploads/blue-lens/` in your server configuration.
