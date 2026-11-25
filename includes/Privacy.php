<?php

namespace Outstand\WP\FluentForm\Addons\Privacy;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Services\Submission\SubmissionService;

/**
 * Bridges Fluent Forms submissions to WordPress core's privacy tooling.
 *
 * Fluent Forms (free and Pro) does not register a personal-data exporter or eraser, so form
 * entries are silently omitted from Tools → Export/Erase Personal Data. This module registers both,
 * matching submissions by the email address stored in any field or by the user who submitted them,
 * and erasing through Fluent Forms' own `SubmissionService::deleteEntries()` cascade so files, meta
 * and detail rows go with the entry.
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
	 * Erases every submission whose fields hold the requested email address. A paid entry is
	 * anonymized and keeps its payment record.
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

		$anonymizer = new Anonymizer();
		$removed    = 0;
		$retained   = 0;
		$anonymized = 0;

		foreach ( $by_form as $form_id => $ids ) {
			$kept      = $anonymizer->kept_ids( $ids );
			$deletable = array_values( array_diff( $ids, $kept ) );

			$count       = $anonymizer->anonymize( $kept, $form_id );
			$anonymized += $count;
			$removed    += $count;

			if ( empty( $deletable ) ) {
				continue;
			}

			try {
				( new SubmissionService() )->deleteEntries( $deletable, $form_id );
			} catch ( \Throwable $e ) {
				// One form failing must not abort erasure of the others. The count below reads live DB
				// state, so this group's still-present ids fall through as retained and get reported.
				unset( $e );
			}

			$still_present = $this->existing_submission_ids( $deletable );
			$removed      += count( $deletable ) - count( $still_present );
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

		if ( $anonymized > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of Fluent Forms payment entries kept without personal data. */
				_n(
					'%d Fluent Forms payment entry was kept without personal data.',
					'%d Fluent Forms payment entries were kept without personal data.',
					$anonymized,
					'fluentform-privacy'
				),
				$anonymized
			);
		}

		// `done` is based on forward progress, not batch size alone. Each pass clears its batch, so the
		// next call reads a fresh top-of-list batch. A full page where nothing could be removed means the
		// remaining rows are stuck (retained by the cascade); returning them again would loop forever, so
		// stop and let `items_retained` report them.
		$done = count( $rows ) < self::PER_PAGE || 0 === $removed;

		return [
			'items_removed'  => $removed,
			'items_retained' => $retained > 0 || $anonymized > 0,
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
	 * Submission id + form id rows for the given email, oldest entry first.
	 *
	 * An entry matches when its stored response holds the address as a JSON string value, in any
	 * field or sub-field, or when a WordPress user with that address submitted it. The response is
	 * written when the entry is created, so this also finds entries that admin approval or double
	 * opt-in still hold, which have no entry detail rows yet.
	 *
	 * The match is case-insensitive. The `response` column has no index, so this is a full scan. That
	 * is acceptable for personal-data requests, but it is a reason to keep the batch size small.
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

		$where = [];
		$args  = [];

		// Fluent Forms encodes the response both with and without escaped Unicode.
		$encodings = array_unique( [ (string) wp_json_encode( $email, JSON_UNESCAPED_UNICODE ), (string) wp_json_encode( $email ) ] );

		foreach ( $encodings as $encoded ) {
			$where[] = 'LOWER(response) LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $encoded ) . '%';
		}

		$user = get_user_by( 'email', $email );

		if ( $user instanceof \WP_User ) {
			$where[] = 'user_id = %d';
			$args[]  = $user->ID;
		}

		$table = $wpdb->prefix . 'fluentform_submissions';
		$sql   = "SELECT id AS submission_id, form_id FROM {$table} WHERE " . implode( ' OR ', $where ) . ' ORDER BY id ASC';

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
	 * The personal data of one submission, for the export: the entry columns, every submitted field
	 * and sub-field, and the consent record.
	 *
	 * @param  int $submission_id The submission id.
	 * @return array<int,array{name:string,value:string}>
	 */
	protected function export_entry_data( int $submission_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_submissions';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $submission_id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return [];
		}

		$data = [];

		foreach ( $this->entry_columns() as $column => $label ) {
			$value = (string) ( $row[ $column ] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			// Fluent Forms stores the payment total in cents.
			if ( 'payment_total' === $column ) {
				$value = number_format( (float) $value / 100, 2, '.', '' );
			}

			$data[] = [
				'name'  => $label,
				'value' => $value,
			];
		}

		$response = json_decode( (string) ( $row['response'] ?? '' ), true );

		if ( is_array( $response ) ) {
			$internal = $this->internal_fields( (int) ( $row['form_id'] ?? 0 ) );
			$this->flatten_fields( array_diff_key( $response, array_flip( $internal ) ), '', $data );
		}

		return array_merge( $data, $this->consent_data( $submission_id ) );
	}

	/**
	 * The submission columns that hold personal data, with their export labels.
	 *
	 * @return array<string,string>
	 */
	protected function entry_columns(): array {
		return [
			'created_at'     => __( 'Submitted at', 'fluentform-privacy' ),
			'source_url'     => __( 'Page', 'fluentform-privacy' ),
			'user_id'        => __( 'User ID', 'fluentform-privacy' ),
			'ip'             => __( 'IP address', 'fluentform-privacy' ),
			'browser'        => __( 'Browser', 'fluentform-privacy' ),
			'device'         => __( 'Device', 'fluentform-privacy' ),
			'city'           => __( 'City', 'fluentform-privacy' ),
			'country'        => __( 'Country', 'fluentform-privacy' ),
			'status'         => __( 'Entry status', 'fluentform-privacy' ),
			'payment_status' => __( 'Payment status', 'fluentform-privacy' ),
			'payment_method' => __( 'Payment method', 'fluentform-privacy' ),
			'payment_total'  => __( 'Payment total', 'fluentform-privacy' ),
			'currency'       => __( 'Currency', 'fluentform-privacy' ),
		];
	}

	/**
	 * The response keys that Fluent Forms adds for its own use (nonce, referrer, captcha tokens).
	 *
	 * @param  int $form_id The form id.
	 * @return array<int,string>
	 */
	protected function internal_fields( int $form_id ): array {
		if ( method_exists( \FluentForm\App\Helpers\Helper::class, 'getWhiteListedFields' ) ) {
			return (array) \FluentForm\App\Helpers\Helper::getWhiteListedFields( $form_id );
		}

		return [ '_fluentform_' . $form_id . '_fluentformnonce', '_wp_http_referer' ];
	}

	/**
	 * Adds each non-empty value of a decoded response to the export, with sub-field keys in brackets
	 * (`names[first_name]`, `repeater[0][1]`).
	 *
	 * @param  array<int|string,mixed>                    $fields The fields to add.
	 * @param  string                                     $prefix The parent key, empty at the top level.
	 * @param  array<int,array{name:string,value:string}> $data   The export data, by reference.
	 * @return void
	 */
	private function flatten_fields( array $fields, string $prefix, array &$data ): void {
		foreach ( $fields as $key => $value ) {
			$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';

			if ( is_array( $value ) ) {
				$this->flatten_fields( $value, $name, $data );
				continue;
			}

			if ( null === $value || '' === $value || false === $value ) {
				continue;
			}

			$data[] = [
				'name'  => $name,
				'value' => true === $value ? '1' : (string) $value,
			];
		}
	}

	/**
	 * The consent record and the submission language that Consent stored on the entry.
	 *
	 * @param  int $submission_id The submission id.
	 * @return array<int,array{name:string,value:string}>
	 */
	private function consent_data( int $submission_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_submission_meta';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, value FROM {$table} WHERE response_id = %d AND meta_key IN (%s, %s) ORDER BY id ASC", $submission_id, Consent::META_CONSENT, Consent::META_LANGUAGE ) );

		$data = [];

		foreach ( $meta as $row ) {
			if ( Consent::META_LANGUAGE === $row->meta_key ) {
				$data[] = [
					'name'  => __( 'Submission language', 'fluentform-privacy' ),
					'value' => (string) $row->value,
				];
				continue;
			}

			$snapshot = json_decode( (string) $row->value, true );

			foreach ( is_array( $snapshot ) ? $snapshot : [] as $consent ) {
				$data[] = [
					'name'  => ! empty( $consent['accepted'] ) ? __( 'Consent given', 'fluentform-privacy' ) : __( 'Consent not given', 'fluentform-privacy' ),
					'value' => (string) ( $consent['text'] ?? '' ),
				];
			}
		}

		return $data;
	}
}
