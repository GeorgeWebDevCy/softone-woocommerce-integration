<?php
/**
 * SoftOne item synchronisation service (variable products by colour).
 *
 * Creates variable parents grouped by cleaned title (DESC without "| colour"), brand and CODE;
 * then creates variations by colour. Safely handles SoftOne's odd field names.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Softone_Category_Sync_Logger' ) ) {
    require_once __DIR__ . '/class-softone-category-sync-logger.php';
}
if ( ! class_exists( 'Softone_Sync_Activity_Logger' ) ) {
    require_once __DIR__ . '/class-softone-sync-activity-logger.php';
}

if ( ! class_exists( 'Softone_Item_Sync' ) ) :

class Softone_Item_Sync {

    const CRON_HOOK         = 'softone_wc_integration_sync_items';
    const ADMIN_ACTION      = 'softone_wc_integration_run_item_import';
    const OPTION_LAST_RUN   = 'softone_last_run';
    const META_MTRL         = '_softone_mtrl_id';
    const META_LAST_SYNC    = '_softone_last_synced';
    const META_BARCODE      = '_softone_barcode';
    const META_BRAND        = '_softone_brand';
    const META_SOFTONE_CODE = '_softone_item_code';

    /**
     * Back-compat for older loader code that expects register_hooks().
     * (Your loader is calling ->register_hooks(); keep it working.)
     *
     * @param mixed $loader Optional loader helper used by the plugin bootstrap.
     *
     * @return void
     */
    public function register_hooks( $loader = null ) {
        if ( $this->register_hooks_with_loader( $loader ) ) {
            return;
        }

        $this->init();

        if ( null !== $loader ) {
            $this->log_loader_fallback( $loader );
        }
    }

    /**
     * Preferred hook wiring (new name used internally).
     */
    public function init() {
        add_action( self::CRON_HOOK, [ $this, 'run' ] );
        add_action( 'admin_post_' . self::ADMIN_ACTION, [ $this, 'run_from_admin' ] );
        // Ensure attribute taxonomy exists early (after WC init).
        add_action( 'init', [ $this, 'ensure_colour_taxonomy' ], 11 );
    }

    /**
     * Attempt to register hooks via the plugin loader helper when available.
     *
     * @param mixed $loader Potential loader instance.
     *
     * @return bool True when hooks were registered using the loader, false otherwise.
     */
    protected function register_hooks_with_loader( $loader ) : bool {
        if ( ! is_object( $loader ) || ! method_exists( $loader, 'add_action' ) ) {
            return false;
        }

        $loader->add_action( self::CRON_HOOK, $this, 'run' );
        $loader->add_action( 'admin_post_' . self::ADMIN_ACTION, $this, 'run_from_admin' );
        $loader->add_action( 'init', $this, 'ensure_colour_taxonomy', 11 );

        return true;
    }

    /**
     * Log when the loader fallback path is triggered.
     *
     * @param mixed $loader Loader value that could not be used.
     *
     * @return void
     */
    protected function log_loader_fallback( $loader ) {
        if ( ! class_exists( 'Softone_Sync_Activity_Logger' ) ) {
            return;
        }

        $context = array(
            'loader_type' => is_object( $loader ) ? get_class( $loader ) : gettype( $loader ),
        );

        $logger = new Softone_Sync_Activity_Logger();
        $logger->log(
            'item_sync',
            'loader_fallback',
            'SoftOne item sync registered hooks directly because the loader helper was unavailable.',
            $context
        );
    }

    /**
     * Manual trigger from wp-admin action.
     */
    public function run_from_admin() {
        $this->run();
        if ( ! headers_sent() ) {
            wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
        }
        exit;
    }

    /**
     * Schedule the recurring cron event that runs the item synchronisation.
     *
     * @return bool True when the event was scheduled, false otherwise.
     */
    public static function schedule_event() {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
            self::log_cron_event(
                'cron_schedule_unsupported',
                'Failed to schedule the SoftOne item sync because WordPress cron helpers are unavailable.'
            );

            return false;
        }

        $next_run = wp_next_scheduled( self::CRON_HOOK );
        if ( $next_run ) {
            self::log_cron_event(
                'cron_schedule_skipped',
                'Skipped scheduling the SoftOne item sync because an event already exists.',
                array( 'scheduled_for' => (int) $next_run )
            );

            return false;
        }

        $scheduled = wp_schedule_event( time(), 'hourly', self::CRON_HOOK );

        if ( false === $scheduled ) {
            self::log_cron_event(
                'cron_schedule_failed',
                'WordPress failed to schedule the SoftOne item sync event.'
            );

            return false;
        }

        self::log_cron_event(
            'cron_scheduled',
            'Scheduled the SoftOne item sync hourly cron event.'
        );

        return true;
    }

    /**
     * Remove the scheduled cron event used by the item synchronisation.
     *
     * @return bool True when an event was removed, false otherwise.
     */
    public static function clear_scheduled_event() {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_unschedule_event' ) ) {
            self::log_cron_event(
                'cron_clear_unsupported',
                'Failed to clear the SoftOne item sync because WordPress cron helpers are unavailable.'
            );

            return false;
        }

        $timestamp = wp_next_scheduled( self::CRON_HOOK );

        if ( ! $timestamp ) {
            self::log_cron_event(
                'cron_clear_skipped',
                'Skipped clearing the SoftOne item sync because no event was scheduled.'
            );

            return false;
        }

        $cleared = wp_unschedule_event( $timestamp, self::CRON_HOOK );

        if ( false === $cleared ) {
            self::log_cron_event(
                'cron_clear_failed',
                'WordPress failed to clear the SoftOne item sync cron event.',
                array( 'scheduled_for' => (int) $timestamp )
            );

            return false;
        }

        self::log_cron_event(
            'cron_cleared',
            'Cleared the scheduled SoftOne item sync cron event.',
            array( 'scheduled_for' => (int) $timestamp )
        );

        return true;
    }

    /**
     * Main sync entrypoint – fetch your SoftOne rows and import.
     * Replace fetch_softone_items() with your real API call.
     */
    public function run() {
        $logger = new Softone_Sync_Activity_Logger();
        try {
            $payload = $this->fetch_softone_items();
            if ( empty( $payload ) || empty( $payload['success'] ) || empty( $payload['rows'] ) ) {
                $logger->log(
                    'item_sync',
                    'empty_payload',
                    'SoftOne: No items to sync or API error.',
                    array(
                        'payload_success' => isset( $payload['success'] ) ? (bool) $payload['success'] : null,
                        'row_count'       => isset( $payload['rows'] ) && is_array( $payload['rows'] )
                            ? count( $payload['rows'] )
                            : null,
                    )
                );
                return;
            }
            $processed = $this->process_items( $payload['rows'] );
            $logger->log(
                'item_sync',
                'processed',
                sprintf( 'SoftOne: processed %d rows into variable products.', $processed ),
                array( 'processed_rows' => (int) $processed )
            );
        } catch ( \Throwable $e ) {
            $logger->log(
                'item_sync',
                'exception',
                'SoftOne error: ' . $e->getMessage(),
                array(
                    'exception_class' => get_class( $e ),
                    'code'            => (int) $e->getCode(),
                )
            );
        }
    }

    /**
     * STUB – replace with your actual SoftOne API request.
     */
    protected function fetch_softone_items() {
        return [
            'success' => true,
            'rows'    => [],
        ];
    }

    /**
     * Transform rows => variable parents + colour variations.
     */
    public function process_items( array $rows ) : int {
        $logger = new Softone_Sync_Activity_Logger();

        $groups = [];

        foreach ( $rows as $r ) {
            $desc_raw  = $this->g( $r, 'DESC' );
            $brand     = $this->g( $r, 'BRAND NAME' ) ?: $this->g( $r, 'BRAND' ) ?: '';
            $code      = $this->g( $r, 'CODE' ); // parent CODE (stable id)
            $sku       = $this->g( $r, 'SKU' );  // variation SKU
            $barcode   = $this->g( $r, 'BARCODE' );
            $mtrl      = $this->g( $r, 'MTRL' );
            $price     = $this->g( $r, 'RETAILPRICE' );
            $qty       = $this->g( $r, 'Stock QTY' );

            // Colour: prefer explicit, else parse from " | colour" suffix in DESC.
            $colour    = $this->g( $r, 'COLOUR NAME' ) ?: $this->parse_colour_from_desc( $desc_raw );
            $base_desc = $this->strip_colour_from_desc( $desc_raw );
            $title_for_parent = $base_desc ?: $desc_raw ?: ( $sku ?: $code );

            $groups_key = $this->parent_key( $brand, $title_for_parent, $code );

            $groups[ $groups_key ]['brand']            = $brand;
            $groups[ $groups_key ]['title']            = $title_for_parent;
            $groups[ $groups_key ]['code']             = $code;
            $groups[ $groups_key ]['category_name']    = $this->g( $r, 'COMMECATEGORY NAME' ) ?: $this->g( $r, 'COMMERCATEGORY NAME' ) ?: '';
            $groups[ $groups_key ]['subcategory_name'] = $this->g( $r, 'SUBMECATEGORY NAME' ) ?: $this->g( $r, 'SUBCATEGORY NAME' ) ?: '';

            $groups[ $groups_key ]['items'][] = [
                'sku'     => $sku,
                'barcode' => $barcode,
                'mtrl'    => $mtrl,
                'price'   => is_numeric( $price ) ? (float) $price : null,
                'qty'     => ( $qty === '' || $qty === null ) ? null : (float) $qty,
                'colour'  => $colour ?: 'N/A',
                'raw'     => $r,
            ];
        }

        $processed = 0;

        foreach ( $groups as $key => $group ) {
            $parent_id = $this->upsert_variable_parent( $group );
            if ( ! $parent_id ) {
                $logger->log(
                    'item_sync',
                    'parent_upsert_failed',
                    'Failed to upsert parent for key: ' . $key,
                    array(
                        'brand' => isset( $group['brand'] ) ? $group['brand'] : '',
                        'code'  => isset( $group['code'] ) ? $group['code'] : '',
                    )
                );
                continue;
            }

            // Ensure pa_colour set on parent
            $this->attach_parent_colour_attribute( $parent_id, wp_list_pluck( $group['items'], 'colour' ) );

            // Upsert variations
            foreach ( $group['items'] as $item ) {
                $this->upsert_colour_variation( $parent_id, $item );
            }

            // Categories & brand meta
            $this->apply_categories_and_brand( $parent_id, $group );

            $processed += count( $group['items'] );

            // Force parent type to "variable"
            $product = wc_get_product( $parent_id );
            if ( $product && $product->get_type() !== 'variable' ) {
                wp_set_object_terms( $parent_id, 'variable', 'product_type', false );
            }
        }

        return $processed;
    }

    protected function upsert_variable_parent( array $group ) : int {
        $title = $group['title'];
        $code  = $group['code'];

        $existing = $this->find_product_by_meta( self::META_SOFTONE_CODE, $code );
        $post_id  = $existing ?: wc_get_product_id_by_sku( $code );

        if ( ! $post_id ) {
            $post_id = wp_insert_post( [
                'post_title'   => wp_strip_all_tags( $title ),
                'post_status'  => 'publish',
                'post_type'    => 'product',
                'post_content' => '',
            ] );
        }

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            return 0;
        }

        wp_set_object_terms( $post_id, 'variable', 'product_type', false );

        update_post_meta( $post_id, self::META_SOFTONE_CODE, $code );
        update_post_meta( $post_id, self::META_LAST_SYNC, current_time( 'mysql' ) );

        $product = wc_get_product( $post_id );
        if ( $product ) {
            if ( ! $product->get_sku() ) {
                $product->set_sku( $code ); // parent SKU = CODE
            }
            $product->save();
        }

        return (int) $post_id;
    }

    protected function attach_parent_colour_attribute( int $parent_id, array $colours ) {
        $colours = array_filter( array_map( 'trim', $colours ) );
        $colours = array_unique( $colours );

        $taxonomy = 'pa_colour';
        $this->ensure_colour_taxonomy();

        $term_slugs = [];
        foreach ( $colours as $c ) {
            $term = $this->get_or_create_term( $taxonomy, $c );
            if ( $term && ! is_wp_error( $term ) ) {
                $term_slugs[] = $term['slug'];
            }
        }

        $product = wc_get_product( $parent_id );
        if ( ! $product ) return;

        $attributes = $product->get_attributes();
        $tax_obj    = wc_get_attribute_taxonomy_by_name( $taxonomy );

        $attr = new WC_Product_Attribute();
        $attr->set_id( (int) ( $tax_obj ? $tax_obj->attribute_id : 0 ) );
        $attr->set_name( $taxonomy );
        $attr->set_options( $term_slugs );
        $attr->set_visible( true );
        $attr->set_variation( true );

        $attributes[ $taxonomy ] = $attr;
        $product->set_attributes( $attributes );
        $product->save();

        if ( ! empty( $term_slugs ) ) {
            wp_set_object_terms( $parent_id, $term_slugs, $taxonomy, false );
        }
    }

    protected function upsert_colour_variation( int $parent_id, array $item ) {
        $taxonomy   = 'pa_colour';
        $colour_raw = $item['colour'] ?: 'N/A';
        $colour     = $this->normalize_colour_name( $colour_raw );
        $term       = $this->get_or_create_term( $taxonomy, $colour );
        if ( ! $term || is_wp_error( $term ) ) return;

        $variation_id = $this->find_existing_variation( $parent_id, $taxonomy, $term['slug'] );
        if ( ! $variation_id ) {
            $variation_id = wp_insert_post( [
                'post_title'  => get_the_title( $parent_id ) . ' – ' . $colour,
                'post_name'   => sanitize_title( get_the_title( $parent_id ) . ' ' . $colour ),
                'post_status' => 'publish',
                'post_parent' => $parent_id,
                'post_type'   => 'product_variation',
                'menu_order'  => 0,
            ] );
        }
        if ( ! $variation_id || is_wp_error( $variation_id ) ) return;

        update_post_meta( $variation_id, 'attribute_' . $taxonomy, $term['slug'] );

        $v = new WC_Product_Variation( $variation_id );

        if ( ! empty( $item['sku'] ) && $v->get_sku() !== $item['sku'] ) {
            $v->set_sku( $item['sku'] );
        }

        if ( isset( $item['price'] ) && $item['price'] !== null ) {
            $v->set_regular_price( (string) $item['price'] );
        }

        if ( $item['qty'] === null ) {
            $v->set_manage_stock( false );
            $v->set_stock_status( 'instock' );
        } else {
            $v->set_manage_stock( true );
            $v->set_stock_quantity( max( 0, (int) round( $item['qty'] ) ) );
            $v->set_stock_status( ( (float) $item['qty'] ) > 0 ? 'instock' : 'outofstock' );
        }

        $v->save();

        if ( ! empty( $item['barcode'] ) ) {
            update_post_meta( $variation_id, self::META_BARCODE, $item['barcode'] );
        }
        if ( ! empty( $item['mtrl'] ) ) {
            update_post_meta( $variation_id, self::META_MTRL, $item['mtrl'] );
        }
        update_post_meta( $variation_id, self::META_LAST_SYNC, current_time( 'mysql' ) );
    }

    protected function apply_categories_and_brand( int $parent_id, array $group ) {
        $cat  = trim( (string) $group['category_name'] );
        $sub  = trim( (string) $group['subcategory_name'] );
        $cats = array_filter( [ $cat, $sub ] );

        if ( ! empty( $cats ) ) {
            $term_ids = [];
            foreach ( $cats as $label ) {
                $term = term_exists( $label, 'product_cat' );
                if ( ! $term ) {
                    $term = wp_insert_term( $label, 'product_cat' );
                }
                if ( $term && ! is_wp_error( $term ) ) {
                    $term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
                }
            }
            if ( ! empty( $term_ids ) ) {
                wp_set_object_terms( $parent_id, $term_ids, 'product_cat', false );
            }
        }

        if ( ! empty( $group['brand'] ) ) {
            update_post_meta( $parent_id, self::META_BRAND, $group['brand'] );
        }
    }

    /**
     * Ensure pa_colour exists.
     */
    public function ensure_colour_taxonomy() {
        $taxonomy = 'pa_colour';
        if ( taxonomy_exists( $taxonomy ) ) {
            return;
        }
        if ( function_exists( 'wc_create_attribute' ) ) {
            $attr_id = wc_create_attribute( [
                'name'         => 'Colour',
                'slug'         => 'pa_colour',
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ] );
            if ( ! is_wp_error( $attr_id ) ) {
                register_taxonomy(
                    $taxonomy,
                    apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, [ 'product' ] ),
                    apply_filters( 'woocommerce_taxonomy_args_' . $taxonomy, [
                        'labels'       => [ 'name' => __( 'Colours', 'woocommerce' ) ],
                        'hierarchical' => false,
                        'show_ui'      => false,
                        'query_var'    => true,
                        'rewrite'      => false,
                    ] )
                );
            }
        }
    }

    protected function find_existing_variation( int $parent_id, string $taxonomy, string $term_slug ) : int {
        $ids = get_children( [
            'post_parent' => $parent_id,
            'post_type'   => 'product_variation',
            'numberposts' => -1,
            'post_status' => [ 'publish', 'private' ],
            'fields'      => 'ids',
        ] );
        if ( empty( $ids ) ) return 0;
        foreach ( $ids as $vid ) {
            $val = get_post_meta( $vid, 'attribute_' . $taxonomy, true );
            if ( $val === $term_slug ) return (int) $vid;
        }
        return 0;
    }

    protected function get_or_create_term( string $taxonomy, string $label ) {
        $label = $this->normalize_colour_name( $label );
        $exists = term_exists( $label, $taxonomy );
        if ( $exists ) {
            $term = is_array( $exists ) ? get_term( $exists['term_id'], $taxonomy ) : get_term( (int) $exists, $taxonomy );
        } else {
            $term = wp_insert_term( $label, $taxonomy, [ 'slug' => sanitize_title( $label ) ] );
            if ( ! is_wp_error( $term ) ) {
                $term = get_term( $term['term_id'], $taxonomy );
            }
        }
        if ( is_wp_error( $term ) || ! $term ) return null;
        return [ 'term_id' => $term->term_id, 'slug' => $term->slug, 'name' => $term->name ];
    }

    protected function parse_colour_from_desc( ?string $desc ) : ?string {
        if ( ! $desc ) return null;
        if ( preg_match( '/\s*\|\s*([^|]+)\s*$/u', $desc, $m ) ) {
            $colour = trim( $m[1] );
            if ( $colour !== '' && $colour !== '-' ) {
                return $this->normalize_colour_name( $colour );
            }
        }
        return null;
    }

    protected function strip_colour_from_desc( ?string $desc ) : ?string {
        if ( ! $desc ) return $desc;
        return preg_replace( '/\s*\|\s*[^|]+\s*$/u', '', $desc );
    }

    protected function normalize_colour_name( string $c ) : string {
        $c = trim( $c );
        $map = [
            'blk' => 'Black',
            'black' => 'Black',
            'denim blue' => 'Denim Blue',
            'olive green' => 'Olive Green',
            'sepia black' => 'Sepia Black',
            '-' => '',
        ];
        $lc = function_exists('mb_strtolower') ? mb_strtolower( $c, 'UTF-8' ) : strtolower( $c );
        if ( isset( $map[ $lc ] ) ) return $map[ $lc ];
        if ( function_exists('mb_convert_case') ) {
            return mb_convert_case( $c, MB_CASE_TITLE, 'UTF-8' );
        }
        return ucwords( strtolower( $c ) );
    }

    protected function g( array $row, string $key ) {
        if ( isset( $row[ $key ] ) ) return $row[ $key ];
        $alts = [
            $key,
            str_replace( ' ', '_', $key ),
            str_replace( '_', ' ', $key ),
        ];
        foreach ( $alts as $k ) {
            if ( isset( $row[ $k ] ) ) return $row[ $k ];
        }
        foreach ( $row as $k => $v ) {
            if ( strtolower( $k ) === strtolower( $key ) ) {
                return $v;
            }
        }
        return null;
    }

    protected function parent_key( string $brand, string $title, string $code ) : string {
        return md5( implode( '|', [ trim( $brand ), trim( $title ), trim( (string) $code ) ] ) );
    }

    protected function find_product_by_meta( string $meta_key, $meta_val ) : int {
        if ( ! $meta_val ) return 0;
        $q = new WP_Query( [
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'meta_key'       => $meta_key,
            'meta_value'     => $meta_val,
            'fields'         => 'ids',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'no_found_rows'  => true,
        ] );
        if ( $q->have_posts() ) {
            return (int) $q->posts[0];
        }
        return 0;
    }

    /**
     * Persist cron related log entries.
     *
     * @param string               $action  Log action key.
     * @param string               $message Human readable summary.
     * @param array<string, mixed> $context Additional context for the log entry.
     *
     * @return void
     */
    protected static function log_cron_event( $action, $message, array $context = array() ) {
        if ( ! class_exists( 'Softone_Sync_Activity_Logger' ) ) {
            return;
        }

        $logger = new Softone_Sync_Activity_Logger();
        $logger->log( 'item_sync', (string) $action, (string) $message, $context );
    }
}

endif;

// Optional bootstrap (only if your main loader *doesn't* instantiate this):
// if ( class_exists( 'Softone_Item_Sync' ) && ! isset( $GLOBALS['softone_item_sync'] ) ) {
//     $GLOBALS['softone_item_sync'] = new Softone_Item_Sync();
//     $GLOBALS['softone_item_sync']->register_hooks();
// }
