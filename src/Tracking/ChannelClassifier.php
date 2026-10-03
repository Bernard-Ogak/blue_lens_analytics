<?php
/**
 * Channel grouping.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

use BlueLens\Analytics\Core\ConfigStore;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns each session one channel from UTM parameters, click IDs and the referrer.
 *
 * Rules are data: defaults below, overridable per site through the "channel_rules" config key
 * (bla_config) and the blue_lens_channel_rules filter.
 *
 * Domain entries ending in "." match any TLD (e.g. "google." matches google.co.ke); other entries
 * match the domain or any of its subdomains.
 */
final class ChannelClassifier {

	public const DIRECT         = 'direct';
	public const ORGANIC_SEARCH = 'organic_search';
	public const PAID_SEARCH    = 'paid_search';
	public const PAID_SOCIAL    = 'paid_social';
	public const SOCIAL         = 'social';
	public const EMAIL          = 'email';
	public const REFERRAL       = 'referral';
	public const DISPLAY        = 'display';
	public const AFFILIATE      = 'affiliate';
	public const AI_ASSISTANT   = 'ai_assistant';
	public const OTA_LISTING    = 'ota_listing';

	/**
	 * Resolved rules for this request.
	 *
	 * @var array<string, list<string>>|null
	 */
	private ?array $rules = null;

	/**
	 * Constructor.
	 *
	 * @param ConfigStore $config Versioned config store.
	 */
	public function __construct( private ConfigStore $config ) {}

