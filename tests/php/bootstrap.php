<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Outstand\WP\FluentForm\Addons\Privacy
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Ensure WP_TESTS_DIR is set or run: npm run test:setup\n"; // phpcs:ignore
	exit( 1 );
}

// Load Composer autoloader (plugin classes live under PSR-4).
$plugin_dir = dirname( __DIR__, 2 );
if ( file_exists( $plugin_dir . '/vendor/autoload.php' ) ) {
	require_once $plugin_dir . '/vendor/autoload.php';
}

// Minimal WP_CLI stub so Cli::prune() can be exercised outside a real WP-CLI process. error() throws
// instead of exiting, so the error paths are catchable; log()/success() collect messages for asserts.
if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Test double for WP_CLI.
	 */
	class WP_CLI {

		/**
		 * Captured log() messages.
		 *
		 * @var array<int,string>
		 */
		public static array $logs = [];

		/**
		 * Captured success() messages.
		 *
		 * @var array<int,string>
		 */
		public static array $successes = [];

		/**
		 * Records a log message.
		 *
		 * @param  string $message The message.
		 * @return void
		 */
		public static function log( string $message ): void {
			self::$logs[] = $message;
		}

		/**
		 * Records a success message.
		 *
		 * @param  string $message The message.
		 * @return void
		 */
		public static function success( string $message ): void {
			self::$successes[] = $message;
		}

		/**
		 * Simulates a fatal CLI error by throwing, so tests catch it instead of the process exiting.
		 *
		 * @param  string $message The message.
		 * @return void
		 * @throws \RuntimeException Always.
		 */
		public static function error( string $message ): void {
			throw new \RuntimeException( esc_html( $message ) );
		}

		/**
		 * No-op command registration.
		 *
		 * @param  string $name     Command name.
		 * @param  mixed  $handler  Command callable/class.
		 * @return void
		 */
		public static function add_command( string $name, $handler ): void {
			unset( $name, $handler );
		}
	}
}

// Load WordPress test suite functions.
require_once $_tests_dir . '/includes/functions.php';

// Load Fluent Forms (free + Pro) alongside the plugin so the integration tests exercise the real
// deletion cascade. wp-env creates the Fluent Forms tables on activation but does not load its code
// during the PHPUnit run, so require the plugin files here when present. Tests skip gracefully when
// they are absent (e.g. a CI job without Fluent Forms).
tests_add_filter(
	'muplugins_loaded',
	function () use ( $plugin_dir ) {
		$plugins_dir = dirname( $plugin_dir );

		foreach ( [ 'fluentform/fluentform.php', 'fluentformpro/fluentformpro.php' ] as $fluent_plugin ) {
			$path = $plugins_dir . '/' . $fluent_plugin;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		require $plugin_dir . '/plugin.php';
	}
);

// Bootstrap WordPress test suite.
require $_tests_dir . '/includes/bootstrap.php';
