<?php
/**
 * Displays the customer synchronization page for the Softone WooCommerce Integration.
 */
function softone_customers_page() {
    if (isset($_POST['sync_customers']) && check_admin_referer('softone_sync_customers_action', 'softone_sync_customers_nonce')) {
        $result = softone_sync_customers();
        echo '<div class="notice notice-success"><p>' . $result . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Sync Softone Customers</h1>
        <form method="post">
            <?php wp_nonce_field('softone_sync_customers_action', 'softone_sync_customers_nonce'); ?>
            <input type="hidden" name="sync_customers" value="1">
            <?php submit_button('Sync Customers'); ?>
        </form>
        <h2>Synced Customers</h2>
        <table class="widefat">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $customers = get_option('softone_synced_customers', []);
                foreach ($customers as $customer) {
                    echo '<tr><td>' . esc_html($customer['id']) . '</td><td>' . esc_html($customer['name']) . '</td><td>' . esc_html($customer['email']) . '</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}