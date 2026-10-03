<?php
/**
 * Collection pipeline: payload -> validated session and event rows.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Events\EventRegistry;
use BlueLens\Analytics\Events\EventValidator;
use BlueLens\Analytics\Events\EventWriter;
use BlueLens\Analytics\Events\FxConverter;
use BlueLens\Analytics\Privacy\PiiScrubber;
use BlueLens\Analytics\Privacy\VisitorHasher;
use BlueLens\Analytics\Tracking\Geo\GeoLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Processes browser payloads and server-side events.
 *
 * Browser payload (compact keys to keep beacons small):
 *   u  page URL           r  referrer         ti title          tz time zone     lg language
 *   vw viewport width     tp touch-Mac flag   vid first-party ID (enhanced + consent only)
 *   wd webdriver flag     c  page context     hb engaged seconds since last send
 *   hm heatmap clicks: [[x percent 0-99, y in 20px rows], ...]
 *   e  events: [{ n name, d ms ago, a attributes, m module, c category, et entity_type,
 *                 ei entity_id, v value, cu currency, lr lead_ref }]
 *
 * @phpstan-import-type ValidEvent from EventValidator
 * @phpstan-import-type Row from EventWriter
 */
final class Collector {

	public const MAX_EVENTS            = 25;
	public const SESSION_EVENT_CAP     = 2000;
	public const RATE_LIMIT_PER_MINUTE = 120;
	public const MAX_HEARTBEAT_SECONDS = 300;
	public const CLIENT_ID_COOKIE      = 'bla_vid';

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings     Settings.
	 * @param VisitorHasher     $hasher       Visitor key derivation.
	 * @param SessionManager    $sessions     Session storage.
	 * @param EventValidator    $validator    Event validation.
	 * @param EventWriter       $writer       Event storage.
	 * @param UrlSanitizer      $urls         URL handling.
	 * @param ChannelClassifier $channels     Channel grouping.
	 * @param UserAgentParser   $ua_parser    UA parsing.
	 * @param BotDetector       $bots         Bot detection.
	 * @param GeoLocator        $geo          Geo lookup.
	 * @param FxConverter       $fx           Currency conversion.
	 * @param RateLimiter       $rate_limiter Rate limiting.
	 * @param PiiScrubber       $scrubber     PII scrubbing.
	 * @param HeatmapRecorder   $heatmaps     Heatmap aggregation.
	 */
	public function __construct(
		private Settings $settings,
		private VisitorHasher $hasher,
		private SessionManager $sessions,
		private EventValidator $validator,
		private EventWriter $writer,
		private UrlSanitizer $urls,
		private ChannelClassifier $channels,
		private UserAgentParser $ua_parser,
		private BotDetector $bots,
		private GeoLocator $geo,
		private FxConverter $fx,
		private RateLimiter $rate_limiter,
		private PiiScrubber $scrubber,
		private HeatmapRecorder $heatmaps
	) {}

