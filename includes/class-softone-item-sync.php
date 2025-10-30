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
         * Constructor.
         *
         * @param Softone_API_Client|null           $api_client API client instance.
         * @param WC_Logger|Psr\Log\LoggerInterface|null $logger Optional logger instance.
         */
        public function __construct( ?Softone_API_Client $api_client = null, $logger = null ) {
            $this->api_client = $api_client ?: new Softone_API_Client();
            $this->logger     = $logger ?: $this->get_default_logger();
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
         *     started_at:int
         * }
         */
        public function sync( $force_full_import = null ) {
            if ( ! class_exists( 'WC_Product' ) ) {
                throw new Exception( __( 'WooCommerce is required to sync items.', 'softone-woocommerce-integration' ) );
            }

            $started_at = time();
            $last_run   = (int) get_option( self::OPTION_LAST_RUN );

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
                    $result     = $this->import_row( $normalized );

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
         * @param array $data Normalised row data.
         *
         * @throws Exception When the row cannot be imported.
         *
         * @return string One of created, updated or skipped.
         */
        protected function import_row( array $data ) {
            $mtrl = isset( $data['mtrl'] ) ? (string) $data['mtrl'] : '';
            $sku  = $this->determine_sku( $data );

            if ( '' === $mtrl && '' === $sku ) {
                throw new Exception( __( 'Unable to determine a product identifier for the imported row.', 'softone-woocommerce-integration' ) );
            }

            $product_id = $this->find_existing_product( $sku, $mtrl );
            $is_new     = 0 === $product_id;

            if ( $is_new ) {
                $product = new WC_Product_Simple();
                $product->set_status( 'publish' );
            } else {
                $product = wc_get_product( $product_id );
            }

            if ( ! $product ) {
                throw new Exception( __( 'Failed to load the matching WooCommerce product.', 'softone-woocommerce-integration' ) );
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

            foreach ( $attribute_assignments['terms'] as $taxonomy => $term_ids ) {
                wp_set_object_terms( $product_id, $term_ids, $taxonomy );
            }

            foreach ( $attribute_assignments['clear'] as $taxonomy ) {
                wp_set_object_terms( $product_id, array(), $taxonomy );
            }

            return $is_new ? 'created' : 'updated';
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

            $attribute_id = wc_attribute_taxonomy_id_by_name( $slug );

            if ( $attribute_id ) {
                return (int) $attribute_id;
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

            $term = get_term_by( 'name', $value, $taxonomy );

            if ( $term && ! is_wp_error( $term ) ) {
                return (int) $term->term_id;
            }

            $result = wp_insert_term( $value, $taxonomy );

            if ( is_wp_error( $result ) ) {
                $this->log( 'error', $result->get_error_message(), array( 'taxonomy' => $taxonomy ) );

                return 0;
            }

            return (int) $result['term_id'];
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

            $term = term_exists( $name, $tax, $parent );

            if ( is_array( $term ) && isset( $term['term_id'] ) ) {
                return (int) $term['term_id'];
            }

            if ( $term ) {
                return (int) $term;
            }

            $args = array();

            if ( $parent ) {
                $args['parent'] = (int) $parent;
            }

            $result = wp_insert_term( $name, $tax, $args );

            if ( is_wp_error( $result ) ) {
                $this->log( 'error', $result->get_error_message(), array( 'taxonomy' => $tax ) );

                return 0;
            }

            return (int) $result['term_id'];
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
