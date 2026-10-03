<?php
/**
 * Front-end tracker loading.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

use BlueLens\Analytics\Admin\Rest\SettingsController;
use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the tracker (deferred, no dependencies) and prints one JSON data block with the site
 * config and page context. The output is identical for every anonymous visitor of a URL, so it
 * is safe to serve from full-page caches; it contains no nonces or visitor data.
 */
final class TrackerLoader implements Hookable {

	public const HANDLE  = 'blue-lens-tracker';
	public const DATA_ID = 'bla-data';

	/**
	 * Resolved decision for this request.
	 *
	 * @var bool|null
	 */
	private ?bool $should_track = null;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings.
	 * @param PageContext $context  Page context builder.
	 */
	public function __construct(
		private Settings $settings,
		private PageContext $context
	) {}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'template_include', [ $this->context, 'capture_template' ], PHP_INT_MAX );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_head', [ $this, 'print_data' ], 1 );
		add_filter( 'script_loader_tag', [ $this, 'script_attributes' ], 10, 2 );
		add_action( 'init', [ $this, 'register_consent_cookie' ] );
		add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar_in_preview' ] );
		// Declares WP Consent API support so the API does not flag the plugin as non-compliant.
		add_filter( 'wp_consent_api_registered_' . plugin_basename( BLA_FILE ), '__return_true' );

		// Keep optimization plugins from delaying/combining the tracker (delayed trackers lose bounce visits).
		add_filter( 'rocket_delay_js_exclusions', [ $this, 'exclude_from_optimizers' ] );
		add_filter( 'rocket_exclude_js', [ $this, 'exclude_from_optimizers' ] );
		add_filter( 'litespeed_optm_js_defer_exc', [ $this, 'exclude_from_optimizers' ] );
		add_filter( 'sgo_javascript_combine_exclude', [ $this, 'exclude_handles' ] );
		add_filter( 'sgo_js_minify_exclude', [ $this, 'exclude_handles' ] );
		add_filter( 'autoptimize_filter_js_exclude', [ $this, 'exclude_autoptimize' ] );
	}

	/**
	 * Whether this request should load the tracker.
	 */
	public function should_track(): bool {
		if ( null !== $this->should_track ) {
			return $this->should_track;
		}

		$track = (bool) $this->settings->get( 'tracking_enabled' )
			&& ! is_admin()
			&& ! is_feed()
			&& ! is_robots()
			&& ! is_trackback()
			&& ! is_preview()
			&& ! is_customize_preview()
			&& ! wp_doing_ajax()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! $this->is_builder_preview()
			&& ! self::is_heatmap_preview()
			&& ! $this->is_excluded_user();

		/**
		 * Filters whether the tracker loads on this request.
		 *
		 * @param bool $track Whether to track.
		 */
		$this->should_track = (bool) apply_filters( 'blue_lens_should_track', $track );

		return $this->should_track;
	}

	/**
	 * Enqueues the tracker script.
	 */
	public function enqueue(): void {
		if ( ! $this->should_track() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			BLA_URL . $this->script_path(),
			[],
			BLA_VERSION,
			[
				'strategy'  => 'defer',
				'in_footer' => false,
			]
		);
	}

	/**
	 * Prints the JSON data block.
	 */
	public function print_data(): void {
		if ( ! $this->should_track() ) {
			return;
		}

		$settings = $this->settings->all();
		$data     = [
			'cfg' => [
				'url'  => rest_url( SettingsController::ROUTE_NAMESPACE . CollectController::ROUTE ),
				'mode' => $settings['privacy_mode'],
				'gpc'  => $settings['respect_gpc'] ? 1 : 0,
				'ga'   => $settings['gpc_action'],
				'dnt'  => $settings['respect_dnt'] ? 1 : 0,
				'hb'   => (int) $settings['heartbeat_seconds'],
				'spa'  => $settings['spa_tracking'] ? 1 : 0,
				'xp'   => $settings['excluded_paths'],
				'lk'   => $settings['track_links'] ? 1 : 0,
				'fm'   => $settings['track_forms'] ? 1 : 0,
				'vd'   => $settings['track_video'] ? 1 : 0,
				'er'   => $settings['track_errors'] ? 1 : 0,
				'wv'   => $settings['web_vitals'] ? 1 : 0,
				'ac'   => $settings['autocapture'] ? 1 : 0,
				'hm'   => $settings['heatmaps'] ? 1 : 0,
				'dx'   => $settings['download_extensions'],
				'cs'   => $settings['cta_selectors'],
				'ch'   => $this->chunks( $settings ),
			],
			'ctx' => $this->context->build(),
		];

		/**
		 * Filters the data embedded for the tracker.
		 *
		 * @param array<string, mixed> $data Tracker data.
		 */
		$data = (array) apply_filters( 'blue_lens_tracker_data', $data );

		printf(
			'<script type="application/json" id="%1$s" data-no-optimize="1" data-cfasync="false">%2$s</script>' . "\n",
			esc_attr( self::DATA_ID ),
			wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG prevents breaking out of the script element.
		);
	}

	/**
	 * Adds optimizer opt-out attributes to the tracker tag.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 */
	public function script_attributes( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}

		return str_replace( '<script ', '<script data-no-optimize="1" data-no-minify="1" data-cfasync="false" ', $tag );
	}

	/**
	 * Adds the tracker to URL/pattern-based exclusion lists (WP Rocket, LiteSpeed).
	 *
	 * @param mixed $exclusions Existing exclusions.
	 * @return array<mixed>
	 */
	public function exclude_from_optimizers( mixed $exclusions ): array {
		$exclusions   = is_array( $exclusions ) ? $exclusions : [];
		$exclusions[] = 'blue-lens-analytics/assets/tracker';
		$exclusions[] = self::DATA_ID;

		return $exclusions;
	}

	/**
	 * Adds the tracker handle to handle-based exclusion lists (SiteGround Optimizer).
	 *
	 * @param mixed $handles Existing handles.
	 * @return array<mixed>
	 */
	public function exclude_handles( mixed $handles ): array {
		$handles   = is_array( $handles ) ? $handles : [];
		$handles[] = self::HANDLE;

		return $handles;
	}

	/**
	 * Autoptimize uses a comma-separated string.
	 *
	 * @param mixed $exclusions Existing exclusions.
	 */
	public function exclude_autoptimize( mixed $exclusions ): string {
		$exclusions = is_string( $exclusions ) ? $exclusions : '';

		return ltrim( $exclusions . ', blue-lens-analytics/assets/tracker, ' . self::DATA_ID, ', ' );
	}

	/**
	 * Listener chunks needed by the enabled features. Each is fetched after page load only when used.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return list<string>
	 */
	private function chunks( array $settings ): array {
		$chunks = [];

		if ( $settings['track_links'] || $settings['track_forms'] || $settings['track_video'] || $settings['track_errors'] || $settings['web_vitals'] ) {
			$chunks[] = BLA_URL . $this->script_path( 'auto' ) . '?ver=' . BLA_VERSION;
		}
		if ( $settings['autocapture'] || $settings['heatmaps'] ) {
			$chunks[] = BLA_URL . $this->script_path( 'capture' ) . '?ver=' . BLA_VERSION;
		}

		/**
		 * Filters tracker chunks (script URLs) loaded after the core tracker.
		 *
		 * @param list<string>         $chunks   Script URLs.
		 * @param array<string, mixed> $settings Settings.
		 */
		$chunks = (array) apply_filters( 'blue_lens_tracker_chunks', $chunks, $settings );

		return array_values( array_map( 'esc_url_raw', $chunks ) );
	}

	/**
	 * Registers the enhanced-mode cookie with the WP Consent API so consent banners can list it.
	 */
	public function register_consent_cookie(): void {
		if ( ! function_exists( 'wp_add_cookie_info' ) ) {
			return;
		}

		wp_add_cookie_info(
			Collector::CLIENT_ID_COOKIE,
			'Blue Lens Analytics',
			'statistics',
			__( '13 months', 'blue-lens-analytics' ),
			__( 'Recognises returning visitors. Set only after statistics consent, and only when enhanced mode is enabled.', 'blue-lens-analytics' ),
			false,
			false,
			false
		);
	}

	/**
	 * Minified build when present, else the readable source.
	 *
	 * @param string $name Script name without extension (tracker, auto, capture).
	 */
	private function script_path( string $name = 'tracker' ): string {
		return is_readable( BLA_PATH . "assets/tracker/build/{$name}.min.js" )
			? "assets/tracker/build/{$name}.min.js"
			: "assets/tracker/src/{$name}.js";
	}

	/**
	 * Whether the page is loaded inside the dashboard's heatmap viewer (never tracked, no admin bar).
	 */
	public static function is_heatmap_preview(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag; it can only disable tracking.
		return isset( $_GET['bla_heatmap'] );
	}

	/**
	 * Hides the admin bar in the heatmap viewer so page coordinates match what visitors see.
	 *
	 * @param bool $show Whether to show the admin bar.
	 */
	public function hide_admin_bar_in_preview( bool $show ): bool {
		return self::is_heatmap_preview() ? false : $show;
	}

	/**
	 * Whether a page builder is rendering its editor preview.
	 */
	private function is_builder_preview(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only presence checks.
		foreach ( [ 'elementor-preview', 'et_fb', 'fl_builder', 'ct_builder', 'brizy-edit-iframe', 'vcv-editable', 'tve' ] as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				return true;
			}
		}

		return isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'];
		// phpcs:enable
	}

	/**
	 * Whether the logged-in user has an excluded role.
	 */
	private function is_excluded_user(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$excluded = (array) $this->settings->get( 'excluded_roles' );

		return (bool) array_intersect( wp_get_current_user()->roles, $excluded );
	}
}
