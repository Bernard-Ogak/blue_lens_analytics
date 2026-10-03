<?php
/**
 * Admin menu and system status screen.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Admin;

use BlueLens\Analytics\Core\Capabilities;
use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Installer;
use BlueLens\Analytics\Core\Migrations\Migrator;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Core\Tables;
use BlueLens\Analytics\Tracking\Geo\GeoDatabase;

defined( 'ABSPATH' ) || exit;

/**
 * System status screen (Blue Lens → Status), GeoIP update action, migration notices and plugin links.
 * The menu itself is registered by Dashboard.
 */
final class Menu implements Hookable {

	public const SLUG = Dashboard::STATUS_SLUG;

	private const GEO_ACTION = 'blue_lens_geo_update';

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings service.
	 * @param Migrator    $migrator Migration runner.
	 * @param GeoDatabase $geo      GeoIP database manager.
	 */
	public function __construct(
		private Settings $settings,
		private Migrator $migrator,
		private GeoDatabase $geo
	) {}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', [ $this, 'render_migration_notice' ] );
		add_action( 'admin_post_' . self::GEO_ACTION, [ $this, 'handle_geo_update' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( BLA_FILE ), [ $this, 'add_action_links' ] );
	}

	/**
	 * Runs a GeoIP database update from the status screen.
	 */
	public function handle_geo_update(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'blue-lens-analytics' ), 403 );
		}
		check_admin_referer( self::GEO_ACTION );

		$result = $this->geo->update();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::SLUG,
					'bla_geo' => is_wp_error( $result ) ? 'error' : 'updated',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}


	/**
	 * Adds "Dashboard" and "Settings" links on the Plugins screen.
	 *
	 * @param array<string, string>|array<int, string> $links Existing links.
	 * @return array<string|int, string>
	 */
	public function add_action_links( array $links ): array {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . Dashboard::SETTINGS_SLUG ) ), esc_html__( 'Settings', 'blue-lens-analytics' ) ) );
		}
		if ( current_user_can( Capabilities::VIEW ) ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . Dashboard::SLUG ) ), esc_html__( 'Dashboard', 'blue-lens-analytics' ) ) );
		}

		return $links;
	}

	/**
	 * Shows the last migration failure to users who can act on it.
	 */
	public function render_migration_notice(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		$error = get_option( Installer::ERROR_OPTION );
		if ( ! is_string( $error ) || '' === $error ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p><code>%3$s</code></p></div>',
			esc_html__( 'Blue Lens Analytics could not update its database.', 'blue-lens-analytics' ),
			esc_html__( 'Tracking data may not be saved. The update is retried automatically every 10 minutes.', 'blue-lens-analytics' ),
			esc_html( $error )
		);
	}

	/**
	 * Renders the status screen.
	 */
	public function render_status_page(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blue-lens-analytics' ), 403 );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Blue Lens Status', 'blue-lens-analytics' ) . '</h1>';
		echo '<p>' . esc_html__( 'Health of the tracking, database and background jobs.', 'blue-lens-analytics' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $this->status_rows() as $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %2$s is one of two literal icon strings.
			printf(
				'<tr><th scope="row" style="width:260px">%1$s</th><td>%2$s %3$s</td></tr>',
				esc_html( $row['label'] ),
				$row['ok'] ? '<span class="dashicons dashicons-yes-alt" style="color:#008a20"></span>' : '<span class="dashicons dashicons-warning" style="color:#d63638"></span>',
				esc_html( $row['value'] )
			);
		}
		echo '</tbody></table>';

		$this->render_geo_section();
		$this->render_tables();
		echo '</div>';
	}

	/**
	 * Events and sessions received in the last 24 hours (uses the occurred_at/started_at indexes).
	 *
	 * @return array{label: string, value: string, ok: bool}
	 */
	private function traffic_row(): array {
		global $wpdb;

		$since    = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$events   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE occurred_at >= %s', Tables::name( Tables::EVENTS ), $since ) );
		$sessions = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE started_at >= %s', Tables::name( Tables::SESSIONS ), $since ) );

		return [
			'label' => __( 'Last 24 hours', 'blue-lens-analytics' ),
			/* translators: 1: session count, 2: event count. */
			'value' => sprintf( __( '%1$s sessions, %2$s events', 'blue-lens-analytics' ), number_format_i18n( $sessions ), number_format_i18n( $events ) ),
			'ok'    => $events > 0,
		];
	}

	/**
	 * GeoIP database status.
	 *
	 * @param string $provider Configured provider.
	 * @return array{label: string, value: string, ok: bool}
	 */
	private function geo_row( string $provider ): array {
		$meta = $this->geo->meta();

		if ( 'none' === $provider ) {
			$value = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] )
				? __( 'No database; country from Cloudflare header only', 'blue-lens-analytics' )
				: __( 'Not configured (no location data)', 'blue-lens-analytics' );
			$ok    = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
		} elseif ( '' !== $meta['error'] ) {
			$value = $meta['error'];
			$ok    = false;
		} elseif ( $this->geo->is_available() ) {
			/* translators: 1: provider name, 2: update date, 3: file size. */
			$value = sprintf( __( '%1$s, updated %2$s UTC (%3$s)', 'blue-lens-analytics' ), 'maxmind' === $provider ? 'MaxMind GeoLite2' : 'DB-IP Lite', $meta['updated_at'], size_format( $meta['size'] ) );
			$ok    = true;
		} else {
			$value = __( 'Not downloaded yet', 'blue-lens-analytics' );
			$ok    = false;
		}

		return [
			'label' => __( 'GeoIP database', 'blue-lens-analytics' ),
			'value' => $value,
			'ok'    => $ok,
		];
	}

	/**
	 * Warns when a CDN header is present but visitor IPs are read from REMOTE_ADDR (all visitors
	 * would share the CDN's IPs, breaking cookieless visitor counts and geo lookups).
	 *
	 * @param string $ip_header Configured source.
	 * @return array{label: string, value: string, ok: bool}
	 */
	private function proxy_row( string $ip_header ): array {
		$cdn_header = null;
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP' ] as $header ) {
			if ( isset( $_SERVER[ $header ] ) ) {
				$cdn_header = $header;
				break;
			}
		}

		$ok = null === $cdn_header || 'remote_addr' !== $ip_header;

		return [
			'label' => __( 'Visitor IP source', 'blue-lens-analytics' ),
			'value' => $ok
				? strtoupper( $ip_header )
				/* translators: %s: header name. */
				: sprintf( __( 'REMOTE_ADDR, but %s is present: set ip_header if this site is behind a proxy or CDN you trust.', 'blue-lens-analytics' ), $cdn_header ),
			'ok'    => $ok,
		];
	}

	/**
	 * GeoIP update button and attribution.
	 */
	private function render_geo_section(): void {
		$provider = (string) $this->settings->get( 'geoip_provider' );
		if ( 'none' === $provider ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our redirect.
		$flag = isset( $_GET['bla_geo'] ) ? sanitize_key( $_GET['bla_geo'] ) : '';
		if ( 'updated' === $flag ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'GeoIP database updated.', 'blue-lens-analytics' ) . '</p></div>';
		} elseif ( 'error' === $flag ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $this->geo->meta()['error'] ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::GEO_ACTION ) . '">';
		wp_nonce_field( self::GEO_ACTION );
		submit_button( __( 'Update GeoIP database now', 'blue-lens-analytics' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( 'dbip' === $provider ) {
			echo '<p class="description"><a href="https://db-ip.com" target="_blank" rel="noopener">' . esc_html__( 'IP Geolocation by DB-IP', 'blue-lens-analytics' ) . '</a> (CC BY 4.0)</p>';
		}
	}

	/**
	 * Status rows.
	 *
	 * @return list<array{label: string, value: string, ok: bool}>
	 */
	private function status_rows(): array {
		$current  = $this->migrator->current_version();
		$latest   = $this->migrator->latest_version();
		$settings = $this->settings->all();
		$has_as   = class_exists( 'ActionScheduler_Versions' );

		return [
			[
				'label' => __( 'Plugin version', 'blue-lens-analytics' ),
				'value' => BLA_VERSION,
				'ok'    => true,
			],
			[
				'label' => __( 'Database schema', 'blue-lens-analytics' ),
				/* translators: 1: installed schema version, 2: latest schema version. */
				'value' => sprintf( __( 'version %1$d of %2$d', 'blue-lens-analytics' ), $current, $latest ),
				'ok'    => $current >= $latest,
			],
			[
				'label' => __( 'Tracking', 'blue-lens-analytics' ),
				'value' => $settings['tracking_enabled'] ? __( 'Enabled', 'blue-lens-analytics' ) : __( 'Disabled', 'blue-lens-analytics' ),
				'ok'    => (bool) $settings['tracking_enabled'],
			],
			[
				'label' => __( 'Privacy mode', 'blue-lens-analytics' ),
				'value' => Settings::MODE_ENHANCED === $settings['privacy_mode']
					? __( 'Enhanced (first-party ID after consent)', 'blue-lens-analytics' )
					: __( 'Cookieless', 'blue-lens-analytics' ),
				'ok'    => true,
			],
			[
				'label' => __( 'Background jobs', 'blue-lens-analytics' ),
				'value' => $has_as
					/* translators: %s: Action Scheduler version. */
					? sprintf( __( 'Action Scheduler %s', 'blue-lens-analytics' ), $this->action_scheduler_version() )
					: __( 'Action Scheduler not found. Run "composer install" in the plugin folder.', 'blue-lens-analytics' ),
				'ok'    => $has_as,
			],
			$this->traffic_row(),
			$this->geo_row( (string) $settings['geoip_provider'] ),
			$this->proxy_row( (string) $settings['ip_header'] ),
			[
				'label' => __( 'Delete data on uninstall', 'blue-lens-analytics' ),
				'value' => $settings['delete_data_on_uninstall'] ? __( 'Yes', 'blue-lens-analytics' ) : __( 'No', 'blue-lens-analytics' ),
				'ok'    => true,
			],
			[
				'label' => __( 'Environment', 'blue-lens-analytics' ),
				/* translators: 1: PHP version, 2: WordPress version, 3: "multisite" or "single site". */
				'value' => sprintf( __( 'PHP %1$s, WordPress %2$s, %3$s', 'blue-lens-analytics' ), PHP_VERSION, get_bloginfo( 'version' ), is_multisite() ? __( 'multisite', 'blue-lens-analytics' ) : __( 'single site', 'blue-lens-analytics' ) ),
				'ok'    => true,
			],
		];
	}

	/**
	 * Renders the table checklist.
	 */
	private function render_tables(): void {
		$existing = Tables::existing();

		echo '<h2>' . esc_html__( 'Database tables', 'blue-lens-analytics' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( Tables::all() as $table ) {
			$ok = in_array( $table, $existing, true );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %2$s is built from escaped literals.
			printf(
				'<tr><td style="width:260px"><code>%1$s</code></td><td>%2$s</td></tr>',
				esc_html( $table ),
				$ok ? esc_html__( 'Present', 'blue-lens-analytics' ) : '<strong style="color:#d63638">' . esc_html__( 'Missing', 'blue-lens-analytics' ) . '</strong>'
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Loaded Action Scheduler version.
	 */
	private function action_scheduler_version(): string {
		if ( class_exists( 'ActionScheduler_Versions' ) ) {
			return (string) \ActionScheduler_Versions::instance()->latest_version();
		}

		return '';
	}
}
