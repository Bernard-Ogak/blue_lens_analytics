<?php
/**
 * Detects and redacts personal data patterns in free text.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Pattern-based PII detection for URLs, titles and event attributes.
 *
 * Deliberately errs on the side of removal: a 10-digit order number may be redacted as a phone
 * number, which is preferable to storing a real phone number.
 */
final class PiiScrubber {

	private const EMAIL = '/[A-Z0-9._%+\-]+(?:@|%40)[A-Z0-9.\-]+\.[A-Z]{2,}/i';

	/**
	 * Digit runs with phone-style separators. Candidates are confirmed by digit count (>= 9),
	 * so ISO dates (8 digits) and prices survive.
	 */
	private const PHONE_CANDIDATE = '/\+?\d[\d\s().\-]{6,}\d/';

	/**
	 * Opaque tokens: UUIDs, or 24+ character runs mixing letters and digits without hyphens
	 * (reset keys, session IDs, JWT segments). Hyphenated slugs are not tokens.
	 */
	private const UUID  = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i';
	private const TOKEN = '/(?<![A-Za-z0-9_])(?=[A-Za-z0-9_]*\d)(?=[A-Za-z0-9_]*[A-Za-z])[A-Za-z0-9_]{24,}(?![A-Za-z0-9_])/';

	/**
	 * Redacts emails, phone numbers and opaque tokens.
	 *
	 * @param string $text Input.
	 */
	public function scrub( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		$text = (string) preg_replace( self::EMAIL, '[email]', $text );
		$text = (string) preg_replace_callback(
			self::PHONE_CANDIDATE,
			static fn( array $m ): string => self::is_phone( $m[0] ) ? '[phone]' : $m[0],
			$text
		);
		$text = (string) preg_replace( self::UUID, '[token]', $text );

		return (string) preg_replace( self::TOKEN, '[token]', $text );
	}

	/**
	 * Whether text contains an email address or phone number.
	 *
	 * @param string $text Input.
	 */
	public function contains_pii( string $text ): bool {
		if ( 1 === preg_match( self::EMAIL, $text ) ) {
			return true;
		}

		if ( preg_match_all( self::PHONE_CANDIDATE, $text, $matches ) ) {
			foreach ( $matches[0] as $candidate ) {
				if ( self::is_phone( $candidate ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Confirms a phone candidate by digit count.
	 *
	 * @param string $candidate Matched text.
	 */
	private static function is_phone( string $candidate ): bool {
		return strlen( (string) preg_replace( '/\D/', '', $candidate ) ) >= 9;
	}
}
