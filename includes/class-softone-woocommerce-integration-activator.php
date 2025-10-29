<?php

/**
 * Fired during plugin activation
 *
 * @link       https://www.georgenicolaou.me/
 * @since      1.0.0
 *
 * @package    Softone_Woocommerce_Integration
 * @subpackage Softone_Woocommerce_Integration/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Softone_Woocommerce_Integration
 * @subpackage Softone_Woocommerce_Integration/includes
 * @author     George Nicolaou <orionas.elite@gmail.com>
 */
class Softone_Woocommerce_Integration_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
        public static function activate() {

                require_once plugin_dir_path( __FILE__ ) . 'class-softone-item-sync.php';

                Softone_Item_Sync::schedule_event();

        }

}
