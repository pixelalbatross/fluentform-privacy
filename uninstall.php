<?php
/**
 * Uninstall cleanup.
 *
 * Deactivation clears the retention prune cron event, but a plugin can be deleted without being
 * deactivated first (an already-inactive install, an FTP delete). WordPress runs this file on
 * deletion, so clear the event here too. No plugin options are stored, and submission data lives in
 * Fluent Forms' own tables, so nothing else is removed.
 *
 * @package Outstand
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Mirrors Retention::CRON_HOOK; hardcoded because the plugin's autoloader may not run on uninstall.
wp_clear_scheduled_hook( 'outstand_fluentform_privacy_prune' );
