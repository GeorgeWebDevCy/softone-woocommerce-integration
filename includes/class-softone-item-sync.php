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

            if ( empty( $extra ) ) {
                $response = $this->api_client->sql_data( 'getItems' );
            } else {
                $response = $this->api_client->sql_data( 'getItems', array(), $extra );
            }
            $rows     = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array();

            $stats = array(
                'processed'  => 0,
                'created'    => 0,
                'updated'    => 0,
                'skipped'    => 0,
                'started_at' => $started_at,
            );

            foreach ( $rows as $row ) {
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

            $description = $this->get_value( $data, array( 'remarks', 'remark', 'notes' ) );
            if ( '' !== $description ) {
                $product->set_description( $description );
            }

            $price = $this->get_value( $data, array( 'retailprice' ) );
            if ( '' !== $price ) {
                $product->set_regular_price( wc_format_decimal( $price ) );
            }

            $product->set_sku( $sku );

            $stock_quantity = $this->get_value( $data, array( 'stock_qty', 'qty1' ) );
            if ( '' !== $stock_quantity ) {
                $stock_amount = wc_stock_amount( $stock_quantity );
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $stock_amount );
                $product->set_stock_status( $stock_amount > 0 ? 'instock' : 'outofstock' );
            }

            $category_ids = $this->prepare_category_ids( $data );
            if ( ! empty( $category_ids ) ) {
                $product->set_category_ids( $category_ids );
            }

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

            $category_name    = $this->get_value( $data, array( 'commercategory_name', 'commercategory', 'category_name' ) );
            $subcategory_name = $this->get_value( $data, array( 'submecategory_name', 'subcategory_name', 'subcategory' ) );

            $category_parent = 0;

            if ( '' !== $category_name ) {
                $category_parent = $this->ensure_term( $category_name, 'product_cat' );
                if ( $category_parent ) {
                    $categories[] = $category_parent;
                }
            }

            if ( '' !== $subcategory_name ) {
                $subcategory_id = $this->ensure_term( $subcategory_name, 'product_cat', $category_parent );
                if ( $subcategory_id ) {
                    $categories[] = $subcategory_id;
                }
            }

            return array_values( array_unique( array_filter( $categories ) ) );
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
                    'value'    => $this->get_value( $data, array( 'colour_name', 'color_name', 'colour' ) ),
                    'position' => 0,
                ),
                'size'   => array(
                    'label'    => __( 'Size', 'softone-woocommerce-integration' ),
                    'value'    => $this->get_value( $data, array( 'size_name', 'size' ) ),
                    'position' => 1,
                ),
                'brand'  => array(
                    'label'    => __( 'Brand', 'softone-woocommerce-integration' ),
                    'value'    => $this->get_value( $data, array( 'brand_name', 'brand' ) ),
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
         * Ensure a term exists in a taxonomy, optionally nested.
         *
         * @param string $name   Term name.
         * @param string $tax    Taxonomy.
         * @param int    $parent Optional parent term ID.
         *
         * @return int Term identifier.
         */
        protected function ensure_term( $name, $tax, $parent = 0 ) {
            $name = trim( (string) $name );

            if ( '' === $name ) {
                return 0;
            }

            $key = $this->build_term_cache_key( $tax, $name, $parent );

            if ( array_key_exists( $key, $this->term_cache ) ) {
                $this->cache_stats['term_cache_hits']++;

                return (int) $this->term_cache[ $key ];
            }

            $this->cache_stats['term_cache_misses']++;

            $term = term_exists( $name, $tax, $parent );

            if ( is_array( $term ) && isset( $term['term_id'] ) ) {
                $term_id                    = (int) $term['term_id'];
                $this->term_cache[ $key ]   = $term_id;

                return $term_id;
            }

            if ( $term ) {
                $term_id                  = (int) $term;
                $this->term_cache[ $key ] = $term_id;

                return $term_id;
            }

            $args = array();

            if ( $parent ) {
                $args['parent'] = (int) $parent;
            }

            $result = wp_insert_term( $name, $tax, $args );

            if ( is_wp_error( $result ) ) {
                $this->log( 'error', $result->get_error_message(), array( 'taxonomy' => $tax ) );

                $this->term_cache[ $key ] = 0;

                return 0;
            }

            $term_id = (int) $result['term_id'];
            $this->cache_stats['term_created']++;
            $this->term_cache[ $key ] = $term_id;

            return $term_id;
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
