<?php
/**
 * Settings tests.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tests\Integration;

use BlueLens\Analytics\Core\Plugin;
use BlueLens\Analytics\Core\Settings;
use WP_UnitTestCase;

/**
 * @covers \BlueLens\Analytics\Core\Settings
 */
final class SettingsTest extends WP_UnitTestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = Plugin::instance()->container()->get( Settings::class );
		delete_option( Settings::OPTION );
		$this->settings->ensure_defaults();
	}

	public function test_defaults_are_privacy_first(): void {
		$all = $this->settings->all();

		$this->assertSame( Settings::MODE_COOKIELESS, $all['privacy_mode'] );
		$this->assertTrue( $all['respect_gpc'] );
		$this->assertFalse( $all['delete_data_on_uninstall'] );
		$this->assertSame( 13, $all['retention_raw_months'] );
		$this->assertSame( [ 'administrator', 'editor' ], $all['excluded_roles'] );
	}

	public function test_unknown_keys_are_dropped(): void {
		$clean = $this->settings->sanitize( [ 'evil' => 'x' ] );

		$this->assertArrayNotHasKey( 'evil', $clean );
		$this->assertSame( array_keys( $this->settings->schema() ), array_keys( $clean ) );
	}

	public function test_invalid_values_fall_back_or_clamp(): void {
		$clean = $this->settings->sanitize(
			[
				'privacy_mode'         => 'spy-mode',
				'retention_raw_months' => '500',
				'base_currency'        => 'kesx',
				'tracking_enabled'     => 'false',
			]
		);

		$this->assertSame( Settings::MODE_COOKIELESS, $clean['privacy_mode'] );
		$this->assertSame( 120, $clean['retention_raw_months'] );
		$this->assertSame( 'USD', $clean['base_currency'] );
		$this->assertFalse( $clean['tracking_enabled'] );
	}

	public function test_currency_is_uppercased(): void {
		$this->assertSame( 'KES', $this->settings->sanitize( [ 'base_currency' => ' kes ' ] )['base_currency'] );
	}

	public function test_ip_list_accepts_ips_and_cidrs_only(): void {
		$clean = $this->settings->sanitize(
			[ 'excluded_ips' => "10.0.0.1\n192.168.0.0/16, 2001:db8::/32\nnot-an-ip\n10.0.0.0/33" ]
		);

		$this->assertSame( [ '10.0.0.1', '192.168.0.0/16', '2001:db8::/32' ], $clean['excluded_ips'] );
	}

	public function test_path_list_is_normalized(): void {
		$clean = $this->settings->sanitize(
			[ 'excluded_paths' => [ 'checkout/*', '/thank-you/', '<script>', '/thank-you/' ] ]
		);

		$this->assertSame( [ '/checkout/*', '/thank-you/', '/script' ], $clean['excluded_paths'] );
	}

	public function test_update_merges_and_persists(): void {
		$this->settings->update( [ 'respect_dnt' => true ] );
		$this->settings->flush();

		$this->assertTrue( $this->settings->get( 'respect_dnt' ) );
		$this->assertSame( Settings::MODE_COOKIELESS, $this->settings->get( 'privacy_mode' ) );
	}

	public function test_direct_update_option_is_sanitized(): void {
		update_option( Settings::OPTION, [ 'privacy_mode' => 'bogus' ] );

		$stored = get_option( Settings::OPTION );
		$this->assertSame( Settings::MODE_COOKIELESS, $stored['privacy_mode'] );
	}

	public function test_ensure_defaults_backfills_new_fields(): void {
		update_option( Settings::OPTION, [ 'respect_dnt' => true ] );
		$this->settings->ensure_defaults();

		$stored = get_option( Settings::OPTION );
		$this->assertTrue( $stored['respect_dnt'] );
		$this->assertArrayHasKey( 'retention_raw_months', $stored );
	}
}
