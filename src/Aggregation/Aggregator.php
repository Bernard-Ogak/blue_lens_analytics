<?php
/**
 * Daily rollups from raw sessions and events.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Aggregation;

use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds the daily_* tables for a site-local day. Each rebuild deletes and re-inserts the day,
 * so it is idempotent and safe to repeat.
 *
 * Schedule: hourly for today and yesterday, plus any days skipped since the previous run; older
 * days are back-filled 14 per run until the first recorded event is reached.
 */
final class Aggregator implements Hookable {

	public const HOOK            = 'blue_lens_aggregate';
	public const LAST_RUN_OPTION = 'blue_lens_last_aggregated';
	public const BACKFILL_OPTION = 'blue_lens_backfill_cursor';

	private const BACKFILL_DAYS_PER_RUN = 14;
	private const CATCH_UP_MAX_DAYS     = 31;
	private const INSERT_CHUNK          = 250;

	/**
	 * Session dimensions: key => [SQL expression over bla_sessions columns, extra WHERE condition].
	 * Expressions are fixed strings (never user input).
	 */
	private const SESSION_DIMENSIONS = [
		'channel'      => [ "IF(channel <> '', channel, 'direct')", '' ],
		'source'       => [ "IF(utm_source <> '', LOWER(utm_source), IF(referrer_domain <> '', referrer_domain, '(direct)'))", '' ],
		'medium'       => [ "IF(utm_medium <> '', LOWER(utm_medium), '(none)')", '' ],
		'campaign'     => [ 'utm_campaign', "utm_campaign <> ''" ],
		'referrer'     => [ 'referrer_domain', "referrer_domain <> ''" ],
		'landing_page' => [ 'landing_path', '' ],
		'country'      => [ "IF(country <> '', country, '(unknown)')", '' ],
		'region'       => [ "CONCAT(country, ' · ', region)", "region <> ''" ],
		'city'         => [ "CONCAT(country, ' · ', city)", "city <> ''" ],
		'device'       => [ "IF(device_type <> '', device_type, '(unknown)')", '' ],
		'browser'      => [ "IF(browser <> '', browser, '(unknown)')", '' ],
		'os'           => [ "IF(os <> '', os, '(unknown)')", '' ],
		'language'     => [ "IF(language <> '', LOWER(language), '(unknown)')", '' ],
		'viewport'     => [ "IF(viewport <> '', viewport, '(unknown)')", '' ],
		'visitor_type' => [ "CASE is_returning WHEN 1 THEN 'returning' WHEN 0 THEN 'new' ELSE 'unknown' END", '' ],
	];

