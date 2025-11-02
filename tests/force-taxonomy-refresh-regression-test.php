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

if ( ! function_exists( 'is_wp_error' ) ) {
    /**
     * Minimal WP_Error detection shim.
     *
     * @param mixed $thing Value to test.
     * @return bool
     */
    function is_wp_error( $thing ) {
        return false;
    }
}

$GLOBALS['softone_products']            = array();
$GLOBALS['softone_next_product_id']      = 1;
$GLOBALS['softone_post_meta']            = array();
$GLOBALS['softone_term_calls']           = array();
$GLOBALS['softone_object_terms']         = array();

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
    protected function prepare_attribute_assignments( array $data, $product ) {
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

echo "Taxonomy refresh regression test passed." . PHP_EOL;
