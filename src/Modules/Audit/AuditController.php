<?php
/**
 * REST: blue-lens/v1/audit/*.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Modules\Audit;

use BlueLens\Analytics\Admin\Rest\SettingsController;
use BlueLens\Analytics\Core\Capabilities;
use BlueLens\Analytics\Core\Hookable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Site audit endpoints. Reading needs view_blue_lens_reports; starting, advancing and cancelling an
 * audit needs manage_blue_lens.
 */
final class AuditController implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param SiteAudit   $audit  Audit runner.
	 * @param AuditReport $report Report queries.
	 */
	public function __construct(
		private SiteAudit $audit,
		private AuditReport $report
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
		$ns     = SettingsController::ROUTE_NAMESPACE;
		$view   = [ $this, 'can_view' ];
		$manage = [ $this, 'can_manage' ];
		$run    = [
			'type'    => 'integer',
			'minimum' => 0,
			'default' => 0,
		];

		register_rest_route(
			$ns,
			'/audit',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => $this->no_store( $this->report->overview() ),
				'permission_callback' => $view,
			]
		);

		register_rest_route(
			$ns,
			'/audit/issue',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->no_store( $this->report->issue( (int) $r['run'], (string) $r['code'] ) ),
				'permission_callback' => $view,
				'args'                => [
					'run'  => $run,
					'code' => [
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9_]{1,64}$',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/audit/pages',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => $this->no_store( $this->report->pages( (int) $r['run'], (string) $r['filter'] ) ),
				'permission_callback' => $view,
				'args'                => [
					'run'    => $run,
					'filter' => [
						'type'    => 'string',
						'enum'    => [ 'all', 'healthy', 'warnings', 'errors', 'redirects', 'broken' ],
						'default' => 'all',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/audit/page',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'page' ],
				'permission_callback' => $view,
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/audit/start',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => fn() => $this->no_store( $this->report->progress( $this->audit->start( get_current_user_id() ) ) ),
				'permission_callback' => $manage,
			]
		);

		register_rest_route(
			$ns,
			'/audit/step',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'step' ],
				'permission_callback' => $manage,
				'args'                => [
					'run' => [
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/audit/cancel',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( WP_REST_Request $r ) {
					$this->audit->cancel( (int) $r['run'] );
					return $this->no_store( [ 'cancelled' => true ] );
				},
				'permission_callback' => $manage,
				'args'                => [
					'run' => [
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Advances a running audit by a few seconds of work.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function step( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run = $this->audit->step( (int) $request['run'] );
		if ( ! $run ) {
			return new WP_Error( 'rest_not_found', __( 'Audit not found.', 'blue-lens-analytics' ), [ 'status' => 404 ] );
		}

		return $this->no_store( $this->report->progress( $run ) );
	}

	/**
	 * One page's details.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function page( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$page = $this->report->page( (int) $request['id'] );
		if ( ! $page ) {
			return new WP_Error( 'rest_not_found', __( 'Page not found.', 'blue-lens-analytics' ), [ 'status' => 404 ] );
		}

		return $this->no_store( $page );
	}

	/**
	 * Permission: view reports.
	 *
	 * @return true|WP_Error
	 */
	public function can_view(): bool|WP_Error {
		return current_user_can( Capabilities::VIEW )
			? true
			: new WP_Error( 'rest_forbidden', __( 'You are not allowed to view Blue Lens reports.', 'blue-lens-analytics' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * Permission: manage Blue Lens.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage(): bool|WP_Error {
		return current_user_can( Capabilities::MANAGE )
			? true
			: new WP_Error( 'rest_forbidden', __( 'You are not allowed to run site audits.', 'blue-lens-analytics' ), [ 'status' => rest_authorization_required_code() ] );
	}

	/**
	 * Uncacheable response.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	private function no_store( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
