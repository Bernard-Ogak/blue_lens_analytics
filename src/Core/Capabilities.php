<?php
/**
 * Custom capability management.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Two capabilities:
 * - manage_blue_lens: settings, data management (administrators);
 * - view_blue_lens_reports: dashboards and exports (administrators; add roles such as editor or
 *   shop_manager with the blue_lens_report_roles filter to share reports without settings access).
 */
final class Capabilities {

	public const MANAGE = 'manage_blue_lens';
	public const VIEW   = 'view_blue_lens_reports';

	/**
	 * Roles that receive manage_blue_lens on install.
	 *
	 * @return list<string>
	 */
	public static function roles(): array {
		/**
		 * Filters the roles granted manage_blue_lens on install and upgrade.
		 *
		 * @param list<string> $roles Role slugs.
		 */
		$roles = (array) apply_filters( 'blue_lens_capability_roles', [ 'administrator' ] );

		return array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );
	}

	/**
	 * Roles that receive view_blue_lens_reports on install (always includes the manage roles).
	 *
	 * @return list<string>
	 */
	public static function report_roles(): array {
		/**
		 * Filters the roles granted view_blue_lens_reports on install and upgrade.
		 *
		 * @param list<string> $roles Role slugs.
		 */
		$roles = (array) apply_filters( 'blue_lens_report_roles', [ 'administrator' ] );

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_merge( $roles, self::roles() ) ) ) ) );
	}

	/**
	 * Grants the capabilities to the configured roles on the current site.
	 */
	public static function add(): void {
		foreach ( [ self::MANAGE => self::roles(), self::VIEW => self::report_roles() ] as $cap => $roles ) {
			foreach ( $roles as $slug ) {
				$role = get_role( $slug );
				if ( $role && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Revokes both capabilities from every role on the current site.
	 */
	public static function remove(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( [ self::MANAGE, self::VIEW ] as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
