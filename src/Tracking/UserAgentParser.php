<?php
/**
 * Lightweight user agent parser.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Regex-based parser covering the browsers and platforms that make up nearly all real traffic.
 * Order matters: more specific tokens (Edge, Opera, in-app browsers) are checked before Chrome/Safari.
 */
final class UserAgentParser {

	/**
	 * Browser patterns: name => regex with the major version in the first non-empty group.
	 */
	private const BROWSERS = [
		'Facebook App'     => '/FBAV\/(\d+)|FBAN/',
		'Instagram App'    => '/Instagram (\d+)/',
		'Edge'             => '/Edg(?:e|A|iOS)?\/(\d+)/',
		'Opera'            => '/OPR\/(\d+)|Opera.*Version\/(\d+)|OPT\/(\d+)/',
		'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
		'UC Browser'       => '/UCBrowser\/(\d+)/',
		'Yandex'           => '/YaBrowser\/(\d+)/',
		'Vivaldi'          => '/Vivaldi\/(\d+)/',
		'Firefox'          => '/(?:Firefox|FxiOS)\/(\d+)/',
		'Chrome'           => '/(?:Chrome|CriOS)\/(\d+)/',
		'Safari'           => '/Version\/(\d+)[\d.]* (?:Mobile\/\S+ )?Safari/',
		'Internet Explorer' => '/MSIE (\d+)|Trident\/.*rv:(\d+)/',
	];

	private const WINDOWS_VERSIONS = [
		'10.0' => '10',
		'6.3'  => '8.1',
		'6.2'  => '8',
		'6.1'  => '7',
		'6.0'  => 'Vista',
		'5.1'  => 'XP',
	];

	/**
	 * Parses a user agent, using the Sec-CH-UA-Platform hint when the UA string is frozen or empty.
	 *
	 * @param string $ua          User-Agent header.
	 * @param string $ch_platform Sec-CH-UA-Platform value, unquoted.
	 * @param bool   $touch_mac   Client reported a touch-capable "Mac" (iPadOS in desktop mode).
	 */
	public function parse( string $ua, string $ch_platform = '', bool $touch_mac = false ): UserAgentInfo {
		[ $browser, $browser_version ] = $this->browser( $ua );
		[ $os, $os_version ]           = $this->os( $ua, $ch_platform );
		$device                        = $this->device( $ua );

		if ( $touch_mac && 'macOS' === $os ) {
			$os         = 'iPadOS';
			$os_version = '';
			$device     = 'tablet';
		}

		return new UserAgentInfo( $browser, $browser_version, $os, $os_version, $device );
	}

	/**
	 * Browser name and major version.
	 *
	 * @param string $ua User agent.
	 * @return array{0: string, 1: string}
	 */
	private function browser( string $ua ): array {
		foreach ( self::BROWSERS as $name => $pattern ) {
			if ( preg_match( $pattern, $ua, $m ) ) {
				$version = '';
				foreach ( array_slice( $m, 1 ) as $group ) {
					if ( '' !== $group ) {
						$version = $group;
						break;
					}
				}
				return [ $name, $version ];
			}
		}

		return [ '' === $ua ? '' : 'Other', '' ];
	}

	/**
	 * OS name and version.
	 *
	 * @param string $ua          User agent.
	 * @param string $ch_platform Platform client hint.
	 * @return array{0: string, 1: string}
	 */
	private function os( string $ua, string $ch_platform ): array {
		if ( preg_match( '/Windows NT (\d+\.\d+)/', $ua, $m ) ) {
			return [ 'Windows', self::WINDOWS_VERSIONS[ $m[1] ] ?? $m[1] ];
		}
		if ( preg_match( '/iPad.*? OS (\d+)_/', $ua, $m ) ) {
			return [ 'iPadOS', $m[1] ];
		}
		if ( preg_match( '/(?:iPhone|iPod).*? OS (\d+)_/', $ua, $m ) ) {
			return [ 'iOS', $m[1] ];
		}
		if ( preg_match( '/Android (\d+)/', $ua, $m ) ) {
			return [ 'Android', $m[1] ];
		}
		if ( str_contains( $ua, 'Android' ) ) {
			return [ 'Android', '' ];
		}
		if ( str_contains( $ua, 'CrOS' ) ) {
			return [ 'ChromeOS', '' ];
		}
		if ( preg_match( '/Mac OS X (\d+)[_.](\d+)/', $ua, $m ) ) {
			return [ 'macOS', $m[1] . '.' . $m[2] ];
		}
		if ( str_contains( $ua, 'Macintosh' ) ) {
			return [ 'macOS', '' ];
		}
		if ( str_contains( $ua, 'Linux' ) ) {
			return [ 'Linux', '' ];
		}

		$hinted = [
			'Windows'   => 'Windows',
			'macOS'     => 'macOS',
			'Android'   => 'Android',
			'iOS'       => 'iOS',
			'Chrome OS' => 'ChromeOS',
			'Linux'     => 'Linux',
		];

		return [ $hinted[ $ch_platform ] ?? ( '' === $ua ? '' : 'Other' ), '' ];
	}

	/**
	 * Device class.
	 *
	 * @param string $ua User agent.
	 */
	private function device( string $ua ): string {
		if ( preg_match( '/SmartTV|SMART-TV|HbbTV|AppleTV|CrKey|Tizen.+TV|Roku|BRAVIA|NetCast|Web0S/i', $ua ) ) {
			return 'tv';
		}
		if ( preg_match( '/iPad|Tablet|Kindle|Silk|PlayBook|Android(?!.*Mobile)/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Mobi|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini|IEMobile/i', $ua ) ) {
			return 'mobile';
		}

		return 'desktop';
	}
}
