<?php
/**
 * Config store tests.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Core\ConfigStore;
use BlueLens\Analytics\Core\Plugin;
use WP_UnitTestCase;

/**
 * @covers \BlueLens\Analytics\Core\ConfigStore
 */
final class ConfigStoreTest extends WP_UnitTestCase {

	private ConfigStore $store;

	public function set_up(): void {
		parent::set_up();
		$this->store = Plugin::instance()->container()->get( ConfigStore::class );
	}

	public function test_missing_key_returns_null(): void {
		$this->assertNull( $this->store->get_active( 'nothing_here' ) );
		$this->assertSame( 0, $this->store->active_version( 'nothing_here' ) );
	}

	public function test_save_creates_active_versions(): void {
		$this->assertSame( 1, $this->store->save( 'active_profiles', [ 'hotel' ], 'first' ) );
		$this->assertSame( 2, $this->store->save( 'active_profiles', [ 'hotel', 'tours' ], 'second' ) );

		$this->assertSame( [ 'hotel', 'tours' ], $this->store->get_active( 'active_profiles' ) );
		$this->assertSame( 2, $this->store->active_version( 'active_profiles' ) );
	}

	public function test_activate_rolls_back(): void {
		$this->store->save( 'goals', [ 'a' => 1 ] );
		$this->store->save( 'goals', [ 'a' => 2 ] );

		$this->assertTrue( $this->store->activate( 'goals', 1 ) );
		$this->assertSame( [ 'a' => 1 ], $this->store->get_active( 'goals' ) );
		$this->assertFalse( $this->store->activate( 'goals', 99 ) );

		$history = $this->store->history( 'goals' );
		$this->assertCount( 2, $history );
		$this->assertSame( 2, $history[0]['version'] );
		$this->assertFalse( $history[0]['is_active'] );
		$this->assertTrue( $history[1]['is_active'] );
	}

	public function test_invalid_key_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->store->save( '', [] );
	}
}