	/**
	 * Dimension keys available to reports.
	 *
	 * @return list<string>
	 */
	public static function dimensions(): array {
		return array_keys( self::SESSION_DIMENSIONS );
	}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_filter(
			'blue_lens_recurring_jobs',
			static function ( array $jobs ): array {
				$jobs[ self::HOOK ] = HOUR_IN_SECONDS;
				return $jobs;
			}
		);
	}

	/**
	 * Job callback: today, yesterday, then a slice of the back-fill.
	 */
	public function run(): void {
		$today = SiteTime::today();

		$this->aggregate_day( $today );
		$this->aggregate_day( SiteTime::add_days( $today, -1 ) );
		$this->catch_up( (int) get_option( self::LAST_RUN_OPTION, 0 ), $today );
		$this->backfill( self::BACKFILL_DAYS_PER_RUN );

		update_option( self::LAST_RUN_OPTION, time(), false );
	}

	/**
	 * Rebuilds the days between the previous run and yesterday (at most the last 31). When the job
	 * has not run for more than a day (no visits to trigger WP-Cron, a broken system cron, the site
	 * offline), those days were never summarised once complete, and the back-fill cursor has
	 * already passed them.
	 *
	 * @param int    $last_run Unix time of the previous run (0 when unknown).
	 * @param string $today    Today, site-local Y-m-d.
	 */
	private function catch_up( int $last_run, string $today ): void {
		if ( $last_run <= 0 ) {
			return;
		}

		$day       = SiteTime::local_day( gmdate( 'Y-m-d H:i:s', $last_run ) );
		$yesterday = SiteTime::add_days( $today, -1 );
		$oldest    = SiteTime::add_days( $yesterday, -self::CATCH_UP_MAX_DAYS );
		if ( $day < $oldest ) {
			$day = $oldest;
		}

		while ( $day < $yesterday ) {
			$this->aggregate_day( $day );
			$day = SiteTime::add_days( $day, 1 );
		}
	}

	/**
	 * Aggregates today only (the dashboard's "Refresh" button).
	 */
	public function refresh_today(): int {
		$this->aggregate_day( SiteTime::today() );
		update_option( self::LAST_RUN_OPTION, time(), false );

		return time();
	}

	/**
	 * Aggregates older days, walking backwards from the cursor to the first recorded event.
	 *
	 * @param int $max_days Days to process in this call.
	 * @return int Days processed.
	 */
	public function backfill( int $max_days ): int {
		global $wpdb;

		$first = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(occurred_at) FROM %i', Tables::name( Tables::EVENTS ) ) );
		if ( ! is_string( $first ) || '' === $first ) {
			return 0;
		}
		$first_day = SiteTime::local_day( $first );

		$cursor = get_option( self::BACKFILL_OPTION );
		$cursor = SiteTime::is_date( $cursor ) ? (string) $cursor : SiteTime::add_days( SiteTime::today(), -1 );

		$done = 0;
		while ( $done < $max_days && $cursor > $first_day ) {
			$cursor = SiteTime::add_days( $cursor, -1 );
			$this->aggregate_day( $cursor );
			++$done;
		}

		update_option( self::BACKFILL_OPTION, $cursor, false );

		return $done;
	}

	/**
	 * Rebuilds all summaries for one site-local day.
	 *
	 * @param string $day Y-m-d in the site time zone.
	 */
	public function aggregate_day( string $day ): void {
		global $wpdb;

		if ( ! SiteTime::is_date( $day ) ) {
			return;
		}

		[ $start, $end ] = SiteTime::utc_bounds( $day, $day );

		foreach ( [ Tables::DAILY_TRAFFIC, Tables::DAILY_DIMENSIONS, Tables::DAILY_CONTENT, Tables::DAILY_EVENTS ] as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day = %s', Tables::name( $table ), $day ) );
		}

		$this->traffic( $day, $start, $end );
		$this->session_dimensions( $day, $start, $end );
		$this->content( $day, $start, $end );
		$this->events( $day, $start, $end );

		/**
		 * Fires after a day's summaries are rebuilt.
		 *
		 * @param string $day Site-local date.
		 */
		do_action( 'blue_lens_day_aggregated', $day );
	}

	/**
	 * Daily totals.
	 *
	 * @param string $day   Day.
	 * @param string $start UTC start.
	 * @param string $end   UTC end.
	 */
	private function traffic( string $day, string $start, string $end ): void {
		global $wpdb;

		$sessions = (array) $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT visitor_key) AS visitors, COUNT(*) AS sessions,
					COALESCE(SUM(is_engaged), 0) AS engaged_sessions,
					COALESCE(SUM(pageviews <= 1 AND is_engaged = 0), 0) AS bounces,
					COALESCE(SUM(engaged_seconds), 0) AS engaged_seconds,
					COALESCE(SUM(duration_seconds), 0) AS duration_seconds,
					COUNT(DISTINCT IF(is_returning = 0, visitor_key, NULL)) AS new_visitors,
					COUNT(DISTINCT IF(is_returning = 1, visitor_key, NULL)) AS returning_visitors
				FROM %i WHERE started_at >= %s AND started_at < %s',
				Tables::name( Tables::SESSIONS ),
				$start,
				$end
			),
			ARRAY_A
		);

		$events = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS events,
					COALESCE(SUM(event_name = 'page_view'), 0) AS pageviews,
					COALESCE(SUM(is_conversion), 0) AS conversions,
					COALESCE(SUM(IF(is_conversion = 1, COALESCE(event_value_base, 0), 0)), 0) AS revenue
				FROM %i WHERE occurred_at >= %s AND occurred_at < %s",
				Tables::name( Tables::EVENTS ),
				$start,
				$end
			),
			ARRAY_A
		);

		if ( 0 === (int) ( $sessions['sessions'] ?? 0 ) && 0 === (int) ( $events['events'] ?? 0 ) ) {
			return;
		}

		$wpdb->insert(
			Tables::name( Tables::DAILY_TRAFFIC ),
			[
				'day'                => $day,
				'visitors'           => (int) $sessions['visitors'],
				'sessions'           => (int) $sessions['sessions'],
				'engaged_sessions'   => (int) $sessions['engaged_sessions'],
				'bounces'            => (int) $sessions['bounces'],
				'pageviews'          => (int) $events['pageviews'],
				'events'             => (int) $events['events'],
				'conversions'        => (int) $events['conversions'],
				'revenue'            => (float) $events['revenue'],
				'engaged_seconds'    => (int) $sessions['engaged_seconds'],
				'duration_seconds'   => (int) $sessions['duration_seconds'],
				'new_visitors'       => (int) $sessions['new_visitors'],
				'returning_visitors' => (int) $sessions['returning_visitors'],
				'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%d', '%d', '%d', '%d', '%s' ]
		);
	}

	/**
	 * Per-dimension session metrics. Revenue counts conversions made by sessions that started this day,
	 * including those completed shortly after midnight.
	 *
	 * @param string $day   Day.
	 * @param string $start UTC start.
	 * @param string $end   UTC end.
	 */
	private function session_dimensions( string $day, string $start, string $end ): void {
		global $wpdb;

		$sessions   = Tables::name( Tables::SESSIONS );
		$events     = Tables::name( Tables::EVENTS );
		$dimensions = Tables::name( Tables::DAILY_DIMENSIONS );
		$revenue_to = gmdate( 'Y-m-d H:i:s', (int) strtotime( $end . ' UTC' ) + DAY_IN_SECONDS );

		foreach ( self::SESSION_DIMENSIONS as $dimension => [ $expression, $condition ] ) {
			$where = '' === $condition ? '' : ' AND ' . $condition;

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $expression/$where are class constants.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (day, dimension, value_hash, value, sessions, visitors, engaged_sessions, pageviews, engaged_seconds, conversions, revenue)
					SELECT %s, %s, UNHEX(MD5(v)), LEFT(v, 191), COUNT(*), COUNT(DISTINCT visitor_key), SUM(is_engaged), SUM(pageviews), SUM(engaged_seconds), SUM(conversions), COALESCE(SUM(rev), 0)
					FROM (
						SELECT {$expression} AS v, s.visitor_key, s.is_engaged, s.pageviews, s.engaged_seconds, s.conversions, r.rev
						FROM %i s
						LEFT JOIN (
							SELECT session_id, SUM(COALESCE(event_value_base, 0)) AS rev FROM %i
							WHERE is_conversion = 1 AND occurred_at >= %s AND occurred_at < %s GROUP BY session_id
						) r ON r.session_id = s.id
						WHERE s.started_at >= %s AND s.started_at < %s{$where}
					) t
					GROUP BY v",
					$dimensions,
					$day,
					$dimension,
					$sessions,
					$events,
					$start,
					$revenue_to,
					$start,
					$end
				)
			);
			// phpcs:enable
		}
	}

	/**
	 * Per-page metrics.
	 *
	 * @param string $day   Day.
	 * @param string $start UTC start.
	 * @param string $end   UTC end.
	 */
	private function content( string $day, string $start, string $end ): void {
		global $wpdb;

		$events   = Tables::name( Tables::EVENTS );
		$sessions = Tables::name( Tables::SESSIONS );
		$pages    = [];

		$blank = static fn( string $path ): array => [
			'path'            => $path,
			'post_id'         => 0,
			'post_type'       => '',
			'title'           => '',
			'pageviews'       => 0,
			'visitors'        => 0,
			'entrances'       => 0,
			'exits'           => 0,
			'engaged_seconds' => 0,
			'engaged_views'   => 0,
			'conversions'     => 0,
		];

		$views = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_path, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_key) AS visitors, MAX(post_id) AS post_id, MAX(post_type) AS post_type, MAX(page_title) AS title
				FROM %i WHERE event_name = 'page_view' AND occurred_at >= %s AND occurred_at < %s GROUP BY page_path",
				$events,
				$start,
				$end
			),
			ARRAY_A
		);
		foreach ( $views as $row ) {
			$path                        = (string) $row['page_path'];
			$pages[ $path ]              = $blank( $path );
			$pages[ $path ]['pageviews'] = (int) $row['pageviews'];
			$pages[ $path ]['visitors']  = (int) $row['visitors'];
			$pages[ $path ]['post_id']   = (int) $row['post_id'];
			$pages[ $path ]['post_type'] = (string) $row['post_type'];
			$pages[ $path ]['title']     = (string) $row['title'];
		}

		$counts = [
			'entrances'   => $wpdb->prepare( 'SELECT landing_path AS p, COUNT(*) AS n FROM %i WHERE started_at >= %s AND started_at < %s GROUP BY landing_path', $sessions, $start, $end ),
			'exits'       => $wpdb->prepare( 'SELECT exit_path AS p, COUNT(*) AS n FROM %i WHERE started_at >= %s AND started_at < %s GROUP BY exit_path', $sessions, $start, $end ),
			'conversions' => $wpdb->prepare( 'SELECT page_path AS p, COUNT(*) AS n FROM %i WHERE is_conversion = 1 AND occurred_at >= %s AND occurred_at < %s GROUP BY page_path', $events, $start, $end ),
		];
		foreach ( $counts as $metric => $sql ) {
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
				$path                    = (string) $row['p'];
				$pages[ $path ]        ??= $blank( $path );
				$pages[ $path ][ $metric ] = (int) $row['n'];
			}
		}

		// Engaged time: the highest cumulative value reported per page view, capped at 30 minutes.
		$engagement = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_path AS p, SUM(mx) AS secs, COUNT(*) AS views FROM (
					SELECT page_path, session_id, JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.pv')) AS pv,
						LEAST(MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.engaged_seconds')) AS UNSIGNED)), 1800) AS mx
					FROM %i WHERE event_name = 'page_engagement' AND occurred_at >= %s AND occurred_at < %s
					GROUP BY page_path, session_id, pv
				) t GROUP BY page_path",
				$events,
				$start,
				$end
			),
			ARRAY_A
		);
		foreach ( $engagement as $row ) {
			$path                              = (string) $row['p'];
			$pages[ $path ]                  ??= $blank( $path );
			$pages[ $path ]['engaged_seconds'] = (int) $row['secs'];
			$pages[ $path ]['engaged_views']   = (int) $row['views'];
		}

		foreach ( array_chunk( array_values( $pages ), self::INSERT_CHUNK ) as $chunk ) {
			$values = [];
			foreach ( $chunk as $p ) {
				$values[] = $wpdb->prepare(
					'(%s, UNHEX(%s), %s, %d, %s, %s, %d, %d, %d, %d, %d, %d, %d)',
					$day,
					md5( $p['path'] ),
					$p['path'],
					$p['post_id'],
					$p['post_type'],
					mb_substr( $p['title'], 0, 255 ),
					$p['pageviews'],
					$p['visitors'],
					$p['entrances'],
					$p['exits'],
					$p['engaged_seconds'],
					$p['engaged_views'],
					$p['conversions']
				);
			}

			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tuples prepared above.
				$wpdb->prepare( 'INSERT INTO %i (day, path_hash, path, post_id, post_type, title, pageviews, visitors, entrances, exits, engaged_seconds, engaged_views, conversions) VALUES ', Tables::name( Tables::DAILY_CONTENT ) )
				. implode( ',', $values )
			);
		}
	}

	/**
	 * Per-event counts.
	 *
	 * @param string $day   Day.
	 * @param string $start UTC start.
	 * @param string $end   UTC end.
	 */
	private function events( string $day, string $start, string $end ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (day, event_name, category, module, events, sessions, value, is_conversion)
				SELECT %s, event_name, MAX(category), MAX(module), COUNT(*), COUNT(DISTINCT session_id), COALESCE(SUM(event_value_base), 0), MAX(is_conversion)
				FROM %i WHERE occurred_at >= %s AND occurred_at < %s GROUP BY event_name',
				Tables::name( Tables::DAILY_EVENTS ),
				$day,
				Tables::name( Tables::EVENTS ),
				$start,
				$end
			)
		);
	}
}
