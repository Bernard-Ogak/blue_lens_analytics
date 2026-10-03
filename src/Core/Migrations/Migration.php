<?php
/**
 * Database migration contract.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * One forward-only schema change. Migrations must be idempotent: re-running up() must be safe.
 */
interface Migration {

	/**
	 * Schema version this migration brings the database to. Unique, increasing integers.
	 */
	public function version(): int;

	/**
	 * Short human-readable description for logs.
	 */
	public function description(): string;

	/**
	 * Applies the change.
	 *
	 * @throws \RuntimeException When the change could not be applied.
	 */
	public function up(): void;
}
