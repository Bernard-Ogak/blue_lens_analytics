<?php
/**
 * Capability tests.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Core\Capabilities;
use WP_UnitTestCase;

/**
 * @covers \BlueLens\Analytics\Core\Capabilities
 */
final class CapabilitiesTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Capabilities::add();
		parent::tear_down();
	}

	public function test_administrators_can_manage(): void {
		$admin  = self::factory()->user->create_and_get( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create_and_get( [ 'role' => 'editor' ] );

		$this->assertTrue( user_can( $admin, Capabilities::MANAGE ) );
		$this->assertFalse( user_can( $editor, Capabilities::MANAGE ) );
	}

	public function test_roles_filter_grants_extra_roles(): void {
		$filter = static fn(): array => [ 'administrator', 'editor' ];
		add_filter( 'blue_lens_capability_roles', $filter );
		Capabilities::add();
		remove_filter( 'blue_lens_capability_roles', $filter );

		$this->assertTrue( get_role( 'editor' )->has_cap( Capabilities::MANAGE ) );

		Capabilities::remove();
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::MANAGE ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
	}
}
