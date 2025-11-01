<?php
/**
 * SoftOne item synchronisation service.
 *
 * @package    Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
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
         * Constructor.
         *
         * @param Softone_API_Client|null           $api_client API client instance.
         * @param WC_Logger|Psr\Log\LoggerInterface|null $logger Optional logger instance.
         */
        public function __construct( ?Softone_API_Client $api_client = null, $logger = null ) {
            $this->api_client = $api_client ?: new Softone_API_Client();
            $this->logger     = $logger ?: $this->get_default_logger();

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
         * @param bool|null $force_full_import Whether to force a full import.
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
        public function sync( $force_full_import = null ) {
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

                if ( '' !== $existing_hash && $existing_hash === $payload_hash ) {
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

            $name = $this->get_value( $data, array( 'desc', 'description', 'code' ) );
            if ( '' !== $name ) {
                $product->set_name( $name );
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
            $attribute_assignments = $this->prepare_attribute_assignments( $data, $product );

            if ( ! empty( $attribute_assignments['attributes'] ) ) {
                $product->set_attributes( $attribute_assignments['attributes'] );
            } elseif ( empty( $attribute_assignments['attributes'] ) && $is_new ) {
                $product->set_attributes( array() );
            }

            $product_id = $product->save();

            if ( ! $product_id ) {
                throw new Exception( __( 'Unable to save the WooCommerce product.', 'softone-woocommerce-integration' ) );
            }

            if ( $mtrl ) {
                update_post_meta( $product_id, self::META_MTRL, $mtrl );
            }

            update_post_meta( $product_id, self::META_PAYLOAD_HASH, $payload_hash );

            if ( is_numeric( $run_timestamp ) ) {
                update_post_meta( $product_id, self::META_LAST_SYNC, (int) $run_timestamp );
            }

            foreach ( $attribute_assignments['terms'] as $taxonomy => $term_ids ) {
                wp_set_object_terms( $product_id, $term_ids, $taxonomy );
            }

            foreach ( $attribute_assignments['clear'] as $taxonomy ) {
                wp_set_object_terms( $product_id, array(), $taxonomy );
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

            $action = apply_filters( 'softone_wc_integration_stale_item_action', 'draft' );
            if ( ! in_array( $action, array( 'draft', 'stock_out' ), true ) ) {
                $action = 'draft';
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

            $category_parent = 0;

            $category_slug    = function_exists( 'sanitize_title' ) ? sanitize_title( $category_name ) : '';
            $subcategory_slug = function_exists( 'sanitize_title' ) ? sanitize_title( $subcategory_name ) : '';

            $category_context = array(
                'raw_name'       => $category_name,
                'sanitized_slug' => $category_slug,
                'term_id'        => 0,
                'parent_id'      => 0,
            );

            if (
                '' !== $category_name
                && ! $this->is_numeric_term_name( $category_name )
                && ! $this->is_uncategorized_term( $category_name )
            ) {
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_002 Ensuring top-level category.',
                    $category_context
                );
                $category_parent = $this->ensure_term( $category_name, 'product_cat' );
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_002 Result for top-level category ensure.',
                    array_merge(
                        $category_context,
                        array(
                            'term_id' => $category_parent,
                        )
                    )
                );
                if ( $category_parent ) {
                    $categories[] = $category_parent;
                }
            } else {
                $reason = 'empty_name';

                if ( '' !== $category_name && $this->is_numeric_term_name( $category_name ) ) {
                    $reason = 'numeric_name';
                } elseif ( '' !== $category_name && $this->is_uncategorized_term( $category_name ) ) {
                    $reason = 'uncategorized';
                }

                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_001 Skipping top-level category ensure.',
                    array_merge(
                        $category_context,
                        array(
                            'reason' => $reason,
                        )
                    )
                );
            }

            $subcategory_context = array(
                'raw_name'       => $subcategory_name,
                'sanitized_slug' => $subcategory_slug,
                'term_id'        => 0,
                'parent_id'      => $category_parent,
            );

            if (
                '' !== $subcategory_name
                && ! $this->is_numeric_term_name( $subcategory_name )
                && ! $this->is_uncategorized_term( $subcategory_name )
            ) {
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_005 Ensuring subcategory.',
                    $subcategory_context
                );
                $subcategory_id = $this->ensure_term( $subcategory_name, 'product_cat', $category_parent );
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_005 Result for subcategory ensure.',
                    array_merge(
                        $subcategory_context,
                        array(
                            'term_id' => $subcategory_id,
                        )
                    )
                );
                if ( $subcategory_id ) {
                    $categories[] = $subcategory_id;
                }
            } else {
                $reason = 'empty_name';

                if ( '' !== $subcategory_name && $this->is_numeric_term_name( $subcategory_name ) ) {
                    $reason = 'numeric_name';
                } elseif ( '' !== $subcategory_name && $this->is_uncategorized_term( $subcategory_name ) ) {
                    $reason = 'uncategorized';
                }

                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_004 Skipping subcategory ensure.',
                    array_merge(
                        $subcategory_context,
                        array(
                            'reason' => $reason,
                        )
                    )
                );
            }

            return array_values( array_unique( array_filter( $categories ) ) );
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
         * @param array       $data    Normalised data.
         * @param WC_Product  $product Product instance.
         *
         * @return array{
         *     attributes: array<string, WC_Product_Attribute>,
         *     terms: array<string, array<int>>,
         *     clear: array<string>
         * }
         */
        protected function prepare_attribute_assignments( array $data, $product ) {
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
                'clear'      => array(),
            );

            $attribute_map = array(
                'colour' => array(
                    'label'    => __( 'Colour', 'softone-woocommerce-integration' ),
                    'value'    => trim( $this->get_value( $data, array( 'colour_name', 'color_name', 'colour' ) ) ),
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
            }

            return $assignments;
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

                return (int) $this->attribute_taxonomy_cache[ $key ];
            }

            $this->cache_stats['attribute_taxonomy_cache_misses']++;

            $attribute_id = wc_attribute_taxonomy_id_by_name( $slug );

            if ( $attribute_id ) {
                $attribute_id = (int) $attribute_id;
                $this->attribute_taxonomy_cache[ $key ] = $attribute_id;

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

            $taxonomy = wc_attribute_taxonomy_name( $slug );

            if ( ! taxonomy_exists( $taxonomy ) ) {
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
            }

            $this->attribute_taxonomy_cache[ $key ] = $attribute_id;

            return $attribute_id;
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

            if ( '' === $name ) {
                return false;
            }

            return (bool) preg_match( '/^\d+$/', $name );
        }

        /**
         * Determine whether a term name refers to the default uncategorised product category.
         *
         * @param string $name Term name.
         *
         * @return bool
         */
        protected function is_uncategorized_term( $name ) {
            $name = trim( (string) $name );

            if ( '' === $name ) {
                return false;
            }

            $sanitized_name = function_exists( 'sanitize_title' ) ? sanitize_title( $name ) : '';

            if ( 'uncategorized' === $sanitized_name ) {
                return true;
            }

            $default_category_id = (int) get_option( 'default_product_cat', 0 );
            if ( $default_category_id <= 0 ) {
                return false;
            }

            $default_category = get_term( $default_category_id, 'product_cat' );
            if ( $default_category instanceof WP_Term ) {
                if ( 'uncategorized' === $default_category->slug ) {
                    return true;
                }

                if ( '' !== $sanitized_name && $sanitized_name === $default_category->slug ) {
                    return true;
                }

                return 0 === strcasecmp( $name, $default_category->name );
            }

            if ( is_wp_error( $default_category ) ) {
                $this->log( 'debug', 'Failed to fetch default product category.', array( 'error' => $default_category ) );
            }

            return false;
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
                $this->log(
                    'error',
                    'SOFTONE_CAT_SYNC_003 ' . $result->get_error_message(),
                    array(
                        'taxonomy'  => $tax,
                        'parent_id' => $parent,
                        'cache_key' => $key,
                    )
                );

                $this->term_cache[ $key ] = 0;

                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_008 Term creation failed; returning empty identifier.',
                    array(
                        'taxonomy'  => $tax,
                        'parent_id' => $parent,
                        'cache_key' => $key,
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
    }
}
