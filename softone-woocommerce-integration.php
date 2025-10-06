<?php
/**
 * Plugin Name: Softone WooCommerce Integration
 * Plugin URI: https://wordpress.org/plugins/softone-woocommerce-integration/
 * Description: Integrates WooCommerce with Softone API for customer, product, and order synchronization.
 * Version: 2.2.55
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

// Helper functions for encrypting and decrypting the API password
function softone_encrypt($data) {
    if ($data === '') {
        return '';
    }
    $key = hash('sha256', AUTH_KEY);
    $iv  = substr($key, 0, 16);
    return base64_encode(openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv));
}

function softone_decrypt($data) {
    if ($data === '' || $data === false) {
        return '';
    }
    $key = hash('sha256', AUTH_KEY);
    $iv  = substr($key, 0, 16);
    return openssl_decrypt(base64_decode($data), 'AES-256-CBC', $key, 0, $iv);
}

function softone_sanitize_api_password($password) {
    return $password ? softone_encrypt($password) : '';
}

// Include necessary files
require_once SOFTONE_PLUGIN_PATH . 'includes/api.php';
require_once SOFTONE_PLUGIN_PATH . 'includes/logging.php';
require_once SOFTONE_PLUGIN_PATH . 'includes/menu-sync.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/settings-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/customer-sync-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/product-sync-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/order-sync-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/logs-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/customer-logs-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/order-logs-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/request-tester-page.php';
require_once SOFTONE_PLUGIN_PATH . 'admin/api-fields-page.php';
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
    add_action('softone_cron_sync_customers', 'softone_sync_customers');
    add_action('softone_cron_sync_products', 'softone_sync_products');
    add_action('softone_cron_sync_menu', 'softone_run_auto_sync_product_menu');
    add_action('softone_cron_sync_orders', 'softone_sync_orders');
    // Hook into WooCommerce order processed
    add_action('woocommerce_checkout_order_processed', 'softone_create_order', 10, 1);
    // Schedule cron jobs
    softone_schedule_cron_jobs();

}
add_action('plugins_loaded', 'softone_woocommerce_integration_init');

function softone_load_textdomain() {
    load_plugin_textdomain('softone-woocommerce-integration', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'softone_load_textdomain');

// Register product brand taxonomy
function softone_register_brand_taxonomy() {
    $labels = [
        'name'          => _x('Brands', 'taxonomy general name', 'softone-woocommerce-integration'),
        'singular_name' => _x('Brand', 'taxonomy singular name', 'softone-woocommerce-integration'),
    ];
    $args = [
        'hierarchical'      => true,
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => ['slug' => 'brand'],
    ];

    if (!taxonomy_exists('product_brand')) {
        register_taxonomy('product_brand', ['product'], $args);
    }
}
add_action('init', 'softone_register_brand_taxonomy');

// Ensure the Products menu structure is created after rewrite rules are loaded
add_action('init', function () {
    if (function_exists('softone_ensure_menu_structure')) {
        softone_ensure_menu_structure('Main Menu', 'Products');
    }
}, 20);

// Schedule cron jobs
function softone_schedule_cron_jobs() {
    if (!wp_next_scheduled('softone_cron_sync_customers')) {
        wp_schedule_event(time(), 'hourly', 'softone_cron_sync_customers');
    }
    if (!wp_next_scheduled('softone_cron_sync_products')) {
        wp_schedule_event(time(), 'two_minutes', 'softone_cron_sync_products');
    }
    if (!wp_next_scheduled('softone_cron_sync_menu')) {
        wp_schedule_event(time(), 'two_minutes', 'softone_cron_sync_menu');
    }
    if (!wp_next_scheduled('softone_cron_sync_orders')) {
        wp_schedule_event(time(), 'hourly', 'softone_cron_sync_orders');
    }
}

// Clear scheduled cron jobs on deactivation
function softone_clear_scheduled_cron_jobs() {
    wp_clear_scheduled_hook('softone_cron_sync_customers');
    wp_clear_scheduled_hook('softone_cron_sync_products');
    wp_clear_scheduled_hook('softone_cron_sync_menu');
    wp_clear_scheduled_hook('softone_cron_sync_orders');
}
register_deactivation_hook(__FILE__, 'softone_clear_scheduled_cron_jobs');

// Admin menu setup
function softone_admin_menu() {
    softone_debug_log('admin_menu', 'Registering admin menu.');

    $main_hook = add_menu_page(
        __('Softone Integration', 'softone-woocommerce-integration'),
        __('Softone', 'softone-woocommerce-integration'),
        'manage_options',
        'softone-settings',
        'softone_settings_page'
    );
    softone_debug_log('admin_menu', 'Main menu hook: ' . $main_hook);

    $customer_hook = add_submenu_page(
        'softone-settings',
        __('Customer Sync', 'softone-woocommerce-integration'),
        __('Customers', 'softone-woocommerce-integration'),
        'manage_options',
        'softone-customers',
        'softone_customers_page'
    );
    softone_debug_log('admin_menu', 'Customer submenu hook: ' . $customer_hook);

    $product_hook = add_submenu_page(
        'softone-settings',
        __('Product Sync', 'softone-woocommerce-integration'),
        __('Products', 'softone-woocommerce-integration'),
        'manage_options',
        'softone-products',
        'softone_products_page'
    );
    softone_debug_log('admin_menu', 'Product submenu hook: ' . $product_hook);

    $order_hook = add_submenu_page(
        'softone-settings',
        __('Order Sync', 'softone-woocommerce-integration'),
        __('Orders', 'softone-woocommerce-integration'),
        'manage_options',
        'softone-orders',
        'softone_orders_page'
    );
    softone_debug_log('admin_menu', 'Order submenu hook: ' . $order_hook);

    $log_hook = add_submenu_page(
        'softone-settings',
        __('Live Logging', 'softone-woocommerce-integration'),
        __('Logs', 'softone-woocommerce-integration'),
        'manage_options',
        'softone-logs',
        'softone_logs_page'
    );
    softone_debug_log('admin_menu', 'Logs submenu hook: ' . $log_hook);

    $customer_logs_hook = add_submenu_page(
        'softone-settings',
        __('Customer Logs', 'softone-woocommerce-integration'),
        __('Customer Logs', 'softone-woocommerce-integration'),
        'manage_options',
        'softone-customer-logs',
        'softone_customer_logs_page'
    );
    softone_debug_log('admin_menu', 'Customer logs submenu hook: ' . $customer_logs_hook);

    $order_logs_hook = add_submenu_page(
        'softone-settings',
        __('Order Logs', 'softone-woocommerce-integration'),
        __('Order Logs', 'softone-woocommerce-integration'),
        'manage_options',
        'softone-order-logs',
        'softone_order_logs_page'
    );
    softone_debug_log('admin_menu', 'Order logs submenu hook: ' . $order_logs_hook);

    $tester_hook = add_submenu_page(
        'softone-settings',
        __('API Request Tester', 'softone-woocommerce-integration'),
        __('Request Tester', 'softone-woocommerce-integration'),
        'manage_options',
        'softone-request-tester',
        'softone_request_tester_page'
    );
    softone_debug_log('admin_menu', 'Request tester submenu hook: ' . $tester_hook);

    $fields_hook = add_submenu_page(
        'softone-settings',
        __('API Fields', 'softone-woocommerce-integration'),
        __('API Fields', 'softone-woocommerce-integration'),
        'manage_options',
        'softone-api-fields',
        'softone_api_fields_page'
    );
    softone_debug_log('admin_menu', 'API fields submenu hook: ' . $fields_hook);

    $menu_hook = add_submenu_page(
        'softone-settings',
        __('Menu Sync', 'softone-woocommerce-integration'),
        __('Menu Sync', 'softone-woocommerce-integration'),
        'manage_options',
        'softone-sync-product-menu',
        'softone_render_sync_product_menu_page'
    );
    softone_debug_log('admin_menu', 'Menu sync submenu hook: ' . $menu_hook);
}

// Register settings
function softone_register_settings() {
    register_setting('softone_settings_group', 'softone_api_username', 'sanitize_text_field');
    register_setting('softone_settings_group', 'softone_api_password', 'softone_sanitize_api_password');
}

// Activation hook to set default options
function softone_activate() {
    // Set default values for the options if they don't exist
    if (get_option('softone_synced_customers') === false) {
        update_option('softone_synced_customers', []);
    }
    if (get_option('softone_synced_products') === false) {
        update_option('softone_synced_products', 0);
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
    $schedules['two_minutes'] = [
        'interval' => 120,
        'display' => __('Every Two Minutes', 'softone-woocommerce-integration')
    ];
    $schedules['two_hours'] = [
        'interval' => 7200,
        'display' => __('Every Two Hours', 'softone-woocommerce-integration')
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

// Sync functions
function softone_sync_customers() {
    if (class_exists('WooCommerce')) {
        $api = new Softone_API();
        $customers = $api->get_customers();
        if ($customers && isset($customers['rows'])) {
            foreach ($customers['rows'] as $customer) {
                // Check if customer exists by email
                $existing_customer_id = email_exists($customer['EMAIL']);
                if ($existing_customer_id) {
                    // Update existing customer
                    wp_update_user([
                        'ID' => $existing_customer_id,
                        'first_name' => sanitize_text_field($customer['NAME']),
                        'billing_address_1' => sanitize_text_field($customer['ADDRESS']),
                        'billing_city' => sanitize_text_field($customer['CITY']),
                        'billing_postcode' => sanitize_text_field($customer['ZIP']),
                        'billing_country' => sanitize_text_field($customer['COUNTRY']),
                        'billing_phone' => sanitize_text_field($customer['PHONE1']),
                    ]);
                } else {
                    // Create new customer
                    wp_insert_user([
                        'user_login' => sanitize_user($customer['CODE']),
                        'user_pass' => wp_generate_password(),
                        'user_email' => sanitize_email($customer['EMAIL']),
                        'first_name' => sanitize_text_field($customer['NAME']),
                        'billing_address_1' => sanitize_text_field($customer['ADDRESS']),
                        'billing_city' => sanitize_text_field($customer['CITY']),
                        'billing_postcode' => sanitize_text_field($customer['ZIP']),
                        'billing_country' => sanitize_text_field($customer['COUNTRY']),
                        'billing_phone' => sanitize_text_field($customer['PHONE1']),
                        'role' => 'customer',
                    ]);
                }
            }
            update_option('softone_synced_customers', array_map('sanitize_text_field', $customers['rows']));
            softone_log('sync_customers', __('Customers synchronized successfully.', 'softone-woocommerce-integration'));
            return ['success' => true, 'message' => __('Customers synchronized successfully.', 'softone-woocommerce-integration'), 'customers' => $customers['rows']];
        } else {
            softone_log('sync_customers', __('Failed to synchronize customers.', 'softone-woocommerce-integration'));
            return ['success' => false, 'message' => __('Failed to synchronize customers.', 'softone-woocommerce-integration')];
        }
    }
}

function softone_sync_products() {
    if (class_exists('WooCommerce')) {
        $api = new Softone_API();
        $last_sync = get_option('softone_last_product_sync');
        $minutes = $last_sync ? max(1, ceil((time() - strtotime($last_sync)) / 60)) : 99999;
        $products = $api->get_products($minutes);
        if ($products) {
            // Avoid object cache growth during large sync operations
            wp_suspend_cache_addition(true);
            $count = 0;
            $softone_mtrls = [];
            foreach ($products as $index => $product) {
                if (!empty($product['MTRL'])) {
                    $softone_mtrls[] = sanitize_text_field(mb_convert_encoding(trim($product['MTRL']), 'UTF-8', 'UTF-8'));
                }
                $api->sync_product_to_woocommerce($product);
                // Free memory consumed by each product iteration
                unset($products[$index], $product);
                gc_collect_cycles();
                $count++;
            }
            wp_suspend_cache_addition(false);
            wp_cache_flush();
            update_option('softone_synced_products', $count);
            update_option('softone_last_product_sync', current_time('mysql'));
            unset($products);

            // Find WooCommerce products not returned from Softone
            $woo_ids = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            $woo_mtrls = [];
            foreach ($woo_ids as $pid) {
                $mtrl = get_post_meta($pid, 'attribute_mtrl', true);
                if ($mtrl) {
                    $woo_mtrls[$mtrl] = $pid;
                }
            }
            if (!empty($softone_mtrls)) {
                $missing_mtrls = array_diff(array_keys($woo_mtrls), $softone_mtrls);
                softone_log('cleanup_products', 'Softone MTRLs: ' . implode(', ', $softone_mtrls));
                softone_log('cleanup_products', 'WooCommerce MTRLs: ' . implode(', ', array_keys($woo_mtrls)));
                softone_log('cleanup_products', 'Missing MTRLs: ' . implode(', ', $missing_mtrls));
                $cleanup_action = get_option('softone_missing_product_action', 'draft');
                foreach ($missing_mtrls as $mtrl) {
                    $pid = $woo_mtrls[$mtrl];
                    if ('delete' === $cleanup_action) {
                        wp_delete_post($pid, true);
                        softone_log('cleanup_products', sprintf('Deleted product with MTRL %s', $mtrl));
                    } else {
                        wp_update_post(['ID' => $pid, 'post_status' => 'draft']);
                        wc_update_product_stock_status($pid, 'outofstock');
                        softone_log('cleanup_products', sprintf('Marked product %s as draft/out-of-stock', $mtrl));
                    }
                }
            }

            softone_log('sync_products', __('Products synchronized successfully.', 'softone-woocommerce-integration'));
            if (function_exists('softone_sync_woocommerce_product_categories_menu')) {
                softone_sync_woocommerce_product_categories_menu('Main Menu', 'Products');
            }
            return ['success' => true, 'message' => __('Products synchronized successfully.', 'softone-woocommerce-integration'), 'count' => $count];
        } else {
            softone_log('sync_products', __('Failed to synchronize products.', 'softone-woocommerce-integration'));
            return ['success' => false, 'message' => __('Failed to synchronize products.', 'softone-woocommerce-integration')];
        }
    }
}

function softone_sync_orders() {
    if (class_exists('WooCommerce')) {
        $api = new Softone_API();
        $page = 1;
        $per_page = 20; // Process orders in small batches to reduce memory usage
        wp_suspend_cache_addition(true);
        do {
            $order_ids = wc_get_orders([
                'limit'  => $per_page,
                'paged'  => $page,
                'return' => 'ids',
            ]);
            if (empty($order_ids)) {
                break;
            }
            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);
                $api->create_order($order);
                unset($order);
                gc_collect_cycles();
            }
            // Clear object cache to free memory between batches
            wp_cache_flush();
            $page++;
            unset($order_ids);
        } while (true);
        wp_suspend_cache_addition(false);
        softone_log('sync_orders', __('Orders synchronized successfully.', 'softone-woocommerce-integration'));
        return __('Orders synchronized successfully.', 'softone-woocommerce-integration');
    }
}

add_action('wp_ajax_softone_get_logs', 'softone_get_logs');
function softone_get_logs() {
    check_ajax_referer('softone_get_logs_nonce');
    if (!current_user_can('manage_options')) {
        return wp_send_json_error('Unauthorized');
    }
    $logs = get_option('softone_api_logs', []);
    if (!is_array($logs)) {
        $logs = [];
    }
    // Return only the most recent 100 log entries to prevent memory issues
    $logs = array_slice($logs, -100);
    wp_send_json($logs);
}

add_action('admin_enqueue_scripts', function () {
    wp_localize_script('jquery', 'softone_sync_products', [
        'nonce' => wp_create_nonce('softone_sync_products_nonce')
    ]);
    wp_localize_script('jquery', 'softone_logs', [
        'nonce' => wp_create_nonce('softone_get_logs_nonce')
    ]);
});

add_action('init', function () {
    add_rewrite_rule('^softone-sync-products-cron/?$', 'index.php?softone_batch_sync=1', 'top');
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'softone_batch_sync';
    return $vars;
});

add_action('template_redirect', function () {
    if (get_query_var('softone_batch_sync') == 1) {
        // SECURITY
        $expected_key = 'r8Kx12A9ZtX';
        if (!isset($_GET['key']) || $_GET['key'] !== $expected_key) {
            wp_die(__('Invalid access key', 'softone-woocommerce-integration'));
        }

        $offset = intval(get_option('softone_cron_offset', 0));
        $limit = 20;

        $api = new Softone_API();
        $last_sync = get_option('softone_last_product_sync');
        $minutes = $last_sync ? max(1, ceil((time() - strtotime($last_sync)) / 60)) : 99999;
        $products = $api->get_products($minutes);
        $total = count($products);

        if ($offset >= $total) {
            // Reset
            update_option('softone_cron_offset', 0);
            echo "✅ All products synced. Resetting offset.\n";
            exit;
        }

        $batch = array_slice($products, $offset, $limit);
        $count = 0;

        foreach ($batch as $item) {
            $api->sync_product_to_woocommerce($item);
            $count++;
        }

        $next_offset = $offset + $limit;
        update_option('softone_cron_offset', $next_offset);
        update_option('softone_last_product_sync', current_time('mysql'));

        echo "✅ Synced $count products (offset $offset → $next_offset of $total)\n";
        exit;
    }
});

