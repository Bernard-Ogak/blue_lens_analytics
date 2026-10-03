<?php
/**
 * Client IP resolution, truncation and range matching.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * IP helpers. The IP is used in memory for hashing, exclusion and geo lookup, and is never stored.
 */
final class ClientIp {

	/**
	 * Resolves the visitor IP from the configured server variable.
	 *
	 * @param string               $source Settings value, e.g. "remote_addr" or "http_cf_connecting_ip".
	 * @param array<string, mixed> $server $_SERVER-like array.
	 * @return string Valid IP, or '' when none.
	 */
	public static function resolve( string $source, array $server ): string {
		$key = strtoupper( $source );

		if ( 'REMOTE_ADDR' !== $key && ! empty( $server[ $key ] ) && is_string( $server[ $key ] ) ) {
			// X-Forwarded-For: client, proxy1, proxy2 — the left-most valid address is the client.
			foreach ( explode( ',', $server[ $key ] ) as $candidate ) {
				$ip = filter_var( trim( $candidate ), FILTER_VALIDATE_IP );
				if ( false !== $ip ) {
					return $ip;
				}
			}
		}

		$remote = isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '';
		$ip     = filter_var( trim( $remote ), FILTER_VALIDATE_IP );

		return false === $ip ? '' : $ip;
	}

	/**
	 * Truncates an IP before hashing: IPv4 to /24, IPv6 to /48.
	 *
	 * @param string $ip Valid IP.
	 */
	public static function truncate( string $ip ): string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		if ( false === $packed ) {
			return '';
		}

		if ( 4 === strlen( $packed ) ) {
			return (string) inet_ntop( substr( $packed, 0, 3 ) . "\0" );
		}

		return (string) inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) );
	}

	/**
	 * Whether an IP matches any address or CIDR range in the list.
	 *
	 * @param string       $ip   IP to test.
	 * @param list<string> $list Addresses and CIDR ranges.
	 */
	public static function matches( string $ip, array $list ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		if ( false === $packed ) {
			return false;
		}

		foreach ( $list as $entry ) {
			[ $range, $bits ] = array_pad( explode( '/', $entry, 2 ), 2, null );

			$range_packed = @inet_pton( (string) $range ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $range_packed || strlen( $range_packed ) !== strlen( $packed ) ) {
				continue;
			}

			$bits = null === $bits ? strlen( $packed ) * 8 : (int) $bits;
			if ( self::prefix_equal( $packed, $range_packed, $bits ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compares the first $bits bits of two packed addresses.
	 *
	 * @param string $a    Packed address.
	 * @param string $b    Packed address.
	 * @param int    $bits Prefix length.
	 */
	private static function prefix_equal( string $a, string $b, int $bits ): bool {
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}

		$remainder = $bits % 8;
		if ( 0 === $remainder ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $remainder ) ) & 0xFF;

		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}
}