	/**
	 * Default rules.
	 *
	 * @return array<string, list<string>>
	 */
	public static function default_rules(): array {
		return [
			'ai_assistants'      => [ 'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'bard.google.com', 'copilot.microsoft.com', 'you.com', 'phind.com', 'poe.com', 'meta.ai', 'chat.mistral.ai', 'chat.deepseek.com', 'grok.com' ],
			'ota_listing'        => [ 'booking.com', 'expedia.', 'hotels.com', 'agoda.com', 'tripadvisor.', 'airbnb.', 'viator.com', 'getyourguide.', 'safaribookings.com', 'tourradar.com', 'trivago.', 'kayak.', 'skyscanner.', 'trip.com', 'hostelworld.com', 'klook.com', 'lonelyplanet.com' ],
			'social'             => [ 'facebook.com', 'fb.com', 'fb.me', 'instagram.com', 't.co', 'twitter.com', 'x.com', 'linkedin.com', 'lnkd.in', 'pinterest.', 'youtube.com', 'youtu.be', 'tiktok.com', 'reddit.com', 'threads.net', 'snapchat.com', 'whatsapp.com', 'wa.me', 't.me', 'vk.com', 'quora.com', 'tumblr.com', 'bsky.app', 'mastodon.social' ],
			'search_engines'     => [ 'google.', 'bing.com', 'yahoo.', 'duckduckgo.com', 'yandex.', 'baidu.com', 'ecosia.org', 'search.brave.com', 'qwant.com', 'naver.com', 'seznam.cz', 'startpage.com', 'ask.com', 'aol.com' ],
			'email_domains'      => [ 'mail.google.com', 'outlook.live.com', 'outlook.office.com', 'outlook.office365.com', 'mail.yahoo.com', 'mail.aol.com', 'mail.proton.me' ],
			'social_sources'     => [ 'facebook', 'fb', 'instagram', 'ig', 'meta', 'twitter', 'x', 'linkedin', 'pinterest', 'youtube', 'tiktok', 'reddit', 'snapchat', 'whatsapp', 'threads' ],
			'medium_paid_search' => [ 'cpc', 'ppc', 'paidsearch', 'paid_search', 'paid-search', 'sem' ],
			'medium_paid_social' => [ 'paid_social', 'paidsocial', 'paid-social', 'social_paid', 'social-paid', 'paid-social-media' ],
			'medium_display'     => [ 'display', 'banner', 'cpm', 'expandable', 'interstitial' ],
			'medium_email'       => [ 'email', 'e-mail', 'e_mail', 'newsletter' ],
			'medium_affiliate'   => [ 'affiliate', 'affiliates', 'partner' ],
			'medium_social'      => [ 'social', 'social-network', 'social-media', 'social_media', 'sm' ],
			'click_paid_search'  => [ 'gclid', 'gbraid', 'wbraid', 'msclkid' ],
			'click_paid_social'  => [ 'ttclid' ],
		];
	}

	/**
	 * Effective rules.
	 *
	 * @return array<string, list<string>>
	 */
	public function rules(): array {
		if ( null !== $this->rules ) {
			return $this->rules;
		}

		$rules    = self::default_rules();
		$override = $this->config->get_active( 'channel_rules' );
		if ( is_array( $override ) ) {
			foreach ( $override as $key => $values ) {
				if ( isset( $rules[ $key ] ) && is_array( $values ) ) {
					$rules[ $key ] = array_values( array_map( 'strval', $values ) );
				}
			}
		}

		/**
		 * Filters channel grouping rules.
		 *
		 * @param array<string, list<string>> $rules Rules.
		 */
		$this->rules = (array) apply_filters( 'blue_lens_channel_rules', $rules );

		return $this->rules;
	}

	/**
	 * Classifies a session's source.
	 *
	 * @param string                $referrer_domain External referrer domain ('' when none or internal).
	 * @param array<string, string> $utm             UTM parameters.
	 * @param string                $click_type      Click ID parameter name, '' when none.
	 */
	public function classify( string $referrer_domain, array $utm, string $click_type ): string {
		$rules  = $this->rules();
		$medium = strtolower( trim( $utm['utm_medium'] ?? '' ) );
		$source = strtolower( trim( $utm['utm_source'] ?? '' ) );

		$channel = $this->by_medium( $medium, $source, $rules )
			?? $this->by_click_id( $click_type, $rules )
			?? $this->by_referrer( $referrer_domain, $rules );

		if ( null === $channel ) {
			if ( '' !== $source ) {
				$channel = in_array( $source, $rules['social_sources'], true ) ? self::SOCIAL : self::REFERRAL;
			} else {
				$channel = self::DIRECT;
			}
		}

		/**
		 * Filters the channel assigned to a session.
		 *
		 * @param string                $channel         Channel slug.
		 * @param string                $referrer_domain Referrer domain.
		 * @param array<string, string> $utm             UTM parameters.
		 * @param string                $click_type      Click ID parameter name.
		 */
		return (string) apply_filters( 'blue_lens_channel', $channel, $referrer_domain, $utm, $click_type );
	}

	/**
	 * Channel from utm_medium.
	 *
	 * @param string                      $medium Lowercased medium.
	 * @param string                      $source Lowercased source.
	 * @param array<string, list<string>> $rules  Rules.
	 */
	private function by_medium( string $medium, string $source, array $rules ): ?string {
		if ( '' === $medium ) {
			return null;
		}
		if ( in_array( $medium, $rules['medium_paid_search'], true ) ) {
			return in_array( $source, $rules['social_sources'], true ) ? self::PAID_SOCIAL : self::PAID_SEARCH;
		}

		$map = [
			'medium_paid_social' => self::PAID_SOCIAL,
			'medium_display'     => self::DISPLAY,
			'medium_email'       => self::EMAIL,
			'medium_affiliate'   => self::AFFILIATE,
			'medium_social'      => self::SOCIAL,
		];
		foreach ( $map as $rule => $channel ) {
			if ( in_array( $medium, $rules[ $rule ], true ) ) {
				return $channel;
			}
		}

		return null;
	}

	/**
	 * Channel from an ad click ID.
	 *
	 * @param string                      $click_type Click ID name.
	 * @param array<string, list<string>> $rules      Rules.
	 */
	private function by_click_id( string $click_type, array $rules ): ?string {
		if ( in_array( $click_type, $rules['click_paid_search'], true ) ) {
			return self::PAID_SEARCH;
		}
		if ( in_array( $click_type, $rules['click_paid_social'], true ) ) {
			return self::PAID_SOCIAL;
		}

		return null;
	}

	/**
	 * Channel from the referrer domain.
	 *
	 * @param string                      $domain Domain without www.
	 * @param array<string, list<string>> $rules  Rules.
	 */
	private function by_referrer( string $domain, array $rules ): ?string {
		if ( '' === $domain ) {
			return null;
		}

		// AI before search: gemini.google.com must not count as Google search.
		$map = [
			'ai_assistants'  => self::AI_ASSISTANT,
			'email_domains'  => self::EMAIL,
			'ota_listing'    => self::OTA_LISTING,
			'social'         => self::SOCIAL,
			'search_engines' => self::ORGANIC_SEARCH,
		];
		foreach ( $map as $rule => $channel ) {
			foreach ( $rules[ $rule ] as $entry ) {
				if ( self::domain_matches( $domain, $entry ) ) {
					return $channel;
				}
			}
		}

		return self::REFERRAL;
	}

	/**
	 * Domain rule matching.
	 *
	 * @param string $domain Domain.
	 * @param string $entry  Rule entry.
	 */
	public static function domain_matches( string $domain, string $entry ): bool {
		$entry = strtolower( $entry );

		if ( str_ends_with( $entry, '.' ) ) {
			return str_starts_with( $domain, $entry ) || str_contains( $domain, '.' . $entry );
		}

		return $domain === $entry || str_ends_with( $domain, '.' . $entry );
	}
}
