# Installation guide

## 1. Before you start

| Requirement | Minimum | Recommended |
|---|---|---|
| WordPress | 6.4 | Latest |
| PHP | 8.1 | 8.2 or 8.3 |
| Database | MySQL 5.7 / MariaDB 10.3 | MySQL 8 / MariaDB 10.6+ |
| PHP extensions | `json`, `mbstring`, `zlib` | plus `apcu`, or a Redis/Memcached object cache, for rate limiting |
| Disk space | 5 MB | +70–130 MB if you enable a GeoIP city database |

You need a WordPress administrator account. On multisite, a network administrator is needed to network-activate the plugin.

## 2. Install the plugin

### From a zip file

1. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
2. Choose `blue-lens-analytics.zip` and click **Install Now**.
3. Click **Activate Plugin**.

### By FTP / file manager

1. Unzip the package.
2. Upload the `blue-lens-analytics` folder to `wp-content/plugins/`.
3. Go to **Plugins** and click **Activate** under Blue Lens Analytics.

### From source (developers)

```bash
cd wp-content/plugins
git clone https://github.com/Bernard-Ogak/blue_lens_analytics.git blue-lens-analytics
cd blue-lens-analytics
composer install --no-dev   # Action Scheduler and the GeoIP reader
npm install && npm run build   # optional: minified tracker scripts
```

The plugin runs without `composer install`, but background jobs then fall back to WP-Cron and the GeoIP reader is unavailable.

## 3. What happens on activation

- Fourteen database tables named `{prefix}bla_*` are created (see [developer-guide.md](developer-guide.md#database)).
- Administrators receive the `manage_blue_lens` (settings) and `view_blue_lens_reports` (dashboards) capabilities.
- Default settings are saved: cookieless mode, tracking on, administrators and editors excluded.
- A random site secret is generated for anonymous visitor codes.

On **multisite network activation**, the first 100 sites are set up immediately. Every other site sets itself up on its next page load. New sites are set up when they are created.

## 4. Check that it works

1. Open your website in a **private/incognito window**. You are logged in as an administrator in your normal window, and administrators are not tracked by default.
2. Click around a few pages.
3. In WordPress, open **Blue Lens → Dashboard** and press **Refresh** (the circular arrow). Your visit appears in the metrics and under **Real-time**.
4. For a technical check, **Blue Lens → Status** shows sessions in the last 24 hours and lists every database table as *Present*.

If nothing appears, see [Troubleshooting](user-guide.md#troubleshooting).

## 5. Optional: location data (GeoIP)

Without a GeoIP database, Blue Lens only knows the visitor's country when your site is behind Cloudflare (it reads Cloudflare's country header). For country, region and city on any host, choose one free provider:

### Option A — MaxMind GeoLite2 (weekly updates)

1. Create a free account at maxmind.com and generate a licence key.
2. Set `geoip_provider` to `maxmind` and enter your `maxmind_account_id` and `maxmind_license_key` (see [Changing settings](user-guide.md#changing-settings)).
3. The database downloads in the background within a few minutes. You can also click **Update GeoIP database now** on the Status screen.

You must accept MaxMind's GeoLite2 End User License Agreement.

### Option B — DB-IP Lite (monthly updates, no account)

1. Set `geoip_provider` to `dbip`.
2. Click **Update GeoIP database now** on the Status screen.

DB-IP Lite is licensed CC BY 4.0. Where location data is shown, you must credit "IP Geolocation by DB-IP" with a link to db-ip.com (Blue Lens does this on its own screens).

The database is stored in `wp-content/uploads/blue-lens/geo/`. IP addresses are looked up in memory and then discarded; they are never saved.

## 6. Sites behind a proxy or CDN

If your site is behind Cloudflare, a load balancer or a reverse proxy, WordPress may see the proxy's address instead of the visitor's. The Status screen warns you when this looks likely. Set `ip_header` to the header your proxy uses:

| Your setup | `ip_header` value |
|---|---|
| No proxy (default) | `remote_addr` |
| Cloudflare | `http_cf_connecting_ip` |
| Most load balancers / Nginx proxies | `http_x_forwarded_for` or `http_x_real_ip` |
| Akamai, some CDNs | `http_true_client_ip` |

Only change this if a proxy you trust really sets that header. Otherwise visitors could fake their address.

## 7. Caching and optimisation plugins

No set-up is needed. Blue Lens adds no cookies or nonces to cached pages. It also excludes its own script from delaying and combining in WP Rocket, LiteSpeed Cache, SiteGround Optimizer, Autoptimize and Cloudflare Rocket Loader.

If you use another optimiser with a "delay JavaScript until interaction" feature, add `blue-lens-analytics/assets/tracker` and `bla-data` to its exclusions. Otherwise visitors who leave without interacting are not counted.

## 8. Upgrading

Replace the plugin files (or update from the Plugins screen). Database changes run automatically on the next page load and are recorded under **Blue Lens → Status**. If an update fails, an admin notice explains why, and the update is retried every 10 minutes. No data is lost.

## 9. Deactivating and uninstalling

- **Deactivate:** tracking stops and background jobs are cancelled. All data is kept.
- **Delete (uninstall):** data is **kept** unless the `delete_data_on_uninstall` setting was turned on before deleting. With it on, deleting removes every Blue Lens table, setting, scheduled job, the GeoIP folder and the `manage_blue_lens` capability. This cannot be undone.

On multisite, each site's own `delete_data_on_uninstall` setting decides whether that site's data is removed.
