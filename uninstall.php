<?php
/**
 * Uninstall entry point. Data is removed only where "Delete all data on uninstall" is enabled.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	return;
}

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	require_once __DIR__ . '/src/Autoloader.php';
	\BlueLens\Analytics\Autoloader::register( __DIR__ . '/src/' );
}

\BlueLens\Analytics\Core\Uninstaller::run();
