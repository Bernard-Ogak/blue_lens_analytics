<?php
/**
 * Lightweight service container.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily builds and caches shared service instances, keyed by class name.
 */
final class Container {

	/**
	 * Service factories keyed by id.
	 *
	 * @var array<string, callable(Container): object>
	 */
	private array $factories = [];

	/**
	 * Built service instances keyed by id.
	 *
	 * @var array<string, object>
	 */
	private array $instances = [];

	/**
	 * Registers (or replaces) a service factory.
	 *
	 * @param string                     $id      Service id, normally the class name.
	 * @param callable(Container):object $factory Factory receiving this container.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Returns the shared instance for a service, building it on first use.
	 *
	 * @template T of object
	 * @param class-string<T> $id Service class name.
	 * @return T
	 *
	 * @throws \OutOfBoundsException     When the service is not registered.
	 * @throws \UnexpectedValueException When the factory returns the wrong type.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			/** @var T */
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \OutOfBoundsException( esc_html( sprintf( 'Blue Lens service "%s" is not registered.', $id ) ) );
		}

		$instance = ( $this->factories[ $id ] )( $this );

		if ( ! $instance instanceof $id ) {
			throw new \UnexpectedValueException( esc_html( sprintf( 'Blue Lens service factory for "%s" returned the wrong type.', $id ) ) );
		}

		$this->instances[ $id ] = $instance;

		return $instance;
	}
}
