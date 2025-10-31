<?php
/**
 * Regression check for repeated page detection in Softone_Item_Sync.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        if ( 'softone_wc_integration_item_sync_page_size' === $tag ) {
            return 2;
        }

        return $value;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}

if ( ! class_exists( 'Softone_API_Client' ) ) {
    class Softone_API_Client {
        /**
         * @var array<int, array<int, array<string, mixed>>>
         */
        protected $pages = array();

        /**
         * @param array<int, array<int, array<string, mixed>>> $pages Paged responses keyed by page number.
         */
        public function __construct( array $pages = array() ) {
            $this->pages = $pages;
        }

        /**
         * Simulate the SoftOne SqlData API response.
         *
         * @param string $endpoint Endpoint name.
         * @param array  $data     Request data.
         * @param array  $extra    Extra data including the page number.
         *
         * @return array
         */
        public function sql_data( $endpoint, array $data, array $extra ) {
            $page = isset( $extra['pPage'] ) ? (int) $extra['pPage'] : 1;

            if ( isset( $this->pages[ $page ] ) ) {
                return array( 'rows' => $this->pages[ $page ] );
            }

            return array( 'rows' => array() );
        }
    }
}

class Softone_Item_Sync_Logger {
    /**
     * @var array<int, array{level:string,message:string,context:array}>
     */
    public $entries = array();

    /**
     * Record a log entry.
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    public function log( $level, $message, array $context = array() ) {
        $this->entries[] = array(
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        );
    }
}

require_once dirname( __DIR__ ) . '/includes/class-softone-item-sync.php';

class Softone_Item_Sync_Test extends Softone_Item_Sync {
    /**
     * @var array<int, string>
     */
    public $hashes = array();

    /**
     * Expose the generator for testing.
     *
     * @param array $extra Extra request data.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function yield_rows_public( array $extra ) {
        return $this->yield_item_rows( $extra );
    }

    /**
     * Capture the hash values used for pagination checks.
     *
     * @param array<int|string, mixed> $rows Rows returned from the API.
     *
     * @return string
     */
    protected function hash_item_rows( array $rows ) {
        $hash = parent::hash_item_rows( $rows );
        $this->hashes[] = $hash;

        return $hash;
    }
}

$pages = array(
    1 => array(
        array( 'id' => 1, 'name' => 'Test Product' ),
        array( 'id' => 2, 'name' => 'Another Product' ),
    ),
    2 => array(
        array( 'id' => 1, 'name' => 'Test Product' ),
        array( 'id' => 2, 'name' => 'Another Product' ),
    ),
);

$logger = new Softone_Item_Sync_Logger();
$api    = new Softone_API_Client( $pages );
$sync   = new Softone_Item_Sync_Test( $api, $logger );

$generator = $sync->yield_rows_public( array() );

foreach ( $generator as $row ) {
    // Exhaust the generator to trigger pagination.
}

$detected = false;

foreach ( $logger->entries as $entry ) {
    if ( false !== strpos( $entry['message'], 'Detected repeated page payload' ) ) {
        $detected = true;
        break;
    }
}

if ( ! $detected ) {
    fwrite( STDERR, "Repeated page detection did not trigger as expected.\n" );
    fwrite( STDERR, 'Hashes: ' . implode( ', ', $sync->hashes ) . "\n" );
    exit( 1 );
}

echo "Repeated page detection confirmed.\n";
exit( 0 );
