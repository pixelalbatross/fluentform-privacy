<?php

namespace Outstand\WP\FluentForm\Addons\Privacy\Tests\Unit;

use Outstand\WP\FluentForm\Addons\Privacy\Retention;
use WP_UnitTestCase;

/**
 * Retention window + prune.
 *
 * @covers \Outstand\WP\FluentForm\Addons\Privacy\Retention
 */
class RetentionTest extends WP_UnitTestCase {

	/**
	 * Retention is off by default and opt-in through the filter.
	 *
	 * @return void
	 */
	public function test_months_is_off_by_default_and_filterable(): void {
		$this->assertSame( 0, Retention::months() );

		$callback = static fn () => 12;
		add_filter( 'outstand_fluentform_privacy_retention_months', $callback );
		$this->assertSame( 12, Retention::months() );
		remove_filter( 'outstand_fluentform_privacy_retention_months', $callback );
	}

	/**
	 * The cutoff is a MySQL datetime roughly the window in the past.
	 *
	 * @return void
	 */
	public function test_cutoff_is_in_the_past(): void {
		$cutoff_ts = strtotime( Retention::cutoff( 12 ) );

		$this->assertLessThan( strtotime( '-11 months' ), $cutoff_ts );
		$this->assertGreaterThan( strtotime( '-13 months' ), $cutoff_ts );
	}

	/**
	 * The daily prune is scheduled only while a window is configured, and cleared once it is not.
	 *
	 * @return void
	 */
	public function test_sync_schedule_tracks_the_window(): void {
		$retention = new Retention();

		wp_clear_scheduled_hook( Retention::CRON_HOOK );

		$callback = static fn () => 12;
		add_filter( 'outstand_fluentform_privacy_retention_months', $callback );
		$retention->sync_schedule();
		$this->assertNotFalse( wp_next_scheduled( Retention::CRON_HOOK ) );
		remove_filter( 'outstand_fluentform_privacy_retention_months', $callback );

		$retention->sync_schedule();
		$this->assertFalse( wp_next_scheduled( Retention::CRON_HOOK ) );
	}

