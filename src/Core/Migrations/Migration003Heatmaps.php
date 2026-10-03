<?php
/**
 * Migration 3: click heatmaps.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core\Migrations;

use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates bla_heatmap_daily: click counters per day, page, device class and grid cell.
 */
final class Migration003Heatmaps implements Migration {

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 3;
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Create heatmap_daily table.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = Tables::name( Tables::HEATMAP_DAILY );
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  day date NOT NULL,
  path_hash binary(16) NOT NULL,
  path varchar(512) NOT NULL DEFAULT '',
  device varchar(16) NOT NULL DEFAULT '',
  x_bucket tinyint(3) unsigned NOT NULL,
  y_bucket smallint(5) unsigned NOT NULL,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,path_hash,device,x_bucket,y_bucket),
  KEY path_day (path_hash,day)
) {$collate};"
		);

		if ( ! Tables::exists( Tables::HEATMAP_DAILY ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'Could not create table %s: %s', $table, $wpdb->last_error ) ) );
		}
	}
}
