<?php
/**
 * Regression test ensuring taxonomy refresh bypasses payload hash short circuit.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( '__' ) ) {
    /**
     * Basic translation shim.
     *
     * @param string $text Text to translate.
     * @return string
     */
    function __( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    /**
     * Simple filter passthrough implementation.
     *
     * @param string $hook  Hook name.
     * @param mixed  $value Value to filter.
     * @return mixed
     */
    function apply_filters( $hook, $value ) {
        return $value;
    }
}

if ( ! class_exists( 'Softone_API_Client' ) ) {
    /**
     * Lightweight API client stub for tests.
     */
    class Softone_API_Client {
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    /**
     * Minimal wp_json_encode stand-in.
     *
     * @param mixed $data Data to encode.
     * @return string
     */
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}

if ( ! function_exists( 'softone_wc_integration_should_force_minimum_stock' ) ) {
    /**
     * Always avoid forcing minimum stock during tests.
     *
     * @return bool
     */
    function softone_wc_integration_should_force_minimum_stock() {
        return false;
    }
}

if ( ! function_exists( 'softone_wc_integration_should_backorder_out_of_stock' ) ) {
    /**
     * Disable backorders for the test harness.
     *
     * @return bool
     */
    function softone_wc_integration_should_backorder_out_of_stock() {
        return false;
    }
}

if ( ! function_exists( 'wc_stock_amount' ) ) {
    /**
     * Cast a stock value to an integer.
     *
     * @param mixed $value Stock value.
     * @return int
     */
    function wc_stock_amount( $value ) {
        return (int) $value;
    }
}

if ( ! function_exists( 'wc_format_decimal' ) ) {
    /**
     * Simplified price formatter.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    function wc_format_decimal( $value ) {
        return (string) $value;
    }
}

if ( ! function_exists( 'sanitize_title' ) ) {
    /**
     * Minimal sanitize_title replacement.
     *
     * @param string $value Raw value.
     * @return string
     */
    function sanitize_title( $value ) {
        $value = strtolower( (string) $value );
        $value = preg_replace( '/[^a-z0-9\-]+/', '-', $value );

        return trim( (string) $value, '-' );
    }
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
    /**
     * Always treat taxonomies as registered in the test harness.
     *
     * @param string $taxonomy Taxonomy name.
     * @return bool
     */
    function taxonomy_exists( $taxonomy ) {
        return true;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    /**
     * Lightweight WP_Error stand-in for tests.
     */
    class WP_Error {
        /**
         * @var string
         */
        protected $code;

        /**
         * @var string
         */
        protected $message;

        /**
         * @var mixed
         */
        protected $data;

        /**
         * Constructor.
         *
         * @param string $code    Error code.
         * @param string $message Error message.
         * @param mixed  $data    Optional data payload.
         */
        public function __construct( $code, $message = '', $data = null ) {
            $this->code    = (string) $code;
            $this->message = (string) $message;
            $this->data    = $data;
        }

        /**
         * Retrieve the error code.
         *
         * @return string
         */
        public function get_error_code() {
            return $this->code;
        }

        /**
         * Retrieve the error message.
         *
         * @return string
         */
        public function get_error_message() {
            return $this->message;
        }

        /**
         * Retrieve the error data payload.
         *
         * @return mixed
         */
        public function get_error_data() {
            return $this->data;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    /**
     * Determine whether a value is a WP_Error instance.
     *
     * @param mixed $thing Value to test.
     * @return bool
     */
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

$GLOBALS['softone_products']                     = array();
$GLOBALS['softone_next_product_id']               = 1;
$GLOBALS['softone_post_meta']                     = array();
$GLOBALS['softone_term_calls']                    = array();
$GLOBALS['softone_object_terms']                  = array();
$GLOBALS['softone_attribute_taxonomies']          = array();
$GLOBALS['softone_next_attribute_taxonomy_id']    = 1;
$GLOBALS['softone_terms']                         = array();
$GLOBALS['softone_next_term_id']                  = 1;
$GLOBALS['softone_clean_term_cache_invocations']  = array();

if ( ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
    /**
     * Build the taxonomy name for a given attribute slug.
     *
     * @param string $slug Attribute slug.
     * @return string
     */
    function wc_attribute_taxonomy_name( $slug ) {
        return 'pa_' . sanitize_title( $slug );
    }
}

if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
    /**
     * Retrieve an attribute taxonomy identifier by slug.
     *
     * @param string $slug Attribute slug.
     * @return int
     */
    function wc_attribute_taxonomy_id_by_name( $slug ) {
        $slug = sanitize_title( $slug );

        if ( isset( $GLOBALS['softone_attribute_taxonomies'][ $slug ] ) ) {
            return (int) $GLOBALS['softone_attribute_taxonomies'][ $slug ]['attribute_id'];
        }

        return 0;
    }
}

if ( ! function_exists( 'wc_create_attribute' ) ) {
    /**
     * Register an attribute taxonomy in the in-memory store.
     *
     * @param array $args Attribute arguments.
     * @return int|WP_Error
     */
    function wc_create_attribute( array $args ) {
        $defaults = array(
            'slug' => '',
            'name' => '',
        );

        $args = array_merge( $defaults, $args );
        $slug = sanitize_title( $args['slug'] );

        if ( '' === $slug ) {
            return new WP_Error( 'invalid_slug', 'Attribute slug cannot be empty.' );
        }

        if ( isset( $GLOBALS['softone_attribute_taxonomies'][ $slug ] ) ) {
            return (int) $GLOBALS['softone_attribute_taxonomies'][ $slug ]['attribute_id'];
        }

        $attribute_id = (int) $GLOBALS['softone_next_attribute_taxonomy_id'];
        $GLOBALS['softone_next_attribute_taxonomy_id']++;

        $GLOBALS['softone_attribute_taxonomies'][ $slug ] = array(
            'attribute_id' => $attribute_id,
            'slug'         => $slug,
            'name'         => (string) $args['name'],
        );

        return $attribute_id;
    }
}

if ( ! function_exists( 'get_term_by' ) ) {
    /**
     * Locate a term in the in-memory store.
     *
     * @param string $field    Field to match (name or slug).
     * @param string $value    Value to match.
     * @param string $taxonomy Taxonomy name.
     * @return object|false
     */
    function get_term_by( $field, $value, $taxonomy ) {
        $taxonomy = (string) $taxonomy;
        $value    = (string) $value;

        if ( ! isset( $GLOBALS['softone_terms'][ $taxonomy ] ) ) {
            return false;
        }

        foreach ( $GLOBALS['softone_terms'][ $taxonomy ] as $term ) {
            if ( 'name' === $field && isset( $term['name'] ) && $term['name'] === $value ) {
                return (object) $term;
            }

            if ( 'slug' === $field && isset( $term['slug'] ) && $term['slug'] === $value ) {
                return (object) $term;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'get_term' ) ) {
    /**
     * Retrieve a term by identifier.
     *
     * @param int    $term_id  Term identifier.
     * @param string $taxonomy Taxonomy name.
     * @return object|false
     */
    function get_term( $term_id, $taxonomy ) {
        $taxonomy = (string) $taxonomy;
        $term_id  = (int) $term_id;

        if ( isset( $GLOBALS['softone_terms'][ $taxonomy ][ $term_id ] ) ) {
            return (object) $GLOBALS['softone_terms'][ $taxonomy ][ $term_id ];
        }

        return false;
    }
}

if ( ! function_exists( 'wp_insert_term' ) ) {
    /**
     * Insert a term into the in-memory store.
     *
     * @param string $term     Term name.
     * @param string $taxonomy Taxonomy name.
     * @return array|WP_Error
     */
    function wp_insert_term( $term, $taxonomy ) {
        $taxonomy = (string) $taxonomy;
        $name     = (string) $term;
        $slug     = sanitize_title( $name );

        if ( ! isset( $GLOBALS['softone_terms'][ $taxonomy ] ) ) {
            $GLOBALS['softone_terms'][ $taxonomy ] = array();
        }

        foreach ( $GLOBALS['softone_terms'][ $taxonomy ] as $existing_term ) {
            if ( isset( $existing_term['slug'] ) && $existing_term['slug'] === $slug ) {
                return new WP_Error( 'term_exists', 'Term already exists.', (int) $existing_term['term_id'] );
            }
        }

        $term_id = (int) $GLOBALS['softone_next_term_id'];
        $GLOBALS['softone_next_term_id']++;

        $GLOBALS['softone_terms'][ $taxonomy ][ $term_id ] = array(
            'term_id' => $term_id,
            'name'    => $name,
            'slug'    => $slug,
        );

        return array(
            'term_id'          => $term_id,
            'term_taxonomy_id' => $term_id,
        );
    }
}

if ( ! function_exists( 'wp_update_term' ) ) {
    /**
     * Update a term in the in-memory store.
     *
     * @param int    $term_id  Term identifier.
     * @param string $taxonomy Taxonomy name.
     * @param array  $args     Arguments to update.
     * @return array|WP_Error
     */
    function wp_update_term( $term_id, $taxonomy, array $args ) {
        $taxonomy = (string) $taxonomy;
        $term_id  = (int) $term_id;

        if ( ! isset( $GLOBALS['softone_terms'][ $taxonomy ][ $term_id ] ) ) {
            return new WP_Error( 'term_not_found', 'Term not found.' );
        }

        if ( isset( $args['name'] ) ) {
            $GLOBALS['softone_terms'][ $taxonomy ][ $term_id ]['name'] = (string) $args['name'];
            $GLOBALS['softone_terms'][ $taxonomy ][ $term_id ]['slug'] = sanitize_title( $args['name'] );
        }

        return array(
            'term_id'          => $term_id,
            'term_taxonomy_id' => $term_id,
        );
    }
}

if ( ! function_exists( 'clean_term_cache' ) ) {
    /**
     * Track cache refreshes triggered during tests.
     *
     * @param array<int> $term_ids Term identifiers.
     * @param string     $taxonomy Taxonomy name.
     * @return void
     */
    function clean_term_cache( $term_ids, $taxonomy ) {
        $GLOBALS['softone_clean_term_cache_invocations'][] = array(
            'term_ids' => array_map( 'intval', (array) $term_ids ),
            'taxonomy' => (string) $taxonomy,
        );
    }
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
    /**
     * Track taxonomy assignments for assertions.
     *
     * @param int          $object_id Object identifier.
     * @param array|int    $terms     Term identifiers.
     * @param string       $taxonomy  Taxonomy slug.
     * @return array<int>
     */
    function wp_set_object_terms( $object_id, $terms, $taxonomy ) {
        $terms = (array) $terms;

        $GLOBALS['softone_term_calls'][] = array(
            'product_id' => (int) $object_id,
            'terms'      => array_map( 'intval', $terms ),
            'taxonomy'   => (string) $taxonomy,
        );

        if ( ! isset( $GLOBALS['softone_object_terms'][ $taxonomy ] ) ) {
            $GLOBALS['softone_object_terms'][ $taxonomy ] = array();
        }

        $GLOBALS['softone_object_terms'][ $taxonomy ][ $object_id ] = array_map( 'intval', $terms );

        return array_map( 'intval', $terms );
    }
}

if ( ! function_exists( 'wp_get_object_terms' ) ) {
    /**
     * Retrieve taxonomy assignments from the in-memory store.
     *
     * @param int    $object_id Object identifier.
     * @param string $taxonomy  Taxonomy slug.
     * @param array  $args      Query arguments (unused).
     * @return array<int>
     */
    function wp_get_object_terms( $object_id, $taxonomy, $args = array() ) {
        $taxonomy = (string) $taxonomy;
        $object_id = (int) $object_id;

        if ( isset( $GLOBALS['softone_object_terms'][ $taxonomy ][ $object_id ] ) ) {
            return $GLOBALS['softone_object_terms'][ $taxonomy ][ $object_id ];
        }

        return array();
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    /**
     * Retrieve a value from the in-memory post meta store.
     *
     * @param int    $post_id Post identifier.
     * @param string $key     Meta key.
     * @param bool   $single  Whether a single value is requested.
     * @return mixed
     */
    function get_post_meta( $post_id, $key, $single = false ) {
        if ( isset( $GLOBALS['softone_post_meta'][ $post_id ][ $key ] ) ) {
            return $GLOBALS['softone_post_meta'][ $post_id ][ $key ];
        }

        return '';
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    /**
     * Persist a value in the in-memory post meta store.
     *
     * @param int    $post_id Post identifier.
     * @param string $key     Meta key.
     * @param mixed  $value   Value to store.
     * @return bool
     */
    function update_post_meta( $post_id, $key, $value ) {
        if ( ! isset( $GLOBALS['softone_post_meta'][ $post_id ] ) ) {
            $GLOBALS['softone_post_meta'][ $post_id ] = array();
        }

        $GLOBALS['softone_post_meta'][ $post_id ][ $key ] = $value;

        return true;
    }
}

if ( ! class_exists( 'WC_Product' ) ) {
    /**
     * Minimal WC_Product replacement.
     */
    class WC_Product {
        /**
         * @var int
         */
        protected $id = 0;

        /**
         * @var array<string,mixed>
         */
        protected $data = array(
            'status'         => 'draft',
            'name'           => '',
            'description'    => '',
            'short_desc'     => '',
            'regular_price'  => '',
            'sku'            => '',
            'manage_stock'   => false,
            'stock_quantity' => 0,
            'backorders'     => 'no',
            'stock_status'   => 'instock',
            'category_ids'   => array(),
            'attributes'     => array(),
        );

        /**
         * Save the product to the global store.
         *
         * @return int
         */
        public function save() {
            if ( 0 === $this->id ) {
                $this->id = $GLOBALS['softone_next_product_id'];
                $GLOBALS['softone_next_product_id']++;
            }

            $GLOBALS['softone_products'][ $this->id ] = $this;

            return $this->id;
        }

        /**
         * Retrieve the product identifier.
         *
         * @return int
         */
        public function get_id() {
            return $this->id;
        }

        /**
         * Retrieve the SKU.
         *
         * @return string
         */
        public function get_sku() {
            return (string) $this->data['sku'];
        }

        /**
         * Retrieve the product status.
         *
         * @return string
         */
        public function get_status() {
            return (string) $this->data['status'];
        }

        /**
         * Update the product status.
         *
         * @param string $status New status.
         * @return void
         */
        public function set_status( $status ) {
            $this->data['status'] = (string) $status;
        }

        /**
         * Assign the product name.
         *
         * @param string $name Product name.
         * @return void
         */
        public function set_name( $name ) {
            $this->data['name'] = (string) $name;
        }

        /**
         * Assign the long description.
         *
         * @param string $description Description text.
         * @return void
         */
        public function set_description( $description ) {
            $this->data['description'] = (string) $description;
        }

        /**
         * Assign the short description.
         *
         * @param string $description Short description.
         * @return void
         */
        public function set_short_description( $description ) {
            $this->data['short_desc'] = (string) $description;
        }

        /**
         * Assign the price.
         *
         * @param string $price Price value.
         * @return void
         */
        public function set_regular_price( $price ) {
            $this->data['regular_price'] = (string) $price;
        }

        /**
         * Assign the SKU.
         *
         * @param string $sku SKU value.
         * @return void
         */
        public function set_sku( $sku ) {
            $this->data['sku'] = (string) $sku;
        }

        /**
         * Toggle stock management.
         *
         * @param bool $manage_stock Whether stock management is enabled.
         * @return void
         */
        public function set_manage_stock( $manage_stock ) {
            $this->data['manage_stock'] = (bool) $manage_stock;
        }

        /**
         * Assign the stock quantity.
         *
         * @param int $quantity Stock quantity.
         * @return void
         */
        public function set_stock_quantity( $quantity ) {
            $this->data['stock_quantity'] = (int) $quantity;
        }

        /**
         * Configure backorders.
         *
         * @param string $backorders Backorder mode.
         * @return void
         */
        public function set_backorders( $backorders ) {
            $this->data['backorders'] = (string) $backorders;
        }

        /**
         * Update the stock status.
         *
         * @param string $status Stock status.
         * @return void
         */
        public function set_stock_status( $status ) {
            $this->data['stock_status'] = (string) $status;
        }

        /**
         * Assign product categories.
         *
         * @param array<int> $category_ids Category identifiers.
         * @return void
         */
        public function set_category_ids( $category_ids ) {
            $this->data['category_ids'] = array_map( 'intval', (array) $category_ids );
        }

        /**
         * Assign product attributes.
         *
         * @param array<int|string,mixed> $attributes Attributes.
         * @return void
         */
        public function set_attributes( $attributes ) {
            $this->data['attributes'] = $attributes;
        }

        /**
         * Retrieve assigned attributes.
         *
         * @return array<int|string,mixed>
         */
        public function get_attributes() {
            return $this->data['attributes'];
        }
    }
}

if ( ! class_exists( 'WC_Product_Attribute' ) ) {
    /**
     * Minimal WC_Product_Attribute replacement for tests.
     */
    class WC_Product_Attribute {
        /**
         * @var int
         */
        protected $id = 0;

        /**
         * @var string
         */
        protected $name = '';

        /**
         * @var array<int>
         */
        protected $options = array();

        /**
         * @var int
         */
        protected $position = 0;

        /**
         * @var bool
         */
        protected $visible = false;

        /**
         * @var bool
         */
        protected $variation = false;

        /**
         * Assign the attribute identifier.
         *
         * @param int $id Attribute identifier.
         * @return void
         */
        public function set_id( $id ) {
            $this->id = (int) $id;
        }

        /**
         * Set the taxonomy name.
         *
         * @param string $name Taxonomy name.
         * @return void
         */
        public function set_name( $name ) {
            $this->name = (string) $name;
        }

        /**
         * Assign attribute term options.
         *
         * @param array<int> $options Term identifiers.
         * @return void
         */
        public function set_options( array $options ) {
            $this->options = array_map( 'intval', $options );
        }

        /**
         * Update the attribute position.
         *
         * @param int $position Attribute position.
         * @return void
         */
        public function set_position( $position ) {
            $this->position = (int) $position;
        }

        /**
         * Toggle visibility.
         *
         * @param bool $visible Whether visible.
         * @return void
         */
        public function set_visible( $visible ) {
            $this->visible = (bool) $visible;
        }

        /**
         * Toggle variation usage.
         *
         * @param bool $variation Whether used for variations.
         * @return void
         */
        public function set_variation( $variation ) {
            $this->variation = (bool) $variation;
        }

        /**
         * Retrieve the configured options.
         *
         * @return array<int>
         */
        public function get_options() {
            return $this->options;
        }

        /**
         * Determine whether the attribute is used for variations.
         *
         * @return bool
         */
        public function get_variation() {
            return $this->variation;
        }
    }
}

if ( ! class_exists( 'WC_Product_Simple' ) ) {
    /**
     * Simple product stub.
     */
    class WC_Product_Simple extends WC_Product {
    }
}

if ( ! function_exists( 'wc_get_product' ) ) {
    /**
     * Retrieve a product from the global store.
     *
     * @param int $product_id Product identifier.
     * @return WC_Product|null
     */
    function wc_get_product( $product_id ) {
        if ( isset( $GLOBALS['softone_products'][ $product_id ] ) ) {
            return $GLOBALS['softone_products'][ $product_id ];
        }

        return null;
    }
}

require_once dirname( __DIR__ ) . '/includes/class-softone-item-sync.php';

/**
 * Test double exposing protected helpers.
 */
class Softone_Item_Sync_Test_Double extends Softone_Item_Sync {
    /**
     * Expose import_row() for testing.
     *
     * @param array $data         Row data.
     * @param int   $run_timestamp Run timestamp.
     * @return string
     */
    public function import_row_public( array $data, $run_timestamp ) {
        return $this->import_row( $data, $run_timestamp );
    }

    /**
     * Forcefully toggle the taxonomy refresh flag for direct testing.
     *
     * @param bool $value Flag value.
     * @return void
     */
    public function set_force_taxonomy_refresh( $value ) {
        $this->force_taxonomy_refresh = (bool) $value;
    }

    /**
     * Avoid actual taxonomy lookups in tests.
     *
     * @param array $data Row data.
     * @return int[]
     */
    protected function prepare_category_ids( array $data ) {
        if ( isset( $data['category_ids'] ) ) {
            return array_map( 'intval', (array) $data['category_ids'] );
        }

        return parent::prepare_category_ids( $data );
    }

    /**
     * Simplify attribute handling during tests.
     *
     * @param array       $data    Row data.
     * @param WC_Product  $product Product instance.
     * @return array<string,array>
     */
    protected function prepare_attribute_assignments( array $data, $product, array $fallback_attributes = array() ) {
        return array(
            'attributes' => array(),
            'terms'      => array(),
            'clear'      => array(),
        );
    }

    /**
     * Prevent brand assignment side effects.
     *
     * @param int    $product_id Product identifier.
     * @param string $brand_value Brand value.
     * @return void
     */
    protected function assign_brand_term( $product_id, $brand_value ) {
    }

    /**
     * Route lookups through the in-memory stores.
     *
     * @param string $sku  SKU value.
     * @param string $mtrl Material identifier.
     * @return int
     */
    protected function find_existing_product( $sku, $mtrl ) {
        foreach ( $GLOBALS['softone_post_meta'] as $post_id => $meta ) {
            if ( '' !== $mtrl && isset( $meta[ self::META_MTRL ] ) && (string) $meta[ self::META_MTRL ] === (string) $mtrl ) {
                return (int) $post_id;
            }
        }

        if ( '' !== $sku ) {
            foreach ( $GLOBALS['softone_products'] as $post_id => $product ) {
                if ( $product instanceof WC_Product && (string) $product->get_sku() === (string) $sku ) {
                    return (int) $post_id;
                }
            }
        }

        return 0;
    }

    /**
     * Silence logging within tests.
     *
     * @param string $level   Log level.
     * @param string $message Message text.
     * @param array  $context Context values.
     * @return void
     */
    protected function log( $level, $message, array $context = array() ) {
    }
}

/**
 * Test double exposing attribute assignment helper.
 */
class Softone_Item_Sync_Attribute_Test_Double extends Softone_Item_Sync {
    /**
     * Expose prepare_attribute_assignments() for testing.
     *
     * @param array      $data                Row data.
     * @param WC_Product $product             Product instance.
     * @param array      $fallback_attributes Optional fallback attribute values.
     * @return array
     */
    public function prepare_attribute_assignments_public( array $data, $product, array $fallback_attributes = array() ) {
        return $this->prepare_attribute_assignments( $data, $product, $fallback_attributes );
    }

    /**
     * Silence logging.
     *
     * @param string $level   Log level.
     * @param string $message Message text.
     * @param array  $context Context data.
     * @return void
     */
    protected function log( $level, $message, array $context = array() ) {
    }

    /**
     * Avoid brand term assignment side effects.
     *
     * @param int    $product_id Product identifier.
     * @param string $brand_value Brand value.
     * @return void
     */
    protected function assign_brand_term( $product_id, $brand_value ) {
    }
}

$sync = new Softone_Item_Sync_Test_Double();

$row = array(
    'mtrl'         => 'MTRL-100',
    'sku'          => 'SKU-100',
    'desc'         => 'Example product',
    'category_ids' => array( 11, 22 ),
);

$result = $sync->import_row_public( $row, time() );
if ( 'created' !== $result ) {
    throw new RuntimeException( 'Expected the first import to create a product.' );
}

if ( count( $GLOBALS['softone_term_calls'] ) !== 1 ) {
    throw new RuntimeException( 'Expected a single taxonomy assignment during the initial import.' );
}

$GLOBALS['softone_term_calls'] = array();

$result = $sync->import_row_public( $row, time() + 5 );
if ( 'skipped' !== $result ) {
    throw new RuntimeException( 'Expected the unchanged payload to be skipped without forcing refresh.' );
}

if ( ! empty( $GLOBALS['softone_term_calls'] ) ) {
    throw new RuntimeException( 'Unexpected taxonomy assignment when the payload hash matched.' );
}

$GLOBALS['softone_term_calls'] = array();
$sync->set_force_taxonomy_refresh( true );

$result = $sync->import_row_public( $row, time() + 10 );
if ( 'updated' !== $result ) {
    throw new RuntimeException( 'Expected the forced refresh to process the product update.' );
}

if ( count( $GLOBALS['softone_term_calls'] ) !== 1 ) {
    throw new RuntimeException( 'Expected taxonomy assignment during the forced refresh.' );
}

$assignment = $GLOBALS['softone_term_calls'][0];
if ( $assignment['taxonomy'] !== 'product_cat' ) {
    throw new RuntimeException( 'Expected product_cat taxonomy assignments during forced refresh.' );
}

if ( $assignment['terms'] !== array( 11, 22 ) ) {
    throw new RuntimeException( 'Forced refresh should reapply the stored category IDs.' );
}

$GLOBALS['softone_attribute_taxonomies']         = array();
$GLOBALS['softone_next_attribute_taxonomy_id']   = 1;
$GLOBALS['softone_terms']                        = array();
$GLOBALS['softone_next_term_id']                 = 1;
$GLOBALS['softone_clean_term_cache_invocations'] = array();

$attribute_sync = new Softone_Item_Sync_Attribute_Test_Double();

$colour_slug     = 'colour';
$colour_taxonomy = wc_attribute_taxonomy_name( $colour_slug );
$attribute_id    = wc_create_attribute(
    array(
        'slug' => $colour_slug,
        'name' => 'Colour',
    )
);

if ( is_wp_error( $attribute_id ) ) {
    throw new RuntimeException( 'Failed to register the colour attribute taxonomy for testing.' );
}

$seed_term = wp_insert_term( 'Blue ', $colour_taxonomy );
if ( is_wp_error( $seed_term ) ) {
    throw new RuntimeException( 'Failed to seed the colour attribute term.' );
}

$expected_term_id = (int) $seed_term['term_id'];

$product = new WC_Product_Simple();
$product->set_attributes( array() );

$assignments = $attribute_sync->prepare_attribute_assignments_public(
    array(
        'colour_name' => ' blue ',
    ),
    $product
);

if ( ! isset( $assignments['terms'][ $colour_taxonomy ] ) ) {
    throw new RuntimeException( 'Colour attribute terms should be scheduled for assignment.' );
}

$assigned_terms = $assignments['terms'][ $colour_taxonomy ];
if ( $assigned_terms !== array( $expected_term_id ) ) {
    throw new RuntimeException( 'Expected the normalised colour value to reuse the existing term identifier.' );
}

if ( ! isset( $assignments['attributes'][ $colour_taxonomy ] ) ) {
    throw new RuntimeException( 'Colour attribute metadata should be prepared for the product.' );
}

$attribute_object = $assignments['attributes'][ $colour_taxonomy ];
if ( ! $attribute_object instanceof WC_Product_Attribute ) {
    throw new RuntimeException( 'Expected a WC_Product_Attribute instance for the colour taxonomy.' );
}

if ( $attribute_object->get_options() !== array( $expected_term_id ) ) {
    throw new RuntimeException( 'Prepared attribute options should include the reused colour term identifier.' );
}

if ( ! isset( $assignments['variation_taxonomies'][ $colour_taxonomy ] ) ) {
    throw new RuntimeException( 'Colour attributes should be marked for variation handling.' );
}

if ( ! $attribute_object->get_variation() ) {
    throw new RuntimeException( 'Colour attribute metadata should be flagged as a variation attribute.' );
}

$term = get_term( $expected_term_id, $colour_taxonomy );
if ( ! $term || is_wp_error( $term ) ) {
    throw new RuntimeException( 'Failed to retrieve the colour term after ensuring it exists.' );
}

if ( 'Blue' !== $term->name ) {
    throw new RuntimeException( 'The colour term name should be updated to match the normalised value.' );
}

$cache_refreshed = false;
foreach ( $GLOBALS['softone_clean_term_cache_invocations'] as $invocation ) {
    if ( $invocation['taxonomy'] === $colour_taxonomy && in_array( $expected_term_id, $invocation['term_ids'], true ) ) {
        $cache_refreshed = true;
        break;
    }
}

if ( ! $cache_refreshed ) {
    throw new RuntimeException( 'Expected the attribute term cache to be refreshed when reusing an existing term.' );
}

$alias_product = new WC_Product_Simple();
$alias_product->set_attributes( array() );

$alias_assignments = $attribute_sync->prepare_attribute_assignments_public(
    array(
        'color' => ' blue ',
    ),
    $alias_product
);

if ( ! isset( $alias_assignments['terms'][ $colour_taxonomy ] ) ) {
    throw new RuntimeException( 'Colour attribute terms should be prepared when using the `color` key alias.' );
}

$alias_term_ids = $alias_assignments['terms'][ $colour_taxonomy ];
if ( $alias_term_ids !== array( $expected_term_id ) ) {
    throw new RuntimeException( 'Expected the colour alias to reuse the existing term identifier.' );
}

if ( ! isset( $alias_assignments['attributes'][ $colour_taxonomy ] ) ) {
    throw new RuntimeException( 'Colour attribute metadata should be prepared when using the alias key.' );
}

$alias_attribute = $alias_assignments['attributes'][ $colour_taxonomy ];
if ( ! $alias_attribute instanceof WC_Product_Attribute ) {
    throw new RuntimeException( 'Expected a WC_Product_Attribute instance for the colour alias assignment.' );
}

if ( ! isset( $alias_assignments['variation_taxonomies'][ $colour_taxonomy ] ) ) {
    throw new RuntimeException( 'Colour alias assignments should be marked for variation handling.' );
}

if ( ! $alias_attribute->get_variation() ) {
    throw new RuntimeException( 'Colour alias metadata should be flagged as a variation attribute.' );
}

if ( $alias_attribute->get_options() !== array( $expected_term_id ) ) {
    throw new RuntimeException( 'Colour alias attribute options should contain the reused term identifier.' );
}

$group_attribute_sync = new Softone_Item_Sync_Attribute_Test_Double();

$group_product = new WC_Product_Simple();
$group_product->set_attributes( array() );

$group_assignments = $group_attribute_sync->prepare_attribute_assignments_public(
    array(
        '__group_variations' => array(
            array(
                'colour_label' => 'Teal',
            ),
            array(
                'colour_label' => 'Scarlet',
            ),
        ),
    ),
    $group_product
);

if ( ! isset( $group_assignments['variation_taxonomies'][ $colour_taxonomy ] ) ) {
    throw new RuntimeException( 'Group variation colour labels should trigger variation taxonomy assignment.' );
}

if ( ! isset( $group_assignments['attributes'][ $colour_taxonomy ] ) ) {
    throw new RuntimeException( 'Expected group variations to prepare colour attribute metadata.' );
}

$group_attribute = $group_assignments['attributes'][ $colour_taxonomy ];
if ( ! $group_attribute instanceof WC_Product_Attribute ) {
    throw new RuntimeException( 'Group variation attributes should use WC_Product_Attribute metadata.' );
}

if ( ! $group_attribute->get_variation() ) {
    throw new RuntimeException( 'Group variation colour metadata should be flagged for variation handling.' );
}

if ( ! isset( $group_assignments['terms'][ $colour_taxonomy ] ) ) {
    throw new RuntimeException( 'Group variations should schedule colour term assignments.' );
}

$group_term_ids = $group_assignments['terms'][ $colour_taxonomy ];
if ( count( $group_term_ids ) !== 2 ) {
    throw new RuntimeException( 'Expected both group colour labels to register attribute terms.' );
}

$group_term_names = array();
foreach ( $group_term_ids as $term_id ) {
    $term = get_term( $term_id, $colour_taxonomy );
    if ( ! $term || is_wp_error( $term ) ) {
        throw new RuntimeException( 'Failed to load the colour term created for group variations.' );
    }
    $group_term_names[] = $term->name;
}

sort( $group_term_names );
if ( $group_term_names !== array( 'Scarlet', 'Teal' ) ) {
    throw new RuntimeException( 'Group variation colour labels should be normalised into attribute terms.' );
}

if ( ! isset( $group_assignments['values'][ $colour_taxonomy ] ) ) {
    throw new RuntimeException( 'Group variation attributes should include a comma-separated value summary.' );
}

if ( $group_assignments['values'][ $colour_taxonomy ] !== 'Teal, Scarlet' ) {
    throw new RuntimeException( 'Group variation attribute values should preserve the original label order.' );
}

if ( ! isset( $group_assignments['term_slugs'][ $colour_taxonomy ]['Teal'] ) || ! isset( $group_assignments['term_slugs'][ $colour_taxonomy ]['Scarlet'] ) ) {
    throw new RuntimeException( 'Group variation term slugs should be recorded for both colours.' );
}

echo "Taxonomy refresh regression test passed." . PHP_EOL;
echo "Attribute term normalisation regression test passed." . PHP_EOL;
echo "Group variation attribute fallback regression test passed." . PHP_EOL;
