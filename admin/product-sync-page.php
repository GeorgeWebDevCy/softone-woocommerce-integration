<?php
/**
 * Displays the product sync page for the Softone WooCommerce Integration.
 */
function softone_products_page() {
    if (isset($_POST['sync_products'])) {
        $result = softone_sync_products();
        if (is_array($result) && isset($result['success']) && $result['success']) {
            echo '<div class="notice notice-success"><p>' . esc_html($result['message']) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to synchronize products.</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Product Sync</h1>
        <form method="post">
            <input type="hidden" name="sync_products" value="1" />
            <?php submit_button('Sync Products'); ?>
        </form>
        <?php if (isset($result) && is_array($result) && isset($result['products'])): ?>
        <h2>Synchronized Products</h2>
        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Price</th>
                    <th>Category</th>
                    <th>SubCategory</th>
                    <th>Barcode</th>
                    <th>Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['products'] as $product): ?>
                <tr>
                    <td><?php echo esc_html($product['MTRL']); ?></td>
                    <td><?php echo esc_html($product['CODE']); ?></td>
                    <td><?php echo esc_html($product['DESC']); ?></td>
                    <td><?php echo esc_html($product['RETAILPRICE']); ?></td>
                    <td><?php echo esc_html($product['COMMECATEGORY_NAME']); ?></td>
                    <td><?php echo esc_html($product['SUBMECATEGORY_NAME']); ?></td>
                    <td><?php echo esc_html($product['BARCODE']); ?></td>
                    <td><?php echo esc_html($product['Stock QTY']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
?>