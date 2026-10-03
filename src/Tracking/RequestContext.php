<?php
/**
 * Immutable view of the incoming request.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Request facts needed by the collector. Held in memory only.
 */
final class RequestContext {

	/**
	 * Constructor.
	 *
	 * @param string $ip          Visitor IP ('' when unknown). Never persisted.
	 * @param string $user_agent  User-Agent header.
	 * @param bool   $dnt         DNT: 1 header present.
	 * @param bool   $gpc         Sec-GPC: 1 header present.
	 * @param string $ch_platform Sec-CH-UA-Platform client hint, unquoted.
	 * @param string $cf_country  CF-IPCountry header (Cloudflare), uppercase.
	 * @param int    $now         Request time (Unix).
	 */
	public function __construct(
		public readonly string $ip,
		public readonly string $user_agent,
		public readonly bool $dnt,
		public readonly bool $gpc,
		public readonly string $ch_platform,
		public readonly string $cf_country,
		public readonly int $now
	) {}

	/**
	 * Builds the context from $_SERVER.
	 *
	 * @param string                    $ip_source Settings ip_header value.
	 * @param array<string, mixed>|null $server    Server vars; defaults to $_SERVER.
	 */
	public static function from_globals( string $ip_source, ?array $server = null ): self {
		$server = $server ?? $_SERVER;

		$header = static function ( string $key ) use ( $server ): string {
			return isset( $server[ $key ] ) && is_string( $server[ $key ] )
				? substr( sanitize_text_field( wp_unslash( $server[ $key ] ) ), 0, 512 )
				: '';
		};

		return new self(
			ClientIp::resolve( $ip_source, $server ),
			$header( 'HTTP_USER_AGENT' ),
			'1' === $header( 'HTTP_DNT' ),
			'1' === $header( 'HTTP_SEC_GPC' ),
			trim( $header( 'HTTP_SEC_CH_UA_PLATFORM' ), '"' ),
			strtoupper( $header( 'HTTP_CF_IPCOUNTRY' ) ),
			time()
		);
	}
}
