<?php
/**
 * Displays the settings page for the Softone WooCommerce Integration.
 */
function softone_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Softone API Settings', 'softone-woocommerce-integration'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('softone_settings_group');
            do_settings_sections('softone-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'softone_admin_settings');
function softone_admin_settings() {
    // Add settings section
    add_settings_section('softone_settings_section', __('API Credentials', 'softone-woocommerce-integration'), null, 'softone-settings');

    // Product cleanup section
    add_settings_section('softone_product_settings_section', __('Product Cleanup', 'softone-woocommerce-integration'), null, 'softone-settings');

    // Add settings fields
    add_settings_field('softone_api_username', __('API Username', 'softone-woocommerce-integration'), 'softone_api_username_callback', 'softone-settings', 'softone_settings_section');
    add_settings_field('softone_api_password', __('API Password', 'softone-woocommerce-integration'), 'softone_api_password_callback', 'softone-settings', 'softone_settings_section');
    add_settings_field('softone_missing_product_action', __('Missing Products Action', 'softone-woocommerce-integration'), 'softone_missing_product_action_callback', 'softone-settings', 'softone_product_settings_section');

    register_setting('softone_settings_group', 'softone_api_username');
    register_setting('softone_settings_group', 'softone_api_password', 'softone_sanitize_api_password');
    register_setting('softone_settings_group', 'softone_missing_product_action');
}

// Callbacks for settings fields
function softone_api_username_callback() {
    $username = get_option('softone_api_username');
    echo '<input type="text" id="softone_api_username" name="softone_api_username" value="' . esc_attr($username) . '" />';
}

function softone_api_password_callback() {
    $password = softone_decrypt(get_option('softone_api_password'));
    echo '<input type="password" id="softone_api_password" name="softone_api_password" value="' . esc_attr($password) . '" />';
}

function softone_missing_product_action_callback() {
    $value = get_option('softone_missing_product_action', 'draft');
    ?>
    <select id="softone_missing_product_action" name="softone_missing_product_action">
        <option value="draft" <?php selected($value, 'draft'); ?>><?php esc_html_e('Mark as Draft/Out of Stock', 'softone-woocommerce-integration'); ?></option>
        <option value="delete" <?php selected($value, 'delete'); ?>><?php esc_html_e('Delete', 'softone-woocommerce-integration'); ?></option>
    </select>
    <?php
}
?>