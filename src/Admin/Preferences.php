<?php
/**
 * Per-user dashboard preferences.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Each user's look and layout: theme, accent colour, density, KPI tile style, default date range,
 * the KPI tiles shown, and which dashboard cards appear in which order.
 */
final class Preferences {

	public const META_KEY = 'blue_lens_prefs';

	public const THEMES   = [ 'dark', 'light' ];
	public const ACCENTS  = [ 'blue', 'teal', 'violet', 'orange', 'magenta', 'green' ];
	public const DENSITY  = [ 'comfortable', 'compact' ];
	public const TILES    = [ 'vivid', 'subtle' ];
	public const RANGES   = [ 'today', 'yesterday', 'last7', 'last28', 'last30', 'last90', 'this_month', 'last_month', 'year_to_date', 'last12m' ];
	public const COMPARES = [ 'previous', 'year', 'none' ];
	public const KPIS     = [ 'visitors', 'sessions', 'pageviews', 'engagement_rate', 'avg_engaged_time', 'bounce_rate', 'pages_per_session', 'conversions', 'conversion_rate', 'revenue', 'new_visitors', 'returning_visitors' ];
	public const PAGES    = [ 'overview', 'acquisition', 'audience', 'content', 'engagement', 'ads' ];

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'theme'   => 'light',
			'accent'  => 'blue',
			'density' => 'comfortable',
			'tiles'   => 'subtle',
			'range'   => 'last28',
			'compare' => 'previous',
			'kpis'    => [ 'visitors', 'sessions', 'pageviews', 'engagement_rate', 'conversions', 'avg_engaged_time' ],
			'layout'  => (object) [],
		];
	}

	/**
	 * Preferences for a user, merged over defaults.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	public static function get( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_KEY, true );

		return self::sanitize( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Merges and saves preferences.
	 *
	 * @param int          $user_id User ID.
	 * @param array<mixed> $values  Partial preferences.
	 * @return array<string, mixed>
	 */
	public static function update( int $user_id, array $values ): array {
		$prefs = self::sanitize( array_merge( self::get( $user_id ), $values ) );
		$store = $prefs;
		// Store layout as an array; it is returned as an object so empty layouts encode as {}.
		$store['layout'] = (array) $prefs['layout'];
		update_user_meta( $user_id, self::META_KEY, $store );

		return $prefs;
	}

	/**
	 * Validates preferences; invalid values fall back to defaults.
	 *
	 * @param array<mixed> $input Raw values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$enum     = static fn( string $key, array $allowed ): string => isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ? (string) $input[ $key ] : (string) $defaults[ $key ];

		$kpis = [];
		if ( isset( $input['kpis'] ) && is_array( $input['kpis'] ) ) {
			foreach ( $input['kpis'] as $kpi ) {
				if ( is_string( $kpi ) && in_array( $kpi, self::KPIS, true ) && ! in_array( $kpi, $kpis, true ) ) {
					$kpis[] = $kpi;
				}
			}
		}

		$layout = [];
		$raw    = isset( $input['layout'] ) ? (array) $input['layout'] : [];
		foreach ( self::PAGES as $page ) {
			if ( ! isset( $raw[ $page ] ) || ! is_array( $raw[ $page ] ) ) {
				continue;
			}
			$items = [];
			foreach ( array_slice( $raw[ $page ], 0, 30 ) as $item ) {
				$item = (array) $item;
				$id   = isset( $item['id'] ) && is_string( $item['id'] ) ? $item['id'] : '';
				if ( 1 === preg_match( '/^[a-z_]{1,32}$/', $id ) ) {
					$items[] = [
						'id' => $id,
						'on' => ! empty( $item['on'] ),
					];
				}
			}
			$layout[ $page ] = $items;
		}

		return [
			'theme'   => $enum( 'theme', self::THEMES ),
			'accent'  => $enum( 'accent', self::ACCENTS ),
			'density' => $enum( 'density', self::DENSITY ),
			'tiles'   => $enum( 'tiles', self::TILES ),
			'range'   => $enum( 'range', self::RANGES ),
			'compare' => $enum( 'compare', self::COMPARES ),
			'kpis'    => $kpis ? array_slice( $kpis, 0, 8 ) : $defaults['kpis'],
			'layout'  => (object) $layout,
		];
	}
}
