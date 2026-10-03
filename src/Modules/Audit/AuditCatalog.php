<?php
/**
 * Every check the site audit can report.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Modules\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Issue definitions: severity, On-Page SEO idea category, plain-language title and how to fix it.
 *
 * Severities: error (hurts visibility or visitors now), warning (worth fixing soon), notice
 * (an improvement idea). Categories group the same issues as "ideas" on the On-Page SEO card.
 *
 * @phpstan-type Issue array{severity: string, category: string, scope: string, title: string, fix: string}
 */
final class AuditCatalog {

	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const NOTICE  = 'notice';

	public const CATEGORIES = [ 'strategy', 'technical', 'content', 'ux', 'semantic', 'serp' ];

	/**
	 * All definitions keyed by issue code.
	 *
	 * @return array<string, Issue>
	 */
	public static function all(): array {
		static $issues = null;
		if ( null !== $issues ) {
			return $issues;
		}

		$page = static fn( string $severity, string $category, string $title, string $fix ): array => [
			'severity' => $severity,
			'category' => $category,
			'scope'    => 'page',
			'title'    => $title,
			'fix'      => $fix,
		];
		$site = static fn( string $severity, string $category, string $title, string $fix ): array => [
			'severity' => $severity,
			'category' => $category,
			'scope'    => 'site',
			'title'    => $title,
			'fix'      => $fix,
		];

		$issues = [
			// Page errors.
			'http_4xx'                => $page( self::ERROR, 'technical', __( 'Page returns a 4xx error', 'blue-lens-analytics' ), __( 'Restore the page, or redirect it to the closest live page and update links pointing to it.', 'blue-lens-analytics' ) ),
			'http_5xx'                => $page( self::ERROR, 'technical', __( 'Page returns a 5xx server error', 'blue-lens-analytics' ), __( 'Check the PHP error log for this URL; a plugin or theme is failing while building the page.', 'blue-lens-analytics' ) ),
			'fetch_failed'            => $page( self::ERROR, 'technical', __( 'Page could not be fetched', 'blue-lens-analytics' ), __( 'The request timed out or the connection failed. Check that the page loads and that the server is not blocking its own requests.', 'blue-lens-analytics' ) ),
			'title_missing'           => $page( self::ERROR, 'technical', __( 'Missing title tag', 'blue-lens-analytics' ), __( 'Give the page a title. In WordPress the theme must declare title-tag support, or an SEO plugin must output one.', 'blue-lens-analytics' ) ),
			'title_duplicate'         => $page( self::ERROR, 'technical', __( 'Duplicate title tag', 'blue-lens-analytics' ), __( 'Several pages share this title. Write a unique title that describes each page.', 'blue-lens-analytics' ) ),
			'broken_internal_links'   => $page( self::ERROR, 'technical', __( 'Broken internal links', 'blue-lens-analytics' ), __( 'Links on this page lead to pages on your site that return an error. Fix or remove them.', 'blue-lens-analytics' ) ),
			'mixed_content'           => $page( self::ERROR, 'ux', __( 'Insecure (HTTP) resources on an HTTPS page', 'blue-lens-analytics' ), __( 'Load images, scripts and styles over https://. Browsers block or flag mixed content.', 'blue-lens-analytics' ) ),

			// Page warnings.
			'meta_description_missing' => $page( self::WARNING, 'serp', __( 'Missing meta description', 'blue-lens-analytics' ), __( 'Write a 70–160 character summary. Search engines often show it under your title in results.', 'blue-lens-analytics' ) ),
			'meta_description_duplicate' => $page( self::WARNING, 'serp', __( 'Duplicate meta description', 'blue-lens-analytics' ), __( 'Several pages share this description. Make each one specific to its page.', 'blue-lens-analytics' ) ),
			'title_too_long'          => $page( self::WARNING, 'serp', __( 'Title is too long', 'blue-lens-analytics' ), __( 'Keep titles under about 60 characters so they are not cut off in search results.', 'blue-lens-analytics' ) ),
			'title_too_short'         => $page( self::WARNING, 'content', __( 'Title is too short', 'blue-lens-analytics' ), __( 'Use at least 20 characters that say what the page offers and who it is for.', 'blue-lens-analytics' ) ),
			'h1_missing'              => $page( self::WARNING, 'content', __( 'Missing H1 heading', 'blue-lens-analytics' ), __( 'Add one main heading (H1) that states the topic of the page.', 'blue-lens-analytics' ) ),
			'low_word_count'          => $page( self::WARNING, 'content', __( 'Low word count', 'blue-lens-analytics' ), __( 'The page has fewer than 250 words. Add useful detail visitors are looking for.', 'blue-lens-analytics' ) ),
			'images_missing_alt'      => $page( self::WARNING, 'semantic', __( 'Images without alt text', 'blue-lens-analytics' ), __( 'Describe each meaningful image in its alt text. It helps screen readers and image search.', 'blue-lens-analytics' ) ),
			'slow_response'           => $page( self::WARNING, 'ux', __( 'Slow server response', 'blue-lens-analytics' ), __( 'The server took over 1.5 seconds to send this page. Use page caching and check slow plugins.', 'blue-lens-analytics' ) ),
			'large_html'              => $page( self::WARNING, 'ux', __( 'Very large HTML', 'blue-lens-analytics' ), __( 'The HTML is over 1 MB. Large inline scripts, styles or page-builder markup slow the page down.', 'blue-lens-analytics' ) ),
			'viewport_missing'        => $page( self::WARNING, 'ux', __( 'No mobile viewport tag', 'blue-lens-analytics' ), __( 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> so the page fits phone screens.', 'blue-lens-analytics' ) ),
			'lang_missing'            => $page( self::WARNING, 'semantic', __( 'Missing language attribute', 'blue-lens-analytics' ), __( 'The <html> element should declare the page language, e.g. lang="en". Themes do this with language_attributes().', 'blue-lens-analytics' ) ),
			'orphan_page'             => $page( self::WARNING, 'strategy', __( 'Orphan page (no internal links to it)', 'blue-lens-analytics' ), __( 'No other crawled page links here, so visitors and search engines struggle to find it. Link to it from related pages or menus.', 'blue-lens-analytics' ) ),

			// Page notices (ideas).
			'redirect'                => $page( self::NOTICE, 'technical', __( 'Page redirects', 'blue-lens-analytics' ), __( 'Link straight to the final URL to save visitors a hop.', 'blue-lens-analytics' ) ),
			'noindex'                 => $page( self::NOTICE, 'technical', __( 'Page is set to noindex', 'blue-lens-analytics' ), __( 'Search engines are asked not to list this page. Make sure that is intended.', 'blue-lens-analytics' ) ),
			'canonical_missing'       => $page( self::NOTICE, 'technical', __( 'No canonical URL', 'blue-lens-analytics' ), __( 'Declare the preferred URL with <link rel="canonical"> to avoid duplicate-content confusion.', 'blue-lens-analytics' ) ),
			'canonical_elsewhere'     => $page( self::NOTICE, 'technical', __( 'Canonical points to another URL', 'blue-lens-analytics' ), __( 'This page tells search engines another URL is the main version. Check that is correct.', 'blue-lens-analytics' ) ),
			'multiple_h1'             => $page( self::NOTICE, 'content', __( 'More than one H1 heading', 'blue-lens-analytics' ), __( 'Use a single H1 for the page topic and H2–H3 for sections.', 'blue-lens-analytics' ) ),
			'no_subheadings'          => $page( self::NOTICE, 'content', __( 'Long text without subheadings', 'blue-lens-analytics' ), __( 'Break long text into sections with H2 headings so it is easy to scan.', 'blue-lens-analytics' ) ),
			'meta_description_length' => $page( self::NOTICE, 'serp', __( 'Meta description too long or short', 'blue-lens-analytics' ), __( 'Aim for 70–160 characters so the summary is complete in search results.', 'blue-lens-analytics' ) ),
			'no_structured_data'      => $page( self::NOTICE, 'serp', __( 'No structured data', 'blue-lens-analytics' ), __( 'Add schema.org JSON-LD (e.g. Organization, Article, Product, FAQ) to qualify for rich results.', 'blue-lens-analytics' ) ),
			'open_graph_missing'      => $page( self::NOTICE, 'serp', __( 'No social sharing tags', 'blue-lens-analytics' ), __( 'Add og:title and og:image so links shared on WhatsApp, Facebook and LinkedIn show a proper preview.', 'blue-lens-analytics' ) ),
			'title_h1_unrelated'      => $page( self::NOTICE, 'semantic', __( 'Title and H1 share no words', 'blue-lens-analytics' ), __( 'Use the same main topic words in the title and the H1 so the page focus is clear.', 'blue-lens-analytics' ) ),
			'no_internal_links'       => $page( self::NOTICE, 'strategy', __( 'No links to other pages on the site', 'blue-lens-analytics' ), __( 'Link to related pages so visitors keep exploring and search engines discover more content.', 'blue-lens-analytics' ) ),
			'no_recent_visits'        => $page( self::NOTICE, 'strategy', __( 'No visits in the last 28 days', 'blue-lens-analytics' ), __( 'Blue Lens recorded no page views. Improve, promote, merge or retire this page.', 'blue-lens-analytics' ) ),

			// Site-wide checks.
			'search_engines_blocked'  => $site( self::ERROR, 'technical', __( 'Search engines are discouraged', 'blue-lens-analytics' ), __( 'Settings → Reading → "Discourage search engines from indexing this site" is ticked. Untick it when the site is live.', 'blue-lens-analytics' ) ),
			'robots_blocks_all'       => $site( self::ERROR, 'technical', __( 'robots.txt blocks the whole site', 'blue-lens-analytics' ), __( 'robots.txt contains "Disallow: /" for all crawlers. Remove it unless the site should stay hidden.', 'blue-lens-analytics' ) ),
			'no_https'                => $site( self::WARNING, 'ux', __( 'Site does not use HTTPS', 'blue-lens-analytics' ), __( 'Install an SSL certificate and change the WordPress and site address to https://.', 'blue-lens-analytics' ) ),
			'sitemap_missing'         => $site( self::WARNING, 'technical', __( 'No XML sitemap found', 'blue-lens-analytics' ), __( 'Enable the WordPress sitemap (wp-sitemap.xml) or your SEO plugin’s sitemap, and submit it to search engines.', 'blue-lens-analytics' ) ),
			'plain_permalinks'        => $site( self::WARNING, 'technical', __( 'Plain permalinks (?p=123)', 'blue-lens-analytics' ), __( 'Choose "Post name" under Settings → Permalinks for readable, keyword-rich URLs.', 'blue-lens-analytics' ) ),
			'soft_404'                => $site( self::WARNING, 'technical', __( 'Missing pages do not return 404', 'blue-lens-analytics' ), __( 'A made-up URL returned a normal page. Missing pages must send a 404 status so search engines drop them.', 'blue-lens-analytics' ) ),
			'debug_display'           => $site( self::WARNING, 'ux', __( 'PHP errors are shown to visitors', 'blue-lens-analytics' ), __( 'WP_DEBUG_DISPLAY is on. Turn it off on live sites and log errors to a file instead.', 'blue-lens-analytics' ) ),
			'robots_missing'          => $site( self::NOTICE, 'technical', __( 'No robots.txt', 'blue-lens-analytics' ), __( 'A robots.txt file can point crawlers to your sitemap.', 'blue-lens-analytics' ) ),
		];

		/**
		 * Filters the audit issue catalog (titles, fixes and severities).
		 *
		 * @param array<string, array<string, string>> $issues Issue definitions keyed by code.
		 */
		$issues = (array) apply_filters( 'blue_lens_audit_issues', $issues );

		return $issues;
	}

	/**
	 * One definition, or null.
	 *
	 * @param string $code Issue code.
	 * @return Issue|null
	 */
	public static function get( string $code ): ?array {
		return self::all()[ $code ] ?? null;
	}

	/**
	 * Severity of an issue code (unknown codes count as notices).
	 *
	 * @param string $code Issue code.
	 */
	public static function severity( string $code ): string {
		return self::all()[ $code ]['severity'] ?? self::NOTICE;
	}

	/**
	 * Counts issues by severity.
	 *
	 * @param array<string, mixed> $issues Issue code => detail.
	 * @return array{error: int, warning: int, notice: int}
	 */
	public static function count( array $issues ): array {
		$out = [
			self::ERROR   => 0,
			self::WARNING => 0,
			self::NOTICE  => 0,
		];
		foreach ( array_keys( $issues ) as $code ) {
			$severity = self::severity( (string) $code );
			++$out[ isset( $out[ $severity ] ) ? $severity : self::NOTICE ];
		}

		return $out;
	}
}
