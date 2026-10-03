<?php
/**
 * Report queries over the daily aggregate tables.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Aggregation;

use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only report queries. Dashboards read aggregates only; the real-time report is the one
 * exception and scans at most the last 30 minutes of raw events.
 *
 * All date arguments are site-local Y-m-d strings, validated by the REST controller.
 */
final class Reports {

	public const CACHE_GROUP = 'blue_lens_reports';

	/**
	 * Additive metrics stored in bla_daily_traffic.
	 */
	public const TRAFFIC_METRICS = [ 'visitors', 'sessions', 'engaged_sessions', 'bounces', 'pageviews', 'events', 'conversions', 'revenue', 'engaged_seconds', 'duration_seconds', 'new_visitors', 'returning_visitors' ];

	/**
	 * Content groupings supported by content().
	 */
	public const CONTENT_GROUPS = [ 'page', 'post_type', 'author', 'age' ];

	/**
	 * Totals, derived rates, daily series and optional comparison for a range.
	 *
	 * @param string $from    First day.
	 * @param string $to      Last day.
	 * @param string $compare "previous", "year" or "none".
	 * @return array<string, mixed>
	 */
	public function overview( string $from, string $to, string $compare ): array {
		return $this->cached(
			[ 'overview', $from, $to, $compare ],
			$to,
			function () use ( $from, $to, $compare ): array {
				$result = [
					'range'  => [ 'from' => $from, 'to' => $to ],
					'totals' => $this->totals( $from, $to ),
					'series' => $this->series( $from, $to ),
				];

				$previous = $this->comparison_range( $from, $to, $compare );
				if ( null !== $previous ) {
					$result['compare'] = [
						'range'  => [ 'from' => $previous[0], 'to' => $previous[1] ],
						'totals' => $this->totals( $previous[0], $previous[1] ),
						'series' => $this->series( $previous[0], $previous[1] ),
					];
				}

				return $result;
			}
		);
	}

	/**
	 * Summed metrics plus derived rates.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array<string, float|int>
	 */
	public function totals( string $from, string $to ): array {
		global $wpdb;

		$select = implode( ', ', array_map( static fn( string $m ): string => "COALESCE(SUM({$m}), 0) AS {$m}", self::TRAFFIC_METRICS ) );

		$row = (array) $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $select is built from a constant list.
			$wpdb->prepare( "SELECT {$select} FROM %i WHERE day BETWEEN %s AND %s", Tables::name( Tables::DAILY_TRAFFIC ), $from, $to ),
			ARRAY_A
		);

		$totals = [];
		foreach ( self::TRAFFIC_METRICS as $metric ) {
			$totals[ $metric ] = 'revenue' === $metric ? round( (float) ( $row[ $metric ] ?? 0 ), 2 ) : (int) ( $row[ $metric ] ?? 0 );
		}

