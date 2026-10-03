<?php
/**
 * Dashboard admin screens (React app) and CSV export.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Admin;

use BlueLens\Analytics\Admin\Rest\ReportsController;
use BlueLens\Analytics\Aggregation\Aggregator;
use BlueLens\Analytics\Aggregation\Reports;
use BlueLens\Analytics\Aggregation\SiteTime;
use BlueLens\Analytics\Core\Capabilities;
use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Core\Tables;
use BlueLens\Analytics\Modules\Ads\LocalAdsReport;
use BlueLens\Analytics\Modules\Audit\AuditReport;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Blue Lens menu (Dashboard, Settings, Status) and loads the admin app, built on
 * WordPress's bundled React (wp-element) and components, so no extra framework is shipped.
 */
final class Dashboard implements Hookable {

	public const SLUG          = 'blue-lens';
	public const SETTINGS_SLUG = 'blue-lens-settings';
	public const STATUS_SLUG   = 'blue-lens-status';
	public const EXPORT_ACTION = 'blue_lens_export';

	/**
	 * Hook suffixes of our screens.
	 *
	 * @var list<string>
	 */
	private array $hooks = [];

	/**
	 * Hook suffix of the Status screen.
	 *
	 * @var string
	 */
	private string $status_hook = '';

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings  Settings.
	 * @param Reports        $reports   Reports.
	 * @param Menu           $status    Status screen renderer.
	 * @param LocalAdsReport $local_ads Local Ads report.
	 * @param AuditReport    $audit     Site audit report.
	 */
	public function __construct(
		private Settings $settings,
		private Reports $reports,
		private Menu $status,
		private LocalAdsReport $local_ads,
		private AuditReport $audit
	) {}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_action( 'admin_post_' . self::EXPORT_ACTION, [ $this, 'export' ] );
		add_filter( 'admin_footer_text', [ $this, 'footer_text' ] );
	}

	/**
	 * Menu: Blue Lens → Dashboard, Settings, Status.
	 */
	public function register_menu(): void {
		$this->hooks[] = (string) add_menu_page(
			__( 'Blue Lens Analytics', 'blue-lens-analytics' ),
			__( 'Blue Lens', 'blue-lens-analytics' ),
			Capabilities::VIEW,
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-visibility',
			3
		);
		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Dashboard', 'blue-lens-analytics' ), __( 'Dashboard', 'blue-lens-analytics' ), Capabilities::VIEW, self::SLUG, [ $this, 'render' ] );
		$this->hooks[] = (string) add_submenu_page( self::SLUG, __( 'Blue Lens Settings', 'blue-lens-analytics' ), __( 'Settings', 'blue-lens-analytics' ), Capabilities::MANAGE, self::SETTINGS_SLUG, [ $this, 'render' ] );
		$this->status_hook = (string) add_submenu_page( self::SLUG, __( 'Blue Lens Status', 'blue-lens-analytics' ), __( 'Status', 'blue-lens-analytics' ), Capabilities::MANAGE, self::STATUS_SLUG, [ $this->status, 'render_status_page' ] );
	}

	/**
	 * Credit line in the admin footer, on Blue Lens screens only.
	 *
	 * @param mixed $text Default footer text.
	 * @return mixed
	 */
	public function footer_text( mixed $text ): mixed {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array_merge( $this->hooks, [ $this->status_hook ] ), true ) ) {
			return $text;
		}

		return sprintf(
			/* translators: 1: plugin name and version, 2: author name, 3: link to Creative Bay. */
			esc_html__( '%1$s by %2$s · %3$s', 'blue-lens-analytics' ),
			'Blue Lens ' . esc_html( BLA_VERSION ),
			'Bernard Ogak',
			'<a href="https://www.creativebay.co.ke" target="_blank" rel="noopener noreferrer">Creative Bay</a>'
		);
	}

	/**
	 * App root. The route comes from the submenu; everything else is rendered client-side.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blue-lens-analytics' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$page  = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : self::SLUG;
		$route = self::SETTINGS_SLUG === $page ? 'settings' : 'overview';

		printf(
			'<div id="blue-lens-app" class="bla-root" data-route="%1$s"><div class="bla-boot"><span class="spinner is-active"></span> %2$s</div><noscript>%3$s</noscript></div>',
			esc_attr( $route ),
			esc_html__( 'Loading Blue Lens…', 'blue-lens-analytics' ),
			esc_html__( 'The Blue Lens dashboard needs JavaScript.', 'blue-lens-analytics' )
		);
	}

	/**
	 * Loads the app on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'blue-lens-admin', BLA_URL . 'assets/admin/css/app.css', [ 'wp-components' ], BLA_VERSION );

		wp_enqueue_script( 'blue-lens-charts', BLA_URL . 'assets/admin/js/charts.js', [ 'wp-element', 'wp-i18n' ], BLA_VERSION, true );
		wp_enqueue_script(
			'blue-lens-admin',
			BLA_URL . 'assets/admin/js/app.js',
			[ 'blue-lens-charts', 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-url' ],
			BLA_VERSION,
			true
		);
		wp_set_script_translations( 'blue-lens-admin', 'blue-lens-analytics', BLA_PATH . 'languages' );
		wp_set_script_translations( 'blue-lens-charts', 'blue-lens-analytics', BLA_PATH . 'languages' );

		wp_add_inline_script( 'blue-lens-admin', 'window.BlueLensAdmin = ' . wp_json_encode( $this->bootstrap(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
	}

	/**
	 * Adds the theme class early so the page never flashes the wrong theme.
	 *
	 * @param string $classes Body classes.
	 */
	public function body_class( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, $this->hooks, true ) ) {
			return $classes;
		}

		$prefs = Preferences::get( get_current_user_id() );

		return $classes . ' bla-screen bla-theme-' . $prefs['theme'] . ' bla-accent-' . $prefs['accent'] . ' bla-density-' . $prefs['density'];
	}

	/**
	 * Data the app needs on load.
	 *
	 * @return array<string, mixed>
	 */
	private function bootstrap(): array {
		global $wpdb;

		$first = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(day) FROM %i', Tables::name( Tables::DAILY_TRAFFIC ) ) );

		$taxonomies = [];
		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $taxonomy ) {
			if ( 'post_format' !== $taxonomy->name ) {
				$taxonomies[] = [
					'name'  => $taxonomy->name,
					'label' => $taxonomy->labels->name,
				];
			}
		}

		return [
			'version'        => BLA_VERSION,
			'restPath'       => '/' . Rest\SettingsController::ROUTE_NAMESPACE,
			'prefs'          => Preferences::get( get_current_user_id() ),
			'canManage'      => current_user_can( Capabilities::MANAGE ),
			'site'           => [
				'name'     => get_bloginfo( 'name' ),
				'home'     => home_url( '/' ),
				'timezone' => wp_timezone_string(),
				'today'    => SiteTime::today(),
				'weekStart' => (int) get_option( 'start_of_week', 1 ),
				'currency' => (string) $this->settings->get( 'base_currency' ),
				'locale'   => str_replace( '_', '-', get_user_locale() ),
			],
			'firstDay'       => is_string( $first ) ? $first : null,
			'lastAggregated' => (int) get_option( Aggregator::LAST_RUN_OPTION, 0 ),
			'taxonomies'     => $taxonomies,
			'dimensions'     => Aggregator::dimensions(),
			'export'         => [
				'url'   => admin_url( 'admin-post.php' ),
				'nonce' => wp_create_nonce( self::EXPORT_ACTION ),
			],
			'links'          => [
				'status'   => admin_url( 'admin.php?page=' . self::STATUS_SLUG ),
				'settings' => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
				'docs'     => 'https://github.com/Bernard-Ogak/blue_lens_analytics/blob/main/docs/user-guide.md',
			],
			'trackingEnabled' => (bool) $this->settings->get( 'tracking_enabled' ),
			'localAds'       => [
				'active' => LocalAdsReport::available(),
				'url'    => admin_url( 'admin.php?page=local-ads-analytics' ),
			],
			'audit'          => [
				'maxPages' => (int) $this->settings->get( 'audit_max_pages' ),
				'weekly'   => (bool) $this->settings->get( 'audit_weekly' ),
			],
			'roles'          => array_map( 'translate_user_role', wp_roles()->get_names() ),
		];
	}

	/**
	 * Streams a report as CSV.
	 */
	public function export(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You are not allowed to export reports.', 'blue-lens-analytics' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$report = isset( $_GET['report'] ) ? sanitize_key( $_GET['report'] ) : '';
		$from   = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to     = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$arg    = isset( $_GET['arg'] ) ? sanitize_text_field( wp_unslash( $_GET['arg'] ) ) : '';
		// phpcs:enable

		if ( 'audit' !== $report && ( ! SiteTime::is_date( $from ) || ! SiteTime::is_date( $to ) || $from > $to ) ) {
			wp_die( esc_html__( 'Invalid date range.', 'blue-lens-analytics' ), 400 );
		}

		[ $header, $rows ] = $this->export_rows( $report, $arg, $from, $to );
		if ( ! $header ) {
			wp_die( esc_html__( 'Unknown report.', 'blue-lens-analytics' ), 400 );
		}

		$filename = 'audit' === $report
			? sprintf( 'blue-lens-site-audit-%s.csv', SiteTime::today() )
			: sprintf( 'blue-lens-%s%s-%s-to-%s.csv', $report, '' !== $arg ? '-' . sanitize_file_name( str_replace( ':', '-', $arg ) ) : '', $from, $to );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			exit;
		}
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so Excel reads accents.
		fputcsv( $out, $header );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( [ self::class, 'csv_safe' ], $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Header and rows for an export.
	 *
	 * @param string $report Report key.
	 * @param string $arg    Dimension or content group.
	 * @param string $from   First day.
	 * @param string $to     Last day.
	 * @return array{0: list<string>, 1: list<list<mixed>>}
	 */
	private function export_rows( string $report, string $arg, string $from, string $to ): array {
		switch ( $report ) {
			case 'daily':
				$metrics = Reports::TRAFFIC_METRICS;
				$rows    = array_map( static fn( array $p ): array => array_values( $p ), $this->reports->series( $from, $to ) );
				return [ array_merge( [ 'day' ], $metrics ), $rows ];

			case 'dimension':
				if ( ! in_array( $arg, Aggregator::dimensions(), true ) ) {
					return [ [], [] ];
				}
				$data = $this->reports->dimension( $arg, $from, $to, 5000 );
				$keys = [ 'value', 'sessions', 'visitors', 'engaged_sessions', 'engagement_rate', 'pageviews', 'conversions', 'conversion_rate', 'revenue', 'avg_engaged_time' ];
				return [ $keys, array_map( static fn( array $r ): array => array_map( static fn( string $k ) => $r[ $k ], $keys ), $data['rows'] ) ];

			case 'content':
				if ( ! ReportsController::valid_content_group( $arg ) ) {
					return [ [], [] ];
				}
				$data = $this->reports->content( $arg, $from, $to, 5000 );
				$keys = [ 'label', 'pageviews', 'visitors', 'entrances', 'exits', 'exit_rate', 'avg_engaged_time', 'conversions' ];
				return [ $keys, array_map( static fn( array $r ): array => array_map( static fn( string $k ) => $r[ $k ] ?? '', $keys ), $data['rows'] ) ];

			case 'events':
				$keys = [ 'event', 'category', 'events', 'sessions', 'value', 'is_conversion' ];
				return [ $keys, array_map( static fn( array $r ): array => array_map( static fn( string $k ) => $r[ $k ], $keys ), $this->reports->events( $from, $to )['rows'] ) ];

			case 'local_ads':
				$data = $this->local_ads->report( $from, $to );
				$keys = [ 'ad_id', 'name', 'campaign', 'status', 'impressions', 'clicks', 'ctr' ];
				return [ $keys, array_map( static fn( array $r ): array => array_map( static fn( string $k ) => $r[ $k ], $keys ), (array) ( $data['ads'] ?? [] ) ) ];

			case 'audit':
				$keys = [ 'url', 'title', 'status_code', 'response_ms', 'words', 'inlinks', 'errors', 'warnings', 'notices', 'pageviews' ];
				return [ $keys, array_map( static fn( array $r ): array => array_map( static fn( string $k ) => $r[ $k ], $keys ), $this->audit->pages( 0, 'all' )['rows'] ) ];
		}

		return [ [], [] ];
	}

	/**
	 * Neutralises spreadsheet formula injection (=, +, -, @ at the start of a text cell).
	 *
	 * @param mixed $value Cell.
	 * @return mixed
	 */
	public static function csv_safe( mixed $value ): mixed {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_string( $value ) && '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
