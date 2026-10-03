<?php
/**
 * Search engine and AI crawler log.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Counts known-crawler page renders per day and path (crawlers do not run JavaScript, so this
 * happens server-side). Pages served straight from a full-page cache never reach PHP and are not
 * counted; the report shows crawl activity on uncached renders.
 */
final class CrawlerLogger implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Settings.
	 * @param BotDetector  $bots     Bot detection.
	 * @param UrlSanitizer $urls     URL handling.
	 */
	public function __construct(
		private Settings $settings,
		private BotDetector $bots,
		private UrlSanitizer $urls
	) {}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( 'template_redirect', [ $this, 'maybe_log' ], PHP_INT_MAX );
	}

	/**
	 * Logs the current request if it comes from a known crawler.
	 */
	public function maybe_log(): void {
		if ( ! $this->settings->get( 'log_crawlers' ) || is_admin() ) {
			return;
		}

		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( str_starts_with( $ua, \BlueLens\Analytics\Modules\Audit\SiteAudit::UA ) ) {
			return; // Our own site audit.
		}
		$crawler = '' !== $ua ? $this->bots->crawler( $ua ) : null;
		if ( null === $crawler ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = $this->urls->clean_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );

		$this->record( $crawler['name'], $crawler['type'], $path, is_404() ? 404 : 200, time() );
	}

	/**
	 * Increments the day's counter.
	 *
	 * @param string $crawler Crawler name.
	 * @param string $type    Crawler type.
	 * @param string $path    Path.
	 * @param int    $status  HTTP status.
	 * @param int    $time    Unix time.
	 */
	public function record( string $crawler, string $type, string $path, int $status, int $time ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (day, crawler, crawler_type, path_hash, path, status_code, hits, last_seen_at)
				VALUES (%s, %s, %s, UNHEX(%s), %s, %d, 1, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, status_code = VALUES(status_code), last_seen_at = VALUES(last_seen_at)',
				Tables::name( Tables::CRAWLER_DAILY ),
				gmdate( 'Y-m-d', $time ),
				$crawler,
				$type,
				md5( $path ),
				$path,
				$status,
				gmdate( 'Y-m-d H:i:s', $time )
			)
		);
	}
}
