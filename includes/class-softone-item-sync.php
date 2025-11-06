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

        /** @var Softone_API_Client */
        protected $api_client;

        /** @var WC_Logger|Psr\Log\LoggerInterface|null */
        protected $logger;

        /** @var Softone_Category_Sync_Logger|object|null */
        protected $category_logger;

        /** @var Softone_Sync_Activity_Logger|null */
        protected $activity_logger;

        /** @var array<string,int> */
        protected $term_cache = array();

        /** @var array<string,int> */
        protected $attribute_term_cache = array();

        /** @var array<string,int> */
        protected $attribute_taxonomy_cache = array();

        /** @var array<string,int> */
        protected $cache_stats = array();

        /** @var bool */
        protected $force_taxonomy_refresh = false;

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

        /** @return void */
        public function register_hooks( Softone_Woocommerce_Integration_Loader $loader ) {
            $loader->add_action( 'init', $this, 'maybe_schedule_cron' );
            $loader->add_action( self::CRON_HOOK, $this, 'handle_scheduled_sync', 10, 0 );
        }

        /** @return void */
        public function maybe_schedule_cron() {
            self::schedule_event();
        }

        /** @return void */
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

        /** @return void */
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

        /** @return void */
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
         * @throws Softone_API_Client_Exception
         * @throws Exception
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
                $collected_rows             = array();
                $related_parent_candidates  = array();

                foreach ( $this->yield_item_rows( $extra ) as $row ) {
                    $stats['processed']++;

                    try {
                        $normalized    = $this->normalize_row( $row );
                        $group_context = $this->prepare_group_context( $normalized );

                        $collected_rows[] = array(
                            'data'    => $normalized,
                            'context' => $group_context,
                        );
                    } catch ( Exception $exception ) {
                        $stats['skipped']++;
                        $this->log(
                            'error',
                            $exception->getMessage(),
                            $this->extend_log_context_with_item(
                                array( 'exception' => $exception ),
                                $this->get_item_log_context( is_array( $row ) ? $row : array() )
                            )
                        );
                    }
                }

                foreach ( $collected_rows as $entry ) {
                    $row_data      = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
                    $related_mtrl  = $this->extract_related_parent_mtrl( $row_data );
                    $softone_mtrl  = $this->extract_softone_mtrl( $row_data );
                    $child_mtrls   = $this->extract_related_item_mtrls_from_row( $row_data );

                    if ( '' !== $related_mtrl ) {
                        $related_parent_candidates[ $related_mtrl ] = true;
                    }

                    if ( ! empty( $child_mtrls ) && '' !== $softone_mtrl ) {
                        $related_parent_candidates[ $softone_mtrl ] = true;
                    }
                }

                $group_buckets     = array();
                $standalone_groups = array();

                foreach ( $collected_rows as $index => $entry ) {
                    $row_data      = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
                    $context       = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
                    $group_key     = isset( $context['group_key'] ) ? (string) $context['group_key'] : '';
                    $softone_mtrl  = $this->extract_softone_mtrl( $row_data );
                    $related_mtrl  = $this->extract_related_parent_mtrl( $row_data );
                    $child_mtrls   = $this->extract_related_item_mtrls_from_row( $row_data );

                    if ( '' !== $related_mtrl ) {
                        $group_key = $this->build_related_group_key( $related_mtrl );
                    } elseif ( '' !== $softone_mtrl && isset( $related_parent_candidates[ $softone_mtrl ] ) ) {
                        $group_key = $this->build_related_group_key( $softone_mtrl );
                    }

                    $collected_rows[ $index ]['context']['group_key'] = $group_key;

                    if ( '' === $group_key ) {
                        $standalone_groups[] = array(
                            'rows'    => array( $collected_rows[ $index ] ),
                            'context' => $collected_rows[ $index ]['context'],
                        );
                        continue;
                    }

                    if ( ! isset( $group_buckets[ $group_key ] ) ) {
                        $group_buckets[ $group_key ] = array(
                            'parents'     => array(),
                            'children'    => array(),
                            'context'     => $collected_rows[ $index ]['context'],
                            'child_mtrls' => array(),
                        );
                    }

                    if ( '' !== $related_mtrl ) {
                        $group_buckets[ $group_key ]['children'][] = $collected_rows[ $index ];
                        if ( '' !== $softone_mtrl ) {
                            $group_buckets[ $group_key ]['child_mtrls'][ $softone_mtrl ] = true;
                        }
                    } else {
                        $group_buckets[ $group_key ]['parents'][] = $collected_rows[ $index ];
                        $group_buckets[ $group_key ]['context']   = $collected_rows[ $index ]['context'];
                    }

                    if ( ! empty( $child_mtrls ) ) {
                        foreach ( $child_mtrls as $child_mtrl ) {
                            if ( '' !== $child_mtrl ) {
                                $group_buckets[ $group_key ]['child_mtrls'][ $child_mtrl ] = true;
                            }
                        }
                    }
                }

                $groups_to_process = $standalone_groups;

                foreach ( $group_buckets as $bucket ) {
                    $group_rows = array();
                    $child_list = array();

                    if ( ! empty( $bucket['child_mtrls'] ) ) {
                        $child_list = array_keys( $bucket['child_mtrls'] );
                        sort( $child_list );
                    }

                    foreach ( $bucket['parents'] as $parent_row ) {
                        if ( ! empty( $child_list ) ) {
                            $parent_row['data']['related_item_mtrls'] = $child_list;
                        }

                        $group_rows[] = $parent_row;
                    }

                    foreach ( $bucket['children'] as $idx => $child_row ) {
                        if ( empty( $bucket['parents'] ) && 0 === (int) $idx && ! empty( $child_list ) ) {
                            $child_row['data']['related_item_mtrls'] = $child_list;
                        }

                        $group_rows[] = $child_row;
                    }

                    if ( empty( $group_rows ) ) {
                        continue;
                    }

                    $groups_to_process[] = array(
                        'rows'    => $group_rows,
                        'context' => $bucket['context'],
                    );
                }

                foreach ( $groups_to_process as $group_payload ) {
                    if ( empty( $group_payload['rows'] ) ) {
                        continue;
                    }

                    $item_context = isset( $group_payload['rows'][0]['context']['item_context'] )
                        ? (array) $group_payload['rows'][0]['context']['item_context']
                        : $this->get_item_log_context( $group_payload['rows'][0]['data'] );

                    try {
                        $result = $this->import_grouped_rows( $group_payload['rows'], $started_at );

                        if ( 'created' === $result ) {
                            $stats['created']++;
                        } elseif ( 'updated' === $result ) {
                            $stats['updated']++;
                        } else {
                            $stats['skipped']++;
                        }
                    } catch ( Exception $exception ) {
                        $stats['skipped']++;
                        $this->log(
                            'error',
                            $exception->getMessage(),
                            $this->extend_log_context_with_item(
                                array( 'exception' => $exception ),
                                $item_context
                            )
                        );
                    }
                }

                $stale_processed = $this->handle_stale_products( $started_at );

                if ( $stale_processed > 0 ) {
                    $stats['stale_processed'] = $stale_processed;
                }

                $this->log( 'debug', 'Softone item sync cache usage summary.', array( 'cache_stats' => $this->cache_stats ) );

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

        /** @return void */
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

        /** @return int */
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
                case 'm':
                    $number *= 1024;
                case 'k':
                    $number *= 1024;
                    break;
            }

            return (int) round( $number );
        }

        /**
         * @param array $extra
         * @return Generator<int, array<string, mixed>>
         */
        protected function yield_item_rows( array $extra ) {
            $default_page_size  = 250;
            $filtered_page_size = (int) apply_filters( 'softone_wc_integration_item_sync_page_size', $default_page_size );
            $page_size          = $filtered_page_size > 0 ? $filtered_page_size : $default_page_size;

            $max_pages     = (int) apply_filters( 'softone_wc_integration_item_sync_max_pages', 0 );
            $page          = 1;
            $previous_hash = array();

            while ( true ) {
                $page_extra          = $extra;
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
                        array( 'page' => $page, 'page_size' => $page_size )
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

        /** @return string */
        protected function hash_item_rows( array $rows ) {
            $context = hash_init( 'md5' );
            $this->hash_append_value( $context, $rows );
            return hash_final( $context );
        }

        /** @return void */
        protected function hash_append_value( $context, $value ) {
            if ( is_array( $value ) ) {
                $keys       = array_keys( $value );
                $item_count = count( $value );
                $is_list    = 0 === $item_count || $keys === range( 0, $item_count - 1 );

                if ( $is_list ) {
                    hash_update( $context, '[' );
                    $is_first = true;
                    foreach ( $value as $item ) {
                        if ( $is_first ) { $is_first = false; } else { hash_update( $context, ',' ); }
                        $this->hash_append_value( $context, $item );
                    }
                    hash_update( $context, ']' );
                    return;
                }

                hash_update( $context, '{' );
                $is_first = true;
                foreach ( $value as $key => $item ) {
                    if ( $is_first ) { $is_first = false; } else { hash_update( $context, ',' ); }
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

        /** @return string */
        protected function encode_json_fragment( $value ) {
            $encoded = wp_json_encode( $value );
            if ( false === $encoded ) { $encoded = json_encode( $value ); }
            if ( false === $encoded ) { $encoded = ''; }
            return (string) $encoded;
        }

        /** @return array */
        protected function normalize_row( array $row ) {
            $normalized = array();
            foreach ( $row as $key => $value ) {
                $normalized_key = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '_', (string) $key ) );
                $normalized_key = trim( $normalized_key, '_' );
                if ( '' === $normalized_key ) { continue; }
                $normalized[ $normalized_key ] = $value;
            }
            return $normalized;
        }

        /**
         * Group SoftOne rows into a single parent payload and import it.
         *
         * @param array $group_rows   Rows sharing the same parent grouping context.
         * @param int   $run_timestamp Current sync timestamp.
         *
         * @throws Exception
         * @return string created|updated|skipped
         */
        protected function import_grouped_rows( array $group_rows, $run_timestamp ) {
            if ( empty( $group_rows ) ) {
                return 'skipped';
            }

            $primary_row = $group_rows[0];
            if ( empty( $primary_row['data'] ) || ! is_array( $primary_row['data'] ) ) {
                return 'skipped';
            }

            $parent_payload   = $primary_row['data'];
            $colour_values    = array();
            $colour_seen      = array();
            $variation_records = array();

            foreach ( $group_rows as $entry ) {
                if ( empty( $entry['data'] ) || ! is_array( $entry['data'] ) ) {
                    continue;
                }

                $row     = $entry['data'];
                $context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

                $colour = isset( $context['colour'] ) ? (string) $context['colour'] : '';
                if ( '' === $colour && isset( $context['derived_colour'] ) ) {
                    $colour = (string) $context['derived_colour'];
                }
                $colour = $this->normalize_colour_value( $colour );

                $colour_key = '' !== $colour ? strtolower( $colour ) : '';
                if ( '' !== $colour_key && ! isset( $colour_seen[ $colour_key ] ) ) {
                    $colour_values[]          = $colour;
                    $colour_seen[ $colour_key ] = true;
                }

                $variation_key = ( '' !== $colour_key )
                    ? $colour_key
                    : 'colourless_' . md5( wp_json_encode( array( $row, $context ) ) );

                $regular_price_raw = $this->get_value( $row, array( 'retailprice' ) );
                $regular_price     = '' !== $regular_price_raw ? wc_format_decimal( $regular_price_raw ) : null;

                $variation_records[ $variation_key ] = array(
                    'colour_label'  => $colour,
                    'sku'           => $this->determine_sku( $row ),
                    'regular_price' => $regular_price,
                    'stock_profile' => $this->build_stock_profile_from_data( $row ),
                );
            }

            if ( ! empty( $variation_records ) ) {
                $parent_payload['__group_variations'] = array_values( $variation_records );

                if ( ! empty( $colour_values ) ) {
                    $parent_payload['__colour_values'] = array_values( $colour_values );
                }

                $group_context = isset( $primary_row['context'] ) && is_array( $primary_row['context'] )
                    ? $primary_row['context']
                    : array();
                $parent_code   = isset( $group_context['code'] ) ? (string) $group_context['code'] : '';
                if ( '' === $parent_code && isset( $parent_payload['code'] ) ) {
                    $parent_code = (string) $parent_payload['code'];
                }
                if ( '' !== $parent_code ) {
                    $parent_payload['__parent_sku_override'] = $parent_code;
                }
            }

            return $this->import_row( $parent_payload, $run_timestamp );
        }

        /**
         * Build the grouping context for a normalised SoftOne row.
         *
         * @param array $data Normalised row data.
         * @return array
         */
        protected function prepare_group_context( array $data ) {
            $name_source     = $this->get_value( $data, array( 'varchar02', 'desc', 'description', 'code' ) );
            $clean_name      = $name_source;
            $derived_colour  = '';
            if ( '' !== $name_source ) {
                list( $maybe_clean_name, $maybe_colour ) = $this->split_product_name_and_colour( $name_source );
                if ( '' !== $maybe_clean_name ) {
                    $clean_name = $maybe_clean_name;
                }
                if ( '' !== $maybe_colour ) {
                    $derived_colour = $maybe_colour;
                }
            }

            $explicit_colour = $this->normalize_colour_value(
                $this->get_value( $data, array( 'colour_name', 'color_name', 'colour', 'color' ) )
            );
            $final_colour = '' !== $explicit_colour ? $explicit_colour : $derived_colour;

            $brand = trim( $this->get_value( $data, array( 'brand_name', 'brand' ) ) );
            $code  = trim( $this->get_value( $data, array( 'code' ) ) );

            return array(
                'group_key'      => $this->build_parent_group_key( $brand, $clean_name, $code ),
                'clean_name'     => $clean_name,
                'colour'         => $final_colour,
                'derived_colour' => $derived_colour,
                'brand'          => $brand,
                'code'           => $code,
                'item_context'   => $this->get_item_log_context( $data ),
            );
        }

        /**
         * Construct a stable key for a group of SoftOne rows representing the same parent product.
         *
         * @param string $brand
         * @param string $name
         * @param string $code
         * @return string
         */
        protected function build_parent_group_key( $brand, $name, $code ) {
            $brand_component = $this->normalise_group_component( $brand );
            $name_component  = $this->normalise_group_component( $name );
            $code_component  = $this->normalise_group_component( $code );

            if ( '' === $brand_component && '' === $name_component && '' === $code_component ) {
                return '';
            }

            return md5( implode( '|', array( $brand_component, $name_component, $code_component ) ) );
        }

        /**
         * Build a grouping key for Softone relations based on a parent MTRL value.
         *
         * @param string $mtrl
         * @return string
         */
        protected function build_related_group_key( $mtrl ) {
            $mtrl = strtolower( trim( (string) $mtrl ) );
            if ( '' === $mtrl ) {
                return '';
            }

            return 'related:' . md5( $mtrl );
        }

        /**
         * Extract the Softone MTRL identifier from a normalised row.
         *
         * @param array $data
         * @return string
         */
        protected function extract_softone_mtrl( array $data ) {
            return trim( (string) $this->get_value( $data, array( 'mtrl', 'MTRL', 'mtrl_code', 'MTRL_CODE' ) ) );
        }

        /**
         * Extract the related parent Softone MTRL from a normalised row.
         *
         * @param array $data
         * @return string
         */
        protected function extract_related_parent_mtrl( array $data ) {
            return trim( (string) $this->get_value( $data, array( 'related_item_mtrl', 'related_mtrl', 'rel_mtrl' ) ) );
        }

        /**
         * Extract declared related Softone MTRL identifiers from a row.
         *
         * @param array $data
         * @return array<int, string>
         */
        protected function extract_related_item_mtrls_from_row( array $data ) {
            $values = array();

            if ( isset( $data['related_item_mtrls'] ) ) {
                $values = $this->normalise_related_item_mtrl_list( $data['related_item_mtrls'] );
            }

            return $values;
        }

        /**
         * Normalise a list of related Softone MTRL identifiers.
         *
         * @param mixed $raw
         * @return array<int, string>
         */
        protected function normalise_related_item_mtrl_list( $raw ) {
            $normalized = array();

            if ( is_array( $raw ) ) {
                $candidates = $raw;
            } else {
                $raw        = trim( (string) $raw );
                $candidates = ( '' === $raw ) ? array() : preg_split( '/[\s,|;]+/', $raw );
            }

            foreach ( (array) $candidates as $candidate ) {
                $candidate = trim( (string) $candidate );
                if ( '' === $candidate ) {
                    continue;
                }

                $normalized[ $candidate ] = $candidate;
            }

            return array_values( $normalized );
        }

        /**
         * Normalise a value for grouping purposes.
         *
         * @param string $value
         * @return string
         */
        protected function normalise_group_component( $value ) {
            $value = strtolower( trim( (string) $value ) );
            $value = preg_replace( '/\s+/', ' ', $value );
            return (string) $value;
        }

        /**
         * Create a stock profile array from the SoftOne payload.
         *
         * @param array $data
         * @return array
         */
        protected function build_stock_profile_from_data( array $data ) {
            $profile = array(
                'manage_stock'   => false,
                'stock_quantity' => null,
                'backorders'     => 'no',
                'stock_status'   => 'instock',
            );

            $stock_quantity = $this->get_value( $data, array( 'stock_qty', 'qty1' ) );
            if ( '' === $stock_quantity ) {
                return $profile;
            }

            $stock_amount = wc_stock_amount( $stock_quantity );
            if ( 0 === $stock_amount && softone_wc_integration_should_force_minimum_stock() ) {
                $stock_amount = 1;
            }

            $profile['manage_stock']   = true;
            $profile['stock_quantity'] = $stock_amount;

            $should_backorder = softone_wc_integration_should_backorder_out_of_stock();

            if ( $should_backorder && $stock_amount <= 0 ) {
                $profile['backorders']   = 'notify';
                $profile['stock_status'] = 'onbackorder';
            } else {
                $profile['backorders']   = 'no';
                $profile['stock_status'] = ( $stock_amount > 0 ) ? 'instock' : 'outofstock';
            }

            return $profile;
        }

/**
 * @throws Exception
 * @return string created|updated|skipped
 */
        protected function import_row( array $data, $run_timestamp ) {
            $group_variations    = array();
            $colour_value_overrides = array();
            $parent_sku_override = '';

            if ( isset( $data['__group_variations'] ) && is_array( $data['__group_variations'] ) ) {
                $group_variations = $data['__group_variations'];
            }

            if ( isset( $data['__colour_values'] ) && is_array( $data['__colour_values'] ) ) {
                $colour_value_overrides = $data['__colour_values'];
            }

            if ( isset( $data['__parent_sku_override'] ) ) {
                $parent_sku_override = (string) $data['__parent_sku_override'];
            }

            $mtrl          = isset( $data['mtrl'] ) ? (string) $data['mtrl'] : '';
            $sku_requested = '' !== $parent_sku_override
                ? $parent_sku_override
                : $this->determine_sku( $data );

            if ( '' === $mtrl && '' === $sku_requested ) {
                throw new Exception( __( 'Unable to determine a product identifier for the imported row.', 'softone-woocommerce-integration' ) );
            }

    // DE-DUPE: if the SKU already exists on ANY product, we will update THAT product (no duplicate products)
    $existing_by_sku_id = 0;
    if ( '' !== $sku_requested && function_exists( 'wc_get_product_id_by_sku' ) ) {
        $existing_by_sku_id = (int) wc_get_product_id_by_sku( $sku_requested );
    }

    // Existing by our usual lookup (prefers SKU first now, then MTRL)
    $product_id = $this->find_existing_product( $sku_requested, $mtrl );

    // If find_existing_product() didn’t find it but a SKU owner exists, use the SKU owner
    if ( 0 === $product_id && $existing_by_sku_id > 0 ) {
        $product_id = $existing_by_sku_id;
    }

    $is_new = ( 0 === $product_id );

    $hash_source  = $data;
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

    $regular_price_value = null;
    $stock_profile       = array(
        'manage_stock'   => false,
        'stock_quantity' => null,
        'backorders'     => 'no',
        'stock_status'   => 'instock',
    );

    $category_ids = $this->prepare_category_ids( $data );

    if ( ! $is_new ) {
        $existing_hash    = (string) get_post_meta( $product_id, self::META_PAYLOAD_HASH, true );
        $categories_match = $this->product_categories_match( $product_id, $category_ids );

        if ( ! $this->force_taxonomy_refresh && '' !== $existing_hash && $existing_hash === $payload_hash ) {
            if ( $categories_match ) {
                $this->log(
                    'debug',
                    'Skipping product import because the payload hash matches the existing product.',
                    array(
                        'product_id'             => $product_id,
                        'sku'                    => $sku_requested,
                        'mtrl'                   => $mtrl,
                        'payload_hash'           => $payload_hash,
                        'existing_hash'          => $existing_hash,
                        'category_ids'           => $category_ids,
                        'categories_match'       => true,
                        'force_taxonomy_refresh' => (bool) $this->force_taxonomy_refresh,
                    )
                );
                return 'skipped';
            }

            $this->log(
                'debug',
                'Continuing product import to refresh mismatched category assignments despite a matching payload hash.',
                array(
                    'product_id'             => $product_id,
                    'sku'                    => $sku_requested,
                    'mtrl'                   => $mtrl,
                    'payload_hash'           => $payload_hash,
                    'existing_hash'          => $existing_hash,
                    'category_ids'           => $category_ids,
                    'categories_match'       => false,
                    'force_taxonomy_refresh' => (bool) $this->force_taxonomy_refresh,
                )
            );
        }
    }

    // ---------- PRODUCT NAME ----------
    $name              = $this->get_value( $data, array( 'varchar02','desc', 'description', 'code' ) );
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

            if ( empty( $fallback_metadata['colour'] ) && ! empty( $colour_value_overrides ) ) {
                $first_override = reset( $colour_value_overrides );
                if ( false !== $first_override ) {
                    $normalized_override             = $this->normalize_colour_value( (string) $first_override );
                    $fallback_metadata['colour'] = $normalized_override;
                }
            }

            // ---------- DESCRIPTIONS ----------
            $description = $this->get_value(
                $data,
                array( 'long_description', 'longdescription', 'cccsocylodes', 'remarks', 'remark', 'notes' )
    );
    if ( '' !== $description ) {
        $product->set_description( $description );
    }

    $short_description = $this->get_value( $data, array( 'short_description', 'cccsocyshdes' ) );
    if ( '' !== $short_description ) {
        $product->set_short_description( $short_description );
    }

    // ---------- PRICE ----------
    $price = $this->get_value( $data, array( 'retailprice' ) );
    if ( '' !== $price ) {
        $regular_price_value = wc_format_decimal( $price );
        $product->set_regular_price( $regular_price_value );
    }

    // ---------- SKU (ensure unique, but if someone else owns it, we UPDATE THAT product) ----------
    $extra_suffixes = array();
    if ( '' !== $derived_colour && function_exists( 'sanitize_title' ) ) {
        $extra_suffixes[] = sanitize_title( $derived_colour );
    }

    // If we’re updating a different product but the SKU belongs to another product, switch to that product to avoid duplicates
    if ( '' !== $sku_requested && $is_new && $existing_by_sku_id > 0 ) {
        $product     = wc_get_product( $existing_by_sku_id );
        $product_id  = $existing_by_sku_id;
        $is_new      = false;
        $this->log( 'info', 'Reusing existing product by SKU to prevent duplication.', array( 'product_id' => $product_id, 'sku' => $sku_requested ) );
    }

    // Now set or adjust SKU on this product
    $effective_sku = $this->ensure_unique_sku(
        $sku_requested,
        $is_new ? 0 : (int) $product_id,
        $extra_suffixes
    );

    if ( '' !== $effective_sku ) {
        $product->set_sku( $effective_sku );
    } else {
        $this->log(
            'warning',
            'SKU left empty after failing to find a unique variant.',
            array(
                'requested_sku' => $sku_requested,
                'product_id'    => $is_new ? 0 : (int) $product_id,
                'suffixes'      => $extra_suffixes,
            )
        );
    }

    // ---------- STOCK ----------
    $stock_quantity = $this->get_value( $data, array( 'stock_qty', 'qty1' ) );
    if ( '' !== $stock_quantity ) {
        $stock_amount = wc_stock_amount( $stock_quantity );
        if ( 0 === $stock_amount && softone_wc_integration_should_force_minimum_stock() ) {
            $stock_amount = 1;
        }

        $stock_profile['manage_stock']   = true;
        $stock_profile['stock_quantity'] = $stock_amount;

        $product->set_manage_stock( true );
        $product->set_stock_quantity( $stock_amount );

        $should_backorder = softone_wc_integration_should_backorder_out_of_stock();

        if ( $should_backorder && $stock_amount <= 0 && method_exists( $product, 'set_backorders' ) ) {
            $product->set_backorders( 'notify' );
            $product->set_stock_status( 'onbackorder' );
            $stock_profile['backorders']   = 'notify';
            $stock_profile['stock_status'] = 'onbackorder';
        } else {
            if ( method_exists( $product, 'set_backorders' ) ) {
                $product->set_backorders( 'no' );
            }
            $stock_profile['backorders']   = 'no';
            $stock_profile['stock_status'] = ( $stock_amount > 0 ) ? 'instock' : 'outofstock';
            $product->set_stock_status( $stock_amount > 0 ? 'instock' : 'outofstock' );
        }
    }

    if ( method_exists( $product, 'get_stock_status' ) ) {
        $current_status = (string) $product->get_stock_status();
        if ( '' !== $current_status ) {
            $stock_profile['stock_status'] = $current_status;
        }
    }

    // ---------- CATEGORIES ----------
    if ( ! empty( $category_ids ) ) {
        $product->set_category_ids( $category_ids );
    }

    // ---------- ATTRIBUTES ----------
    $brand_value           = trim( $this->get_value( $data, array( 'brand_name', 'brand' ) ) );
    $attribute_assignments = $this->prepare_attribute_assignments( $data, $product, $fallback_metadata );
    $variation_taxonomies  = isset( $attribute_assignments['variation_taxonomies'] )
        ? (array) $attribute_assignments['variation_taxonomies']
        : array();
    $should_create_variation = ! empty( $variation_taxonomies );

    if ( ! empty( $attribute_assignments['attributes'] ) ) {
        $product->set_attributes( $attribute_assignments['attributes'] );
    } elseif ( empty( $attribute_assignments['attributes'] ) && $is_new ) {
        $product->set_attributes( array() );
    }

    if ( $should_create_variation && method_exists( $product, 'set_type' ) ) {
        $product->set_type( 'variable' );
    }
    if ( $should_create_variation && method_exists( $product, 'set_manage_stock' ) ) {
        $product->set_manage_stock( false );
        $product->set_stock_quantity( null );
    }
    if ( $should_create_variation && method_exists( $product, 'set_backorders' ) ) {
        $product->set_backorders( 'no' );
    }

    // ---------- SAVE FIRST ----------
    $product_id = $product->save();
    if ( ! $product_id ) {
        throw new Exception( __( 'Unable to save the WooCommerce product.', 'softone-woocommerce-integration' ) );
    }

    if ( $should_create_variation && function_exists( 'wp_set_object_terms' ) ) {
        wp_set_object_terms( (int) $product_id, 'variable', 'product_type' );
    }

    // If we learned MTRL during import, ensure it’s set on reused products too
    if ( $mtrl ) {
        update_post_meta( $product_id, self::META_MTRL, $mtrl );
    }

    // ---------- IMAGES (after save) ----------
    $sku_for_images = ( '' !== $effective_sku ? $effective_sku : $sku_requested );
    if ( ! empty( $sku_for_images ) && class_exists( 'Softone_Sku_Image_Attacher' ) ) {
        \Softone_Sku_Image_Attacher::attach_gallery_from_sku( (int) $product_id, (string) $sku_for_images );
    }

    // ---------- CATEGORY TERMS ----------
    if ( ! empty( $category_ids ) && function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'product_cat' ) && function_exists( 'wp_set_object_terms' ) ) {
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
        }
    }

    // ---------- META ----------
    update_post_meta( $product_id, self::META_PAYLOAD_HASH, $payload_hash );
    if ( is_numeric( $run_timestamp ) ) {
        update_post_meta( $product_id, self::META_LAST_SYNC, (int) $run_timestamp );
    }

    // ---------- ATTRIBUTE TERMS (taxonomies) ----------
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

        wp_set_object_terms( $product_id, $normalized_term_ids, $taxonomy );
    }

            if ( $should_create_variation ) {
                if ( ! empty( $group_variations ) ) {
                    $this->sync_group_variations(
                        $product_id,
                        $product,
                        $attribute_assignments,
                        $group_variations,
                        $regular_price_value
                    );
                } else {
                    $this->sync_single_variation(
                        $product_id,
                        $product,
                        $attribute_assignments,
                        $regular_price_value,
                        $stock_profile
                    );
                }
            }

    // ---------- CLEAR TAXONOMIES ----------
    foreach ( $attribute_assignments['clear'] as $taxonomy ) {
        if ( '' === $taxonomy ) { continue; }
        wp_set_object_terms( $product_id, array(), $taxonomy );
    }

    // ---------- BRAND (attribute + WooCommerce Brands taxonomy) ----------
    $this->assign_brand_term( $product_id, $brand_value );               // attribute pa_brand
    $this->assign_product_brand_term( $product_id, $brand_value );       // taxonomy product_brand

    $action = $is_new ? 'created' : 'updated';
    $this->log(
        'info',
        sprintf( 'Product %s via Softone sync.', $action ),
        array(
            'product_id' => $product_id,
            'sku'        => $sku_for_images,
            'mtrl'       => $mtrl,
            'timestamp'  => $run_timestamp,
        )
    );

    return $action;
}



        /** @return int */
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
                        $this->log( 'warning', 'Unable to load product while marking as stale.', array( 'product_id' => $product_id ) );
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
                    // --- Attach images based on SKU ---
                    if ( ! empty( $sku ) ) {
                    Softone_Sku_Image_Attacher::attach_gallery_from_sku( (int) $product_id, (string) $sku );
                    }
                    update_post_meta( $product_id, self::META_LAST_SYNC, (int) $run_timestamp );

                    $this->log( 'info', 'Marked product as stale following Softone sync run.', array( 'product_id' => $product_id, 'action' => $action ) );
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

        /** @return string */
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
         * Ensure a unique SKU across the catalog.
         *
         * Strategy:
         *  - If $base_sku is unused or used by $current_product_id, keep it.
         *  - Else try `$base_sku-<suffixParts>` if provided (e.g. colour), then increment `-2`, `-3`, ...
         *  - After max attempts, return '' (leave SKU empty, valid in WC).
         *
         * @param string $base_sku
         * @param int    $current_product_id
         * @param array  $extra_suffix_parts e.g. ['red']
         * @return string
         */
        protected function ensure_unique_sku( $base_sku, $current_product_id = 0, array $extra_suffix_parts = array() ) {
            $base_sku = trim( (string) $base_sku );
            if ( '' === $base_sku ) {
                return '';
            }

            // Base free or belongs to the same product?
            if ( ! $this->sku_taken_by_other( $base_sku, (int) $current_product_id ) ) {
                return $base_sku;
            }

            // Try with suffix parts (e.g., colour)
            $suffix = '';
            $parts  = array();
            foreach ( $extra_suffix_parts as $p ) {
                $p = trim( (string) $p );
                if ( '' !== $p ) { $parts[] = $p; }
            }
            if ( ! empty( $parts ) ) {
                $suffix = '-' . implode( '-', $parts );
            }

            $candidate  = $base_sku . $suffix;
            $max_checks = (int) apply_filters( 'softone_wc_integration_sku_unique_attempts', 100 );

            if ( $this->sku_taken_by_other( $candidate, (int) $current_product_id ) ) {
                $idx = 2;
                while ( $this->sku_taken_by_other( $candidate, (int) $current_product_id ) && $idx <= $max_checks ) {
                    $candidate = $base_sku . $suffix . '-' . $idx;
                    $idx++;
                }
            }

            if ( $this->sku_taken_by_other( $candidate, (int) $current_product_id ) ) {
                // Still taken; leave empty.
                return '';
            }

            return $candidate;
        }

        /**
         * Check if SKU belongs to someone else (not the current product).
         *
         * @param string $sku
         * @param int    $current_product_id
         * @return bool
         */
        protected function sku_taken_by_other( $sku, $current_product_id = 0 ) {
            $sku = trim( (string) $sku );
            if ( '' === $sku ) {
                return false;
            }

            $owner_id = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $sku ) : 0;
            if ( ! $owner_id ) {
                return false;
            }

            $current_product_id = (int) $current_product_id;
            return $current_product_id <= 0 || $owner_id !== $current_product_id;
        }
