<?php

namespace Outstand\WP\FluentForm\Addons\Privacy;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Services\Submission\SubmissionService;

/**
 * Deletes Fluent Forms entries past a retention window, and anonymizes the paid ones.
 *
 * Retention is **off by default** — a plugin that silently deletes personal data the moment it is
 * installed would be a footgun. A site opts in by returning a positive number of months from the
 * `outstand_fluentform_privacy_retention_months` filter (or by passing `--months` to the CLI command).
 * When a window is configured, a daily WP-Cron event enforces it automatically; the CLI command remains
 * for on-demand runs. Deletion goes through Fluent Forms' own `SubmissionService::deleteEntries()` cascade.
 * An entry with a kept payment status goes through Anonymizer, so its payment record stays.
 */
class Retention extends BaseModule {

	/**
	 * The daily cron hook that enforces the retention window.
	 */
	public const CRON_HOOK = 'outstand_fluentform_privacy_prune';

	/**
	 * Registers nothing unless Fluent Forms is active — the deletion cascade it delegates to must exist.
	 */
	public function can_register(): bool {
		return class_exists( SubmissionService::class );
	}

	/**
	 * Wires the daily prune schedule and its handler.
	 */
	public function register(): void {
		// The schedule only needs syncing where it can change: admin, cron, and CLI. Skipping it on
		// front-end requests avoids a filter + wp_next_scheduled() call on every anonymous page view.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'init', [ $this, 'sync_schedule' ] );
		}

		add_action( self::CRON_HOOK, [ $this, 'run' ] );
	}

	/**
	 * The retention window in months, or 0 when retention is disabled.
	 *
	 * @return int
	 */
	public static function months(): int {
		return max( 0, (int) apply_filters( 'outstand_fluentform_privacy_retention_months', 0 ) );
	}

	/**
	 * The datetime cutoff: submissions created before this are past the window.
	 *
	 * Computed in site-local time to match Fluent Forms' `created_at`, which is written with
	 * `current_time( 'mysql' )` — comparing against a UTC cutoff would be off by the site's offset.
	 *
	 * Subtracting months is anchored to the first of the month before clamping the day, so a date like
	 * Aug 31 minus 6 months lands on Feb 28, not Mar 3 — PHP's relative "-N months" arithmetic overflows
	 * short months, which would prune entries a few days before the window has actually elapsed.
	 *
	 * @param  int      $months Retention window in months.
	 * @param  int|null $now    Reference timestamp; defaults to the site's current time. For tests.
	 * @return string MySQL datetime.
	 */
	public static function cutoff( int $months, ?int $now = null ): string {
		$now = $now ?? (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		$date  = new \DateTimeImmutable( '@' . $now );
		$day   = (int) $date->format( 'j' );
		$first = $date->modify( 'first day of this month' )->modify( "-{$months} months" );
		$max   = (int) $first->format( 't' );

		return $first
			->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), min( $day, $max ) )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Schedules the daily prune when a window is configured, and clears it when it is not.
	 *
	 * @return void
	 */
	public function sync_schedule(): void {
		$months    = self::months();
		$scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );

		if ( $months > 0 ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time(), 'daily', self::CRON_HOOK );
			}
			return;
		}

		if ( $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * The scheduled prune: enforces the configured window, or no-ops when retention is off.
	 *
	 * @return void
	 */
	public function run(): void {
		$months = self::months();

		if ( $months <= 0 ) {
			return;
		}

		$this->prune( $months, false );
	}

	/**
	 * Handles every submission older than the window, grouped by form. An entry with a kept payment
	 * status is anonymized and keeps its payment record; every other entry is deleted.
	 *
	 * @param  int  $months  Retention window in months.
	 * @param  bool $dry_run When true, counts without changing anything.
	 * @return array<int,array{deleted:int,anonymized:int}> Counts keyed by form id. On a dry run, the
	 *                                                     entries that would be deleted or anonymized.
	 */
	public function prune( int $months, bool $dry_run ): array {
		global $wpdb;

		$cutoff = self::cutoff( $months );
		$table  = $wpdb->prefix . 'fluentform_submissions';
		$meta   = $wpdb->prefix . 'fluentform_submission_meta';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, form_id FROM {$table} WHERE created_at < %s AND id NOT IN (SELECT response_id FROM {$meta} WHERE meta_key = %s)", $cutoff, Anonymizer::META_ANONYMIZED ) );

		$by_form = [];
		foreach ( (array) $rows as $row ) {
			$by_form[ (int) $row->form_id ][] = (int) $row->id;
		}

		$anonymizer = new Anonymizer();
		$counts     = [];

		foreach ( $by_form as $form_id => $ids ) {
			$kept      = $anonymizer->kept_ids( $ids );
			$deletable = array_values( array_diff( $ids, $kept ) );

			if ( $dry_run ) {
				$counts[ $form_id ] = [
					'deleted'    => count( $deletable ),
					'anonymized' => count( $kept ),
				];
				continue;
			}

			$anonymized = $anonymizer->anonymize( $kept, $form_id );

			if ( ! empty( $deletable ) ) {
				try {
					( new SubmissionService() )->deleteEntries( $deletable, $form_id );
				} catch ( \Throwable $e ) {
					// One form failing must not abort the whole prune; the next scheduled run retries. The
					// count below reads live DB state, so a failed group reports 0 deleted.
					unset( $e );
				}
			}

			$counts[ $form_id ] = [
				'deleted'    => count( $deletable ) - $this->still_present( $deletable ),
				'anonymized' => $anonymized,
			];
		}

		return $counts;
	}

	/**
	 * How many of the given submission ids still exist — to report the count actually deleted.
	 *
	 * @param  array<int,int> $ids Submission ids that were targeted.
	 * @return int
	 */
	protected function still_present( array $ids ): int {
		global $wpdb;

		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = $wpdb->prefix . 'fluentform_submissions';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}
}
