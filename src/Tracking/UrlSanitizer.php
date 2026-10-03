<?php
/**
 * URL parsing and PII stripping.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

use BlueLens\Analytics\Privacy\PiiScrubber;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw URLs into storable parts. Query strings are never stored whole: only UTM parameters and
 * click IDs are extracted, and every stored string passes through the PII scrubber.
 */
final class UrlSanitizer {

	public const UTM_KEYS = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ];

	public const CLICK_IDS = [ 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid' ];

	/**
	 * Constructor.
	 *
	 * @param PiiScrubber $scrubber PII scrubber.
	 */
	public function __construct( private PiiScrubber $scrubber ) {}

	/**
	 * Splits a URL into host, path and query parameters.
	 *
	 * @param string $url Absolute URL.
	 * @return array{host: string, path: string, query: array<string, string>}
	 */
	public function parse( string $url ): array {
		$parts = wp_parse_url( $url );
		$query = [];

		if ( is_array( $parts ) && isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $parsed );
			foreach ( $parsed as $key => $value ) {
				if ( is_string( $value ) ) {
					$query[ strtolower( (string) $key ) ] = $value;
				}
			}
		}

		return [
			'host'  => is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '',
			'path'  => $this->with_identity_query( $this->clean_path( is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/' ), $query ),
			'query' => $query,
		];
	}

	/**
	 * Keeps the query parameters that identify content on sites without pretty permalinks
	 * (e.g. /?page_id=4), so different pages do not collapse into "/".
	 *
	 * @param string                $path  Clean path.
	 * @param array<string, string> $query Query parameters.
	 */
	private function with_identity_query( string $path, array $query ): string {
		/**
		 * Filters the query parameters kept in stored page paths. Values must be simple slugs or numbers.
		 *
		 * @param list<string> $params Parameter names.
		 */
		$params = (array) apply_filters( 'blue_lens_path_query_params', [ 'p', 'page_id', 'cat', 'tag', 'author', 'post_type', 'product', 'product_cat', 'paged', 'lang' ] );

		$kept = [];
		foreach ( $params as $param ) {
			$param = (string) $param;
			if ( isset( $query[ $param ] ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $query[ $param ] ) ) {
				$kept[ $param ] = $query[ $param ];
			}
		}

		return $kept ? mb_substr( $path . '?' . http_build_query( $kept ), 0, 512 ) : $path;
	}

	/**
	 * Normalizes and scrubs a path.
	 *
	 * @param string $path Raw path.
	 */
	public function clean_path( string $path ): string {
		$path = '/' . ltrim( $path, '/' );
		$path = (string) preg_replace( '#/{2,}#', '/', $path );
		$path = $this->scrubber->scrub( rawurldecode( $path ) );
		$path = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $path );

		return mb_substr( $path, 0, 512 );
	}

	/**
	 * UTM parameters, scrubbed and length-limited.
	 *
	 * @param array<string, string> $query Query parameters.
	 * @return array<string, string> Keys from UTM_KEYS; missing values are ''.
	 */
	public function campaign( array $query ): array {
		$utm = [];

		foreach ( self::UTM_KEYS as $key ) {
			$value       = isset( $query[ $key ] ) ? sanitize_text_field( $query[ $key ] ) : '';
			$utm[ $key ] = mb_substr( $this->scrubber->scrub( $value ), 0, 191 );
		}

		return $utm;
	}

	/**
	 * First advertising click ID present.
	 *
	 * @param array<string, string> $query Query parameters.
	 * @return array{type: string, value: string}
	 */
	public function click_id( array $query ): array {
		foreach ( self::CLICK_IDS as $key ) {
			if ( isset( $query[ $key ] ) && 1 === preg_match( '/^[A-Za-z0-9_\-.~]{1,255}$/', $query[ $key ] ) ) {
				return [
					'type'  => $key,
					'value' => $query[ $key ],
				];
			}
		}

		return [
			'type'  => '',
			'value' => '',
		];
	}

	/**
	 * Referrer domain and URL without its query string.
	 *
	 * @param string $url Raw referrer.
	 * @return array{domain: string, url: string}
	 */
	public function referrer( string $url ): array {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', [ 'http', 'https', 'android-app' ], true ) ) {
			return [
				'domain' => '',
				'url'    => '',
			];
		}

		$host   = strtolower( $parts['host'] );
		$domain = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		$path   = $this->clean_path( $parts['path'] ?? '/' );

		return [
			'domain' => mb_substr( $domain, 0, 191 ),
			'url'    => mb_substr( $parts['scheme'] . '://' . $host . $path, 0, 1024 ),
		];
	}

	/**
	 * Host without a leading "www.".
	 *
	 * @param string $host Host.
	 */
	public static function bare_host( string $host ): string {
		$host = strtolower( $host );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Whether a path matches any pattern; "*" matches any run of characters.
	 *
	 * @param string       $path     Path.
	 * @param list<string> $patterns Patterns such as /checkout/*.
	 */
	public static function path_matches( string $path, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
			if ( 1 === preg_match( $regex, $path ) ) {
				return true;
			}
		}

		return false;
	}
}
