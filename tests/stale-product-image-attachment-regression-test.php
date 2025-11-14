<?php
/**
 * Regression test covering stale product image attachment safeguards.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

set_error_handler(
    static function ( $errno, $errstr ) {
        if ( E_NOTICE === $errno || E_WARNING === $errno ) {
            throw new Exception( $errstr );
        }

        return false;
    }
);

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
     * Minimal filter shim returning the default value.
     *
     * @param string $tag   Filter name.
     * @param mixed  $value Default value.
     * @return mixed
     */
    function apply_filters( $tag, $value ) {
        return $value;
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    /**
     * Retrieve a value from the in-memory post meta store.
     *
     * @param int    $post_id Post identifier.
     * @param string $key     Meta key.
     * @param bool   $single  Whether to return a single value.
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

if ( ! function_exists( 'wp_reset_postdata' ) ) {
    /**
     * Reset postdata shim for the regression harness.
     *
     * @return void
     */
    function wp_reset_postdata() {}
}

if ( ! function_exists( 'wc_get_product' ) ) {
    /**
     * Retrieve a test double for the WooCommerce product.
     *
     * @param int $product_id Product identifier.
     * @return object|null
     */
    function wc_get_product( $product_id ) {
        if ( isset( $GLOBALS['softone_products'][ $product_id ] ) ) {
            return $GLOBALS['softone_products'][ $product_id ];
        }

        return null;
    }
}

if ( ! class_exists( 'WP_Query' ) ) {
    /**
     * Query stub returning predetermined batches of posts.
     */
    class WP_Query {

        /**
         * @var array<int>
         */
        public $posts = array();

        /**
         * @param array<string,mixed> $args
         */
        public function __construct( $args ) {
            if ( empty( $GLOBALS['softone_wp_query_batches'] ) ) {
                $this->posts = array();
                return;
            }

            $batch = array_shift( $GLOBALS['softone_wp_query_batches'] );
            if ( null === $batch ) {
                $batch = array();
            }

            $this->posts = (array) $batch;
        }

        /**
         * @return bool
         */
        public function have_posts() {
            return ! empty( $this->posts );
        }
    }
}

if ( ! class_exists( 'Softone_API_Client' ) ) {
    /**
     * Lightweight API client stub for tests.
     */
    class Softone_API_Client {}
}

require_once __DIR__ . '/../includes/class-softone-item-sync.php';

class Softone_Item_Stale_Handler_Test_Logger {

    /**
     * @var array<int,array{level:string,message:string,context:array}>
     */
    public $logs = array();

    /**
     * @param string $level
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function log( $level, $message, array $context = array() ) {
        $this->logs[] = array(
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        );
    }
}

if ( ! class_exists( 'Softone_Sku_Image_Attacher' ) ) {
    /**
     * Captures attachment attempts during stale processing.
     */
    class Softone_Sku_Image_Attacher {

        /**
         * @var array<int,array{product_id:int,sku:string}>
         */
        protected static $calls = array();

        /**
         * @param int    $product_id Product identifier.
         * @param string $sku        Product SKU.
         * @return void
         */
        public static function attach_gallery_from_sku( $product_id, $sku ) {
            self::$calls[] = array(
                'product_id' => (int) $product_id,
                'sku'        => (string) $sku,
            );
        }

        /**
         * Reset captured calls.
         *
         * @return void
         */
        public static function reset() {
            self::$calls = array();
        }

        /**
         * Retrieve captured calls for assertions.
         *
         * @return array<int,array{product_id:int,sku:string}>
         */
        public static function get_calls() {
            return self::$calls;
        }
    }
}

/**
 * Simple WooCommerce product stub used by the regression harness.
 */
class Softone_Test_Product {

    /**
     * @var int
     */
    protected $id;

    /**
     * @var string
     */
    protected $sku;

    /**
     * @var string
     */
    protected $status;

    /**
     * @var string
     */
    protected $stock_status;

    /**
     * @var int
     */
    public $save_count = 0;

    /**
     * @param int    $id  Product identifier.
     * @param string $sku Product SKU.
     */
    public function __construct( $id, $sku ) {
        $this->id           = (int) $id;
        $this->sku          = (string) $sku;
        $this->status       = 'publish';
        $this->stock_status = 'instock';
    }

    /**
     * @return string
     */
    public function get_status() {
        return $this->status;
    }

    /**
     * @param string $status
     * @return void
     */
    public function set_status( $status ) {
        $this->status = (string) $status;
    }

