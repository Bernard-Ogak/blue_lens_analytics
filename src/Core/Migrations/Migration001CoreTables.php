<?php
/**
 * Migration 1: core tables.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core\Migrations;

use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates sessions, events, leads, scan_results, config and fx_rates.
 *
 * Design notes:
 * - All timestamps are UTC DATETIME.
 * - Visitor and session keys are 128-bit BINARY(16) hashes, never raw IPs or user IDs.
 * - JSON payloads use LONGTEXT (validated in PHP) because MariaDB reports JSON as LONGTEXT,
 *   which makes dbDelta re-alter JSON columns on every run. JSON_EXTRACT works on both engines.
 * - bla_events has PRIMARY KEY (id, occurred_at) so it can later be RANGE-partitioned by date.
 */
final class Migration001CoreTables implements Migration {

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 1;
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Create core tables: sessions, events, leads, scan_results, config, fx_rates.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $this->statements( $wpdb->get_charset_collate() ) as $table => $sql ) {
			dbDelta( $sql );

			if ( ! Tables::exists( $table ) ) {
				throw new \RuntimeException(
					esc_html( sprintf( 'Could not create table %s: %s', Tables::name( $table ), $wpdb->last_error ) )
				);
			}
		}
	}

	/**
	 * CREATE TABLE statements in dbDelta format, keyed by table key.
	 *
	 * @param string $collate Charset/collation clause.
	 * @return array<string, string>
	 */
	private function statements( string $collate ): array {
		$sessions     = Tables::name( Tables::SESSIONS );
		$events       = Tables::name( Tables::EVENTS );
		$leads        = Tables::name( Tables::LEADS );
		$scan_results = Tables::name( Tables::SCAN_RESULTS );
		$config       = Tables::name( Tables::CONFIG );
		$fx_rates     = Tables::name( Tables::FX_RATES );

		return [
			Tables::SESSIONS     => "CREATE TABLE {$sessions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_key binary(16) NOT NULL,
  visitor_key binary(16) NOT NULL,
  id_mode tinyint(3) unsigned NOT NULL DEFAULT 0,
  is_returning tinyint(1) DEFAULT NULL,
  started_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  duration_seconds int(10) unsigned NOT NULL DEFAULT 0,
  engaged_seconds int(10) unsigned NOT NULL DEFAULT 0,
  pageviews smallint(5) unsigned NOT NULL DEFAULT 0,
  event_count int(10) unsigned NOT NULL DEFAULT 0,
  conversions smallint(5) unsigned NOT NULL DEFAULT 0,
  is_engaged tinyint(1) NOT NULL DEFAULT 0,
  landing_path varchar(512) NOT NULL DEFAULT '',
  landing_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  exit_path varchar(512) NOT NULL DEFAULT '',
  referrer_domain varchar(191) NOT NULL DEFAULT '',
  referrer_url varchar(1024) NOT NULL DEFAULT '',
  channel varchar(32) NOT NULL DEFAULT 'direct',
  utm_source varchar(191) NOT NULL DEFAULT '',
  utm_medium varchar(191) NOT NULL DEFAULT '',
  utm_campaign varchar(191) NOT NULL DEFAULT '',
  utm_term varchar(191) NOT NULL DEFAULT '',
  utm_content varchar(191) NOT NULL DEFAULT '',
  click_id_type varchar(16) NOT NULL DEFAULT '',
  click_id varchar(255) NOT NULL DEFAULT '',
  device_type varchar(16) NOT NULL DEFAULT '',
  browser varchar(32) NOT NULL DEFAULT '',
  browser_version varchar(16) NOT NULL DEFAULT '',
  os varchar(32) NOT NULL DEFAULT '',
  os_version varchar(16) NOT NULL DEFAULT '',
  viewport varchar(16) NOT NULL DEFAULT '',
  language varchar(16) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  region varchar(64) NOT NULL DEFAULT '',
  city varchar(96) NOT NULL DEFAULT '',
  timezone varchar(64) NOT NULL DEFAULT '',
  is_logged_in tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY session_key (session_key),
  KEY started_at (started_at),
  KEY last_seen_at (last_seen_at),
  KEY visitor_started (visitor_key,started_at)
) {$collate};",

			Tables::EVENTS       => "CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  occurred_at datetime NOT NULL,
  session_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visitor_key binary(16) DEFAULT NULL,
  event_name varchar(64) NOT NULL,
  category varchar(32) NOT NULL DEFAULT '',
  module varchar(32) NOT NULL DEFAULT 'core',
  origin tinyint(3) unsigned NOT NULL DEFAULT 0,
  entity_type varchar(32) NOT NULL DEFAULT '',
  entity_id varchar(64) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_type varchar(20) NOT NULL DEFAULT '',
  page_path varchar(512) NOT NULL DEFAULT '',
  page_title varchar(255) NOT NULL DEFAULT '',
  event_value decimal(18,4) DEFAULT NULL,
  currency char(3) NOT NULL DEFAULT '',
  event_value_base decimal(18,4) DEFAULT NULL,
  is_conversion tinyint(1) NOT NULL DEFAULT 0,
  lead_ref varchar(16) NOT NULL DEFAULT '',
  attributes longtext,
  context longtext,
  PRIMARY KEY  (id,occurred_at),
  KEY occurred_at (occurred_at),
  KEY session_id (session_id),
  KEY event_occurred (event_name,occurred_at),
  KEY lead_ref (lead_ref)
) {$collate};",

			Tables::LEADS        => "CREATE TABLE {$leads} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lead_ref varchar(16) NOT NULL,
  session_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visitor_key binary(16) DEFAULT NULL,
  module varchar(32) NOT NULL DEFAULT '',
  entity_type varchar(32) NOT NULL DEFAULT '',
  entity_id varchar(64) NOT NULL DEFAULT '',
  form_ref varchar(100) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'new',
  channel varchar(32) NOT NULL DEFAULT '',
  utm_source varchar(191) NOT NULL DEFAULT '',
  utm_medium varchar(191) NOT NULL DEFAULT '',
  utm_campaign varchar(191) NOT NULL DEFAULT '',
  landing_path varchar(512) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  attribution longtext,
  attributes longtext,
  estimated_value decimal(18,4) DEFAULT NULL,
  booking_value decimal(18,4) DEFAULT NULL,
  currency char(3) NOT NULL DEFAULT '',
  booking_value_base decimal(18,4) DEFAULT NULL,
  lost_reason varchar(191) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  status_changed_at datetime DEFAULT NULL,
  booked_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY lead_ref (lead_ref),
  KEY status_created (status,created_at),
  KEY created_at (created_at),
  KEY channel_created (channel,created_at)
) {$collate};",

			Tables::SCAN_RESULTS => "CREATE TABLE {$scan_results} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scan_uid char(36) NOT NULL,
  scan_version smallint(5) unsigned NOT NULL DEFAULT 1,
  detector varchar(64) NOT NULL,
  signal_key varchar(128) NOT NULL,
  suggested_module varchar(32) NOT NULL DEFAULT '',
  confidence decimal(4,3) NOT NULL DEFAULT 0.000,
  evidence text NOT NULL,
  payload longtext,
  status varchar(16) NOT NULL DEFAULT 'new',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY scan_uid (scan_uid),
  KEY module_confidence (suggested_module,confidence)
) {$collate};",

			Tables::CONFIG       => "CREATE TABLE {$config} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  config_key varchar(64) NOT NULL,
  version int(10) unsigned NOT NULL,
  config_value longtext NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY key_version (config_key,version),
  KEY key_active (config_key,is_active)
) {$collate};",

			Tables::FX_RATES     => "CREATE TABLE {$fx_rates} (
  rate_date date NOT NULL,
  base_currency char(3) NOT NULL,
  quote_currency char(3) NOT NULL,
  rate decimal(20,10) NOT NULL,
  source varchar(32) NOT NULL DEFAULT '',
  fetched_at datetime NOT NULL,
  PRIMARY KEY  (rate_date,base_currency,quote_currency)
) {$collate};",
		];
	}
}
