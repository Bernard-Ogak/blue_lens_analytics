<?php
/**
 * Phase 4: aggregation, reports, retention, preferences and report endpoints.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Admin\Dashboard;
use BlueLens\Analytics\Admin\Preferences;
use BlueLens\Analytics\Aggregation\Aggregator;
use BlueLens\Analytics\Aggregation\Reports;
use BlueLens\Analytics\Aggregation\Retention;
use BlueLens\Analytics\Aggregation\SiteTime;
use BlueLens\Analytics\Core\Plugin;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Core\Tables;
use BlueLens\Analytics\Tracking\Collector;
use BlueLens\Analytics\Tracking\RequestContext;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \BlueLens\Analytics\Aggregation\Aggregator
 * @covers \BlueLens\Analytics\Aggregation\Reports
 * @covers \BlueLens\Analytics\Aggregation\Retention
 * @covers \BlueLens\Analytics\Admin\Preferences
 * @covers \BlueLens\Analytics\Admin\Rest\ReportsController
 */
final class ReportsTest extends WP_UnitTestCase {

	private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

	private Collector $collector;
	private Aggregator $aggregator;
	private Reports $reports;
	private string $today;

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Africa/Nairobi' );
		$c                = Plugin::instance()->container();
		$this->collector  = $c->get( Collector::class );
		$this->aggregator = $c->get( Aggregator::class );
		$this->reports    = $c->get( Reports::class );
		$this->today      = SiteTime::today();
		delete_option( Settings::OPTION );
		$c->get( Settings::class )->flush();
	}

	/**
	 * Records one visit.
	 *
	 * @param string $ip      Visitor IP.
	 * @param string $query   Landing query string.
	 * @param string $country Country code.
	 * @param int    $time    Unix time.
	 * @param bool   $convert Submit a form.
	 */
	private function visit( string $ip, string $query, string $country, int $time, bool $convert = false ): void {
		$events = [
			[ 'n' => 'page_view', 'a' => [ 'pv' => substr( md5( $ip . $time ), 0, 8 ) ] ],
			[ 'n' => 'page_engagement', 'a' => [ 'pv' => substr( md5( $ip . $time ), 0, 8 ), 'engaged_seconds' => 40, 'scroll_depth' => 75 ] ],
		];
		if ( $convert ) {
			$events[] = [ 'n' => 'form_submit', 'et' => 'form', 'ei' => 'cf7:1' ];
		}

		$this->collector->collect(
			[
				'u'  => home_url( '/tours/mara/' . $query ),
				'ti' => 'Mara Safari',
				'hb' => 40,
				'hm' => [ [ 50, 10 ] ],
				'c'  => [ 'k' => 'singular', 'p' => 0, 't' => 'tour' ],
				'e'  => $events,
			],
			new RequestContext( $ip, self::UA, false, false, '', $country, $time )
		);
	}

	private function seed_today(): void {
		$noon = ( new \DateTimeImmutable( $this->today . ' 12:00:00', wp_timezone() ) )->getTimestamp();
		$this->visit( '41.1.1.1', '?utm_source=newsletter&utm_medium=email', 'KE', $noon, true );
		$this->visit( '81.2.2.2', '', 'GB', $noon + 60 );
		$this->visit( '92.3.3.3', '?gclid=abc', 'DE', $noon + 120, true );
		$this->aggregator->aggregate_day( $this->today );
	}

	public function test_daily_totals_and_derived_rates(): void {
		$this->seed_today();

		$t = $this->reports->totals( $this->today, $this->today );

		$this->assertSame( 3, $t['sessions'] );
		$this->assertSame( 3, $t['visitors'] );
		$this->assertSame( 3, $t['pageviews'] );
		$this->assertSame( 2, $t['conversions'] );
		$this->assertSame( 120, $t['engaged_seconds'] );
		$this->assertEqualsWithDelta( 2 / 3, $t['conversion_rate'], 0.001 );
		$this->assertSame( 1.0, $t['engagement_rate'] );
	}

	public function test_aggregation_is_idempotent(): void {
		$this->seed_today();
		$this->aggregator->aggregate_day( $this->today );

		$this->assertSame( 3, $this->reports->totals( $this->today, $this->today )['sessions'] );
	}

	public function test_dimensions(): void {
		$this->seed_today();

		$channels = array_column( $this->reports->dimension( 'channel', $this->today, $this->today, 10 )['rows'], 'sessions', 'value' );
		$this->assertSame( [ 'email' => 1, 'direct' => 1, 'paid_search' => 1 ], array_intersect_key( $channels, [ 'email' => 0, 'direct' => 0, 'paid_search' => 0 ] ) );

		$countries = $this->reports->dimension( 'country', $this->today, $this->today, 2 );
		$this->assertCount( 2, $countries['rows'] );
		$this->assertSame( 3, $countries['total'] );
	}

	public function test_content_engagement_and_entrances(): void {
		$this->seed_today();

		$row = $this->reports->content( 'page', $this->today, $this->today, 5 )['rows'][0];

		$this->assertSame( '/tours/mara/', $row['path'] );
		$this->assertSame( 3, $row['pageviews'] );
		$this->assertSame( 3, $row['entrances'] );
		$this->assertSame( 2, $row['conversions'] );
		$this->assertEquals( 40, $row['avg_engaged_time'] );
	}

	public function test_series_is_zero_filled_and_comparison_range(): void {
		$this->seed_today();
		$from = SiteTime::add_days( $this->today, -6 );

		$overview = $this->reports->overview( $from, $this->today, 'previous' );

		$this->assertCount( 7, $overview['series'] );
		$this->assertSame( 0, $overview['series'][0]['sessions'] );
		$this->assertSame( 3, $overview['series'][6]['sessions'] );
		$this->assertSame( SiteTime::add_days( $from, -7 ), $overview['compare']['range']['from'] );
		$this->assertSame( SiteTime::add_days( $from, -1 ), $overview['compare']['range']['to'] );
	}

	public function test_heatmap_report(): void {
		$this->seed_today();

		$heat = $this->reports->heatmap( '/tours/mara/', 'desktop', $this->today, $this->today );

		$this->assertSame( [ [ 50, 10, 3 ] ], $heat['cells'] );
		$this->assertSame( 3, $heat['max'] );
	}

	public function test_retention_deletes_old_raw_rows_only(): void {
		global $wpdb;

		$old = time() - 400 * DAY_IN_SECONDS;
		$this->visit( '10.9.9.9', '', 'KE', $old );
		$this->seed_today();

		$deleted = Plugin::instance()->container()->get( Retention::class )->run();

		$this->assertSame( 2, $deleted[ Tables::EVENTS ] );
		$this->assertSame( 1, $deleted[ Tables::SESSIONS ] );
		$this->assertSame( '3', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tables::name( Tables::SESSIONS ) ) ) );
	}

	public function test_preferences_are_sanitized(): void {
		$prefs = Preferences::sanitize(
			[
				'theme'  => 'light',
				'accent' => 'neon',
				'kpis'   => [ 'revenue', 'nope', 'revenue', 'visitors' ],
				'layout' => [
					'overview' => [ [ 'id' => 'trend', 'on' => false ], [ 'id' => '<b>', 'on' => true ] ],
					'hacker'   => [ [ 'id' => 'x', 'on' => true ] ],
				],
			]
		);

		$this->assertSame( 'light', $prefs['theme'] );
		$this->assertSame( 'blue', $prefs['accent'] );
		$this->assertSame( [ 'revenue', 'visitors' ], $prefs['kpis'] );
		$this->assertSame( [ 'overview' => [ [ 'id' => 'trend', 'on' => false ] ] ], (array) $prefs['layout'] );
	}

	public function test_report_endpoints_require_capability_and_valid_ranges(): void {
		$this->seed_today();
		$request = new WP_REST_Request( 'GET', '/blue-lens/v1/reports/overview' );
		$request->set_param( 'from', $this->today );
		$request->set_param( 'to', $this->today );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		$request->set_param( 'from', SiteTime::add_days( $this->today, 1 ) );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );

		$dimension = new WP_REST_Request( 'GET', '/blue-lens/v1/reports/dimension' );
		$dimension->set_param( 'from', $this->today );
		$dimension->set_param( 'to', $this->today );
		$dimension->set_param( 'dimension', 'value_hash; DROP TABLE' );
		$this->assertSame( 400, rest_do_request( $dimension )->get_status() );
	}

	public function test_report_roles_filter_shares_dashboards_without_settings(): void {
		$filter = static fn(): array => [ 'editor' ];
		add_filter( 'blue_lens_report_roles', $filter );
		\BlueLens\Analytics\Core\Capabilities::add();
		remove_filter( 'blue_lens_report_roles', $filter );

		$editor = self::factory()->user->create_and_get( [ 'role' => 'editor' ] );
		$this->assertTrue( user_can( $editor, \BlueLens\Analytics\Core\Capabilities::VIEW ) );
		$this->assertFalse( user_can( $editor, \BlueLens\Analytics\Core\Capabilities::MANAGE ) );

		get_role( 'editor' )->remove_cap( \BlueLens\Analytics\Core\Capabilities::VIEW );
	}

	public function test_csv_cells_are_protected_from_formula_injection(): void {
		$this->assertSame( "'=1+1", Dashboard::csv_safe( '=1+1' ) );
		$this->assertSame( "'@SUM(A1)", Dashboard::csv_safe( '@SUM(A1)' ) );
		$this->assertSame( 'google.com', Dashboard::csv_safe( 'google.com' ) );
		$this->assertSame( 12, Dashboard::csv_safe( 12 ) );
		$this->assertSame( 1, Dashboard::csv_safe( true ) );
	}
}
