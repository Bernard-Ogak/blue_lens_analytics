<?php
/**
 * Visitor key derivation.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Privacy;

use BlueLens\Analytics\Core\Installer;
use BlueLens\Analytics\Tracking\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * Produces 16-byte visitor keys. Neither the IP nor the first-party ID is ever stored.
 *
 * - Cookieless: HMAC(daily salt, truncated IP | user agent | site). Changes every UTC day.
 * - Enhanced (after consent): HMAC(site salt, first-party random ID). Stable while the ID is kept.
 */
final class VisitorHasher {

	/**
	 * Constructor.
	 *
	 * @param DailySalt $daily_salt Daily salt source.
	 */
	public function __construct( private DailySalt $daily_salt ) {}

	/**
	 * Cookieless visitor key.
	 *
	 * @param string $ip         Visitor IP.
	 * @param string $user_agent User agent.
	 * @param int    $time       Unix time (selects the day's salt).
	 * @return string 16 raw bytes.
	 */
	public function cookieless( string $ip, string $user_agent, int $time ): string {
		$data = ClientIp::truncate( $ip ) . '|' . $user_agent . '|' . home_url();

		return substr( hash_hmac( 'sha256', $data, $this->daily_salt->for_day( gmdate( 'Ymd', $time ) ), true ), 0, 16 );
	}

	/**
	 * Enhanced-mode visitor key.
	 *
	 * @param string $client_id Validated first-party ID (32 hex chars).
	 * @return string 16 raw bytes.
	 */
	public function enhanced( string $client_id ): string {
		$salt = (string) get_option( Installer::SALT_OPTION, '' );

		return substr( hash_hmac( 'sha256', 'vid|' . $client_id, $salt, true ), 0, 16 );
	}

	/**
	 * Whether a first-party ID has the expected format.
	 *
	 * @param mixed $client_id Candidate.
	 */
	public static function is_valid_client_id( mixed $client_id ): bool {
		return is_string( $client_id ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $client_id );
	}
}
