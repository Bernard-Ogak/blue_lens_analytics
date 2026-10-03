<?php
/**
 * Plugin Name:       Blue Lens Analytics
 * Plugin URI:        https://github.com/Bernard-Ogak/blue_lens_analytics
 * Update URI:        https://github.com/Bernard-Ogak/blue_lens_analytics
 * Description:       Privacy-first, self-hosted analytics and SEO site audit for WordPress. Cookieless by default, with on-page SEO ideas and Local Ads reporting.
 * Version:           0.5.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Bernard Ogak (Creative Bay)
 * Author URI:        https://www.creativebay.co.ke
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blue-lens-analytics
 * Domain Path:       /languages
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'BLA_VERSION', '0.5.1' );
define( 'BLA_FILE', __FILE__ );
define( 'BLA_PATH', plugin_dir_path( __FILE__ ) );
define( 'BLA_URL', plugin_dir_url( __FILE__ ) );
define( 'BLA_MIN_PHP', '8.1' );
define( 'BLA_MIN_WP', '6.4' );

/**
 * Whether the server meets the minimum PHP and WordPress versions.
 *
 * Kept free of PHP 8 syntax so older runtimes can still parse this file and show a notice.
 *
 * @return bool
 */
function bla_requirements_met() {
	return version_compare( PHP_VERSION, BLA_MIN_PHP, '>=' )
		&& version_compare( (string) get_bloginfo( 'version' ), BLA_MIN_WP, '>=' );
}

/**
 * Admin notice shown when requirements are not met.
 *
 * @return void
 */
function bla_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: minimum PHP version, 2: minimum WordPress version. */
				__( 'Blue Lens Analytics requires PHP %1$s+ and WordPress %2$s+. The plugin stays inactive until the server is upgraded.', 'blue-lens-analytics' ),
				BLA_MIN_PHP,
				BLA_MIN_WP
			)
		)
	);
}

if ( ! bla_requirements_met() ) {
	add_action( 'admin_notices', 'bla_requirements_notice' );
	return;
}

if ( is_readable( BLA_PATH . 'vendor/autoload.php' ) ) {
	require_once BLA_PATH . 'vendor/autoload.php';
} else {
	require_once BLA_PATH . 'src/Autoloader.php';
	\BlueLens\Analytics\Autoloader::register( BLA_PATH . 'src/' );
}

require_once BLA_PATH . 'src/functions.php';

// Action Scheduler must be included from the main plugin file so its registry can pick the newest bundled copy.
if ( is_readable( BLA_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once BLA_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook( BLA_FILE, [ \BlueLens\Analytics\Core\Installer::class, 'activate' ] );
register_deactivation_hook( BLA_FILE, [ \BlueLens\Analytics\Core\Deactivator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		\BlueLens\Analytics\Core\Plugin::instance()->boot();
	}
);

/**
 * Returns the plugin instance.
 *
 * @return \BlueLens\Analytics\Core\Plugin
 */
function blue_lens(): \BlueLens\Analytics\Core\Plugin {
	return \BlueLens\Analytics\Core\Plugin::instance();
}
