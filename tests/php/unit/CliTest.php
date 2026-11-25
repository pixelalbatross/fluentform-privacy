<?php

namespace Outstand\WP\FluentForm\Addons\Privacy\Tests\Unit;

use Outstand\WP\FluentForm\Addons\Privacy\Cli;
use WP_UnitTestCase;

/**
 * WP-CLI `prune` command.
 *
 * Runs against the WP_CLI test double defined in the test bootstrap, whose error() throws so the
 * command's error paths are catchable and whose log()/success() collect messages for assertions.
 *
 * @covers \Outstand\WP\FluentForm\Addons\Privacy\Cli
 */
class CliTest extends WP_UnitTestCase {

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		\WP_CLI::$logs      = [];
		\WP_CLI::$successes = [];
	}

	/**
	 * Errors when Fluent Forms is not active.
	 *
	 * @return void
	 */
	public function test_prune_errors_when_fluent_forms_inactive(): void {
		if ( class_exists( \FluentForm\App\Services\Submission\SubmissionService::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is active.' );
		}

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Fluent Forms is not active' );

		( new Cli() )->prune( [], [] );
	}

	/**
	 * Errors when no retention window is configured and none is passed.
	 *
	 * @return void
	 */
	public function test_prune_errors_when_retention_disabled(): void {
		if ( ! class_exists( \FluentForm\App\Services\Submission\SubmissionService::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is not active.' );
		}

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Retention is disabled' );

		( new Cli() )->prune( [], [] );
	}

	/**
	 * A --months value below 1 or not a whole number is refused, so it never runs a shorter window.
	 *
	 * @dataProvider invalid_months
	 *
	 * @param  string $months The --months value.
	 * @return void
	 */
	public function test_prune_refuses_invalid_months( string $months ): void {
		if ( ! class_exists( \FluentForm\App\Services\Submission\SubmissionService::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is not active.' );
		}

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '--months must be a whole number of 1 or more.' );

		( new Cli() )->prune( [], [ 'months' => $months ] );
	}

	/**
	 * The --months values that prune() refuses.
	 *
	 * @return array<string,array{string}>
	 */
	public static function invalid_months(): array {
		return [
			'zero'     => [ '0' ],
			'negative' => [ '-3' ],
			'text'     => [ 'abc' ],
			'decimal'  => [ '1.5' ],
		];
	}

	/**
	 * A dry run reports what would be deleted without deleting it.
	 *
	 * @return void
	 */
	public function test_prune_dry_run_reports_without_deleting(): void {
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

		( new Cli() )->prune(
			[],
			[
				'months'  => '12',
				'dry-run' => true,
			]
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( (string) $old, $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$submissions} WHERE id = %d", $old ) ) );

		$this->assertSame( [ "Form {$form_id}: 1 to delete, 0 to anonymize, past 12 months" ], \WP_CLI::$logs );
		$this->assertSame( [ '1 entry would be deleted and 0 entries anonymized. Re-run without --dry-run to apply.' ], \WP_CLI::$successes );
	}

	/**
	 * Passing --months deletes past that window even with no configured retention filter.
	 *
	 * @return void
	 */
	public function test_prune_deletes_with_explicit_months(): void {
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

		( new Cli() )->prune( [], [ 'months' => '1' ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$submissions} WHERE id = %d", $old ) ) );

		$this->assertSame( [ 'Deleted 1 entry and anonymized 0 entries older than 1 month.' ], \WP_CLI::$successes );
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
				'title'       => 'Cli Test',
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
				'response'   => (string) wp_json_encode( [ 'email' => 'cli@example.test' ] ),
				'status'     => 'unread',
				'created_at' => $created_at,
				'updated_at' => $created_at,
			]
		);

		return (int) $wpdb->insert_id;
	}
}
