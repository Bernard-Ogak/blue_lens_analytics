<?php
/**
 * REST: POST blue-lens/v1/collect (public).
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

use BlueLens\Analytics\Admin\Rest\SettingsController;
use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Settings;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Public collection endpoint.
 *
 * - Accepts text/plain JSON (sendBeacon) so no CORS preflight is needed.
 * - Uses no nonce, so it works on fully cached pages; abuse is bounded by host checks, payload
 *   limits, rate limiting and a per-session event cap.
 * - Always answers 204 so the response reveals nothing about filtering decisions.
 */
final class CollectController implements Hookable {

	public const ROUTE    = '/collect';
	public const MAX_BODY = 16384;

	/**
	 * Constructor.
	 *
	 * @param Collector $collector Collector.
	 * @param Settings  $settings  Settings.
	 */
	public function __construct(
		private Collector $collector,
		private Settings $settings
	) {}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			SettingsController::ROUTE_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handles a beacon.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_body();

		if ( strlen( $body ) > self::MAX_BODY ) {
			return $this->respond( 413 );
		}

		$payload = json_decode( $body, true, 8 );
		if ( ! is_array( $payload ) ) {
			return $this->respond( 400 );
		}

		$this->collector->collect( $payload, RequestContext::from_globals( (string) $this->settings->get( 'ip_header' ) ) );

		return $this->respond( 204 );
	}

	/**
	 * Empty, uncacheable response.
	 *
	 * @param int $status HTTP status.
	 */
	private function respond( int $status ): WP_REST_Response {
		$response = new WP_REST_Response( null, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
