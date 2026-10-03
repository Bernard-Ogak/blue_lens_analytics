<?php
/**
 * Site-time-zone date helpers.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Aggregation;

defined( 'ABSPATH' ) || exit;

/**
 * Reports use calendar days in the site's time zone; raw data is stored in UTC.
 */
final class SiteTime {

	/**
	 * Today's date in the site time zone.
	 */
	public static function today(): string {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Whether a string is a valid Y-m-d date.
	 *
	 * @param mixed $date Candidate.
	 */
	public static function is_date( mixed $date ): bool {
		if ( ! is_string( $date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		[ $y, $m, $d ] = array_map( 'intval', explode( '-', $date ) );

		return checkdate( $m, $d, $y );
	}

	/**
	 * UTC datetime bounds [start, end) of a site-local day range.
	 *
	 * @param string $from First day (Y-m-d, site time).
	 * @param string $to   Last day, inclusive.
	 * @return array{0: string, 1: string} UTC "Y-m-d H:i:s" strings.
	 */
	public static function utc_bounds( string $from, string $to ): array {
		$tz    = wp_timezone();
		$utc   = new \DateTimeZone( 'UTC' );
		$start = new \DateTimeImmutable( $from . ' 00:00:00', $tz );
		$end   = ( new \DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' );

		return [
			$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Adds days to a date.
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days Days (negative to subtract).
	 */
	public static function add_days( string $date, int $days ): string {
		return ( new \DateTimeImmutable( $date, wp_timezone() ) )->modify( sprintf( '%+d day', $days ) )->format( 'Y-m-d' );
	}

	/**
	 * Inclusive number of days between two dates.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function span( string $from, string $to ): int {
		$a = new \DateTimeImmutable( $from, wp_timezone() );
		$b = new \DateTimeImmutable( $to, wp_timezone() );

		return (int) $a->diff( $b )->days + 1;
	}

	/**
	 * Every date in a range, inclusive.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return list<string>
	 */
	public static function days( string $from, string $to ): array {
		$days = [];
		$day  = $from;
		for ( $i = 0; $i < 3700 && $day <= $to; $i++ ) {
			$days[] = $day;
			$day    = self::add_days( $day, 1 );
		}

		return $days;
	}

	/**
	 * Site-local date of a UTC datetime.
	 *
	 * @param string $utc "Y-m-d H:i:s" in UTC.
	 */
	public static function local_day( string $utc ): string {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}
}
