<?php

namespace Outstand\WP\FluentForm\Addons\Privacy\Tests\Unit;

use Outstand\WP\FluentForm\Addons\Privacy\Anonymizer;
use Outstand\WP\FluentForm\Addons\Privacy\Consent;
use Outstand\WP\FluentForm\Addons\Privacy\Privacy;
use Outstand\WP\FluentForm\Addons\Privacy\Retention;
use WP_UnitTestCase;

/**
 * Anonymization of paid Fluent Forms entries.
 *
 * Integration tests against the real Fluent Forms tables; skipped where Fluent Forms is not
 * installed/migrated. The transaction checks run only where the payment tables exist.
 */
class AnonymizerTest extends WP_UnitTestCase {

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		if ( ! class_exists( \FluentForm\App\Services\Submission\SubmissionService::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is not active.' );
		}

		$table = $wpdb->prefix . 'fluentform_submissions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $found !== $table ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}
	}

	/**
	 * Only paid, processing and refunded entries are kept.
	 *
	 * @return void
	 */
	public function test_kept_ids_selects_the_kept_payment_statuses(): void {
		$form_id  = $this->seed_form();
		$paid     = $this->seed_entry( $form_id, [ 'payment_status' => 'paid' ] );
		$refunded = $this->seed_entry( $form_id, [ 'payment_status' => 'partially-refunded' ] );
		$pending  = $this->seed_entry( $form_id, [ 'payment_status' => 'pending' ] );
		$failed   = $this->seed_entry( $form_id, [ 'payment_status' => 'failed' ] );
		$no_pay   = $this->seed_entry( $form_id );

		$kept = ( new Anonymizer() )->kept_ids( [ $paid, $refunded, $pending, $failed, $no_pay ] );

		sort( $kept );
		$this->assertSame( [ $paid, $refunded ], $kept );
	}

	/**
	 * The kept statuses are filterable.
	 *
	 * @return void
	 */
	public function test_kept_statuses_are_filterable(): void {
		$callback = static fn () => [ 'pending' ];
		add_filter( 'outstand_fluentform_privacy_kept_payment_statuses', $callback );

		$this->assertSame( [ 'pending' ], Anonymizer::kept_statuses() );

		remove_filter( 'outstand_fluentform_privacy_kept_payment_statuses', $callback );
	}

	/**
	 * Anonymizing removes the submitted fields, request details, detail rows, logs, consent record and
	 * payer details, and keeps the entry, its payment columns, other meta and the transaction amount.
	 *
	 * @return void
	 */
	public function test_anonymize_removes_personal_data_and_keeps_the_payment(): void {
		global $wpdb;

		$user_id  = self::factory()->user->create();
		$form_id  = $this->seed_form();
		$entry_id = $this->seed_entry(
			$form_id,
			[
				'response'       => (string) wp_json_encode(
					[
						'email' => 'payer@example.test',
						'names' => [ 'first_name' => 'Ada' ],
					]
				),
				'source_url'     => 'https://example.test/donate/',
				'user_id'        => $user_id,
				'ip'             => '192.0.2.20',
				'browser'        => 'Firefox',
				'device'         => 'Desktop',
				'country'        => 'PT',
				'payment_status' => 'paid',
				'payment_method' => 'ifthenpay',
				'payment_total'  => 1250,
				'currency'       => 'EUR',
			]
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_entry_details',
			[
				'form_id'       => $form_id,
				'submission_id' => $entry_id,
				'field_name'    => 'email',
				'field_value'   => 'payer@example.test',
			]
		);
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_logs',
			[
				'parent_source_id' => $form_id,
				'source_type'      => 'submission_item',
				'source_id'        => $entry_id,
				'component'        => 'Payment',
				'status'           => 'success',
				'title'            => 'Paid',
				'description'      => 'Paid by payer@example.test',
				'created_at'       => current_time( 'mysql' ),
			]
		);

		foreach ( [ Consent::META_CONSENT, Consent::META_LANGUAGE, '_entry_uid_hash' ] as $meta_key ) {
			$wpdb->insert(
				$wpdb->prefix . 'fluentform_submission_meta',
				[
					'response_id' => $entry_id,
					'form_id'     => $form_id,
					'meta_key'    => $meta_key,
					'value'       => 'x',
				]
			);
		}

