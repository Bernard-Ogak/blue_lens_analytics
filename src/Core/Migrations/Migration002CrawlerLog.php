<?php
/**
 * Migration 2: crawler log.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core\Migrations;

use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates bla_crawler_daily: one counter row per day, crawler and path, so heavy bot traffic
 * cannot grow the table faster than (days × crawlers × URLs).
 */
final class Migration002CrawlerLog implements Migration {

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 2;
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Create crawler_daily table for the SEO crawl report.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = Tables::name( Tables::CRAWLER_DAILY );
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  day date NOT NULL,
  crawler varchar(32) NOT NULL,
  crawler_type varchar(16) NOT NULL DEFAULT '',
  path_hash binary(16) NOT NULL,
  path varchar(512) NOT NULL DEFAULT '',
  status_code smallint(5) unsigned NOT NULL DEFAULT 200,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  last_seen_at datetime NOT NULL,
  PRIMARY KEY  (day,crawler,path_hash),
  KEY crawler_day (crawler,day)
) {$collate};"
		);

		if ( ! Tables::exists( Tables::CRAWLER_DAILY ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'Could not create table %s: %s', $table, $wpdb->last_error ) ) );
		}
	}
}
