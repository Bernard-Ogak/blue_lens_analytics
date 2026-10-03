<?php
/**
 * Migration 5: site audit tables.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core\Migrations;

use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates bla_audit_runs (one row per audit, with its scores) and bla_audit_pages (one row per
 * crawled URL, with its measurements and issue codes).
 */
final class Migration005SiteAudit implements Migration {

	/**
	 * {@inheritDoc}
	 */
	public function version(): int {
		return 5;
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Create audit_runs and audit_pages tables.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$runs    = Tables::name( Tables::AUDIT_RUNS );
		$pages   = Tables::name( Tables::AUDIT_PAGES );
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$runs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(16) NOT NULL DEFAULT 'queued',
  phase varchar(16) NOT NULL DEFAULT 'pages',
  started_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  started_by bigint(20) unsigned NOT NULL DEFAULT 0,
  pages_total int(10) unsigned NOT NULL DEFAULT 0,
  pages_done int(10) unsigned NOT NULL DEFAULT 0,
  health tinyint(3) unsigned DEFAULT NULL,
  errors int(10) unsigned NOT NULL DEFAULT 0,
  warnings int(10) unsigned NOT NULL DEFAULT 0,
  notices int(10) unsigned NOT NULL DEFAULT 0,
  site_checks longtext,
  summary longtext,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY started_at (started_at)
) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$pages} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  url varchar(1024) NOT NULL DEFAULT '',
  path varchar(512) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(16) NOT NULL DEFAULT 'content',
  checked tinyint(1) NOT NULL DEFAULT 0,
  status_code smallint(5) unsigned NOT NULL DEFAULT 0,
  redirect_to varchar(1024) NOT NULL DEFAULT '',
  response_ms int(10) unsigned NOT NULL DEFAULT 0,
  html_bytes int(10) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  word_count int(10) unsigned NOT NULL DEFAULT 0,
  inlinks int(10) unsigned NOT NULL DEFAULT 0,
  errors smallint(5) unsigned NOT NULL DEFAULT 0,
  warnings smallint(5) unsigned NOT NULL DEFAULT 0,
  notices smallint(5) unsigned NOT NULL DEFAULT 0,
  issues longtext,
  facts longtext,
  PRIMARY KEY  (id),
  KEY run_checked (run_id,checked),
  KEY run_path (run_id,path(191))
) {$collate};"
		);

		foreach ( [ Tables::AUDIT_RUNS, Tables::AUDIT_PAGES ] as $key ) {
			if ( ! Tables::exists( $key ) ) {
				throw new \RuntimeException( esc_html( sprintf( 'Could not create table %s: %s', Tables::name( $key ), $wpdb->last_error ) ) );
			}
		}
	}
}