/** @return int */
protected function find_existing_product( $sku, $mtrl ) {
    global $wpdb;

    // 1) Prefer SKU match first to avoid duplicate products when MTRL is missing/empty
    $sku = trim( (string) $sku );
    if ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
        $by_sku = (int) wc_get_product_id_by_sku( $sku );
        if ( $by_sku > 0 ) {
            return $by_sku;
        }
    }

    // 2) Fallback to Softone MTRL meta match
    $mtrl = trim( (string) $mtrl );
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

    return 0;
}


        /** @return bool */
        protected function product_categories_match( $product_id, array $category_ids ) {
            $normalized_target_ids = array();
            foreach ( (array) $category_ids as $category_id ) {
                $category_id = (int) $category_id;
                if ( $category_id > 0 ) {
                    $normalized_target_ids[] = $category_id;
                }
            }
            sort( $normalized_target_ids );
            $normalized_target_ids = array_values( array_unique( $normalized_target_ids ) );

            if ( $product_id <= 0 ) { return false; }
            if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'product_cat' ) ) { return false; }
            if ( ! function_exists( 'wp_get_object_terms' ) ) { return false; }

            $existing_terms = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
            if ( is_wp_error( $existing_terms ) ) { return false; }

            $normalized_existing_ids = array();
            foreach ( (array) $existing_terms as $existing_term_id ) {
                $existing_term_id = (int) $existing_term_id;
                if ( $existing_term_id > 0 ) {
                    $normalized_existing_ids[] = $existing_term_id;
                }
            }
            sort( $normalized_existing_ids );
            $normalized_existing_ids = array_values( array_unique( $normalized_existing_ids ) );

            return $normalized_existing_ids === $normalized_target_ids;
        }

        /**
         * Prepare a list of category IDs from the SoftOne data.
         *
         * @param array $data Normalised data.
         * @return array<int>
         */
        protected function prepare_category_ids( array $data ) {
            $categories = array();

            $category_name = $this->get_value(
                $data,
                array( 'commecategory_name', 'commercategory_name', 'commercategory', 'category_name', 'Category Name', 'Category' )
            );
            $subcategory_name = $this->get_value(
                $data,
                array( 'submecategory_name', 'subcategory_name', 'subcategory', 'Subcategory Name', 'Subcategory' )
            );
            $subsubcategory_name = $this->get_value(
                $data,
                array( 'subsubcategoy_name', 'subsubcategory_name', 'subsubcategory', 'sub_subcategory_name', 'sub_subcategory' )
            );

            $category_parent = 0;

            $category_slug       = function_exists( 'sanitize_title' ) ? sanitize_title( $category_name ) : '';
            $subcategory_slug    = function_exists( 'sanitize_title' ) ? sanitize_title( $subcategory_name ) : '';
            $subsubcategory_slug = function_exists( 'sanitize_title' ) ? sanitize_title( $subsubcategory_name ) : '';

            $category_uncategorized       = $this->evaluate_uncategorized_term( $category_name );
            $subcategory_uncategorized    = $this->evaluate_uncategorized_term( $subcategory_name );
            $subsubcategory_uncategorized = $this->evaluate_uncategorized_term( $subsubcategory_name );

            $item_log_context = array();
            $sku  = isset( $data['sku'] ) ? (string) $data['sku'] : '';
            $mtrl = isset( $data['mtrl'] ) ? (string) $data['mtrl'] : '';
            if ( '' !== $sku )  { $item_log_context['sku']  = $sku; }
            if ( '' !== $mtrl ) { $item_log_context['mtrl'] = $mtrl; }

            // Top-level
            $category_context = array(
                'raw_name'       => $category_name,
                'sanitized_slug' => $category_slug,
                'term_id'        => 0,
                'parent_id'      => 0,
            );
            $category_log_context = $this->extend_log_context_with_item( $category_context, $item_log_context );

            if ( '' !== $category_name && ! $this->is_numeric_term_name( $category_name ) ) {
                $this->log( 'debug', 'SOFTONE_CAT_SYNC_002 Ensuring top-level category.', $category_log_context );
                $category_parent = $this->ensure_term( $category_name, 'product_cat' );
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_002 Result for top-level category ensure.',
                    $this->extend_log_context_with_item(
                        array_merge( $category_context, array( 'term_id' => $category_parent ) ),
                        $item_log_context
                    )
                );
                if ( $category_parent ) { $categories[] = $category_parent; }
            } else {
                $reason = ( '' === $category_name ) ? 'empty_name' : ( $this->is_numeric_term_name( $category_name ) ? 'numeric_name' : 'uncategorized' );
                $skip_context = array_merge( $this->build_uncategorized_log_fields( $category_uncategorized ), array( 'reason' => $reason ) );
                $this->log( 'debug', 'SOFTONE_CAT_SYNC_012 Skipping top-level category ensure.', $this->extend_log_context_with_item( $skip_context, $item_log_context ) );
            }

            // Subcategory
            $subcategory_parent = $category_parent;
            $subcategory_context = array(
                'raw_name'       => $subcategory_name,
                'sanitized_slug' => $subcategory_slug,
                'term_id'        => 0,
                'parent_id'      => $subcategory_parent,
            );
            $subcategory_log_context = $this->extend_log_context_with_item( $subcategory_context, $item_log_context );

            if ( '' !== $subcategory_name && ! $this->is_numeric_term_name( $subcategory_name ) ) {
                $this->log( 'debug', 'SOFTONE_CAT_SYNC_002 Ensuring subcategory.', $subcategory_log_context );
                $subcategory_parent = $this->ensure_term( $subcategory_name, 'product_cat', $subcategory_parent );
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_002 Result for subcategory ensure.',
                    $this->extend_log_context_with_item(
                        array_merge( $subcategory_context, array( 'term_id' => $subcategory_parent ) ),
                        $item_log_context
                    )
                );
                if ( $subcategory_parent ) { $categories[] = $subcategory_parent; }
            } else {
                $reason = ( '' === $subcategory_name ) ? 'empty_name' : ( $this->is_numeric_term_name( $subcategory_name ) ? 'numeric_name' : 'uncategorized' );
                $skip_context = array_merge( $this->build_uncategorized_log_fields( $subcategory_uncategorized ), array( 'reason' => $reason ) );
                $this->log( 'debug', 'SOFTONE_CAT_SYNC_012 Skipping subcategory ensure.', $this->extend_log_context_with_item( $skip_context, $item_log_context ) );
            }

            // Sub-subcategory
            $subsubcategory_parent = $subcategory_parent ?: $category_parent;
            $subsubcategory_context = array(
                'raw_name'       => $subsubcategory_name,
                'sanitized_slug' => $subsubcategory_slug,
                'term_id'        => 0,
                'parent_id'      => $subsubcategory_parent,
            );
            $subsubcategory_log_context = $this->extend_log_context_with_item( $subsubcategory_context, $item_log_context );

            if ( '' !== $subsubcategory_name && ! $this->is_numeric_term_name( $subsubcategory_name ) ) {
                $this->log( 'debug', 'SOFTONE_CAT_SYNC_002 Ensuring sub-subcategory.', $subsubcategory_log_context );
                $subsubcategory_parent = $this->ensure_term( $subsubcategory_name, 'product_cat', $subsubcategory_parent );
                $this->log(
                    'debug',
                    'SOFTONE_CAT_SYNC_002 Result for sub-subcategory ensure.',
                    $this->extend_log_context_with_item(
                        array_merge( $subsubcategory_context, array( 'term_id' => $subsubcategory_parent ) ),
                        $item_log_context
                    )
                );
                if ( $subsubcategory_parent ) { $categories[] = $subsubcategory_parent; }
            } else {
                $reason = ( '' === $subsubcategory_name ) ? 'empty_name' : ( $this->is_numeric_term_name( $subsubcategory_name ) ? 'numeric_name' : 'uncategorized' );
                $skip_context = array_merge( $this->build_uncategorized_log_fields( $subsubcategory_uncategorized ), array( 'reason' => $reason ) );
                $this->log( 'debug', 'SOFTONE_CAT_SYNC_012 Skipping sub-subcategory ensure.', $this->extend_log_context_with_item( $skip_context, $item_log_context ) );
            }

            $categories = array_values( array_unique( array_map( 'intval', array_filter( $categories ) ) ) );
            return $categories;
        }
