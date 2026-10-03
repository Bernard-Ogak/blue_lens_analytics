<?php
/**
 * Fixed-window rate limiting for the collection endpoint.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Counts requests per visitor key in a persistent object cache or APCu. Without either, it allows
 * every request (a database counter would cost a write per hit); the per-session event cap in the
 * collector still bounds abuse.
 */
final class RateLimiter {

	private const GROUP = 'blue_lens_rl';

	/**
	 * Whether another request is allowed.
	 *
	 * @param string $key    Bucket identity (hex visitor key).
	 * @param int    $limit  Requests per window.
	 * @param int    $window Window length in seconds.
	 */
	public function allow( string $key, int $limit, int $window = 60 ): bool {
		$bucket = $key . ':' . intdiv( time(), $window );

		if ( wp_using_ext_object_cache() ) {
			$count = wp_cache_incr( $bucket, 1, self::GROUP );
			if ( false === $count ) {
				wp_cache_add( $bucket, 0, self::GROUP, $window );
				$count = wp_cache_incr( $bucket, 1, self::GROUP );
			}
			return false === $count || $count <= $limit;
		}

		if ( function_exists( 'apcu_inc' ) && function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
			$count = apcu_inc( 'bla_rl_' . $bucket, 1, $success, $window );
			return false === $count || $count <= $limit;
		}

		return true;
	}
}
