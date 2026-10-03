<?php
/**
 * Server-side form submission tracking.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Modules\Forms;

use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Events\EventRegistry;
use BlueLens\Analytics\Tracking\Collector;
use BlueLens\Analytics\Tracking\RequestContext;

defined( 'ABSPATH' ) || exit;

/**
 * Records form_submit from each form plugin's own "submission succeeded" hook, so AJAX forms,
 * validation failures and spam rejections are counted correctly. Only the form identity, title and
 * field count are recorded, never field values.
 *
 * Form IDs use the same "plugin:id" format as the browser's form_start/form_abandon events, so the
 * three can be joined into a per-form funnel.
 */
final class FormIntegrations implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param Collector $collector Collector.
	 * @param Settings  $settings  Settings.
	 */
	public function __construct(
		private Collector $collector,
		private Settings $settings
	) {}

	/**
	 * Attaches hooks. Hooks of plugins that are not installed simply never fire.
	 */
	public function register_hooks(): void {
		add_action( 'wpcf7_mail_sent', [ $this, 'contact_form_7' ] );
		add_action( 'gform_after_submission', [ $this, 'gravity_forms' ], 10, 2 );
		add_action( 'wpforms_process_complete', [ $this, 'wpforms' ], 10, 3 );
		add_action( 'fluentform/submission_inserted', [ $this, 'fluent_forms' ], 10, 3 );
		add_action( 'elementor_pro/forms/new_record', [ $this, 'elementor' ], 10, 1 );
		add_action( 'frm_after_create_entry', [ $this, 'formidable' ], 30, 2 );
		add_action( 'ninja_forms_after_submission', [ $this, 'ninja_forms' ] );
	}

	/**
	 * Contact Form 7.
	 *
	 * @param object $form WPCF7_ContactForm.
	 */
	public function contact_form_7( object $form ): void {
		$id    = method_exists( $form, 'id' ) ? (int) $form->id() : 0;
		$title = method_exists( $form, 'title' ) ? (string) $form->title() : '';
		$count = 0;
		if ( method_exists( $form, 'scan_form_tags' ) ) {
			$count = count( array_filter( (array) $form->scan_form_tags(), static fn( $tag ): bool => is_object( $tag ) && ! empty( $tag->name ) ) );
		}

		$this->record( 'cf7', $id, $title, $count );
	}

	/**
	 * Gravity Forms.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param array<string, mixed> $form  Form.
	 */
	public function gravity_forms( $entry, $form ): void {
		if ( ! is_array( $form ) ) {
			return;
		}

		$this->record( 'gf', (int) ( $form['id'] ?? 0 ), (string) ( $form['title'] ?? '' ), count( (array) ( $form['fields'] ?? [] ) ) );
	}

	/**
	 * WPForms.
	 *
	 * @param array<mixed>         $fields    Submitted fields.
	 * @param array<mixed>         $entry     Entry.
	 * @param array<string, mixed> $form_data Form data.
	 */
	public function wpforms( $fields, $entry, $form_data ): void {
		if ( ! is_array( $form_data ) ) {
			return;
		}

		$this->record(
			'wpforms',
			(int) ( $form_data['id'] ?? 0 ),
			(string) ( $form_data['settings']['form_title'] ?? '' ),
			is_array( $fields ) ? count( $fields ) : 0
		);
	}

	/**
	 * Fluent Forms.
	 *
	 * @param int|string   $entry_id  Entry ID.
	 * @param array<mixed> $form_data Submitted data.
	 * @param object       $form      Form model.
	 */
	public function fluent_forms( $entry_id, $form_data, $form ): void {
		if ( ! is_object( $form ) ) {
			return;
		}

		$this->record( 'fluent', (int) ( $form->id ?? 0 ), (string) ( $form->title ?? '' ), is_array( $form_data ) ? count( $form_data ) : 0 );
	}

	/**
	 * Elementor Pro forms.
	 *
	 * @param object $record Form record.
	 */
	public function elementor( object $record ): void {
		if ( ! method_exists( $record, 'get_form_settings' ) ) {
			return;
		}

		$id     = (string) $record->get_form_settings( 'id' );
		$name   = (string) $record->get_form_settings( 'form_name' );
		$fields = method_exists( $record, 'get' ) ? (array) $record->get( 'fields' ) : [];

		$this->record( 'elementor', $id, $name, count( $fields ) );
	}

	/**
	 * Formidable Forms.
	 *
	 * @param int|string $entry_id Entry ID.
	 * @param int|string $form_id  Form ID.
	 */
	public function formidable( $entry_id, $form_id ): void {
		$title = '';
		if ( class_exists( '\FrmForm' ) ) {
			$form  = \FrmForm::getOne( $form_id );
			$title = is_object( $form ) && isset( $form->name ) ? (string) $form->name : '';
		}

		$this->record( 'frm', (int) $form_id, $title, 0 );
	}

	/**
	 * Ninja Forms.
	 *
	 * @param array<string, mixed> $form_data Submission data.
	 */
	public function ninja_forms( $form_data ): void {
		if ( ! is_array( $form_data ) ) {
			return;
		}

		$this->record(
			'nf',
			(int) ( $form_data['form_id'] ?? 0 ),
			(string) ( $form_data['settings']['title'] ?? '' ),
			count( (array) ( $form_data['fields'] ?? [] ) )
		);
	}

	/**
	 * Records the submission. Failures never interrupt the form plugin.
	 *
	 * @param string     $plugin Plugin prefix.
	 * @param int|string $id     Form ID.
	 * @param string     $title  Form title.
	 * @param int        $fields Field count.
	 */
	private function record( string $plugin, int|string $id, string $title, int $fields ): void {
		if ( ! $this->settings->get( 'track_forms' ) ) {
			return;
		}

		$form_id = self::form_id( $plugin, $id );

		try {
			$this->collector->record_server_event(
				[
					'event'       => EventRegistry::FORM_SUBMIT,
					'entity_type' => 'form',
					'entity_id'   => $form_id,
					'attributes'  => array_filter(
						[
							'form_plugin' => $plugin,
							'form_title'  => mb_substr( wp_strip_all_tags( $title ), 0, 100 ),
							'field_count' => $fields,
						]
					),
				],
				RequestContext::from_globals( (string) $this->settings->get( 'ip_header' ) )
			);
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Blue Lens form tracking failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		/**
		 * Fires after a supported form plugin reports a successful submission.
		 *
		 * @param string $form_id Form ID ("plugin:id").
		 * @param string $plugin  Plugin prefix.
		 */
		do_action( 'blue_lens_form_submitted', $form_id, $plugin );
	}

	/**
	 * Canonical form ID.
	 *
	 * @param string     $plugin Plugin prefix.
	 * @param int|string $id     Plugin form ID.
	 */
	public static function form_id( string $plugin, int|string $id ): string {
		$clean = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $id );

		return substr( $plugin . ':' . ( '' === $clean ? '0' : $clean ), 0, 64 );
	}
}
