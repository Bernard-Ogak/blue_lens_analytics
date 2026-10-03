<?php
/**
 * REST: GET/POST blue-lens/v1/settings.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Admin\Rest;

use BlueLens\Analytics\Core\Capabilities;
use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and updates plugin settings. Requires manage_blue_lens; cookie-authenticated
 * requests must send the wp_rest nonce in X-WP-Nonce (enforced by WordPress core).
 */
final class SettingsController implements Hookable {

	public const ROUTE_NAMESPACE = 'blue-lens/v1';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

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
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				'schema' => [ $this, 'get_schema' ],
			]
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to manage Blue Lens Analytics.', 'blue-lens-analytics' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * GET handler.
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings->public_view() );
	}

	/**
	 * POST/PUT/PATCH handler. Accepts a partial settings object.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) || [] === $params ) {
			return new WP_Error(
				'rest_invalid_json',
				__( 'Send the settings to change as a JSON object.', 'blue-lens-analytics' ),
				[ 'status' => 400 ]
			);
		}

		$unknown = array_diff( array_keys( $params ), array_keys( $this->settings->schema() ) );
		if ( $unknown ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: comma-separated setting names. */
					__( 'Unknown settings: %s', 'blue-lens-analytics' ),
					implode( ', ', array_map( 'sanitize_key', array_map( 'strval', $unknown ) ) )
				),
				[ 'status' => 400 ]
			);
		}

		$this->settings->update( $params );

		return new WP_REST_Response( $this->settings->public_view() );
	}

	/**
	 * JSON Schema derived from the settings schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_schema(): array {
		$properties = [];

		foreach ( $this->settings->schema() as $key => $field ) {
			$properties[ $key ] = match ( $field['type'] ) {
				'bool'     => [ 'type' => 'boolean' ],
				'int'      => array_filter(
					[
						'type'    => 'integer',
						'minimum' => $field['min'] ?? null,
						'maximum' => $field['max'] ?? null,
					],
					static fn( mixed $v ): bool => null !== $v
				),
				'enum'     => [
					'type' => 'string',
					'enum' => $field['choices'] ?? [],
				],
				'currency' => [
					'type'    => 'string',
					'pattern' => '^[A-Z]{3}$',
				],
				'string', 'secret' => [
					'type'      => 'string',
					'maxLength' => $field['max'] ?? 255,
				],
				'role_list', 'ip_list', 'path_list', 'ext_list', 'selector_list' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				default    => [],
			};
			$properties[ $key ]['default'] = $field['default'];
		}

		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'blue-lens-settings',
			'type'       => 'object',
			'properties' => $properties,
		];
	}
}
