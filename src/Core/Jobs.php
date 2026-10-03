<?php
/**
 * Background job scheduling.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over Action Scheduler with a WP-Cron fallback when Action Scheduler is not installed.
 *
 * Recurring jobs are declared through the blue_lens_recurring_jobs filter (hook => interval seconds)
 * and registered at most once per hour from admin or cron requests, never on front-end page views.
 */
final class Jobs implements Hookable {

	private const CHECK_TRANSIENT = 'blue_lens_jobs_checked';

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'ensure_recurring_jobs' ] );
		add_action( 'action_scheduler_init', [ $this, 'maybe_ensure_from_cron' ] );
	}

	/**
	 * Whether Action Scheduler is loaded.
	 */
	public static function has_action_scheduler(): bool {
		return function_exists( 'as_schedule_recurring_action' );
	}

	/**
	 * Declared recurring jobs.
	 *
	 * @return array<string, int> Hook => interval in seconds.
	 */
	public static function recurring(): array {
		/**
		 * Filters recurring background jobs.
		 *
		 * @param array<string, int> $jobs Hook => interval seconds.
		 */
		$jobs = (array) apply_filters( 'blue_lens_recurring_jobs', [] );

		return array_filter( array_map( 'intval', $jobs ), static fn( int $interval ): bool => $interval >= 60 );
	}

	/**
	 * Registers missing recurring jobs (throttled to once per hour).
	 */
	public function ensure_recurring_jobs(): void {
		if ( false !== get_transient( self::CHECK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::CHECK_TRANSIENT, 1, HOUR_IN_SECONDS );

		foreach ( self::recurring() as $hook => $interval ) {
			self::ensure( (string) $hook, $interval );
		}
	}

	/**
	 * Registers jobs when cron runs, for sites where nobody opens wp-admin.
	 */
	public function maybe_ensure_from_cron(): void {
		if ( wp_doing_cron() ) {
			$this->ensure_recurring_jobs();
		}
	}

	/**
	 * Schedules a recurring job unless already scheduled.
	 *
	 * @param string $hook     Action hook.
	 * @param int    $interval Seconds.
	 */
	public static function ensure( string $hook, int $interval ): void {
		// Spread first runs so network sites do not all fire at once.
		$first = time() + wp_rand( 60, 3600 );

		if ( self::has_action_scheduler() ) {
			if ( ! as_has_scheduled_action( $hook, [], Plugin::ACTION_GROUP ) ) {
				as_schedule_recurring_action( $first, $interval, $hook, [], Plugin::ACTION_GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( $first, self::cron_recurrence( $interval ), $hook );
		}
	}

	/**
	 * Runs a job in the background as soon as possible.
	 *
	 * @param string       $hook Action hook.
	 * @param array<mixed> $args Arguments.
	 */
	public static function enqueue( string $hook, array $args = [] ): void {
		if ( self::has_action_scheduler() ) {
			if ( ! as_has_scheduled_action( $hook, $args, Plugin::ACTION_GROUP ) ) {
				as_enqueue_async_action( $hook, $args, Plugin::ACTION_GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time(), $hook, $args );
		}
	}

	/**
	 * Cancels every job on the current site.
	 */
	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], Plugin::ACTION_GROUP );
		}

		foreach ( array_keys( self::recurring() ) as $hook ) {
			wp_clear_scheduled_hook( (string) $hook );
		}

		delete_transient( self::CHECK_TRANSIENT );
	}

	/**
	 * Closest built-in WP-Cron recurrence.
	 *
	 * @param int $interval Seconds.
	 */
	private static function cron_recurrence( int $interval ): string {
		if ( $interval >= WEEK_IN_SECONDS ) {
			return 'weekly';
		}
		if ( $interval >= DAY_IN_SECONDS ) {
			return 'daily';
		}
		if ( $interval >= 12 * HOUR_IN_SECONDS ) {
			return 'twicedaily';
		}

		return 'hourly';
	}
}
