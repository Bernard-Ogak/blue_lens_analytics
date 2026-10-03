<?php
/**
 * Versioned configuration storage (bla_config).
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stores approved configuration (active profiles, mappings, goals) as immutable versions.
 * Exactly one version per key is active; older versions remain for audit and rollback.
 */
final class ConfigStore {

	private const CACHE_GROUP = 'blue_lens_config';

	/**
	 * Active value for a key.
	 *
	 * @param string $key Config key, e.g. "active_profiles".
	 * @return array<mixed>|null Null when no version is active.
	 */
	public function get_active( string $key ): ?array {
		global $wpdb;

		$key    = $this->normalize_key( $key );
		$cached = wp_cache_get( $this->cache_key( $key ), self::CACHE_GROUP, false, $found );
		if ( $found ) {
			return is_array( $cached ) ? $cached : null;
		}

		$json = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT config_value FROM %i WHERE config_key = %s AND is_active = 1 ORDER BY version DESC LIMIT 1',
				Tables::name( Tables::CONFIG ),
				$key
			)
		);

		$value = is_string( $json ) ? json_decode( $json, true ) : null;
		$value = is_array( $value ) ? $value : null;

		wp_cache_set( $this->cache_key( $key ), $value, self::CACHE_GROUP );

		return $value;
	}

	/**
	 * Active version number for a key, 0 when none.
	 *
	 * @param string $key Config key.
	 */
	public function active_version( string $key ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT version FROM %i WHERE config_key = %s AND is_active = 1 ORDER BY version DESC LIMIT 1',
				Tables::name( Tables::CONFIG ),
				$this->normalize_key( $key )
			)
		);
	}

	/**
	 * Saves a new version and makes it active.
	 *
	 * @param string       $key   Config key.
	 * @param array<mixed> $value JSON-serializable value.
	 * @param string       $note  Optional change note.
	 * @return int New version number.
	 *
	 * @throws \InvalidArgumentException When the value cannot be encoded.
	 * @throws \RuntimeException         When the row could not be written.
	 */
	public function save( string $key, array $value, string $note = '' ): int {
		global $wpdb;

		$key   = $this->normalize_key( $key );
		$json  = wp_json_encode( $value );
		$table = Tables::name( Tables::CONFIG );

		if ( false === $json ) {
			throw new \InvalidArgumentException( 'Blue Lens config value is not JSON-serializable.' );
		}

		// The UNIQUE (config_key, version) index resolves concurrent saves; the loser retries.
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$version = 1 + (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT MAX(version) FROM %i WHERE config_key = %s', $table, $key )
			);

			$suppress = $wpdb->suppress_errors( true );
			$inserted = $wpdb->insert(
				$table,
				[
					'config_key'   => $key,
					'version'      => $version,
					'config_value' => $json,
					'is_active'    => 0,
					'note'         => substr( sanitize_text_field( $note ), 0, 255 ),
					'created_by'   => get_current_user_id(),
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				],
				[ '%s', '%d', '%s', '%d', '%s', '%d', '%s' ]
			);
			$wpdb->suppress_errors( $suppress );

			if ( false !== $inserted ) {
				$this->activate( $key, $version );
				return $version;
			}
		}

		throw new \RuntimeException( esc_html( sprintf( 'Could not save Blue Lens config "%s".', $key ) ) );
	}

	/**
	 * Makes an existing version the active one (used for rollback).
	 *
	 * @param string $key     Config key.
	 * @param int    $version Version to activate.
	 * @return bool False when the version does not exist.
	 */
	public function activate( string $key, int $version ): bool {
		global $wpdb;

		$key   = $this->normalize_key( $key );
		$table = Tables::name( Tables::CONFIG );

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE config_key = %s AND version = %d', $table, $key, $version )
		);
		if ( 0 === $exists ) {
			return false;
		}

		// A single UPDATE flips every row for the key atomically.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET is_active = IF(version = %d, 1, 0) WHERE config_key = %s',
				$table,
				$version,
				$key
			)
		);

		wp_cache_delete( $this->cache_key( $key ), self::CACHE_GROUP );

		/**
		 * Fires when the active version of a configuration key changes.
		 *
		 * @param string $key     Config key.
		 * @param int    $version Active version.
		 */
		do_action( 'blue_lens_config_changed', $key, $version );

		return true;
	}

	/**
	 * Version history for a key, newest first.
	 *
	 * @param string $key   Config key.
	 * @param int    $limit Maximum rows.
	 * @return list<array{version: int, is_active: bool, note: string, created_by: int, created_at: string}>
	 */
	public function history( string $key, int $limit = 20 ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT version, is_active, note, created_by, created_at FROM %i WHERE config_key = %s ORDER BY version DESC LIMIT %d',
				Tables::name( Tables::CONFIG ),
				$this->normalize_key( $key ),
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => [
				'version'    => (int) $row['version'],
				'is_active'  => (bool) $row['is_active'],
				'note'       => (string) $row['note'],
				'created_by' => (int) $row['created_by'],
				'created_at' => (string) $row['created_at'],
			],
			array_values( $rows )
		);
	}

	/**
	 * Validates a key.
	 *
	 * @param string $key Raw key.
	 *
	 * @throws \InvalidArgumentException When the key is empty or too long.
	 */
	private function normalize_key( string $key ): string {
		$clean = sanitize_key( $key );

		if ( '' === $clean || strlen( $clean ) > 64 ) {
			throw new \InvalidArgumentException( 'Invalid Blue Lens config key.' );
		}

		return $clean;
	}

	/**
	 * Object-cache key, scoped per site.
	 *
	 * @param string $key Config key.
	 */
	private function cache_key( string $key ): string {
		return get_current_blog_id() . ':' . $key;
	}
}
