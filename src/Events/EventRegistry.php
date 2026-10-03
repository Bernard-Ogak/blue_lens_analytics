<?php
/**
 * Known event definitions.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of named events. Unregistered names are still accepted as custom events.
 * Industry profiles (Phase 5) add their events through the blue_lens_event_registry filter.
 *
 * @phpstan-type Definition array{category: string, module: string, conversion: bool}
 */
final class EventRegistry {

	public const PAGE_VIEW       = 'page_view';
	public const PAGE_ENGAGEMENT = 'page_engagement';
	public const FORM_SUBMIT     = 'form_submit';

	/**
	 * Resolved definitions.
	 *
	 * @var array<string, Definition>|null
	 */
	private ?array $events = null;

	/**
	 * All definitions keyed by event name.
	 *
	 * @return array<string, Definition>
	 */
	public function all(): array {
		if ( null !== $this->events ) {
			return $this->events;
		}

		$events = [
			self::PAGE_VIEW       => [
				'category'   => 'page',
				'module'     => 'core',
				'conversion' => false,
			],
			self::PAGE_ENGAGEMENT => [
				'category'   => 'engagement',
				'module'     => 'core',
				'conversion' => false,
			],
		];

		// Automatic events: name => category. Form submissions count as conversions until goals
		// are configured in onboarding (Phase 6), which can reclassify newsletter or search forms.
		$automatic = [
			'outbound_click'  => 'navigation',
			'file_download'   => 'download',
			'contact_click'   => 'contact',
			'cta_click'       => 'cta',
			'site_search'     => 'search',
			'page_not_found'  => 'error',
			'js_error'        => 'error',
			'web_vitals'      => 'performance',
			'video_start'     => 'video',
			'video_progress'  => 'video',
			'video_complete'  => 'video',
			'form_start'      => 'form',
			'form_abandon'    => 'form',
			self::FORM_SUBMIT => 'form',
			'click'           => 'interaction',
			'rage_click'      => 'interaction',
			'dead_click'      => 'interaction',
			'copy_text'       => 'interaction',
		];
		foreach ( $automatic as $name => $category ) {
			$events[ $name ] = [
				'category'   => $category,
				'module'     => 'core',
				'conversion' => self::FORM_SUBMIT === $name,
			];
		}

		/**
		 * Filters registered events.
		 *
		 * @param array<string, array{category: string, module: string, conversion: bool}> $events Definitions.
		 */
		$filtered = (array) apply_filters( 'blue_lens_event_registry', $events );

		$this->events = [];
		foreach ( $filtered as $name => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$this->events[ (string) $name ] = [
				'category'   => sanitize_key( (string) ( $definition['category'] ?? 'custom' ) ),
				'module'     => sanitize_key( (string) ( $definition['module'] ?? 'core' ) ),
				'conversion' => ! empty( $definition['conversion'] ),
			];
		}

		return $this->events;
	}

	/**
	 * One definition.
	 *
	 * @param string $name Event name.
	 * @return Definition|null
	 */
	public function get( string $name ): ?array {
		return $this->all()[ $name ] ?? null;
	}

	/**
	 * Drops the cached definitions (after profiles change).
	 */
	public function reset(): void {
		$this->events = null;
	}
}
