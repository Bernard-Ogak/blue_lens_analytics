<?php
/**
 * Deactivation handling.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stops background work on deactivation. Data is kept; removal happens only on uninstall.
 */
final class Deactivator {

	/**
	 * Deactivation hook callback.
	 *
	 * @param bool|mixed $network_wide Whether the plugin is being network-deactivated.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			Network::each_site( [ self::class, 'deactivate_site' ] );
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Cancels this plugin's pending background jobs on the current site.
	 */
	public static function deactivate_site(): void {
		Jobs::unschedule_all();

		/**
		 * Fires when Blue Lens is deactivated on a site.
		 */
		do_action( 'blue_lens_deactivated' );
	}
}
