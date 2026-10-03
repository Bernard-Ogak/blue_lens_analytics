<?php
/**
 * Phase 3: form integrations, heatmaps, GPC modes, settings for automatic events.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Core\Plugin;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Core\Tables;
use BlueLens\Analytics\Modules\Forms\FormIntegrations;
use BlueLens\Analytics\Tracking\Collector;
use BlueLens\Analytics\Tracking\RequestContext;
use WP_UnitTestCase;

/**
 * @covers \BlueLens\Analytics\Modules\Forms\FormIntegrations
 * @covers \BlueLens\Analytics\Tracking\HeatmapRecorder
 * @covers \BlueLens\Analytics\Tracking\Collector
 */
final class AutomaticEventsTest extends WP_UnitTestCase {

	private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

	private Settings $settings;
	private Collector $collector;

	public function set_up(): void {
		parent::set_up();
		$container       = Plugin::instance()->container();
		$this->settings  = $container->get( Settings::class );
		$this->collector = $container->get( Collector::class );
		delete_option( Settings::OPTION );
		$this->settings->flush();

		$_SERVER['REMOTE_ADDR']     = '41.90.12.34';
		$_SERVER['HTTP_USER_AGENT'] = self::UA;
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_USER_AGENT'] );
		parent::tear_down();
	}

