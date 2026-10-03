# Third-party notices

Blue Lens Analytics is licensed under the GNU General Public License, version 2 or (at your option) any later version. See [LICENSE](LICENSE).

It includes or can use the following third-party components. Each remains under its own licence.

## Bundled libraries (installed with Composer)

| Component | Purpose | Licence |
|---|---|---|
| [Action Scheduler](https://actionscheduler.org/) (`woocommerce/action-scheduler`) | Background job queue | GPL-3.0-or-later |
| [MaxMind DB Reader for PHP](https://github.com/maxmind/MaxMind-DB-Reader-php) (`maxmind-db/reader`) | Reads `.mmdb` GeoIP databases | Apache License 2.0 |

Because Action Scheduler is GPL-3.0 and the MaxMind reader is Apache-2.0 (which is compatible with GPL version 3 but not version 2), **distributions that include these libraries are distributed under GPL version 3** or later, as permitted by the "or later" clause of Blue Lens's licence.

## Data sources (downloaded by you, not bundled)

| Source | Licence and conditions |
|---|---|
| **MaxMind GeoLite2 City** | GeoLite2 End User License Agreement. Requires your own free MaxMind account and licence key. Includes GeoLite2 data created by MaxMind, available from https://www.maxmind.com. |
| **DB-IP IP to City Lite** | Creative Commons Attribution 4.0 International (CC BY 4.0). Attribution: "IP Geolocation by DB-IP" with a link to https://db-ip.com. |

## Development-only tools (not shipped)

PHPUnit, PHPStan, PHP_CodeSniffer, WordPress Coding Standards, esbuild, Jest, jsdom and ESLint are used to build and test the plugin. They are not included in release packages.

## Trademarks

WordPress, Google, YouTube, Meta, Facebook, Instagram, WhatsApp, Microsoft, Bing, TikTok, LinkedIn, X, OpenAI, ChatGPT, Anthropic, Claude, Perplexity, Cloudflare, MaxMind, DB-IP, Booking.com, TripAdvisor, Expedia and other names are trademarks of their respective owners. They are mentioned only to describe compatibility. Blue Lens Analytics is not affiliated with or endorsed by them.
