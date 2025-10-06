<?php
/**
 * WooCommerce Product Categories Menu Sync
 * Syncs WooCommerce product categories under "Products" in "Main Menu"
 */

// Ensure menu and root item exist
function softone_ensure_menu_structure($menu_name = 'Main Menu', $parent_title = 'Products', $add_mega_menu_class = true) {
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

    if ($add_mega_menu_class) {
        $classes = get_post_meta($product_root_id, '_menu_item_classes', true);
        if (!is_array($classes)) {
            $classes = is_string($classes) ? explode(' ', $classes) : [];
        }
        $classes = array_unique(array_filter(array_map('trim', $classes)));
        if (!in_array('mega-menu', $classes, true)) {
            $classes[] = 'mega-menu';
        }
        update_post_meta($product_root_id, '_menu_item_classes', $classes);
    }

    return [$menu_id, $product_root_id];
}

function softone_sync_taxonomy_menu($taxonomy, $menu_name, $parent_title, $args = []) {
    static $running = [];
    if (!empty($running[$taxonomy])) return false;
    $running[$taxonomy] = true;

    $args = wp_parse_args($args, [
        'exclude_slugs'     => [],
        'move_to_end_slugs' => [],
        'add_mega_menu'     => true,
    ]);

    try {
        list($menu_id, $root_menu_id) = softone_ensure_menu_structure($menu_name, $parent_title, $args['add_mega_menu']);

        $menu_items = wp_get_nav_menu_items($menu_id, ['orderby' => 'menu_order']);
        $existing_menu_items = [];

        foreach ($menu_items as $item) {
            if ($item->type === 'taxonomy' && $item->object === $taxonomy) {
                $existing_menu_items[$item->menu_item_parent][$item->object_id] = $item->ID;
            }
        }

        $product_cats = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'update_term_meta_cache' => false,
        ]);

        if (is_wp_error($product_cats)) {
            throw new Exception('Failed to get terms for ' . $taxonomy . ': ' . $product_cats->get_error_message());
        }

        $term_map = [];
        $term_children = [];
        $all_term_ids = [];
        $move_to_end_ids = [];

        foreach ($product_cats as $term) {
            if (in_array($term->slug, $args['exclude_slugs'], true)) {
                if (!empty($existing_menu_items[$term->parent][$term->term_id])) {
                    wp_delete_post($existing_menu_items[$term->parent][$term->term_id], true);
                    unset($existing_menu_items[$term->parent][$term->term_id]);
                }
                continue;
            }

            $term_map[$term->term_id] = $term;
            $term_children[$term->parent][] = $term->term_id;
            $all_term_ids[] = $term->term_id;

            if (in_array($term->slug, $args['move_to_end_slugs'], true)) {
                $move_to_end_ids[] = $term->term_id;
            }
        }

        if (!empty($move_to_end_ids) && isset($term_children[0])) {
            $term_children[0] = array_values(array_diff($term_children[0], $move_to_end_ids));
            foreach ($move_to_end_ids as $term_id) {
                $term_children[0][] = $term_id;
            }
        }

        $new_menu_item_ids = [];

        $add_recursive = function ($parent_term_id, $parent_menu_id) use (&$add_recursive, $term_children, $term_map, &$existing_menu_items, $menu_id, &$new_menu_item_ids) {
            if (!isset($term_children[$parent_term_id])) return;

            foreach ($term_children[$parent_term_id] as $term_id) {
                $term = $term_map[$term_id];

                $args = [
                    'menu-item-title'      => $term->name,
                    'menu-item-object'     => $taxonomy,
                    'menu-item-object-id'  => $term->term_id,
                    'menu-item-type'       => 'taxonomy',
                    'menu-item-status'     => 'publish',
                    'menu-item-parent-id'  => $parent_menu_id,
                ];

                $existing_id = $existing_menu_items[$parent_menu_id][$term_id] ?? 0;

                $menu_item_id = wp_update_nav_menu_item($menu_id, $existing_id, $args);

                if (is_wp_error($menu_item_id)) continue;

                $existing_menu_items[$parent_menu_id][$term_id] = $menu_item_id;
                $new_menu_item_ids[] = $menu_item_id;

                $add_recursive($term_id, $menu_item_id);
            }
        };

        $add_recursive(0, $root_menu_id);

        foreach ($existing_menu_items as $parent_id => $items) {
            foreach ($items as $term_id => $item_id) {
                if (!in_array($term_id, $all_term_ids) && !in_array($item_id, $new_menu_item_ids)) {
                    wp_delete_post($item_id, true);
                }
            }
        }

        return true;

    } catch (Exception $e) {
        error_log('Menu sync error (' . $taxonomy . '): ' . $e->getMessage());
        return false;
    } finally {
        $running[$taxonomy] = false;
    }
}