		return $totals + self::derived( $totals );
	}

	/**
	 * Rates and averages computed from additive totals.
	 *
	 * @param array<string, float|int> $t Totals.
	 * @return array<string, float>
	 */
	public static function derived( array $t ): array {
		$sessions = max( 0, (int) ( $t['sessions'] ?? 0 ) );
		$ratio    = static fn( float $a, float $b ): float => $b > 0 ? round( $a / $b, 4 ) : 0.0;

		return [
			'engagement_rate'   => $ratio( (float) ( $t['engaged_sessions'] ?? 0 ), $sessions ),
			'bounce_rate'       => $ratio( (float) ( $t['bounces'] ?? 0 ), $sessions ),
			'pages_per_session' => $ratio( (float) ( $t['pageviews'] ?? 0 ), $sessions ),
			'avg_engaged_time'  => $ratio( (float) ( $t['engaged_seconds'] ?? 0 ), $sessions ),
			'avg_duration'      => $ratio( (float) ( $t['duration_seconds'] ?? 0 ), $sessions ),
			'conversion_rate'   => $ratio( (float) ( $t['conversions'] ?? 0 ), $sessions ),
		];
	}

	/**
	 * Zero-filled daily series.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return list<array<string, mixed>>
	 */
	public function series( string $from, string $to ): array {
		global $wpdb;

		$columns = implode( ', ', self::TRAFFIC_METRICS );
		$rows    = (array) $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant column list.
			$wpdb->prepare( "SELECT day, {$columns} FROM %i WHERE day BETWEEN %s AND %s", Tables::name( Tables::DAILY_TRAFFIC ), $from, $to ),
			OBJECT_K
		);

		$series = [];
		foreach ( SiteTime::days( $from, $to ) as $day ) {
			$point = [ 'day' => $day ];
			foreach ( self::TRAFFIC_METRICS as $metric ) {
				$value            = isset( $rows[ $day ] ) ? $rows[ $day ]->$metric : 0;
				$point[ $metric ] = 'revenue' === $metric ? round( (float) $value, 2 ) : (int) $value;
			}
			$series[] = $point;
		}

		return $series;
	}

	/**
	 * Top values of a session dimension.
	 *
	 * @param string $dimension Dimension key (validated against Aggregator::dimensions()).
	 * @param string $from      First day.
	 * @param string $to        Last day.
	 * @param int    $limit     Rows.
	 * @param string $orderby   Metric to sort by.
	 * @return array{rows: list<array<string, mixed>>, total: int}
	 */
	public function dimension( string $dimension, string $from, string $to, int $limit, string $orderby = 'sessions' ): array {
		return $this->cached(
			[ 'dimension', $dimension, $from, $to, $limit, $orderby ],
			$to,
			function () use ( $dimension, $from, $to, $limit, $orderby ): array {
				global $wpdb;

				$orderby = in_array( $orderby, [ 'sessions', 'visitors', 'engaged_sessions', 'pageviews', 'conversions', 'revenue' ], true ) ? $orderby : 'sessions';
				$table   = Tables::name( Tables::DAILY_DIMENSIONS );

				$rows = (array) $wpdb->get_results(
					$wpdb->prepare(
						'SELECT MAX(value) AS value, SUM(sessions) AS sessions, SUM(visitors) AS visitors, SUM(engaged_sessions) AS engaged_sessions,
							SUM(pageviews) AS pageviews, SUM(engaged_seconds) AS engaged_seconds, SUM(conversions) AS conversions, SUM(revenue) AS revenue
						FROM %i WHERE dimension = %s AND day BETWEEN %s AND %s
						GROUP BY value_hash ORDER BY %i DESC LIMIT %d',
						$table,
						$dimension,
						$from,
						$to,
						$orderby,
						$limit
					),
					ARRAY_A
				);

				$total = (int) $wpdb->get_var(
					$wpdb->prepare( 'SELECT COUNT(DISTINCT value_hash) FROM %i WHERE dimension = %s AND day BETWEEN %s AND %s', $table, $dimension, $from, $to )
				);

				return [
					'rows'  => array_map( [ self::class, 'normalize_session_row' ], $rows ),
					'total' => $total,
				];
			}
		);
	}

	/**
	 * Content report.
	 *
	 * @param string $group "page", "post_type", "author", "age" or "tax:{taxonomy}".
	 * @param string $from  First day.
	 * @param string $to    Last day.
	 * @param int    $limit Rows.
	 * @return array{rows: list<array<string, mixed>>}
	 */
	public function content( string $group, string $from, string $to, int $limit ): array {
		return $this->cached(
			[ 'content', $group, $from, $to, $limit ],
			$to,
			function () use ( $group, $from, $to, $limit ): array {
				global $wpdb;

				$table = Tables::name( Tables::DAILY_CONTENT );
				$sums  = 'SUM(pageviews) AS pageviews, SUM(visitors) AS visitors, SUM(entrances) AS entrances, SUM(exits) AS exits,
					SUM(engaged_seconds) AS engaged_seconds, SUM(engaged_views) AS engaged_views, SUM(conversions) AS conversions';

				if ( 'page' === $group ) {
					$rows = (array) $wpdb->get_results(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sums is a constant string.
						$wpdb->prepare( "SELECT MAX(path) AS path, MAX(title) AS title, MAX(post_id) AS post_id, MAX(post_type) AS post_type, {$sums} FROM %i WHERE day BETWEEN %s AND %s GROUP BY path_hash ORDER BY pageviews DESC LIMIT %d", $table, $from, $to, $limit ),
						ARRAY_A
					);

					return [
						'rows' => array_map(
							static function ( array $row ): array {
								$post_id = (int) $row['post_id'];
								$title   = (string) $row['title'];
								if ( '' === $title && $post_id > 0 ) {
									$title = get_the_title( $post_id );
								}
								return [ 'key' => (string) $row['path'], 'label' => '' !== $title ? wp_strip_all_tags( $title ) : (string) $row['path'], 'path' => (string) $row['path'], 'post_id' => $post_id ] + self::content_metrics( $row );
							},
							$rows
						),
					];
				}

				if ( 'post_type' === $group ) {
					$rows = (array) $wpdb->get_results(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sums is a constant string.
						$wpdb->prepare( "SELECT post_type, {$sums} FROM %i WHERE day BETWEEN %s AND %s GROUP BY post_type ORDER BY pageviews DESC LIMIT %d", $table, $from, $to, $limit ),
						ARRAY_A
					);

					return [
						'rows' => array_map(
							static function ( array $row ): array {
								$type   = (string) $row['post_type'];
								$object = '' !== $type ? get_post_type_object( $type ) : null;
								$label  = $object ? (string) $object->labels->singular_name : ( '' === $type ? __( 'Archives & other pages', 'blue-lens-analytics' ) : $type );
								return [ 'key' => $type, 'label' => $label ] + self::content_metrics( $row );
							},
							$rows
						),
					];
				}

				// Author, content age and taxonomy are derived from each post's current data.
				$rows = (array) $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sums is a constant string.
					$wpdb->prepare( "SELECT post_id, {$sums} FROM %i WHERE day BETWEEN %s AND %s AND post_id > 0 GROUP BY post_id ORDER BY pageviews DESC LIMIT 5000", $table, $from, $to ),
					ARRAY_A
				);

				$ids = array_map( static fn( array $r ): int => (int) $r['post_id'], $rows );
				if ( $ids ) {
					_prime_post_caches( $ids, true, false );
				}

				$groups = [];
				foreach ( $rows as $row ) {
					foreach ( $this->content_keys( $group, (int) $row['post_id'] ) as $key => $label ) {
						$groups[ $key ] ??= [ 'key' => (string) $key, 'label' => $label, 'row' => array_fill_keys( [ 'pageviews', 'visitors', 'entrances', 'exits', 'engaged_seconds', 'engaged_views', 'conversions' ], 0 ) ];
						foreach ( $groups[ $key ]['row'] as $metric => $value ) {
							$groups[ $key ]['row'][ $metric ] = $value + (int) $row[ $metric ];
						}
					}
				}

				usort( $groups, static fn( array $a, array $b ): int => $b['row']['pageviews'] <=> $a['row']['pageviews'] );

				return [
					'rows' => array_map(
						static fn( array $g ): array => [ 'key' => $g['key'], 'label' => $g['label'] ] + self::content_metrics( $g['row'] ),
						array_slice( $groups, 0, $limit )
					),
				];
			}
		);
	}

	/**
	 * Event totals.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array{rows: list<array<string, mixed>>}
	 */
	public function events( string $from, string $to ): array {
		return $this->cached(
			[ 'events', $from, $to ],
			$to,
			function () use ( $from, $to ): array {
				global $wpdb;

				$rows = (array) $wpdb->get_results(
					$wpdb->prepare(
						'SELECT event_name, MAX(category) AS category, MAX(module) AS module, SUM(events) AS events, SUM(sessions) AS sessions, SUM(value) AS value, MAX(is_conversion) AS is_conversion
						FROM %i WHERE day BETWEEN %s AND %s GROUP BY event_name ORDER BY events DESC',
						Tables::name( Tables::DAILY_EVENTS ),
						$from,
						$to
					),
					ARRAY_A
				);

				return [
					'rows' => array_map(
						static fn( array $r ): array => [
							'event'         => (string) $r['event_name'],
							'category'      => (string) $r['category'],
							'module'        => (string) $r['module'],
							'events'        => (int) $r['events'],
							'sessions'      => (int) $r['sessions'],
							'value'         => round( (float) $r['value'], 2 ),
							'is_conversion' => (bool) $r['is_conversion'],
						],
						$rows
					),
				];
			}
		);
	}

	/**
	 * Live activity from raw data: last 5 minutes for "now", last 30 for trends.
	 *
	 * @return array<string, mixed>
	 */
	public function realtime(): array {
		global $wpdb;

		$now      = time();
		$since_5  = gmdate( 'Y-m-d H:i:s', $now - 5 * MINUTE_IN_SECONDS );
		$since_30 = gmdate( 'Y-m-d H:i:s', $now - 30 * MINUTE_IN_SECONDS );
		$sessions = Tables::name( Tables::SESSIONS );
		$events   = Tables::name( Tables::EVENTS );

		$active = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT visitor_key) FROM %i WHERE last_seen_at >= %s', $sessions, $since_5 ) );

		$per_minute = array_fill( 0, 30, 0 );
		$minutes    = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT FLOOR(TIMESTAMPDIFF(SECOND, occurred_at, %s) / 60) AS ago, COUNT(*) AS n FROM %i
				WHERE event_name = 'page_view' AND occurred_at >= %s GROUP BY ago",
				gmdate( 'Y-m-d H:i:s', $now ),
				$events,
				$since_30
			),
			ARRAY_A
		);
		foreach ( $minutes as $row ) {
			$ago = (int) $row['ago'];
			if ( $ago >= 0 && $ago < 30 ) {
				$per_minute[ 29 - $ago ] = (int) $row['n'];
			}
		}

		$pages = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_path AS path, MAX(page_title) AS title, COUNT(*) AS views FROM %i
				WHERE event_name = 'page_view' AND occurred_at >= %s GROUP BY page_path ORDER BY views DESC LIMIT 6",
				$events,
				$since_30
			),
			ARRAY_A
		);

		$channels = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT channel, COUNT(*) AS sessions FROM %i WHERE last_seen_at >= %s GROUP BY channel ORDER BY sessions DESC LIMIT 6', $sessions, $since_30 ),
			ARRAY_A
		);

		$feed = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.event_name, e.page_path, e.occurred_at, s.country, s.device_type FROM %i e LEFT JOIN %i s ON s.id = e.session_id
				WHERE e.occurred_at >= %s AND e.event_name NOT IN ('page_engagement', 'click') ORDER BY e.id DESC LIMIT 12",
				$events,
				$sessions,
				$since_30
			),
			ARRAY_A
		);

		return [
			'active_visitors'      => $active,
			'pageviews_per_minute' => $per_minute,
			'pageviews_30m'        => array_sum( $per_minute ),
			'top_pages'            => array_map(
				static fn( array $r ): array => [ 'path' => (string) $r['path'], 'title' => wp_strip_all_tags( (string) $r['title'] ), 'views' => (int) $r['views'] ],
				$pages
			),
			'channels'             => array_map( static fn( array $r ): array => [ 'channel' => (string) $r['channel'], 'sessions' => (int) $r['sessions'] ], $channels ),
			'feed'                 => array_map(
				static fn( array $r ): array => [
					'event'   => (string) $r['event_name'],
					'path'    => (string) $r['page_path'],
					'seconds' => max( 0, $now - (int) strtotime( $r['occurred_at'] . ' UTC' ) ),
					'country' => (string) $r['country'],
					'device'  => (string) $r['device_type'],
				],
				$feed
			),
		];
	}

	/**
	 * Pages with heatmap data.
	 *
	 * @param string $from   First day.
	 * @param string $to     Last day.
	 * @param string $device Device class or "all".
	 * @return list<array{path: string, clicks: int}>
	 */
	public function heatmap_pages( string $from, string $to, string $device ): array {
		global $wpdb;

		$device_sql = 'all' === $device ? '' : $wpdb->prepare( ' AND device = %s', $device );
		$rows       = (array) $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $device_sql is prepared.
			$wpdb->prepare( "SELECT MAX(path) AS path, SUM(clicks) AS clicks FROM %i WHERE day BETWEEN %s AND %s{$device_sql} GROUP BY path_hash ORDER BY clicks DESC LIMIT 100", Tables::name( Tables::HEATMAP_DAILY ), $from, $to ),
			ARRAY_A
		);

		return array_map( static fn( array $r ): array => [ 'path' => (string) $r['path'], 'clicks' => (int) $r['clicks'] ], $rows );
	}

	/**
	 * Heatmap cells for one page.
	 *
	 * @param string $path   Page path.
	 * @param string $device Device class or "all".
	 * @param string $from   First day.
	 * @param string $to     Last day.
	 * @return array{cells: list<array{0: int, 1: int, 2: int}>, max: int, total: int}
	 */
	public function heatmap( string $path, string $device, string $from, string $to ): array {
		global $wpdb;

		$device_sql = 'all' === $device ? '' : $wpdb->prepare( ' AND device = %s', $device );
		$rows       = (array) $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $device_sql is prepared.
			$wpdb->prepare( "SELECT x_bucket, y_bucket, SUM(clicks) AS clicks FROM %i WHERE path_hash = UNHEX(%s) AND day BETWEEN %s AND %s{$device_sql} GROUP BY x_bucket, y_bucket", Tables::name( Tables::HEATMAP_DAILY ), md5( $path ), $from, $to ),
			ARRAY_A
		);

		$cells = array_map( static fn( array $r ): array => [ (int) $r['x_bucket'], (int) $r['y_bucket'], (int) $r['clicks'] ], $rows );
		$max   = $cells ? max( array_column( $cells, 2 ) ) : 0;

		return [
			'cells' => $cells,
			'max'   => $max,
			'total' => array_sum( array_column( $cells, 2 ) ),
		];
	}

	/**
	 * Crawler activity.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array{crawlers: list<array<string, mixed>>, not_found: list<array<string, mixed>>}
	 */
	public function crawlers( string $from, string $to ): array {
		global $wpdb;

		$table = Tables::name( Tables::CRAWLER_DAILY );

		$crawlers = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT crawler, MAX(crawler_type) AS type, SUM(hits) AS hits, COUNT(DISTINCT path_hash) AS pages, SUM(IF(status_code = 404, hits, 0)) AS not_found, MAX(last_seen_at) AS last_seen
				FROM %i WHERE day BETWEEN %s AND %s GROUP BY crawler ORDER BY hits DESC',
				$table,
				$from,
				$to
			),
			ARRAY_A
		);

		$not_found = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT MAX(path) AS path, SUM(hits) AS hits, COUNT(DISTINCT crawler) AS crawlers FROM %i
				WHERE day BETWEEN %s AND %s AND status_code = 404 GROUP BY path_hash ORDER BY hits DESC LIMIT 20',
				$table,
				$from,
				$to
			),
			ARRAY_A
		);

		return [
			'crawlers'  => array_map(
				static fn( array $r ): array => [
					'crawler'   => (string) $r['crawler'],
					'type'      => (string) $r['type'],
					'hits'      => (int) $r['hits'],
					'pages'     => (int) $r['pages'],
					'not_found' => (int) $r['not_found'],
					'last_seen' => (string) $r['last_seen'],
				],
				$crawlers
			),
			'not_found' => array_map( static fn( array $r ): array => [ 'path' => (string) $r['path'], 'hits' => (int) $r['hits'], 'crawlers' => (int) $r['crawlers'] ], $not_found ),
		];
	}

	/**
	 * Previous or year-ago range of the same length.
	 *
	 * @param string $from    First day.
	 * @param string $to      Last day.
	 * @param string $compare Mode.
	 * @return array{0: string, 1: string}|null
	 */
	public function comparison_range( string $from, string $to, string $compare ): ?array {
		if ( 'previous' === $compare ) {
			$span = SiteTime::span( $from, $to );
			return [ SiteTime::add_days( $from, -$span ), SiteTime::add_days( $from, -1 ) ];
		}
		if ( 'year' === $compare ) {
			$shift = static fn( string $d ): string => ( new \DateTimeImmutable( $d, wp_timezone() ) )->modify( '-1 year' )->format( 'Y-m-d' );
			return [ $shift( $from ), $shift( $to ) ];
		}

		return null;
	}

	/**
	 * Group keys and labels for a post.
	 *
	 * @param string $group   Grouping.
	 * @param int    $post_id Post ID.
	 * @return array<string, string>
	 */
	private function content_keys( string $group, int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [ 'deleted' => __( 'Deleted content', 'blue-lens-analytics' ) ];
		}

		if ( 'author' === $group ) {
			$author = (int) $post->post_author;
			$name   = $author > 0 ? get_the_author_meta( 'display_name', $author ) : '';
			return [ (string) $author => '' !== $name ? $name : __( 'Unknown author', 'blue-lens-analytics' ) ];
		}

		if ( 'age' === $group ) {
			$months = ( time() - (int) get_post_time( 'U', true, $post ) ) / ( 30 * DAY_IN_SECONDS );
			return match ( true ) {
				$months < 1  => [ 'a1' => __( 'Under 1 month', 'blue-lens-analytics' ) ],
				$months < 6  => [ 'a2' => __( '1–6 months', 'blue-lens-analytics' ) ],
				$months < 12 => [ 'a3' => __( '6–12 months', 'blue-lens-analytics' ) ],
				$months < 24 => [ 'a4' => __( '1–2 years', 'blue-lens-analytics' ) ],
				default      => [ 'a5' => __( 'Over 2 years', 'blue-lens-analytics' ) ],
			};
		}

		if ( str_starts_with( $group, 'tax:' ) ) {
			$terms = get_the_terms( $post, substr( $group, 4 ) );
			if ( ! is_array( $terms ) || ! $terms ) {
				return [ '0' => __( '(none)', 'blue-lens-analytics' ) ];
			}
			$keys = [];
			foreach ( $terms as $term ) {
				$keys[ (string) $term->term_id ] = $term->name;
			}
			return $keys;
		}

		return [];
	}

	/**
	 * Numeric content metrics and derived averages.
	 *
	 * @param array<string, mixed> $row Summed row.
	 * @return array<string, float|int>
	 */
	private static function content_metrics( array $row ): array {
		$pageviews = (int) $row['pageviews'];
		$views     = (int) $row['engaged_views'];

		return [
			'pageviews'        => $pageviews,
			'visitors'         => (int) $row['visitors'],
			'entrances'        => (int) $row['entrances'],
			'exits'            => (int) $row['exits'],
			'conversions'      => (int) $row['conversions'],
			'avg_engaged_time' => $views > 0 ? round( (int) $row['engaged_seconds'] / $views, 1 ) : 0,
			'exit_rate'        => $pageviews > 0 ? round( (int) $row['exits'] / $pageviews, 4 ) : 0,
		];
	}

	/**
	 * Numeric session-dimension row and derived rates.
	 *
	 * @param array<string, mixed> $row Summed row.
	 * @return array<string, mixed>
	 */
	private static function normalize_session_row( array $row ): array {
		$sessions = (int) $row['sessions'];

		return [
			'value'            => (string) $row['value'],
			'sessions'         => $sessions,
			'visitors'         => (int) $row['visitors'],
			'engaged_sessions' => (int) $row['engaged_sessions'],
			'pageviews'        => (int) $row['pageviews'],
			'conversions'      => (int) $row['conversions'],
			'revenue'          => round( (float) $row['revenue'], 2 ),
			'engagement_rate'  => $sessions > 0 ? round( (int) $row['engaged_sessions'] / $sessions, 4 ) : 0,
			'conversion_rate'  => $sessions > 0 ? round( (int) $row['conversions'] / $sessions, 4 ) : 0,
			'avg_engaged_time' => $sessions > 0 ? round( (int) $row['engaged_seconds'] / $sessions, 1 ) : 0,
		];
	}

	/**
	 * Object-cache wrapper: effective only with a persistent cache. Ranges ending today expire
	 * in 5 minutes, historical ranges in 1 hour.
	 *
	 * @template T
	 * @param array<mixed>  $key     Cache key parts.
	 * @param string        $to      Range end (decides freshness).
	 * @param callable(): T $compute Producer.
	 * @return T
	 */
	private function cached( array $key, string $to, callable $compute ): mixed {
		$cache_key = get_current_blog_id() . ':' . md5( (string) wp_json_encode( $key ) . ':' . get_option( Aggregator::LAST_RUN_OPTION, 0 ) );
		$hit       = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );
		if ( $found ) {
			return $hit;
		}

		$value = $compute();
		wp_cache_set( $cache_key, $value, self::CACHE_GROUP, $to >= SiteTime::today() ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );

		return $value;
	}
}
