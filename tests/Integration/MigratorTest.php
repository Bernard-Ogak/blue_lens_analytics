<?php
/**
 * Migration tests.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Core\Migrations\Migrator;
use BlueLens\Analytics\Core\Plugin;
use BlueLens\Analytics\Core\Tables;
use WP_UnitTestCase;

/**
 * @covers \BlueLens\Analytics\Core\Migrations\Migrator
 * @covers \BlueLens\Analytics\Core\Migrations\Migration001CoreTables
 */
final class MigratorTest extends WP_UnitTestCase {

	private Migrator $migrator;

	public function set_up(): void {
		parent::set_up();
		$this->migrator = Plugin::instance()->container()->get( Migrator::class );
	}

	public function test_schema_is_current_after_install(): void {
		$this->assertGreaterThanOrEqual( 1, $this->migrator->latest_version() );
		$this->assertFalse( $this->migrator->needs_migration() );
	}

	public function test_all_core_tables_exist(): void {
		foreach ( Tables::keys() as $table ) {
			$this->assertTrue( Tables::exists( $table ), "Missing table {$table}" );
		}
	}

	public function test_events_primary_key_is_partition_friendly(): void {
		global $wpdb;

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				"SHOW INDEX FROM %i WHERE Key_name = 'PRIMARY'",
				Tables::name( Tables::EVENTS )
			),
			4
		);

		$this->assertSame( [ 'id', 'occurred_at' ], $columns );
	}

	public function test_rerunning_migrations_is_idempotent(): void {
		update_option( Migrator::VERSION_OPTION, 0 );

		$this->assertTrue( $this->migrator->migrate() );
		$this->assertSame( $this->migrator->latest_version(), $this->migrator->current_version() );
	}

	public function test_migrate_returns_false_while_locked(): void {
		global $wpdb;

		update_option( Migrator::VERSION_OPTION, 0 );
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => Migrator::LOCK_OPTION,
				'option_value' => (string) time(),
				'autoload'     => 'no',
			]
		);

		$this->assertFalse( $this->migrator->migrate() );
		$this->assertSame( 0, $this->migrator->current_version() );
	}

	public function test_stale_lock_is_taken_over(): void {
		global $wpdb;

		update_option( Migrator::VERSION_OPTION, 0 );
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => Migrator::LOCK_OPTION,
				'option_value' => (string) ( time() - 3600 ),
				'autoload'     => 'no',
			]
		);

		$this->assertTrue( $this->migrator->migrate() );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, Migrator::LOCK_OPTION ) ) );
	}
}
