<?php
/**
 * Versioned migration runner.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Applies pending migrations in order under a database lock, recording the schema version per site.
 */
final class Migrator {

	public const VERSION_OPTION = 'blue_lens_db_version';
	public const LOG_OPTION     = 'blue_lens_migration_log';
	public const LOCK_OPTION    = 'blue_lens_migration_lock';

	/**
	 * Seconds after which a lock is considered abandoned (e.g. a request that died mid-migration).
	 */
	private const LOCK_TTL = 300;

	/**
	 * Registered migrations keyed by version, sorted ascending.
	 *
	 * @var array<int, Migration>|null
	 */
	private ?array $migrations = null;

	/**
	 * All migrations keyed by version.
	 *
	 * @return array<int, Migration>
	 *
	 * @throws \LogicException When two migrations share a version.
	 */
	public function migrations(): array {
		if ( null !== $this->migrations ) {
			return $this->migrations;
		}

		$list = [
			new Migration001CoreTables(),
			new Migration002CrawlerLog(),
			new Migration003Heatmaps(),
			new Migration004Aggregates(),
			new Migration005SiteAudit(),
		];

		/**
		 * Filters the registered migrations.
		 *
		 * @param Migration[] $list Migration instances.
		 */
		$list = (array) apply_filters( 'blue_lens_migrations', $list );

		$by_version = [];
		foreach ( $list as $migration ) {
			if ( ! $migration instanceof Migration ) {
				continue;
			}
			$version = $migration->version();
			if ( isset( $by_version[ $version ] ) ) {
				throw new \LogicException( esc_html( sprintf( 'Duplicate Blue Lens migration version %d.', $version ) ) );
			}
			$by_version[ $version ] = $migration;
		}
		ksort( $by_version );

		$this->migrations = $by_version;

		return $this->migrations;
	}

	/**
	 * Schema version applied to the current site.
	 */
	public function current_version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Highest registered schema version.
	 */
	public function latest_version(): int {
		$migrations = $this->migrations();

		return [] === $migrations ? 0 : (int) array_key_last( $migrations );
	}

	/**
	 * Whether the current site has pending migrations.
	 */
	public function needs_migration(): bool {
		return $this->current_version() < $this->latest_version();
	}

	/**
	 * Migrations newer than the current site's version.
	 *
	 * @return array<int, Migration>
	 */
	public function pending(): array {
		$current = $this->current_version();

		return array_filter(
			$this->migrations(),
			static fn( int $version ): bool => $version > $current,
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Runs pending migrations for the current site.
	 *
	 * @return bool True when the schema is up to date, false when another process holds the lock.
	 *
	 * @throws \RuntimeException When a migration fails; later migrations are not attempted.
	 */
	public function migrate(): bool {
		if ( ! $this->pending() ) {
			return true;
		}

		if ( ! $this->acquire_lock() ) {
			return false;
		}

		try {
			// Another request may have finished while we waited for the lock.
			wp_cache_delete( self::VERSION_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );

			foreach ( $this->pending() as $version => $migration ) {
				/**
				 * Fires before a migration runs.
				 *
				 * @param int       $version   Target schema version.
				 * @param Migration $migration Migration instance.
				 */
				do_action( 'blue_lens_before_migration', $version, $migration );

				$migration->up();

				update_option( self::VERSION_OPTION, $version, true );
				$this->log( $version, $migration->description() );

				/**
				 * Fires after a migration succeeds.
				 *
				 * @param int       $version   Schema version now applied.
				 * @param Migration $migration Migration instance.
				 */
				do_action( 'blue_lens_after_migration', $version, $migration );
			}
		} finally {
			$this->release_lock();
		}

		return true;
	}

	/**
	 * Applied-migration history for the status screen.
	 *
	 * @return array<int, array{description: string, applied_at: string}>
	 */
	public function log_entries(): array {
		$log = get_option( self::LOG_OPTION, [] );

		return is_array( $log ) ? $log : [];
	}

	/**
	 * Records an applied migration.
	 *
	 * @param int    $version     Schema version.
	 * @param string $description Migration description.
	 */
	private function log( int $version, string $description ): void {
		$log             = $this->log_entries();
		$log[ $version ] = [
			'description' => $description,
			'applied_at'  => gmdate( 'Y-m-d H:i:s' ),
		];

		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Takes the migration lock with an atomic INSERT IGNORE (add_option() is an upsert, so it cannot lock).
	 */
	private function acquire_lock(): bool {
		global $wpdb;

		if ( $this->insert_lock() ) {
			return true;
		}

		// Clear an abandoned lock, then retry once.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d',
				$wpdb->options,
				self::LOCK_OPTION,
				time() - self::LOCK_TTL
			)
		);

		return $this->insert_lock();
	}

	/**
	 * Inserts the lock row if absent.
	 */
	private function insert_lock(): bool {
		global $wpdb;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$wpdb->options,
				self::LOCK_OPTION,
				(string) time()
			)
		);

		return 1 === $inserted;
	}

	/**
	 * Releases the migration lock.
	 */
	private function release_lock(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, [ 'option_name' => self::LOCK_OPTION ], [ '%s' ] );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}
}
