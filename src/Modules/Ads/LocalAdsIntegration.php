<?php
/**
 * Integration with the Local Ads by Bernard plugin.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Modules\Ads;

use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Tracking\TrackerLoader;

defined( 'ABSPATH' ) || exit;

/**
 * Records Local Ads popups as Blue Lens events.
 *
 * Local Ads (1.0.1+) dispatches a "localads:track" DOM event each time it shows an ad or a visitor
 * clicks one. A small bridge script turns these into ad_impression / ad_click events on the
 * Blue Lens tracker, so they inherit the visitor's session, channel, page and consent state.
 * Only the ad ID is recorded; names and campaigns are looked up from Local Ads when reporting.
 */
final class LocalAdsIntegration implements Hookable {

	public const BRIDGE_HANDLE = 'blue-lens-local-ads';

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'blue_lens_event_registry', [ $this, 'register_events' ] );

		if ( ! is_admin() ) {
			// After TrackerLoader::enqueue() (priority 10), so we know whether the tracker loads.
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_bridge' ], 20 );
		}
	}

	/**
	 * Adds the ad events to the registry.
	 *
	 * @param mixed $events Event definitions.
	 * @return array<string, mixed>
	 */
	public function register_events( mixed $events ): array {
		$events = (array) $events;

		foreach ( [ LocalAdsReport::EVENT_IMPRESSION, LocalAdsReport::EVENT_CLICK ] as $name ) {
			$events[ $name ] = [
				'category'   => 'advertising',
				'module'     => 'local_ads',
				'conversion' => false,
			];
		}

		return $events;
	}

	/**
	 * Loads the bridge on pages where both the tracker and Local Ads run.
	 */
	public function enqueue_bridge(): void {
		if ( ! LocalAdsReport::available() || ! wp_script_is( TrackerLoader::HANDLE, 'enqueued' ) ) {
			return;
		}

		/**
		 * Filters whether Local Ads impressions and clicks are recorded by Blue Lens.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'blue_lens_local_ads_tracking', true ) ) {
			return;
		}

		wp_enqueue_script(
			self::BRIDGE_HANDLE,
			BLA_URL . 'assets/integrations/local-ads.js',
			[ TrackerLoader::HANDLE ],
			BLA_VERSION,
			[
				'strategy'  => 'defer',
				'in_footer' => false,
			]
		);
	}
}
