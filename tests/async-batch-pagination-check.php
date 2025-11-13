<?php
/**
 * Regression check ensuring async item sync resumes pages across batches.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( '__' ) ) {
    function __( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        return $value;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_object( $args ) ) {
            $args = get_object_vars( $args );
        }

        if ( ! is_array( $args ) ) {
            $args = array();
        }

        if ( ! is_array( $defaults ) ) {
            $defaults = array();
        }

        return array_merge( $defaults, $args );
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        return $default ?: 0;
    }
}

if ( ! class_exists( 'WC_Product' ) ) {
    class WC_Product {}
}

if ( ! class_exists( 'WC_Product_Simple' ) ) {
    class WC_Product_Simple extends WC_Product {}
}

if ( ! class_exists( 'Softone_Category_Sync_Logger' ) ) {
    class Softone_Category_Sync_Logger {
        public function __construct( $logger ) {}
    }
}

if ( ! class_exists( 'Softone_Sync_Activity_Logger' ) ) {
    class Softone_Sync_Activity_Logger {}
}

if ( ! class_exists( 'Softone_API_Client' ) ) {
    class Softone_API_Client {
        /**
         * @var array<int, array<int, array<string, mixed>>>
         */
        protected $pages = array();

        /**
         * @param array<int, array<int, array<string, mixed>>> $pages
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
         * @return array<string, mixed>
         */
        public function sql_data( $endpoint, array $data, array $extra ) {
            $page = isset( $extra['pPage'] ) ? (int) $extra['pPage'] : 1;

            if ( isset( $this->pages[ $page ] ) ) {
                return array(
                    'rows'  => $this->pages[ $page ],
                    'total' => count( $this->pages[1] ),
                );
            }

            return array( 'rows' => array(), 'total' => count( $this->pages[1] ) );
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

class Softone_Item_Sync_Async_Test extends Softone_Item_Sync {
    /**
     * @var array<int, array<string, mixed>>
     */
    public $imported = array();

    /**
     * @var array<int, array{level:string,message:string,context:array}>
     */
    public $logs = array();

    /**
     * @param array<string, mixed> $row
     * @param int                  $run_timestamp
     *
     * @return string
     */
    protected function import_row( array $row, $run_timestamp ) {
        $this->imported[] = $row;
        return 'created';
    }

    /** @return void */
    protected function reset_caches() {}

    /** @return void */
    protected function maybe_adjust_memory_limits() {}

    /** @return void */
    protected function process_pending_single_product_variations() {}

    /** @return void */
    protected function process_pending_colour_variation_syncs() {}

    /**
     * @param int $run_timestamp
     * @return int
     */
    protected function handle_stale_products( $run_timestamp ) {
        return 0;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array  $context
     * @return void
     */
    protected function log( $level, $message, array $context = array() ) {
        $this->logs[] = array(
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        );
    }

    /** @return void */
    protected function log_activity( $channel, $action, $message, array $context = array() ) {}
}

$rows = array();
for ( $i = 1; $i <= 49; $i++ ) {
    $rows[] = array(
        'id'   => $i,
        'name' => 'Product ' . $i,
    );
}

$pages = array(
    1 => $rows,
    2 => array(),
);

$logger = new Softone_Item_Sync_Logger();
$api    = new Softone_API_Client( $pages );
$sync   = new Softone_Item_Sync_Async_Test( $api, $logger );

try {
    $state = $sync->begin_async_import();
} catch ( Exception $exception ) {
    fwrite( STDERR, 'Failed to initialise async import: ' . $exception->getMessage() . "\n" );
    exit( 1 );
}

$result1 = $sync->run_async_import_batch( $state, 25 );
$state   = $result1['state'];

if ( $result1['complete'] ) {
    fwrite( STDERR, "Import reported complete after first batch.\n" );
    exit( 1 );
}

if ( 25 !== (int) $result1['batch']['processed'] ) {
    fwrite( STDERR, 'Unexpected first batch count: ' . $result1['batch']['processed'] . "\n" );
    exit( 1 );
}

$result2 = $sync->run_async_import_batch( $state, 25 );

if ( ! $result2['complete'] ) {
    fwrite( STDERR, "Import did not complete after second batch.\n" );
    exit( 1 );
}

if ( 24 !== (int) $result2['batch']['processed'] ) {
    fwrite( STDERR, 'Unexpected second batch count: ' . $result2['batch']['processed'] . "\n" );
    exit( 1 );
}

if ( 49 !== (int) $result2['stats']['processed'] ) {
    fwrite( STDERR, 'Processed count mismatch: ' . $result2['stats']['processed'] . "\n" );
    exit( 1 );
}

foreach ( $result2['warnings'] as $warning ) {
    if ( false !== strpos( $warning, 'repeated page payload' ) ) {
        fwrite( STDERR, "Duplicate page warning triggered unexpectedly.\n" );
        exit( 1 );
    }
}

echo "Async import batches completed successfully.\n";
exit( 0 );
