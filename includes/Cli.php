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
	 * Deletes Fluent Forms entries older than the retention window, and anonymizes the paid ones.
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

		$months = Retention::months();

		if ( isset( $assoc_args['months'] ) ) {
			$given  = trim( (string) $assoc_args['months'] );
			$months = (int) $given;

			if ( $months < 1 || (string) $months !== $given ) {
				\WP_CLI::error( '--months must be a whole number of 1 or more.' );
			}
		}

		if ( $months <= 0 ) {
			\WP_CLI::error( 'Retention is disabled. Set the outstand_fluentform_privacy_retention_months filter or pass --months.' );
		}

		$dry_run    = (bool) ( $assoc_args['dry-run'] ?? false );
		$counts     = ( new Retention() )->prune( $months, $dry_run );
		$deleted    = array_sum( array_column( $counts, 'deleted' ) );
		$anonymized = array_sum( array_column( $counts, 'anonymized' ) );

		foreach ( $counts as $form_id => $count ) {
			\WP_CLI::log( sprintf( 'Form %d: %d to delete, %d to anonymize, past %s', $form_id, $count['deleted'], $count['anonymized'], $this->count_label( $months, 'month', 'months' ) ) );
		}

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( '%s would be deleted and %s anonymized. Re-run without --dry-run to apply.', $this->count_label( $deleted, 'entry', 'entries' ), $this->count_label( $anonymized, 'entry', 'entries' ) ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Deleted %s and anonymized %s older than %s.', $this->count_label( $deleted, 'entry', 'entries' ), $this->count_label( $anonymized, 'entry', 'entries' ), $this->count_label( $months, 'month', 'months' ) ) );
	}

	/**
	 * A count followed by the singular or plural noun, such as "1 entry" or "3 entries".
	 *
	 * @param  int    $count    The count.
	 * @param  string $singular The noun for a count of 1.
	 * @param  string $plural   The noun for any other count.
	 * @return string
	 */
	private function count_label( int $count, string $singular, string $plural ): string {
		$noun = 1 === $count ? $singular : $plural;

		return sprintf( '%d %s', $count, $noun );
	}
}
