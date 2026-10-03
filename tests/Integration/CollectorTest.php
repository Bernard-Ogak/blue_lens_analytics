<?php
/**
 * End-to-end collection tests.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Core\Plugin;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Core\Tables;
use BlueLens\Analytics\Tracking\Collector;
use BlueLens\Analytics\Tracking\RequestContext;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \BlueLens\Analytics\Tracking\Collector
 * @covers \BlueLens\Analytics\Tracking\SessionManager
 * @covers \BlueLens\Analytics\Events\EventWriter
 * @covers \BlueLens\Analytics\Tracking\CollectController
 */
final class CollectorTest extends WP_UnitTestCase {

	private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';

	private Collector $collector;
	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$container       = Plugin::instance()->container();
		$this->collector = $container->get( Collector::class );
		$this->settings  = $container->get( Settings::class );
		delete_option( Settings::OPTION );
		$this->settings->flush();
	}

	private function request( string $ip = '41.90.12.34', string $ua = self::UA, int $now = 0, bool $gpc = false, bool $dnt = false ): RequestContext {
		return new RequestContext( $ip, $ua, $dnt, $gpc, '', 'KE', $now ?: time() );
	}

	/**
	 * @param array<string, mixed> $overrides Payload overrides.
	 * @return array<string, mixed>
	 */
	private function payload( array $overrides = [] ): array {
		return $overrides + [
			'v'  => 1,
			'u'  => home_url( '/tours/great-migration/?utm_source=google&utm_medium=cpc&utm_campaign=migration&gclid=abc123' ),
			'r'  => 'https://www.google.com/',
			'ti' => 'Great Migration Safari',
			'tz' => 'Europe/London',
			'lg' => 'en-GB',
			'vw' => 390,
			'c'  => [
				'k'  => 'singular',
				'p'  => 42,
				't'  => 'tour',
				'tx' => [ 'destination' => [ 5, 9 ] ],
			],
			'e'  => [
				[
					'n' => 'page_view',
					'a' => [ 'pv' => 'ab12cd34' ],
				],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function session( int $id ): array {
		global $wpdb;

		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Tables::name( Tables::SESSIONS ), $id ), ARRAY_A );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function events( int $session_id ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id = %d ORDER BY id', Tables::name( Tables::EVENTS ), $session_id ), ARRAY_A );
	}

	public function test_first_page_view_creates_session_with_attribution(): void {
		$result = $this->collector->collect( $this->payload(), $this->request() );

		$this->assertSame( 'stored', $result->status );
		$this->assertSame( 1, $result->stored );

		$session = $this->session( $result->session_id );
		$this->assertSame( 'paid_search', $session['channel'] );
		$this->assertSame( 'google', $session['utm_source'] );
		$this->assertSame( 'gclid', $session['click_id_type'] );
		$this->assertSame( '', $session['click_id'], 'Click IDs are not stored in cookieless mode.' );
		$this->assertSame( '/tours/great-migration/', $session['landing_path'] );
		$this->assertSame( 'google.com', $session['referrer_domain'] );
		$this->assertSame( 'iOS', $session['os'] );
		$this->assertSame( 'mobile', $session['device_type'] );
		$this->assertSame( 'xs', $session['viewport'] );
		$this->assertSame( 'KE', $session['country'] );
		$this->assertSame( 'Europe/London', $session['timezone'] );
		$this->assertNull( $session['is_returning'] );
		$this->assertSame( '1', $session['pageviews'] );

		$events = $this->events( $result->session_id );
		$this->assertSame( 'page_view', $events[0]['event_name'] );
		$this->assertSame( '42', $events[0]['post_id'] );
		$this->assertSame( 'tour', $events[0]['post_type'] );
		$this->assertSame( 'Great Migration Safari', $events[0]['page_title'] );
		$this->assertSame( [ 'k' => 'singular', 'tx' => [ 'destination' => [ 5, 9 ] ] ], json_decode( $events[0]['context'], true ) );
	}

	public function test_no_raw_ip_is_stored(): void {
		global $wpdb;

		$result = $this->collector->collect( $this->payload(), $this->request( '41.90.12.34' ) );
		$row    = wp_json_encode( $this->session( $result->session_id ) );

		$this->assertStringNotContainsString( '41.90.12', (string) $row );
		$this->assertStringNotContainsString( '41.90.12', (string) wp_json_encode( $this->events( $result->session_id ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_value LIKE %s', $wpdb->options, '%41.90.12%' ) ) );
	}

	public function test_same_visitor_continues_session_and_accumulates(): void {
		$now   = time();
		$first = $this->collector->collect( $this->payload(), $this->request( now: $now ) );

		$second = $this->collector->collect(
			$this->payload(
				[
					'u'  => home_url( '/tours/great-migration/itinerary/' ),
					'hb' => 15,
					'e'  => [ [ 'n' => 'page_view' ], [ 'n' => 'brochure_download', 'a' => [ 'file' => 'mara.pdf' ] ] ],
				]
			),
			$this->request( now: $now + 60 )
		);

		$this->assertSame( $first->session_id, $second->session_id );

		$session = $this->session( $first->session_id );
		$this->assertSame( '2', $session['pageviews'] );
		$this->assertSame( '3', $session['event_count'] );
		$this->assertSame( '15', $session['engaged_seconds'] );
		$this->assertSame( '1', $session['is_engaged'] );
		$this->assertSame( '/tours/great-migration/itinerary/', $session['exit_path'] );
		$this->assertSame( '/tours/great-migration/', $session['landing_path'] );
	}

	public function test_new_campaign_starts_new_session(): void {
		$first  = $this->collector->collect( $this->payload(), $this->request() );
		$second = $this->collector->collect(
			$this->payload( [ 'u' => home_url( '/?utm_source=newsletter&utm_medium=email&utm_campaign=october' ) ] ),
			$this->request()
		);

		$this->assertNotSame( $first->session_id, $second->session_id );
		$this->assertSame( 'email', $this->session( $second->session_id )['channel'] );
	}

	public function test_enhanced_mode_with_consent_keeps_click_id_and_marks_returning(): void {
		$this->settings->update( [ 'privacy_mode' => Settings::MODE_ENHANCED ] );
		$vid = str_repeat( 'ab', 16 );

		$first = $this->collector->collect( $this->payload( [ 'vid' => $vid ] ), $this->request( now: time() - 7200 ) );
		$this->assertSame( '0', $this->session( $first->session_id )['is_returning'] );
		$this->assertSame( 'abc123', $this->session( $first->session_id )['click_id'] );

		$second = $this->collector->collect( $this->payload( [ 'vid' => $vid, 'u' => home_url( '/' ) ] ), $this->request( '8.8.8.8' ) );
		$this->assertNotSame( $first->session_id, $second->session_id );
		$this->assertSame( '1', $this->session( $second->session_id )['is_returning'] );
		$this->assertSame( '1', $this->session( $second->session_id )['id_mode'] );
	}

	public function test_gpc_forces_cookieless_in_enhanced_mode(): void {
		$this->settings->update( [ 'privacy_mode' => Settings::MODE_ENHANCED ] );

		$result  = $this->collector->collect( $this->payload( [ 'vid' => str_repeat( 'cd', 16 ) ] ), $this->request( gpc: true ) );
		$session = $this->session( $result->session_id );

		$this->assertSame( '0', $session['id_mode'] );
		$this->assertSame( '', $session['click_id'] );
	}

	/**
	 * @dataProvider ignored_cases
	 *
	 * @param array<string, mixed> $settings Settings to apply.
	 * @param array<string, mixed> $payload  Payload overrides.
	 */
	public function test_ignored_requests( array $settings, array $payload, string $ua, bool $dnt, string $reason ): void {
		if ( $settings ) {
			$this->settings->update( $settings );
		}

		$result = $this->collector->collect( $this->payload( $payload ), $this->request( ua: $ua, dnt: $dnt ) );

		$this->assertSame( 'ignored', $result->status );
		$this->assertSame( $reason, $result->reason );
	}

	/**
	 * @return array<string, array{array<string, mixed>, array<string, mixed>, string, bool, string}>
	 */
	public static function ignored_cases(): array {
		return [
			'tracking disabled' => [ [ 'tracking_enabled' => false ], [], self::UA, false, 'disabled' ],
			'dnt respected'     => [ [ 'respect_dnt' => true ], [], self::UA, true, 'dnt' ],
			'bot'               => [ [], [], 'Mozilla/5.0 (compatible; Googlebot/2.1)', false, 'bot' ],
			'webdriver'         => [ [], [ 'wd' => 1 ], self::UA, false, 'bot' ],
			'excluded ip'       => [ [ 'excluded_ips' => [ '41.90.0.0/16' ] ], [], self::UA, false, 'excluded_ip' ],
			'excluded path'     => [ [ 'excluded_paths' => [ '/tours/*' ] ], [], self::UA, false, 'excluded_path' ],
			'foreign host'      => [ [], [ 'u' => 'https://copycat.example/page/' ], self::UA, false, 'foreign_host' ],
			'empty'             => [ [], [ 'e' => [ [ 'n' => '!!' ] ] ], self::UA, false, 'empty' ],
		];
	}

	public function test_server_event_links_to_active_session(): void {
		$browser = $this->collector->collect( $this->payload(), $this->request() );

		$result = $this->collector->record_server_event(
			[
				'event'       => 'purchase',
				'category'    => 'conversion',
				'module'      => 'ecommerce',
				'entity_type' => 'order',
				'entity_id'   => 1001,
				'value'       => 250,
				'currency'    => 'USD',
			],
			$this->request()
		);

		$this->assertSame( $browser->session_id, $result->session_id );
		$events = $this->events( $browser->session_id );
		$this->assertSame( 'purchase', $events[1]['event_name'] );
		$this->assertSame( '1', $events[1]['origin'] );
		$this->assertSame( '250.0000', $events[1]['event_value_base'] );
	}

	public function test_server_event_without_request_is_stored_unlinked(): void {
		$result = $this->collector->record_server_event( [ 'event' => 'refund', 'value' => 10 ], null );

		$this->assertSame( 'stored', $result->status );
		$this->assertSame( 0, $result->session_id );
	}

	public function test_rest_endpoint_accepts_text_plain_and_answers_204(): void {
		$_SERVER['REMOTE_ADDR']     = '41.90.12.99';
		$_SERVER['HTTP_USER_AGENT'] = self::UA;

		$request = new WP_REST_Request( 'POST', '/blue-lens/v1/collect' );
		$request->set_header( 'Content-Type', 'text/plain' );
		$request->set_body( (string) wp_json_encode( $this->payload() ) );
		$response = rest_do_request( $request );

		$this->assertSame( 204, $response->get_status() );

		$request->set_body( '{not json' );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );

		$request->set_body( str_repeat( 'x', 20000 ) );
		$this->assertSame( 413, rest_do_request( $request )->get_status() );
	}

	public function test_crawler_hits_are_counted_per_day_and_path(): void {
		global $wpdb;

		$logger = Plugin::instance()->container()->get( \BlueLens\Analytics\Tracking\CrawlerLogger::class );
		$logger->record( 'GPTBot', 'ai', '/tours/', 200, time() );
		$logger->record( 'GPTBot', 'ai', '/tours/', 200, time() );

		$hits = $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE crawler = %s AND path = %s', Tables::name( Tables::CRAWLER_DAILY ), 'GPTBot', '/tours/' ) );
		$this->assertSame( '2', $hits );
	}
}