	/**
	 * Processes one browser payload.
	 *
	 * @param array<mixed>   $payload Decoded JSON body.
	 * @param RequestContext $req     Request facts.
	 */
	public function collect( array $payload, RequestContext $req ): CollectResult {
		/**
		 * Filters a raw payload before processing. Return null to drop it.
		 *
		 * @param array<mixed>|null $payload Payload.
		 * @param RequestContext    $req     Request facts.
		 */
		$payload = apply_filters( 'blue_lens_before_collect', $payload, $req );
		if ( ! is_array( $payload ) ) {
			return CollectResult::ignored( 'filtered' );
		}

		$settings = $this->settings->all();

		$blocked = $this->blocked_reason( $settings, $req );
		if ( null !== $blocked ) {
			return CollectResult::ignored( $blocked );
		}
		if ( ! empty( $payload['wd'] ) || $this->bots->is_bot( $req->user_agent ) ) {
			return CollectResult::ignored( 'bot' );
		}

		$page = $this->urls->parse( self::str( $payload['u'] ?? '' ) );
		if ( ! $this->is_own_host( $page['host'] ) ) {
			return CollectResult::ignored( 'foreign_host' );
		}
		if ( UrlSanitizer::path_matches( $page['path'], $settings['excluded_paths'] ) ) {
			return CollectResult::ignored( 'excluded_path' );
		}

		$client_id   = $payload['vid'] ?? null;
		$enhanced    = $this->enhanced_allowed( $settings, $req ) && VisitorHasher::is_valid_client_id( $client_id );
		$visitor_key = $enhanced
			? $this->hasher->enhanced( (string) $client_id )
			: $this->hasher->cookieless( $req->ip, $req->user_agent, $req->now );

		if ( ! $this->rate_limiter->allow( bin2hex( $visitor_key ), self::RATE_LIMIT_PER_MINUTE ) ) {
			return CollectResult::ignored( 'rate_limited' );
		}

		$events    = $this->validate_events( $payload['e'] ?? null );
		$heartbeat = isset( $payload['hb'] ) && is_numeric( $payload['hb'] )
			? max( 0, min( self::MAX_HEARTBEAT_SECONDS, (int) $payload['hb'] ) )
			: 0;

		$heat_points = 0;
		if ( $settings['heatmaps'] && isset( $payload['hm'] ) && is_array( $payload['hm'] ) ) {
			$device      = $this->ua_parser->parse( $req->user_agent, $req->ch_platform, ! empty( $payload['tp'] ) )->device_type;
			$heat_points = $this->heatmaps->record( $page['path'], $device, $payload['hm'], $req->now );
		}

		if ( ! $events && 0 === $heartbeat ) {
			return $heat_points > 0 ? CollectResult::stored( 0, 0 ) : CollectResult::ignored( 'empty' );
		}

		$now_sql      = gmdate( 'Y-m-d H:i:s', $req->now );
		$has_pageview = (bool) array_filter( $events, static fn( array $e ): bool => EventRegistry::PAGE_VIEW === $e[0]['event'] );
		$utm          = $this->urls->campaign( $page['query'] );
		$click        = $this->urls->click_id( $page['query'] );
		$context      = self::sanitize_context( $payload['c'] ?? null );

		$session = $this->sessions->find_active(
			$visitor_key,
			gmdate( 'Y-m-d H:i:s', $req->now - 60 * (int) $settings['session_timeout_minutes'] )
		);

		if ( null !== $session && $has_pageview && self::campaign_changed( $session, $utm, $click['type'] ) ) {
			$session = null;
		}

		if ( null === $session ) {
			if ( ! $events ) {
				return CollectResult::ignored( 'no_session' );
			}

			$session_id = $this->start_session( $payload, $req, $visitor_key, $enhanced, $page, $utm, $click, $context, $now_sql );
			if ( 0 === $session_id ) {
				return CollectResult::ignored( 'db_error' );
			}
			$event_count = 0;
		} else {
			$session_id  = $session['id'];
			$event_count = $session['event_count'];
		}

		$events = array_slice( $events, 0, max( 0, self::SESSION_EVENT_CAP - $event_count ) );
		$title  = mb_substr( $this->scrubber->scrub( sanitize_text_field( self::str( $payload['ti'] ?? '' ) ) ), 0, 255 );

		$rows = [];
		foreach ( $events as [ $event, $delay_ms ] ) {
			$rows[] = $this->row(
				$event,
				gmdate( 'Y-m-d H:i:s', $req->now - intdiv( $delay_ms, 1000 ) ),
				$session_id,
				$visitor_key,
				EventWriter::ORIGIN_BROWSER,
				$page['path'],
				$title,
				$context
			);
		}

		$stored      = $this->writer->insert( $rows );
		$pageviews   = count( array_filter( $rows, static fn( array $r ): bool => EventRegistry::PAGE_VIEW === $r['event_name'] ) );
		$conversions = count( array_filter( $rows, static fn( array $r ): bool => $r['is_conversion'] ) );

		$this->sessions->touch( $session_id, $now_sql, $pageviews, $stored, $heartbeat, $conversions, $pageviews > 0 ? $page['path'] : '' );

		/**
		 * Fires after a browser payload is stored.
		 *
		 * @param list<array<string, mixed>> $rows       Event rows written.
		 * @param int                        $session_id Session ID.
		 */
		do_action( 'blue_lens_after_collect', $rows, $session_id );

		return CollectResult::stored( $stored, $session_id );
	}

