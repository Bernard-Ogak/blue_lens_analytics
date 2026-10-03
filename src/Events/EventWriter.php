<?php
/**
 * Batched event inserts.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Events;

use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Writes event rows to bla_events with one multi-row INSERT per batch.
 *
 * Binary keys are passed as hex and converted with UNHEX(): raw bytes in a query string trip
 * wpdb's charset validation and the query is silently discarded.
 *
 * @phpstan-type Row array{occurred_at: string, session_id: int, visitor_key: string|null, event_name: string, category: string, module: string, origin: int, entity_type: string, entity_id: string, post_id: int, post_type: string, page_path: string, page_title: string, event_value: float|null, currency: string, event_value_base: float|null, is_conversion: bool, lead_ref: string, attributes: array<string, mixed>, context: array<string, mixed>|null}
 */
final class EventWriter {

	public const ORIGIN_BROWSER = 0;
	public const ORIGIN_SERVER  = 1;
	public const ORIGIN_IMPORT  = 2;

	/**
	 * Inserts rows.
	 *
	 * @param list<Row> $rows Event rows.
	 * @return int Rows inserted.
	 */
	public function insert( array $rows ): int {
		global $wpdb;

		if ( ! $rows ) {
			return 0;
		}

		$values = [];
		foreach ( $rows as $row ) {
			$values[] = $this->row_sql( $row );
		}

		$sql = $wpdb->prepare(
			'INSERT INTO %i (occurred_at, session_id, visitor_key, event_name, category, module, origin, entity_type, entity_id, post_id, post_type, page_path, page_title, event_value, currency, event_value_base, is_conversion, lead_ref, attributes, context) VALUES ',
			Tables::name( Tables::EVENTS )
		) . implode( ',', $values );

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each VALUES tuple is prepared in row_sql().

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * One prepared VALUES tuple.
	 *
	 * @param Row $row Event row.
	 */
	private function row_sql( array $row ): string {
		global $wpdb;

		$flags      = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$attributes = $row['attributes'] ? wp_json_encode( $row['attributes'], $flags ) : false;
		$context    = $row['context'] ? wp_json_encode( $row['context'], $flags ) : false;

		$parts = [
			$wpdb->prepare( '%s', $row['occurred_at'] ),
			$wpdb->prepare( '%d', $row['session_id'] ),
			null === $row['visitor_key'] ? 'NULL' : $wpdb->prepare( 'UNHEX(%s)', bin2hex( $row['visitor_key'] ) ),
			$wpdb->prepare( '%s', $row['event_name'] ),
			$wpdb->prepare( '%s', $row['category'] ),
			$wpdb->prepare( '%s', $row['module'] ),
			$wpdb->prepare( '%d', $row['origin'] ),
			$wpdb->prepare( '%s', $row['entity_type'] ),
			$wpdb->prepare( '%s', $row['entity_id'] ),
			$wpdb->prepare( '%d', $row['post_id'] ),
			$wpdb->prepare( '%s', $row['post_type'] ),
			$wpdb->prepare( '%s', $row['page_path'] ),
			$wpdb->prepare( '%s', $row['page_title'] ),
			null === $row['event_value'] ? 'NULL' : $wpdb->prepare( '%f', $row['event_value'] ),
			$wpdb->prepare( '%s', $row['currency'] ),
			null === $row['event_value_base'] ? 'NULL' : $wpdb->prepare( '%f', $row['event_value_base'] ),
			$row['is_conversion'] ? '1' : '0',
			$wpdb->prepare( '%s', $row['lead_ref'] ),
			false === $attributes ? 'NULL' : $wpdb->prepare( '%s', $attributes ),
			false === $context ? 'NULL' : $wpdb->prepare( '%s', $context ),
		];

		return '(' . implode( ',', $parts ) . ')';
	}
}
