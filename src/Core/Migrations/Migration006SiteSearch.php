<?php
/**
 * Migration 6: daily site-search terms.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core\Migrations;

use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates bla_daily_searches: what visitors searched for, per site-local day and normalised term.
 *
 * Built from site_search events by the Aggregator, like the other daily_* tables. `source_*`
 * columns split the count by where the search happened: the WordPress search results page, a
 * submitted search or filter form, or a live search box.
 */
final class Migration006SiteSearch implements Migration {

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 6;
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Create daily_searches table.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = Tables::name( Tables::DAILY_SEARCHES );
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  day date NOT NULL,
  term_hash binary(16) NOT NULL,
  term varchar(191) NOT NULL DEFAULT '',
  searches int(10) unsigned NOT NULL DEFAULT 0,
  sessions int(10) unsigned NOT NULL DEFAULT 0,
  zero_results int(10) unsigned NOT NULL DEFAULT 0,
  results_total bigint(20) unsigned NOT NULL DEFAULT 0,
  results_counted int(10) unsigned NOT NULL DEFAULT 0,
  source_results_page int(10) unsigned NOT NULL DEFAULT 0,
  source_form int(10) unsigned NOT NULL DEFAULT 0,
  source_live int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,term_hash),
  KEY term_day (term_hash,day)
) {$collate};"
		);

		if ( ! Tables::exists( Tables::DAILY_SEARCHES ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'Could not create table %s: %s', $table, $wpdb->last_error ) ) );
		}

		// Existing site_search events are summarised by the next aggregation run.
		delete_option( 'blue_lens_search_backfilled' );
	}
}
