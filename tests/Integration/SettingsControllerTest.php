<?php
/**
 * Settings REST endpoint tests.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Core\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \BlueLens\Analytics\Admin\Rest\SettingsController
 */
final class SettingsControllerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
	}

	public function test_requires_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/blue-lens/v1/settings' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_admin_can_read_and_update(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/blue-lens/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'retention_raw_months' => 6 ] ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 6, $response->get_data()['retention_raw_months'] );
	}

	public function test_unknown_setting_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/blue-lens/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'not_a_setting' => 1 ] ) );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}
}
