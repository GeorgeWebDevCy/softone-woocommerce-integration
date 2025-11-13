<?php
/**
 * Regression test covering related item pointer prioritisation.
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

if ( ! function_exists( 'delete_post_meta' ) ) {
    /**
     * Remove a value from the in-memory post meta store.
     *
     * @param int    $post_id Post identifier.
     * @param string $key     Meta key.
     * @return bool
     */
    function delete_post_meta( $post_id, $key ) {
        if ( isset( $GLOBALS['softone_post_meta'][ $post_id ][ $key ] ) ) {
            unset( $GLOBALS['softone_post_meta'][ $post_id ][ $key ] );

            if ( empty( $GLOBALS['softone_post_meta'][ $post_id ] ) ) {
                unset( $GLOBALS['softone_post_meta'][ $post_id ] );
            }
        }

        return true;
    }
}

if ( ! class_exists( 'Softone_API_Client' ) ) {
    /**
     * Lightweight API client stub for tests.
     */
    class Softone_API_Client {
    }
}

require_once __DIR__ . '/../includes/class-softone-item-sync.php';

/**
 * Test double exposing related item helper methods.
 */
class Softone_Item_Sync_Related_Item_Test_Double extends Softone_Item_Sync {

    /**
     * Map Softone material identifiers to product IDs.
     *
     * @var array<string,int>
     */
    public $product_map = array();

    /**
     * Map parent Softone materials to related child materials.
     *
     * @var array<string,array<int,string>>
     */
    public $child_map = array();

    /**
     * Captured colour variation queue requests.
     *
     * @var array<int,array<string,mixed>>
     */
    protected $queued_requests = array();

    /**
     * Recorded colour variation sync payloads.
     *
     * @var array<int,array<string,mixed>>
     */
    protected $processed_variations = array();

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( new Softone_API_Client(), null, null, null );
    }

    /**
     * Public wrapper around the protected related item sync helper.
     *
     * @param int               $product_id
     * @param string            $mtrl
     * @param string            $related_item_mtrl
     * @param array<int,string> $related_item_mtrls
     * @param bool              $received_related_payload
     * @return void
     */
    public function sync_relationships_public( $product_id, $mtrl, $related_item_mtrl, array $related_item_mtrls, $received_related_payload ) {
        $this->sync_related_item_relationships( $product_id, $mtrl, $related_item_mtrl, $related_item_mtrls, $received_related_payload );
    }

    /**
     * Public wrapper around the protected colour variation queue helper.
     *
     * @param int               $product_id
     * @param string            $mtrl
     * @param array<int,string> $related_item_mtrls
     * @param string            $colour_taxonomy
     * @return void
     */
    public function queue_colour_variation_sync_public( $product_id, $mtrl, array $related_item_mtrls, $colour_taxonomy ) {
        $this->queue_colour_variation_sync( $product_id, $mtrl, $related_item_mtrls, $colour_taxonomy );
    }

    /**
     * Retrieve captured colour variation queue requests.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_queued_requests() {
        return $this->queued_requests;
    }

    /**
     * Retrieve processed colour variation sync payloads.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_processed_variations() {
        return $this->processed_variations;
    }

    /**
     * Process queued colour variation sync requests.
     *
     * @return void
     */
    public function process_pending_colour_variation_syncs_public() {
        parent::process_pending_colour_variation_syncs();
    }

    /**
     * Locate a product ID for the supplied material identifier.
     *
     * @param string $mtrl Material identifier.
     * @return int
     */
    protected function find_product_id_by_mtrl( $mtrl ) {
        $mtrl = (string) $mtrl;

        if ( isset( $this->product_map[ $mtrl ] ) ) {
            return (int) $this->product_map[ $mtrl ];
        }

        return 0;
    }

    /**
     * Retrieve related child materials for a parent material.
     *
     * @param string $parent_mtrl Parent material identifier.
     * @return array<int,string>
     */
    protected function find_child_mtrls_for_parent( $parent_mtrl ) {
        $parent_mtrl = (string) $parent_mtrl;

        if ( isset( $this->child_map[ $parent_mtrl ] ) ) {
            return $this->child_map[ $parent_mtrl ];
        }

        return array();
    }

    /**
     * Refresh related children without touching WooCommerce internals.
     *
     * @param string $parent_mtrl Parent material identifier.
     * @return void
     */
    protected function refresh_related_item_children( $parent_mtrl ) {
    }

    /**
     * Capture queued colour variation sync requests with sanitised payloads.
     *
     * @param int               $product_id
     * @param string            $mtrl
     * @param array<int,string> $related_item_mtrls
     * @param string            $colour_taxonomy
     * @return void
     */
    protected function queue_colour_variation_sync( $product_id, $mtrl, array $related_item_mtrls, $colour_taxonomy ) {
        $previous_queue_size = count( $this->pending_colour_variation_syncs );

        parent::queue_colour_variation_sync( $product_id, $mtrl, $related_item_mtrls, $colour_taxonomy );

        $sanitised_payload = array();
        if ( isset( $this->pending_colour_variation_syncs[ $previous_queue_size ] ) ) {
            $entry = $this->pending_colour_variation_syncs[ $previous_queue_size ];
            if ( isset( $entry['related_item_mtrls'] ) && is_array( $entry['related_item_mtrls'] ) ) {
                $sanitised_payload = array_values( array_map( 'strval', $entry['related_item_mtrls'] ) );
            }
        }

        $this->queued_requests[] = array(
            'product_id'         => (int) $product_id,
            'mtrl'               => (string) $mtrl,
            'related_item_mtrls' => $sanitised_payload,
            'colour_taxonomy'    => (string) $colour_taxonomy,
        );
    }

    /**
     * Record colour variation sync payloads without WooCommerce dependencies.
     *
     * @param int               $product_id
     * @param string            $current_mtrl
     * @param array<int,string> $related_item_mtrls
     * @param string            $colour_taxonomy
     * @return void
     */
    protected function sync_related_colour_variations( $product_id, $current_mtrl, array $related_item_mtrls, $colour_taxonomy ) {
        $this->processed_variations[] = array(
            'product_id'         => (int) $product_id,
            'current_mtrl'       => (string) $current_mtrl,
            'related_item_mtrls' => array_values( array_map( 'strval', $related_item_mtrls ) ),
            'colour_taxonomy'    => (string) $colour_taxonomy,
        );
    }

    /**
     * Silence logging during the regression test.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Context data.
     * @return void
     */
    protected function log( $level, $message, array $context = array() ) {
    }
}

