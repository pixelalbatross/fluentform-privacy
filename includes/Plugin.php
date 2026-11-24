<?php

namespace Outstand\WP\FluentForm\Addons\Privacy;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Singleton instance of the Plugin.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns singleton instance.
	 *
	 * @return Plugin The singleton instance.
	 */
	public static function get_instance(): Plugin {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Enable plugin functionality.
	 *
	 * @return void
	 */
	public function enable(): void {

		$modules = [
			new Privacy(),
			new Consent(),
			new Retention(),
			new DataMinimization(),
			new Cli(),
		];

		foreach ( $modules as $module ) {
			if ( $module instanceof BaseModule && $module->can_register() ) {
				$module->register();
			}
		}
	}

	/**
	 * Clears scheduled events on deactivation so no retention prune fires while inactive.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Retention::CRON_HOOK );
	}
}
