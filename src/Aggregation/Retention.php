<?php
/**
 * Data retention.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Aggregation;

use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Jobs;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes data older than the configured retention, in small batches so large tables are never
 * locked for long. If a run hits its batch budget it queues itself again.
 */
final class Retention implements Hookable {

	public const HOOK = 'blue_lens_retention';

	private const BATCH       = 5000;
	private const MAX_BATCHES = 40;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_filter(
			'blue_lens_recurring_jobs',
			static function ( array $jobs ): array {
				$jobs[ self::HOOK ] = DAY_IN_SECONDS;
				return $jobs;
			}
		);
	}

	/**
	 * Job callback.
	 *
	 * @return array<string, int> Rows deleted per table.
	 */
	public function run(): array {
		$deleted = [];
		$budget  = self::MAX_BATCHES;

		$raw_months = (int) $this->settings->get( 'retention_raw_months' );
		if ( $raw_months > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$raw_months} months" ) );

			$deleted[ Tables::EVENTS ]   = $this->delete_batches( Tables::EVENTS, 'occurred_at', $cutoff, $budget );
			$deleted[ Tables::SESSIONS ] = $this->delete_batches( Tables::SESSIONS, 'started_at', $cutoff, $budget );
		}

		$aggregate_months = (int) $this->settings->get( 'retention_aggregate_months' );
		if ( $aggregate_months > 0 ) {
			$cutoff_day = wp_date( 'Y-m-d', (int) strtotime( "-{$aggregate_months} months" ) );

			foreach ( [ Tables::DAILY_TRAFFIC, Tables::DAILY_DIMENSIONS, Tables::DAILY_CONTENT, Tables::DAILY_EVENTS, Tables::HEATMAP_DAILY, Tables::CRAWLER_DAILY ] as $table ) {
				$deleted[ $table ] = $this->delete_batches( $table, 'day', (string) $cutoff_day, $budget );
			}
		}

		if ( $budget <= 0 ) {
			Jobs::enqueue( self::HOOK );
		}

		/**
		 * Fires after a retention run.
		 *
		 * @param array<string, int> $deleted Rows deleted per table key.
		 */
		do_action( 'blue_lens_retention_completed', $deleted );

		return $deleted;
	}

	/**
	 * Deletes rows older than a cutoff in batches.
	 *
	 * @param string $table  Table key.
	 * @param string $column Date column (fixed identifier).
	 * @param string $cutoff Rows with column < cutoff are deleted.
	 * @param int    $budget Remaining batches (decremented).
	 */
	private function delete_batches( string $table, string $column, string $cutoff, int &$budget ): int {
		global $wpdb;

		$total = 0;
		while ( $budget > 0 ) {
			--$budget;
			$rows = (int) $wpdb->query(
				$wpdb->prepare( 'DELETE FROM %i WHERE %i < %s LIMIT %d', Tables::name( $table ), $column, $cutoff, self::BATCH )
			);
			$total += $rows;
			if ( $rows < self::BATCH ) {
				++$budget; // The last, partial batch does not use up the budget.
				break;
			}
		}

		return $total;
	}
}
