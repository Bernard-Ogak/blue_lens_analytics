<?php
/**
 * Session persistence.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes bla_sessions. A session is a visitor's hits with gaps shorter than the timeout.
 *
 * @phpstan-type ActiveSession array{id: int, started_at: string, utm_source: string, utm_medium: string, utm_campaign: string, click_id_type: string, event_count: int}
 */
final class SessionManager {

	/**
	 * Engagement thresholds: a session is engaged after this many engaged seconds, pageviews, or any conversion.
	 */
	public const ENGAGED_SECONDS   = 10;
	public const ENGAGED_PAGEVIEWS = 2;

	/**
	 * Latest session for a visitor still inside the inactivity window.
	 *
	 * @param string $visitor_key 16-byte key.
	 * @param string $since       UTC datetime; sessions last seen before it are expired.
	 * @return ActiveSession|null
	 */
	public function find_active( string $visitor_key, string $since ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, started_at, utm_source, utm_medium, utm_campaign, click_id_type, event_count FROM %i WHERE visitor_key = UNHEX(%s) AND last_seen_at >= %s ORDER BY last_seen_at DESC LIMIT 1',
				Tables::name( Tables::SESSIONS ),
				bin2hex( $visitor_key ),
				$since
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'id'            => (int) $row['id'],
			'started_at'    => (string) $row['started_at'],
			'utm_source'    => (string) $row['utm_source'],
			'utm_medium'    => (string) $row['utm_medium'],
			'utm_campaign'  => (string) $row['utm_campaign'],
			'click_id_type' => (string) $row['click_id_type'],
			'event_count'   => (int) $row['event_count'],
		];
	}

	/**
	 * Session by its public key.
	 *
	 * @param string $session_key 16-byte key.
	 * @return array{id: int, visitor_key: string}|null
	 */
	public function find_by_key( string $session_key ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, HEX(visitor_key) AS visitor_hex FROM %i WHERE session_key = UNHEX(%s)',
				Tables::name( Tables::SESSIONS ),
				bin2hex( $session_key )
			),
			ARRAY_A
		);

		return is_array( $row )
			? [
				'id'          => (int) $row['id'],
				'visitor_key' => (string) hex2bin( (string) $row['visitor_hex'] ),
			]
			: null;
	}

	/**
	 * Whether the visitor had a session before the given time (enhanced mode "returning" flag).
	 *
	 * @param string $visitor_key 16-byte key.
	 * @param string $before      UTC datetime.
	 */
	public function has_previous( string $visitor_key, string $before ): bool {
		global $wpdb;

		return null !== $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE visitor_key = UNHEX(%s) AND started_at < %s LIMIT 1',
				Tables::name( Tables::SESSIONS ),
				bin2hex( $visitor_key ),
				$before
			)
		);
	}

	/**
	 * Creates a session.
	 *
	 * @param array<string, string|int|null> $data Column values; binary keys as raw bytes.
	 * @return int Session ID, 0 on failure.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$binary = [ 'session_key', 'visitor_key' ];
		$ints   = [ 'id_mode', 'is_returning', 'landing_post_id', 'is_logged_in' ];

		$columns = [];
		$values  = [];
		foreach ( $data as $column => $value ) {
			if ( 1 !== preg_match( '/^[a-z_]+$/', $column ) ) {
				continue;
			}
			$columns[] = $column;

			if ( null === $value ) {
				$values[] = 'NULL';
			} elseif ( in_array( $column, $binary, true ) ) {
				$values[] = $wpdb->prepare( 'UNHEX(%s)', bin2hex( (string) $value ) );
			} elseif ( in_array( $column, $ints, true ) ) {
				$values[] = $wpdb->prepare( '%d', (int) $value );
			} else {
				$values[] = $wpdb->prepare( '%s', (string) $value );
			}
		}

		$sql = $wpdb->prepare( 'INSERT INTO %i ', Tables::name( Tables::SESSIONS ) )
			. '(' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ')';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column names are validated, values prepared above.
		if ( false === $wpdb->query( $sql ) ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Applies counters from one collection request.
	 *
	 * The is_engaged and duration expressions come first in SET so they read pre-update values on
	 * both MySQL (left-to-right assignment) and MariaDB with SIMULTANEOUS_ASSIGNMENT.
	 *
	 * @param int    $session_id  Session ID.
	 * @param string $now         UTC datetime.
	 * @param int    $pageviews   Pageviews in this batch.
	 * @param int    $events      Events in this batch.
	 * @param int    $engaged     Engaged seconds to add.
	 * @param int    $conversions Conversions in this batch.
	 * @param string $exit_path   Latest pageview path, '' to keep.
	 */
	public function touch( int $session_id, string $now, int $pageviews, int $events, int $engaged, int $conversions, string $exit_path ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET
					is_engaged = IF(is_engaged = 1 OR engaged_seconds + %d > %d OR pageviews + %d >= %d OR conversions + %d > 0, 1, 0),
					duration_seconds = GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, %s)),
					last_seen_at = GREATEST(last_seen_at, %s),
					pageviews = LEAST(pageviews + %d, 65535),
					event_count = event_count + %d,
					engaged_seconds = LEAST(engaged_seconds + %d, 86400),
					conversions = LEAST(conversions + %d, 65535),
					exit_path = IF(%s = \'\', exit_path, %s)
				WHERE id = %d',
				Tables::name( Tables::SESSIONS ),
				$engaged,
				self::ENGAGED_SECONDS,
				$pageviews,
				self::ENGAGED_PAGEVIEWS,
				$conversions,
				$now,
				$now,
				$pageviews,
				$events,
				$engaged,
				$conversions,
				$exit_path,
				$exit_path,
				$session_id
			)
		);
	}
}
