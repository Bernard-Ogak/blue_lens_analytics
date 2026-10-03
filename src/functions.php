<?php
/**
 * Public PHP API.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

use BlueLens\Analytics\Core\Plugin;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Tracking\Collector;
use BlueLens\Analytics\Tracking\RequestContext;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'blue_lens_track' ) ) {
	/**
	 * Records a server-side event (orders, bookings, refunds), linked to the visitor's session
	 * when called during the visitor's own request.
	 *
	 * Example:
	 *     blue_lens_track( 'purchase', [
	 *         'category'    => 'conversion',
	 *         'module'      => 'ecommerce',
	 *         'entity_type' => 'order',
	 *         'entity_id'   => $order_id,
	 *         'value'       => 1200.00,
	 *         'currency'    => 'USD',
	 *         'attributes'  => [ 'items' => 3 ],
	 *     ] );
	 *
	 * @param string               $event Event name ([a-z][a-z0-9_]{1,63}).
	 * @param array<string, mixed> $props Unified-schema fields; optional "session_key" (32 hex) and "page_path".
	 * @return bool Whether the event was stored.
	 */
	function blue_lens_track( string $event, array $props = [] ): bool {
		if ( ! did_action( 'plugins_loaded' ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'Call blue_lens_track() after plugins_loaded.', 'blue-lens-analytics' ), '0.2.0' );
			return false;
		}

		$container = Plugin::instance()->container();
		$settings  = $container->get( Settings::class );

		$in_request = isset( $_SERVER['REMOTE_ADDR'] ) && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI );
		$request    = $in_request ? RequestContext::from_globals( (string) $settings->get( 'ip_header' ) ) : null;

		$result = $container->get( Collector::class )->record_server_event( [ 'event' => $event ] + $props, $request );

		return 'stored' === $result->status;
	}
}
