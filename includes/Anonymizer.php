<?php

namespace Outstand\WP\FluentForm\Addons\Privacy;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Services\Submission\SubmissionService;

/**
 * Removes the personal data from Fluent Forms entries that hold a payment, and keeps the payment.
 *
 * Retention and erasure delete an entry through Fluent Forms, and that also deletes its order
 * items, transactions and subscriptions. For an entry where money moved, this class runs in place
 * of that delete. It removes the submitted fields, the uploaded files, the request details, the
 * entry logs, the consent record and the payer details of each transaction. The entry row stays
 * with its form, dates, status and payment columns, and the order items, transactions and
 * subscriptions stay with their amounts, status and gateway ids.
 */
class Anonymizer {

	/**
	 * Submission-meta key that marks an anonymized entry, with the time it was anonymized.
	 */
	public const META_ANONYMIZED = '_os_ff_privacy_anonymized';

	/**
	 * Payment statuses of the entries that are anonymized and kept.
	 */
	private const KEPT_STATUSES = [ 'paid', 'processing', 'refunded', 'partially-refunded' ];

	/**
	 * Transaction columns that hold the payer's personal data.
	 */
	private const TRANSACTION_COLUMNS = [ 'payer_name', 'payer_email', 'billing_address', 'shipping_address', 'user_id', 'card_last_4', 'payment_note' ];

	/**
	 * Subscription columns that can hold the payer's personal data.
	 */
	private const SUBSCRIPTION_COLUMNS = [ 'vendor_response', 'note' ];

	/**
	 * Submission columns that hold the visitor's personal data.
	 */
	private const SUBMISSION_COLUMNS = [ 'source_url', 'user_id', 'browser', 'device', 'ip', 'city', 'country' ];

	/**
	 * The payment statuses of the entries that are kept. A site can change them with the
	 * `outstand_fluentform_privacy_kept_payment_statuses` filter.
	 *
	 * @return array<int,string>
	 */
	public static function kept_statuses(): array {
		$statuses = apply_filters( 'outstand_fluentform_privacy_kept_payment_statuses', self::KEPT_STATUSES );

		return array_values( array_filter( array_map( 'strval', (array) $statuses ) ) );
	}

	/**
	 * Of the given submission ids, those whose payment status keeps the entry.
	 *
	 * @param  array<int,int> $ids Submission ids.
	 * @return array<int,int>
	 */
	public function kept_ids( array $ids ): array {
		global $wpdb;

		$statuses = self::kept_statuses();

		if ( empty( $ids ) || empty( $statuses ) ) {
			return [];
		}

		$table       = $wpdb->prefix . 'fluentform_submissions';
		$id_list     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$status_list = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id IN ({$id_list}) AND payment_status IN ({$status_list})", array_merge( $ids, $statuses ) ) );

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Anonymizes the given entries of one form.
	 *
	 * @param  array<int,int> $ids     Submission ids.
	 * @param  int            $form_id The form the entries belong to.
	 * @return int The number of entries anonymized.
	 */
	public function anonymize( array $ids, int $form_id ): int {
		global $wpdb;

		$ids = $this->scope_to_form( $ids, $form_id );

		if ( empty( $ids ) ) {
			return 0;
		}

		// The files are found through the response, so they go before the response is cleared.
		try {
			( new SubmissionService() )->deleteFiles( $ids, $form_id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$id_list = implode( ',', array_map( 'intval', $ids ) );
		$columns = array_merge( [ "response = '{}'" ], array_map( static fn ( string $column ) => "{$column} = NULL", self::SUBMISSION_COLUMNS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$wpdb->prefix}fluentform_submissions SET " . implode( ', ', $columns ) . " WHERE id IN ({$id_list})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}fluentform_entry_details WHERE submission_id IN ({$id_list})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}fluentform_logs WHERE source_type = 'submission_item' AND source_id IN ({$id_list})" );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}fluentform_submission_meta WHERE response_id IN ({$id_list}) AND meta_key IN (%s, %s)", Consent::META_CONSENT, Consent::META_LANGUAGE ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->clear_columns( 'fluentform_transactions', self::TRANSACTION_COLUMNS, $id_list );
		$this->clear_columns( 'fluentform_subscriptions', self::SUBSCRIPTION_COLUMNS, $id_list );

		$now = current_time( 'mysql' );

		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'fluentform_submission_meta',
				[
					'response_id' => $id,
					'form_id'     => $form_id,
					'meta_key'    => self::META_ANONYMIZED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'value'       => $now,
					'created_at'  => $now,
					'updated_at'  => $now,
				]
			);
		}

		/**
		 * Fires after entries of one form are anonymized.
		 *
		 * @param array<int,int> $ids     The anonymized submission ids.
		 * @param int            $form_id The form id.
		 */
		do_action( 'outstand_fluentform_privacy_entries_anonymized', $ids, $form_id );

		return count( $ids );
	}

	/**
	 * Of the given submission ids, those that belong to the form and are not anonymized yet.
	 *
	 * @param  array<int,int> $ids     Submission ids.
	 * @param  int            $form_id The form id.
	 * @return array<int,int>
	 */
	private function scope_to_form( array $ids, int $form_id ): array {
		global $wpdb;

		if ( empty( $ids ) || $form_id <= 0 ) {
			return [];
		}

		$id_list = implode( ',', array_map( 'intval', $ids ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fluentform_submissions WHERE id IN ({$id_list}) AND form_id = %d AND id NOT IN (SELECT response_id FROM {$wpdb->prefix}fluentform_submission_meta WHERE meta_key = %s)", $form_id, self::META_ANONYMIZED ) );

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Sets columns to NULL for the given submissions. Fluent Forms creates the payment tables only
	 * when payments are turned on, so a missing table is skipped.
	 *
	 * @param  string            $table   Table name without the prefix.
	 * @param  array<int,string> $columns Columns to clear.
	 * @param  string            $id_list Comma-separated submission ids.
	 * @return void
	 */
	private function clear_columns( string $table, array $columns, string $id_list ): void {
		global $wpdb;

		$table = $wpdb->prefix . $table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $found !== $table ) {
			return;
		}

		$set = implode( ', ', array_map( static fn ( string $column ) => "{$column} = NULL", $columns ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$table} SET {$set} WHERE submission_id IN ({$id_list})" );
	}
}
