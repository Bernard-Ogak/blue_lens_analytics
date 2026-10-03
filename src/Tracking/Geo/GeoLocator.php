<?php
/**
 * IP to location lookup.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking\Geo;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up country/region/city in the local database; falls back to Cloudflare's CF-IPCountry.
 * Called once per new session; the IP is discarded by the caller afterwards.
 */
final class GeoLocator {

	/**
	 * Open reader, reused within the request.
	 *
	 * @var \MaxMind\Db\Reader|null
	 */
	private ?\MaxMind\Db\Reader $reader = null;

	/**
	 * Constructor.
	 *
	 * @param GeoDatabase $database Database manager.
	 */
	public function __construct( private GeoDatabase $database ) {}

	/**
	 * Resolves a location.
	 *
	 * @param string $ip         Visitor IP.
	 * @param string $cf_country CF-IPCountry header value ('' when absent).
	 */
	public function lookup( string $ip, string $cf_country = '' ): GeoResult {
		/**
		 * Short-circuits the lookup, e.g. for a custom provider. Return a GeoResult to use it.
		 *
		 * @param GeoResult|null $result Null to continue.
		 * @param string         $ip     Visitor IP (in memory only; do not persist).
		 */
		$pre = apply_filters( 'blue_lens_geo_lookup', null, $ip );
		if ( $pre instanceof GeoResult ) {
			return $pre;
		}

		$result = '' !== $ip ? $this->from_database( $ip ) : new GeoResult();

		if ( '' === $result->country && 1 === preg_match( '/^[A-Z]{2}$/', $cf_country ) && ! in_array( $cf_country, [ 'XX', 'T1' ], true ) ) {
			$result = new GeoResult( $cf_country );
		}

		return $result;
	}

	/**
	 * Database lookup.
	 *
	 * @param string $ip Visitor IP.
	 */
	private function from_database( string $ip ): GeoResult {
		if ( ! $this->database->is_available() ) {
			return new GeoResult();
		}

		try {
			$this->reader ??= new \MaxMind\Db\Reader( $this->database->path() );
			$record         = $this->reader->get( $ip );
		} catch ( \Throwable $e ) {
			return new GeoResult();
		}

		if ( ! is_array( $record ) ) {
			return new GeoResult();
		}

		$country = strtoupper( (string) ( $record['country']['iso_code'] ?? '' ) );

		return new GeoResult(
			1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			mb_substr( (string) ( $record['subdivisions'][0]['names']['en'] ?? '' ), 0, 64 ),
			mb_substr( (string) ( $record['city']['names']['en'] ?? '' ), 0, 96 )
		);
	}
}
