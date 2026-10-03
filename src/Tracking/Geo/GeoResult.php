<?php
/**
 * Geo lookup result.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking\Geo;

defined( 'ABSPATH' ) || exit;

/**
 * Country (ISO 3166-1 alpha-2), region and city. Empty strings when unknown.
 */
final class GeoResult {

	/**
	 * Constructor.
	 *
	 * @param string $country ISO country code.
	 * @param string $region  Region/subdivision name.
	 * @param string $city    City name.
	 */
	public function __construct(
		public readonly string $country = '',
		public readonly string $region = '',
		public readonly string $city = ''
	) {}
}
