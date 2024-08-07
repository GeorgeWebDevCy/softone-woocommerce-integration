<?php
/**
 * Plugin Name: Softone WooCommerce Integration
 * Plugin URI: https://wordpress.org/plugins/softone-woocommerce-integration/
 * Description: Integrates WooCommerce with Softone API for customer, product, and order synchronization.
 * Version: 1.0.0
 * Author: George Nicolaou
 * Author URI: https://profiles.wordpress.org/georgenicolaou/
 * Text Domain: softone-woocommerce-integration
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Check if WooCommerce is active
function softone_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('This plugin requires WooCommerce to be installed and active.');
    }
}
register_activation_hook(__FILE__, 'softone_check_woocommerce');

// Define plugin path
define('SOFTONE_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Include necessary files
require_once SOFTONE_PLUGIN_PATH . 'includes/api.php';
require_once SOFTONE_PLUGIN_PATH . 'includes/logging.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/settings-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/customer-sync-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/product-sync-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/order-sync-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/logs-page.php';
require 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/GeorgeWebDevCy/softone-woocommerce-integration',
    __FILE__,
    'softone-woocommerce-integration'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('main');

// Initialize plugin
function softone_woocommerce_integration_init() {
    // Add admin menu
    add_action('admin_menu', 'softone_admin_menu');
    // Register settings
    add_action('admin_init', 'softone_register_settings');
    // Add cron jobs
    add_action('softone_cron_sync_products', 'softone_sync_products');
    add_action('softone_cron_sync_orders', 'softone_sync_orders');
    // Hook into WooCommerce order processed
    add_action('woocommerce_checkout_order_processed', 'softone_create_order', 10, 1);
    // Hook into WooCommerce customer creation
    add_action('user_register', 'softone_send_new_customer_to_api', 10, 1);
    // Schedule cron jobs
    softone_schedule_cron_jobs();
}
add_action('plugins_loaded', 'softone_woocommerce_integration_init');

// Schedule cron jobs
function softone_schedule_cron_jobs() {
    if (!wp_next_scheduled('softone_cron_sync_products')) {
        wp_schedule_event(time(), 'two_hours', 'softone_cron_sync_products');
    }
    if (!wp_next_scheduled('softone_cron_sync_orders')) {
        wp_schedule_event(time(), 'hourly', 'softone_cron_sync_orders');
    }
}

// Clear scheduled cron jobs on deactivation
function softone_clear_scheduled_cron_jobs() {
    wp_clear_scheduled_hook('softone_cron_sync_products');
    wp_clear_scheduled_hook('softone_cron_sync_orders');
}
register_deactivation_hook(__FILE__, 'softone_clear_scheduled_cron_jobs');

// Admin menu setup
function softone_admin_menu() {
    add_menu_page('Softone Integration', 'Softone', 'manage_options', 'softone-settings', 'softone_settings_page');
    add_submenu_page('softone-settings', 'Customer Sync', 'Customers', 'manage_options', 'softone-customers', 'softone_customers_page');
    add_submenu_page('softone-settings', 'Product Sync', 'Products', 'manage_options', 'softone-products', 'softone_products_page');
    add_submenu_page('softone-settings', 'Order Sync', 'Orders', 'manage_options', 'softone-orders', 'softone_orders_page');
    add_submenu_page('softone-settings', 'Live Logging', 'Logs', 'manage_options', 'softone-logs', 'softone_logs_page');
}

// Register settings
function softone_register_settings() {
    register_setting('softone_settings_group', 'softone_api_username', 'sanitize_text_field');
    register_setting('softone_settings_group', 'softone_api_password', 'sanitize_text_field');
}

// Activation hook to set default options
function softone_activate() {
    // Set default values for the options if they don't exist
    if (get_option('softone_api_username') === false) {
        update_option('softone_api_username', 'default_username');
    }
    if (get_option('softone_api_password') === false) {
        update_option('softone_api_password', 'default_password');
    }
    if (get_option('softone_client_id') === false) {
        update_option('softone_client_id', 'default_client_id');
    }
    if (get_option('softone_synced_customers') === false) {
        update_option('softone_synced_customers', []);
    }
    if (get_option('softone_synced_products') === false) {
        update_option('softone_synced_products', []);
    }
    if (get_option('softone_api_logs') === false) {
        update_option('softone_api_logs', []);
    }
    // Schedule cron jobs
    softone_schedule_cron_jobs();
}
register_activation_hook(__FILE__, 'softone_activate');

// Cleanup on deactivation
function softone_cleanup() {
    delete_option('softone_api_username');
    delete_option('softone_api_password');
    delete_option('softone_client_id');
    delete_option('softone_synced_customers');
    delete_option('softone_synced_products');
    delete_option('softone_api_logs');
    softone_clear_scheduled_cron_jobs();
}
register_deactivation_hook(__FILE__, 'softone_cleanup');

// Custom cron schedules
add_filter('cron_schedules', 'softone_custom_cron_schedules');
function softone_custom_cron_schedules($schedules) {
    $schedules['two_hours'] = [
        'interval' => 7200,
        'display' => __('Every Two Hours')
    ];
    return $schedules;
}

// Hook into WooCommerce order creation
function softone_create_order($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
        $api = new Softone_API();
        $api->create_order($order);
    }
}

// Function to send new customers to Softone
function softone_send_new_customer_to_api($customer_id) {
    $user = get_userdata($customer_id);
    if ($user && in_array('customer', $user->roles)) {
        $api = new Softone_API();
        $customer_data = [
            'CUSTOMER' => [
                [
                    'CODE' => $user->user_login,
                    'NAME' => $user->first_name,
                    'EMAIL' => $user->user_email,
                    'ADDRESS' => get_user_meta($customer_id, 'billing_address_1', true),
                    'CITY' => get_user_meta($customer_id, 'billing_city', true),
                    'ZIP' => get_user_meta($customer_id, 'billing_postcode', true),
                    'COUNTRY' => get_user_meta($customer_id, 'billing_country', true),
                    'PHONE1' => get_user_meta($customer_id, 'billing_phone', true),
                ]
            ]
        ];
        $api->request('setData', [
            'clientID' => $api->session,
            'appID' => 1000,
            'object' => 'CUSTOMER',
            'data' => $customer_data
        ]);
        softone_log('send_new_customer', 'New customer sent to Softone: ' . $user->user_login);
    }
}

// Sync products
function softone_sync_products() {
    if (class_exists('WooCommerce')) {
        $api = new Softone_API();
        $products = $api->get_products();
        if ($products && isset($products['rows'])) {
            foreach ($products['rows'] as $product) {
                // Check if product exists by SKU
                $existing_product_id = wc_get_product_id_by_sku($product['SKU']);
                if ($existing_product_id) {
                    // Update existing product
                    $product_obj = new WC_Product($existing_product_id);
                    $product_obj->set_name(sanitize_text_field($product['DESC']));
                    $product_obj->set_price(floatval($product['RETAILPRICE']));
                    $product_obj->set_regular_price(floatval($product['RETAILPRICE']));
                    $product_obj->set_stock_quantity(intval($product['Stock QTY']));
                    $product_obj->set_manage_stock(true);

                    // Update categories and subcategories
                    $category_ids = array();
                    if (!empty($product['COMMECATEGORY_NAME'])) {
                        $category_id = get_term_by('name', sanitize_text_field($product['COMMECATEGORY_NAME']), 'product_cat');
                        if ($category_id) {
                            $category_ids[] = $category_id->term_id;
                        } else {
                            // Create new category if it does not exist
                            $new_category = wp_insert_term(sanitize_text_field($product['COMMECATEGORY_NAME']), 'product_cat');
                            if (!is_wp_error($new_category)) {
                                $category_ids[] = $new_category['term_id'];
                            }
                        }
                    }
                    if (!empty($product['SUBMECATEGORY_NAME'])) {
                        $subcategory_id = get_term_by('name', sanitize_text_field($product['SUBMECATEGORY_NAME']), 'product_cat');
                        if ($subcategory_id) {
                            $category_ids[] = $subcategory_id->term_id;
                        } else {
                            // Create new subcategory if it does not exist
                            $new_subcategory = wp_insert_term(sanitize_text_field($product['SUBMECATEGORY_NAME']), 'product_cat');
                            if (!is_wp_error($new_subcategory)) {
                                $category_ids[] = $new_subcategory['term_id'];
                            }
                        }
                    }
                    if (!empty($category_ids)) {
                        $product_obj->set_category_ids($category_ids);
                    }

                    $product_obj->save();
                } else {
                    // Create new product
                    $new_product = new WC_Product();
                    $new_product->set_name(sanitize_text_field($product['DESC']));
                    $new_product->set_sku(sanitize_text_field($product['SKU']));
                    $new_product->set_price(floatval($product['RETAILPRICE']));
                    $new_product->set_regular_price(floatval($product['RETAILPRICE']));
                    $new_product->set_stock_quantity(intval($product['Stock QTY']));
                    $new_product->set_manage_stock(true);

                    // Set categories and subcategories
                    $category_ids = array();
                    if (!empty($product['COMMECATEGORY_NAME'])) {
                        $category_id = get_term_by('name', sanitize_text_field($product['COMMECATEGORY_NAME']), 'product_cat');
                        if ($category_id) {
                            $category_ids[] = $category_id->term_id;
                        } else {
                            // Create new category if it does not exist
                            $new_category = wp_insert_term(sanitize_text_field($product['COMMECATEGORY_NAME']), 'product_cat');
                            if (!is_wp_error($new_category)) {
                                $category_ids[] = $new_category['term_id'];
                            }
                        }
                    }
                    if (!empty($product['SUBMECATEGORY_NAME'])) {
                        $subcategory_id = get_term_by('name', sanitize_text_field($product['SUBMECATEGORY_NAME']), 'product_cat');
                        if ($subcategory_id) {
                            $category_ids[] = $subcategory_id->term_id;
                        } else {
                            // Create new subcategory if it does not exist
                            $new_subcategory = wp_insert_term(sanitize_text_field($product['SUBMECATEGORY_NAME']), 'product_cat');
                            if (!is_wp_error($new_subcategory)) {
                                $category_ids[] = $new_subcategory['term_id'];
                            }
                        }
                    }
                    if (!empty($category_ids)) {
                        $new_product->set_category_ids($category_ids);
                    }

                    $new_product->save();
                }
            }
            update_option('softone_synced_products', array_map('sanitize_text_field', $products['rows']));
            softone_log('sync_products', 'Products synchronized successfully.');
            return ['success' => true, 'message' => 'Products synchronized successfully.', 'products' => $products['rows']];
        } else {
            softone_log('sync_products', 'Failed to synchronize products.');
            return ['success' => false, 'message' => 'Failed to synchronize products.'];
        }
    }
}

function softone_sync_orders() {
    if (class_exists('WooCommerce')) {
        $api = new Softone_API();
        $orders = wc_get_orders(['limit' => -1]);
        foreach ($orders as $order) {
            $api->create_order($order);
        }
        softone_log('sync_orders', 'Orders synchronized successfully.');
        return 'Orders synchronized successfully.';
    }
}

