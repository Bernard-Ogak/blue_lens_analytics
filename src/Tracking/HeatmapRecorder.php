<?php
/**
 * Click heatmap aggregation.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Stores heatmap clicks as daily counters per page, device class and grid cell — never one row
 * per click, and with no visitor or session link.
 *
 * Grid: x = percent of document width (0-99), y = 20 px rows from the top of the document (0-999).
 */
final class HeatmapRecorder {

	public const MAX_POINTS = 300;
	public const ROW_PX     = 20;
	public const MAX_ROW    = 999;

	/**
	 * Records a batch of points.
	 *
	 * @param string $path   Page path.
	 * @param string $device desktop|tablet|mobile|tv.
	 * @param mixed  $points Raw [[x, y], ...].
	 * @param int    $time   Unix time.
	 * @return int Points recorded.
	 */
	public function record( string $path, string $device, mixed $points, int $time ): int {
		global $wpdb;

		if ( ! is_array( $points ) ) {
			return 0;
		}

		$cells = [];
		$total = 0;
		foreach ( array_slice( array_values( $points ), 0, self::MAX_POINTS ) as $point ) {
			if ( ! is_array( $point ) || ! isset( $point[0], $point[1] ) || ! is_numeric( $point[0] ) || ! is_numeric( $point[1] ) ) {
				continue;
			}
			$x = (int) $point[0];
			$y = (int) $point[1];
			if ( $x < 0 || $x > 99 || $y < 0 || $y > self::MAX_ROW ) {
				continue;
			}
			$key           = $x . ':' . $y;
			$cells[ $key ] = ( $cells[ $key ] ?? 0 ) + 1;
			++$total;
		}

		if ( ! $cells ) {
			return 0;
		}

		$day    = gmdate( 'Y-m-d', $time );
		$hash   = md5( $path );
		$values = [];
		foreach ( $cells as $key => $clicks ) {
			[ $x, $y ] = array_map( 'intval', explode( ':', (string) $key ) );
			$values[]  = $wpdb->prepare( '(%s, UNHEX(%s), %s, %s, %d, %d, %d)', $day, $hash, $path, $device, $x, $y, $clicks );
		}

		$sql = $wpdb->prepare( 'INSERT INTO %i (day, path_hash, path, device, x_bucket, y_bucket, clicks) VALUES ', Tables::name( Tables::HEATMAP_DAILY ) )
			. implode( ',', $values )
			. ' ON DUPLICATE KEY UPDATE clicks = clicks + VALUES(clicks)';

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tuples prepared above.

		return $total;
	}
}