    /**
     * @param string $status
     * @return void
     */
    public function set_stock_status( $status ) {
        $this->stock_status = (string) $status;
    }

    /**
     * @return string
     */
    public function get_stock_status() {
        return $this->stock_status;
    }

    /**
     * @return void
     */
    public function save() {
        $this->save_count++;
    }

    /**
     * @return string
     */
    public function get_sku() {
        return $this->sku;
    }
}

try {
    $logger  = new Softone_Item_Stale_Handler_Test_Logger();
    $handler = new Softone_Item_Stale_Handler( $logger );

    // Scenario: product with SKU triggers attachment.
    $product_id_with_sku = 501;
    $product_with_sku    = new Softone_Test_Product( $product_id_with_sku, 'STALE-SKU-501' );

    $GLOBALS['softone_products']        = array( $product_id_with_sku => $product_with_sku );
    $GLOBALS['softone_post_meta']       = array();
    $GLOBALS['softone_wp_query_batches'] = array(
        array( $product_id_with_sku ),
        array(),
    );

    Softone_Sku_Image_Attacher::reset();

    $processed_with_sku = $handler->handle( 123456 );

    if ( 1 !== $processed_with_sku ) {
        throw new RuntimeException( 'Expected exactly one stale product to be processed for SKU scenario.' );
    }

    $attachment_calls = Softone_Sku_Image_Attacher::get_calls();
    if ( 1 !== count( $attachment_calls ) ) {
        throw new RuntimeException( 'Attachment helper was not invoked exactly once when SKU was present.' );
    }

    $first_call = $attachment_calls[0];
    if ( $first_call['product_id'] !== $product_id_with_sku ) {
        throw new RuntimeException( 'Attachment helper received an unexpected product identifier.' );
    }

    if ( $first_call['sku'] !== 'STALE-SKU-501' ) {
        throw new RuntimeException( 'Attachment helper received an unexpected SKU value.' );
    }

    if ( 'outofstock' !== $product_with_sku->get_stock_status() ) {
        throw new RuntimeException( 'Stale processing did not set the stock status to out of stock.' );
    }

    if ( 1 !== $product_with_sku->save_count ) {
        throw new RuntimeException( 'Product save count did not match expectation for SKU scenario.' );
    }

    if ( ! isset( $GLOBALS['softone_post_meta'][ $product_id_with_sku ][ Softone_Item_Sync::META_LAST_SYNC ] ) ) {
        throw new RuntimeException( 'Last sync timestamp was not recorded for the product with SKU.' );
    }

    if ( 123456 !== (int) $GLOBALS['softone_post_meta'][ $product_id_with_sku ][ Softone_Item_Sync::META_LAST_SYNC ] ) {
        throw new RuntimeException( 'Recorded last sync timestamp did not match the provided value.' );
    }

    // Scenario: product without SKU does not trigger attachment helper.
    $product_id_without_sku = 777;
    $product_without_sku    = new Softone_Test_Product( $product_id_without_sku, '' );

    $GLOBALS['softone_products']        = array( $product_id_without_sku => $product_without_sku );
    $GLOBALS['softone_post_meta']       = array();
    $GLOBALS['softone_wp_query_batches'] = array(
        array( $product_id_without_sku ),
        array(),
    );

    Softone_Sku_Image_Attacher::reset();

    $processed_without_sku = $handler->handle( 654321 );

    if ( 1 !== $processed_without_sku ) {
        throw new RuntimeException( 'Expected exactly one stale product to be processed for no-SKU scenario.' );
    }

    $attachment_calls_without_sku = Softone_Sku_Image_Attacher::get_calls();
    if ( ! empty( $attachment_calls_without_sku ) ) {
        throw new RuntimeException( 'Attachment helper should not be invoked when the product SKU is empty.' );
    }

    if ( 1 !== $product_without_sku->save_count ) {
        throw new RuntimeException( 'Product save count did not match expectation for empty SKU scenario.' );
    }

    if ( 654321 !== (int) $GLOBALS['softone_post_meta'][ $product_id_without_sku ][ Softone_Item_Sync::META_LAST_SYNC ] ) {
        throw new RuntimeException( 'Recorded last sync timestamp did not match for the product without SKU.' );
    }
} catch ( Throwable $throwable ) {
    restore_error_handler();
    fwrite( STDERR, 'Stale product image attachment regression failed: ' . $throwable->getMessage() . "\n" );
    exit( 1 );
}

restore_error_handler();
echo "Stale product image attachment regression passed.\n";
exit( 0 );
