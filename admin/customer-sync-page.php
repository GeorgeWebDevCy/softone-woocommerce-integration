<?php
/**
 * Displays the customer sync page for the Softone WooCommerce Integration.
 */
function softone_customers_page() {
    if (isset($_POST['sync_customers'])) {
        $result = softone_sync_customers();
        if (is_array($result) && isset($result['success']) && $result['success']) {
            echo '<div class="notice notice-success"><p>' . esc_html($result['message']) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to synchronize customers.</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Customer Sync</h1>
        <form method="post">
            <input type="hidden" name="sync_customers" value="1" />
            <?php submit_button('Sync Customers'); ?>
        </form>
        <?php if (isset($result) && is_array($result) && isset($result['customers'])): ?>
        <h2>Synchronized Customers</h2>
        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Address</th>
                    <th>City</th>
                    <th>Zip</th>
                    <th>Country</th>
                    <th>Phone</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['customers'] as $customer): ?>
                <tr>
                    <td><?php echo esc_html($customer['TRDR']); ?></td>
                    <td><?php echo esc_html($customer['CODE']); ?></td>
                    <td><?php echo esc_html($customer['NAME']); ?></td>
                    <td><?php echo esc_html($customer['EMAIL']); ?></td>
                    <td><?php echo esc_html($customer['ADDRESS']); ?></td>
                    <td><?php echo esc_html($customer['CITY']); ?></td>
                    <td><?php echo esc_html($customer['ZIP']); ?></td>
                    <td><?php echo esc_html($customer['COUNTRY']); ?></td>
                    <td><?php echo esc_html($customer['PHONE1']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
?>
