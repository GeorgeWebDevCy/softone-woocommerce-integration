<?php
/**
 * Displays the order synchronization page for the Softone WooCommerce Integration.
 */
function softone_orders_page() {
    if (isset($_POST['sync_orders']) && check_admin_referer('softone_sync_orders_action', 'softone_sync_orders_nonce')) {
        $result = softone_sync_orders();
        echo '<div class="notice notice-success"><p>' . esc_html($result) . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Sync WooCommerce Orders to Softone', 'softone-woocommerce-integration'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('softone_sync_orders_action', 'softone_sync_orders_nonce'); ?>
            <input type="hidden" name="sync_orders" value="1">
            <?php submit_button(__('Sync Orders', 'softone-woocommerce-integration')); ?>
        </form>
        <?php
        // Assuming softone_get_orders() function retrieves orders from Softone
        $orders = softone_get_orders();
        if ($orders) {
            ?>
            <h2><?php esc_html_e('Synchronized Orders', 'softone-woocommerce-integration'); ?></h2>
            <table class="widefat fixed" cellspacing="0">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Order ID', 'softone-woocommerce-integration'); ?></th>
                        <th><?php esc_html_e('Customer', 'softone-woocommerce-integration'); ?></th>
                        <th><?php esc_html_e('Date', 'softone-woocommerce-integration'); ?></th>
                        <th><?php esc_html_e('Status', 'softone-woocommerce-integration'); ?></th>
                        <th><?php esc_html_e('Total', 'softone-woocommerce-integration'); ?></th>
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