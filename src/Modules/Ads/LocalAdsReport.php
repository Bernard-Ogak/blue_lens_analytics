<?php
/**
 * Report for the Local Ads by Bernard plugin.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Modules\Ads;

use BlueLens\Analytics\Aggregation\SiteTime;
use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Combines two sources for the dashboard's Ads page:
 *
 * - Local Ads' own daily statistics (impressions, clicks, CTR by day, ad and campaign), read through
 *   its public Local_Ads_Analytics API so the numbers always match the Local Ads screens.
 * - Blue Lens's ad_impression / ad_click events, which tie each ad interaction to a session, so
 *   clicks can be broken down by channel and page and related to conversions.
 */
final class LocalAdsReport {

	public const EVENT_IMPRESSION = 'ad_impression';
	public const EVENT_CLICK      = 'ad_click';
	public const ENTITY_TYPE      = 'local_ad';

	/**
	 * Whether Local Ads by Bernard is active and exposes its analytics API.
	 */
	public static function available(): bool {
		return class_exists( 'Local_Ads_Analytics' )
			&& class_exists( 'Local_Ads_DB' )
			&& method_exists( 'Local_Ads_Analytics', 'totals' )
			&& method_exists( 'Local_Ads_Analytics', 'daily' )
			&& method_exists( 'Local_Ads_Analytics', 'by_ad' )
			&& method_exists( 'Local_Ads_Analytics', 'by_campaign' );
	}

	/**
	 * Full report for a range of site-time days.
	 *
	 * @param string $from First day (Y-m-d).
	 * @param string $to   Last day (Y-m-d).
	 * @return array<string, mixed>
	 */
	public function report( string $from, string $to ): array {
		if ( ! self::available() ) {
			return [ 'available' => false ];
		}

		$totals = (array) \Local_Ads_Analytics::totals( $from, $to );

		return [
			'available' => true,
			'totals'    => [
				'impressions' => (int) ( $totals['impressions'] ?? 0 ),
				'clicks'      => (int) ( $totals['clicks'] ?? 0 ),
				'ctr'         => (float) ( $totals['ctr'] ?? 0 ),
			],
			'daily'     => array_map(
				static fn( $r ): array => [
					'day'         => (string) ( $r['date'] ?? '' ),
					'impressions' => (int) ( $r['impressions'] ?? 0 ),
					'clicks'      => (int) ( $r['clicks'] ?? 0 ),
				],
				(array) \Local_Ads_Analytics::daily( $from, $to )
			),
			'ads'       => array_map(
				static fn( $r ): array => [
					'ad_id'       => (int) ( $r['ad_id'] ?? 0 ),
					'name'        => (string) ( $r['name'] ?? '' ),
					'status'      => (string) ( $r['status'] ?? '' ),
					'campaign'    => (string) ( $r['campaign'] ?? '' ),
					'impressions' => (int) ( $r['impressions'] ?? 0 ),
					'clicks'      => (int) ( $r['clicks'] ?? 0 ),
					'ctr'         => (float) ( $r['ctr'] ?? 0 ),
				],
				(array) \Local_Ads_Analytics::by_ad( $from, $to )
			),
			'campaigns' => array_map(
				static fn( $r ): array => [
					'campaign_id' => (int) ( $r['campaign_id'] ?? 0 ),
					'name'        => (string) ( $r['name'] ?? '' ),
					'impressions' => (int) ( $r['impressions'] ?? 0 ),
					'clicks'      => (int) ( $r['clicks'] ?? 0 ),
					'ctr'         => (float) ( $r['ctr'] ?? 0 ),
				],
				(array) \Local_Ads_Analytics::by_campaign( $from, $to )
			),
			'visits'    => $this->visits( $from, $to ),
		];
	}

