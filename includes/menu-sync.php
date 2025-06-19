<?php
/**
 * WooCommerce Product Categories Menu Sync
 * Syncs WooCommerce product categories under "Products" in "Main Menu"
 */

// Ensure menu and root item exist
function softone_ensure_menu_structure($menu_name = 'Main Menu', $parent_title = 'Products') {
    $menu = wp_get_nav_menu_object($menu_name);
    if (!$menu) {
        $menu_id = wp_create_nav_menu($menu_name);
    } else {
        $menu_id = $menu->term_id;
    }

    $items = wp_get_nav_menu_items($menu_id);
    $product_root_id = null;
    if ($items) {
        foreach ($items as $item) {
            if ($item->title === $parent_title && ($item->url === '#' || $item->type === 'custom')) {
                $product_root_id = $item->ID;
                break;
            }
        }
    }

    if (!$product_root_id) {
        $product_root_id = wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'  => $parent_title,
            'menu-item-url'    => '#',
            'menu-item-type'   => 'custom',
            'menu-item-status' => 'publish',
        ]);
    }

    $classes = get_post_meta($product_root_id, '_menu_item_classes', true);
    if (!is_array($classes)) {
        $classes = is_string($classes) ? explode(' ', $classes) : [];
    }
    $classes = array_unique(array_filter(array_map('trim', $classes)));
    if (!in_array('mega-menu', $classes, true)) {
        $classes[] = 'mega-menu';
    }
    update_post_meta($product_root_id, '_menu_item_classes', $classes);

    return [$menu_id, $product_root_id];
}

// Main sync function
function softone_sync_woocommerce_product_categories_menu($menu_name = 'Main Menu', $parent_title = 'Products') {
    static $running = false;
    if ($running) return false;
    $running = true;

    try {
        list($menu_id, $product_root_id) = softone_ensure_menu_structure($menu_name, $parent_title);

        $menu_items = wp_get_nav_menu_items($menu_id, ['orderby' => 'menu_order']);
        $existing_menu_items = [];

        foreach ($menu_items as $item) {
            if ($item->type === 'taxonomy' && $item->object === 'product_cat') {
                $existing_menu_items[$item->menu_item_parent][$item->object_id] = $item->ID;
            }
        }

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

        $top_level_index = 1;

        $add_recursive = function ($parent_term_id, $parent_menu_id) use (&$add_recursive, $term_children, $term_map, &$existing_menu_items, $menu_id, &$new_menu_item_ids, $product_root_id, &$top_level_index) {
            if (!isset($term_children[$parent_term_id])) return;

            foreach ($term_children[$parent_term_id] as $term_id) {
                $term = $term_map[$term_id];

                $args = [
                    'menu-item-title'      => $term->name,
                    'menu-item-object'     => 'product_cat',
                    'menu-item-object-id'  => $term->term_id,
                    'menu-item-type'       => 'taxonomy',
                    'menu-item-status'     => 'publish',
                    'menu-item-parent-id'  => $parent_menu_id,
                ];

                $existing_id = $existing_menu_items[$parent_menu_id][$term_id] ?? 0;

                if ($parent_menu_id == $product_root_id) {
                    $args['menu-item-classes'] = 'mega-menu mega-menu-parent mega-menu-parent-' . $top_level_index;
                }

                $menu_item_id = wp_update_nav_menu_item($menu_id, $existing_id, $args);

                if (is_wp_error($menu_item_id)) continue;

                $existing_menu_items[$parent_menu_id][$term_id] = $menu_item_id;
                $new_menu_item_ids[] = $menu_item_id;

                $add_recursive($term_id, $menu_item_id);
                if ($parent_menu_id == $product_root_id) {
                    $top_level_index++;
                }
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

function softone_render_sync_product_menu_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'softone-woocommerce-integration'));
    }

    $message = '';
    $message_type = '';

    if (isset($_POST['softone_run_sync'])) {
        check_admin_referer('softone_sync_product_menu_action');

        if (function_exists('softone_sync_woocommerce_product_categories_menu')) {
            $result = softone_sync_woocommerce_product_categories_menu('Main Menu', 'Products');
            if ($result) {
                update_option('softone_last_product_menu_sync', current_time('mysql'));
                $message = __('✅ Product categories synced successfully.', 'softone-woocommerce-integration');
                $message_type = 'success';
            } else {
                $message = __('❌ There was an error syncing product categories. Check logs.', 'softone-woocommerce-integration');
                $message_type = 'error';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Sync WooCommerce Product Categories', 'softone-woocommerce-integration'); ?></h1>

        <?php if ($message) : ?>
            <div class="notice notice-<?php echo esc_attr($message_type); ?>">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?php wp_nonce_field('softone_sync_product_menu_action'); ?>
            <input type="hidden" name="softone_run_sync" value="1">
            <?php submit_button(__('Sync Now', 'softone-woocommerce-integration')); ?>
        </form>

        <hr>

        <p><strong><?php esc_html_e('Last sync:', 'softone-woocommerce-integration'); ?></strong> <?php echo esc_html(get_option('softone_last_product_menu_sync', 'Never')); ?></p>
        <p><strong><?php esc_html_e('Note:', 'softone-woocommerce-integration'); ?></strong> <?php esc_html_e('The sync runs automatically when product categories are added, edited, or deleted.', 'softone-woocommerce-integration'); ?></p>
    </div>
    <?php
}
