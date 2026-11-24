<?php

namespace Outstand\WP\FluentForm\Addons\Privacy;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Services\Submission\SubmissionService;

/**
 * WP-CLI commands. Registered only under WP-CLI.
 */
class Cli extends BaseModule {

	/**
	 * Registers nothing outside WP-CLI.
	 */
	public function can_register(): bool {
		return defined( 'WP_CLI' ) && \WP_CLI;
	}

	/**
	 * Registers the WP-CLI command.
	 */
	public function register(): void {
		\WP_CLI::add_command( 'fluentform-privacy', $this );
	}

	/**
	 * Deletes Fluent Forms entries older than the retention window.
	 *
	 * Retention is off unless a window is configured via the `outstand_fluentform_privacy_retention_months`
	 * filter, or passed here with `--months`. Run through the host's cron for reliable scheduling.
	 *
	 * ## OPTIONS
	 *
	 * [--months=<n>]
	 * : Retention window in months. Defaults to the configured window.
	 *
	 * [--dry-run]
	 * : Report what would be deleted without deleting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fluentform-privacy prune
	 *     wp fluentform-privacy prune --months=12 --dry-run
	 *
	 * @subcommand prune
	 * @param array<int,string>   $args       Positional args (unused).
	 * @param array<string,mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function prune( array $args, array $assoc_args ): void {
		if ( ! class_exists( SubmissionService::class ) ) {
			\WP_CLI::error( 'Fluent Forms is not active; there are no entries to prune.' );
		}

		$months = isset( $assoc_args['months'] ) ? max( 1, (int) $assoc_args['months'] ) : Retention::months();

		if ( $months <= 0 ) {
			\WP_CLI::error( 'Retention is disabled. Set the outstand_fluentform_privacy_retention_months filter or pass --months.' );
		}

		$dry_run = (bool) ( $assoc_args['dry-run'] ?? false );
		$counts  = ( new Retention() )->prune( $months, $dry_run );
		$total   = array_sum( $counts );

		foreach ( $counts as $form_id => $count ) {
			\WP_CLI::log( sprintf( 'Form %d: %d past %d months', $form_id, $count, $months ) );
		}

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( '%d entr(y|ies) would be deleted. Re-run without --dry-run to delete.', $total ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Deleted %d entr(y|ies) older than %d months.', $total, $months ) );
	}
}
