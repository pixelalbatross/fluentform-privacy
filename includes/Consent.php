<?php

namespace Outstand\WP\FluentForm\Addons\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Captures a consent + language snapshot on every Fluent Forms submission.
 *
 * A consent checkbox stores a boolean, which proves nothing once the policy is reworded. This
 * snapshots the wording of every consent field (`gdpr_agreement`/`terms_and_condition`) as it read
 * at submission time — and whether that field was actually accepted, read from the submitted value —
 * plus the submission language, onto the entry's submission-meta: the record that makes consent
 * demonstrable.
 */
class Consent extends BaseModule {

	/**
	 * Submission-meta key holding the rendered consent wording captured at submission time.
	 */
	public const META_CONSENT = '_os_ff_privacy_consent';

	/**
	 * Submission-meta key holding the language the form was submitted in.
	 */
	public const META_LANGUAGE = '_os_ff_privacy_language';

	/**
	 * Fluent Forms field elements that carry consent wording.
	 */
	private const CONSENT_ELEMENTS = [ 'gdpr_agreement', 'terms_and_condition' ];

	/**
	 * Registers nothing unless Fluent Forms is active.
	 */
	public function can_register(): bool {
		return class_exists( \FluentForm\App\Services\Submission\SubmissionService::class );
	}

	/**
	 * Hooks consent capture onto every submission.
	 */
	public function register(): void {
		add_action( 'fluentform/submission_inserted', [ $this, 'capture' ], 20, 3 );
	}

	/**
	 * Snapshots each consent field's wording, whether it was accepted, and the submission language
	 * onto the entry. Storing the accepted state — read from the submitted value — is what makes the
	 * snapshot proof of consent rather than proof the field merely existed.
	 *
	 * @param  int       $entry_id  The new submission id.
	 * @param  array     $form_data The submitted data, keyed by field name.
	 * @param  \stdClass $form      The form object.
	 * @return void
	 */
	public function capture( int $entry_id, array $form_data, $form ): void {
		$form_id        = (int) ( $form->id ?? 0 );
		$consent_fields = self::extract_consent_fields( (string) ( $form->form_fields ?? '' ) );

		if ( ! empty( $consent_fields ) ) {
			$snapshot = [];
			foreach ( $consent_fields as $field ) {
				$snapshot[] = [
					'text'     => $field['text'],
					'accepted' => self::is_accepted( $form_data, $field['name'] ),
				];
			}

			$this->set_meta( $entry_id, $form_id, self::META_CONSENT, (string) wp_json_encode( $snapshot ) );
		}

		$this->set_meta( $entry_id, $form_id, self::META_LANGUAGE, self::current_language() );
	}

	/**
	 * Walks a Fluent Forms `form_fields` JSON string and collects the name + label of every consent
	 * field, descending into the container/column nesting the builder produces.
	 *
	 * @param  string $form_fields_json The form's `form_fields` JSON.
	 * @return array<int,array{name:string,text:string}>
	 */
	public static function extract_consent_fields( string $form_fields_json ): array {
		$decoded = json_decode( $form_fields_json, true );

		if ( ! is_array( $decoded ) || empty( $decoded['fields'] ) || ! is_array( $decoded['fields'] ) ) {
			return [];
		}

		$fields = [];
		self::collect_consent_fields( $decoded['fields'], $fields );

		return $fields;
	}

	/**
	 * The consent wording of every consent field, ignoring names and acceptance.
	 *
	 * @param  string $form_fields_json The form's `form_fields` JSON.
	 * @return array<int,string>
	 */
	public static function extract_consent_texts( string $form_fields_json ): array {
		$texts = array_column( self::extract_consent_fields( $form_fields_json ), 'text' );

		return array_values( array_unique( array_filter( $texts ) ) );
	}

	/**
	 * The language the current request is served in, for the language tag stored on the entry.
	 *
	 * @return string
	 */
	protected static function current_language(): string {
		$language = apply_filters( 'wpml_current_language', null );

		if ( is_string( $language ) && '' !== $language ) {
			return $language;
		}

		return substr( (string) get_locale(), 0, 2 );
	}

	/**
	 * Whether a consent field was accepted, from its submitted value. An unchecked box submits an
	 * empty or explicitly-negative value; anything else counts as acceptance.
	 *
	 * @param  array<string,mixed> $form_data The submitted data, keyed by field name.
	 * @param  string              $name      The consent field's name.
	 * @return bool
	 */
	private static function is_accepted( array $form_data, string $name ): bool {
		if ( '' === $name || ! array_key_exists( $name, $form_data ) ) {
			return false;
		}

		$value = $form_data[ $name ];

		if ( is_array( $value ) ) {
			return ! empty( array_filter( $value ) );
		}

		return ! in_array( strtolower( trim( (string) $value ) ), [ '', '0', 'no', 'off', 'false' ], true );
	}

	/**
	 * Recursively gathers consent-field name + label pairs from a fields array.
	 *
	 * @param  array<int,mixed>                          $fields    The fields to walk.
	 * @param  array<int,array{name:string,text:string}> $collected Collected fields, by reference.
	 * @return void
	 */
	private static function collect_consent_fields( array $fields, array &$collected ): void {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$element = (string) ( $field['element'] ?? '' );

			if ( in_array( $element, self::CONSENT_ELEMENTS, true ) ) {
				$settings   = isset( $field['settings'] ) && is_array( $field['settings'] ) ? $field['settings'] : [];
				$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];
				$label      = (string) ( $settings['tnc_html'] ?? $settings['label'] ?? '' );
				$text       = trim( wp_strip_all_tags( $label ) );

				if ( '' !== $text ) {
					$collected[] = [
						'name' => (string) ( $attributes['name'] ?? '' ),
						'text' => $text,
					];
				}
			}

			// Container fields nest their inputs under columns[].fields[]; plain groups under fields[].
			if ( ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
				self::collect_consent_fields( $field['fields'], $collected );
			}

			if ( ! empty( $field['columns'] ) && is_array( $field['columns'] ) ) {
				foreach ( $field['columns'] as $column ) {
					if ( is_array( $column ) && ! empty( $column['fields'] ) && is_array( $column['fields'] ) ) {
						self::collect_consent_fields( $column['fields'], $collected );
					}
				}
			}
		}
	}

	/**
	 * Writes one Fluent Forms submission-meta row.
	 *
	 * @param  int    $entry_id The submission id (`response_id` on the meta table).
	 * @param  int    $form_id  The form id.
	 * @param  string $key      Meta key.
	 * @param  string $value    Meta value.
	 * @return void
	 */
	private function set_meta( int $entry_id, int $form_id, string $key, string $value ): void {
		global $wpdb;

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_submission_meta',
			[
				'response_id' => $entry_id,
				'form_id'     => $form_id,
				'meta_key'    => $key,
				'value'       => $value,
				'created_at'  => $now,
				'updated_at'  => $now,
			]
		);
	}
}