	private function request( bool $gpc = false ): RequestContext {
		return new RequestContext( '41.90.12.34', self::UA, false, $gpc, '', '', time() );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function events_named( string $name ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE event_name = %s ORDER BY id', Tables::name( Tables::EVENTS ), $name ), ARRAY_A );
	}

	private function page_view(): int {
		return $this->collector->collect(
			[
				'u' => home_url( '/contact/' ),
				'e' => [ [ 'n' => 'page_view' ] ],
			],
			$this->request()
		)->session_id;
	}

	public function test_contact_form_7_submission_is_recorded_server_side(): void {
		$session_id = $this->page_view();

		$form = new class() {
			public function id(): int {
				return 42;
			}
			public function title(): string {
				return 'Safari enquiry';
			}
			/** @return list<object> */
			public function scan_form_tags(): array {
				return [ (object) [ 'name' => 'your-name' ], (object) [ 'name' => 'arrival' ], (object) [ 'name' => '' ] ];
			}
		};

		do_action( 'wpcf7_mail_sent', $form );

		$rows = $this->events_named( 'form_submit' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'cf7:42', $rows[0]['entity_id'] );
		$this->assertSame( (string) $session_id, $rows[0]['session_id'] );
		$this->assertSame( '1', $rows[0]['is_conversion'] );
		$this->assertSame( [ 'form_plugin' => 'cf7', 'form_title' => 'Safari enquiry', 'field_count' => 2 ], json_decode( $rows[0]['attributes'], true ) );
	}

	public function test_gravity_and_wpforms_ids(): void {
		do_action( 'gform_after_submission', [], [ 'id' => 3, 'title' => 'Quote', 'fields' => [ 1, 2, 3 ] ] );
		do_action( 'wpforms_process_complete', [ 1, 2 ], [], [ 'id' => 7, 'settings' => [ 'form_title' => 'Newsletter' ] ] );

		$ids = array_column( $this->events_named( 'form_submit' ), 'entity_id' );
		$this->assertSame( [ 'gf:3', 'wpforms:7' ], $ids );
	}

	public function test_form_tracking_can_be_disabled_and_staff_are_excluded(): void {
		$this->settings->update( [ 'track_forms' => false ] );
		do_action( 'gform_after_submission', [], [ 'id' => 3 ] );
		$this->assertCount( 0, $this->events_named( 'form_submit' ) );

		$this->settings->update( [ 'track_forms' => true ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		do_action( 'gform_after_submission', [], [ 'id' => 3 ] );
		$this->assertCount( 0, $this->events_named( 'form_submit' ) );
	}

	public function test_form_id_is_normalized(): void {
		$this->assertSame( 'elementor:a1b2c3', FormIntegrations::form_id( 'elementor', 'a1b2c3' ) );
		$this->assertSame( 'nf:script', FormIntegrations::form_id( 'nf', '<script>' ) );
		$this->assertSame( 'nf:0', FormIntegrations::form_id( 'nf', '' ) );
	}

	public function test_heatmap_points_are_aggregated_per_cell(): void {
		global $wpdb;

		$result = $this->collector->collect(
			[
				'u'  => home_url( '/safaris/' ),
				'hm' => [ [ 10, 5 ], [ 10, 5 ], [ 50, 30 ], [ 120, 5 ], [ 'x', 1 ], [ 10, 5000 ] ],
			],
			$this->request()
		);

		$this->assertSame( 'stored', $result->status );

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT device, x_bucket, y_bucket, clicks FROM %i WHERE path = %s ORDER BY x_bucket', Tables::name( Tables::HEATMAP_DAILY ), '/safaris/' ),
			ARRAY_A
		);
		$this->assertSame(
			[
				[ 'device' => 'desktop', 'x_bucket' => '10', 'y_bucket' => '5', 'clicks' => '2' ],
				[ 'device' => 'desktop', 'x_bucket' => '50', 'y_bucket' => '30', 'clicks' => '1' ],
			],
			$rows
		);

		// A second batch increments the same cell.
		$this->collector->collect( [ 'u' => home_url( '/safaris/' ), 'hm' => [ [ 10, 5 ] ] ], $this->request() );
		$this->assertSame( '3', $wpdb->get_var( $wpdb->prepare( 'SELECT clicks FROM %i WHERE path = %s AND x_bucket = 10', Tables::name( Tables::HEATMAP_DAILY ), '/safaris/' ) ) );
	}

	public function test_heatmaps_off_ignores_points(): void {
		$this->settings->update( [ 'heatmaps' => false ] );

		$result = $this->collector->collect( [ 'u' => home_url( '/' ), 'hm' => [ [ 1, 1 ] ] ], $this->request() );

		$this->assertSame( 'empty', $result->reason );
	}

	public function test_gpc_stop_mode_drops_requests(): void {
		$this->settings->update( [ 'gpc_action' => 'stop' ] );

		$result = $this->collector->collect( [ 'u' => home_url( '/' ), 'e' => [ [ 'n' => 'page_view' ] ] ], $this->request( true ) );

		$this->assertSame( 'gpc', $result->reason );
	}

	public function test_autocapture_event_attributes_survive_validation(): void {
		$this->collector->collect(
			[
				'u' => home_url( '/' ),
				'e' => [
					[ 'n' => 'page_view' ],
					[ 'n' => 'click', 'a' => [ 'tag' => 'a', 'sel' => '#main-nav > a.menu-link', 'label' => 'Safaris', 'target' => '/safaris/' ] ],
					[ 'n' => 'copy_text', 'a' => [ 'chars' => 21, 'kind' => 'phone', 'sel' => 'p' ] ],
					[ 'n' => 'site_search', 'a' => [ 'query' => 'mail me at a@b.co', 'results' => 0, 'zero_results' => true ] ],
				],
			],
			$this->request()
		);

		$click = $this->events_named( 'click' )[0];
		$this->assertSame( 'interaction', $click['category'] );
		$this->assertSame( '#main-nav > a.menu-link', json_decode( $click['attributes'], true )['sel'] );

		$search = json_decode( $this->events_named( 'site_search' )[0]['attributes'], true );
		$this->assertArrayNotHasKey( 'query', $search, 'A search term containing an email is dropped.' );
		$this->assertTrue( $search['zero_results'] );
	}

	public function test_new_list_settings_are_sanitized(): void {
		$clean = $this->settings->sanitize(
			[
				'download_extensions' => '.PDF, docx, bad ext, toolongextension',
				'cta_selectors'       => [ '.book-now', '#enquire button', '<script>alert(1)</script>' ],
			]
		);

		$this->assertSame( [ 'pdf', 'docx' ], $clean['download_extensions'] );
		$this->assertSame( [ '.book-now', '#enquire button' ], $clean['cta_selectors'] );
	}
}
