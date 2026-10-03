<?php
/**
 * Activation, upgrades, and new-site setup.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

use BlueLens\Analytics\Core\Migrations\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Installs or upgrades the plugin on a site. Every step is idempotent.
 */
final class Installer implements Hookable {

	public const VERSION_OPTION = 'blue_lens_version';
	public const SALT_OPTION    = 'blue_lens_site_salt';
	public const ERROR_OPTION   = 'blue_lens_migration_error';

	/**
	 * Set after a failed upgrade so a broken migration is not retried on every request.
	 */
	private const BACKOFF_TRANSIENT = 'blue_lens_migration_backoff';

	/**
	 * Constructor.
	 *
	 * @param Migrator $migrator Migration runner.
	 * @param Settings $settings Settings service.
	 */
	public function __construct(
		private Migrator $migrator,
		private Settings $settings
	) {}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'maybe_upgrade' ], 1 );
		add_action( 'wp_initialize_site', [ $this, 'initialize_new_site' ], 200 );
	}

	/**
	 * Activation hook callback.
	 *
	 * On network activation, installs on up to `blue_lens_network_activation_limit` sites (default 100);
	 * the remaining sites install themselves on their next request via maybe_upgrade().
	 *
	 * @param bool|mixed $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		$installer = Plugin::instance()->container()->get( self::class );

		if ( is_multisite() && $network_wide ) {
			/**
			 * Filters how many sites are installed synchronously on network activation.
			 *
			 * @param int $limit Site count.
			 */
			$limit = (int) apply_filters( 'blue_lens_network_activation_limit', 100 );

			Network::each_site(
				static function () use ( $installer ): void {
					$installer->install_site();
				},
				max( 1, $limit )
			);
			return;
		}

		$installer->install_site();
	}

	/**
	 * Installs or upgrades the current site.
	 *
	 * @return bool False when another process is migrating; the next request will retry.
	 */
	public function install_site(): bool {
		if ( ! $this->migrator->migrate() ) {
			return false;
		}

		Capabilities::add();
		$this->ensure_salt();
		$this->settings->ensure_defaults();

		update_option( self::VERSION_OPTION, BLA_VERSION, true );
		delete_option( self::ERROR_OPTION );
		delete_transient( self::BACKOFF_TRANSIENT );

		/**
		 * Fires after Blue Lens is installed or upgraded on a site.
		 *
		 * @param string $version Plugin version.
		 */
		do_action( 'blue_lens_installed', BLA_VERSION );

		return true;
	}

	/**
	 * Runs install_site() after a plugin update or on a network site not yet installed.
	 *
	 * Costs two autoloaded option reads when nothing is pending.
	 */
	public function maybe_upgrade(): void {
		if ( BLA_VERSION === get_option( self::VERSION_OPTION ) && ! $this->migrator->needs_migration() ) {
			return;
		}

		if ( false !== get_transient( self::BACKOFF_TRANSIENT ) ) {
			return;
		}

		try {
			$this->install_site();
		} catch ( \Throwable $e ) {
			$this->record_failure( $e );
		}
	}

	/**
	 * Installs on sites created while the plugin is network-active.
	 *
	 * @param \WP_Site $site New site.
	 */
	public function initialize_new_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( BLA_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		try {
			$this->install_site();
		} catch ( \Throwable $e ) {
			// Never break site creation; the site retries on its next request.
			$this->record_failure( $e );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Creates the per-site secret salt used for cookieless visitor hashing.
	 */
	private function ensure_salt(): void {
		if ( get_option( self::SALT_OPTION ) ) {
			return;
		}

		add_option( self::SALT_OPTION, bin2hex( random_bytes( 32 ) ), '', true );
	}

	/**
	 * Stores the failure for the admin notice and backs off retries for ten minutes.
	 *
	 * @param \Throwable $e Failure.
	 */
	private function record_failure( \Throwable $e ): void {
		update_option( self::ERROR_OPTION, sanitize_text_field( $e->getMessage() ), false );
		set_transient( self::BACKOFF_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'Blue Lens Analytics migration failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
