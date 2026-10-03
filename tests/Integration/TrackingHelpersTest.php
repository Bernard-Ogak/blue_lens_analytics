<?php
/**
 * Tests for stateless tracking helpers: UA parsing, bots, IPs, URLs, PII, channels.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Core\Plugin;
use BlueLens\Analytics\Privacy\PiiScrubber;
use BlueLens\Analytics\Tracking\BotDetector;
use BlueLens\Analytics\Tracking\ChannelClassifier;
use BlueLens\Analytics\Tracking\ClientIp;
use BlueLens\Analytics\Tracking\UrlSanitizer;
use BlueLens\Analytics\Tracking\UserAgentParser;
use WP_UnitTestCase;

/**
 * @covers \BlueLens\Analytics\Tracking\UserAgentParser
 * @covers \BlueLens\Analytics\Tracking\BotDetector
 * @covers \BlueLens\Analytics\Tracking\ClientIp
 * @covers \BlueLens\Analytics\Tracking\UrlSanitizer
 * @covers \BlueLens\Analytics\Privacy\PiiScrubber
 * @covers \BlueLens\Analytics\Tracking\ChannelClassifier
 */
final class TrackingHelpersTest extends WP_UnitTestCase {

	/**
	 * @dataProvider user_agents
	 */
	public function test_user_agent_parsing( string $ua, string $browser, string $os, string $device ): void {
		$info = ( new UserAgentParser() )->parse( $ua );

		$this->assertSame( $browser, $info->browser );
		$this->assertSame( $os, $info->os );
		$this->assertSame( $device, $info->device_type );
	}

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function user_agents(): array {
		return [
			'chrome windows'  => [ 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome', 'Windows', 'desktop' ],
			'edge windows'    => [ 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.2792.52', 'Edge', 'Windows', 'desktop' ],
			'safari iphone'   => [ 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'mobile' ],
			'samsung android' => [ 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'Samsung Internet', 'Android', 'mobile' ],
			'android tablet'  => [ 'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'Chrome', 'Android', 'tablet' ],
			'firefox mac'     => [ 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox', 'macOS', 'desktop' ],
			'facebook in-app' => [ 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/476.0.0.39.106]', 'Facebook App', 'iOS', 'mobile' ],
		];
	}

	public function test_touch_mac_is_ipad(): void {
		$info = ( new UserAgentParser() )->parse( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15', '', true );

		$this->assertSame( 'iPadOS', $info->os );
		$this->assertSame( 'tablet', $info->device_type );
	}

	public function test_bots_and_crawlers(): void {
		$bots = new BotDetector();

		$this->assertTrue( $bots->is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ) );
		$this->assertTrue( $bots->is_bot( 'curl/8.4.0' ) );
		$this->assertTrue( $bots->is_bot( '' ) );
		$this->assertTrue( $bots->is_bot( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36' ) );
		$this->assertFalse( $bots->is_bot( 'Mozilla/5.0 (Linux; Android 12; CUBOT X50) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36' ) );

		$this->assertSame( [ 'name' => 'GPTBot', 'type' => 'ai' ], $bots->crawler( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)' ) );
		$this->assertSame( 'search', $bots->crawler( 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' )['type'] );
		$this->assertNull( $bots->crawler( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129.0.0.0 Safari/537.36' ) );
	}

	public function test_ip_resolution_truncation_and_matching(): void {
		$server = [
			'REMOTE_ADDR'          => '172.70.1.1',
			'HTTP_X_FORWARDED_FOR' => 'not-an-ip, 41.90.12.34, 172.70.1.1',
		];

		$this->assertSame( '172.70.1.1', ClientIp::resolve( 'remote_addr', $server ) );
		$this->assertSame( '41.90.12.34', ClientIp::resolve( 'http_x_forwarded_for', $server ) );
		$this->assertSame( '41.90.12.0', ClientIp::truncate( '41.90.12.34' ) );
		$this->assertSame( '2001:db8:85a3::', ClientIp::truncate( '2001:db8:85a3:8d3:1319:8a2e:370:7348' ) );

		$this->assertTrue( ClientIp::matches( '10.1.2.3', [ '10.0.0.0/8' ] ) );
		$this->assertTrue( ClientIp::matches( '192.168.1.77', [ '192.168.1.77' ] ) );
		$this->assertTrue( ClientIp::matches( '2001:db8::1', [ '2001:db8::/32' ] ) );
		$this->assertFalse( ClientIp::matches( '11.0.0.1', [ '10.0.0.0/8', '2001:db8::/32' ] ) );
		$this->assertTrue( ClientIp::matches( '10.0.0.200', [ '10.0.0.128/25' ] ) );
		$this->assertFalse( ClientIp::matches( '10.0.0.100', [ '10.0.0.128/25' ] ) );
	}

	public function test_pii_scrubbing(): void {
		$scrubber = new PiiScrubber();

		$this->assertSame( '/thank-you/[email]/', $scrubber->scrub( '/thank-you/jane.doe@example.com/' ) );
		$this->assertSame( 'call [phone] now', $scrubber->scrub( 'call +254 712 345 678 now' ) );
		$this->assertSame( '/reset/[token]', $scrubber->scrub( '/reset/a8f3k2b9c7d1e5f4a6b8c0d2e4f6' ) );
		$this->assertSame( '/tours/10-day-kenya-safari-and-zanzibar-beach-extension/', $scrubber->scrub( '/tours/10-day-kenya-safari-and-zanzibar-beach-extension/' ) );
		$this->assertSame( '2026-12-20', $scrubber->scrub( '2026-12-20' ) );
		$this->assertTrue( $scrubber->contains_pii( 'guest: bob@example.org' ) );
		$this->assertFalse( $scrubber->contains_pii( 'USD 1,200.00' ) );
	}

	public function test_url_sanitizer(): void {
		$urls = Plugin::instance()->container()->get( UrlSanitizer::class );

		$page = $urls->parse( 'https://Example.org/rooms/tented-suite/?utm_source=Newsletter&utm_medium=email&utm_content=jane@example.com&gclid=Cj0KCQ_abc-123&email=x@y.z' );
		$this->assertSame( 'example.org', $page['host'] );
		$this->assertSame( '/rooms/tented-suite/', $page['path'] );

		$utm = $urls->campaign( $page['query'] );
		$this->assertSame( 'Newsletter', $utm['utm_source'] );
		$this->assertSame( '[email]', $utm['utm_content'] );
		$this->assertSame( [ 'type' => 'gclid', 'value' => 'Cj0KCQ_abc-123' ], $urls->click_id( $page['query'] ) );

		$ref = $urls->referrer( 'https://www.google.co.ke/search?q=safari+kenya' );
		$this->assertSame( [ 'domain' => 'google.co.ke', 'url' => 'https://www.google.co.ke/search' ], $ref );
		$this->assertSame( '', $urls->referrer( 'javascript:alert(1)' )['domain'] );

		// Plain permalinks keep the content-identifying query parameters; everything else is dropped.
		$this->assertSame( '/?page_id=4', $urls->parse( 'https://example.org/?page_id=4&email=a@b.co&s=secret' )['path'] );

		$this->assertTrue( UrlSanitizer::path_matches( '/checkout/pay/', [ '/checkout/*' ] ) );
		$this->assertFalse( UrlSanitizer::path_matches( '/tours/checkout/', [ '/checkout/*' ] ) );
	}

	/**
	 * @dataProvider channels
	 *
	 * @param array<string, string> $utm UTM parameters.
	 */
	public function test_channel_grouping( string $referrer, array $utm, string $click, string $expected ): void {
		$classifier = Plugin::instance()->container()->get( ChannelClassifier::class );
		$utm       += [
			'utm_source'   => '',
			'utm_medium'   => '',
			'utm_campaign' => '',
		];

		$this->assertSame( $expected, $classifier->classify( $referrer, $utm, $click ) );
	}

	/**
	 * @return array<string, array{string, array<string, string>, string, string}>
	 */
	public static function channels(): array {
		return [
			'direct'           => [ '', [], '', ChannelClassifier::DIRECT ],
			'organic google'   => [ 'google.co.ke', [], '', ChannelClassifier::ORGANIC_SEARCH ],
			'gemini is ai'     => [ 'gemini.google.com', [], '', ChannelClassifier::AI_ASSISTANT ],
			'chatgpt'          => [ 'chatgpt.com', [], '', ChannelClassifier::AI_ASSISTANT ],
			'gclid'            => [ 'google.com', [], 'gclid', ChannelClassifier::PAID_SEARCH ],
			'cpc on facebook'  => [ '', [ 'utm_source' => 'facebook', 'utm_medium' => 'cpc' ], '', ChannelClassifier::PAID_SOCIAL ],
			'newsletter'       => [ '', [ 'utm_source' => 'mailchimp', 'utm_medium' => 'email' ], '', ChannelClassifier::EMAIL ],
			'safaribookings'   => [ 'safaribookings.com', [], '', ChannelClassifier::OTA_LISTING ],
			'tripadvisor intl' => [ 'tripadvisor.co.uk', [], '', ChannelClassifier::OTA_LISTING ],
			'facebook organic' => [ 'm.facebook.com', [], '', ChannelClassifier::SOCIAL ],
			'gmail web'        => [ 'mail.google.com', [], '', ChannelClassifier::EMAIL ],
			'blog referral'    => [ 'travelblog.example', [], '', ChannelClassifier::REFERRAL ],
		];
	}
}
