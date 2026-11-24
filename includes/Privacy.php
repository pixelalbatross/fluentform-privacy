<?php

namespace Outstand\WP\FluentForm\Addons\Privacy;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Services\Submission\SubmissionService;

/**
 * Bridges Fluent Forms submissions to WordPress core's privacy tooling.
 *
 * Fluent Forms (free and Pro) does not register a personal-data exporter or eraser, so form
 * entries are silently omitted from Tools → Export/Erase Personal Data. This module registers both,
 * matching submissions by the email address stored in any field, and erasing through Fluent Forms'
 * own `SubmissionService::deleteEntries()` cascade so files, meta and detail rows go with the entry.
 */
class Privacy extends BaseModule {

	/**
	 * WordPress privacy exporter/eraser id.
	 */
	private const PRIVACY_ID = 'fluentform-privacy';

	/**
	 * Submissions handled per export/erase page.
	 */
	private const PER_PAGE = 100;

	/**
	 * Registers nothing unless Fluent Forms is active — the deletion cascade it delegates to must exist.
	 */
	public function can_register(): bool {
		return class_exists( SubmissionService::class );
	}

	/**
	 * Registers the exporter/eraser with WordPress core.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/**
	 * Registers the exporter with WordPress core.
	 *
	 * @param  array<string,mixed> $exporters Registered exporters.
	 * @return array<string,mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::PRIVACY_ID ] = [
			'exporter_friendly_name' => $this->group_label(),
			'callback'               => [ $this, 'export_by_email' ],
		];

		return $exporters;
	}

	/**
	 * Registers the eraser with WordPress core.
	 *
	 * @param  array<string,mixed> $erasers Registered erasers.
	 * @return array<string,mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::PRIVACY_ID ] = [
			'eraser_friendly_name' => $this->group_label(),
			'callback'             => [ $this, 'erase_by_email' ],
		];

		return $erasers;
	}

	/**
	 * Exports every submission whose fields hold the requested email address.
	 *
	 * @param  string $email The person's email address.
	 * @param  int    $page  Pagination page, 1-based.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export_by_email( string $email, int $page = 1 ): array {
		$group_label  = $this->group_label();
		$export_items = [];
		$offset       = ( max( 1, $page ) - 1 ) * self::PER_PAGE;
		$rows         = $this->submission_ids_for_email( $email, self::PER_PAGE, $offset );

		foreach ( $rows as $row ) {
			$export_items[] = [
				'group_id'    => self::PRIVACY_ID,
				'group_label' => $group_label,
				'item_id'     => 'entry-' . (int) $row->submission_id,
				'data'        => $this->export_entry_data( (int) $row->submission_id ),
			];
		}

		return [
			'data' => $export_items,
			'done' => count( $rows ) < self::PER_PAGE,
		];
	}

	/**
	 * Erases every submission whose fields hold the requested email address.
	 *
	 * @param  string $email The person's email address.
	 * @param  int    $page  Pagination page, 1-based. Each pass removes the current batch, so the next
	 *                       call reads a fresh top-of-list batch rather than an offset one.
	 * @return array{items_removed:int,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase_by_email( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $page is part of WordPress' eraser callback contract; each pass clears the top batch.
		$rows    = $this->submission_ids_for_email( $email, self::PER_PAGE );
		$by_form = [];

		foreach ( $rows as $row ) {
			$by_form[ (int) $row->form_id ][] = (int) $row->submission_id;
		}

		$removed  = 0;
		$retained = 0;
		foreach ( $by_form as $form_id => $ids ) {
			try {
				( new SubmissionService() )->deleteEntries( $ids, $form_id );
			} catch ( \Throwable $e ) {
				// One form failing must not abort erasure of the others. The count below reads live DB
				// state, so this group's still-present ids fall through as retained and get reported.
				unset( $e );
			}

			$still_present = $this->existing_submission_ids( $ids );
			$removed      += count( $ids ) - count( $still_present );
			$retained     += count( $still_present );
		}

		$messages = [];
		if ( $retained > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of Fluent Forms entries that could not be deleted. */
				_n(
					'%d Fluent Forms entry could not be deleted.',
					'%d Fluent Forms entries could not be deleted.',
					$retained,
					'fluentform-privacy'
				),
				$retained
			);
		}

		// `done` is based on forward progress, not batch size alone. Each pass clears its batch, so the
		// next call reads a fresh top-of-list batch. A full page where nothing could be removed means the
		// remaining rows are stuck (retained by the cascade); returning them again would loop forever, so
		// stop and let `items_retained` report them.
		$done = count( $rows ) < self::PER_PAGE || 0 === $removed;

		return [
			'items_removed'  => $removed,
			'items_retained' => $retained > 0,
			'messages'       => $messages,
			'done'           => $done,
		];
	}

	/**
	 * The group label shown in the export/erasure UI. Filterable so a site can rename it.
	 *
	 * @return string
	 */
	protected function group_label(): string {
		return (string) apply_filters( 'outstand_fluentform_privacy_group_label', __( 'Fluent Forms', 'fluentform-privacy' ) );
	}

	/**
	 * Submission id + form id rows whose entry details hold the given email in any field.
	 *
	 * Matched case-insensitively and whitespace-trimmed, since the requested address and the stored
	 * value can differ in case. The `field_value` column is unindexed, so this is a full scan — fine
	 * at the low volumes personal-data requests run at, but a reason not to widen the batch size.
	 *
	 * A second, quoted-substring match catches addresses stored inside a JSON or serialized value
	 * (multi-value or repeater fields), where the email is wrapped in double quotes. Bracketing the
	 * quotes keeps it from matching a longer address that merely starts with the same characters.
	 *
	 * @param  string $email  The email to match.
	 * @param  int    $limit  Max rows to return; 0 for no limit.
	 * @param  int    $offset Rows to skip.
	 * @return array<int,object>
	 */
	protected function submission_ids_for_email( string $email, int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$email = strtolower( trim( $email ) );

		if ( '' === $email ) {
			return [];
		}

		$table  = $wpdb->prefix . 'fluentform_entry_details';
		$quoted = '%"' . $wpdb->esc_like( $email ) . '"%';
		$sql    = "SELECT DISTINCT submission_id, form_id FROM {$table} WHERE LOWER(TRIM(field_value)) = %s OR LOWER(field_value) LIKE %s";
		$args   = [ $email, $quoted ];

		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = $limit;
			$args[] = max( 0, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Of the given submission ids, those that still exist — used to confirm a deletion actually took.
	 *
	 * @param  array<int,int> $ids Submission ids to check.
	 * @return array<int,int> The ids still present.
	 */
	protected function existing_submission_ids( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return [];
		}

		$table        = $wpdb->prefix . 'fluentform_submissions';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id IN ({$placeholders})", $ids ) );

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * The field name/value pairs of one submission, for the export.
	 *
	 * @param  int $submission_id The submission id.
	 * @return array<int,array{name:string,value:string}>
	 */
	protected function export_entry_data( int $submission_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_entry_details';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$details = (array) $wpdb->get_results( $wpdb->prepare( "SELECT field_name, field_value FROM {$table} WHERE submission_id = %d", $submission_id ) );

		$data = [];
		foreach ( $details as $detail ) {
			$data[] = [
				'name'  => (string) $detail->field_name,
				'value' => (string) $detail->field_value,
			];
		}

		return $data;
	}
}
