<?php

namespace Outstand\WP\FluentForm\Addons\Privacy\Tests\Unit;

use Outstand\WP\FluentForm\Addons\Privacy\Consent;
use WP_UnitTestCase;

/**
 * Consent + language capture.
 *
 * @covers \Outstand\WP\FluentForm\Addons\Privacy\Consent
 */
class ConsentTest extends WP_UnitTestCase {

	/**
	 * Consent extraction pulls the label of a consent field nested in the builder's container/column
	 * structure, and ignores ordinary fields.
	 *
	 * @return void
	 */
	public function test_extract_consent_texts_finds_nested_consent_fields(): void {
		$json = (string) wp_json_encode(
			[
				'fields' => [
					[
						'element'  => 'input_email',
						'settings' => [ 'label' => 'Email' ],
					],
					[
						'element' => 'container',
						'columns' => [
							[
								'fields' => [
									[
										'element'  => 'gdpr_agreement',
										'settings' => [ 'tnc_html' => 'I accept the <a href="/privacy">privacy policy</a>.' ],
									],
								],
							],
						],
					],
				],
			]
		);

		$texts = Consent::extract_consent_texts( $json );

		$this->assertCount( 1, $texts );
		$this->assertSame( 'I accept the privacy policy.', $texts[0] );
	}

	/**
	 * A form with no consent field, or malformed JSON, yields nothing.
	 *
	 * @return void
	 */
	public function test_extract_consent_texts_handles_absence(): void {
		$no_consent = (string) wp_json_encode(
			[
				'fields' => [
					[
						'element'  => 'input_text',
						'settings' => [ 'label' => 'Name' ],
					],
				],
			]
		);

		$this->assertSame( [], Consent::extract_consent_texts( $no_consent ) );
		$this->assertSame( [], Consent::extract_consent_texts( 'not json' ) );
		$this->assertSame( [], Consent::extract_consent_texts( '' ) );
	}

	/**
	 * Extraction returns the name + wording of each consent field, so acceptance can be resolved from
	 * the submitted value.
	 *
	 * @return void
	 */
	public function test_extract_consent_fields_returns_name_and_text(): void {
		$json = (string) wp_json_encode(
			[
				'fields' => [
					[
						'element'    => 'gdpr_agreement',
						'attributes' => [ 'name' => 'gdpr_agreement_1' ],
						'settings'   => [ 'label' => 'I agree.' ],
					],
				],
			]
		);

		$fields = Consent::extract_consent_fields( $json );

		$this->assertSame(
			[
				[
					'name' => 'gdpr_agreement_1',
					'text' => 'I agree.',
				],
			],
			$fields
		);
	}

