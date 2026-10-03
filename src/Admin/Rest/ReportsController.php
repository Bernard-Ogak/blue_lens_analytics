<?php
/**
 * REST: blue-lens/v1/reports/*, /preferences.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Admin\Rest;

use BlueLens\Analytics\Admin\Preferences;
use BlueLens\Analytics\Aggregation\Aggregator;
use BlueLens\Analytics\Aggregation\Reports;
use BlueLens\Analytics\Aggregation\SiteTime;
use BlueLens\Analytics\Core\Capabilities;
use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Modules\Ads\LocalAdsReport;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Report endpoints for the dashboard (view_blue_lens_reports) and the current user's preferences.
 */
final class ReportsController implements Hookable {

	/**
	 * Longest range a single request may cover.
	 */
	private const MAX_DAYS = 800;

	/**
	 * Constructor.
	 *
	 * @param Reports        $reports    Report queries.
	 * @param Aggregator     $aggregator Aggregator.
	 * @param LocalAdsReport $local_ads  Local Ads report.
	 */
	public function __construct(
		private Reports $reports,
		private Aggregator $aggregator,
		private LocalAdsReport $local_ads
	) {}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$ns    = SettingsController::ROUTE_NAMESPACE;
		$range = [
			'from' => [
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^\\d{4}-\\d{2}-\\d{2}$',
			],
			'to'   => [
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^\\d{4}-\\d{2}-\\d{2}$',
			],
		];
		$view  = [ $this, 'can_view' ];

		register_rest_route(
			$ns,
			'/reports/overview',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->respond( $r, fn( string $from, string $to ) => $this->reports->overview( $from, $to, (string) $r['compare'] ) ),
				'permission_callback' => $view,
				'args'                => $range + [
					'compare' => [
						'type'    => 'string',
						'enum'    => Preferences::COMPARES,
						'default' => 'previous',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/reports/dimension',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->respond( $r, fn( string $from, string $to ) => $this->reports->dimension( (string) $r['dimension'], $from, $to, (int) $r['limit'], (string) $r['orderby'] ) ),
				'permission_callback' => $view,
				'args'                => $range + [
					'dimension' => [
						'type'     => 'string',
						'enum'     => Aggregator::dimensions(),
						'required' => true,
					],
					'limit'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
						'default' => 10,
					],
					'orderby'   => [
						'type'    => 'string',
						'enum'    => [ 'sessions', 'visitors', 'engaged_sessions', 'pageviews', 'conversions', 'revenue' ],
						'default' => 'sessions',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/reports/content',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->respond( $r, fn( string $from, string $to ) => $this->reports->content( (string) $r['group'], $from, $to, (int) $r['limit'] ) ),
				'permission_callback' => $view,
				'args'                => $range + [
					'group' => [
						'type'              => 'string',
						'default'           => 'page',
						'validate_callback' => [ self::class, 'valid_content_group' ],
					],
					'limit' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
						'default' => 10,
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/reports/events',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->respond( $r, fn( string $from, string $to ) => $this->reports->events( $from, $to ) ),
				'permission_callback' => $view,
				'args'                => $range,
			]
		);

		register_rest_route(
			$ns,
			'/reports/crawlers',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->respond( $r, fn( string $from, string $to ) => $this->reports->crawlers( $from, $to ) ),
				'permission_callback' => $view,
				'args'                => $range,
			]
		);

		register_rest_route(
			$ns,
			'/reports/local-ads',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->respond( $r, fn( string $from, string $to ) => $this->local_ads->report( $from, $to ) ),
				'permission_callback' => $view,
				'args'                => $range,
			]
		);

		$device = [
			'type'    => 'string',
			'enum'    => [ 'all', 'desktop', 'tablet', 'mobile' ],
			'default' => 'desktop',
		];

		register_rest_route(
			$ns,
			'/reports/heatmap-pages',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->respond( $r, fn( string $from, string $to ) => [ 'pages' => $this->reports->heatmap_pages( $from, $to, (string) $r['device'] ) ] ),
				'permission_callback' => $view,
				'args'                => $range + [ 'device' => $device ],
			]
		);

		register_rest_route(
			$ns,
			'/reports/heatmap',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->respond( $r, fn( string $from, string $to ) => $this->reports->heatmap( (string) $r['path'], (string) $r['device'], $from, $to ) ),
				'permission_callback' => $view,
				'args'                => $range + [
					'device' => $device,
					'path'   => [
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 512,
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/reports/realtime',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => $this->no_store( new WP_REST_Response( $this->reports->realtime() ) ),
				'permission_callback' => $view,
			]
		);

		register_rest_route(
			$ns,
			'/reports/refresh',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => fn() => new WP_REST_Response( [ 'last_aggregated' => $this->aggregator->refresh_today() ] ),
				'permission_callback' => $view,
			]
		);

		register_rest_route(
			$ns,
			'/preferences',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn() => new WP_REST_Response( Preferences::get( get_current_user_id() ) ),
					'permission_callback' => $view,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_preferences' ],
					'permission_callback' => $view,
				],
			]
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return true|WP_Error
	 */
	public function can_view(): bool|WP_Error {
		return current_user_can( Capabilities::VIEW )
			? true
			: new WP_Error( 'rest_forbidden', __( 'You are not allowed to view Blue Lens reports.', 'blue-lens-analytics' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * Saves the current user's preferences.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function update_preferences( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'rest_invalid_json', __( 'Send preferences as a JSON object.', 'blue-lens-analytics' ), [ 'status' => 400 ] );
		}

		return new WP_REST_Response( Preferences::update( get_current_user_id(), $params ) );
	}

	/**
	 * Validates a content grouping ("page", "post_type", "author", "age" or "tax:{public taxonomy}").
	 *
	 * @param mixed $group Candidate.
	 */
	public static function valid_content_group( mixed $group ): bool {
		if ( ! is_string( $group ) ) {
			return false;
		}
		if ( in_array( $group, Reports::CONTENT_GROUPS, true ) ) {
			return true;
		}
		if ( str_starts_with( $group, 'tax:' ) ) {
			$taxonomy = get_taxonomy( substr( $group, 4 ) );
			return $taxonomy && $taxonomy->public;
		}

		return false;
	}

	/**
	 * Validates the date range and runs a report.
	 *
	 * @param WP_REST_Request                        $request Request.
	 * @param callable(string, string): array<mixed> $report  Report producer.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private function respond( WP_REST_Request $request, callable $report ): WP_REST_Response|WP_Error {
		$from = (string) $request['from'];
		$to   = (string) $request['to'];

		if ( ! SiteTime::is_date( $from ) || ! SiteTime::is_date( $to ) || $from > $to ) {
			return new WP_Error( 'rest_invalid_param', __( 'Invalid date range.', 'blue-lens-analytics' ), [ 'status' => 400 ] );
		}
		if ( SiteTime::span( $from, $to ) > self::MAX_DAYS ) {
			return new WP_Error( 'rest_invalid_param', __( 'The date range is too long.', 'blue-lens-analytics' ), [ 'status' => 400 ] );
		}

		$data                    = $report( $from, $to );
		$data['last_aggregated'] = (int) get_option( Aggregator::LAST_RUN_OPTION, 0 );

		return $this->no_store( new WP_REST_Response( $data ) );
	}

	/**
	 * Marks a response uncacheable by browsers and proxies.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
