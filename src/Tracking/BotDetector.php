<?php
/**
 * Bot and crawler identification.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Filters bots out of analytics and names known crawlers for the SEO crawl report.
 *
 * Crawlers are identified by user agent only (not reverse DNS), so spoofed agents are counted as the
 * crawler they claim to be.
 */
final class BotDetector {

	public const TYPE_SEARCH = 'search';
	public const TYPE_AI     = 'ai';
	public const TYPE_SEO    = 'seo';
	public const TYPE_SOCIAL = 'social';

	/**
	 * Known crawlers: name => [case-insensitive UA token, type]. First match wins.
	 */
	private const CRAWLERS = [
		// AI crawlers and assistants.
		'GPTBot'             => [ 'GPTBot', self::TYPE_AI ],
		'ChatGPT-User'       => [ 'ChatGPT-User', self::TYPE_AI ],
		'OAI-SearchBot'      => [ 'OAI-SearchBot', self::TYPE_AI ],
		'ClaudeBot'          => [ 'ClaudeBot', self::TYPE_AI ],
		'Claude-User'        => [ 'Claude-User', self::TYPE_AI ],
		'Claude-SearchBot'   => [ 'Claude-SearchBot', self::TYPE_AI ],
		'anthropic-ai'       => [ 'anthropic-ai', self::TYPE_AI ],
		'PerplexityBot'      => [ 'PerplexityBot', self::TYPE_AI ],
		'Perplexity-User'    => [ 'Perplexity-User', self::TYPE_AI ],
		'Google-CloudVertex' => [ 'Google-CloudVertexBot', self::TYPE_AI ],
		'CCBot'              => [ 'CCBot', self::TYPE_AI ],
		'Bytespider'         => [ 'Bytespider', self::TYPE_AI ],
		'Amazonbot'          => [ 'Amazonbot', self::TYPE_AI ],
		'Meta-ExternalAgent' => [ 'meta-externalagent', self::TYPE_AI ],
		'MistralAI-User'     => [ 'MistralAI-User', self::TYPE_AI ],
		'cohere-ai'          => [ 'cohere-ai', self::TYPE_AI ],
		'YouBot'             => [ 'YouBot', self::TYPE_AI ],
		'Diffbot'            => [ 'Diffbot', self::TYPE_AI ],
		// Search engines.
		'Googlebot'          => [ 'Googlebot', self::TYPE_SEARCH ],
		'Google-Inspection'  => [ 'Google-InspectionTool', self::TYPE_SEARCH ],
		'Bingbot'            => [ 'bingbot', self::TYPE_SEARCH ],
		'YandexBot'          => [ 'YandexBot', self::TYPE_SEARCH ],
		'Baiduspider'        => [ 'Baiduspider', self::TYPE_SEARCH ],
		'DuckDuckBot'        => [ 'DuckDuckBot', self::TYPE_SEARCH ],
		'Applebot'           => [ 'Applebot', self::TYPE_SEARCH ],
		'PetalBot'           => [ 'PetalBot', self::TYPE_SEARCH ],
		'SeznamBot'          => [ 'SeznamBot', self::TYPE_SEARCH ],
		'Sogou'              => [ 'Sogou', self::TYPE_SEARCH ],
		'Naver Yeti'         => [ 'Yeti/', self::TYPE_SEARCH ],
		// SEO tools.
		'AhrefsBot'          => [ 'AhrefsBot', self::TYPE_SEO ],
		'SemrushBot'         => [ 'SemrushBot', self::TYPE_SEO ],
		'MJ12bot'            => [ 'MJ12bot', self::TYPE_SEO ],
		'DotBot'             => [ 'DotBot', self::TYPE_SEO ],
		'DataForSeoBot'      => [ 'DataForSeoBot', self::TYPE_SEO ],
		'Screaming Frog'     => [ 'Screaming Frog', self::TYPE_SEO ],
		'rogerbot'           => [ 'rogerbot', self::TYPE_SEO ],
		// Link previews.
		'Facebook'           => [ 'facebookexternalhit', self::TYPE_SOCIAL ],
		'Twitterbot'         => [ 'Twitterbot', self::TYPE_SOCIAL ],
		'LinkedInBot'        => [ 'LinkedInBot', self::TYPE_SOCIAL ],
		'Slackbot'           => [ 'Slackbot', self::TYPE_SOCIAL ],
		'WhatsApp'           => [ 'WhatsApp/', self::TYPE_SOCIAL ],
		'TelegramBot'        => [ 'TelegramBot', self::TYPE_SOCIAL ],
		'Discordbot'         => [ 'Discordbot', self::TYPE_SOCIAL ],
		'Pinterestbot'       => [ 'Pinterestbot', self::TYPE_SOCIAL ],
	];

	/**
	 * Generic automation markers.
	 */
	private const GENERIC = '/\bbot\b|bots?\/|bot;|crawl|spider|slurp|mediapartners|headless|phantomjs|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|curl\/|wget\/|python-requests|python-urllib|aiohttp|httpclient|okhttp|go-http-client|java\/|libwww|scrapy|axios\/|node-fetch|feedfetcher|validator|preview/i';

	/**
	 * Whether the user agent belongs to a bot or automation tool.
	 *
	 * @param string $ua User agent.
	 */
	public function is_bot( string $ua ): bool {
		if ( strlen( $ua ) < 12 ) {
			return true;
		}

		$is_bot = null !== $this->crawler( $ua ) || 1 === preg_match( self::GENERIC, $ua );

		/**
		 * Filters bot detection.
		 *
		 * @param bool   $is_bot Whether the request is from a bot.
		 * @param string $ua     User agent.
		 */
		return (bool) apply_filters( 'blue_lens_is_bot', $is_bot, $ua );
	}

	/**
	 * Identifies a known crawler.
	 *
	 * @param string $ua User agent.
	 * @return array{name: string, type: string}|null
	 */
	public function crawler( string $ua ): ?array {
		foreach ( self::CRAWLERS as $name => [ $token, $type ] ) {
			if ( false !== stripos( $ua, $token ) ) {
				return [
					'name' => $name,
					'type' => $type,
				];
			}
		}

		return null;
	}
}
