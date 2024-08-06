<?php
/**
 * Displays the settings page for the Softone WooCommerce Integration.
 */
function softone_settings_page() {
    $username = get_option('softone_api_username');
    $password = get_option('softone_api_password');
    $client_id = get_option('softone_client_id');
    ?>
    <div class="wrap">
        <h1>Softone API Settings</h1>
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
    add_settings_section('softone_settings_section', 'API Credentials', null, 'softone-settings');

    // Add settings fields
    add_settings_field('softone_api_username', 'API Username', 'softone_api_username_callback', 'softone-settings', 'softone_settings_section');
    add_settings_field('softone_api_password', 'API Password', 'softone_api_password_callback', 'softone-settings', 'softone_settings_section');
}

// Callbacks for settings fields
function softone_api_username_callback() {
    $username = get_option('softone_api_username');
    echo '<input type="text" id="softone_api_username" name="softone_api_username" value="' . esc_attr($username) . '" />';
}

function softone_api_password_callback() {
    $password = get_option('softone_api_password');
    echo '<input type="password" id="softone_api_password" name="softone_api_password" value="' . esc_attr($password) . '" />';
}
?>