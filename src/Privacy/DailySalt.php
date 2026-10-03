<?php
/**
 * Rotating daily salt for cookieless visitor hashing.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * One random salt per UTC day. Previous days' salts are deleted as soon as a new one is created,
 * so yesterday's visitor hashes can no longer be recomputed or linked to today's.
 */
final class DailySalt {

	public const OPTION_PREFIX = 'blue_lens_dsalt_';

	/**
	 * Salts resolved in this request, keyed by Ymd.
	 *
	 * @var array<string, string>
	 */
	private array $memo = [];

	/**
	 * Salt for a UTC day.
	 *
	 * @param string $day Date as Ymd.
	 */
	public function for_day( string $day ): string {
		if ( isset( $this->memo[ $day ] ) ) {
			return $this->memo[ $day ];
		}

		$name = self::OPTION_PREFIX . $day;
		$salt = get_option( $name );

		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = $this->create( $name );
			$this->purge_except( $name );
		}

		$this->memo[ $day ] = $salt;

		return $salt;
	}

	/**
	 * Creates the day's salt atomically; concurrent requests all read the winner's value.
	 *
	 * @param string $name Option name.
	 */
	private function create( string $name ): string {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'yes')",
				$wpdb->options,
				$name,
				bin2hex( random_bytes( 32 ) )
			)
		);

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( $name, 'options' );

		return (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name )
		);
	}

	/**
	 * Deletes every other day's salt.
	 *
	 * @param string $keep Option name to keep.
	 */
	private function purge_except( string $keep ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s AND option_name <> %s',
				$wpdb->options,
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				$keep
			)
		);

		wp_cache_delete( 'alloptions', 'options' );
	}
}
