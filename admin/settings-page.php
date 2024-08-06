<?php
/**
 * Displays the settings page for the Softone WooCommerce Integration.
 */
function softone_settings_page() {
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
    add_settings_field('softone_api_username', 'API Username', 'softone_api_username_field', 'softone-settings', 'softone_settings_section');
    add_settings_field('softone_api_password', 'API Password', 'softone_api_password_field', 'softone-settings', 'softone_settings_section');
}

function softone_api_username_field() {
    $username = esc_attr(get_option('softone_api_username'));
    echo '<input type="text" name="softone_api_username" value="' . $username . '" />';
}

function softone_api_password_field() {
    $password = esc_attr(get_option('softone_api_password'));
    echo '<input type="password" name="softone_api_password" value="' . $password . '" />';
}
