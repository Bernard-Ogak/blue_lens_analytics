<?php
/**
 * Parsed user agent.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Browser, OS and device class. Versions are major only to limit fingerprinting value.
 */
final class UserAgentInfo {

	/**
	 * Constructor.
	 *
	 * @param string $browser         e.g. "Chrome".
	 * @param string $browser_version Major version.
	 * @param string $os              e.g. "Android".
	 * @param string $os_version      Major (or major.minor for Windows/macOS).
	 * @param string $device_type     desktop|mobile|tablet|tv.
	 */
	public function __construct(
		public readonly string $browser,
		public readonly string $browser_version,
		public readonly string $os,
		public readonly string $os_version,
		public readonly string $device_type
	) {}
}