/**
 * Build attribute assignments for the product.
 *
 * - Adds hidden custom attributes:
 *     - softone_mtrl
 *     - related_item_mtrl
 * - Ensures taxonomy attributes for Colour, Size, Brand (visible on frontend).
 *
 * @param array       $data
 * @param WC_Product  $product
 * @param array       $fallback_attributes e.g. ['colour' => 'Red'].
 *
 * @return array{attributes: array<int,WC_Product_Attribute>, terms: array, values: array, clear: array, variation_taxonomies: array, term_slugs: array}
 */
protected function prepare_attribute_assignments( array $data, $product, array $fallback_attributes = array() ) {
    $assignments = array(
        'attributes'           => array(),
        'terms'                => array(),
        'values'               => array(),
        'clear'                => array(),
        'variation_taxonomies' => array(),
        'term_slugs'           => array(),
    );

    // ---------------- Hidden custom attribute: softone_mtrl ----------------
    $mtrl_value = (string) $this->get_value( $data, array( 'mtrl', 'MTRL', 'mtrl_code', 'MTRL_CODE' ) );
    $mtrl_value = trim( $mtrl_value );
    if ( $mtrl_value !== '' && class_exists( 'WC_Product_Attribute' ) ) {
        try {
            $attr = new WC_Product_Attribute();
            $attr->set_id( 0 );
            $attr->set_name( 'softone_mtrl' );
            $attr->set_options( array( $mtrl_value ) );
            $attr->set_visible( false );
            $attr->set_variation( false );
            $assignments['attributes'][] = $attr;
        } catch ( \Throwable $e ) {
            if ( method_exists( $product, 'get_id' ) ) {
                $pid = (int) $product->get_id();
                if ( $pid > 0 ) {
                    update_post_meta( $pid, self::META_MTRL, $mtrl_value );
                }
            }
        }
    }

    // ---------------- Hidden custom attribute: related_item_mtrl ----------------
    $related_mtrl = (string) $this->get_value( $data, array( 'related_item_mtrl', 'related_mtrl', 'rel_mtrl' ) );
    $related_mtrl = trim( $related_mtrl );
    if ( $related_mtrl !== '' && class_exists( 'WC_Product_Attribute' ) ) {
        try {
            $attr = new WC_Product_Attribute();
            $attr->set_id( 0 );
            $attr->set_name( 'related_item_mtrl' );
            $attr->set_options( array( $related_mtrl ) );
            $attr->set_visible( false );
            $attr->set_variation( false );
            $assignments['attributes'][] = $attr;
        } catch ( \Throwable $e ) {
            if ( method_exists( $product, 'get_id' ) ) {
                $pid = (int) $product->get_id();
                if ( $pid > 0 ) {
                    update_post_meta( $pid, '_softone_related_item_mtrl', $related_mtrl );
                }
            }
        }
    }

    // ---------------- Hidden custom attribute: related_item_mtrls ----------------
    $related_mtrls = array();
    if ( isset( $data['related_item_mtrls'] ) ) {
        $related_mtrls = $this->normalise_related_item_mtrl_list( $data['related_item_mtrls'] );
    }

    if ( ! empty( $related_mtrls ) && class_exists( 'WC_Product_Attribute' ) ) {
        try {
            $attr = new WC_Product_Attribute();
            $attr->set_id( 0 );
            $attr->set_name( 'related_item_mtrls' );
            $attr->set_options( array_values( $related_mtrls ) );
            $attr->set_visible( false );
            $attr->set_variation( false );
            $assignments['attributes'][] = $attr;
        } catch ( \Throwable $e ) {
            if ( method_exists( $product, 'get_id' ) ) {
                $pid = (int) $product->get_id();
                if ( $pid > 0 ) {
                    update_post_meta( $pid, '_softone_related_item_mtrls', array_values( $related_mtrls ) );
                }
            }
        }
    }

    // ---------------- Visible taxonomy attributes: Colour, Size, Brand ----------------
    $colour_values = array();
    if ( isset( $data['__colour_values'] ) && is_array( $data['__colour_values'] ) ) {
        foreach ( $data['__colour_values'] as $value ) {
            $normalized = $this->normalize_colour_value( (string) $value );
            if ( '' !== $normalized ) {
                $colour_values[] = $normalized;
            }
        }
    }

    if ( empty( $colour_values ) && isset( $data['__group_variations'] ) && is_array( $data['__group_variations'] ) ) {
        foreach ( $data['__group_variations'] as $variation_payload ) {
            if ( ! is_array( $variation_payload ) ) {
                continue;
            }

            $label = '';
            if ( isset( $variation_payload['colour_label'] ) ) {
                $label = (string) $variation_payload['colour_label'];
            } elseif ( isset( $variation_payload['color_label'] ) ) {
                $label = (string) $variation_payload['color_label'];
            }

            $normalized = $this->normalize_colour_value( $label );
            if ( '' !== $normalized ) {
                $colour_values[] = $normalized;
            }
        }
    }

    if ( empty( $colour_values ) ) {
        $single_colour = $this->normalize_colour_value(
            trim( $this->get_value( $data, array( 'colour_name', 'color_name', 'colour', 'color' ) ) )
        );
        if ( '' !== $single_colour ) {
            $colour_values[] = $single_colour;
        }
    }

    if ( empty( $colour_values ) && isset( $fallback_attributes['colour'] ) ) {
        $fallback_colour = $this->normalize_colour_value( (string) $fallback_attributes['colour'] );
        if ( '' !== $fallback_colour ) {
            $colour_values[] = $fallback_colour;
        }
    }

    $unique_colour_values = array();
    foreach ( $colour_values as $colour ) {
        $key = strtolower( $colour );
        if ( ! isset( $unique_colour_values[ $key ] ) ) {
            $unique_colour_values[ $key ] = $colour;
        }
    }
    $colour_values = array_values( $unique_colour_values );

    $size_value  = trim( $this->get_value( $data, array( 'size_name', 'size' ) ) );
    $brand_value = trim( $this->get_value( $data, array( 'brand_name', 'brand' ) ) );

    $colour_slug = $this->resolve_colour_attribute_slug();
    if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
        $colour_taxonomy = wc_attribute_taxonomy_name( $colour_slug );
        if ( '' !== $colour_taxonomy ) {
            $this->ensure_attribute_taxonomy( $colour_slug, __( 'Colour', 'softone-woocommerce-integration' ) );
        }
    }

    $attribute_map = array(
        $colour_slug => array(
            'label'        => __( 'Colour', 'softone-woocommerce-integration' ),
            'values'       => $colour_values,
            'position'     => 0,
            'is_variation' => true,
        ),
        'size'       => array(
            'label'        => __( 'Size', 'softone-woocommerce-integration' ),
            'values'       => '' !== $size_value ? array( $size_value ) : array(),
            'position'     => 1,
            'is_variation' => false,
        ),
        'brand'      => array(
            'label'        => __( 'Brand', 'softone-woocommerce-integration' ),
            'values'       => '' !== $brand_value ? array( $brand_value ) : array(),
            'position'     => 2,
            'is_variation' => false,
        ),
    );

    foreach ( $attribute_map as $slug => $config ) {
        $values = array();
        foreach ( (array) $config['values'] as $candidate ) {
            $candidate = trim( (string) $candidate );
            if ( '' !== $candidate ) {
                $values[] = $candidate;
            }
        }

        $taxonomy = function_exists( 'wc_attribute_taxonomy_name' )
            ? wc_attribute_taxonomy_name( $slug )
            : '';

        if ( empty( $values ) ) {
            if ( '' !== $taxonomy ) {
                $assignments['clear'][] = $taxonomy;
            }
            continue;
        }

        $attribute_id = $this->ensure_attribute_taxonomy( $slug, $config['label'] );
        if ( ! $attribute_id ) {
            continue;
        }

        $term_ids   = array();
        $term_slugs = array();
        foreach ( $values as $value ) {
            $term_id = $this->ensure_attribute_term( $taxonomy, $value );
            if ( ! $term_id ) {
                continue;
            }

            $term_ids[] = (int) $term_id;

            if ( function_exists( 'get_term' ) ) {
                $term = get_term( $term_id, $taxonomy );
                if ( $term && ! is_wp_error( $term ) ) {
                    $term_slugs[ $value ] = (string) $term->slug;
                }
            }
        }

        if ( empty( $term_ids ) ) {
            continue;
        }

        if ( class_exists( 'WC_Product_Attribute' ) ) {
            $attr = new WC_Product_Attribute();
            $attr->set_id( (int) $attribute_id );
            $attr->set_name( $taxonomy );
            $attr->set_options( array_map( 'intval', $term_ids ) );
            $attr->set_position( (int) $config['position'] );
            $attr->set_visible( true );

            $is_colour_attribute = ! empty( $config['is_variation'] );
            $attr->set_variation( $is_colour_attribute );

            if ( $is_colour_attribute ) {
                $assignments['variation_taxonomies'][ $taxonomy ] = true;
            }

            $assignments['attributes'][ $taxonomy ] = $attr;
            $assignments['terms'][ $taxonomy ]      = array_map( 'intval', $term_ids );
            $assignments['values'][ $taxonomy ]     = implode( ', ', $values );

            if ( ! empty( $term_slugs ) ) {
                $assignments['term_slugs'][ $taxonomy ] = $term_slugs;
            }
        }
    }

    return $assignments;
}

        /**
         * Synchronise multiple colour-based variations for a parent product.
         *
         * @param int        $product_id            Product identifier.
         * @param WC_Product $product               Parent product instance.
         * @param array      $attribute_assignments Prepared attribute assignments.
         * @param array      $group_variations      Variation payloads derived from SoftOne.
         * @param string|null $default_regular_price Fallback regular price.
         *
         * @return void
         */
        protected function sync_group_variations( $product_id, $product, array $attribute_assignments, array $group_variations, $default_regular_price ) {
            if ( empty( $attribute_assignments['variation_taxonomies'] ) || empty( $group_variations ) ) {
                return;
            }

            if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Variation' ) ) {
                return;
            }

            $variation_taxonomies = array_keys( $attribute_assignments['variation_taxonomies'] );
            if ( empty( $variation_taxonomies ) ) {
                return;
            }

            $variation_taxonomy = (string) reset( $variation_taxonomies );
            if ( '' === $variation_taxonomy ) {
                return;
            }

            $term_slug_map = array();
            if ( isset( $attribute_assignments['term_slugs'][ $variation_taxonomy ] ) && is_array( $attribute_assignments['term_slugs'][ $variation_taxonomy ] ) ) {
                $term_slug_map = $attribute_assignments['term_slugs'][ $variation_taxonomy ];
            }

            $variable_product = wc_get_product( $product_id );
            if ( ! $variable_product ) {
                return;
            }

            if ( method_exists( $variable_product, 'set_type' ) ) {
                $variable_product->set_type( 'variable' );
            }
            if ( method_exists( $variable_product, 'set_manage_stock' ) ) {
                $variable_product->set_manage_stock( false );
                $variable_product->set_stock_quantity( null );
            }

            $existing_variations = array();
            if ( method_exists( $variable_product, 'get_children' ) ) {
                foreach ( (array) $variable_product->get_children() as $child_id ) {
                    $child_id = (int) $child_id;
                    if ( $child_id <= 0 ) {
                        continue;
                    }

                    $child = wc_get_product( $child_id );
                    if ( ! $child ) {
                        continue;
                    }

                    $attribute_slug = '';
                    if ( method_exists( $child, 'get_attribute' ) ) {
                        $attribute_slug = (string) $child->get_attribute( $variation_taxonomy );
                    }
                    if ( '' === $attribute_slug ) {
                        $attributes = $child->get_attributes();
                        if ( isset( $attributes[ 'attribute_' . $variation_taxonomy ] ) ) {
                            $attribute_slug = (string) $attributes[ 'attribute_' . $variation_taxonomy ];
                        }
                    }

                    $existing_variations[ $child_id ] = array(
                        'product' => $child,
                        'slug'    => $attribute_slug,
                        'sku'     => strtolower( (string) $child->get_sku() ),
                    );
                }
            }

            $default_attributes   = array();
            $parent_stock_status  = 'outofstock';
            $variation_position   = 0;

            foreach ( $group_variations as $variation_payload ) {
                if ( ! is_array( $variation_payload ) ) {
                    continue;
                }

                $colour_label = isset( $variation_payload['colour_label'] ) ? (string) $variation_payload['colour_label'] : '';
                if ( '' === $colour_label ) {
                    continue;
                }

                $colour_slug = '';
                if ( ! empty( $term_slug_map ) ) {
                    foreach ( $term_slug_map as $label => $slug ) {
                        if ( 0 === strcasecmp( (string) $label, $colour_label ) ) {
                            $colour_slug = (string) $slug;
                            break;
                        }
                    }
                }

                if ( '' === $colour_slug && function_exists( 'get_term_by' ) ) {
                    $term = get_term_by( 'name', $colour_label, $variation_taxonomy );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $colour_slug = (string) $term->slug;
                    }
                }

                if ( '' === $colour_slug ) {
                    continue;
                }

                $matched_variation_id = 0;
                foreach ( $existing_variations as $existing_id => $existing_data ) {
                    if ( '' !== $existing_data['slug'] && 0 === strcasecmp( $existing_data['slug'], $colour_slug ) ) {
                        $matched_variation_id = (int) $existing_id;
                        break;
                    }
                }

                $base_sku      = isset( $variation_payload['sku'] ) ? (string) $variation_payload['sku'] : '';
                $normalized_sku = strtolower( $base_sku );
                if ( 0 === $matched_variation_id && '' !== $normalized_sku ) {
                    foreach ( $existing_variations as $existing_id => $existing_data ) {
                        if ( '' !== $existing_data['sku'] && $existing_data['sku'] === $normalized_sku ) {
                            $matched_variation_id = (int) $existing_id;
                            break;
                        }
                    }
                }

                $variation = null;
                if ( 0 !== $matched_variation_id && isset( $existing_variations[ $matched_variation_id ]['product'] ) ) {
                    $variation = $existing_variations[ $matched_variation_id ]['product'];
                    unset( $existing_variations[ $matched_variation_id ] );
                } else {
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id( $product_id );
                }

                $status = method_exists( $product, 'get_status' ) ? (string) $product->get_status() : 'publish';
                if ( '' === $status ) {
                    $status = 'publish';
                }
                $variation->set_status( $status );

                $variation->set_attributes( array( 'attribute_' . $variation_taxonomy => $colour_slug ) );

                if ( '' !== $base_sku ) {
                    $variation_id  = $variation->get_id();
                    $effective_sku = $this->ensure_unique_sku(
                        $base_sku,
                        $variation_id ? (int) $variation_id : 0,
                        array( $colour_slug )
                    );
                    if ( '' !== $effective_sku ) {
                        $variation->set_sku( $effective_sku );
                    }
                } else {
                    $variation->set_sku( '' );
                }

                $regular_price = isset( $variation_payload['regular_price'] ) ? $variation_payload['regular_price'] : null;
                if ( null === $regular_price || '' === $regular_price ) {
                    $regular_price = $default_regular_price;
                }
                if ( null !== $regular_price && '' !== $regular_price ) {
                    $variation->set_regular_price( $regular_price );
                }

                if ( isset( $variation_payload['stock_profile'] ) && is_array( $variation_payload['stock_profile'] ) && method_exists( $variation, 'set_manage_stock' ) ) {
                    $profile       = $variation_payload['stock_profile'];
                    $manage_stock  = ! empty( $profile['manage_stock'] );
                    $backorder_val = isset( $profile['backorders'] ) ? (string) $profile['backorders'] : 'no';

                    $variation->set_manage_stock( $manage_stock );
                    if ( $manage_stock ) {
                        $quantity = isset( $profile['stock_quantity'] ) ? (int) $profile['stock_quantity'] : 0;
                        $variation->set_stock_quantity( $quantity );

                        if ( method_exists( $variation, 'set_backorders' ) ) {
                            $variation->set_backorders( $backorder_val );
                        }
                    } else {
                        $variation->set_stock_quantity( null );
                        if ( method_exists( $variation, 'set_backorders' ) ) {
                            $variation->set_backorders( 'no' );
                        }
                    }

                    if ( isset( $profile['stock_status'] ) ) {
                        $variation->set_stock_status( (string) $profile['stock_status'] );
                        if ( in_array( $profile['stock_status'], array( 'instock', 'onbackorder' ), true ) ) {
                            $parent_stock_status = 'instock';
                        }
                    }
                }

                $variation->save();

                if ( 0 === $variation_position && '' !== $colour_slug ) {
                    $default_attributes[ $variation_taxonomy ] = $colour_slug;
                }

                $variation_position++;
            }

            if ( function_exists( 'wp_delete_post' ) ) {
                foreach ( $existing_variations as $existing_data ) {
                    if ( isset( $existing_data['product'] ) && $existing_data['product'] instanceof WC_Product_Variation ) {
                        wp_delete_post( $existing_data['product']->get_id(), true );
                    }
                }
            }

            if ( method_exists( $variable_product, 'set_default_attributes' ) && ! empty( $default_attributes ) ) {
                $variable_product->set_default_attributes( $default_attributes );
            }

            if ( 'instock' !== $parent_stock_status ) {
                $parent_stock_status = 'outofstock';
            }

            if ( method_exists( $variable_product, 'set_stock_status' ) ) {
                $variable_product->set_stock_status( $parent_stock_status );
            }

            if ( method_exists( $variable_product, 'save' ) ) {
                $variable_product->save();
            }

            if ( class_exists( 'WC_Product_Variable' ) && method_exists( 'WC_Product_Variable', 'sync' ) ) {
                \WC_Product_Variable::sync( $product_id );
            }

            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $product_id );
            }
        }

        /**
         * Ensure the product exposes a single variation reflecting the assigned attributes.
         *
         * @param int        $product_id            Product identifier.
         * @param WC_Product $product               Parent product instance.
         * @param array      $attribute_assignments Prepared attribute assignments.
         * @param string|null $regular_price_value  Regular price captured from the payload.
         * @param array      $stock_profile         Normalised stock information.
         *
         * @return void
         */
        protected function sync_single_variation( $product_id, $product, array $attribute_assignments, $regular_price_value, array $stock_profile ) {
            if ( empty( $attribute_assignments['variation_taxonomies'] ) ) {
                return;
            }

    if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Variation' ) ) {
        return;
    }

    $variation_taxonomies = array_keys( $attribute_assignments['variation_taxonomies'] );
    if ( empty( $variation_taxonomies ) ) {
        return;
    }

    $variable_product = wc_get_product( $product_id );
    if ( ! $variable_product ) {
        return;
    }

    if ( method_exists( $variable_product, 'set_type' ) ) {
        $variable_product->set_type( 'variable' );
    }

    $variation_attributes = array();
    $default_attributes   = array();

    foreach ( $variation_taxonomies as $taxonomy ) {
        if ( empty( $attribute_assignments['terms'][ $taxonomy ] ) ) {
            continue;
        }

        $term_id = (int) $attribute_assignments['terms'][ $taxonomy ][0];
        if ( $term_id <= 0 ) {
            continue;
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            continue;
        }

        $variation_attributes[ 'attribute_' . $taxonomy ] = $term->slug;
        $default_attributes[ $taxonomy ]                  = $term->slug;
    }

    if ( empty( $variation_attributes ) ) {
        return;
    }

    $existing_variation_ids = array();
    if ( method_exists( $variable_product, 'get_children' ) ) {
        $existing_variation_ids = array_map( 'intval', (array) $variable_product->get_children() );
    }

    $variation = null;
    if ( ! empty( $existing_variation_ids ) ) {
        $variation = wc_get_product( (int) $existing_variation_ids[0] );
    }

    if ( ! $variation ) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id( $product_id );
    }

    $status = method_exists( $product, 'get_status' ) ? (string) $product->get_status() : 'publish';
    if ( '' === $status ) {
        $status = 'publish';
    }
    $variation->set_status( $status );
    $variation->set_attributes( $variation_attributes );

    if ( null === $regular_price_value ) {
        $regular_price_value = $product->get_regular_price();
    }

    if ( null !== $regular_price_value && '' !== $regular_price_value ) {
        $variation->set_regular_price( $regular_price_value );
    }

    if ( method_exists( $variation, 'set_manage_stock' ) ) {
        $manage_stock = ! empty( $stock_profile['manage_stock'] );
        $variation->set_manage_stock( $manage_stock );

        if ( $manage_stock ) {
            $quantity = isset( $stock_profile['stock_quantity'] ) ? (int) $stock_profile['stock_quantity'] : 0;
            $variation->set_stock_quantity( $quantity );

            if ( method_exists( $variation, 'set_backorders' ) ) {
                $variation->set_backorders(
                    isset( $stock_profile['backorders'] ) ? (string) $stock_profile['backorders'] : 'no'
                );
            }
        } else {
            $variation->set_stock_quantity( null );

            if ( method_exists( $variation, 'set_backorders' ) ) {
                $variation->set_backorders( 'no' );
            }
        }
    }

    if ( isset( $stock_profile['stock_status'] ) ) {
        $variation->set_stock_status( (string) $stock_profile['stock_status'] );
    }

    $variation_id = $variation->save();

    if ( method_exists( $variable_product, 'set_default_attributes' ) ) {
        $variable_product->set_default_attributes( $default_attributes );
    }

    if ( method_exists( $variable_product, 'save' ) ) {
        $variable_product->save();
    }

    if ( class_exists( 'WC_Product_Variable' ) && method_exists( 'WC_Product_Variable', 'sync' ) ) {
        \WC_Product_Variable::sync( $product_id );
    }

    if ( function_exists( 'wc_delete_product_transients' ) ) {
        wc_delete_product_transients( $product_id );
    }
}