	/**
	 * Records a server-side event (order, booking, refund) and links it to the visitor's session when
	 * the hook runs inside the visitor's own request.
	 *
	 * @param array<mixed>        $raw Unified-schema event; optional "session_key" (32 hex) and "page_path".
	 * @param RequestContext|null $req Current request, or null for background contexts (webhooks, CLI).
	 */
	public function record_server_event( array $raw, ?RequestContext $req ): CollectResult {
		$settings = $this->settings->all();

		if ( null !== $req ) {
			$blocked = $this->blocked_reason( $settings, $req );
			if ( null !== $blocked ) {
				return CollectResult::ignored( $blocked );
			}
		} elseif ( ! $settings['tracking_enabled'] ) {
			return CollectResult::ignored( 'disabled' );
		}

		// Staff testing forms or checkout are excluded, matching the browser tracker's role exclusion.
		if ( is_user_logged_in() && array_intersect( wp_get_current_user()->roles, (array) $settings['excluded_roles'] ) ) {
			return CollectResult::ignored( 'excluded_role' );
		}

		$event = $this->validator->validate( $raw );
		if ( null === $event ) {
			return CollectResult::ignored( 'invalid' );
		}

		[ $session_id, $visitor_key ] = $this->resolve_server_session( $raw, $req, $settings );

		$now  = null !== $req ? $req->now : time();
		$path = isset( $raw['page_path'] ) && is_string( $raw['page_path'] )
			? $this->urls->clean_path( $raw['page_path'] )
			: $this->server_page_path();

		$row    = $this->row( $event, gmdate( 'Y-m-d H:i:s', $now ), $session_id, $visitor_key, EventWriter::ORIGIN_SERVER, $path, '', [] );
		$stored = $this->writer->insert( [ $row ] );

		if ( $stored > 0 && $session_id > 0 ) {
			$this->sessions->touch( $session_id, gmdate( 'Y-m-d H:i:s', $now ), 0, 1, 0, $row['is_conversion'] ? 1 : 0, '' );
		}

		/** This action is documented in src/Tracking/Collector.php */
		do_action( 'blue_lens_after_collect', [ $row ], $session_id );

		return $stored > 0 ? CollectResult::stored( $stored, $session_id ) : CollectResult::ignored( 'db_error' );
	}

	/**
	 * Checks that apply to every request.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param RequestContext       $req      Request.
	 * @return string|null Reason, or null when allowed.
	 */
	private function blocked_reason( array $settings, RequestContext $req ): ?string {
		if ( ! $settings['tracking_enabled'] ) {
			return 'disabled';
		}
		if ( $settings['respect_dnt'] && $req->dnt ) {
			return 'dnt';
		}
		if ( $settings['respect_gpc'] && 'stop' === $settings['gpc_action'] && $req->gpc ) {
			return 'gpc';
		}
		if ( '' !== $req->ip && $settings['excluded_ips'] && ClientIp::matches( $req->ip, $settings['excluded_ips'] ) ) {
			return 'excluded_ip';
		}

		return null;
	}

	/**
	 * Whether enhanced (first-party ID) mode may be used. GPC, when respected, forces cookieless mode.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param RequestContext       $req      Request.
	 */
	private function enhanced_allowed( array $settings, RequestContext $req ): bool {
		return Settings::MODE_ENHANCED === $settings['privacy_mode'] && ! ( $settings['respect_gpc'] && $req->gpc );
	}

