<?php
/**
 * Single-page checks for the site audit.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Modules\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Parses one fetched page and returns its facts and the issues that can be judged from that page
 * alone. Cross-page issues (duplicates, orphans, broken links, traffic) are added by SiteAudit.
 */
final class PageAnalyzer {

	public const SLOW_MS        = 1500;
	public const LARGE_BYTES    = 1048576;
	public const MIN_WORDS      = 250;
	public const MAX_LINKS      = 300;
	private const SKIP_EXTENSIONS = 'jpg|jpeg|png|gif|webp|avif|svg|ico|pdf|zip|rar|7z|mp3|mp4|mov|webm|css|js|json|xml|txt|doc|docx|xls|xlsx|ppt|pptx|csv';

	/**
	 * Analyses a response.
	 *
	 * @param string $url    Requested URL.
	 * @param int    $status HTTP status (0 when the request failed).
	 * @param int    $ms     Response time in milliseconds.
	 * @param string $html   Body.
	 * @param string $source What kind of URL this is: home, content, archive or link.
	 * @return array{facts: array<string, mixed>, issues: array<string, mixed>}
	 */
	public function analyze( string $url, int $status, int $ms, string $html, string $source ): array {
		$facts  = [
			'bytes' => strlen( $html ),
			'ms'    => $ms,
		];
		$issues = [];

		if ( 0 === $status ) {
			return [
				'facts'  => $facts,
				'issues' => [ 'fetch_failed' => 1 ],
			];
		}
		if ( $status >= 500 ) {
			$issues['http_5xx'] = $status;
		} elseif ( $status >= 400 ) {
			$issues['http_4xx'] = $status;
		} elseif ( $status >= 300 ) {
			$issues['redirect'] = $status;
		}
		if ( $status >= 300 || '' === trim( $html ) ) {
			return [
				'facts'  => $facts,
				'issues' => $issues,
			];
		}

		if ( $ms > self::SLOW_MS ) {
			$issues['slow_response'] = $ms;
		}
		if ( $facts['bytes'] > self::LARGE_BYTES ) {
			$issues['large_html'] = $facts['bytes'];
		}

		$doc = $this->load( $html );
		if ( null === $doc ) {
			return [
				'facts'  => $facts,
				'issues' => $issues,
			];
		}
		$xpath = new \DOMXPath( $doc );

		// Title.
		$title                = $this->text( $xpath->query( '//head/title' ) );
		$facts['title']       = $title;
		$facts['title_len']   = mb_strlen( $title );
		if ( '' === $title ) {
			$issues['title_missing'] = 1;
		} elseif ( $facts['title_len'] > 60 ) {
			$issues['title_too_long'] = $facts['title_len'];
		} elseif ( $facts['title_len'] < 20 ) {
			$issues['title_too_short'] = $facts['title_len'];
		}

		// Meta description.
		$description          = $this->meta( $xpath, 'name', 'description' );
		$facts['description'] = $description;
		$facts['desc_len']    = mb_strlen( $description );
		if ( '' === $description ) {
			$issues['meta_description_missing'] = 1;
		} elseif ( $facts['desc_len'] < 70 || $facts['desc_len'] > 160 ) {
			$issues['meta_description_length'] = $facts['desc_len'];
		}

		// Headings.
		$h1s               = $xpath->query( '//body//h1' );
		$facts['h1_count'] = $h1s ? $h1s->length : 0;
		$facts['h1']       = $this->text( $h1s );
		$facts['h2_count'] = $this->count( $xpath, '//body//h2' );
		if ( 0 === $facts['h1_count'] ) {
			$issues['h1_missing'] = 1;
		} elseif ( $facts['h1_count'] > 1 ) {
			$issues['multiple_h1'] = $facts['h1_count'];
		}
		if ( '' !== $title && '' !== $facts['h1'] && ! $this->share_words( $title, $facts['h1'] ) ) {
			$issues['title_h1_unrelated'] = 1;
		}

		// Text.
		$facts['words'] = $this->word_count( $xpath );
		if ( 'content' === $source && $facts['words'] < self::MIN_WORDS ) {
			$issues['low_word_count'] = $facts['words'];
		}
		if ( $facts['words'] > 600 && 0 === $facts['h2_count'] ) {
			$issues['no_subheadings'] = $facts['words'];
		}

		// Images.
		$images         = $xpath->query( '//body//img' );
		$missing_alt    = 0;
		$facts['images'] = $images ? $images->length : 0;
		if ( $images ) {
			foreach ( $images as $img ) {
				if ( $img instanceof \DOMElement && ! $img->hasAttribute( 'alt' ) && 'presentation' !== $img->getAttribute( 'role' ) && 'true' !== $img->getAttribute( 'aria-hidden' ) ) {
					++$missing_alt;
				}
			}
		}
		$facts['images_no_alt'] = $missing_alt;
		if ( $missing_alt > 0 ) {
			$issues['images_missing_alt'] = $missing_alt;
		}

		// Head tags.
		$html_el        = $doc->documentElement;
		$facts['lang']  = $html_el instanceof \DOMElement ? $html_el->getAttribute( 'lang' ) : '';
		if ( '' === $facts['lang'] ) {
			$issues['lang_missing'] = 1;
		}
		if ( '' === $this->meta( $xpath, 'name', 'viewport' ) ) {
			$issues['viewport_missing'] = 1;
		}

		$robots          = strtolower( $this->meta( $xpath, 'name', 'robots' ) );
		$facts['noindex'] = str_contains( $robots, 'noindex' );
		if ( $facts['noindex'] ) {
			$issues['noindex'] = 1;
		}

		$canonical          = $this->attr( $xpath->query( '//head/link[translate(@rel,"CANONICAL","canonical")="canonical"]' ), 'href' );
		$facts['canonical'] = $canonical;
		if ( '' === $canonical ) {
			$issues['canonical_missing'] = 1;
		} elseif ( $this->normalize_url( $canonical, $url ) !== $this->normalize_url( $url, $url ) ) {
			$issues['canonical_elsewhere'] = $canonical;
		}

		$facts['og'] = '' !== $this->meta( $xpath, 'property', 'og:title' ) && '' !== $this->meta( $xpath, 'property', 'og:image' );
		if ( ! $facts['og'] ) {
			$issues['open_graph_missing'] = 1;
		}

		$jsonld          = $xpath->query( '//script[@type="application/ld+json"]' );
		$facts['schema'] = $jsonld ? $jsonld->length : 0;
		if ( 0 === $facts['schema'] && 0 === $this->count( $xpath, '//*[@itemtype]' ) ) {
			$issues['no_structured_data'] = 1;
		}

		// Mixed content.
		if ( str_starts_with( strtolower( $url ), 'https://' ) ) {
			$insecure = $xpath->query( '//img[starts-with(@src,"http://")] | //script[starts-with(@src,"http://")] | //link[@rel="stylesheet"][starts-with(@href,"http://")] | //iframe[starts-with(@src,"http://")] | //source[starts-with(@src,"http://")] | //video[starts-with(@src,"http://")] | //audio[starts-with(@src,"http://")]' );
			if ( $insecure && $insecure->length > 0 ) {
				$issues['mixed_content'] = $insecure->length;
			}
		}

		// Links.
		[ $internal, $external ] = $this->links( $xpath, $url );
		$facts['links']          = $internal;
		$facts['external_links'] = $external;
		if ( ! $internal ) {
			$issues['no_internal_links'] = 1;
		}

		return [
			'facts'  => $facts,
			'issues' => $issues,
		];
	}