/** @return string */
protected function resolve_colour_attribute_slug() {
    return 'colour';
}


        
        /** @return array{0:string,1:string} */
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

        /** @return string */
        protected function normalize_colour_value( $colour ) {
            $colour = trim( (string) $colour );
            if ( '' === $colour ) { return ''; }

            $normalized_placeholder = strtolower( preg_replace( '/\s+/', ' ', $colour ) );
            $placeholder_values     = array( '-', 'n/a', 'na', 'none', 'not applicable', 'no colour', 'no color' );

            if ( in_array( $normalized_placeholder, $placeholder_values, true ) ) {
                return '';
            }

            if ( preg_match( '/^[-]+$/', $colour ) ) {
                return '';
            }

            if ( function_exists( 'mb_convert_case' ) ) {
                return mb_convert_case( $colour, MB_CASE_TITLE, 'UTF-8' );
            }
            return ucwords( strtolower( $colour ) );
        }

        /** @return int */
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

        /** @return bool */
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
                    array( 'slug' => $slug, 'taxonomy' => $taxonomy )
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

            $this->log( 'error', 'Failed to register attribute taxonomy for assignment.', array( 'slug' => $slug, 'taxonomy' => $taxonomy ) );
            return false;
        }

        /** @return int */
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
                $term_id = (int) $term->term_id;
                $this->attribute_term_cache[ $key ] = $term_id;
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
                            $update_result = wp_update_term( $existing_term_id, $taxonomy, array( 'name' => $value ) );
                            if ( is_wp_error( $update_result ) ) {
                                $this->log( 'error', $update_result->get_error_message(), array( 'taxonomy' => $taxonomy, 'term_id' => $existing_term_id ) );
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

        /** @return bool */
        protected function is_numeric_term_name( $name ) {
            // No longer skipping numeric names; allow SoftOne numeric codes as names.
            return false;
        }

        /** @return bool */
        protected function is_uncategorized_term( $name ) {
            $analysis = $this->evaluate_uncategorized_term( $name );
            return $analysis['is_uncategorized'];
        }

        /**
         * @return array{
         *   is_uncategorized: bool,
         *   match_type: string,
         *   sanitized_name: string,
         *   default_category_id: int,
         *   default_category_slug: string,
         *   default_category_name: string
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

        /** @return array<string,mixed> */
        protected function build_uncategorized_log_fields( array $analysis ) {
            if ( empty( $analysis['is_uncategorized'] ) ) {
                return array();
            }

            $fields = array( 'uncategorized_match_type' => $analysis['match_type'] );

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

        /** @return int */
        protected function ensure_term( $name, $tax, $parent = 0 ) {
            $name   = trim( (string) $name );
            $parent = (int) $parent;

            $key = $this->build_term_cache_key( $tax, $name, $parent );

            if ( '' === $name ) {
                $this->log( 'debug', 'SOFTONE_CAT_SYNC_006 Empty term name provided.', array( 'taxonomy' => $tax, 'parent_id' => $parent, 'cache_key' => $key ) );
                return 0;
            }

            if ( array_key_exists( $key, $this->term_cache ) ) {
                $this->cache_stats['term_cache_hits']++;
                $cached = (int) $this->term_cache[ $key ];
                if ( 0 === $cached ) {
                    $this->log( 'debug', 'SOFTONE_CAT_SYNC_007 Term cache contained empty identifier.', array( 'taxonomy' => $tax, 'parent_id' => $parent, 'cache_key' => $key ) );
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
            if ( '' !== $sanitized_name ) { $existing_term = get_term_by( 'slug', $sanitized_name, $tax ); }
            if ( ! ( $existing_term instanceof WP_Term ) ) { $existing_term = get_term_by( 'name', $name, $tax ); }

            if ( $existing_term instanceof WP_Term ) {
                $term_id = $this->maybe_update_term_parent( $existing_term, $tax, $parent );
                $this->term_cache[ $key ] = $term_id;
                return $term_id;
            }

            $args = array();
            if ( $parent ) { $args['parent'] = $parent; }

            $result = wp_insert_term( $name, $tax, $args );
            if ( is_wp_error( $result ) ) {
                $term_id    = 0;
                $error_code = method_exists( $result, 'get_error_code' ) ? $result->get_error_code() : '';
                $error_data = null;

                if ( method_exists( $result, 'get_error_data' ) ) {
                    $error_data = $result->get_error_data( 'term_exists' );
                    if ( null === $error_data ) { $error_data = $result->get_error_data(); }
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
                            $this->log( 'debug', 'SOFTONE_CAT_SYNC_013 Re-used existing term after concurrent creation.', array( 'taxonomy' => $tax, 'parent_id' => $parent, 'cache_key' => $key, 'term_id' => $term_id, 'error_code' => $error_code ) );
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
                        $this->log( 'debug', 'SOFTONE_CAT_SYNC_014 Recovered term after creation error.', array( 'taxonomy' => $tax, 'parent_id' => $parent, 'cache_key' => $key, 'term_id' => $term_id, 'error_code' => $error_code, 'error_data' => $error_data ) );
                        return $term_id;
                    }
                }

                $this->log(
                    'error',
                    'SOFTONE_CAT_SYNC_003 ' . $result->get_error_message(),
                    array( 'taxonomy' => $tax, 'parent_id' => $parent, 'cache_key' => $key, 'error_code' => $error_code, 'error_data' => $error_data )
                );

                $this->term_cache[ $key ] = 0;
                $this->log( 'debug', 'SOFTONE_CAT_SYNC_008 Term creation failed; returning empty identifier.', array( 'taxonomy' => $tax, 'parent_id' => $parent, 'cache_key' => $key, 'error_code' => $error_code, 'error_data' => $error_data ) );
                return 0;
            }

            $term_id = (int) $result['term_id'];
            $this->cache_stats['term_created']++;
            $this->term_cache[ $key ] = $term_id;

            return $term_id;
        }

        /** @return int */
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

        /** @return int */
        protected function maybe_update_term_parent( WP_Term $term, $tax, $parent ) {
            $parent = (int) $parent;

            if ( (int) $term->parent === $parent ) {
                return (int) $term->term_id;
            }

            $result = wp_update_term( $term->term_id, $tax, array( 'parent' => max( 0, $parent ) ) );
            if ( is_wp_error( $result ) ) {
                $this->log( 'error', $result->get_error_message(), array( 'taxonomy' => $tax, 'term_id' => $term->term_id ) );
                return (int) $term->term_id;
            }

            return (int) $result['term_id'];
        }

        /** @return void */
        protected function reset_caches() {
            $this->term_cache               = array();
            $this->attribute_term_cache     = array();
            $this->attribute_taxonomy_cache = array();
            $this->cache_stats              = array(
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

        /** @return string */
        protected function build_term_cache_key( $taxonomy, $term, $parent ) {
            return strtolower( (string) $taxonomy ) . '|' . md5( strtolower( (string) $term ) ) . '|' . (int) $parent;
        }

        /** @return string */
        protected function build_attribute_term_cache_key( $taxonomy, $term ) {
            return strtolower( (string) $taxonomy ) . '|' . md5( strtolower( (string) $term ) );
        }

        /** @return string */
        protected function get_value( array $data, array $keys ) {
            foreach ( $keys as $key ) {
                if ( isset( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
                    return (string) $data[ $key ];
                }
            }
            return '';
        }

        /** @return array */
        protected function extend_log_context_with_item( array $context, array $item_context ) {
            if ( empty( $item_context ) ) {
                return $context;
            }
            $context['item'] = $item_context;
            return $context;
        }

        /** @return array<string,string> */
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
                array( 'desc', 'description', 'item_description', 'itemname', 'name' )
            );
            if ( '' !== $name ) {
                $context['name'] = $this->truncate_log_value( $name );
            }

            return $context;
        }

        /** @return string */
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

        /** @return mixed */
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

        /** @return void */
        protected function log_activity( $channel, $action, $message, array $context = array() ) {
            if ( ! $this->activity_logger || ! method_exists( $this->activity_logger, 'log' ) ) {
                return;
            }
            $this->activity_logger->log( $channel, $action, $message, $context );
        }

        /** @return WC_Logger|Psr\Log\LoggerInterface|null */
        protected function get_default_logger() {
            if ( function_exists( 'wc_get_logger' ) ) {
                return wc_get_logger();
            }
            return null;
        }

        /** @return void */
        protected function log( $level, $message, array $context = array() ) {
            if ( ! $this->logger || ! method_exists( $this->logger, 'log' ) ) {
                return;
            }

            if ( class_exists( 'WC_Logger' ) && $this->logger instanceof WC_Logger ) {
                $context['source'] = self::LOGGER_SOURCE;
            }

            $this->logger->log( $level, $message, $context );
        }

        /** @return void */
        protected function log_category_assignment( $product_id, array $category_ids, array $context = array() ) {
            if ( ! $this->category_logger || ! method_exists( $this->category_logger, 'log_assignment' ) ) {
                return;
            }
            $this->category_logger->log_assignment( $product_id, $category_ids, $context );
        }

        /**
         * Try to attach existing Media Library images to the product by SKU.
         * Safe no-op if nothing is found.
         *
         * @param WC_Product $product
         * @param string     $sku
         * @return void
         */
        protected function assign_media_library_images( $product, $sku ) {
            $sku = trim( (string) $sku );
            if ( '' === $sku || ! function_exists( 'get_posts' ) ) {
                return;
            }

            $ids = get_posts( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 10,
                'orderby'        => 'date',
                'order'          => 'DESC',
                's'              => $sku,
                'fields'         => 'ids',
            ) );

            if ( empty( $ids ) ) {
                global $wpdb;
                $like  = '%' . $wpdb->esc_like( $sku ) . '%';
                $ids   = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status='inherit' AND guid LIKE %s ORDER BY post_date_gmt DESC LIMIT 10",
                        $like
                    )
                );
            }

            if ( empty( $ids ) || ! is_array( $ids ) ) {
                return;
            }

            $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
            $thumb_id = array_shift( $ids );

            if ( $thumb_id > 0 && method_exists( $product, 'set_image_id' ) ) {
                $product->set_image_id( $thumb_id );
            }

            if ( ! empty( $ids ) && method_exists( $product, 'set_gallery_image_ids' ) ) {
                $product->set_gallery_image_ids( $ids );
            }
        }

       /**
 * Ensure a Brand attribute term exists and assign it to the product (pa_brand).
 *
 * @param int    $product_id
 * @param string $brand_value
 * @return void
 */
protected function assign_brand_term( $product_id, $brand_value ) {
    $brand_value = trim( (string) $brand_value );
    if ( $product_id <= 0 || '' === $brand_value ) {
        return;
    }

    $slug    = 'brand';
    $label   = __( 'Brand', 'softone-woocommerce-integration' );
    $attr_id = $this->ensure_attribute_taxonomy( $slug, $label );
    if ( ! $attr_id || ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
        return;
    }

    $taxonomy = wc_attribute_taxonomy_name( $slug );
    if ( '' === $taxonomy || ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) ) {
        return;
    }

    $term_id = $this->ensure_attribute_term( $taxonomy, $brand_value );
    if ( $term_id > 0 && function_exists( 'wp_set_object_terms' ) ) {
        wp_set_object_terms( $product_id, array( (int) $term_id ), $taxonomy, false );
    }
}

/**
 * Assign WooCommerce Brands taxonomy (product_brand) if the taxonomy exists.
 *
 * @param int    $product_id
 * @param string $brand_value
 * @return void
 */
protected function assign_product_brand_term( $product_id, $brand_value ) {
    $product_id  = (int) $product_id;
    $brand_value = trim( (string) $brand_value );

    if ( $product_id <= 0 || '' === $brand_value ) {
        return;
    }

    // Only if WooCommerce Brands (or equivalent) is installed
    if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'product_brand' ) ) {
        return;
    }

    // Ensure term exists (create if missing)
    $term = get_term_by( 'name', $brand_value, 'product_brand' );
    if ( ! $term ) {
        $created = wp_insert_term( $brand_value, 'product_brand' );
        if ( is_wp_error( $created ) ) {
            $this->log( 'error', 'Failed to create product_brand term', array( 'brand' => $brand_value, 'error' => $created->get_error_message() ) );
            return;
        }
        $term_id = (int) $created['term_id'];
    } else {
        $term_id = (int) $term->term_id;
    }

    if ( $term_id > 0 ) {
        wp_set_object_terms( $product_id, array( $term_id ), 'product_brand', false );
    }
}

    }
}