	/**
	 * Validates the payload's events.
	 *
	 * @param mixed $raw_events Raw "e" list.
	 * @return list<array{0: ValidEvent, 1: int}> Event and client-side delay in ms.
	 */
	private function validate_events( mixed $raw_events ): array {
		if ( ! is_array( $raw_events ) ) {
			return [];
		}

		$events = [];
		foreach ( array_slice( array_values( $raw_events ), 0, self::MAX_EVENTS ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$event = $this->validator->validate(
				[
					'event'       => $raw['n'] ?? null,
					'category'    => $raw['c'] ?? null,
					'module'      => $raw['m'] ?? null,
					'entity_type' => $raw['et'] ?? null,
					'entity_id'   => $raw['ei'] ?? null,
					'value'       => $raw['v'] ?? null,
					'currency'    => $raw['cu'] ?? null,
					'attributes'  => $raw['a'] ?? null,
					'lead_ref'    => $raw['lr'] ?? null,
				]
			);

			if ( null !== $event ) {
				$delay    = isset( $raw['d'] ) && is_numeric( $raw['d'] ) ? (int) $raw['d'] : 0;
				$events[] = [ $event, max( 0, min( 600000, $delay ) ) ];
			}
		}

		return $events;
	}

	/**
	 * Creates a session from the first hit.
	 *
	 * @param array<mixed>                                                      $payload     Payload.
	 * @param RequestContext                                                    $req         Request.
	 * @param string                                                            $visitor_key Visitor key.
	 * @param bool                                                              $enhanced    Enhanced mode.
	 * @param array{host: string, path: string, query: array<string, string>} $page        Parsed page URL.
	 * @param array<string, string>                                             $utm         UTM parameters.
	 * @param array{type: string, value: string}                                $click       Click ID.
	 * @param array<string, mixed>                                              $context     Page context.
	 * @param string                                                            $now_sql     Now (UTC).
	 * @return int Session ID.
	 */
	private function start_session( array $payload, RequestContext $req, string $visitor_key, bool $enhanced, array $page, array $utm, array $click, array $context, string $now_sql ): int {
		$referrer = $this->urls->referrer( self::str( $payload['r'] ?? '' ) );
		if ( '' !== $referrer['domain'] && $this->is_own_host( $referrer['domain'] ) ) {
			$referrer = [
				'domain' => '',
				'url'    => '',
			];
		}

		$ua  = $this->ua_parser->parse( $req->user_agent, $req->ch_platform, ! empty( $payload['tp'] ) );
		$geo = $this->geo->lookup( $req->ip, $req->cf_country );

		$data = [
			'session_key'     => random_bytes( 16 ),
			'visitor_key'     => $visitor_key,
			'id_mode'         => $enhanced ? 1 : 0,
			'is_returning'    => $enhanced ? ( $this->sessions->has_previous( $visitor_key, $now_sql ) ? 1 : 0 ) : null,
			'started_at'      => $now_sql,
			'last_seen_at'    => $now_sql,
			'landing_path'    => $page['path'],
			'landing_post_id' => (int) ( $context['p'] ?? 0 ),
			'exit_path'       => $page['path'],
			'referrer_domain' => $referrer['domain'],
			'referrer_url'    => $referrer['url'],
			'channel'         => $this->channels->classify( $referrer['domain'], $utm, $click['type'] ),
			'click_id_type'   => $click['type'],
			// Click IDs can be linked to ad-platform profiles: kept only for consented (enhanced) sessions.
			'click_id'        => $enhanced ? $click['value'] : '',
			'device_type'     => $ua->device_type,
			'browser'         => mb_substr( $ua->browser, 0, 32 ),
			'browser_version' => mb_substr( $ua->browser_version, 0, 16 ),
			'os'              => mb_substr( $ua->os, 0, 32 ),
			'os_version'      => mb_substr( $ua->os_version, 0, 16 ),
			'viewport'        => self::viewport_bucket( $payload['vw'] ?? null ),
			'language'        => self::language( $payload['lg'] ?? null ),
			'country'         => $geo->country,
			'region'          => $geo->region,
			'city'            => $geo->city,
			'timezone'        => self::timezone( $payload['tz'] ?? null ),
			'is_logged_in'    => (int) ( $context['li'] ?? 0 ),
		] + $utm;

		/**
		 * Filters a new session row before insert.
		 *
		 * @param array<string, mixed> $data Column values.
		 */
		$data = (array) apply_filters( 'blue_lens_new_session', $data );

		return $this->sessions->create( $data );
	}

	/**
	 * Builds one event row.
	 *
	 * @param ValidEvent           $event       Validated event.
	 * @param string               $occurred_at UTC datetime.
	 * @param int                  $session_id  Session ID (0 for none).
	 * @param string|null          $visitor_key Visitor key.
	 * @param int                  $origin      EventWriter::ORIGIN_*.
	 * @param string               $path        Page path.
	 * @param string               $title       Page title.
	 * @param array<string, mixed> $context     Page context.
	 * @return Row
	 */
	private function row( array $event, string $occurred_at, int $session_id, ?string $visitor_key, int $origin, string $path, string $title, array $context ): array {
		$is_pageview = EventRegistry::PAGE_VIEW === $event['event'];
		$value_base  = null === $event['value'] ? null : $this->fx->to_base( $event['value'], $event['currency'], substr( $occurred_at, 0, 10 ) );

		// The full page context is stored once per page view; other events keep post ID and type only.
		$stored_context = $is_pageview ? array_diff_key( $context, [ 'p' => 0, 't' => 0 ] ) : [];

		return [
			'occurred_at'      => $occurred_at,
			'session_id'       => $session_id,
			'visitor_key'      => $visitor_key,
			'event_name'       => $event['event'],
			'category'         => $event['category'],
			'module'           => $event['module'],
			'origin'           => $origin,
			'entity_type'      => $event['entity_type'],
			'entity_id'        => $event['entity_id'],
			'post_id'          => (int) ( $context['p'] ?? 0 ),
			'post_type'        => (string) ( $context['t'] ?? '' ),
			'page_path'        => $path,
			'page_title'       => $is_pageview ? $title : '',
			'event_value'      => $event['value'],
			'currency'         => $event['currency'],
			'event_value_base' => $value_base,
			'is_conversion'    => $event['is_conversion'],
			'lead_ref'         => $event['lead_ref'],
			'attributes'       => $event['attributes'],
			'context'          => $stored_context ? $stored_context : null,
		];
	}

	/**
	 * Session and visitor for a server-side event.
	 *
	 * @param array<mixed>         $raw      Raw event.
	 * @param RequestContext|null  $req      Request.
	 * @param array<string, mixed> $settings Settings.
	 * @return array{0: int, 1: string|null}
	 */
	private function resolve_server_session( array $raw, ?RequestContext $req, array $settings ): array {
		if ( isset( $raw['session_key'] ) && is_string( $raw['session_key'] ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $raw['session_key'] ) ) {
			$session = $this->sessions->find_by_key( (string) hex2bin( $raw['session_key'] ) );
			if ( null !== $session ) {
				return [ $session['id'], $session['visitor_key'] ];
			}
		}

		if ( null === $req || '' === $req->ip ) {
			return [ 0, null ];
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- validated by is_valid_client_id().
		$cookie      = isset( $_COOKIE[ self::CLIENT_ID_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::CLIENT_ID_COOKIE ] ) ) : '';
		$visitor_key = $this->enhanced_allowed( $settings, $req ) && VisitorHasher::is_valid_client_id( $cookie )
			? $this->hasher->enhanced( $cookie )
			: $this->hasher->cookieless( $req->ip, $req->user_agent, $req->now );

		$session = $this->sessions->find_active(
			$visitor_key,
			gmdate( 'Y-m-d H:i:s', $req->now - 60 * (int) $settings['session_timeout_minutes'] )
		);

		return null === $session ? [ 0, null ] : [ $session['id'], $visitor_key ];
	}

	/**
	 * Path for a server event: the same-site referer (AJAX checkouts), else the request path.
	 */
	private function server_page_path(): string {
		$referer = wp_get_raw_referer();
		if ( is_string( $referer ) && '' !== $referer ) {
			$parsed = $this->urls->parse( $referer );
			if ( $this->is_own_host( $parsed['host'] ) ) {
				return $parsed['path'];
			}
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return $this->urls->clean_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	/**
	 * Whether a host belongs to this site.
	 *
	 * @param string $host Host.
	 */
	private function is_own_host( string $host ): bool {
		if ( '' === $host ) {
			return false;
		}

		$hosts = [
			UrlSanitizer::bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			UrlSanitizer::bare_host( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
		];

		/**
		 * Filters hosts accepted by the collector (add language domains for WPML/Polylang multi-domain setups).
		 *
		 * @param list<string> $hosts Hosts without "www.".
		 */
		$hosts = (array) apply_filters( 'blue_lens_allowed_hosts', $hosts );

		return in_array( UrlSanitizer::bare_host( $host ), array_map( 'strval', $hosts ), true );
	}

	/**
	 * Whether a page view carries a different campaign than its session, which starts a new session.
	 *
	 * @param array<string, mixed>  $session    Active session.
	 * @param array<string, string> $utm        Incoming UTM.
	 * @param string                $click_type Incoming click ID type.
	 */
	private static function campaign_changed( array $session, array $utm, string $click_type ): bool {
		if ( '' === $utm['utm_source'] && '' === $utm['utm_medium'] && '' === $utm['utm_campaign'] && '' === $click_type ) {
			return false;
		}

		return strtolower( $utm['utm_source'] . '|' . $utm['utm_medium'] . '|' . $utm['utm_campaign'] ) !== strtolower( $session['utm_source'] . '|' . $session['utm_medium'] . '|' . $session['utm_campaign'] )
			|| ( '' !== $click_type && $click_type !== $session['click_id_type'] );
	}

	/**
	 * Validates the page context sent by the tracker (it is client-supplied, so types and sizes are enforced).
	 *
	 * @param mixed $raw Raw context.
	 * @return array<string, mixed>
	 */
	public static function sanitize_context( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$ctx = [];

		foreach ( [ 'p', 'a' ] as $key ) {
			if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) && (int) $raw[ $key ] > 0 ) {
				$ctx[ $key ] = (int) $raw[ $key ];
			}
		}
		foreach ( [ 't' => 20, 'tpl' => 64, 'b' => 20, 'k' => 20, 'r' => 32 ] as $key => $max ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) {
				$value = substr( sanitize_key( $raw[ $key ] ), 0, $max );
				if ( '' !== $value ) {
					$ctx[ $key ] = $value;
				}
			}
		}
		foreach ( [ 'pd', 'md' ] as $key ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw[ $key ] ) ) {
				$ctx[ $key ] = $raw[ $key ];
			}
		}
		if ( isset( $raw['l'] ) && is_string( $raw['l'] ) && 1 === preg_match( '/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/', $raw['l'] ) ) {
			$ctx['l'] = $raw['l'];
		}
		if ( isset( $raw['li'] ) ) {
			$ctx['li'] = empty( $raw['li'] ) ? 0 : 1;
		}
		if ( isset( $raw['tx'] ) && is_array( $raw['tx'] ) ) {
			$taxonomies = [];
			foreach ( array_slice( $raw['tx'], 0, 10, true ) as $taxonomy => $terms ) {
				$taxonomy = substr( sanitize_key( (string) $taxonomy ), 0, 32 );
				if ( '' === $taxonomy || ! is_array( $terms ) ) {
					continue;
				}
				$ids = array_values( array_filter( array_map( 'intval', array_slice( $terms, 0, 20 ) ), static fn( int $id ): bool => $id > 0 ) );
				if ( $ids ) {
					$taxonomies[ $taxonomy ] = $ids;
				}
			}
			if ( $taxonomies ) {
				$ctx['tx'] = $taxonomies;
			}
		}
		if ( isset( $raw['q'] ) && is_array( $raw['q'] ) ) {
			$query = [];
			if ( isset( $raw['q']['tax'] ) && is_string( $raw['q']['tax'] ) ) {
				$query['tax'] = substr( sanitize_key( $raw['q']['tax'] ), 0, 32 );
			}
			foreach ( [ 'term', 'author' ] as $key ) {
				if ( isset( $raw['q'][ $key ] ) && is_numeric( $raw['q'][ $key ] ) ) {
					$query[ $key ] = (int) $raw['q'][ $key ];
				}
			}
			if ( $query ) {
				$ctx['q'] = $query;
			}
		}

		return $ctx;
	}

	/**
	 * Viewport width bucket.
	 *
	 * @param mixed $width Pixels.
	 */
	public static function viewport_bucket( mixed $width ): string {
		if ( ! is_numeric( $width ) || (int) $width <= 0 ) {
			return '';
		}

		$width = (int) $width;

		return match ( true ) {
			$width < 576  => 'xs',
			$width < 768  => 'sm',
			$width < 992  => 'md',
			$width < 1200 => 'lg',
			$width < 1600 => 'xl',
			default       => 'xxl',
		};
	}

	/**
	 * BCP 47 language tag.
	 *
	 * @param mixed $value Raw.
	 */
	private static function language( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/', $value ) ? substr( $value, 0, 16 ) : '';
	}

	/**
	 * IANA time zone.
	 *
	 * @param mixed $value Raw.
	 */
	private static function timezone( mixed $value ): string {
		return is_string( $value ) && strlen( $value ) <= 64 && in_array( $value, timezone_identifiers_list(), true ) ? $value : '';
	}

	/**
	 * String or ''.
	 *
	 * @param mixed $value Raw.
	 */
	private static function str( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
