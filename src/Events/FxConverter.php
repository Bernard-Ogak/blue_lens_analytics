<?php
/**
 * Currency normalization.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Events;

use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Converts values to the site base currency using bla_fx_rates.
 *
 * A rate row means: 1 unit of base_currency = rate units of quote_currency. The latest rate on or
 * before the event date is used. When no rate exists the base value is left NULL (and can be
 * backfilled by aggregation once rates arrive); the original value and currency are always kept.
 */
final class FxConverter {

	/**
	 * Rates resolved in this request, keyed "CUR|Y-m-d".
	 *
	 * @var array<string, float|null>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Converts a value to the base currency.
	 *
	 * @param float  $value    Amount.
	 * @param string $currency ISO 4217 code.
	 * @param string $date     Y-m-d (UTC).
	 * @return float|null Null when no rate is known.
	 */
	public function to_base( float $value, string $currency, string $date ): ?float {
		$base = (string) $this->settings->get( 'base_currency' );

		if ( '' === $currency || $currency === $base ) {
			return $value;
		}

		$rate = $this->rate( $base, $currency, $date );

		return null === $rate ? null : round( $value / $rate, 4 );
	}

	/**
	 * Units of $quote per 1 $base on or before $date.
	 *
	 * @param string $base  Base currency.
	 * @param string $quote Quote currency.
	 * @param string $date  Y-m-d.
	 */
	private function rate( string $base, string $quote, string $date ): ?float {
		global $wpdb;

		$key = $quote . '|' . $date;
		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}

		$table = Tables::name( Tables::FX_RATES );
		$rate  = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT rate FROM %i WHERE base_currency = %s AND quote_currency = %s AND rate_date <= %s ORDER BY rate_date DESC LIMIT 1',
				$table,
				$base,
				$quote,
				$date
			)
		);

		if ( null === $rate ) {
			// Inverse row: 1 quote = r base, so 1 base = 1/r quote.
			$inverse = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT rate FROM %i WHERE base_currency = %s AND quote_currency = %s AND rate_date <= %s ORDER BY rate_date DESC LIMIT 1',
					$table,
					$quote,
					$base,
					$date
				)
			);
			$rate    = null !== $inverse && (float) $inverse > 0 ? 1 / (float) $inverse : null;
		}

		$rate               = null !== $rate && (float) $rate > 0 ? (float) $rate : null;
		$this->memo[ $key ] = $rate;

		return $rate;
	}
}
