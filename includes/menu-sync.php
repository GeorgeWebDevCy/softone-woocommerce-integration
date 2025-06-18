<?php
/**
 * WooCommerce Product Categories Menu Sync
 * Syncs WooCommerce product categories under "Products" in "Main Menu"
 */

// Main sync function
function softone_sync_woocommerce_product_categories_menu($menu_name = 'Main Menu', $parent_title = 'Products') {
    static $running = false;
    if ($running) return false;
    $running = true;

    try {
        $menu = wp_get_nav_menu_object($menu_name);
        if (!$menu) throw new Exception("Menu '{$menu_name}' not found");

        $menu_id = $menu->term_id;
        $menu_items = wp_get_nav_menu_items($menu_id, ['orderby' => 'menu_order']);
        $product_root_id = null;
        $existing_menu_items = [];

        foreach ($menu_items as $item) {
            if ($item->title === $parent_title && ($item->url === '#' || $item->type === 'custom')) {
                $product_root_id = $item->ID;
            }
            if ($item->type === 'taxonomy' && $item->object === 'product_cat') {
                $existing_menu_items[$item->menu_item_parent][$item->object_id] = $item->ID;
            }
        }

        if (!$product_root_id) throw new Exception("Parent menu item '{$parent_title}' not found in menu");

        $product_cats = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'update_term_meta_cache' => false,
        ]);

        if (is_wp_error($product_cats)) {
            throw new Exception('Failed to get product categories: ' . $product_cats->get_error_message());
        }

        $term_map = [];
        $term_children = [];
        $all_term_ids = [];

        foreach ($product_cats as $term) {
            // Skip default "Uncategorized" category
            if ($term->slug === 'uncategorized') {
                continue;
            }

            $term_map[$term->term_id] = $term;
            $term_children[$term->parent][] = $term->term_id;
            $all_term_ids[] = $term->term_id;
        }

        $new_menu_item_ids = [];

        $add_recursive = function ($parent_term_id, $parent_menu_id) use (&$add_recursive, $term_children, $term_map, &$existing_menu_items, $menu_id, &$new_menu_item_ids, $product_root_id) {
            if (!isset($term_children[$parent_term_id])) return;

            foreach ($term_children[$parent_term_id] as $term_id) {
                if (isset($existing_menu_items[$parent_menu_id][$term_id])) {
                    $new_menu_item_ids[] = $existing_menu_items[$parent_menu_id][$term_id];
                    $add_recursive($term_id, $existing_menu_items[$parent_menu_id][$term_id]);
                    continue;
                }

                $term = $term_map[$term_id];

                $args = [
                    'menu-item-title'      => $term->name,
                    'menu-item-object'     => 'product_cat',
                    'menu-item-object-id'  => $term->term_id,
                    'menu-item-type'       => 'taxonomy',
                    'menu-item-status'     => 'publish',
                    'menu-item-parent-id'  => $parent_menu_id,
                ];

                // Add mega menu class for top level categories when using Divi
                if ($parent_menu_id == $product_root_id) {
                    $args['menu-item-classes'] = 'mega-menu';
                }

                $menu_item_id = wp_update_nav_menu_item($menu_id, 0, $args);

                if (is_wp_error($menu_item_id)) continue;

                $existing_menu_items[$parent_menu_id][$term_id] = $menu_item_id;
                $new_menu_item_ids[] = $menu_item_id;

                $add_recursive($term_id, $menu_item_id);
            }
        };

        $add_recursive(0, $product_root_id);

        foreach ($existing_menu_items as $parent_id => $items) {
            foreach ($items as $term_id => $item_id) {
                if (!in_array($term_id, $all_term_ids) && !in_array($item_id, $new_menu_item_ids)) {
                    wp_delete_post($item_id, true);
                }
            }
        }

        update_option('softone_last_product_menu_sync', current_time('mysql'));
        return true;

    } catch (Exception $e) {
        error_log('Product menu sync error: ' . $e->getMessage());
        return false;
    } finally {
        $running = false;
    }
}

// Schedule auto sync on category changes (debounced 30s)
function softone_schedule_auto_sync_product_menu() {
    if (!wp_next_scheduled('softone_debounced_auto_sync_product_menu')) {
        wp_schedule_single_event(time() + 30, 'softone_debounced_auto_sync_product_menu');
    }
}
add_action('created_product_cat', 'softone_schedule_auto_sync_product_menu');
add_action('edited_product_cat', 'softone_schedule_auto_sync_product_menu');
add_action('delete_product_cat', 'softone_schedule_auto_sync_product_menu');

function softone_run_auto_sync_product_menu() {
    if (function_exists('softone_sync_woocommerce_product_categories_menu')) {
        softone_sync_woocommerce_product_categories_menu('Main Menu', 'Products');
    }
}
add_action('softone_debounced_auto_sync_product_menu', 'softone_run_auto_sync_product_menu');

// Admin page to manually trigger sync under the Softone menu
function softone_register_product_menu_sync_page() {
    add_submenu_page(
        'softone-settings',
        'Sync Product Categories',
        'Menu Sync',
        'manage_options',
        'softone-sync-product-menu',
        'softone_render_sync_product_menu_page'
    );
}
add_action('admin_menu', 'softone_register_product_menu_sync_page');

function softone_render_sync_product_menu_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $message = '';
    $message_type = '';

    if (isset($_POST['softone_run_sync'])) {
        check_admin_referer('softone_sync_product_menu_action');

        if (function_exists('softone_sync_woocommerce_product_categories_menu')) {
            $result = softone_sync_woocommerce_product_categories_menu('Main Menu', 'Products');
            if ($result) {
                update_option('softone_last_product_menu_sync', current_time('mysql'));
                $message = '✅ Product categories synced successfully.';
                $message_type = 'success';
            } else {
                $message = '❌ There was an error syncing product categories. Check logs.';
                $message_type = 'error';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>Sync WooCommerce Product Categories</h1>

        <?php if ($message) : ?>
            <div class="notice notice-<?php echo esc_attr($message_type); ?>">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?php wp_nonce_field('softone_sync_product_menu_action'); ?>
            <input type="hidden" name="softone_run_sync" value="1">
            <?php submit_button('Sync Now'); ?>
        </form>

        <hr>

        <p><strong>Last sync:</strong> <?php echo esc_html(get_option('softone_last_product_menu_sync', 'Never')); ?></p>
        <p><strong>Note:</strong> The sync runs automatically when product categories are added, edited, or deleted.</p>
    </div>
    <?php
}
