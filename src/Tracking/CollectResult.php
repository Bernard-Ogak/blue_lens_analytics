<?php
/**
 * Outcome of a collection request.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * What the collector did with a payload. Not exposed to the browser (the endpoint always answers 204).
 */
final class CollectResult {

	/**
	 * Constructor.
	 *
	 * @param string $status     "stored" or "ignored".
	 * @param string $reason     Why a payload was ignored.
	 * @param int    $stored     Events written.
	 * @param int    $session_id Session touched (0 when none).
	 */
	private function __construct(
		public readonly string $status,
		public readonly string $reason,
		public readonly int $stored,
		public readonly int $session_id
	) {}

	/**
	 * Payload processed.
	 *
	 * @param int $stored     Events written.
	 * @param int $session_id Session ID.
	 */
	public static function stored( int $stored, int $session_id ): self {
		return new self( 'stored', '', $stored, $session_id );
	}

	/**
	 * Payload dropped.
	 *
	 * @param string $reason Reason code.
	 */
	public static function ignored( string $reason ): self {
		return new self( 'ignored', $reason, 0, 0 );
	}
}