	/**
	 * Normalises a URL for comparisons: absolute, no fragment, lower-case host, no trailing slash
	 * difference.
	 *
	 * @param string $href Link (absolute or relative).
	 * @param string $base Page URL used to resolve relative links.
	 */
	public function normalize_url( string $href, string $base ): string {
		$href = trim( html_entity_decode( $href, ENT_QUOTES ) );
		$hash = strpos( $href, '#' );
		if ( false !== $hash ) {
			$href = substr( $href, 0, $hash );
		}
		if ( '' === $href ) {
			return '';
		}

		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $href;
		}
		$scheme = $parts['scheme'] ?? 'https';
		$origin = $scheme . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( str_starts_with( $href, '//' ) ) {
			$href = $scheme . ':' . $href;
		} elseif ( str_starts_with( $href, '/' ) ) {
			$href = $origin . $href;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) ) {
			$dir  = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';
			$href = $origin . $dir . $href;
		}

		$u = wp_parse_url( $href );
		if ( ! is_array( $u ) || empty( $u['host'] ) ) {
			return $href;
		}
		$path = $u['path'] ?? '/';
		// Resolve ./ and ../ segments.
		$segments = [];
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $segments );
			} elseif ( '.' !== $segment ) {
				$segments[] = $segment;
			}
		}
		$path = implode( '/', $segments );
		$path = '' === $path ? '/' : $path;
		if ( '/' !== $path && ! str_contains( basename( $path ), '.' ) ) {
			$path = trailingslashit( $path );
		}

		return strtolower( $u['scheme'] ?? $scheme ) . '://' . strtolower( (string) $u['host'] ) . ( isset( $u['port'] ) ? ':' . $u['port'] : '' ) . $path . ( isset( $u['query'] ) && '' !== $u['query'] ? '?' . $u['query'] : '' );
	}

	/**
	 * Whether a URL is on the same host as the site.
	 *
	 * @param string $url URL.
	 */
	public static function is_internal( string $url ): bool {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$test = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && is_string( $test ) && self::bare_host( $host ) === self::bare_host( $test );
	}

	/**
	 * Host without a leading www.
	 *
	 * @param string $host Host.
	 */
	private static function bare_host( string $host ): string {
		$host = strtolower( $host );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Parses HTML into a DOM, tolerating broken markup.
	 *
	 * @param string $html HTML.
	 */
	private function load( string $html ): ?\DOMDocument {
		if ( ! class_exists( '\DOMDocument' ) ) {
			return null;
		}
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$ok       = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $ok ? $doc : null;
	}

	/**
	 * Number of nodes matching an expression.
	 *
	 * @param \DOMXPath $xpath XPath.
	 * @param string    $expr  Expression.
	 */
	private function count( \DOMXPath $xpath, string $expr ): int {
		$nodes = $xpath->query( $expr );

		return $nodes instanceof \DOMNodeList ? $nodes->length : 0;
	}

	/**
	 * Trimmed text of the first node in a list.
	 *
	 * @param \DOMNodeList<\DOMNode>|false|null $nodes Nodes.
	 */
	private function text( mixed $nodes ): string {
		if ( ! $nodes instanceof \DOMNodeList || 0 === $nodes->length ) {
			return '';
		}

		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $nodes->item( 0 )?->textContent ) );
	}

	/**
	 * Attribute of the first element in a list.
	 *
	 * @param \DOMNodeList<\DOMNode>|false|null $nodes Nodes.
	 * @param string                            $name  Attribute.
	 */
	private function attr( mixed $nodes, string $name ): string {
		if ( ! $nodes instanceof \DOMNodeList || 0 === $nodes->length ) {
			return '';
		}
		$node = $nodes->item( 0 );

		return $node instanceof \DOMElement ? trim( $node->getAttribute( $name ) ) : '';
	}

	/**
	 * Content of a <meta> tag, matched case-insensitively.
	 *
	 * @param \DOMXPath $xpath XPath.
	 * @param string    $attr  "name" or "property".
	 * @param string    $value Attribute value.
	 */
	private function meta( \DOMXPath $xpath, string $attr, string $value ): string {
		$lower = 'translate(@' . $attr . ',"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")';

		return $this->attr( $xpath->query( '//meta[' . $lower . '="' . strtolower( $value ) . '"]' ), 'content' );
	}

	/**
	 * Words of visible body text.
	 *
	 * @param \DOMXPath $xpath XPath.
	 */
	private function word_count( \DOMXPath $xpath ): int {
		$text  = '';
		$nodes = $xpath->query( '//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::template) and not(ancestor::svg)]' );
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				$text .= ' ' . $node->textContent;
			}
		}

		return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text );
	}

	/**
	 * Whether two strings share a meaningful word (3+ letters).
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 */
	private function share_words( string $a, string $b ): bool {
		$words = static function ( string $s ): array {
			preg_match_all( '/[\p{L}\p{N}]{3,}/u', mb_strtolower( $s ), $m );
			return array_unique( $m[0] );
		};

		return [] !== array_intersect( $words( $a ), $words( $b ) );
	}

	/**
	 * Unique internal page links and the number of external links.
	 *
	 * @param \DOMXPath $xpath XPath.
	 * @param string    $url   Page URL.
	 * @return array{0: list<string>, 1: int}
	 */
	private function links( \DOMXPath $xpath, string $url ): array {
		$internal = [];
		$external = 0;
		$anchors  = $xpath->query( '//body//a[@href]' );
		if ( ! $anchors ) {
			return [ [], 0 ];
		}

		foreach ( $anchors as $a ) {
			if ( ! $a instanceof \DOMElement ) {
				continue;
			}
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || str_starts_with( $href, '#' ) || preg_match( '#^(mailto|tel|sms|javascript|data|whatsapp|skype):#i', $href ) ) {
				continue;
			}
			$abs = $this->normalize_url( $href, $url );
			if ( ! preg_match( '#^https?://#i', $abs ) ) {
				continue;
			}
			if ( ! self::is_internal( $abs ) ) {
				++$external;
				continue;
			}
			$path = (string) wp_parse_url( $abs, PHP_URL_PATH );
			if ( preg_match( '#/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php|feed)(/|$)#', $path ) || preg_match( '#\.(' . self::SKIP_EXTENSIONS . ')$#i', $path ) ) {
				continue;
			}
			if ( $abs === $this->normalize_url( $url, $url ) ) {
				continue;
			}
			$internal[ $abs ] = true;
			if ( count( $internal ) >= self::MAX_LINKS ) {
				break;
			}
		}

		return [ array_keys( $internal ), $external ];
	}
}
