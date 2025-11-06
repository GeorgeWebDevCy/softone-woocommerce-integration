<?php
/**
 * SoftOne item synchronisation service (variable products by colour).
 *
 * Drop-in replacement for your current item sync. Creates variable parents grouped
 * by cleaned title (DESC without "| colour"), brand and CODE; creates variations by colour.
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

    const CRON_HOOK    = 'softone_wc_integration_sync_items';
    const ADMIN_ACTION = 'softone_wc_integration_run_item_import';

    // Common meta keys you use elsewhere (keep names stable):
    const META_MTRL      = '_softone_mtrl_id';
    const META_LAST_SYNC = '_softone_last_synced';
    const META_BARCODE   = '_softone_barcode';
    const META_BRAND     = '_softone_brand';
    const META_SOFTONE_CODE = '_softone_item_code';

    /**
     * Boot wiring (call once in your main plugin loader).
     */
    public function init() {
        add_action( self::CRON_HOOK, [ $this, 'run' ] );
        add_action( 'admin_post_' . self::ADMIN_ACTION, [ $this, 'run_from_admin' ] );
        // Ensure attribute taxonomy exists early.
        add_action( 'init', [ $this, 'ensure_colour_taxonomy' ], 11 );
    }

    /**
     * Manual trigger from wp-admin action.
     */
    public function run_from_admin() {
        $this->run();
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
        exit;
    }

    /**
     * Main sync entrypoint – fetch your SoftOne rows externally, then pass here.
     * For demo we expect you call $this->process_items( $rows ) after you get the API payload.
     */
    public function run() {
        $logger = new Softone_Sync_Activity_Logger();
        try {
            // TODO: replace with your real API call helper.
            $payload = $this->fetch_softone_items();
            if ( ! $payload || empty( $payload['success'] ) || empty( $payload['rows'] ) ) {
                $logger->log( 'SoftOne: No items to sync or API error.' );
                return;
            }
            $processed = $this->process_items( $payload['rows'] );
            $logger->log( sprintf( 'SoftOne: processed %d rows into variable products.', $processed ) );

        } catch ( \Throwable $e ) {
            $logger->log( 'SoftOne error: ' . $e->getMessage() );
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
     * Core: transform rows => variable parents + colour variations.
     */
    public function process_items( array $rows ) : int {
        $logger = new Softone_Sync_Activity_Logger();

        // Group by parent key (brand + cleaned title + code), so each colour becomes a variation.
        $groups = [];

        foreach ( $rows as $r ) {
            $desc_raw  = $this->g( $r, 'DESC' );
            $brand     = $this->g( $r, 'BRAND NAME' ) ?: $this->g( $r, 'BRAND' ) ?: '';
            $code      = $this->g( $r, 'CODE' ); // stable parent reference from SoftOne
            $sku       = $this->g( $r, 'SKU' );  // variation SKU
            $barcode   = $this->g( $r, 'BARCODE' );
            $mtrl      = $this->g( $r, 'MTRL' );
            $price     = $this->g( $r, 'RETAILPRICE' );
            $qty       = $this->g( $r, 'Stock QTY' );

            // Colour from fields (prefer explicit), else parse from suffix in DESC ("... | colour").
            $colour    = $this->g( $r, 'COLOUR NAME' ) ?: $this->parse_colour_from_desc( $desc_raw );
            $base_desc = $this->strip_colour_from_desc( $desc_raw );

            // Fallbacks
            $title_for_parent = $base_desc ?: $desc_raw ?: ( $sku ?: $code );

            $parent_key = $this->parent_key( $brand, $title_for_parent, $code );

            $groups[ $parent_key ]['brand']          = $brand;
            $groups[ $parent_key ]['title']          = $title_for_parent;
            $groups[ $parent_key ]['code']           = $code;
            $groups[ $parent_key ]['category_name']  = $this->g( $r, 'COMMECATEGORY NAME' ) ?: $this->g( $r, 'COMMERCATEGORY NAME' ) ?: '';
            $groups[ $parent_key ]['subcategory_name']= $this->g( $r, 'SUBMECATEGORY NAME' ) ?: $this->g( $r, 'SUBCATEGORY NAME' ) ?: '';

            $groups[ $parent_key ]['items'][] = [
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
                $logger->log( 'Failed to upsert parent for key: ' . $key );
                continue;
            }

            // Ensure pa_colour attribute is attached to parent and used for variations.
            $this->attach_parent_colour_attribute( $parent_id, wp_list_pluck( $group['items'], 'colour' ) );

            // Upsert variations
            foreach ( $group['items'] as $item ) {
                $this->upsert_colour_variation( $parent_id, $item );
            }

            // Set categories/brand meta after variations exist (optional, cosmetic order).
            $this->apply_categories_and_brand( $parent_id, $group );

            $processed += count( $group['items'] );

            // Force parent to be "variable"
            $product = wc_get_product( $parent_id );
            if ( $product && $product->get_type() !== 'variable' ) {
                wp_set_object_terms( $parent_id, 'variable', 'product_type', false );
            }
        }

        return $processed;
    }

    /**
     * Create/update variable parent.
     */
    protected function upsert_variable_parent( array $group ) : int {
        $title = $group['title'];
        $code  = $group['code'];

        // Find existing by meta (SoftOne CODE stored on parent) or by exact title.
        $existing = $this->find_product_by_meta( self::META_SOFTONE_CODE, $code );
        $post_id  = $existing ?: wc_get_product_id_by_sku( $code ); // treat CODE as parent SKU fallback

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

        // Ensure product type = variable
        wp_set_object_terms( $post_id, 'variable', 'product_type', false );

        // Basic meta
        update_post_meta( $post_id, self::META_SOFTONE_CODE, $code );
        update_post_meta( $post_id, self::META_LAST_SYNC, current_time( 'mysql' ) );

        // Set parent SKU to CODE to keep a stable identifier
        $product = wc_get_product( $post_id );
        if ( $product ) {
            if ( ! $product->get_sku() ) {
                $product->set_sku( $code );
            }
            $product->save();
        }

        return (int) $post_id;
    }

    /**
     * Ensure parent has pa_colour attribute covering all variation colours.
     */
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

        // Attach attribute to parent
        $product = wc_get_product( $parent_id );
        if ( ! $product ) {
            return;
        }

        $attributes = $product->get_attributes();
        $tax_obj    = wc_get_attribute_taxonomy_by_name( $taxonomy );

        $attr = new WC_Product_Attribute();
        $attr->set_id( (int) ( $tax_obj ? $tax_obj->attribute_id : 0 ) );
        $attr->set_name( $taxonomy );
        $attr->set_options( $term_slugs );
        $attr->set_visible( true );
        $attr->set_variation( true );

        // Replace or add
        $attributes[ $taxonomy ] = $attr;
        $product->set_attributes( $attributes );
        $product->save();

        // Set terms on parent so filters work
        if ( ! empty( $term_slugs ) ) {
            wp_set_object_terms( $parent_id, $term_slugs, $taxonomy, false );
        }
    }

    /**
     * Upsert a variation for the given colour row.
     */
    protected function upsert_colour_variation( int $parent_id, array $item ) {
        $taxonomy   = 'pa_colour';
        $colour_raw = $item['colour'] ?: 'N/A';
        $colour     = $this->normalize_colour_name( $colour_raw );
        $term       = $this->get_or_create_term( $taxonomy, $colour );
        if ( ! $term || is_wp_error( $term ) ) {
            return;
        }

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

        if ( ! $variation_id || is_wp_error( $variation_id ) ) {
            return;
        }

        // Attribute on variation
        update_post_meta( $variation_id, 'attribute_' . $taxonomy, $term['slug'] );

        // Price / stock / SKU / barcode / mtrl
        $v = new WC_Product_Variation( $variation_id );

        if ( ! empty( $item['sku'] ) ) {
            // Ensure unique, don’t collide with parent’s CODE
            if ( $v->get_sku() !== $item['sku'] ) {
                $v->set_sku( $item['sku'] );
            }
        }

        if ( isset( $item['price'] ) && $item['price'] !== null ) {
            $v->set_regular_price( (string) $item['price'] );
        }

        // Stock handling: if qty null => manage stock off; else set qty and manage on
        if ( $item['qty'] === null ) {
            $v->set_manage_stock( false );
            $v->set_stock_status( 'instock' ); // or 'onbackorder' if you prefer
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

    /**
     * Categories + brand (optional: adjust to your taxonomy slugs).
     */
    protected function apply_categories_and_brand( int $parent_id, array $group ) {
        // Product categories (you likely already handle mapping – this is safe/no-op if empty)
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

        // Brand meta
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
        // Create attribute taxonomy using WooCommerce API.
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
                        'labels'       => [
                            'name' => __( 'Colours', 'woocommerce' ),
                        ],
                        'hierarchical' => false,
                        'show_ui'      => false,
                        'query_var'    => true,
                        'rewrite'      => false,
                    ] )
                );
            }
        }
    }

    /**
     * Return existing variation id matching the attribute value, or 0.
     */
    protected function find_existing_variation( int $parent_id, string $taxonomy, string $term_slug ) : int {
        $args = [
            'post_parent' => $parent_id,
            'post_type'   => 'product_variation',
            'numberposts' => -1,
            'post_status' => [ 'publish', 'private' ],
            'fields'      => 'ids',
        ];
        $ids = get_children( $args );
        if ( empty( $ids ) ) {
            return 0;
        }
        foreach ( $ids as $vid ) {
            $val = get_post_meta( $vid, 'attribute_' . $taxonomy, true );
            if ( $val === $term_slug ) {
                return (int) $vid;
            }
        }
        return 0;
    }

    /**
     * Create/find attribute term.
     */
    protected function get_or_create_term( string $taxonomy, string $label ) {
        $label = $this->normalize_colour_name( $label );
        $exists = term_exists( $label, $taxonomy );
        if ( $exists ) {
            if ( is_array( $exists ) ) {
                $term = get_term( $exists['term_id'], $taxonomy );
            } else {
                $term = get_term( (int) $exists, $taxonomy );
            }
        } else {
            $term = wp_insert_term( $label, $taxonomy, [ 'slug' => sanitize_title( $label ) ] );
            if ( ! is_wp_error( $term ) ) {
                $term = get_term( $term['term_id'], $taxonomy );
            }
        }
        if ( is_wp_error( $term ) || ! $term ) {
            return null;
        }
        return [ 'term_id' => $term->term_id, 'slug' => $term->slug, 'name' => $term->name ];
    }

    /**
     * SoftOne sometimes sends "Desc ... | black". Extract trailing colour if present.
     */
    protected function parse_colour_from_desc( ?string $desc ) : ?string {
        if ( ! $desc ) return null;
        // Capture last " | something" group (ignore extra pipes in the middle).
        if ( preg_match( '/\s*\|\s*([^|]+)\s*$/u', $desc, $m ) ) {
            $colour = trim( $m[1] );
            // Filter out generic dashes or empties
            if ( $colour !== '' && $colour !== '-' ) {
                return $this->normalize_colour_name( $colour );
            }
        }
        return null;
    }

    /**
     * Remove trailing " | colour" from DESC for parent title.
     */
    protected function strip_colour_from_desc( ?string $desc ) : ?string {
        if ( ! $desc ) return $desc;
        return preg_replace( '/\s*\|\s*[^|]+\s*$/u', '', $desc );
    }

    /**
     * Normalise colour labels (capitalise nicely, unify common forms).
     */
    protected function normalize_colour_name( string $c ) : string {
        $c = trim( $c );
        // Common quick normalisations
        $map = [
            'blk' => 'Black',
            'black' => 'Black',
            'denim blue' => 'Denim Blue',
            'olive green' => 'Olive Green',
            'sepia black' => 'Sepia Black',
            '-' => '',
        ];
        $lc = mb_strtolower( $c, 'UTF-8' );
        if ( isset( $map[ $lc ] ) ) {
            return $map[ $lc ];
        }
        // Title-case fallback (keeps all-caps acronyms mostly intact)
        $c = mb_convert_case( $c, MB_CASE_TITLE, 'UTF-8' );
        return $c;
    }

    /**
     * Defensive getter for SoftOne rows (handles odd spaced keys).
     */
    protected function g( array $row, string $key ) {
        if ( isset( $row[ $key ] ) ) return $row[ $key ];
        // Try normalise spaces/underscores
        $alts = [
            $key,
            str_replace( ' ', '_', $key ),
            str_replace( '_', ' ', $key ),
        ];
        foreach ( $alts as $k ) {
            if ( isset( $row[ $k ] ) ) return $row[ $k ];
        }
        // Try case-insensitive
        foreach ( $row as $k => $v ) {
            if ( mb_strtolower( $k ) === mb_strtolower( $key ) ) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Unique parent key.
     */
    protected function parent_key( string $brand, string $title, string $code ) : string {
        return md5( implode( '|', [ trim( $brand ), trim( $title ), trim( (string) $code ) ] ) );
    }

    /**
     * Find product id by meta key/value.
     */
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
}

endif;

// Bootstrap (if your main plugin loader doesn’t already do this):
if ( class_exists( 'Softone_Item_Sync' ) ) {
    $GLOBALS['softone_item_sync'] = new Softone_Item_Sync();
    $GLOBALS['softone_item_sync']->init();
}