	/**
	 * Ad interactions recorded by Blue Lens, joined to their sessions.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array<string, mixed>
	 */
	private function visits( string $from, string $to ): array {
		global $wpdb;

		[ $start, $end ] = SiteTime::utc_bounds( $from, $to );
		$events          = Tables::name( Tables::EVENTS );
		$sessions        = Tables::name( Tables::SESSIONS );

		$summary = (array) $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					SUM(e.event_name = %s) AS impressions,
					SUM(e.event_name = %s) AS clicks,
					COUNT(DISTINCT CASE WHEN e.event_name = %s THEN e.session_id END) AS click_sessions
				FROM %i e
				WHERE e.event_name IN (%s, %s) AND e.occurred_at >= %s AND e.occurred_at < %s',
				self::EVENT_IMPRESSION,
				self::EVENT_CLICK,
				self::EVENT_CLICK,
				$events,
				self::EVENT_IMPRESSION,
				self::EVENT_CLICK,
				$start,
				$end
			),
			ARRAY_A
		);

		$converting = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i s
				WHERE s.conversions > 0 AND s.id IN (
					SELECT DISTINCT e.session_id FROM %i e
					WHERE e.event_name = %s AND e.occurred_at >= %s AND e.occurred_at < %s AND e.session_id > 0
				)',
				$sessions,
				$events,
				self::EVENT_CLICK,
				$start,
				$end
			)
		);

		$channels = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.channel AS channel,
					SUM(e.event_name = %s) AS impressions,
					SUM(e.event_name = %s) AS clicks,
					COUNT(DISTINCT CASE WHEN e.event_name = %s THEN e.session_id END) AS sessions
				FROM %i e INNER JOIN %i s ON s.id = e.session_id
				WHERE e.event_name IN (%s, %s) AND e.occurred_at >= %s AND e.occurred_at < %s
				GROUP BY s.channel
				ORDER BY clicks DESC, impressions DESC
				LIMIT 20',
				self::EVENT_IMPRESSION,
				self::EVENT_CLICK,
				self::EVENT_CLICK,
				$events,
				$sessions,
				self::EVENT_IMPRESSION,
				self::EVENT_CLICK,
				$start,
				$end
			),
			ARRAY_A
		);

		$pages = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.page_path AS path, MAX(e.page_title) AS title,
					SUM(e.event_name = %s) AS impressions,
					SUM(e.event_name = %s) AS clicks
				FROM %i e
				WHERE e.event_name IN (%s, %s) AND e.occurred_at >= %s AND e.occurred_at < %s
				GROUP BY e.page_path
				ORDER BY clicks DESC, impressions DESC
				LIMIT 50',
				self::EVENT_IMPRESSION,
				self::EVENT_CLICK,
				$events,
				self::EVENT_IMPRESSION,
				self::EVENT_CLICK,
				$start,
				$end
			),
			ARRAY_A
		);

		$click_sessions = (int) ( $summary['click_sessions'] ?? 0 );

		return [
			'impressions'         => (int) ( $summary['impressions'] ?? 0 ),
			'clicks'              => (int) ( $summary['clicks'] ?? 0 ),
			'click_sessions'      => $click_sessions,
			'converting_sessions' => $converting,
			'conversion_rate'     => $click_sessions > 0 ? round( $converting / $click_sessions, 4 ) : 0.0,
			'channels'            => array_map(
				static fn( array $r ): array => [
					'channel'     => (string) $r['channel'],
					'impressions' => (int) $r['impressions'],
					'clicks'      => (int) $r['clicks'],
					'sessions'    => (int) $r['sessions'],
					'ctr'         => self::ctr( (int) $r['clicks'], (int) $r['impressions'] ),
				],
				$channels
			),
			'pages'               => array_map(
				static fn( array $r ): array => [
					'path'        => (string) $r['path'],
					'title'       => (string) $r['title'],
					'impressions' => (int) $r['impressions'],
					'clicks'      => (int) $r['clicks'],
					'ctr'         => self::ctr( (int) $r['clicks'], (int) $r['impressions'] ),
				],
				$pages
			),
		];
	}

	/**
	 * Click-through rate as a percentage with two decimals, matching Local Ads.
	 *
	 * @param int $clicks      Clicks.
	 * @param int $impressions Impressions.
	 */
	public static function ctr( int $clicks, int $impressions ): float {
		return $impressions > 0 ? round( $clicks / $impressions * 100, 2 ) : 0.0;
	}
}
