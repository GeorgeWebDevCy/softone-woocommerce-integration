<?php
/**
 * SoftOne item synchronisation service.
 *
 * @package    Softone_Woocommerce_Integration
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

if ( ! class_exists( 'Softone_Item_Sync' ) ) {
    /**
     * Handles importing SoftOne catalogue items into WooCommerce.
     */
    class Softone_Item_Sync {

        const CRON_HOOK          = 'softone_wc_integration_sync_items';
        const ADMIN_ACTION       = 'softone_wc_integration_run_item_import';
        const META_MTRL          = '_softone_mtrl_id';
        const META_LAST_SYNC     = '_softone_last_synced';
        const META_PAYLOAD_HASH  = '_softone_payload_hash';
        const OPTION_LAST_RUN    = 'softone_wc_integration_last_item_sync';
        const LOGGER_SOURCE      = 'softone-item-sync';
        const DEFAULT_CRON_EVENT = 'hourly';

        /**
         * API client instance.
         *
         * @var Softone_API_Client
         */
        protected $api_client;

        /**
         * Logger instance.
         *
         * @var WC_Logger|Psr\Log\LoggerInterface|null
         */
        protected $logger;

        /**
         * Category synchronisation logger instance.
         *
         * @var Softone_Category_Sync_Logger|object|null
         */
        protected $category_logger;

        /**
         * File-based synchronisation activity logger.
         *
         * @var Softone_Sync_Activity_Logger|null
         */
        protected $activity_logger;

        /**
         * Cache for taxonomy terms keyed by taxonomy and term name.
         *
         * @var array<string,int>
         */
        protected $term_cache = array();

        /**
         * Cache for attribute terms keyed by taxonomy and term name.
         *
         * @var array<string,int>
         */
        protected $attribute_term_cache = array();

        /**
         * Cache for attribute taxonomies keyed by slug.
         *
         * @var array<string,int>
         */
        protected $attribute_taxonomy_cache = array();

        /**
         * Cache usage statistics for debugging purposes.
         *
         * @var array<string,int>
         */
        protected $cache_stats = array();

        /**
         * Whether taxonomy assignments should be refreshed regardless of payload hash matches.
         *
         * @var bool
         */
        protected $force_taxonomy_refresh = false;

        /**
         * Constructor.
         *
         * @param Softone_API_Client|null           $api_client API client instance.
         * @param WC_Logger|Psr\Log\LoggerInterface|null $logger Optional logger instance.
         */
        public function __construct( ?Softone_API_Client $api_client = null, $logger = null, $category_logger = null, ?Softone_Sync_Activity_Logger $activity_logger = null ) {
            $this->api_client = $api_client ?: new Softone_API_Client();
            $this->logger     = $logger ?: $this->get_default_logger();

            if ( null !== $category_logger && method_exists( $category_logger, 'log_assignment' ) ) {
                $this->category_logger = $category_logger;
            } else {
                $this->category_logger = new Softone_Category_Sync_Logger( $this->logger );
            }

            $this->activity_logger = $activity_logger;

            $this->reset_caches();
        }

        /**
         * Register WordPress hooks via the loader.
         *
         * @param Softone_Woocommerce_Integration_Loader $loader Loader instance.
         *
         * @return void
         */
        public function register_hooks( Softone_Woocommerce_Integration_Loader $loader ) {
            $loader->add_action( 'init', $this, 'maybe_schedule_cron' );
            $loader->add_action( self::CRON_HOOK, $this, 'handle_scheduled_sync', 10, 0 );
        }

        /**
         * Schedule the cron event when needed.
         *
         * @return void
         */
        public function maybe_schedule_cron() {
            self::schedule_event();
        }

        /**
         * Ensure the recurring cron event is registered.
         *
         * @return void
         */
        public static function schedule_event() {
            if ( wp_next_scheduled( self::CRON_HOOK ) ) {
                return;
            }

            $interval = apply_filters( 'softone_wc_integration_item_sync_interval', self::DEFAULT_CRON_EVENT );
            if ( ! is_string( $interval ) || '' === $interval ) {
                $interval = self::DEFAULT_CRON_EVENT;
            }

            wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::CRON_HOOK );
        }

        /**
         * Remove any scheduled cron events.
         *
         * @return void
         */
        public static function clear_scheduled_event() {
            if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
                return;
            }

            $timestamp = wp_next_scheduled( self::CRON_HOOK );

            while ( false !== $timestamp ) {
                wp_unschedule_event( $timestamp, self::CRON_HOOK );
                $timestamp = wp_next_scheduled( self::CRON_HOOK );
            }
        }

        /**
         * Execute the synchronisation routine for cron events.
         *
         * @return void
         */
        public function handle_scheduled_sync() {
            try {
                $result = $this->sync();
                if ( isset( $result['processed'] ) ) {
                    $timestamp = isset( $result['started_at'] ) ? (int) $result['started_at'] : time();
                    update_option( self::OPTION_LAST_RUN, $timestamp );
                }
            } catch ( Exception $exception ) {
                $this->log( 'error', $exception->getMessage(), array( 'exception' => $exception ) );
            }
        }

        /**
         * Run the synchronisation and return statistics.
         *
         * @throws Softone_API_Client_Exception When API requests fail.
         * @throws Exception                     When WooCommerce is not available.
         *
         * @param bool|null $force_full_import        Whether to force a full import.
         * @param bool      $force_taxonomy_refresh Whether to refresh taxonomy assignments even when the payload hash matches.
         *
         * @return array{
         *     processed:int,
         *     created:int,
         *     updated:int,
         *     skipped:int,
         *     started_at:int,
         *     stale_processed?:int
         * }
         */
        public function sync( $force_full_import = null, $force_taxonomy_refresh = false ) {
            if ( ! class_exists( 'WC_Product' ) ) {
                throw new Exception( __( 'WooCommerce is required to sync items.', 'softone-woocommerce-integration' ) );
            }

            $started_at = time();
            $last_run   = (int) get_option( self::OPTION_LAST_RUN );

            $this->reset_caches();
            $this->maybe_adjust_memory_limits();

            $cache_addition_previous_state = null;
            $term_counting_previous_state  = null;
            $comment_count_previous_state  = null;

            if ( function_exists( 'wp_suspend_cache_addition' ) ) {
                $cache_addition_previous_state = wp_suspend_cache_addition( true );
            }

            if ( function_exists( 'wp_defer_term_counting' ) ) {
                $term_counting_previous_state = wp_defer_term_counting( true );
            }

            if ( function_exists( 'wp_defer_comment_counting' ) ) {
                $comment_count_previous_state = wp_defer_comment_counting( true );
            }

            $this->log(
                'info',
                'Starting Softone item sync run.',
                array(
                    'started_at' => $started_at,
                    'last_run'   => $last_run,
                )
            );

            if ( null === $force_full_import ) {
                $force_full_import = false;
            }

            /**
             * Allow forcing a full item sync instead of a delta update.
             *
             * @param bool $force_full_import Current full import flag.
             * @param int  $last_run          Unix timestamp of the previous run.
             */
            $force_full_import = (bool) apply_filters( 'softone_wc_integration_item_sync_force_full', $force_full_import, $last_run );

            $extra = array();

            if ( $last_run > 0 && ! $force_full_import ) {
                $elapsed_seconds = max( 0, $started_at - $last_run );
                $minutes         = max( 1, (int) ceil( $elapsed_seconds / MINUTE_IN_SECONDS ) );
                $extra['pMins']  = $minutes;

                $this->log(
                    'info',
                    sprintf( 'Running Softone item sync in delta mode for the last %d minute(s).', $minutes ),
                    array(
                        'minutes'    => $minutes,
                        'started_at' => $started_at,
                        'last_run'   => $last_run,
                    )
                );
            }

            $stats = array(
                'processed'  => 0,
                'created'    => 0,
                'updated'    => 0,
                'skipped'    => 0,
                'started_at' => $started_at,
            );

            $previous_force_taxonomy_refresh = $this->force_taxonomy_refresh;
            $this->force_taxonomy_refresh    = (bool) $force_taxonomy_refresh;

            try {
                foreach ( $this->yield_item_rows( $extra ) as $row ) {
                    $stats['processed']++;

                    try {
                        $normalized = $this->normalize_row( $row );
                        $result     = $this->import_row( $normalized, $started_at );

                        if ( 'created' === $result ) {
                            $stats['created']++;
                        } elseif ( 'updated' === $result ) {
                            $stats['updated']++;
                        } else {
                            $stats['skipped']++;
                        }
                    } catch ( Exception $exception ) {
                        $stats['skipped']++;
                        $this->log( 'error', $exception->getMessage(), array( 'row' => $row ) );
                    }
                }

                $stale_processed = $this->handle_stale_products( $started_at );

                if ( $stale_processed > 0 ) {
                    $stats['stale_processed'] = $stale_processed;
                }

                $this->log(
                    'debug',
                    'Softone item sync cache usage summary.',
                    array( 'cache_stats' => $this->cache_stats )
                );

                return $stats;
            } finally {
                if ( function_exists( 'wp_suspend_cache_addition' ) && null !== $cache_addition_previous_state ) {
                    wp_suspend_cache_addition( $cache_addition_previous_state );
                }

                if ( function_exists( 'wp_defer_term_counting' ) && null !== $term_counting_previous_state ) {
                    wp_defer_term_counting( $term_counting_previous_state );
                }

                if ( function_exists( 'wp_defer_comment_counting' ) && null !== $comment_count_previous_state ) {
                    wp_defer_comment_counting( $comment_count_previous_state );
                }

                $this->force_taxonomy_refresh = $previous_force_taxonomy_refresh;
            }
        }

        /**
         * Adjust memory limits to reduce the chance of fatal errors during large imports.
         *
         * @return void
         */
        protected function maybe_adjust_memory_limits() {
            if ( function_exists( 'wp_raise_memory_limit' ) ) {
                wp_raise_memory_limit( 'admin' );
            }

            $target_limit = apply_filters( 'softone_wc_integration_item_sync_memory_limit', '1024M' );

            if ( ! is_string( $target_limit ) || '' === trim( $target_limit ) ) {
                return;
            }

            $target_bytes  = $this->normalize_memory_limit( $target_limit );
            $current_limit = function_exists( 'ini_get' ) ? ini_get( 'memory_limit' ) : false;

            if ( $target_bytes <= 0 && -1 !== $target_bytes ) {
                return;
            }

            if ( false === $current_limit || '' === $current_limit ) {
                if ( function_exists( 'ini_set' ) ) {
                    @ini_set( 'memory_limit', $target_limit );
                }
                return;
            }

            $current_bytes = $this->normalize_memory_limit( $current_limit );

            if ( -1 === $current_bytes || ( $current_bytes >= $target_bytes && $target_bytes > 0 ) ) {
                return;
            }

            if ( function_exists( 'ini_set' ) ) {
                @ini_set( 'memory_limit', $target_limit );
            }
        }

        /**
         * Normalise a human readable memory limit value into bytes.
         *
         * @param string|int|float $value Memory limit value.
         *
         * @return int
         */
        protected function normalize_memory_limit( $value ) {
            if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
                return (int) wp_convert_hr_to_bytes( $value );
            }

            if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
                return 0;
            }

            $value = trim( (string) $value );

            if ( '' === $value ) {
                return 0;
            }

            if ( '-1' === $value ) {
                return -1;
            }

            $unit   = strtolower( substr( $value, -1 ) );
            $number = (float) $value;

            if ( in_array( $unit, array( 'g', 'm', 'k' ), true ) ) {
                $number = (float) substr( $value, 0, -1 );
            }

            switch ( $unit ) {
                case 'g':
                    $number *= 1024;
                    // no break.
                case 'm':
                    $number *= 1024;
                    // no break.
                case 'k':
                    $number *= 1024;
                    break;
            }

            return (int) round( $number );
        }

        /**
         * Retrieve SoftOne item rows using memory friendly pagination.
         *
         * @param array $extra Additional payload values for the SqlData request.
         *
         * @return Generator<int, array<string, mixed>>
         */
        protected function yield_item_rows( array $extra ) {
            $default_page_size = 250;
            $filtered_page_size = (int) apply_filters( 'softone_wc_integration_item_sync_page_size', $default_page_size );

            if ( $filtered_page_size <= 0 ) {
                $this->log(
                    'warning',
                    'Received non-positive item sync page size from filter. Falling back to default.',
                    array(
                        'filtered_page_size' => $filtered_page_size,
                        'default_page_size'  => $default_page_size,
                    )
                );
                $page_size = $default_page_size;
            } else {
                $page_size = $filtered_page_size;
            }

            $max_pages     = (int) apply_filters( 'softone_wc_integration_item_sync_max_pages', 0 );
            $page          = 1;
            $previous_hash = array();

            while ( true ) {
                $page_extra = $extra;
                $page_extra['pPage'] = $page;
                $page_extra['pSize'] = $page_size;

                $response = $this->api_client->sql_data( 'getItems', array(), $page_extra );
                $rows     = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array();

                $this->log_activity(
                    'api_requests',
                    'payload_received',
                    'Received payload from Softone API for getItems request.',
                    array(
                        'endpoint'  => 'getItems',
                        'page'      => $page,
                        'page_size' => $page_size,
                        'row_count' => count( $rows ),
                        'request'   => $page_extra,
                        'payload'   => $this->prepare_api_payload_for_logging( $response ),
                    )
                );

                if ( empty( $rows ) ) {
                    break;
                }

                $hash = $this->hash_item_rows( $rows );

                if ( isset( $previous_hash[ $hash ] ) ) {
                    $this->log(
                        'warning',
                        'Detected repeated page payload when fetching Softone item rows. Aborting further pagination to prevent an infinite loop.',
                        array(
                            'page'      => $page,
                            'page_size' => $page_size,
                        )
                    );
                    break;
                }

                $previous_hash[ $hash ] = true;

                foreach ( $rows as $row ) {
                    yield $row;
                }

                $row_count = count( $rows );
                unset( $rows );

                if ( function_exists( 'gc_collect_cycles' ) ) {
                    gc_collect_cycles();
                }

                if ( $row_count < $page_size ) {
                    break;
                }

                $page++;

                if ( $max_pages > 0 && $page > $max_pages ) {
                    break;
                }
            }
        }

        /**
         * Generate a deterministic hash for SoftOne item rows.
         *
         * @param array<int|string, mixed> $rows Rows returned from the API.
         *
         * @return string
         */
        protected function hash_item_rows( array $rows ) {
            $context = hash_init( 'md5' );

            $this->hash_append_value( $context, $rows );

            return hash_final( $context );
        }

        /**
         * Append a PHP value to the hash context using JSON encoding semantics.
         *
         * @param resource|\HashContext $context Hash context from hash_init().
         * @param mixed                $value   Value to append to the hash.
         *
         * @return void
         */
        protected function hash_append_value( $context, $value ) {
            if ( is_array( $value ) ) {
                $keys       = array_keys( $value );
                $item_count = count( $value );
                $is_list    = 0 === $item_count || $keys === range( 0, $item_count - 1 );

                if ( $is_list ) {
                    hash_update( $context, '[' );

                    $is_first = true;
                    foreach ( $value as $item ) {
                        if ( $is_first ) {
                            $is_first = false;
                        } else {
                            hash_update( $context, ',' );
                        }

                        $this->hash_append_value( $context, $item );
                    }

                    hash_update( $context, ']' );
                    return;
                }

                hash_update( $context, '{' );

                $is_first = true;
                foreach ( $value as $key => $item ) {
                    if ( $is_first ) {
                        $is_first = false;
                    } else {
                        hash_update( $context, ',' );
                    }

                    $encoded_key = $this->encode_json_fragment( (string) $key );
                    hash_update( $context, $encoded_key );
                    hash_update( $context, ':' );
                    $this->hash_append_value( $context, $item );
                }

                hash_update( $context, '}' );
                return;
            }

            $encoded = $this->encode_json_fragment( $value );
            hash_update( $context, $encoded );
        }

        /**
         * Encode a PHP value into a JSON fragment string.
         *
         * @param mixed $value Value to encode.
         *
         * @return string
         */
        protected function encode_json_fragment( $value ) {
            $encoded = wp_json_encode( $value );

            if ( false === $encoded ) {
                $encoded = json_encode( $value );
            }

            if ( false === $encoded ) {
                $encoded = '';
            }

            return (string) $encoded;
        }

        /**
         * Normalise the row keys by converting them to lowercase snake case strings.
         *
         * @param array $row Raw row from SoftOne.
         *
         * @return array
         */
        protected function normalize_row( array $row ) {
            $normalized = array();

            foreach ( $row as $key => $value ) {
                $normalized_key = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '_', (string) $key ) );
                $normalized_key = trim( $normalized_key, '_' );

                if ( '' === $normalized_key ) {
                    continue;
                }

                $normalized[ $normalized_key ] = $value;
            }

            return $normalized;
        }

        /**
         * Import a single row into WooCommerce.
         *
         * @param array $data         Normalised row data.
         * @param int   $run_timestamp Current sync run timestamp.
         *
         * @throws Exception When the row cannot be imported.
         *
         * @return string One of created, updated or skipped.
         */
        protected function import_row( array $data, $run_timestamp ) {
            $mtrl = isset( $data['mtrl'] ) ? (string) $data['mtrl'] : '';
            $sku  = $this->determine_sku( $data );

            if ( '' === $mtrl && '' === $sku ) {
                throw new Exception( __( 'Unable to determine a product identifier for the imported row.', 'softone-woocommerce-integration' ) );
            }

            $product_id = $this->find_existing_product( $sku, $mtrl );
            $is_new     = 0 === $product_id;

            $hash_source = $data;
            ksort( $hash_source );
            $payload_hash = md5( wp_json_encode( $hash_source ) );

            if ( $is_new ) {
                $product = new WC_Product_Simple();
                $product->set_status( 'publish' );
            } else {
                $product = wc_get_product( $product_id );
            }

            if ( ! $product ) {
                throw new Exception( __( 'Failed to load the matching WooCommerce product.', 'softone-woocommerce-integration' ) );
            }

            if ( ! $is_new ) {
                $existing_hash = (string) get_post_meta( $product_id, self::META_PAYLOAD_HASH, true );

                if ( ! $this->force_taxonomy_refresh && '' !== $existing_hash && $existing_hash === $payload_hash ) {
                    $this->log(
                        'debug',
                        'Skipping product import because the payload hash matches the existing product.',
                        array(
                            'product_id'    => $product_id,
                            'sku'           => $sku,
                            'mtrl'          => $mtrl,
                            'payload_hash'  => $payload_hash,
                            'existing_hash' => $existing_hash,
                        )
                    );

                    return 'skipped';
                }
            }

            $name              = $this->get_value( $data, array( 'desc', 'description', 'code' ) );
            $derived_colour    = '';
            $normalized_name   = '';
            $fallback_metadata = array();

            if ( '' !== $name ) {
                list( $normalized_name, $derived_colour ) = $this->split_product_name_and_colour( $name );

                if ( '' === $normalized_name ) {
                    $normalized_name = $name;
                }

                if ( '' !== $normalized_name ) {
                    $product->set_name( $normalized_name );
                }
            }

            if ( '' !== $derived_colour ) {
                $fallback_metadata['colour'] = $derived_colour;
            }

            $description = $this->get_value(
                $data,
                array(
                    'long_description',
                    'longdescription',
                    'cccsocylodes',
                    'remarks',
                    'remark',
                    'notes',
                )
            );
            if ( '' !== $description ) {
                $product->set_description( $description );
            }

            $short_description = $this->get_value(
                $data,
                array(
                    'short_description',
                    'cccsocyshdes',
                )
            );
            if ( '' !== $short_description ) {
                $product->set_short_description( $short_description );
            }

            $price = $this->get_value( $data, array( 'retailprice' ) );
            if ( '' !== $price ) {
                $product->set_regular_price( wc_format_decimal( $price ) );
            }

            $product->set_sku( $sku );

            $stock_quantity = $this->get_value( $data, array( 'stock_qty', 'qty1' ) );
            if ( '' !== $stock_quantity ) {
                $stock_amount = wc_stock_amount( $stock_quantity );
                if ( 0 === $stock_amount && softone_wc_integration_should_force_minimum_stock() ) {
                    $stock_amount = 1;
                }

                $product->set_manage_stock( true );
                $product->set_stock_quantity( $stock_amount );

                $should_backorder = softone_wc_integration_should_backorder_out_of_stock();

                if ( $should_backorder && $stock_amount <= 0 && method_exists( $product, 'set_backorders' ) ) {
                    $product->set_backorders( 'notify' );
                    $product->set_stock_status( 'onbackorder' );
                } else {
                    if ( method_exists( $product, 'set_backorders' ) ) {
                        $product->set_backorders( 'no' );
                    }

                    $product->set_stock_status( $stock_amount > 0 ? 'instock' : 'outofstock' );
                }
            }

            $category_ids = $this->prepare_category_ids( $data );
            if ( ! empty( $category_ids ) ) {
                $product->set_category_ids( $category_ids );
            }

            $brand_value           = trim( $this->get_value( $data, array( 'brand_name', 'brand' ) ) );
            $attribute_assignments = $this->prepare_attribute_assignments( $data, $product, $fallback_metadata );

            if ( ! empty( $attribute_assignments['attributes'] ) ) {
                $product->set_attributes( $attribute_assignments['attributes'] );
            } elseif ( empty( $attribute_assignments['attributes'] ) && $is_new ) {
                $product->set_attributes( array() );
            }

            $this->assign_media_library_images( $product, $sku );

            $product_id = $product->save();

            if ( ! $product_id ) {
                throw new Exception( __( 'Unable to save the WooCommerce product.', 'softone-woocommerce-integration' ) );
            }

            if (
                ! empty( $category_ids )
                && function_exists( 'taxonomy_exists' )
                && taxonomy_exists( 'product_cat' )
                && function_exists( 'wp_set_object_terms' )
            ) {
                $category_assignment = wp_set_object_terms( $product_id, $category_ids, 'product_cat' );

                if ( is_wp_error( $category_assignment ) ) {
                    $this->log(
                        'error',
                        'SOFTONE_CAT_SYNC_009 Failed to assign product categories.',
                        array(
                            'product_id'    => $product_id,
                            'category_ids'  => $category_ids,
                            'error_message' => $category_assignment->get_error_message(),
                        )
                    );

                    $this->log_activity(
                        'product_categories',
                        'assignment_error',
                        'Failed to assign product categories.',
                        array(
                            'product_id'    => $product_id,
                            'sku'           => $sku,
                            'mtrl'          => $mtrl,
                            'category_ids'  => $category_ids,
                            'error_message' => $category_assignment->get_error_message(),
                        )
                    );
                } else {
                    $term_taxonomy_ids = array();

                    if ( null !== $category_assignment ) {
                        $term_taxonomy_ids = array_map( 'intval', (array) $category_assignment );
                    }

                    $this->log_category_assignment(
                        $product_id,
                        $category_ids,
                        array(
                            'sku'                    => $sku,
                            'mtrl'                   => $mtrl,
                            'term_taxonomy_ids'      => $term_taxonomy_ids,
                            'force_taxonomy_refresh' => (bool) $this->force_taxonomy_refresh,
                            'sync_action'            => $is_new ? 'created' : 'updated',
                        )
                    );

                    $this->log_activity(
                        'product_categories',
                        'assigned',
                        'Assigned product categories to the item.',
                        array(
                            'product_id'            => $product_id,
                            'sku'                   => $sku,
                            'mtrl'                  => $mtrl,
                            'category_ids'          => $category_ids,
                            'term_taxonomy_ids'     => $term_taxonomy_ids,
                            'force_taxonomy_refresh' => (bool) $this->force_taxonomy_refresh,
                            'sync_action'           => $is_new ? 'created' : 'updated',
                        )
                    );
                }
            }

            if ( $mtrl ) {
                update_post_meta( $product_id, self::META_MTRL, $mtrl );
            }

            update_post_meta( $product_id, self::META_PAYLOAD_HASH, $payload_hash );

            if ( is_numeric( $run_timestamp ) ) {
                update_post_meta( $product_id, self::META_LAST_SYNC, (int) $run_timestamp );
            }

            foreach ( $attribute_assignments['terms'] as $taxonomy => $term_ids ) {
                $normalized_term_ids = array();

                foreach ( (array) $term_ids as $term_id ) {
                    $term_id = (int) $term_id;

                    if ( $term_id > 0 ) {
                        $normalized_term_ids[] = $term_id;
                    }
                }

                if ( empty( $normalized_term_ids ) ) {
                    continue;
                }

                if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
                    $this->log(
                        'error',
                        'SOFTONE_ATTR_SYNC_000 Missing attribute taxonomy before assignment.',
                        array(
                            'product_id'      => $product_id,
                            'taxonomy'        => $taxonomy,
                            'term_ids'        => $normalized_term_ids,
                            'attribute_value' => isset( $attribute_assignments['values'][ $taxonomy ] ) ? $attribute_assignments['values'][ $taxonomy ] : '',
                        )
                    );

                    continue;
                }

                $term_assignment = wp_set_object_terms( $product_id, $normalized_term_ids, $taxonomy );

                if ( is_wp_error( $term_assignment ) ) {
                    $this->log(
                        'error',
                        'SOFTONE_ATTR_SYNC_001 Failed to assign attribute terms.',
                        array(
                            'product_id'      => $product_id,
                            'taxonomy'        => $taxonomy,
                            'term_ids'        => $normalized_term_ids,
                            'attribute_value' => isset( $attribute_assignments['values'][ $taxonomy ] ) ? $attribute_assignments['values'][ $taxonomy ] : '',
                            'error_message'   => $term_assignment->get_error_message(),
                        )
                    );
                    $this->log_activity(
                        'product_attributes',
                        'assignment_error',
                        'Failed to assign attribute terms.',
                        array(
                            'product_id'      => $product_id,
                            'sku'             => $sku,
                            'taxonomy'        => $taxonomy,
                            'term_ids'        => $normalized_term_ids,
                            'attribute_value' => isset( $attribute_assignments['values'][ $taxonomy ] ) ? $attribute_assignments['values'][ $taxonomy ] : '',
                            'error_message'   => $term_assignment->get_error_message(),
                        )
                    );
                }
                if ( ! is_wp_error( $term_assignment ) ) {
                    $this->log_activity(
                        'product_attributes',
                        'assigned_terms',
                        'Assigned attribute terms to the item.',
                        array(
                            'product_id'      => $product_id,
                            'sku'             => $sku,
                            'taxonomy'        => $taxonomy,
                            'term_ids'        => $normalized_term_ids,
                            'attribute_value' => isset( $attribute_assignments['values'][ $taxonomy ] ) ? $attribute_assignments['values'][ $taxonomy ] : '',
                        )
                    );
                }
            }

            $cleared_taxonomies = array();

            foreach ( $attribute_assignments['clear'] as $taxonomy ) {
                if ( '' === $taxonomy ) {
                    continue;
                }

                $cleared_taxonomies[] = (string) $taxonomy;
                wp_set_object_terms( $product_id, array(), $taxonomy );
            }

            if ( ! empty( $cleared_taxonomies ) ) {
                $this->log_activity(
                    'product_attributes',
                    'cleared_terms',
                    'Removed attribute term assignments from the item.',
                    array(
                        'product_id' => $product_id,
                        'sku'        => $sku,
                        'taxonomies' => $cleared_taxonomies,
                    )
                );
            }

            $this->assign_brand_term( $product_id, $brand_value );

            $action = $is_new ? 'created' : 'updated';

            $this->log(
                'info',
                sprintf( 'Product %s via Softone sync.', $action ),
                array(
                    'product_id' => $product_id,
                    'sku'        => $sku,
                    'mtrl'       => $mtrl,
                    'timestamp'  => $run_timestamp,
                )
            );

            return $action;
        }

        /**
         * Identify and handle products that were not updated in the current run.
         *
         * @param int $run_timestamp Timestamp representing the current sync run.
         *
         * @return int Number of products that were marked as stale.
         */
        protected function handle_stale_products( $run_timestamp ) {
            if ( ! is_numeric( $run_timestamp ) || $run_timestamp <= 0 ) {
                return 0;
            }

            $action = apply_filters( 'softone_wc_integration_stale_item_action', 'stock_out' );
            if ( ! in_array( $action, array( 'draft', 'stock_out' ), true ) ) {
                $action = 'stock_out';
            }

            $batch_size = (int) apply_filters( 'softone_wc_integration_stale_item_batch_size', 50 );
            if ( $batch_size <= 0 ) {
                $batch_size = 50;
            }

            $processed = 0;

            do {
                $query = new WP_Query(
                    array(
                        'post_type'      => 'product',
                        'post_status'    => 'any',
                        'fields'         => 'ids',
                        'posts_per_page' => $batch_size,
                        'orderby'        => 'ID',
                        'order'          => 'ASC',
                        'meta_query'     => array(
                            'relation' => 'AND',
                            array(
                                'key'     => self::META_MTRL,
                                'compare' => 'EXISTS',
                            ),
                            array(
                                'relation' => 'OR',
                                array(
                                    'key'     => self::META_LAST_SYNC,
                                    'compare' => 'NOT EXISTS',
                                ),
                                array(
                                    'key'     => self::META_LAST_SYNC,
                                    'value'   => (int) $run_timestamp,
                                    'type'    => 'NUMERIC',
                                    'compare' => '<',
                                ),
                            ),
                        ),
                    )
                );

                if ( ! $query->have_posts() ) {
                    wp_reset_postdata();
                    break;
                }

                foreach ( $query->posts as $product_id ) {
                    $processed++;

                    $product = wc_get_product( $product_id );

                    if ( ! $product ) {
                        $this->log(
                            'warning',
                            'Unable to load product while marking as stale.',
                            array(
                                'product_id' => $product_id,
                            )
                        );
                        update_post_meta( $product_id, self::META_LAST_SYNC, (int) $run_timestamp );
                        continue;
                    }

                    if ( 'draft' === $action ) {
                        if ( 'draft' !== $product->get_status() ) {
                            $product->set_status( 'draft' );
                        }
                    } else {
                        if ( 'publish' !== $product->get_status() ) {
                            $product->set_status( 'publish' );
                        }

                        $product->set_stock_status( 'outofstock' );
                    }

                    $product->save();

                    update_post_meta( $product_id, self::META_LAST_SYNC, (int) $run_timestamp );

                    $this->log(
                        'info',
                        'Marked product as stale following Softone sync run.',
                        array(
                            'product_id' => $product_id,
                            'action'     => $action,
                        )
                    );
                }

                wp_reset_postdata();
            } while ( true );

            if ( $processed > 0 ) {
                $this->log(
                    'notice',
                    sprintf( 'Handled %d stale Softone products.', $processed ),
                    array(
                        'action'     => $action,
                        'timestamp'  => $run_timestamp,
                        'batch_size' => $batch_size,
                    )
                );
            }

            return $processed;
        }

        /**
         * Determine the SKU to use for a row.
         *
         * @param array $data Normalised row data.
         *
         * @return string
         */
        protected function determine_sku( array $data ) {
            $candidates = array( 'sku', 'barcode', 'code' );

            foreach ( $candidates as $key ) {
                if ( isset( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
                    return (string) $data[ $key ];
                }
            }

            return '';
        }

        /**
         * Find an existing WooCommerce product that matches the provided identifiers.
         *
         * @param string $sku  Product SKU.
         * @param string $mtrl SoftOne material id.
         *
         * @return int Product ID or 0 when not found.
         */
        protected function find_existing_product( $sku, $mtrl ) {
            global $wpdb;

            if ( '' !== $mtrl ) {
                $query = $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                    self::META_MTRL,
                    $mtrl
                );

                $found = $wpdb->get_var( $query );
                if ( $found ) {
                    return (int) $found;
                }
            }

            if ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
                $product_id = wc_get_product_id_by_sku( $sku );
                if ( $product_id ) {
                    return (int) $product_id;
                }
            }

            return 0;
        }

        /**
         * Prepare a list of category IDs from the SoftOne data.
         *
         * @param array $data Normalised data.
         *
         * @return int[]
         */
        protected function prepare_category_ids( array $data ) {
            $categories = array();

            $category_name    = $this->get_value(
                $data,
                array(
                    'commecategory_name',
                    'commercategory_name',
                    'commercategory',
                    'category_name',
                    'Category Name',
                    'Category',
                )
            );
            $subcategory_name = $this->get_value(
                $data,
                array(
                    'submecategory_name',
                    'subcategory_name',
                    'subcategory',
                    'Subcategory Name',
                    'Subcategory',
                )
            );

            $subsubcategory_name = $this->get_value(
                $data,
                array(
                    'subsubcategoy_name',
                    'subsubcategory_name',
                    'subsubcategory',
                    'sub_subcategory_name',
                    'sub_subcategory',
                )
            );

            $category_parent = 0;

            $category_slug        = function_exists( 'sanitize_title' ) ? sanitize_title( $category_name ) : '';
            $subcategory_slug     = function_exists( 'sanitize_title' ) ? sanitize_title( $subcategory_name ) : '';
            $subsubcategory_slug  = function_exists( 'sanitize_title' ) ? sanitize_title( $subsubcategory_name ) : '';

            $category_uncategorized       = $this->evaluate_uncategorized_term( $category_name );
            $subcategory_uncategorized    = $this->evaluate_uncategorized_term( $subcategory_name );
            $subsubcategory_uncategorized = $this->evaluate_uncategorized_term( $subsubcategory_name );

            $category_is_uncategorized       = $category_uncategorized['is_uncategorized'];
            $subcategory_is_uncategorized    = $subcategory_uncategorized['is_uncategorized'];
            $subsubcategory_is_uncategorized = $subsubcategory_uncategorized['is_uncategorized'];

            $item_log_context = $this->get_item_log_context( $data );

            $category_context = array(
                'raw_name'       => $category_name,
                'sanitized_slug' => $category_slug,
                'term_id'        => 0,
                'parent_id'      => 0,
            );

            $category_log_context = $this->extend_log_context_with_item( $category_context, $item_log_context );

            if (
                '' !== $category_name
                && ! $this->is_numeric_term_name( $category_name )
                && ! $category_is_uncategorized
            ) {
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_002 Ensuring top-level category.',
                    $category_log_context
                );
                $category_parent = $this->ensure_term( $category_name, 'product_cat' );
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_002 Result for top-level category ensure.',
                    $this->extend_log_context_with_item(
                        array_merge(
                            $category_context,
                            array(
                                'term_id' => $category_parent,
                            )
                        ),
                        $item_log_context
                    )
                );
                if ( $category_parent ) {
                    $categories[] = $category_parent;
                }
            } else {
                $reason = 'empty_name';

                if ( '' !== $category_name && $this->is_numeric_term_name( $category_name ) ) {
                    $reason = 'numeric_name';
                } elseif ( '' !== $category_name && $category_is_uncategorized ) {
                    $reason = 'uncategorized';
                }

                $skip_context = array_merge(
                    $category_context,
                    array(
                        'reason'    => $reason,
                        'term_role' => 'category',
                    ),
                    $this->build_uncategorized_log_fields( $category_uncategorized )
                );

                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_001 Skipping top-level category ensure.',
                    $this->extend_log_context_with_item(
                        $skip_context,
                        $item_log_context
                    )
                );
            }

            $subcategory_context = array(
                'raw_name'       => $subcategory_name,
                'sanitized_slug' => $subcategory_slug,
                'term_id'        => 0,
                'parent_id'      => $category_parent,
            );

            $subcategory_log_context = $this->extend_log_context_with_item( $subcategory_context, $item_log_context );

            if (
                '' !== $subcategory_name
                && ! $this->is_numeric_term_name( $subcategory_name )
                && ! $subcategory_is_uncategorized
            ) {
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_005 Ensuring subcategory.',
                    $subcategory_log_context
                );
                $subcategory_id = $this->ensure_term( $subcategory_name, 'product_cat', $category_parent );
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_005 Result for subcategory ensure.',
                    $this->extend_log_context_with_item(
                        array_merge(
                            $subcategory_context,
                            array(
                                'term_id' => $subcategory_id,
                            )
                        ),
                        $item_log_context
                    )
                );
                if ( $subcategory_id ) {
                    $categories[] = $subcategory_id;
                }
            } else {
                $reason = 'empty_name';

                if ( '' !== $subcategory_name && $this->is_numeric_term_name( $subcategory_name ) ) {
                    $reason = 'numeric_name';
                } elseif ( '' !== $subcategory_name && $subcategory_is_uncategorized ) {
                    $reason = 'uncategorized';
                }

                $skip_context = array_merge(
                    $subcategory_context,
                    array(
                        'reason'    => $reason,
                        'term_role' => 'subcategory',
                    ),
                    $this->build_uncategorized_log_fields( $subcategory_uncategorized )
                );

                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_004 Skipping subcategory ensure.',
                    $this->extend_log_context_with_item(
                        $skip_context,
                        $item_log_context
                    )
                );
            }

            $subsubcategory_parent = $category_parent;

            if ( isset( $subcategory_id ) && $subcategory_id ) {
                $subsubcategory_parent = $subcategory_id;
            }

            $subsubcategory_context = array(
                'raw_name'       => $subsubcategory_name,
                'sanitized_slug' => $subsubcategory_slug,
                'term_id'        => 0,
                'parent_id'      => $subsubcategory_parent,
            );

            $subsubcategory_log_context = $this->extend_log_context_with_item( $subsubcategory_context, $item_log_context );

            if (
                '' !== $subsubcategory_name
                && ! $this->is_numeric_term_name( $subsubcategory_name )
                && ! $subsubcategory_is_uncategorized
            ) {
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_011 Ensuring sub-subcategory.',
                    $subsubcategory_log_context
                );
                $subsubcategory_id = $this->ensure_term( $subsubcategory_name, 'product_cat', $subsubcategory_parent );
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_011 Result for sub-subcategory ensure.',
                    $this->extend_log_context_with_item(
                        array_merge(
                            $subsubcategory_context,
                            array(
                                'term_id' => $subsubcategory_id,
                            )
                        ),
                        $item_log_context
                    )
                );
                if ( $subsubcategory_id ) {
                    $categories[] = $subsubcategory_id;
                }
            } else {
                $reason = 'empty_name';

                if ( '' !== $subsubcategory_name && $this->is_numeric_term_name( $subsubcategory_name ) ) {
                    $reason = 'numeric_name';
                } elseif ( '' !== $subsubcategory_name && $subsubcategory_is_uncategorized ) {
                    $reason = 'uncategorized';
                }

                $skip_context = array_merge(
                    $subsubcategory_context,
                    array(
                        'reason'    => $reason,
                        'term_role' => 'sub_subcategory',
                    ),
                    $this->build_uncategorized_log_fields( $subsubcategory_uncategorized )
                );

                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_012 Skipping sub-subcategory ensure.',
                    $this->extend_log_context_with_item(
                        $skip_context,
                        $item_log_context
                    )
                );
            }

            if ( $category_is_uncategorized ) {
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_010 Term matched default uncategorized category.',
                    $this->extend_log_context_with_item(
                        array_merge(
                            $category_context,
                            array( 'term_role' => 'category' ),
                            $this->build_uncategorized_log_fields( $category_uncategorized )
                        ),
                        $item_log_context
                    )
                );
            }

            if ( $subcategory_is_uncategorized ) {
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_010 Term matched default uncategorized category.',
                    $this->extend_log_context_with_item(
                        array_merge(
                            $subcategory_context,
                            array( 'term_role' => 'subcategory' ),
                            $this->build_uncategorized_log_fields( $subcategory_uncategorized )
                        ),
                        $item_log_context
                    )
                );
            }

            if ( $subsubcategory_is_uncategorized ) {
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_010 Term matched default uncategorized category.',
                    $this->extend_log_context_with_item(
                        array_merge(
                            $subsubcategory_context,
                            array( 'term_role' => 'sub_subcategory' ),
                            $this->build_uncategorized_log_fields( $subsubcategory_uncategorized )
                        ),
                        $item_log_context
                    )
                );
            }

            return array_values( array_unique( array_filter( $categories ) ) );
        }

        /**
         * Assign images from the media library to a product based on its SKU.
         *
         * @param WC_Product $product Product instance.
         * @param string     $sku     Product SKU.
         *
         * @return void
         */
        protected function assign_media_library_images( $product, $sku ) {
            if ( ! $product instanceof WC_Product ) {
                return;
            }

            $allow_assignment = apply_filters(
                'softone_wc_integration_assign_media_library_images',
                true,
                $product,
                $sku
            );

            if ( ! $allow_assignment ) {
                return;
            }

            $attachment_ids = $this->locate_media_library_images_for_sku( $sku );

            if ( empty( $attachment_ids ) ) {
                return;
            }

            $primary_id = (int) array_shift( $attachment_ids );

            if ( $primary_id > 0 ) {
                $product->set_image_id( $primary_id );
            }

            $gallery_ids = array_values( array_filter( array_map( 'intval', $attachment_ids ) ) );

            $product->set_gallery_image_ids( $gallery_ids );
        }

        /**
         * Locate media library images that correspond to the provided SKU.
         *
         * @param string $sku Product SKU.
         *
         * @return int[] Attachment identifiers ordered for assignment.
         */
        protected function locate_media_library_images_for_sku( $sku ) {
            $sku = trim( (string) $sku );

            if ( '' === $sku ) {
                return array();
            }

            if ( ! class_exists( 'WP_Query' ) ) {
                return array();
            }

            $normalized_sku = $this->normalize_media_library_token( $sku );

            if ( '' === $normalized_sku ) {
                return array();
            }

            $attachment_ids = array();

            $search_queries = array(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => -1,
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                    'fields'         => 'ids',
                    's'              => $sku,
                ),
            );

            $meta_terms = array_unique(
                array_filter(
                    array(
                        $sku,
                        function_exists( 'sanitize_title' ) ? sanitize_title( $sku ) : '',
                        $normalized_sku,
                    )
                )
            );

            if ( ! empty( $meta_terms ) ) {
                $meta_query = array( 'relation' => 'OR' );

                foreach ( $meta_terms as $term ) {
                    $meta_query[] = array(
                        'key'     => '_wp_attached_file',
                        'value'   => $term,
                        'compare' => 'LIKE',
                    );
                }

                $search_queries[] = array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => -1,
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                    'fields'         => 'ids',
                    'meta_query'     => $meta_query,
                );
            }

            foreach ( $search_queries as $query_args ) {
                $query = new WP_Query( $query_args );

                if ( $query->have_posts() ) {
                    $attachment_ids = array_merge(
                        $attachment_ids,
                        array_map( 'intval', (array) $query->posts )
                    );
                }

                wp_reset_postdata();
            }

            $attachment_ids = array_values( array_unique( array_map( 'intval', $attachment_ids ) ) );

            if ( empty( $attachment_ids ) ) {
                return array();
            }

            $attachments = array();

            foreach ( $attachment_ids as $attachment_id ) {
                if ( $attachment_id <= 0 ) {
                    continue;
                }

                if ( function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( $attachment_id ) ) {
                    continue;
                }

                $sequence = $this->determine_attachment_sequence_index( $attachment_id, $normalized_sku );

                if ( null === $sequence ) {
                    continue;
                }

                $attachments[] = array(
                    'id'       => (int) $attachment_id,
                    'sequence' => $sequence,
                );
            }

            if ( empty( $attachments ) ) {
                return array();
            }

            usort(
                $attachments,
                function ( $left, $right ) {
                    if ( $left['sequence'] === $right['sequence'] ) {
                        return $left['id'] <=> $right['id'];
                    }

                    return $left['sequence'] <=> $right['sequence'];
                }
            );

            $pluck_source = function_exists( 'wp_list_pluck' )
                ? wp_list_pluck( $attachments, 'id' )
                : array_map(
                    function ( $attachment ) {
                        return isset( $attachment['id'] ) ? $attachment['id'] : 0;
                    },
                    $attachments
                );

            $ordered_ids = array_values( array_map( 'intval', $pluck_source ) );

            /**
             * Filter the media library image IDs detected for a SKU.
             *
             * @param int[]      $ordered_ids    Attachment identifiers.
             * @param string     $sku            Product SKU.
             * @param string     $normalized_sku Normalized SKU for comparison.
             */
            $ordered_ids = apply_filters( 'softone_wc_integration_media_library_image_ids', $ordered_ids, $sku, $normalized_sku );

            return array_values( array_unique( array_filter( array_map( 'intval', $ordered_ids ) ) ) );
        }

        /**
         * Determine the display sequence for a media library attachment.
         *
         * @param int    $attachment_id Attachment identifier.
         * @param string $normalized_sku Normalized SKU used for comparisons.
         *
         * @return int|null
         */
        protected function determine_attachment_sequence_index( $attachment_id, $normalized_sku ) {
            $normalized_sku = (string) $normalized_sku;

            if ( '' === $normalized_sku ) {
                return null;
            }

            $candidates = array();

            $file_reference = get_post_meta( $attachment_id, '_wp_attached_file', true );

            if ( is_string( $file_reference ) && '' !== $file_reference ) {
                $candidates[] = pathinfo( $file_reference, PATHINFO_FILENAME );
            }

            $candidates[] = get_post_field( 'post_name', $attachment_id );
            $candidates[] = get_the_title( $attachment_id );

            $best_sequence = null;

            foreach ( $candidates as $candidate ) {
                $candidate = (string) $candidate;

                if ( '' === $candidate ) {
                    continue;
                }

                $normalized_candidate = $this->normalize_media_library_token( $candidate );

                if ( '' === $normalized_candidate ) {
                    continue;
                }

                if ( 0 !== strpos( $normalized_candidate, $normalized_sku ) ) {
                    continue;
                }

                $suffix = substr( $normalized_candidate, strlen( $normalized_sku ) );

                if ( '' === $suffix ) {
                    $sequence = 1000;
                } elseif ( preg_match( '/^(\d+)/', $suffix, $matches ) ) {
                    $sequence = (int) ltrim( $matches[1], '0' );

                    if ( $sequence <= 0 ) {
                        $sequence = 1000;
                    }
                } else {
                    $sequence = 1000;
                }

                if ( null === $best_sequence || $sequence < $best_sequence ) {
                    $best_sequence = $sequence;
                }
            }

            if ( null === $best_sequence ) {
                return null;
            }

            return (int) $best_sequence;
        }

        /**
         * Normalise strings for media library comparisons.
         *
         * @param string $value Raw string value.
         *
         * @return string
         */
        protected function normalize_media_library_token( $value ) {
            $value = strtolower( (string) $value );

            $value = preg_replace( '/[^a-z0-9]+/', '', $value );

            if ( ! is_string( $value ) ) {
                $value = '';
            }

            return $value;
        }

        /**
         * Assign the brand taxonomy term to a product.
         *
         * @param int    $product_id  Product identifier.
         * @param string $brand_value Brand name.
         *
         * @return void
         */
        protected function assign_brand_term( $product_id, $brand_value ) {
            $product_id = (int) $product_id;

            if ( $product_id <= 0 ) {
                return;
            }

            $brand_value = trim( (string) $brand_value );

            if ( ! taxonomy_exists( 'product_brand' ) ) {
                if ( '' !== $brand_value ) {
                    update_post_meta( $product_id, 'product_brand', $brand_value );
                } else {
                    delete_post_meta( $product_id, 'product_brand' );
                }

                return;
            }

            // Ensure legacy metadata is removed once taxonomy handling is active.
            delete_post_meta( $product_id, 'product_brand' );

            if ( '' === $brand_value || $this->is_numeric_term_name( $brand_value ) ) {
                wp_set_object_terms( $product_id, array(), 'product_brand' );

                return;
            }

            $term_id = $this->ensure_term( $brand_value, 'product_brand' );

            if ( ! $term_id ) {
                return;
            }

            wp_set_object_terms( $product_id, array( (int) $term_id ), 'product_brand' );
        }

        /**
         * Prepare attribute assignments for a product.
         *
         * @param array       $data                Normalised data.
         * @param WC_Product  $product             Product instance.
         * @param array       $fallback_attributes Optional attribute values keyed by slug.
         *
         * @return array{
         *     attributes: array<string, WC_Product_Attribute>,
         *     terms: array<string, array<int>>,
         *     values: array<string, string>,
         *     clear: array<string>
         * }
         */
        protected function prepare_attribute_assignments( array $data, $product, array $fallback_attributes = array() ) {
            if ( ! function_exists( 'wc_attribute_taxonomy_name' ) || ! class_exists( 'WC_Product_Attribute' ) ) {
                return array(
                    'attributes' => $product->get_attributes(),
                    'terms'      => array(),
                    'clear'      => array(),
                );
            }

            $assignments = array(
                'attributes' => $product->get_attributes(),
                'terms'      => array(),
                'values'     => array(),
                'clear'      => array(),
            );

            $attribute_map = array(
                'colour' => array(
                    'label'    => __( 'Colour', 'softone-woocommerce-integration' ),
                    'value'    => $this->normalize_colour_value(
                        trim( $this->get_value( $data, array( 'colour_name', 'color_name', 'colour', 'color' ) ) )
                    ),
                    'position' => 0,
                ),
                'size'   => array(
                    'label'    => __( 'Size', 'softone-woocommerce-integration' ),
                    'value'    => trim( $this->get_value( $data, array( 'size_name', 'size' ) ) ),
                    'position' => 1,
                ),
                'brand'  => array(
                    'label'    => __( 'Brand', 'softone-woocommerce-integration' ),
                    'value'    => trim( $this->get_value( $data, array( 'brand_name', 'brand' ) ) ),
                    'position' => 2,
                ),
            );

            if ( isset( $attribute_map['colour']['value'] ) && '' === $attribute_map['colour']['value'] && isset( $fallback_attributes['colour'] ) ) {
                $attribute_map['colour']['value'] = $this->normalize_colour_value( $fallback_attributes['colour'] );
            }

            foreach ( $attribute_map as $slug => $config ) {
                $taxonomy = wc_attribute_taxonomy_name( $slug );

                if ( '' === $config['value'] ) {
                    if ( isset( $assignments['attributes'][ $taxonomy ] ) ) {
                        unset( $assignments['attributes'][ $taxonomy ] );
                        $assignments['clear'][] = $taxonomy;
                    }
                    continue;
                }

                $attribute_id = $this->ensure_attribute_taxonomy( $slug, $config['label'] );
                if ( ! $attribute_id ) {
                    continue;
                }

                $term_id = $this->ensure_attribute_term( $taxonomy, $config['value'] );
                if ( ! $term_id ) {
                    continue;
                }

                $attribute = isset( $assignments['attributes'][ $taxonomy ] ) ? $assignments['attributes'][ $taxonomy ] : new WC_Product_Attribute();

                if ( ! $attribute instanceof WC_Product_Attribute ) {
                    $attribute = new WC_Product_Attribute();
                }

                $attribute->set_id( $attribute_id );
                $attribute->set_name( $taxonomy );
                $attribute->set_options( array( (int) $term_id ) );
                $attribute->set_position( (int) $config['position'] );
                $attribute->set_visible( true );
                $attribute->set_variation( false );

                $assignments['attributes'][ $taxonomy ] = $attribute;
                $assignments['terms'][ $taxonomy ]      = array( (int) $term_id );
                $assignments['values'][ $taxonomy ]     = $config['value'];
            }

            return $assignments;
        }

        /**
         * Split a product name into the actual name and a colour suffix when present.
         *
         * @param string $name Product name as received from SoftOne.
         *
         * @return array{0: string, 1: string} Array with the cleaned name and the extracted colour value.
         */
        protected function split_product_name_and_colour( $name ) {
            $name   = (string) $name;
            $colour = '';

            if ( '' === $name || false === strpos( $name, '|' ) ) {
                return array( $name, $colour );
            }

            $parts = explode( '|', $name, 2 );

            $clean_name = isset( $parts[0] ) ? trim( $parts[0] ) : '';
            $suffix     = isset( $parts[1] ) ? trim( $parts[1] ) : '';

            if ( '' !== $suffix ) {
                $colour = $this->normalize_colour_value( $suffix );
            }

            return array( $clean_name, $colour );
        }

        /**
         * Normalise a colour value for use within WooCommerce attributes.
         *
         * @param string $colour Raw colour value.
         *
         * @return string Normalised colour string.
         */
        protected function normalize_colour_value( $colour ) {
            $colour = trim( (string) $colour );

            if ( '' === $colour ) {
                return '';
            }

            if ( function_exists( 'mb_convert_case' ) ) {
                return mb_convert_case( $colour, MB_CASE_TITLE, 'UTF-8' );
            }

            return ucwords( strtolower( $colour ) );
        }

        /**
         * Ensure an attribute taxonomy exists and return its identifier.
         *
         * @param string $slug  Attribute slug.
         * @param string $label Attribute label.
         *
         * @return int Attribute taxonomy identifier.
         */
        protected function ensure_attribute_taxonomy( $slug, $label ) {
            if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
                return 0;
            }

            $key = strtolower( (string) $slug );

            if ( array_key_exists( $key, $this->attribute_taxonomy_cache ) ) {
                $this->cache_stats['attribute_taxonomy_cache_hits']++;

                $attribute_id = (int) $this->attribute_taxonomy_cache[ $key ];

                if ( $attribute_id > 0 && ! $this->ensure_attribute_taxonomy_is_registered( $slug, $label ) ) {
                    unset( $this->attribute_taxonomy_cache[ $key ] );

                    return 0;
                }

                return $attribute_id;
            }

            $this->cache_stats['attribute_taxonomy_cache_misses']++;

            $attribute_id = wc_attribute_taxonomy_id_by_name( $slug );

            if ( $attribute_id ) {
                $attribute_id = (int) $attribute_id;
                $this->attribute_taxonomy_cache[ $key ] = $attribute_id;

                if ( ! $this->ensure_attribute_taxonomy_is_registered( $slug, $label ) ) {
                    unset( $this->attribute_taxonomy_cache[ $key ] );

                    return 0;
                }

                return $attribute_id;
            }

            if ( ! function_exists( 'wc_create_attribute' ) ) {
                return 0;
            }

            $result = wc_create_attribute(
                array(
                    'slug'         => $slug,
                    'name'         => $label,
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                )
            );

            if ( is_wp_error( $result ) ) {
                $this->log( 'error', $result->get_error_message() );

                return 0;
            }

            $attribute_id = (int) $result;
            $this->cache_stats['attribute_taxonomy_created']++;

            delete_transient( 'wc_attribute_taxonomies' );

            if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
                wc_get_attribute_taxonomies();
            }

            $this->attribute_taxonomy_cache[ $key ] = $attribute_id;

            if ( ! $this->ensure_attribute_taxonomy_is_registered( $slug, $label ) ) {
                unset( $this->attribute_taxonomy_cache[ $key ] );

                return 0;
            }

            return $attribute_id;
        }

        /**
         * Ensure an attribute taxonomy is registered with WordPress.
         *
         * @param string $slug  Attribute slug.
         * @param string $label Attribute label.
         *
         * @return bool
         */
        protected function ensure_attribute_taxonomy_is_registered( $slug, $label ) {
            if ( ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
                return false;
            }

            $taxonomy = wc_attribute_taxonomy_name( $slug );

            if ( '' === $taxonomy ) {
                return false;
            }

            if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $taxonomy ) ) {
                return true;
            }

            if ( ! function_exists( 'register_taxonomy' ) ) {
                $this->log(
                    'error',
                    'Unable to register attribute taxonomy because register_taxonomy() is unavailable.',
                    array(
                        'slug'     => $slug,
                        'taxonomy' => $taxonomy,
                    )
                );

                return false;
            }

            register_taxonomy(
                $taxonomy,
                apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ),
                apply_filters(
                    'woocommerce_taxonomy_args_' . $taxonomy,
                    array(
                        'labels'       => array( 'name' => $label ),
                        'hierarchical' => false,
                        'show_ui'      => false,
                        'query_var'    => true,
                        'rewrite'      => false,
                    )
                )
            );

            if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $taxonomy ) ) {
                return true;
            }

            $this->log(
                'error',
                'Failed to register attribute taxonomy for assignment.',
                array(
                    'slug'     => $slug,
                    'taxonomy' => $taxonomy,
                )
            );

            return false;
        }

        /**
         * Ensure a term exists for an attribute taxonomy.
         *
         * @param string $taxonomy Taxonomy name.
         * @param string $value    Term name.
         *
         * @return int Term identifier.
         */
        protected function ensure_attribute_term( $taxonomy, $value ) {
            $value = trim( (string) $value );

            if ( '' === $value ) {
                return 0;
            }

            $key = $this->build_attribute_term_cache_key( $taxonomy, $value );

            if ( array_key_exists( $key, $this->attribute_term_cache ) ) {
                $this->cache_stats['attribute_term_cache_hits']++;

                return (int) $this->attribute_term_cache[ $key ];
            }

            $this->cache_stats['attribute_term_cache_misses']++;

            $term = get_term_by( 'name', $value, $taxonomy );

            if ( $term && ! is_wp_error( $term ) ) {
                $term_id                                    = (int) $term->term_id;
                $this->attribute_term_cache[ $key ]         = $term_id;

                return $term_id;
            }

            $result = wp_insert_term( $value, $taxonomy );

            if ( is_wp_error( $result ) ) {
                if ( 'term_exists' === $result->get_error_code() ) {
                    $existing_term_id = $result->get_error_data();

                    if ( is_array( $existing_term_id ) && isset( $existing_term_id['term_id'] ) ) {
                        $existing_term_id = $existing_term_id['term_id'];
                    }

                    $existing_term_id = (int) $existing_term_id;

                    if ( $existing_term_id > 0 ) {
                        if ( function_exists( 'clean_term_cache' ) ) {
                            clean_term_cache( array( $existing_term_id ), $taxonomy );
                        }

                        $term_object = function_exists( 'get_term' ) ? get_term( $existing_term_id, $taxonomy ) : null;
                        $term_name   = '';

                        if ( $term_object && ! is_wp_error( $term_object ) && isset( $term_object->name ) ) {
                            $term_name = (string) $term_object->name;
                        }

                        if ( $term_name !== $value && function_exists( 'wp_update_term' ) ) {
                            $update_result = wp_update_term(
                                $existing_term_id,
                                $taxonomy,
                                array( 'name' => $value )
                            );

                            if ( is_wp_error( $update_result ) ) {
                                $this->log(
                                    'error',
                                    $update_result->get_error_message(),
                                    array(
                                        'taxonomy' => $taxonomy,
                                        'term_id'  => $existing_term_id,
                                    )
                                );
                            }
                        }

                        $this->attribute_term_cache[ $key ] = $existing_term_id;

                        return $existing_term_id;
                    }
                }

                $this->log( 'error', $result->get_error_message(), array( 'taxonomy' => $taxonomy ) );

                $this->attribute_term_cache[ $key ] = 0;

                return 0;
            }

            $term_id = (int) $result['term_id'];
            $this->cache_stats['attribute_term_created']++;
            $this->attribute_term_cache[ $key ] = $term_id;

            return $term_id;
        }

        /**
         * Determine whether a term name is numeric-only and should be skipped.
         *
         * @param string $name Term name.
         *
         * @return bool
         */
        protected function is_numeric_term_name( $name ) {
            $name = trim( (string) $name );

            // Historically the plugin skipped categories whose names were purely numeric.
            // SoftOne categories are often numeric codes, so we no longer treat them
            // as invalid. Always return false so numeric names are processed.
            return false;
        }

        /**
         * Determine whether a term name refers to the default uncategorised product category.
         *
         * @param string $name Term name.
         *
         * @return bool
         */
        protected function is_uncategorized_term( $name ) {
            $analysis = $this->evaluate_uncategorized_term( $name );

            return $analysis['is_uncategorized'];
        }

        /**
         * Evaluate whether a term name refers to the default uncategorised product category.
         *
         * @param string $name Term name.
         *
         * @return array{
         *     is_uncategorized: bool,
         *     match_type: string,
         *     sanitized_name: string,
         *     default_category_id: int,
         *     default_category_slug: string,
         *     default_category_name: string,
         * }
         */
        protected function evaluate_uncategorized_term( $name ) {
            $name = trim( (string) $name );

            $analysis = array(
                'is_uncategorized'      => false,
                'match_type'            => '',
                'sanitized_name'        => '',
                'default_category_id'   => 0,
                'default_category_slug' => '',
                'default_category_name' => '',
            );

            if ( '' === $name ) {
                return $analysis;
            }

            $analysis['sanitized_name'] = function_exists( 'sanitize_title' ) ? sanitize_title( $name ) : '';

            if ( 'uncategorized' === $analysis['sanitized_name'] ) {
                $analysis['is_uncategorized'] = true;
                $analysis['match_type']       = 'sanitized_name';

                return $analysis;
            }

            $default_category_id = (int) get_option( 'default_product_cat', 0 );
            if ( $default_category_id <= 0 ) {
                return $analysis;
            }

            $analysis['default_category_id'] = $default_category_id;

            $default_category = get_term( $default_category_id, 'product_cat' );
            if ( $default_category instanceof WP_Term ) {
                $analysis['default_category_slug'] = $default_category->slug;
                $analysis['default_category_name'] = $default_category->name;

                if ( 'uncategorized' === $default_category->slug ) {
                    $analysis['is_uncategorized'] = true;
                    $analysis['match_type']       = 'default_slug';

                    return $analysis;
                }

                if ( '' !== $analysis['sanitized_name'] && $analysis['sanitized_name'] === $default_category->slug ) {
                    $analysis['is_uncategorized'] = true;
                    $analysis['match_type']       = 'slug_match';

                    return $analysis;
                }

                if ( 0 === strcasecmp( $name, $default_category->name ) ) {
                    $analysis['is_uncategorized'] = true;
                    $analysis['match_type']       = 'name_match';

                    return $analysis;
                }

                return $analysis;
            }

            if ( is_wp_error( $default_category ) ) {
                $this->log( 'debug', 'Failed to fetch default product category.', array( 'error' => $default_category ) );
            }

            return $analysis;
        }

        /**
         * Prepare log fields describing why a term was considered uncategorized.
         *
         * @param array $analysis Result from evaluate_uncategorized_term().
         *
         * @return array<string, mixed>
         */
        protected function build_uncategorized_log_fields( array $analysis ) {
            if ( empty( $analysis['is_uncategorized'] ) ) {
                return array();
            }

            $fields = array(
                'uncategorized_match_type' => $analysis['match_type'],
            );

            if ( '' !== $analysis['sanitized_name'] ) {
                $fields['uncategorized_sanitized_name'] = $analysis['sanitized_name'];
            }

            if ( ! empty( $analysis['default_category_id'] ) ) {
                $fields['default_category_id'] = (int) $analysis['default_category_id'];
            }

            if ( '' !== $analysis['default_category_slug'] ) {
                $fields['default_category_slug'] = $analysis['default_category_slug'];
            }

            if ( '' !== $analysis['default_category_name'] ) {
                $fields['default_category_name'] = $analysis['default_category_name'];
            }

            return $fields;
        }

        /**
         * Ensure a term exists in a taxonomy, optionally nested.
         *
         * @param string $name   Term name.
         * @param string $tax    Taxonomy.
         * @param int    $parent Optional parent term ID.
         *
         * @return int Term identifier.
         */
        protected function ensure_term( $name, $tax, $parent = 0 ) {
            $name   = trim( (string) $name );
            $parent = (int) $parent;

            $key = $this->build_term_cache_key( $tax, $name, $parent );

            if ( '' === $name ) {
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_006 Empty term name provided.',
                    array(
                        'taxonomy'  => $tax,
                        'parent_id' => $parent,
                        'cache_key' => $key,
                    )
                );

                return 0;
            }

            if ( array_key_exists( $key, $this->term_cache ) ) {
                $this->cache_stats['term_cache_hits']++;

                $cached = (int) $this->term_cache[ $key ];

                if ( 0 === $cached ) {
                    $this->log(
                        'debug',
                        'SOFTONE_CAT_SYNC_007 Term cache contained empty identifier.',
                        array(
                            'taxonomy'  => $tax,
                            'parent_id' => $parent,
                            'cache_key' => $key,
                        )
                    );
                }

                return $cached;
            }

            $this->cache_stats['term_cache_misses']++;

            $sanitized_name = function_exists( 'sanitize_title' ) ? sanitize_title( $name ) : '';

            $term = term_exists( $name, $tax, $parent );

            if ( ! $term && '' !== $sanitized_name ) {
                $term = term_exists( $sanitized_name, $tax, $parent );
            }

            $term_id = $this->normalize_term_identifier( $term );

            if ( $term_id > 0 ) {
                $this->term_cache[ $key ] = $term_id;

                return $term_id;
            }

            $existing_term = null;

            if ( '' !== $sanitized_name ) {
                $existing_term = get_term_by( 'slug', $sanitized_name, $tax );
            }

            if ( ! ( $existing_term instanceof WP_Term ) ) {
                $existing_term = get_term_by( 'name', $name, $tax );
            }

            if ( $existing_term instanceof WP_Term ) {
                $term_id = $this->maybe_update_term_parent( $existing_term, $tax, $parent );

                $this->term_cache[ $key ] = $term_id;

                return $term_id;
            }

            $args = array();

            if ( $parent ) {
                $args['parent'] = $parent;
            }

            $result = wp_insert_term( $name, $tax, $args );

            if ( is_wp_error( $result ) ) {
                $term_id   = 0;
                $error_code = method_exists( $result, 'get_error_code' ) ? $result->get_error_code() : '';
                $error_data = null;

                if ( method_exists( $result, 'get_error_data' ) ) {
                    $error_data = $result->get_error_data( 'term_exists' );

                    if ( null === $error_data ) {
                        $error_data = $result->get_error_data();
                    }
                }

                if ( 'term_exists' === $error_code ) {
                    if ( is_array( $error_data ) && isset( $error_data['term_id'] ) ) {
                        $term_id = (int) $error_data['term_id'];
                    } elseif ( is_numeric( $error_data ) ) {
                        $term_id = (int) $error_data;
                    }

                    if ( $term_id > 0 && function_exists( 'get_term' ) ) {
                        $existing_term = get_term( $term_id, $tax );

                        if ( $existing_term instanceof WP_Term && ! is_wp_error( $existing_term ) ) {
                            $term_id = $this->maybe_update_term_parent( $existing_term, $tax, $parent );

                            $this->term_cache[ $key ] = $term_id;

                            $this->log(
                                'debug',
                                'SOFTONE_CAT_SYNC_013 Re-used existing term after concurrent creation.',
                                array(
                                    'taxonomy'   => $tax,
                                    'parent_id'  => $parent,
                                    'cache_key'  => $key,
                                    'term_id'    => $term_id,
                                    'error_code' => $error_code,
                                )
                            );

                            return $term_id;
                        }
                    }
                }

                if ( $term_id <= 0 ) {
                    $term     = term_exists( $name, $tax, $parent );
                    $term_id  = $this->normalize_term_identifier( $term );
                    $fallback = null;

                    if ( $term_id <= 0 && '' !== $sanitized_name ) {
                        $term    = term_exists( $sanitized_name, $tax, $parent );
                        $term_id = $this->normalize_term_identifier( $term );
                    }

                    if ( $term_id <= 0 && '' !== $sanitized_name ) {
                        $fallback = get_term_by( 'slug', $sanitized_name, $tax );
                    }

                    if ( ! ( $fallback instanceof WP_Term ) ) {
                        $fallback = get_term_by( 'name', $name, $tax );
                    }

                    if ( $fallback instanceof WP_Term ) {
                        $term_id = $this->maybe_update_term_parent( $fallback, $tax, $parent );
                    }

                    if ( $term_id > 0 ) {
                        $this->term_cache[ $key ] = $term_id;

                        $this->log(
                            'debug',
                            'SOFTONE_CAT_SYNC_014 Recovered term after creation error.',
                            array(
                                'taxonomy'   => $tax,
                                'parent_id'  => $parent,
                                'cache_key'  => $key,
                                'term_id'    => $term_id,
                                'error_code' => $error_code,
                                'error_data' => $error_data,
                            )
                        );

                        return $term_id;
                    }
                }

                $this->log(
                    'error',
                    'SOFTONE_CAT_SYNC_003 ' . $result->get_error_message(),
                    array(
                        'taxonomy'   => $tax,
                        'parent_id'  => $parent,
                        'cache_key'  => $key,
                        'error_code' => $error_code,
                        'error_data' => $error_data,
                    )
                );

                $this->term_cache[ $key ] = 0;

                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_008 Term creation failed; returning empty identifier.',
                    array(
                        'taxonomy'   => $tax,
                        'parent_id'  => $parent,
                        'cache_key'  => $key,
                        'error_code' => $error_code,
                        'error_data' => $error_data,
                    )
                );

                return 0;
            }

            $term_id = (int) $result['term_id'];
            $this->cache_stats['term_created']++;
            $this->term_cache[ $key ] = $term_id;

            return $term_id;
        }

        /**
         * Normalise the result of a term lookup to a term identifier.
         *
         * @param mixed $term Term lookup result.
         *
         * @return int
         */
        protected function normalize_term_identifier( $term ) {
            if ( is_array( $term ) && isset( $term['term_id'] ) ) {
                return (int) $term['term_id'];
            }

            if ( $term instanceof WP_Term ) {
                return (int) $term->term_id;
            }

            if ( $term ) {
                return (int) $term;
            }

            return 0;
        }

        /**
         * Ensure that an existing term adheres to the requested hierarchy.
         *
         * @param WP_Term $term   Term instance.
         * @param string  $tax    Taxonomy name.
         * @param int     $parent Desired parent term identifier.
         *
         * @return int
         */
        protected function maybe_update_term_parent( WP_Term $term, $tax, $parent ) {
            $parent = (int) $parent;

            if ( (int) $term->parent === $parent ) {
                return (int) $term->term_id;
            }

            $result = wp_update_term(
                $term->term_id,
                $tax,
                array(
                    'parent' => max( 0, $parent ),
                )
            );

            if ( is_wp_error( $result ) ) {
                $this->log(
                    'error',
                    $result->get_error_message(),
                    array(
                        'taxonomy' => $tax,
                        'term_id'  => $term->term_id,
                    )
                );

                return (int) $term->term_id;
            }

            return (int) $result['term_id'];
        }

        /**
         * Reset all in-memory caches and statistics.
         *
         * @return void
         */
        protected function reset_caches() {
            $this->term_cache                = array();
            $this->attribute_term_cache      = array();
            $this->attribute_taxonomy_cache  = array();
            $this->cache_stats               = array(
                'term_cache_hits'                 => 0,
                'term_cache_misses'               => 0,
                'term_created'                    => 0,
                'attribute_term_cache_hits'       => 0,
                'attribute_term_cache_misses'     => 0,
                'attribute_term_created'          => 0,
                'attribute_taxonomy_cache_hits'   => 0,
                'attribute_taxonomy_cache_misses' => 0,
                'attribute_taxonomy_created'      => 0,
            );
        }

        /**
         * Build a cache key for taxonomy terms.
         *
         * @param string $taxonomy Taxonomy name.
         * @param string $term     Term name.
         * @param int    $parent   Parent term identifier.
         *
         * @return string
         */
        protected function build_term_cache_key( $taxonomy, $term, $parent ) {
            return strtolower( (string) $taxonomy ) . '|' . md5( strtolower( (string) $term ) ) . '|' . (int) $parent;
        }

        /**
         * Build a cache key for attribute taxonomy terms.
         *
         * @param string $taxonomy Taxonomy name.
         * @param string $term     Term name.
         *
         * @return string
         */
        protected function build_attribute_term_cache_key( $taxonomy, $term ) {
            return strtolower( (string) $taxonomy ) . '|' . md5( strtolower( (string) $term ) );
        }

        /**
         * Retrieve a value from the normalised data using the first matching key.
         *
         * @param array $data Normalised data set.
         * @param array $keys Possible keys ordered by preference.
         *
         * @return string
         */
        protected function get_value( array $data, array $keys ) {
            foreach ( $keys as $key ) {
                if ( isset( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
                    return (string) $data[ $key ];
                }
            }

            return '';
        }

        /**
         * Append concise item information to a log context array.
         *
         * @param array $context       Existing log context.
         * @param array $item_context  Item-specific context details.
         *
         * @return array
         */
        protected function extend_log_context_with_item( array $context, array $item_context ) {
            if ( empty( $item_context ) ) {
                return $context;
            }

            $context['item'] = $item_context;

            return $context;
        }

        /**
         * Build a compact representation of the SoftOne item for logging purposes.
         *
         * @param array $data Normalised item data.
         *
         * @return array<string,string>
         */
        protected function get_item_log_context( array $data ) {
            $context = array();

            if ( isset( $data['mtrl'] ) ) {
                $mtrl = trim( (string) $data['mtrl'] );
                if ( '' !== $mtrl ) {
                    $context['mtrl'] = $mtrl;
                }
            }

            $sku = $this->determine_sku( $data );
            if ( '' !== $sku ) {
                $context['sku'] = $sku;
            }

            if ( isset( $data['code'] ) ) {
                $code = trim( (string) $data['code'] );
                if ( '' !== $code && ( ! isset( $context['sku'] ) || $context['sku'] !== $code ) ) {
                    $context['code'] = $code;
                }
            }

            $name = $this->get_value(
                $data,
                array(
                    'desc',
                    'description',
                    'item_description',
                    'itemname',
                    'name',
                )
            );

            if ( '' !== $name ) {
                $context['name'] = $this->truncate_log_value( $name );
            }

            return $context;
        }

        /**
         * Truncate a string to keep log entries concise.
         *
         * @param string $value      Original value.
         * @param int    $max_length Maximum length to keep.
         *
         * @return string
         */
        protected function truncate_log_value( $value, $max_length = 120 ) {
            $value = (string) $value;

            if ( $max_length <= 0 ) {
                return $value;
            }

            if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
                if ( mb_strlen( $value ) <= $max_length ) {
                    return $value;
                }

                return rtrim( mb_substr( $value, 0, $max_length - 1 ) ) . '…';
            }

            if ( strlen( $value ) <= $max_length ) {
                return $value;
            }

            return rtrim( substr( $value, 0, $max_length - 1 ) ) . '…';
        }

        /**
         * Prepare API payload data for sync activity logging.
         *
         * @param mixed $payload Raw payload from the API.
         * @param int   $depth   Current recursion depth.
         *
         * @return mixed
         */
        protected function prepare_api_payload_for_logging( $payload, $depth = 0 ) {
            if ( $depth >= 4 ) {
                return '[payload truncated due to depth limits]';
            }

            if ( is_object( $payload ) ) {
                $payload = (array) $payload;
            }

            if ( is_array( $payload ) ) {
                $normalized = array();
                $count      = 0;
                $total      = count( $payload );

                foreach ( $payload as $key => $value ) {
                    if ( $count >= 50 ) {
                        $remaining = $total - $count;

                        if ( $remaining > 0 ) {
                            $normalized['__truncated__'] = sprintf( '%d additional entries truncated.', $remaining );
                        }

                        break;
                    }

                    $normalized[ $key ] = $this->prepare_api_payload_for_logging( $value, $depth + 1 );
                    $count++;
                }

                return $normalized;
            }

            if ( is_string( $payload ) ) {
                return $this->truncate_log_value( $payload, 800 );
            }

            return $payload;
        }

        /**
         * Record an activity entry in the file-based logger when available.
         *
         * @param string $channel Activity channel identifier.
         * @param string $action  Action descriptor.
         * @param string $message Human readable message.
         * @param array  $context Additional context data.
         *
         * @return void
         */
        protected function log_activity( $channel, $action, $message, array $context = array() ) {
            if ( ! $this->activity_logger || ! method_exists( $this->activity_logger, 'log' ) ) {
                return;
            }

            $this->activity_logger->log( $channel, $action, $message, $context );
        }

        /**
         * Retrieve the default WooCommerce logger when available.
         *
         * @return WC_Logger|Psr\Log\LoggerInterface|null
         */
        protected function get_default_logger() {
            if ( function_exists( 'wc_get_logger' ) ) {
                return wc_get_logger();
            }

            return null;
        }

        /**
         * Log a message using the configured logger.
         *
         * @param string $level   Log level (debug, info, warning, error).
         * @param string $message Message to log.
         * @param array  $context Additional context.
         *
         * @return void
         */
        protected function log( $level, $message, array $context = array() ) {
            if ( ! $this->logger || ! method_exists( $this->logger, 'log' ) ) {
                return;
            }

            if ( class_exists( 'WC_Logger' ) && $this->logger instanceof WC_Logger ) {
                $context['source'] = self::LOGGER_SOURCE;
            }

            $this->logger->log( $level, $message, $context );
        }

        /**
         * Record a category assignment event using the category logger.
         *
         * @param int   $product_id   Product identifier.
         * @param array $category_ids Assigned category identifiers.
         * @param array $context      Additional context to include in the log entry.
         *
         * @return void
         */
        protected function log_category_assignment( $product_id, array $category_ids, array $context = array() ) {
            if ( ! $this->category_logger || ! method_exists( $this->category_logger, 'log_assignment' ) ) {
                return;
            }

            $this->category_logger->log_assignment( $product_id, $category_ids, $context );
        }
    }
}
