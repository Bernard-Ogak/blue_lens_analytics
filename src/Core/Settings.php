<?php
/**
 * Plugin settings: schema, defaults, sanitization and storage.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stores all settings in one small autoloaded option, validated against a schema.
 *
 * Supported field types: bool, int (min/max), enum (choices), currency, role_list, ip_list, path_list.
 * A field may also supply its own `sanitize` callable.
 *
 * @phpstan-type Field array{type: string, default: mixed, choices?: list<string>, min?: int, max?: int, max_items?: int, sanitize?: callable}
 */
final class Settings implements Hookable {

	public const OPTION = 'blue_lens_settings';

	public const MODE_COOKIELESS = 'cookieless';
	public const MODE_ENHANCED   = 'enhanced';

	public const SECRET_MASK = '••••••••';

	/**
	 * Resolved settings per blog ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $cache = [];

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'sanitize_option_' . self::OPTION, [ $this, 'sanitize_option' ] );
		add_action( 'add_option_' . self::OPTION, [ $this, 'flush' ] );
		add_action( 'update_option_' . self::OPTION, [ $this, 'flush' ] );
		add_action( 'delete_option_' . self::OPTION, [ $this, 'flush' ] );
	}

	/**
	 * Settings schema.
	 *
	 * @return array<string, Field>
	 */
	public function schema(): array {
		$schema = [
			// Master switch for front-end collection.
			'tracking_enabled'           => [
				'type'    => 'bool',
				'default' => true,
			],
			// Cookieless is the default; enhanced (first-party ID) only applies after consent.
			'privacy_mode'               => [
				'type'    => 'enum',
				'default' => self::MODE_COOKIELESS,
				'choices' => [ self::MODE_COOKIELESS, self::MODE_ENHANCED ],
			],
			'respect_gpc'                => [
				'type'    => 'bool',
				'default' => true,
			],
			// What a respected GPC signal does: "cookieless" (no ID, no click IDs, no ad exports) or "stop".
			'gpc_action'                 => [
				'type'    => 'enum',
				'default' => 'cookieless',
				'choices' => [ 'cookieless', 'stop' ],
			],
			'respect_dnt'                => [
				'type'    => 'bool',
				'default' => false,
			],
			// Automatic events (Phase 3). Each group ships in a separate tracker chunk.
			'track_links'                => [
				'type'    => 'bool',
				'default' => true,
			],
			'track_forms'                => [
				'type'    => 'bool',
				'default' => true,
			],
			'track_video'                => [
				'type'    => 'bool',
				'default' => true,
			],
			'autocapture'                => [
				'type'    => 'bool',
				'default' => true,
			],
			'heatmaps'                   => [
				'type'    => 'bool',
				'default' => true,
			],
			'track_errors'               => [
				'type'    => 'bool',
				'default' => false,
			],
			'web_vitals'                 => [
				'type'    => 'bool',
				'default' => false,
			],
			'download_extensions'        => [
				'type'      => 'ext_list',
				'default'   => [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'zip', 'rar', '7z', 'epub', 'mp3', 'mp4', 'mov', 'gpx', 'kml', 'apk', 'dmg', 'exe' ],
				'max_items' => 60,
			],
			// Extra CSS selectors counted as call-to-action clicks (in addition to [data-bla-event]).
			'cta_selectors'              => [
				'type'      => 'selector_list',
				'default'   => [],
				'max_items' => 50,
			],
			'excluded_roles'             => [
				'type'      => 'role_list',
				'default'   => [ 'administrator', 'editor' ],
				'max_items' => 50,
			],
			'excluded_ips'               => [
				'type'      => 'ip_list',
				'default'   => [],
				'max_items' => 200,
			],
			'excluded_paths'             => [
				'type'      => 'path_list',
				'default'   => [],
				'max_items' => 200,
			],
			'retention_raw_months'       => [
				'type'    => 'int',
				'default' => 13,
				'min'     => 1,
				'max'     => 120,
			],
			// 0 keeps aggregates forever.
			'retention_aggregate_months' => [
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'max'     => 600,
			],
			'base_currency'              => [
				'type'    => 'currency',
				'default' => $this->default_currency(),
			],
			// Site audit: most URLs per audit, and an optional weekly automatic audit.
			'audit_max_pages'            => [
				'type'    => 'int',
				'default' => 250,
				'min'     => 10,
				'max'     => 2000,
			],
			'audit_weekly'               => [
				'type'    => 'bool',
				'default' => false,
			],
			'delete_data_on_uninstall'   => [
				'type'    => 'bool',
				'default' => false,
			],
			// Header holding the visitor IP. Only change behind a trusted proxy/CDN, or visitors can spoof it.
			'ip_header'                  => [
				'type'    => 'enum',
				'default' => 'remote_addr',
				'choices' => [ 'remote_addr', 'http_cf_connecting_ip', 'http_x_forwarded_for', 'http_x_real_ip', 'http_true_client_ip' ],
			],
			'session_timeout_minutes'    => [
				'type'    => 'int',
				'default' => 30,
				'min'     => 5,
				'max'     => 240,
			],
			// Engaged-time heartbeat interval; 0 disables heartbeats (engagement is still sent on page exit).
			'heartbeat_seconds'          => [
				'type'    => 'int',
				'default' => 30,
				'min'     => 0,
				'max'     => 300,
			],
			'spa_tracking'               => [
				'type'    => 'bool',
				'default' => true,
			],
			'log_crawlers'               => [
				'type'    => 'bool',
				'default' => true,
			],
			'geoip_provider'             => [
				'type'    => 'enum',
				'default' => 'none',
				'choices' => [ 'none', 'maxmind', 'dbip' ],
			],
			'maxmind_account_id'         => [
				'type'    => 'string',
				'default' => '',
				'max'     => 20,
			],
			'maxmind_license_key'        => [
				'type'    => 'secret',
				'default' => '',
				'max'     => 64,
			],
		];

		/**
		 * Filters the settings schema. Added fields are sanitized and stored automatically.
		 *
		 * @param array<string, array<string, mixed>> $schema Settings schema.
		 */
		return (array) apply_filters( 'blue_lens_settings_schema', $schema );
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array_map( static fn( array $field ): mixed => $field['default'], $this->schema() );
	}

