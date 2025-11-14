<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://www.georgenicolaou.me/
 * @since             1.0.0
 * @package           Softone_Woocommerce_Integration
 *
 * @wordpress-plugin
 * Plugin Name:       Softone Woocommerce Integration
 * Plugin URI:        https://www.georgenicolaou.me/plugins/softone-woocommerce-integration
 * Description:       Softone Woocommerce Integration
 * Version:           1.8.97
 * Author:            George Nicolaou
 * Author URI:        https://www.georgenicolaou.me//
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       softone-woocommerce-integration
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'SOFTONE_WOOCOMMERCE_INTEGRATION_VERSION', '1.8.97' );

// Load Composer autoloader when present (e.g. when installed via Composer).
$softone_wc_integration_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $softone_wc_integration_autoload ) ) {
	require $softone_wc_integration_autoload;
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-softone-woocommerce-integration-activator.php
 */
function activate_softone_woocommerce_integration() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-softone-woocommerce-integration-activator.php';
	Softone_Woocommerce_Integration_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-softone-woocommerce-integration-deactivator.php
 */
function deactivate_softone_woocommerce_integration() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-softone-woocommerce-integration-deactivator.php';
	Softone_Woocommerce_Integration_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_softone_woocommerce_integration' );
register_deactivation_hook( __FILE__, 'deactivate_softone_woocommerce_integration' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-softone-woocommerce-integration.php';

/**
 * Bootstraps the plugin update checker when the library is available.
 *
 * Uses Yahnis Elsts' plugin-update-checker package (installed via Composer) to
 * discover updates from a VCS repository. Developers can override the default
 * configuration with filters:
 *
 * - `softone_woocommerce_integration_update_url` (string) Repository API URL.
 * - `softone_woocommerce_integration_update_branch` (string) Target branch name.
 * - `softone_woocommerce_integration_use_release_assets` (bool) Toggle release assets.
 *
 * @return void
 */
function softone_woocommerce_integration_bootstrap_update_checker() {
	$namespaced_factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';
	$legacy_factory     = '\Puc_v4_Factory';

	if ( ! class_exists( $namespaced_factory ) && ! class_exists( $legacy_factory ) ) {
		$embedded_loader = __DIR__ . '/vendor/yahnis-elsts/plugin-update-checker/load-v5p6.php';

		if ( file_exists( $embedded_loader ) ) {
			require_once $embedded_loader;
		}
	}

	if ( ! class_exists( $namespaced_factory ) && ! class_exists( $legacy_factory ) ) {
		return;
	}

        $default_repository = 'https://github.com/GeorgeWebDevCy/softone-woocommerce-integration';
	$repository_url     = apply_filters( 'softone_woocommerce_integration_update_url', $default_repository );

	if ( empty( $repository_url ) ) {
		return;
	}

	$repository_url = rtrim( $repository_url, '/' ) . '/';

	if ( class_exists( $namespaced_factory ) ) {
		$update_checker = $namespaced_factory::buildUpdateChecker(
			$repository_url,
			__FILE__,
			'softone-woocommerce-integration'
		);
	} elseif ( class_exists( $legacy_factory ) ) { // @codeCoverageIgnore - backwards compatibility.
		$update_checker = $legacy_factory::buildUpdateChecker(
			$repository_url,
			__FILE__,
			'softone-woocommerce-integration'
		);
	}

	if ( empty( $update_checker ) ) {
		return;
	}

	$branch = apply_filters( 'softone_woocommerce_integration_update_branch', 'main' );
	if ( ! empty( $branch ) && method_exists( $update_checker, 'setBranch' ) ) {
		$update_checker->setBranch( $branch );
	}

	$use_release_assets = apply_filters( 'softone_woocommerce_integration_use_release_assets', false );
	if ( $use_release_assets && method_exists( $update_checker, 'getVcsApi' ) ) {
		$vcs_api = $update_checker->getVcsApi();
		if ( is_object( $vcs_api ) && method_exists( $vcs_api, 'enableReleaseAssets' ) ) {
			$vcs_api->enableReleaseAssets();
		}
	}

	/**
	 * Fires after the update checker has been initialized.
	 *
	 * @param object $update_checker The update checker instance.
	 */
	do_action( 'softone_woocommerce_integration_update_checker_ready', $update_checker );
}
add_action( 'plugins_loaded', 'softone_woocommerce_integration_bootstrap_update_checker', 1 );

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_softone_woocommerce_integration() {

	$plugin = new Softone_Woocommerce_Integration();
	$plugin->run();

}
run_softone_woocommerce_integration();
