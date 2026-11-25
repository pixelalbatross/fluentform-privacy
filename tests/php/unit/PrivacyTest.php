<?php

namespace Outstand\WP\FluentForm\Addons\Privacy\Tests\Unit;

use Outstand\WP\FluentForm\Addons\Privacy\Consent;
use Outstand\WP\FluentForm\Addons\Privacy\Privacy;
use WP_UnitTestCase;

/**
 * Fluent Forms ↔ WordPress privacy-tools bridge.
 *
 * Integration tests against the real Fluent Forms tables; skipped where Fluent Forms is not
 * installed/migrated (e.g. a CI job without the plugin).
 */
class PrivacyTest extends WP_UnitTestCase {

	/**
	 * The module under test.
	 *
	 * @var Privacy
	 */
	private Privacy $privacy;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		if ( ! class_exists( \FluentForm\App\Services\Submission\SubmissionService::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is not active.' );
		}

		$table = $wpdb->prefix . 'fluentform_entry_details';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}

		$this->privacy = new Privacy();
	}

	/**
	 * The exporter registers with WordPress core.
	 *
	 * @return void
	 */
	public function test_exporter_is_registered(): void {
		$exporters = $this->privacy->register_exporter( [] );

		$this->assertArrayHasKey( 'fluentform-privacy', $exporters );
		$this->assertIsCallable( $exporters['fluentform-privacy']['callback'] );
	}

	/**
	 * The eraser registers with WordPress core.
	 *
	 * @return void
	 */
	public function test_eraser_is_registered(): void {
		$erasers = $this->privacy->register_eraser( [] );

		$this->assertArrayHasKey( 'fluentform-privacy', $erasers );
		$this->assertIsCallable( $erasers['fluentform-privacy']['callback'] );
	}

	/**
	 * The export returns every submission holding the email, with its field data, and nothing for
	 * an address that never submitted.
	 *
	 * @return void
	 */
	public function test_export_returns_matching_entries(): void {
		$submission_id = $this->seed_submission( 'jane@example.test', 'Jane' );

		$export = $this->privacy->export_by_email( 'jane@example.test' );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'entry-' . $submission_id, $export['data'][0]['item_id'] );

		$values = wp_list_pluck( $export['data'][0]['data'], 'value' );
		$this->assertContains( 'jane@example.test', $values );
		$this->assertContains( 'Jane', $values );

		$empty = $this->privacy->export_by_email( 'nobody@example.test' );
		$this->assertCount( 0, $empty['data'] );
	}

	/**
	 * The email match is case-insensitive: a request for a differently-cased address still finds the
	 * submission stored under the original casing.
	 *
	 * @return void
	 */
	public function test_export_matches_email_case_insensitively(): void {
		$this->seed_submission( 'Mixed.Case@Example.test', 'Casey' );

		$export = $this->privacy->export_by_email( 'mixed.case@example.test' );

		$this->assertCount( 1, $export['data'] );
	}

	/**
	 * The eraser removes the matching submission and its detail rows, and leaves others intact.
	 *
	 * @return void
	 */
	public function test_erase_removes_only_matching_entries(): void {
		global $wpdb;

		$target = $this->seed_submission( 'erase-me@example.test', 'Target' );
		$other  = $this->seed_submission( 'keep-me@example.test', 'Keep' );

		$result = $this->privacy->erase_by_email( 'erase-me@example.test' );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 1, $result['items_removed'] );

		$submissions = $wpdb->prefix . 'fluentform_submissions';
		$details     = $wpdb->prefix . 'fluentform_entry_details';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$submissions} WHERE id = %d", $target ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$details} WHERE submission_id = %d", $target ) ) );
		$this->assertSame( (string) $other, $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$submissions} WHERE id = %d", $other ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Export paginates a full page of matching submissions across two calls: the first page is a full
	 * batch and not done, the second is the remainder and is done.
	 *
	 * @return void
	 */
	public function test_export_paginates_when_a_full_page_matches(): void {
		$email   = 'page-me@example.test';
		$form_id = $this->ensure_form();

		for ( $i = 0; $i < 101; $i++ ) {
			$this->seed_email_submission( $email, $form_id );
		}

		$first = $this->privacy->export_by_email( $email, 1 );
		$this->assertCount( 100, $first['data'] );
		$this->assertFalse( $first['done'] );

		$second = $this->privacy->export_by_email( $email, 2 );
		$this->assertCount( 1, $second['data'] );
		$this->assertTrue( $second['done'] );

		$item_ids = wp_list_pluck( array_merge( $first['data'], $second['data'] ), 'item_id' );
		$this->assertCount( 101, array_unique( $item_ids ) );
	}

	/**
	 * Erasure makes forward progress across passes: a full first batch is not done, the remainder pass
	 * is, and nothing is left behind for the address afterwards.
	 *
	 * @return void
	 */
	public function test_erase_makes_forward_progress_across_pages(): void {
		$email   = 'bulk@example.test';
		$form_id = $this->ensure_form();

		for ( $i = 0; $i < 101; $i++ ) {
			$this->seed_email_submission( $email, $form_id );
		}

		$first = $this->privacy->erase_by_email( $email );
		$this->assertSame( 100, $first['items_removed'] );
		$this->assertFalse( $first['done'] );

		$second = $this->privacy->erase_by_email( $email );
		$this->assertSame( 1, $second['items_removed'] );
		$this->assertTrue( $second['done'] );

		$remaining = $this->privacy->export_by_email( $email );
		$this->assertCount( 0, $remaining['data'] );
	}

	/**
	 * An empty email address matches nothing.
	 *
	 * @return void
	 */
	public function test_export_with_empty_email_returns_nothing(): void {
		$export = $this->privacy->export_by_email( '' );

		$this->assertSame( [], $export['data'] );
		$this->assertTrue( $export['done'] );
	}

	/**
	 * An email inside a JSON array field value (e.g. a checkbox/repeater field) is matched as a quoted
	 * JSON string, and is both exported and erased.
	 *
	 * @return void
	 */
	public function test_matches_email_stored_in_a_json_array_field(): void {
		global $wpdb;

		$form_id  = $this->ensure_form();
		$response = (string) wp_json_encode(
			[ 'checkbox' => [ 'other@example.test', 'nested@example.test' ] ]
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_submissions',
			[
				'form_id'    => $form_id,
				'response'   => $response,
				'status'     => 'unread',
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			]
		);
		$submission_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'fluentform_entry_details',
			[
				'form_id'       => $form_id,
				'submission_id' => $submission_id,
				'field_name'    => 'checkbox',
				'field_value'   => (string) wp_json_encode( [ 'other@example.test', 'nested@example.test' ] ),
			]
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$export = $this->privacy->export_by_email( 'nested@example.test' );
		$this->assertCount( 1, $export['data'] );

		$erase = $this->privacy->erase_by_email( 'nested@example.test' );
		$this->assertSame( 1, $erase['items_removed'] );
	}

	/**
	 * An entry that admin approval or double opt-in holds has a response but no entry detail rows
	 * yet. It is still exported and erased.
	 *
	 * @return void
	 */
	public function test_matches_a_held_entry_without_detail_rows(): void {
		$submission_id = $this->insert_submission( [ 'response' => (string) wp_json_encode( [ 'email' => 'held@example.test' ] ) ] );

		$export = $this->privacy->export_by_email( 'held@example.test' );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'entry-' . $submission_id, $export['data'][0]['item_id'] );

		$erase = $this->privacy->erase_by_email( 'held@example.test' );
		$this->assertSame( 1, $erase['items_removed'] );
	}

	/**
	 * An entry that a WordPress user submitted matches that user's email, even when no field holds it.
	 *
	 * @return void
	 */
	public function test_matches_entries_by_the_submitting_user(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'member@example.test' ] );

		$submission_id = $this->insert_submission(
			[
				'response' => (string) wp_json_encode( [ 'message' => 'Hello' ] ),
				'user_id'  => $user_id,
			]
		);

		$export = $this->privacy->export_by_email( 'member@example.test' );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'entry-' . $submission_id, $export['data'][0]['item_id'] );
	}

	/**
	 * An address is matched only as a whole JSON string, never as part of a longer address.
	 *
	 * @return void
	 */
	public function test_does_not_match_a_longer_address(): void {
		$this->insert_submission( [ 'response' => (string) wp_json_encode( [ 'email' => 'ann@example.test.org' ] ) ] );

		$export = $this->privacy->export_by_email( 'ann@example.test' );
		$this->assertCount( 0, $export['data'] );
	}

	/**
	 * The export holds the entry columns, every field and sub-field, and the consent record, and
	 * leaves out the fields Fluent Forms adds for its own use.
	 *
	 * @return void
	 */
	public function test_export_holds_columns_sub_fields_and_consent(): void {
		global $wpdb;

		$form_id       = $this->ensure_form();
		$submission_id = $this->insert_submission(
			[
				'form_id'        => $form_id,
				'response'       => (string) wp_json_encode(
					[
						'email'            => 'full@example.test',
						'names'            => [
							'first_name' => 'Ada',
							'last_name'  => 'Example',
						],
						'address'          => [ 'city' => 'Sampletown' ],
						'empty_field'      => '',
						'_wp_http_referer' => '/contact/',
					]
				),
				'ip'             => '192.0.2.10',
				'browser'        => 'Firefox',
				'payment_status' => 'paid',
				'payment_total'  => 1250,
				'currency'       => 'EUR',
			]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_submission_meta',
			[
				'response_id' => $submission_id,
				'form_id'     => $form_id,
				'meta_key'    => Consent::META_CONSENT,
				'value'       => (string) wp_json_encode(
					[
						[
							'text'     => 'I agree.',
							'accepted' => true,
						],
					]
				),
			]
		);

		$export = $this->privacy->export_by_email( 'full@example.test' );
		$this->assertCount( 1, $export['data'] );

		$data = array_column( $export['data'][0]['data'], 'value', 'name' );

		$this->assertSame( 'full@example.test', $data['email'] );
		$this->assertSame( 'Ada', $data['names[first_name]'] );
		$this->assertSame( 'Example', $data['names[last_name]'] );
		$this->assertSame( 'Sampletown', $data['address[city]'] );
		$this->assertSame( '192.0.2.10', $data['IP address'] );
		$this->assertSame( 'Firefox', $data['Browser'] );
		$this->assertSame( 'paid', $data['Payment status'] );
		$this->assertSame( '12.50', $data['Payment total'] );
		$this->assertSame( 'EUR', $data['Currency'] );
		$this->assertSame( 'I agree.', $data['Consent given'] );
		$this->assertArrayNotHasKey( 'empty_field', $data );
		$this->assertArrayNotHasKey( '_wp_http_referer', $data );
		$this->assertArrayNotHasKey( 'Device', $data );
	}

	/**
	 * The export/erase group label is filterable.
	 *
	 * @return void
	 */
	public function test_group_label_is_filterable(): void {
		$callback = static fn () => 'Custom Label';
		add_filter( 'outstand_fluentform_privacy_group_label', $callback );

		$exporters = $this->privacy->register_exporter( [] );
		$this->assertSame( 'Custom Label', $exporters['fluentform-privacy']['exporter_friendly_name'] );

		remove_filter( 'outstand_fluentform_privacy_group_label', $callback );
	}

	/**
	 * Seeds one Fluent Forms submission with an email and name field, returning its id.
	 *
	 * @param  string $email The email to store.
	 * @param  string $name  The name to store.
	 * @return int Submission id.
	 */
	private function seed_submission( string $email, string $name ): int {
		global $wpdb;

		$form_id  = $this->ensure_form();
		$response = (string) wp_json_encode(
			[
				'email' => $email,
				'names' => $name,
			]
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_submissions',
			[
				'form_id'    => $form_id,
				'response'   => $response,
				'status'     => 'unread',
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			]
		);
		$submission_id = (int) $wpdb->insert_id;

		foreach ( [
			'email' => $email,
			'names' => $name,
		] as $field_name => $field_value ) {
			$wpdb->insert(
				$wpdb->prefix . 'fluentform_entry_details',
				[
					'form_id'       => $form_id,
					'submission_id' => $submission_id,
					'field_name'    => $field_name,
					'field_value'   => $field_value,
				]
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $submission_id;
	}

	/**
	 * Inserts one Fluent Forms submission row, with no entry detail rows, and returns its id.
	 *
	 * @param  array<string,mixed> $columns Column values; form_id defaults to a new form.
	 * @return int Submission id.
	 */
	private function insert_submission( array $columns ): int {
		global $wpdb;

		$columns = array_merge(
			[
				'form_id'    => $columns['form_id'] ?? $this->ensure_form(),
				'status'     => 'unread',
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			],
			$columns
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $wpdb->prefix . 'fluentform_submissions', $columns );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Creates one Fluent Forms form to attach submissions to, once per test.
	 *
	 * @return int Form id.
	 */
	private function ensure_form(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_forms',
			[
				'title'       => 'Test Form',
				'status'      => 'published',
				'form_fields' => (string) wp_json_encode( [ 'fields' => [] ] ),
				'created_by'  => 1,
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			]
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $wpdb->insert_id;
	}

	/**
	 * Seeds one Fluent Forms submission holding only an email field, for volume/pagination tests.
	 *
	 * @param  string $email   The email to store.
	 * @param  int    $form_id The form id.
	 * @return int Submission id.
	 */
	private function seed_email_submission( string $email, int $form_id ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_submissions',
			[
				'form_id'    => $form_id,
				'response'   => (string) wp_json_encode( [ 'email' => $email ] ),
				'status'     => 'unread',
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			]
		);
		$submission_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'fluentform_entry_details',
			[
				'form_id'       => $form_id,
				'submission_id' => $submission_id,
				'field_name'    => 'email',
				'field_value'   => $email,
			]
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $submission_id;
	}
}
