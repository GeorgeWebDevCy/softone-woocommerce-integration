<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://www.georgenicolaou.me/
 * @since      1.0.0
 *
 * @package    Softone_Woocommerce_Integration
 * @subpackage Softone_Woocommerce_Integration/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Softone_Woocommerce_Integration
 * @subpackage Softone_Woocommerce_Integration/includes
 * @author     George Nicolaou <orionas.elite@gmail.com>
 */
class Softone_Woocommerce_Integration_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
        public static function deactivate() {

                require_once plugin_dir_path( __FILE__ ) . 'class-softone-item-sync.php';
                require_once plugin_dir_path( __FILE__ ) . 'class-softone-item-cron-manager.php';
                require_once plugin_dir_path( __FILE__ ) . 'class-softone-order-sync.php';
                require_once plugin_dir_path( __FILE__ ) . 'class-softone-checkout-diagnostics.php';

                Softone_Item_Cron_Manager::clear_scheduled_event();

                if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                        wp_clear_scheduled_hook( Softone_Order_Sync::CRON_HOOK_RETRY_EXPORT );
                        wp_clear_scheduled_hook( Softone_Checkout_Diagnostics::CRON_HOOK_SEND_DEFERRED_EMAIL );
                }

        }

}
