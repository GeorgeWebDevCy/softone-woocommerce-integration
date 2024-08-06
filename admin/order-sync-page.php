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
    </div>
    <?php
}