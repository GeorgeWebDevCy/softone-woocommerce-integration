<?php
/**
 * Regression test ensuring colour variations sync into variable products.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( '__' ) ) {
    function __( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        global $softone_filter_overrides;

        if ( isset( $softone_filter_overrides[ $tag ] ) ) {
            $override = $softone_filter_overrides[ $tag ];

            if ( is_callable( $override ) ) {
                $args = func_get_args();
                return call_user_func_array( $override, array_slice( $args, 1 ) );
            }

            return $override;
        }

        return $value;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}

if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $title ) {
        $title = strtolower( (string) $title );
        $title = preg_replace( '/[^a-z0-9]+/i', '-', $title );
        return trim( (string) $title, '-' );
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return false;
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        global $softone_post_meta;

        if ( isset( $softone_post_meta[ $post_id ][ $key ] ) ) {
            return $softone_post_meta[ $post_id ][ $key ];
        }

        return '';
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $key, $value ) {
        global $softone_post_meta, $softone_products, $softone_mtrl_products, $softone_mtrl_variations;

        if ( ! isset( $softone_post_meta[ $post_id ] ) ) {
            $softone_post_meta[ $post_id ] = array();
        }

        $softone_post_meta[ $post_id ][ $key ] = $value;

        if ( class_exists( 'Softone_Item_Sync' ) && Softone_Item_Sync::META_MTRL === $key ) {
            $object = isset( $softone_products[ $post_id ] ) ? $softone_products[ $post_id ] : null;
            if ( $object instanceof WC_Product_Variation ) {
                $softone_mtrl_variations[ (string) $value ] = $post_id;
            } else {
                $softone_mtrl_products[ (string) $value ] = $post_id;
            }
        }

        return true;
    }
}

if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $post_id, $key ) {
        global $softone_post_meta, $softone_mtrl_products, $softone_mtrl_variations;

        if ( isset( $softone_post_meta[ $post_id ][ $key ] ) ) {
            $value = $softone_post_meta[ $post_id ][ $key ];
            unset( $softone_post_meta[ $post_id ][ $key ] );

            if ( empty( $softone_post_meta[ $post_id ] ) ) {
                unset( $softone_post_meta[ $post_id ] );
            }

            if ( class_exists( 'Softone_Item_Sync' ) && Softone_Item_Sync::META_MTRL === $key ) {
                $value = (string) $value;
                unset( $softone_mtrl_products[ $value ] );
                unset( $softone_mtrl_variations[ $value ] );
            }
        }

        return true;
    }
}

if ( ! function_exists( 'get_term' ) ) {
    function get_term( $term_id, $taxonomy ) {
        global $softone_terms;

        if ( isset( $softone_terms[ $taxonomy ][ $term_id ] ) ) {
            return $softone_terms[ $taxonomy ][ $term_id ];
        }

        return false;
    }
}

if ( ! function_exists( 'wc_get_product' ) ) {
    function wc_get_product( $product_id ) {
        global $softone_products, $softone_product_cache;

        $product_id = (int) $product_id;

        if ( isset( $softone_product_cache[ $product_id ] ) ) {
            return $softone_product_cache[ $product_id ];
        }

        if ( isset( $softone_products[ $product_id ] ) ) {
            $softone_product_cache[ $product_id ] = clone $softone_products[ $product_id ];
            return $softone_product_cache[ $product_id ];
        }

        return null;
    }
}

if ( ! function_exists( 'wc_delete_product_transients' ) ) {
    function wc_delete_product_transients( $product_id = 0 ) {
        global $softone_product_cache;

        if ( $product_id > 0 ) {
            unset( $softone_product_cache[ (int) $product_id ] );
            return;
        }

        $softone_product_cache = array();
    }
}

if ( ! function_exists( 'clean_post_cache' ) ) {
    function clean_post_cache( $post_id ) {
        global $softone_product_cache;

        if ( null === $post_id ) {
            $softone_product_cache = array();
            return;
        }

        $post_id = (int) $post_id;

        if ( $post_id > 0 && isset( $softone_product_cache[ $post_id ] ) ) {
            unset( $softone_product_cache[ $post_id ] );
        }
    }
}

if ( ! class_exists( 'WC_Cache_Helper' ) ) {
    class WC_Cache_Helper {
        public static $invalidated_groups = array();

        public static function invalidate_cache_group( $group ) {
            global $softone_product_cache;

            self::$invalidated_groups[] = (string) $group;

            if ( 'products' === $group ) {
                $softone_product_cache = array();
            }
        }
    }
}

if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
    function wc_get_product_id_by_sku( $sku ) {
        global $softone_products_by_sku;

        return isset( $softone_products_by_sku[ $sku ] ) ? (int) $softone_products_by_sku[ $sku ] : 0;
    }
}

if ( ! function_exists( 'wp_get_post_parent_id' ) ) {
    function wp_get_post_parent_id( $post_id ) {
        global $softone_products;

        if ( isset( $softone_products[ $post_id ] ) && method_exists( $softone_products[ $post_id ], 'get_parent_id' ) ) {
            return (int) $softone_products[ $post_id ]->get_parent_id();
        }

        return 0;
    }
}

if ( ! function_exists( 'wc_stock_amount' ) ) {
    function wc_stock_amount( $value ) {
        return (int) $value;
    }
}

if ( ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
    function wc_attribute_taxonomy_name( $slug ) {
        $slug = trim( (string) $slug );
        if ( '' === $slug ) {
            return '';
        }

        return 'pa_' . $slug;
    }
}

if ( ! function_exists( 'wc_variation_attribute_name' ) ) {
    function wc_variation_attribute_name( $taxonomy ) {
        $taxonomy = ltrim( (string) $taxonomy, '_' );
        return 'attribute_' . $taxonomy;
    }
}

if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
    function wc_attribute_taxonomy_id_by_name( $slug ) {
        static $ids = array();

        if ( isset( $ids[ $slug ] ) ) {
            return $ids[ $slug ];
        }

        $ids[ $slug ] = count( $ids ) + 1;
        return $ids[ $slug ];
    }
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
    function wp_set_object_terms( $object_id, $terms, $taxonomy ) {
        if ( 'product_type' !== $taxonomy ) {
            return array();
        }

        $terms = (array) $terms;
        $term  = reset( $terms );

        global $softone_products;

        if ( isset( $softone_products[ $object_id ] ) ) {
            $softone_products[ $object_id ]->set_type( (string) $term );
        }

        return array();
    }
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
    function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
        return array();
    }
}

if ( ! class_exists( 'Softone_API_Client' ) ) {
    class Softone_API_Client {}
}

if ( ! class_exists( 'Softone_Category_Sync_Logger' ) ) {
    class Softone_Category_Sync_Logger {
        public function __construct( $logger ) {}
    }
}

if ( ! class_exists( 'Softone_Sync_Activity_Logger' ) ) {
    class Softone_Sync_Activity_Logger {}
}

if ( ! class_exists( 'WC_Product_Attribute' ) ) {
    class WC_Product_Attribute {
        protected $id = 0;
        protected $name = '';
        protected $options = array();
        protected $visible = true;
        protected $variation = false;
        protected $is_taxonomy = true;

        public function set_id( $id ) {
            $this->id = (int) $id;
        }

        public function get_id() {
            return $this->id;
        }

        public function set_name( $name ) {
            $this->name = (string) $name;
        }

        public function get_name() {
            return $this->name;
        }

        public function set_options( $options ) {
            $this->options = array_values( $options );
        }

        public function get_options() {
            return $this->options;
        }

        public function set_visible( $visible ) {
            $this->visible = (bool) $visible;
        }

        public function get_visible() {
            return $this->visible;
        }

        public function set_variation( $variation ) {
            $this->variation = (bool) $variation;
        }

        public function get_variation() {
            return $this->variation;
        }

        public function set_taxonomy( $is_taxonomy ) {
            $this->is_taxonomy = (bool) $is_taxonomy;
        }

        public function set_is_taxonomy( $is_taxonomy ) {
            $this->is_taxonomy = (bool) $is_taxonomy;
        }
    }
}

if ( ! class_exists( 'WC_Product' ) ) {
    class WC_Product {
        protected $id = 0;
        protected $type = 'simple';
        protected $sku = '';
        protected $attributes = array();
        protected $regular_price = '';
        protected $sale_price = '';
        protected $price = '';
        protected $manage_stock = false;
        protected $stock_quantity = null;
        protected $backorders = 'no';
        protected $status = 'publish';
        protected $parent_id = 0;
        protected $stock_status = 'instock';

        public function __construct( $id = 0 ) {
            $this->id = (int) $id;

            if ( $this->id > 0 && isset( $GLOBALS['softone_products'][ $this->id ] ) ) {
                $this->copy_from( $GLOBALS['softone_products'][ $this->id ] );
            }
        }

        protected function copy_from( $source ) {
            foreach ( get_object_vars( $source ) as $property => $value ) {
                $this->{$property} = $value;
            }
        }

        public function get_id() {
            return $this->id;
        }

        public function set_id( $id ) {
            $this->id = (int) $id;
        }

        public function get_type() {
            return $this->type;
        }

        public function set_type( $type ) {
            $this->type = (string) $type;
        }

        public function set_regular_price( $price ) {
            $this->regular_price = (string) $price;
        }

        public function get_regular_price() {
            return $this->regular_price;
        }

        public function set_sale_price( $price ) {
            $this->sale_price = (string) $price;
        }

        public function set_price( $price ) {
            $this->price = (string) $price;
        }

        public function get_price() {
            return $this->price;
        }

        public function set_manage_stock( $manage_stock ) {
            $this->manage_stock = (bool) $manage_stock;
        }

        public function get_manage_stock() {
            return $this->manage_stock;
        }

        public function set_stock_quantity( $quantity ) {
            $this->stock_quantity = ( null === $quantity ) ? null : (int) $quantity;
        }

        public function get_stock_quantity() {
            return $this->stock_quantity;
        }

        public function set_backorders( $backorders ) {
            $this->backorders = (string) $backorders;
        }

        public function get_backorders() {
            return $this->backorders;
        }

        public function set_stock_status( $status ) {
            $this->stock_status = (string) $status;
        }

        public function get_stock_status() {
            return $this->stock_status;
        }

        public function set_status( $status ) {
            $this->status = (string) $status;
        }

        public function get_status() {
            return $this->status;
        }

        public function set_sku( $sku ) {
            $old = $this->sku;
            $this->sku = (string) $sku;

            if ( '' !== $old && isset( $GLOBALS['softone_products_by_sku'][ $old ] ) ) {
                unset( $GLOBALS['softone_products_by_sku'][ $old ] );
            }

            if ( '' !== $this->sku && $this->id > 0 ) {
                $GLOBALS['softone_products_by_sku'][ $this->sku ] = $this->id;
            }
        }

        public function get_sku() {
            return $this->sku;
        }

        public function set_attributes( $attributes ) {
            $this->attributes = $attributes;
        }

        public function get_attributes() {
            return $this->attributes;
        }

        public function set_parent_id( $parent_id ) {
            $this->parent_id = (int) $parent_id;
        }

        public function get_parent_id() {
            return $this->parent_id;
        }

        public function get_attribute( $key ) {
            return '';
        }

        public function save() {
            if ( $this->id <= 0 ) {
                $this->id = ++$GLOBALS['softone_next_product_id'];
            }

            $GLOBALS['softone_products'][ $this->id ] = $this;

            if ( '' !== $this->sku ) {
                $GLOBALS['softone_products_by_sku'][ $this->sku ] = $this->id;
            }

            return $this->id;
        }
    }
}

if ( ! class_exists( 'WC_Product_Variable' ) ) {
    class WC_Product_Variable extends WC_Product {
        public function __construct( $id = 0 ) {
            parent::__construct( $id );
            $this->set_type( 'variable' );
        }
    }
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
    class WC_Product_Variation extends WC_Product {
        protected $attributes = array();

        public function __construct( $id = 0 ) {
            parent::__construct( $id );
            $this->set_type( 'variation' );
        }

        public function set_attributes( $attributes ) {
            $this->attributes = $attributes;
        }

        public function get_attributes() {
            return $this->attributes;
        }

        public function get_attribute( $key ) {
            return isset( $this->attributes[ $key ] ) ? $this->attributes[ $key ] : '';
        }

        public function save() {
            $id = parent::save();
            $this->set_type( 'variation' );

            if ( ! isset( $GLOBALS['softone_variations_by_parent'][ $this->get_parent_id() ] ) ) {
                $GLOBALS['softone_variations_by_parent'][ $this->get_parent_id() ] = array();
            }

            $GLOBALS['softone_variations_by_parent'][ $this->get_parent_id() ][ $id ] = $this;

            return $id;
        }
    }
}

$softone_post_meta            = array();
$softone_products             = array();
$softone_product_cache        = array();
$softone_products_by_sku       = array();
$softone_mtrl_products         = array();
$softone_mtrl_variations       = array();
$softone_terms                 = array();
$softone_variations_by_parent  = array();
$softone_next_product_id       = 2000;
$softone_filter_overrides      = array(
    'softone_wc_integration_enable_variable_product_handling' => true,
);

require_once dirname( __DIR__ ) . '/includes/class-softone-item-sync.php';

class Softone_Item_Sync_Variable_Test_Double extends Softone_Item_Sync {
    public $logs = array();

    protected function log( $level, $message, array $context = array() ) {
        $this->logs[] = array(
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        );
    }

    protected function find_product_id_by_mtrl( $mtrl ) {
        global $softone_mtrl_products, $softone_mtrl_variations;

        $mtrl = (string) $mtrl;

        if ( isset( $softone_mtrl_products[ $mtrl ] ) ) {
            return (int) $softone_mtrl_products[ $mtrl ];
        }

        if ( isset( $softone_mtrl_variations[ $mtrl ] ) ) {
            return (int) $softone_mtrl_variations[ $mtrl ];
        }

        return 0;
    }

    protected function find_variation_id_by_mtrl( $mtrl ) {
        global $softone_mtrl_variations;

        $mtrl = (string) $mtrl;

        return isset( $softone_mtrl_variations[ $mtrl ] ) ? (int) $softone_mtrl_variations[ $mtrl ] : 0;
    }

    protected function find_existing_variation_id( $product_id, $sku, $mtrl ) {
        global $softone_mtrl_variations, $softone_products_by_sku, $softone_products;

        $mtrl = (string) $mtrl;
        if ( '' !== $mtrl && isset( $softone_mtrl_variations[ $mtrl ] ) ) {
            $candidate = (int) $softone_mtrl_variations[ $mtrl ];
            if ( isset( $softone_products[ $candidate ] ) && $softone_products[ $candidate ] instanceof WC_Product_Variation ) {
                if ( $softone_products[ $candidate ]->get_parent_id() === (int) $product_id ) {
                    return $candidate;
                }
            }
        }

        $sku = (string) $sku;
        if ( '' !== $sku && isset( $softone_products_by_sku[ $sku ] ) ) {
            $candidate = (int) $softone_products_by_sku[ $sku ];
            if ( isset( $softone_products[ $candidate ] ) && $softone_products[ $candidate ] instanceof WC_Product_Variation ) {
                if ( $softone_products[ $candidate ]->get_parent_id() === (int) $product_id ) {
                    return $candidate;
                }
            }
        }

        return 0;
    }

    protected function refresh_related_item_children( $parent_mtrl ) {}

    protected function maybe_adjust_memory_limits() {}

    protected function reset_caches() {}

    public function queue_single_variation_public( $product_id, $colour_term_id, $colour_taxonomy, $sku, $price_value, $stock_amount, $mtrl, $should_backorder, array $additional_attributes = array() ) {
        parent::queue_single_product_variation( $product_id, $colour_term_id, $colour_taxonomy, $sku, $price_value, $stock_amount, $mtrl, $should_backorder, $additional_attributes );
    }

    public function process_single_variations_public() {
        parent::process_pending_single_product_variations();
    }

    public function queue_colour_sync_public( $product_id, $mtrl, array $related_item_mtrls, $colour_taxonomy ) {
        parent::queue_colour_variation_sync( $product_id, $mtrl, $related_item_mtrls, $colour_taxonomy );
    }

    public function process_colour_syncs_public() {
        parent::process_pending_colour_variation_syncs();
    }
}

$softone_terms['pa_colour'] = array(
    11 => (object) array( 'term_id' => 11, 'name' => 'Red', 'slug' => 'red' ),
    12 => (object) array( 'term_id' => 12, 'name' => 'Blue', 'slug' => 'blue' ),
);
$softone_terms['pa_size'] = array(
    21 => (object) array( 'term_id' => 21, 'name' => 'Large', 'slug' => 'large' ),
);

$sync = new Softone_Item_Sync_Variable_Test_Double( new Softone_API_Client(), null, null, null );

$parent_product = new WC_Product( 501 );
$parent_product->set_type( 'simple' );
$parent_product->set_sku( 'SKU-RED' );
$parent_product->set_regular_price( '29.99' );
$parent_product->save();
update_post_meta( 501, Softone_Item_Sync::META_MTRL, 'MTRL-RED' );

$sync->queue_single_variation_public( 501, 11, 'pa_colour', 'SKU-RED', '29.99', 5, 'MTRL-RED', false, array() );
$sync->process_single_variations_public();

$parent_after = wc_get_product( 501 );
if ( ! $parent_after ) {
    throw new RuntimeException( 'Expected parent product to remain accessible after conversion.' );
}

if ( 'variable' !== $parent_after->get_type() ) {
    throw new RuntimeException( 'Parent product should convert to a variable product when queuing a variation.' );
}

$colour_attribute_found = false;
$colour_options         = array();
$parent_attributes      = $parent_after->get_attributes();

foreach ( $parent_attributes as $attribute ) {
    if ( $attribute instanceof WC_Product_Attribute && 'pa_colour' === $attribute->get_name() ) {
        $colour_attribute_found = true;
        $colour_options         = array_map( 'intval', $attribute->get_options() );
        break;
    }
}

if ( ! $colour_attribute_found ) {
    throw new RuntimeException( 'Variable parent should expose the colour attribute.' );
}

if ( $colour_options !== array( 11 ) ) {
    throw new RuntimeException( 'Variable parent colour attribute should reference the queued colour term.' );
}

$variations = isset( $softone_variations_by_parent[ 501 ] ) ? $softone_variations_by_parent[ 501 ] : array();
if ( count( $variations ) !== 1 ) {
    throw new RuntimeException( 'Expected a single variation to be created from the single-product queue.' );
}

$red_variation = reset( $variations );
if ( ! $red_variation instanceof WC_Product_Variation ) {
    throw new RuntimeException( 'Queued variation should materialise as a WC_Product_Variation instance.' );
}

if ( 'SKU-RED' !== $red_variation->get_sku() ) {
    throw new RuntimeException( 'Red variation should inherit the SKU from the source product.' );
}

if ( '29.99' !== $red_variation->get_regular_price() ) {
    throw new RuntimeException( 'Red variation should inherit the regular price from the source product.' );
}

if ( ! $red_variation->get_manage_stock() || 5 !== $red_variation->get_stock_quantity() ) {
    throw new RuntimeException( 'Red variation should manage stock using the queued quantity.' );
}

if ( 'no' !== $red_variation->get_backorders() ) {
    throw new RuntimeException( 'Red variation should not allow backorders when the flag is false.' );
}

$red_attributes = $red_variation->get_attributes();
if ( ! isset( $red_attributes['attribute_pa_colour'] ) || 'red' !== $red_attributes['attribute_pa_colour'] ) {
    throw new RuntimeException( 'Red variation should store the colour slug as a variation attribute.' );
}

$red_mtrl_meta = get_post_meta( $red_variation->get_id(), Softone_Item_Sync::META_MTRL, true );
if ( 'MTRL-RED' !== $red_mtrl_meta ) {
    throw new RuntimeException( 'Red variation should persist the Softone material identifier.' );
}

$blue_product = new WC_Product( 601 );
$blue_product->set_type( 'simple' );
$blue_product->set_sku( 'SKU-BLUE' );
$blue_product->set_regular_price( '34.50' );
$blue_product->set_manage_stock( true );
$blue_product->set_stock_quantity( 2 );
$blue_product->set_backorders( 'notify' );

$blue_colour_attr = new WC_Product_Attribute();
$blue_colour_attr->set_name( 'pa_colour' );
$blue_colour_attr->set_options( array( 12 ) );
$blue_colour_attr->set_visible( true );
$blue_colour_attr->set_variation( true );
$blue_colour_attr->set_taxonomy( true );

$blue_size_attr = new WC_Product_Attribute();
$blue_size_attr->set_name( 'pa_size' );
$blue_size_attr->set_options( array( 21 ) );
$blue_size_attr->set_visible( true );
$blue_size_attr->set_variation( true );
$blue_size_attr->set_taxonomy( true );

$blue_product->set_attributes( array(
    'pa_colour' => $blue_colour_attr,
    'pa_size'   => $blue_size_attr,
) );
$blue_product->save();
update_post_meta( 601, Softone_Item_Sync::META_MTRL, 'MTRL-BLUE' );

$sync->queue_colour_sync_public( 501, 'MTRL-RED', array( 'MTRL-RED', 'MTRL-BLUE' ), 'pa_colour' );
$sync->process_colour_syncs_public();

$variations = isset( $softone_variations_by_parent[ 501 ] ) ? $softone_variations_by_parent[ 501 ] : array();
if ( count( $variations ) !== 2 ) {
    throw new RuntimeException( 'Colour sync should aggregate related products into parent variations.' );
}

if ( ! isset( $softone_mtrl_variations['MTRL-BLUE'] ) ) {
    throw new RuntimeException( 'Blue material should map to a new variation.' );
}

$blue_variation_id = $softone_mtrl_variations['MTRL-BLUE'];
$blue_variation    = wc_get_product( $blue_variation_id );
if ( ! $blue_variation instanceof WC_Product_Variation ) {
    throw new RuntimeException( 'Blue variation should be a WC_Product_Variation instance.' );
}

$blue_attributes = $blue_variation->get_attributes();
if ( ! isset( $blue_attributes['attribute_pa_colour'] ) || 'blue' !== $blue_attributes['attribute_pa_colour'] ) {
    throw new RuntimeException( 'Blue variation should expose the colour attribute slug.' );
}

if ( 'SKU-BLUE' !== $blue_variation->get_sku() ) {
    throw new RuntimeException( 'Blue variation should inherit the source SKU.' );
}

if ( '34.50' !== $blue_variation->get_regular_price() ) {
    throw new RuntimeException( 'Blue variation should inherit the source price.' );
}

if ( ! $blue_variation->get_manage_stock() || 2 !== $blue_variation->get_stock_quantity() ) {
    throw new RuntimeException( 'Blue variation should copy the source stock quantity.' );
}

if ( 'notify' !== $blue_variation->get_backorders() ) {
    throw new RuntimeException( 'Blue variation should preserve the source backorder setting.' );
}

if ( 'instock' !== $blue_variation->get_stock_status() ) {
    throw new RuntimeException( 'Blue variation should mark in-stock items accordingly.' );
}

$colour_options = array();
$size_options   = array();
$parent_attributes = $parent_after->get_attributes();

foreach ( $parent_attributes as $attribute ) {
    if ( ! $attribute instanceof WC_Product_Attribute ) {
        continue;
    }

    if ( 'pa_colour' === $attribute->get_name() ) {
        $colour_options = array_map( 'intval', $attribute->get_options() );
        sort( $colour_options );
    }

    if ( 'pa_size' === $attribute->get_name() ) {
        $size_options = array_map( 'intval', $attribute->get_options() );
    }
}

if ( $colour_options !== array( 11, 12 ) ) {
    throw new RuntimeException( 'Parent colour attribute should include both related colour terms.' );
}

if ( $size_options !== array( 21 ) ) {
    throw new RuntimeException( 'Parent size attribute should include the aggregated size term.' );
}

$blue_source = wc_get_product( 601 );
if ( ! $blue_source || 'draft' !== $blue_source->get_status() ) {
    throw new RuntimeException( 'Single-product sources should be drafted after migrating into a variation.' );
}

$cached_parent = new WC_Product( 701 );
$cached_parent->set_type( 'simple' );
$cached_parent->set_sku( 'SKU-CACHED' );
$cached_parent->set_regular_price( '19.99' );
$cached_parent->save();
update_post_meta( 701, Softone_Item_Sync::META_MTRL, 'MTRL-CACHED' );

$primed_simple = wc_get_product( 701 );
if ( ! $primed_simple || 'simple' !== $primed_simple->get_type() ) {
    throw new RuntimeException( 'Failed to simulate a cached simple product prior to conversion.' );
}

$sync->queue_single_variation_public( 701, 11, 'pa_colour', 'SKU-CACHED', '19.99', null, 'MTRL-CACHED', false, array() );
$sync->process_single_variations_public();

$refreshed_parent = wc_get_product( 701 );
if ( ! $refreshed_parent || 'variable' !== $refreshed_parent->get_type() ) {
    throw new RuntimeException( 'Simple product should convert to a variable product after cache invalidation.' );
}

$cached_variations = isset( $softone_variations_by_parent[ 701 ] ) ? $softone_variations_by_parent[ 701 ] : array();
if ( count( $cached_variations ) !== 1 ) {
    throw new RuntimeException( 'Expected a single variation to materialise for the cached product conversion.' );
}

foreach ( $sync->logs as $log_entry ) {
    if ( isset( $log_entry['context']['product_id'], $log_entry['context']['reason'] ) ) {
        if ( 701 === (int) $log_entry['context']['product_id'] && 'product_not_variable' === $log_entry['context']['reason'] ) {
            throw new RuntimeException( 'ensure_colour_variation should operate on the refreshed variable product object.' );
        }
    }
}

echo 'Variable product colour variation regression test passed.' . PHP_EOL;
