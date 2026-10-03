<?php
/**
 * Uninstall tests. Excluded by default because they drop real tables:
 * vendor/bin/phpunit --group uninstall
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Core\Capabilities;
use BlueLens\Analytics\Core\Installer;
use BlueLens\Analytics\Core\Migrations\Migrator;
use BlueLens\Analytics\Core\Plugin;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Core\Tables;
use BlueLens\Analytics\Core\Uninstaller;
use WP_UnitTestCase;

/**
 * @group uninstall
 * @covers \BlueLens\Analytics\Core\Uninstaller
 */
final class UninstallerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Operate on the real tables rather than the suite's temporary copies.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	public function tear_down(): void {
		delete_option( Migrator::VERSION_OPTION );
		Plugin::instance()->container()->get( Installer::class )->install_site();
		parent::tear_down();
	}

	public function test_keeps_everything_when_not_opted_in(): void {
		update_option( Settings::OPTION, [ 'delete_data_on_uninstall' => false ] );

		$this->assertFalse( Uninstaller::uninstall_site() );
		$this->assertTrue( Tables::exists( Tables::EVENTS ) );
		$this->assertNotFalse( get_option( Installer::SALT_OPTION ) );
	}

	public function test_removes_everything_when_opted_in(): void {
		update_option( Settings::OPTION, [ 'delete_data_on_uninstall' => true ] );
		set_transient( 'blue_lens_example', 1 );

		$this->assertTrue( Uninstaller::uninstall_site() );

		$this->assertSame( [], Tables::existing() );
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Installer::SALT_OPTION ) );
		$this->assertFalse( get_option( Migrator::VERSION_OPTION ) );
		$this->assertFalse( get_transient( 'blue_lens_example' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
	}
}
