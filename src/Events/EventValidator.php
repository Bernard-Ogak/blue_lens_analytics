<?php
/**
 * Event validation against the unified schema.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Events;

use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Privacy\PiiScrubber;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes one raw event (unified schema keys) or rejects it.
 *
 * Attribute rules: flat keys matching [a-z][a-z0-9_]{0,39}; scalar values or lists of up to 10 scalars;
 * strings up to 200 chars. Keys that name personal data, and values that look like emails or phone
 * numbers, are dropped. Profile-specific attribute definitions are enforced from Phase 5.
 *
 * @phpstan-type ValidEvent array{event: string, category: string, module: string, entity_type: string, entity_id: string, value: float|null, currency: string, attributes: array<string, mixed>, is_conversion: bool, lead_ref: string}
 */
final class EventValidator {

	public const MAX_ATTRIBUTES = 25;
	public const MAX_STRING     = 200;
	public const MAX_LIST       = 10;

	/**
	 * Attribute keys that are always rejected.
	 */
	private const FORBIDDEN_KEYS = [
		'name', 'first_name', 'last_name', 'full_name', 'fullname', 'surname', 'firstname', 'lastname',
		'address', 'street', 'street_address', 'postcode', 'zip', 'zipcode',
		'dob', 'birthday', 'birth_date', 'date_of_birth',
		'ip', 'ip_address', 'user_agent', 'username', 'user_login',
	];

	/**
	 * Substrings that reject a key anywhere they appear (e.g. guest_email, billing_phone).
	 */
	private const FORBIDDEN_TOKENS = [
		'email', 'phone', 'mobile', 'passport', 'password', 'passwd', 'card_number', 'cardnumber', 'cvv', 'cvc',
		'iban', 'ssn', 'national_id', 'id_number', 'tax_id', 'document_number', 'token', 'secret',
	];

	/**
	 * Constructor.
	 *
	 * @param EventRegistry $registry Event registry.
	 * @param PiiScrubber   $scrubber PII scrubber.
	 * @param Settings      $settings Settings.
	 */
	public function __construct(
		private EventRegistry $registry,
		private PiiScrubber $scrubber,
		private Settings $settings
	) {}

	/**
	 * Validates a raw event.
	 *
	 * @param array<mixed> $raw Keys: event, category, module, entity_type, entity_id, value, currency, attributes, lead_ref.
	 * @return ValidEvent|null Null when rejected.
	 */
	public function validate( array $raw ): ?array {
		$name = isset( $raw['event'] ) && is_string( $raw['event'] ) ? strtolower( trim( $raw['event'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $name ) ) {
			return null;
		}

		$definition = $this->registry->get( $name );

		$event = [
			'event'         => $name,
			'category'      => $definition['category'] ?? $this->key( $raw['category'] ?? '', 32, 'custom' ),
			'module'        => $definition['module'] ?? $this->key( $raw['module'] ?? '', 32, 'core' ),
			'entity_type'   => $this->key( $raw['entity_type'] ?? '', 32, '' ),
			'entity_id'     => $this->entity_id( $raw['entity_id'] ?? '' ),
			'value'         => $this->value( $raw['value'] ?? null ),
			'currency'      => '',
			'attributes'    => $this->attributes( $raw['attributes'] ?? [] ),
			'is_conversion' => $definition['conversion'] ?? false,
			'lead_ref'      => $this->lead_ref( $raw['lead_ref'] ?? '' ),
		];

		if ( null !== $event['value'] ) {
			$currency          = isset( $raw['currency'] ) && is_string( $raw['currency'] ) ? strtoupper( trim( $raw['currency'] ) ) : '';
			$event['currency'] = 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : (string) $this->settings->get( 'base_currency' );
		}

		/**
		 * Filters a validated event. Return null to drop it.
		 *
		 * @param array<string, mixed>|null $event Normalized event.
		 * @param array<mixed>              $raw   Raw input.
		 */
		$event = apply_filters( 'blue_lens_validate_event', $event, $raw );

		return is_array( $event ) ? $event : null;
	}

	/**
	 * Whether an attribute key names personal data.
	 *
	 * @param string $key Attribute key.
	 */
	public static function is_forbidden_key( string $key ): bool {
		if ( in_array( $key, self::FORBIDDEN_KEYS, true ) ) {
			return true;
		}
		foreach ( self::FORBIDDEN_TOKENS as $token ) {
			if ( str_contains( $key, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validates attributes.
	 *
	 * @param mixed $raw Raw attributes.
	 * @return array<string, mixed>
	 */
	private function attributes( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$clean = [];
		foreach ( $raw as $key => $value ) {
			if ( count( $clean ) >= self::MAX_ATTRIBUTES ) {
				break;
			}

			$key = strtolower( (string) $key );
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) || self::is_forbidden_key( $key ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$list = [];
				foreach ( array_slice( array_values( $value ), 0, self::MAX_LIST ) as $item ) {
					$item = $this->scalar( $item );
					if ( null !== $item ) {
						$list[] = $item;
					}
				}
				if ( $list ) {
					$clean[ $key ] = $list;
				}
				continue;
			}

			$scalar = $this->scalar( $value );
			if ( null !== $scalar ) {
				$clean[ $key ] = $scalar;
			}
		}

		return $clean;
	}

	/**
	 * Validates one scalar attribute value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool|int|float|string|null Null to drop.
	 */
	private function scalar( mixed $value ): bool|int|float|string|null {
		if ( is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? round( $value, 6 ) : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( wp_strip_all_tags( $value ) );
		if ( '' === $value || $this->scrubber->contains_pii( $value ) ) {
			return null;
		}

		return mb_substr( $this->scrubber->scrub( $value ), 0, self::MAX_STRING );
	}

	/**
	 * Sanitized slug with a fallback.
	 *
	 * @param mixed  $value    Raw value.
	 * @param int    $max      Max length.
	 * @param string $fallback Fallback.
	 */
	private function key( mixed $value, int $max, string $fallback ): string {
		$key = is_string( $value ) ? substr( sanitize_key( $value ), 0, $max ) : '';

		return '' === $key ? $fallback : $key;
	}

	/**
	 * Entity ID: numeric or a short code such as a flight number.
	 *
	 * @param mixed $value Raw value.
	 */
	private function entity_id( mixed $value ): string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_\-.:]{1,64}$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Monetary value.
	 *
	 * @param mixed $value Raw value.
	 */
	private function value( mixed $value ): ?float {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$float = (float) $value;

		return is_finite( $float ) && abs( $float ) < 1e12 ? round( $float, 4 ) : null;
	}

	/**
	 * Lead reference ID (BLA-XXXXX).
	 *
	 * @param mixed $value Raw value.
	 */
	private function lead_ref( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^BLA-[A-Z0-9]{5,10}$/', $value ) ? $value : '';
	}
}
