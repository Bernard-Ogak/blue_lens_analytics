<?php
/**
 * Custom table registry.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves table names ({$wpdb->prefix}bla_*) for the current site.
 */
final class Tables {

	public const PREFIX = 'bla_';

	public const SESSIONS     = 'sessions';
	public const EVENTS       = 'events';
	public const LEADS        = 'leads';
	public const SCAN_RESULTS = 'scan_results';
	public const CONFIG       = 'config';
	public const FX_RATES     = 'fx_rates';

	public const CRAWLER_DAILY = 'crawler_daily';
	public const HEATMAP_DAILY = 'heatmap_daily';

	public const DAILY_TRAFFIC    = 'daily_traffic';
	public const DAILY_DIMENSIONS = 'daily_dimensions';
	public const DAILY_CONTENT    = 'daily_content';
	public const DAILY_EVENTS     = 'daily_events';

	public const AUDIT_RUNS  = 'audit_runs';
	public const AUDIT_PAGES = 'audit_pages';

	/**
	 * Full table name for the current site.
	 *
	 * @param string $table One of the class constants.
	 */
	public static function name( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . self::PREFIX . $table;
	}

	/**
	 * Short keys of every table owned by the plugin.
	 *
	 * @return list<string>
	 */
	public static function keys(): array {
		$keys = [
			self::SESSIONS,
			self::EVENTS,
			self::LEADS,
			self::SCAN_RESULTS,
			self::CONFIG,
			self::FX_RATES,
			self::CRAWLER_DAILY,
			self::HEATMAP_DAILY,
			self::DAILY_TRAFFIC,
			self::DAILY_DIMENSIONS,
			self::DAILY_CONTENT,
			self::DAILY_EVENTS,
			self::AUDIT_RUNS,
			self::AUDIT_PAGES,
		];

		/**
		 * Filters the table keys owned by Blue Lens (used by status checks and uninstall).
		 *
		 * @param list<string> $keys Table keys without prefix.
		 */
		$keys = (array) apply_filters( 'blue_lens_tables', $keys );

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $keys ) ) ) );
	}

	/**
	 * Full names of every table owned by the plugin on the current site.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array_map( [ self::class, 'name' ], self::keys() );
	}

	/**
	 * Whether a table exists on the current site.
	 *
	 * @param string $table Table key.
	 */
	public static function exists( string $table ): bool {
		global $wpdb;

		$name = self::name( $table );

		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}

	/**
	 * Full names of existing plugin tables on the current site, in one query.
	 *
	 * @return list<string>
	 */
	public static function existing(): array {
		global $wpdb;

		$like  = $wpdb->esc_like( $wpdb->prefix . self::PREFIX ) . '%';
		$found = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return array_values( array_intersect( self::all(), array_map( 'strval', $found ) ) );
	}
}
