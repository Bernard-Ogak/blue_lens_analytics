<?php
/**
 * Contract for services that attach WordPress hooks.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A service that registers actions and filters when the plugin boots.
 */
interface Hookable {

	/**
	 * Attaches the service's actions and filters.
	 */
	public function register_hooks(): void;
}
