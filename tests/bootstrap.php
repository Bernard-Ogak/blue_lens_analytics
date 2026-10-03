<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and the plugin.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

$bla_root = dirname( __DIR__ );

require_once $bla_root . '/vendor/autoload.php';

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

$bla_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $bla_tests_dir ) {
	$bla_tests_dir = $bla_root . '/vendor/wp-phpunit/wp-phpunit';
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $bla_root . '/vendor/yoast/phpunit-polyfills' );

require_once $bla_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $bla_root ): void {
		require $bla_root . '/blue-lens-analytics.php';
	}
);

// Install tables before WP_UnitTestCase starts rewriting CREATE TABLE into temporary tables.
tests_add_filter(
	'plugins_loaded',
	static function (): void {
		\BlueLens\Analytics\Core\Installer::activate( false );
	},
	20
);

require $bla_tests_dir . '/includes/bootstrap.php';