	/**
	 * All settings for the current site, stored values merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$blog_id = get_current_blog_id();

		if ( ! isset( $this->cache[ $blog_id ] ) ) {
			$stored                  = get_option( self::OPTION, [] );
			$this->cache[ $blog_id ] = $this->sanitize( is_array( $stored ) ? $stored : [] );
		}

		return $this->cache[ $blog_id ];
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed Null for unknown keys.
	 */
	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Settings safe to send to the browser: secret values are replaced by a mask.
	 *
	 * @return array<string, mixed>
	 */
	public function public_view(): array {
		$all = $this->all();

		foreach ( $this->schema() as $key => $field ) {
			if ( 'secret' === $field['type'] && '' !== $all[ $key ] ) {
				$all[ $key ] = self::SECRET_MASK;
			}
		}

		return $all;
	}

	/**
	 * Merges and saves the given values. Unknown keys are ignored; a masked secret keeps its stored value.
	 *
	 * @param array<string, mixed> $values Partial settings.
	 * @return array<string, mixed> The saved settings.
	 */
	public function update( array $values ): array {
		foreach ( $this->schema() as $key => $field ) {
			if ( 'secret' === $field['type'] && self::SECRET_MASK === ( $values[ $key ] ?? null ) ) {
				unset( $values[ $key ] );
			}
		}

		$settings = $this->sanitize( array_merge( $this->all(), $values ) );

		update_option( self::OPTION, $settings, true );
		$this->cache[ get_current_blog_id() ] = $settings;

		/**
		 * Fires after Blue Lens settings are saved.
		 *
		 * @param array<string, mixed> $settings Saved settings.
		 */
		do_action( 'blue_lens_settings_updated', $settings );

		return $settings;
	}

	/**
	 * Creates the option with defaults, or backfills fields added in newer versions.
	 */
	public function ensure_defaults(): void {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			add_option( self::OPTION, $this->defaults(), '', true );
		} elseif ( array_diff_key( $this->schema(), $stored ) ) {
			update_option( self::OPTION, $this->sanitize( $stored ), true );
		}

