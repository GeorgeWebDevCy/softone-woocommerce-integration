<?php
/**
 * Displays the order synchronization page for the Softone WooCommerce Integration.
 */
function softone_orders_page() {
    if (isset($_POST['sync_orders']) && check_admin_referer('softone_sync_orders_action', 'softone_sync_orders_nonce')) {
        $result = softone_sync_orders();
        echo '<div class="notice notice-success"><p>' . $result . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Sync WooCommerce Orders to Softone</h1>
        <form method="post">
            <?php wp_nonce_field('softone_sync_orders_action', 'softone_sync_orders_nonce'); ?>
            <input type="hidden" name="sync_orders" value="1">
            <?php submit_button('Sync Orders'); ?>
        </form>
        <?php
        // Assuming softone_get_orders() function retrieves orders from Softone
        $orders = softone_get_orders();
        if ($orders) {
            ?>
            <h2>Synchronized Orders</h2>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?php echo esc_html($order['id']); ?></td>
                        <td><?php echo esc_html($order['customer']); ?></td>
                        <td><?php echo esc_html($order['date']); ?></td>
                        <td><?php echo esc_html($order['status']); ?></td>
                        <td><?php echo esc_html($order['total']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
        ?>
    </div>
    <?php
}
?>