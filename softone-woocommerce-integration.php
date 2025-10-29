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
 * Plugin Name:       Softon Woocommerce Integration
 * Plugin URI:        https://www.georgenicolaou.me/plugins/softone-woocommerce-integration
 * Description:       Softone Woocommerce Integration
 * Version:           1.0.0
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
define( 'SOFTONE_WOOCOMMERCE_INTEGRATION_VERSION', '1.0.0' );

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