	/**
	 * Capture stores each field's wording with whether it was accepted — the accepted flag coming from
	 * the submitted value, so an unchecked box records as not accepted — plus the language.
	 *
	 * @return void
	 */
	public function test_capture_records_acceptance_and_language(): void {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'fluentform_submission_meta';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) !== $meta_table ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}

		$form = (object) [
			'id'          => 5,
			'form_fields' => (string) wp_json_encode(
				[
					'fields' => [
						[
							'element'    => 'gdpr_agreement',
							'attributes' => [ 'name' => 'consent' ],
							'settings'   => [ 'label' => 'I agree.' ],
						],
					],
				]
			),
		];

		$accepted_entry = 987654;
		( new Consent() )->capture( $accepted_entry, [ 'consent' => 'on' ], $form );

		$declined_entry = 987655;
		( new Consent() )->capture( $declined_entry, [ 'consent' => '' ], $form );

		$this->assertSame(
			[
				[
					'text'     => 'I agree.',
					'accepted' => true,
				],
			],
			$this->read_consent( $accepted_entry )
		);
		$this->assertSame(
			[
				[
					'text'     => 'I agree.',
					'accepted' => false,
				],
			],
			$this->read_consent( $declined_entry )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$language = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$meta_table} WHERE response_id = %d AND meta_key = %s", $accepted_entry, Consent::META_LANGUAGE ) );
		$this->assertNotEmpty( $language );
	}

	/**
	 * A form with no consent field records only the language, never a consent snapshot.
	 *
	 * @return void
	 */
	public function test_capture_writes_only_language_when_no_consent_fields(): void {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'fluentform_submission_meta';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) !== $meta_table ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}

		$form = (object) [
			'id'          => 6,
			'form_fields' => (string) wp_json_encode(
				[
					'fields' => [
						[
							'element'  => 'input_text',
							'settings' => [ 'label' => 'Name' ],
						],
					],
				]
			),
		];

		$entry_id = 987656;
		( new Consent() )->capture( $entry_id, [], $form );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$consent = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$meta_table} WHERE response_id = %d AND meta_key = %s", $entry_id, Consent::META_CONSENT ) );
		$this->assertNull( $consent );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$language = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$meta_table} WHERE response_id = %d AND meta_key = %s", $entry_id, Consent::META_LANGUAGE ) );
		$this->assertNotEmpty( $language );
	}

	/**
	 * When WPML reports a current language, it's what gets stored — not the site locale.
	 *
	 * @return void
	 */
	public function test_capture_uses_wpml_language_when_available(): void {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'fluentform_submission_meta';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) !== $meta_table ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}

		$callback = static fn () => 'de';
		add_filter( 'wpml_current_language', $callback );

		$form = (object) [
			'id'          => 7,
			'form_fields' => (string) wp_json_encode(
				[
					'fields' => [
						[
							'element'    => 'gdpr_agreement',
							'attributes' => [ 'name' => 'consent' ],
							'settings'   => [ 'label' => 'I agree.' ],
						],
					],
				]
			),
		];

		$entry_id = 987657;
		( new Consent() )->capture( $entry_id, [ 'consent' => 'on' ], $form );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$language = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$meta_table} WHERE response_id = %d AND meta_key = %s", $entry_id, Consent::META_LANGUAGE ) );
		$this->assertSame( 'de', $language );

		remove_filter( 'wpml_current_language', $callback );
	}

	/**
	 * Hooks capture() onto before_form_actions_processing at priority 5, before admin approval and
	 * double opt-in hold the entry at priority 10.
	 *
	 * @return void
	 */
	public function test_register_hooks_capture_before_entries_are_held(): void {
		$consent = new Consent();
		$consent->register();

		$this->assertSame( 5, has_action( 'fluentform/before_form_actions_processing', [ $consent, 'capture' ] ) );
		$this->assertFalse( has_action( 'fluentform/submission_inserted', [ $consent, 'capture' ] ) );

		remove_action( 'fluentform/before_form_actions_processing', [ $consent, 'capture' ], 5 );
	}

	/**
	 * A second run for the same entry, as when a held entry is approved, keeps the first snapshot.
	 *
	 * @return void
	 */
	public function test_capture_keeps_the_first_snapshot(): void {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'fluentform_submission_meta';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) !== $meta_table ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}

		$form = static fn ( string $label ) => (object) [
			'id'          => 8,
			'form_fields' => (string) wp_json_encode(
				[
					'fields' => [
						[
							'element'    => 'gdpr_agreement',
							'attributes' => [ 'name' => 'consent' ],
							'settings'   => [ 'label' => $label ],
						],
					],
				]
			),
		];

		$entry_id = 987658;
		( new Consent() )->capture( $entry_id, [ 'consent' => 'on' ], $form( 'Wording at submission.' ) );
		( new Consent() )->capture( $entry_id, [ 'consent' => 'on' ], $form( 'Wording at approval.' ) );

		$this->assertSame(
			[
				[
					'text'     => 'Wording at submission.',
					'accepted' => true,
				],
			],
			$this->read_consent( $entry_id )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$meta_table} WHERE response_id = %d", $entry_id ) );
		$this->assertSame( '2', $rows );
	}

	/**
	 * A hook call without an entry id stores nothing and does not fail.
	 *
	 * @return void
	 */
	public function test_capture_ignores_a_missing_entry_id(): void {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'fluentform_submission_meta';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) !== $meta_table ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$before = $wpdb->get_var( "SELECT COUNT(*) FROM {$meta_table}" );

		( new Consent() )->capture( null, null, (object) [ 'id' => 9 ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( $before, $wpdb->get_var( "SELECT COUNT(*) FROM {$meta_table}" ) );
	}

	/**
	 * Reads and decodes the consent snapshot stored for an entry.
	 *
	 * @param  int $entry_id The submission id.
	 * @return mixed
	 */
	private function read_consent( int $entry_id ) {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'fluentform_submission_meta';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$meta_table} WHERE response_id = %d AND meta_key = %s", $entry_id, Consent::META_CONSENT ) );

		return json_decode( (string) $value, true );
	}
}
