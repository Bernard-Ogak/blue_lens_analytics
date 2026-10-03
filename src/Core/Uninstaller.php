<?php
/**
 * Uninstall routine.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Removes plugin data, but only on sites where "Delete all data on uninstall" is enabled.
 *
 * Runs from uninstall.php without the plugin booted, so it must not rely on BLA_* constants or services.
 */
final class Uninstaller {

	/**
	 * Options deleted explicitly (keeps object caches consistent) before the prefix sweep.
	 */
	private const OPTIONS = [
		Settings::OPTION,
		Installer::VERSION_OPTION,
		Installer::SALT_OPTION,
		Installer::ERROR_OPTION,
		Migrations\Migrator::VERSION_OPTION,
		Migrations\Migrator::LOG_OPTION,
		Migrations\Migrator::LOCK_OPTION,
	];

	/**
	 * Uninstalls on every site (including archived ones on multisite).
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			Network::each_site(
				static function (): void {
					self::uninstall_site();
				},
				0,
				false
			);
			return;
		}

		self::uninstall_site();
	}

	/**
	 * Removes data from the current site if the site opted in.
	 *
	 * @return bool Whether data was removed.
	 */
	public static function uninstall_site(): bool {
		$settings = get_option( Settings::OPTION );

		if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
			return false;
		}

		self::drop_tables();
		self::delete_scheduled_actions();
		self::delete_options();
		self::delete_files();
		Capabilities::remove();

		return true;
	}

	/**
	 * Deletes uploads/blue-lens (GeoIP database and future exports) for the current site.
	 */
	private static function delete_files(): void {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'blue-lens';

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Drops every plugin table on the current site.
	 */
	private static function drop_tables(): void {
		global $wpdb;

		foreach ( Tables::all() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	/**
	 * Deletes this plugin's Action Scheduler rows. Action Scheduler is not loaded during uninstall,
	 * so its tables are cleaned directly.
	 */
	private static function delete_scheduled_actions(): void {
		global $wpdb;

		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';

		if ( $groups !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $groups ) ) ) ) {
			return;
		}

		$group_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s', $groups, Plugin::ACTION_GROUP )
		);

		if ( 0 === $group_id ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'DELETE l FROM %i l INNER JOIN %i a ON a.action_id = l.action_id WHERE a.group_id = %d',
				$logs,
				$actions,
				$group_id
			)
		);
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $actions, $group_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $groups, $group_id ) );
	}

	/**
	 * Deletes options and transients with the blue_lens_ prefix.
	 */
	private static function delete_options(): void {
		global $wpdb;

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'blue_lens_' ) . '%',
				$wpdb->esc_like( '_transient_blue_lens_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_blue_lens_' ) . '%'
			)
		);

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		// Per-user dashboard preferences (user meta is shared across a network; deleting it is harmless).
		delete_metadata( 'user', 0, \BlueLens\Analytics\Admin\Preferences::META_KEY, '', true );
	}
}
