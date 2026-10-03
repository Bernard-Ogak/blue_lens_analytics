<?php
/**
 * Multisite helpers.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs per-site work across a network.
 */
final class Network {

	/**
	 * Site IDs in the current network.
	 *
	 * @param int  $limit       Maximum sites, 0 for all.
	 * @param bool $active_only Skip archived, deleted and spam sites.
	 * @return list<int>
	 */
	public static function site_ids( int $limit = 0, bool $active_only = true ): array {
		$args = [
			'fields' => 'ids',
			'number' => $limit,
		];

		if ( $active_only ) {
			$args['archived'] = 0;
			$args['deleted']  = 0;
			$args['spam']     = 0;
		}

		return array_values( array_map( 'intval', (array) get_sites( $args ) ) );
	}

	/**
	 * Runs a callback in the context of each site.
	 *
	 * @param callable(int):void $callback    Receives the site ID.
	 * @param int                $limit       Maximum sites, 0 for all.
	 * @param bool               $active_only Skip archived, deleted and spam sites.
	 */
	public static function each_site( callable $callback, int $limit = 0, bool $active_only = true ): void {
		foreach ( self::site_ids( $limit, $active_only ) as $site_id ) {
			switch_to_blog( $site_id );
			try {
				$callback( $site_id );
			} finally {
				restore_current_blog();
			}
		}
	}
}
