<?php
/**
 * Migration 4: daily aggregate tables.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core\Migrations;

use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the daily summary tables every dashboard reads from.
 *
 * `day` is a calendar date in the site's time zone (Settings → General), so reports line up
 * with the owner's business day rather than UTC.
 */
final class Migration004Aggregates implements Migration {

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 4;
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Create daily aggregate tables: traffic, dimensions, content, events.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate    = $wpdb->get_charset_collate();
		$traffic    = Tables::name( Tables::DAILY_TRAFFIC );
		$dimensions = Tables::name( Tables::DAILY_DIMENSIONS );
		$content    = Tables::name( Tables::DAILY_CONTENT );
		$events     = Tables::name( Tables::DAILY_EVENTS );

		$statements = [
			Tables::DAILY_TRAFFIC    => "CREATE TABLE {$traffic} (
  day date NOT NULL,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  sessions int(10) unsigned NOT NULL DEFAULT 0,
  engaged_sessions int(10) unsigned NOT NULL DEFAULT 0,
  bounces int(10) unsigned NOT NULL DEFAULT 0,
  pageviews int(10) unsigned NOT NULL DEFAULT 0,
  events int(10) unsigned NOT NULL DEFAULT 0,
  conversions int(10) unsigned NOT NULL DEFAULT 0,
  revenue decimal(18,4) NOT NULL DEFAULT 0.0000,
  engaged_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
  duration_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
  new_visitors int(10) unsigned NOT NULL DEFAULT 0,
  returning_visitors int(10) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (day)
) {$collate};",
			Tables::DAILY_DIMENSIONS => "CREATE TABLE {$dimensions} (
  day date NOT NULL,
  dimension varchar(32) NOT NULL,
  value_hash binary(16) NOT NULL,
  value varchar(191) NOT NULL DEFAULT '',
  sessions int(10) unsigned NOT NULL DEFAULT 0,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  engaged_sessions int(10) unsigned NOT NULL DEFAULT 0,
  pageviews int(10) unsigned NOT NULL DEFAULT 0,
  engaged_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
  conversions int(10) unsigned NOT NULL DEFAULT 0,
  revenue decimal(18,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY  (day,dimension,value_hash),
  KEY dimension_day (dimension,day)
) {$collate};",
			Tables::DAILY_CONTENT    => "CREATE TABLE {$content} (
  day date NOT NULL,
  path_hash binary(16) NOT NULL,
  path varchar(512) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_type varchar(20) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  pageviews int(10) unsigned NOT NULL DEFAULT 0,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  entrances int(10) unsigned NOT NULL DEFAULT 0,
  exits int(10) unsigned NOT NULL DEFAULT 0,
  engaged_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
  engaged_views int(10) unsigned NOT NULL DEFAULT 0,
  conversions int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,path_hash),
  KEY post_day (post_id,day)
) {$collate};",
			Tables::DAILY_EVENTS     => "CREATE TABLE {$events} (
  day date NOT NULL,
  event_name varchar(64) NOT NULL,
  category varchar(32) NOT NULL DEFAULT '',
  module varchar(32) NOT NULL DEFAULT '',
  events int(10) unsigned NOT NULL DEFAULT 0,
  sessions int(10) unsigned NOT NULL DEFAULT 0,
  value decimal(18,4) NOT NULL DEFAULT 0.0000,
  is_conversion tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,event_name),
  KEY event_day (event_name,day)
) {$collate};",
		];

		foreach ( $statements as $table => $sql ) {
			dbDelta( $sql );

			if ( ! Tables::exists( $table ) ) {
				throw new \RuntimeException( esc_html( sprintf( 'Could not create table %s: %s', Tables::name( $table ), $wpdb->last_error ) ) );
			}
		}
	}
}