// Main sync function for product categories
function softone_sync_woocommerce_product_categories_menu($menu_name = 'Main Menu', $parent_title = 'Products') {
    $result = softone_sync_taxonomy_menu('product_cat', $menu_name, $parent_title, [
        'exclude_slugs'     => ['uncategorized'],
        'move_to_end_slugs' => ['special-offers'],
        'add_mega_menu'     => true,
    ]);

    if ($result) {
        update_option('softone_last_product_menu_sync', current_time('mysql'));
    }

    return $result;
}

// Sync function for product brands
function softone_sync_woocommerce_product_brands_menu($menu_name = 'Main Menu', $parent_title = 'Brands') {
    $result = softone_sync_taxonomy_menu('product_brand', $menu_name, $parent_title, [
        'add_mega_menu' => false,
    ]);

    if ($result) {
        update_option('softone_last_product_brand_menu_sync', current_time('mysql'));
    }

    return $result;
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

function softone_schedule_auto_sync_product_brand_menu() {
    softone_schedule_auto_sync_product_menu();
}
add_action('created_product_brand', 'softone_schedule_auto_sync_product_brand_menu');
add_action('edited_product_brand', 'softone_schedule_auto_sync_product_brand_menu');
add_action('delete_product_brand', 'softone_schedule_auto_sync_product_brand_menu');

function softone_run_auto_sync_product_menu() {
    if (function_exists('softone_sync_woocommerce_product_categories_menu')) {
        softone_sync_woocommerce_product_categories_menu('Main Menu', 'Products');
    }
    if (function_exists('softone_sync_woocommerce_product_brands_menu')) {
        softone_sync_woocommerce_product_brands_menu('Main Menu', 'Brands');
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

        if (function_exists('softone_sync_woocommerce_product_categories_menu') && function_exists('softone_sync_woocommerce_product_brands_menu')) {
            $categories_result = softone_sync_woocommerce_product_categories_menu('Main Menu', 'Products');
            $brands_result = softone_sync_woocommerce_product_brands_menu('Main Menu', 'Brands');

            if ($categories_result && $brands_result) {
                $message = __('✅ Product categories and brands synced successfully.', 'softone-woocommerce-integration');
                $message_type = 'success';
            } else {
                $message = __('❌ There was an error syncing product categories or brands. Check logs.', 'softone-woocommerce-integration');
                $message_type = 'error';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Sync WooCommerce Product Categories and Brands', 'softone-woocommerce-integration'); ?></h1>

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

        <p><strong><?php esc_html_e('Last product category sync:', 'softone-woocommerce-integration'); ?></strong> <?php echo esc_html(get_option('softone_last_product_menu_sync', 'Never')); ?></p>
        <p><strong><?php esc_html_e('Last brand sync:', 'softone-woocommerce-integration'); ?></strong> <?php echo esc_html(get_option('softone_last_product_brand_menu_sync', 'Never')); ?></p>
        <p><strong><?php esc_html_e('Note:', 'softone-woocommerce-integration'); ?></strong> <?php esc_html_e('The sync runs automatically when product categories or brands are added, edited, or deleted.', 'softone-woocommerce-integration'); ?></p>
    </div>
    <?php
}