		$this->flush();
	}

	/**
	 * Clears the in-memory cache.
	 */
	public function flush(): void {
		$this->cache = [];
	}

	/**
	 * Callback for sanitize_option_blue_lens_settings.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<string, mixed>
	 */
	public function sanitize_option( mixed $value ): array {
		return $this->sanitize( is_array( $value ) ? $value : [] );
	}

	/**
	 * Returns a complete, valid settings array: known keys only, invalid values replaced by defaults.
	 *
	 * @param array<mixed> $input Raw settings.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$output = [];

		foreach ( $this->schema() as $key => $field ) {
			$output[ $key ] = array_key_exists( $key, $input )
				? $this->sanitize_field( $field, $input[ $key ] )
				: $field['default'];
		}

		return $output;
	}

	/**
	 * Sanitizes one value against its field definition.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Raw value.
	 */
	private function sanitize_field( array $field, mixed $value ): mixed {
		if ( isset( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
			return call_user_func( $field['sanitize'], $value, $field );
		}

		$default   = $field['default'];
		$max_items = isset( $field['max_items'] ) ? (int) $field['max_items'] : 100;

		switch ( $field['type'] ) {
			case 'bool':
				return wp_validate_boolean( $value );

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return $default;
				}
				$int = (int) $value;
				if ( isset( $field['min'] ) ) {
					$int = max( (int) $field['min'], $int );
				}
				if ( isset( $field['max'] ) ) {
					$int = min( (int) $field['max'], $int );
				}
				return $int;

			case 'enum':
				$choice = is_string( $value ) ? sanitize_key( $value ) : '';
				return in_array( $choice, (array) ( $field['choices'] ?? [] ), true ) ? $choice : $default;

			case 'currency':
				$code = is_string( $value ) ? strtoupper( trim( $value ) ) : '';
				return 1 === preg_match( '/^[A-Z]{3}$/', $code ) ? $code : $default;

			case 'string':
			case 'secret':
				if ( ! is_scalar( $value ) ) {
					return $default;
				}
				return substr( sanitize_text_field( (string) $value ), 0, isset( $field['max'] ) ? (int) $field['max'] : 255 );

			case 'role_list':
				return $this->sanitize_list( $value, $max_items, static fn( string $item ): string => sanitize_key( $item ) );

			case 'ip_list':
				return $this->sanitize_list( $value, $max_items, [ self::class, 'sanitize_ip_or_cidr' ] );

			case 'path_list':
				return $this->sanitize_list( $value, $max_items, [ self::class, 'sanitize_path_pattern' ] );

			case 'ext_list':
				return $this->sanitize_list(
					$value,
					$max_items,
					static fn( string $item ): string => 1 === preg_match( '/^[a-z0-9]{1,8}$/', strtolower( ltrim( $item, '.' ) ) ) ? strtolower( ltrim( $item, '.' ) ) : ''
				);

			case 'selector_list':
				return $this->sanitize_list(
					$value,
					$max_items,
					static fn( string $item ): string => 1 === preg_match( '/^[A-Za-z0-9 _\-.#\[\]=:"\'>+~*(),^$|]{1,200}$/', $item ) ? $item : ''
				);
		}

		return $default;
	}

	/**
	 * Sanitizes a list given as an array or a newline/comma separated string.
	 *
	 * @param mixed                   $value     Raw list.
	 * @param int                     $max_items Maximum entries kept.
	 * @param callable(string):string $sanitize  Item sanitizer; empty result drops the item.
	 * @return list<string>
	 */
	private function sanitize_list( mixed $value, int $max_items, callable $sanitize ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return [];
		}

		$items = [];
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$clean = $sanitize( trim( (string) $item ) );
			if ( '' !== $clean ) {
				$items[] = $clean;
			}
		}

		return array_slice( array_values( array_unique( $items ) ), 0, $max_items );
	}

	/**
	 * Returns a normalized IPv4/IPv6 address or CIDR range, or '' when invalid.
	 *
	 * @param string $value Candidate.
	 */
	public static function sanitize_ip_or_cidr( string $value ): string {
		$parts = explode( '/', $value, 2 );
		$ip    = filter_var( $parts[0], FILTER_VALIDATE_IP );

		if ( false === $ip ) {
			return '';
		}
		if ( ! isset( $parts[1] ) ) {
			return $ip;
		}

		$max_bits = str_contains( $ip, ':' ) ? 128 : 32;
		if ( 1 !== preg_match( '/^\d{1,3}$/', $parts[1] ) || (int) $parts[1] > $max_bits ) {
			return '';
		}

		return $ip . '/' . (int) $parts[1];
	}

	/**
	 * Returns a path pattern such as /checkout/* or /thank-you/, or '' when invalid.
	 *
	 * @param string $value Candidate.
	 */
	public static function sanitize_path_pattern( string $value ): string {
		$path = (string) preg_replace( '/[^A-Za-z0-9\-._~\/*%]/', '', $value );

		if ( '' === $path ) {
			return '';
		}

		return substr( '/' . ltrim( $path, '/' ), 0, 255 );
	}

	/**
	 * Default base currency: WooCommerce's store currency when available, else USD.
	 */
	private function default_currency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$code = strtoupper( (string) get_woocommerce_currency() );
			if ( 1 === preg_match( '/^[A-Z]{3}$/', $code ) ) {
				return $code;
			}
		}

		return 'USD';
	}
}
