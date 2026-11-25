<?php // phpcs:ignore Generic.Commenting.DocComment.MissingShort
/**
 * @wordpress-plugin
 * Plugin Name:       Fluent Forms - Privacy
 * Description:       Adds Fluent Forms entries to the WordPress Export and Erase Personal Data tools, with consent records, entry retention and per-form data minimization.
 * Plugin URI:        https://outstand.site/?utm_source=wp-plugins&utm_medium=fluentform-privacy&utm_campaign=plugin-uri
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Version:           1.1.1
 * Author:            Outstand
 * Author URI:        https://outstand.site/?utm_source=wp-plugins&utm_medium=fluentform-privacy&utm_campaign=author-uri
 * License:           GPL-3.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-3.0-or-later.html
 * Update URI:        https://outstand.site/
 * GitHub Plugin URI: https://github.com/pixelalbatross/fluentform-privacy
 * Text Domain:       fluentform-privacy
 * Domain Path:       /languages
 * Requires Plugins:  fluentform
 *
 * @package Outstand
 */

namespace Outstand\WP\FluentForm\Addons\Privacy;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$plugin_version = '1.1.1';
if ( file_exists( __DIR__ . '/.commit' ) ) {
	$plugin_version .= '-' . trim( (string) file_get_contents( __DIR__ . '/.commit' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

define( 'OUTSTAND_FLUENTFORM_PRIVACY_VERSION', $plugin_version );
define( 'OUTSTAND_FLUENTFORM_PRIVACY_BASENAME', plugin_basename( __FILE__ ) );
define( 'OUTSTAND_FLUENTFORM_PRIVACY_URL', plugin_dir_url( __FILE__ ) );
define( 'OUTSTAND_FLUENTFORM_PRIVACY_PATH', plugin_dir_path( __FILE__ ) );

if ( file_exists( OUTSTAND_FLUENTFORM_PRIVACY_PATH . 'vendor/autoload.php' ) ) {
	require_once OUTSTAND_FLUENTFORM_PRIVACY_PATH . 'vendor/autoload.php';
}

if ( class_exists( PucFactory::class ) ) {
	PucFactory::buildUpdateChecker(
		'https://github.com/pixelalbatross/fluentform-privacy/',
		__FILE__,
		'fluentform-privacy'
	)->setBranch( 'main' );
}

register_deactivation_hook( __FILE__, __NAMESPACE__ . '\Plugin::deactivate' );

add_action(
	'plugins_loaded',
	function () {
		Plugin::get_instance()->enable();
	}
);
