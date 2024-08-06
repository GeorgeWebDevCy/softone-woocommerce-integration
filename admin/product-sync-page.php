<?php
/**
 * Displays the product synchronization page for the Softone WooCommerce Integration.
 */
function softone_products_page() {
    if (isset($_POST['sync_products']) && check_admin_referer('softone_sync_products_action', 'softone_sync_products_nonce')) {
        $result = softone_sync_products();
        echo '<div class="notice notice-success"><p>' . $result . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Sync Softone Products</h1>
        <form method="post">
            <?php wp_nonce_field('softone_sync_products_action', 'softone_sync_products_nonce'); ?>
            <input type="hidden" name="sync_products" value="1">
            <?php submit_button('Sync Products'); ?>
        </form>
        <h2>Synced Products</h2>
        <table class="widefat">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Price</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $products = get_option('softone_synced_products', []);
                foreach ($products as $product) {
                    echo '<tr><td>' . esc_html($product['id']) . '</td><td>' . esc_html($product['name']) . '</td><td>' . esc_html($product['price']) . '</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}