<?php
/**
 * Fallback PSR-4 autoloader, used only when Composer's autoloader is absent.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PSR-4 autoloader for the plugin namespace.
 */
final class Autoloader {

	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Registers the autoloader.
	 *
	 * @param string $base_dir Absolute path to the src directory, with trailing slash.
	 */
	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$file     = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		);
	}
}
