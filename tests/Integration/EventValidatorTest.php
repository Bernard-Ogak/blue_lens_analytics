<?php
/**
 * Event validation tests.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Core\Plugin;
use BlueLens\Analytics\Events\EventValidator;
use WP_UnitTestCase;

/**
 * @covers \BlueLens\Analytics\Events\EventValidator
 */
final class EventValidatorTest extends WP_UnitTestCase {

	private EventValidator $validator;

	public function set_up(): void {
		parent::set_up();
		$this->validator = Plugin::instance()->container()->get( EventValidator::class );
	}

	public function test_valid_event_is_normalized(): void {
		$event = $this->validator->validate(
			[
				'event'       => 'booking_enquiry',
				'category'    => 'conversion',
				'module'      => 'hotel',
				'entity_type' => 'room',
				'entity_id'   => 482,
				'value'       => '1200.00',
				'currency'    => 'usd',
				'lead_ref'    => 'BLA-8F3K2',
				'attributes'  => [
					'check_in'     => '2026-12-20',
					'nights'       => 4,
					'guests_adults' => 2,
					'room_type'    => 'Tented Suite',
					'interests'    => [ 'photography', 'birding' ],
				],
			]
		);

		$this->assertNotNull( $event );
		$this->assertSame( 'conversion', $event['category'] );
		$this->assertSame( 'hotel', $event['module'] );
		$this->assertSame( '482', $event['entity_id'] );
		$this->assertSame( 1200.0, $event['value'] );
		$this->assertSame( 'USD', $event['currency'] );
		$this->assertSame( 'BLA-8F3K2', $event['lead_ref'] );
		$this->assertSame( '2026-12-20', $event['attributes']['check_in'] );
		$this->assertSame( [ 'photography', 'birding' ], $event['attributes']['interests'] );
	}

	public function test_invalid_names_are_rejected(): void {
		$this->assertNull( $this->validator->validate( [ 'event' => 'Bad Name!' ] ) );
		$this->assertNull( $this->validator->validate( [ 'event' => '' ] ) );
		$this->assertNull( $this->validator->validate( [] ) );
	}

	public function test_pii_attributes_are_dropped(): void {
		$event = $this->validator->validate(
			[
				'event'      => 'enquiry_submitted',
				'attributes' => [
					'guest_email'     => 'someone@example.com',
					'first_name'      => 'Jane',
					'passport_number' => 'A1234567',
					'notes'           => 'Call me on +254 712 345 678',
					'destination'     => 'Masai Mara',
					'Bad Key'         => 'x',
				],
			]
		);

		$this->assertSame( [ 'destination' => 'Masai Mara' ], $event['attributes'] );
	}

	public function test_registry_overrides_client_category(): void {
		$event = $this->validator->validate(
			[
				'event'    => 'page_view',
				'category' => 'conversion',
			]
		);

		$this->assertSame( 'page', $event['category'] );
		$this->assertFalse( $event['is_conversion'] );
	}

	public function test_value_without_currency_uses_base_currency(): void {
		$event = $this->validator->validate(
			[
				'event' => 'donation',
				'value' => 50,
			]
		);

		$this->assertSame( 'USD', $event['currency'] );
		$this->assertNull( $this->validator->validate( [ 'event' => 'x_y' ] )['value'] );
	}

	public function test_attribute_limits(): void {
		$attributes = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$attributes[ 'a' . $i ] = str_repeat( 'x', 500 );
		}

		$event = $this->validator->validate(
			[
				'event'      => 'many',
				'attributes' => $attributes,
			]
		);

		$this->assertCount( EventValidator::MAX_ATTRIBUTES, $event['attributes'] );
		$this->assertSame( EventValidator::MAX_STRING, mb_strlen( $event['attributes']['a0'] ) );
	}
}