		$transactions = $wpdb->prefix . 'fluentform_transactions';
		$has_payments = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $transactions ) ) === $transactions;

		if ( $has_payments ) {
			$wpdb->insert(
				$transactions,
				[
					'transaction_hash' => 'hash-1',
					'payer_name'       => 'Ada Example',
					'payer_email'      => 'payer@example.test',
					'billing_address'  => '1 Example Street',
					'form_id'          => $form_id,
					'user_id'          => $user_id,
					'submission_id'    => $entry_id,
					'payment_method'   => 'ifthenpay',
					'card_last_4'      => 4242,
					'card_brand'       => 'mbway',
					'charge_id'        => 'REQ-1',
					'payment_total'    => 1250,
					'status'           => 'paid',
					'currency'         => 'EUR',
					'payment_note'     => 'Paid by payer@example.test',
				]
			);
		}

		$this->assertSame( 1, ( new Anonymizer() )->anonymize( [ $entry_id ], $form_id ) );

		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", $entry_id ), ARRAY_A );

		$this->assertSame( '{}', $entry['response'] );

		foreach ( [ 'source_url', 'user_id', 'ip', 'browser', 'device', 'country' ] as $column ) {
			$this->assertNull( $entry[ $column ], $column );
		}

		$this->assertSame( 'paid', $entry['payment_status'] );
		$this->assertSame( 'ifthenpay', $entry['payment_method'] );
		$this->assertEquals( 1250, $entry['payment_total'] );
		$this->assertSame( 'EUR', $entry['currency'] );

		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_entry_details WHERE submission_id = %d", $entry_id ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_logs WHERE source_type = 'submission_item' AND source_id = %d", $entry_id ) ) );

		$meta_keys = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->prefix}fluentform_submission_meta WHERE response_id = %d", $entry_id ) );
		$this->assertEqualsCanonicalizing( [ Anonymizer::META_ANONYMIZED, '_entry_uid_hash' ], $meta_keys );

		if ( $has_payments ) {
			$transaction = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$transactions} WHERE submission_id = %d", $entry_id ), ARRAY_A );

			foreach ( [ 'payer_name', 'payer_email', 'billing_address', 'user_id', 'card_last_4', 'payment_note' ] as $column ) {
				$this->assertNull( $transaction[ $column ], $column );
			}

			$this->assertEquals( 1250, $transaction['payment_total'] );
			$this->assertSame( 'paid', $transaction['status'] );
			$this->assertSame( 'REQ-1', $transaction['charge_id'] );
			$this->assertSame( 'mbway', $transaction['card_brand'] );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		$this->assertSame( 0, ( new Anonymizer() )->anonymize( [ $entry_id ], $form_id ) );
	}

	/**
	 * An entry of another form is not anonymized.
	 *
	 * @return void
	 */
	public function test_anonymize_is_scoped_to_the_form(): void {
		$form_id  = $this->seed_form();
		$entry_id = $this->seed_entry( $form_id, [ 'payment_status' => 'paid' ] );

		$this->assertSame( 0, ( new Anonymizer() )->anonymize( [ $entry_id ], $form_id + 1000 ) );
	}

	/**
	 * Retention anonymizes an old paid entry, deletes an old unpaid one, and skips the anonymized
	 * entry on the next run.
	 *
	 * @return void
	 */
	public function test_retention_anonymizes_paid_entries_and_deletes_the_rest(): void {
		global $wpdb;

		$old     = gmdate( 'Y-m-d H:i:s', strtotime( '-18 months' ) );
		$form_id = $this->seed_form();
		$paid    = $this->seed_entry(
			$form_id,
			[
				'payment_status' => 'paid',
				'created_at'     => $old,
			]
		);
		$unpaid  = $this->seed_entry(
			$form_id,
			[
				'payment_status' => 'pending',
				'created_at'     => $old,
			]
		);

		$dry_run = ( new Retention() )->prune( 12, true );
		$this->assertSame(
			[
				'deleted'    => 1,
				'anonymized' => 1,
			],
			$dry_run[ $form_id ]
		);

		$counts = ( new Retention() )->prune( 12, false );
		$this->assertSame(
			[
				'deleted'    => 1,
				'anonymized' => 1,
			],
			$counts[ $form_id ]
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( (string) $paid, $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", $paid ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", $unpaid ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$again = ( new Retention() )->prune( 12, false );
		$this->assertArrayNotHasKey( $form_id, $again );
	}

	/**
	 * An erasure request anonymizes a paid entry, reports it as retained, and leaves nothing that
	 * matches the address.
	 *
	 * @return void
	 */
	public function test_erasure_anonymizes_paid_entries(): void {
		$form_id = $this->seed_form();
		$this->seed_entry(
			$form_id,
			[
				'response'       => (string) wp_json_encode( [ 'email' => 'donor@example.test' ] ),
				'payment_status' => 'paid',
			]
		);

		$privacy = new Privacy();
		$result  = $privacy->erase_by_email( 'donor@example.test' );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertStringContainsString( 'kept without personal data', implode( ' ', $result['messages'] ) );
		$this->assertCount( 0, $privacy->export_by_email( 'donor@example.test' )['data'] );
	}

	/**
	 * Creates one Fluent Forms form.
	 *
	 * @return int Form id.
	 */
	private function seed_form(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_forms',
			[
				'title'       => 'Anonymizer Test',
				'status'      => 'published',
				'form_fields' => (string) wp_json_encode( [ 'fields' => [] ] ),
				'created_by'  => 1,
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Inserts one submission row and returns its id.
	 *
	 * @param  int                 $form_id The form id.
	 * @param  array<string,mixed> $columns Column values.
	 * @return int Submission id.
	 */
	private function seed_entry( int $form_id, array $columns = [] ): int {
		global $wpdb;

		$columns = array_merge(
			[
				'form_id'    => $form_id,
				'response'   => (string) wp_json_encode( [ 'email' => 'entry@example.test' ] ),
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
}