	/**
	 * Prune deletes entries older than the window and leaves newer ones intact.
	 *
	 * @return void
	 */
	public function test_prune_deletes_only_old_entries(): void {
		global $wpdb;

		if ( ! class_exists( \FluentForm\App\Services\Submission\SubmissionService::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is not active.' );
		}

		$submissions = $wpdb->prefix . 'fluentform_submissions';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions ) ) !== $submissions ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}

		$form_id = $this->seed_form();
		$old     = $this->seed_submission( $form_id, gmdate( 'Y-m-d H:i:s', strtotime( '-18 months' ) ) );
		$new     = $this->seed_submission( $form_id, current_time( 'mysql' ) );

		$counts = ( new Retention() )->prune( 12, false );

		$this->assertSame( 1, array_sum( $counts ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$submissions} WHERE id = %d", $old ) ) );
		$this->assertSame( (string) $new, $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$submissions} WHERE id = %d", $new ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The cutoff clamps day-of-month overflow when subtracting lands in a shorter month — including a
	 * leap-year February — and passes through untouched when there's nothing to clamp.
	 *
	 * @return void
	 */
	public function test_cutoff_clamps_month_overflow(): void {
		$this->assertSame( '2026-02-28 14:05:00', Retention::cutoff( 6, strtotime( '2026-08-31 14:05:00 UTC' ) ) );
		$this->assertSame( '2026-02-28 00:00:00', Retention::cutoff( 1, strtotime( '2026-03-31 00:00:00 UTC' ) ) );
		$this->assertSame( '2024-02-29 23:59:59', Retention::cutoff( 10, strtotime( '2024-12-31 23:59:59 UTC' ) ) );
		$this->assertSame( '2025-01-15 09:00:00', Retention::cutoff( 12, strtotime( '2026-01-15 09:00:00 UTC' ) ) );
	}

	/**
	 * A dry run counts what would be deleted without actually deleting it.
	 *
	 * @return void
	 */
	public function test_prune_dry_run_counts_without_deleting(): void {
		global $wpdb;

		if ( ! class_exists( \FluentForm\App\Services\Submission\SubmissionService::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is not active.' );
		}

		$submissions = $wpdb->prefix . 'fluentform_submissions';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions ) ) !== $submissions ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}

		$form_id = $this->seed_form();
		$old     = $this->seed_submission( $form_id, gmdate( 'Y-m-d H:i:s', strtotime( '-18 months' ) ) );
		$this->seed_submission( $form_id, current_time( 'mysql' ) );

		$counts = ( new Retention() )->prune( 12, true );

		$this->assertSame( 1, array_sum( $counts ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( (string) $old, $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$submissions} WHERE id = %d", $old ) ) );
	}

	/**
	 * Prune groups its deletion counts by form.
	 *
	 * @return void
	 */
	public function test_prune_groups_counts_by_form(): void {
		global $wpdb;

		if ( ! class_exists( \FluentForm\App\Services\Submission\SubmissionService::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is not active.' );
		}

		$submissions = $wpdb->prefix . 'fluentform_submissions';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions ) ) !== $submissions ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}

		$form_a = $this->seed_form();
		$form_b = $this->seed_form();
		$this->seed_submission( $form_a, gmdate( 'Y-m-d H:i:s', strtotime( '-18 months' ) ) );
		$this->seed_submission( $form_b, gmdate( 'Y-m-d H:i:s', strtotime( '-18 months' ) ) );

		$counts = ( new Retention() )->prune( 12, false );

		$this->assertCount( 2, $counts );
		$this->assertSame( 1, $counts[ $form_a ] );
		$this->assertSame( 1, $counts[ $form_b ] );
	}

	/**
	 * The scheduled run only prunes while a retention window is configured; with none, it's a no-op.
	 *
	 * @return void
	 */
	public function test_run_prunes_only_when_a_window_is_configured(): void {
		global $wpdb;

		if ( ! class_exists( \FluentForm\App\Services\Submission\SubmissionService::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is not active.' );
		}

		$submissions = $wpdb->prefix . 'fluentform_submissions';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions ) ) !== $submissions ) {
			$this->markTestSkipped( 'Fluent Forms tables are not present.' );
		}

		$form_id = $this->seed_form();
		$old     = $this->seed_submission( $form_id, gmdate( 'Y-m-d H:i:s', strtotime( '-18 months' ) ) );

		( new Retention() )->run();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( (string) $old, $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$submissions} WHERE id = %d", $old ) ) );

		$callback = static fn () => 12;
		add_filter( 'outstand_fluentform_privacy_retention_months', $callback );

		( new Retention() )->run();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$submissions} WHERE id = %d", $old ) ) );

		remove_filter( 'outstand_fluentform_privacy_retention_months', $callback );
	}

	/**
	 * The prune schedule is only synced in admin/cron/CLI contexts: register() wires the init hook in
	 * wp-admin but not on a front-end request. Asserted via has_action rather than firing init, which
	 * would re-run other plugins' init handlers.
	 *
	 * @return void
	 */
	public function test_register_syncs_schedule_only_in_admin_context(): void {
		$front = new Retention();
		$front->register();
		$this->assertFalse( has_action( 'init', [ $front, 'sync_schedule' ] ) );

		set_current_screen( 'dashboard' );

		$admin = new Retention();
		$admin->register();
		$this->assertNotFalse( has_action( 'init', [ $admin, 'sync_schedule' ] ) );

		remove_action( 'init', [ $admin, 'sync_schedule' ] );
		set_current_screen( 'front' );
	}

	/**
	 * Seeds a Fluent Forms form, returning its id.
	 *
	 * @return int
	 */
	private function seed_form(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_forms',
			[
				'title'       => 'Retention Test',
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
	 * Seeds one submission with a given created_at, returning its id.
	 *
	 * @param  int    $form_id    The form id.
	 * @param  string $created_at MySQL datetime.
	 * @return int
	 */
	private function seed_submission( int $form_id, string $created_at ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_submissions',
			[
				'form_id'    => $form_id,
				'response'   => (string) wp_json_encode( [ 'email' => 'r@example.test' ] ),
				'status'     => 'unread',
				'created_at' => $created_at,
				'updated_at' => $created_at,
			]
		);

		return (int) $wpdb->insert_id;
	}
}