$child_product_id  = 501;
$parent_product_id = 301;
$sibling_product_id = 502;
$descendant_product_id = 601;

$sync = new Softone_Item_Sync_Related_Item_Test_Double();
$sync->product_map = array(
    'PARENT-001'    => $parent_product_id,
    'CHILD-RED'     => $child_product_id,
    'CHILD-BLUE'    => $sibling_product_id,
    'CHILD-RED-V1'  => $descendant_product_id,
);
$sync->child_map = array(
    'PARENT-001' => array( 'CHILD-RED', 'CHILD-BLUE' ),
    'CHILD-RED'  => array( 'CHILD-RED-V1' ),
);

$sync->sync_relationships_public(
    $child_product_id,
    'CHILD-RED',
    'PARENT-001',
    array( 'CHILD-BLUE', 'PARENT-001', 'CHILD-RED' ),
    true
);

$stored_parent_pointer = get_post_meta( $child_product_id, Softone_Item_Sync::META_RELATED_ITEM_MTRL, true );
if ( $stored_parent_pointer !== 'PARENT-001' ) {
    throw new RuntimeException( 'Expected the dedicated related_item_mtrl pointer to take precedence.' );
}

$stored_related_list = get_post_meta( $child_product_id, Softone_Item_Sync::META_RELATED_ITEM_MTRLS, true );
if ( ! is_array( $stored_related_list ) ) {
    throw new RuntimeException( 'Expected the related item material list to persist as an array.' );
}

if ( in_array( 'CHILD-RED', $stored_related_list, true ) ) {
    throw new RuntimeException( 'Related item material list should not include the current product material.' );
}

$expected_related_meta = array( 'CHILD-BLUE', 'PARENT-001' );
if ( $stored_related_list !== $expected_related_meta ) {
    throw new RuntimeException( 'Related item material list should retain sanitised payload ordering.' );
}

$sync->queue_colour_variation_sync_public( $child_product_id, 'CHILD-RED', array(), 'pa_colour' );

$queued_requests = $sync->get_queued_requests();
if ( count( $queued_requests ) !== 1 ) {
    throw new RuntimeException( 'Expected a single colour variation sync request to be queued.' );
}

$queued_payload = $queued_requests[0];
$expected_queue_mtrls = array( 'CHILD-BLUE', 'PARENT-001', 'CHILD-RED', 'CHILD-RED-V1' );
if ( $queued_payload['related_item_mtrls'] !== $expected_queue_mtrls ) {
    throw new RuntimeException( 'Colour variation sync should contain the parent and all related child materials.' );
}

$sync->process_pending_colour_variation_syncs_public();

$processed_variations = $sync->get_processed_variations();
if ( count( $processed_variations ) !== 1 ) {
    throw new RuntimeException( 'Expected queued colour variation syncs to process exactly once.' );
}

$processed_payload = $processed_variations[0];
if ( $processed_payload['related_item_mtrls'] !== $expected_queue_mtrls ) {
    throw new RuntimeException( 'Processed colour variation sync should propagate the complete related material list.' );
}

if ( $processed_payload['current_mtrl'] !== 'CHILD-RED' ) {
    throw new RuntimeException( 'Processed colour variation sync should retain the current product material identifier.' );
}

if ( $processed_payload['product_id'] !== $child_product_id ) {
    throw new RuntimeException( 'Processed colour variation sync should reference the current product identifier.' );
}

echo 'Related item pointer regression test passed.' . PHP_EOL;
