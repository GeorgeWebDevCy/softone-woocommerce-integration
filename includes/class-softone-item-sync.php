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

if ( ! class_exists( 'Softone_Item_Stale_Handler' ) ) {
    require_once __DIR__ . '/class-softone-item-stale-handler.php';
}

if ( ! class_exists( 'Softone_Item_Sync' ) ) {
    /**
     * Handles importing SoftOne catalogue items into WooCommerce.
     */
    class Softone_Item_Sync {

        const CRON_HOOK              = 'softone_wc_integration_sync_items';
        const ADMIN_ACTION           = 'softone_wc_integration_run_item_import';
        const META_MTRL              = '_softone_mtrl_id';
        const META_LAST_SYNC         = '_softone_last_synced';
        const META_PAYLOAD_HASH      = '_softone_payload_hash';
        const META_RELATED_ITEM_MTRL = '_softone_related_item_mtrl';
        const META_RELATED_ITEM_MTRLS = '_softone_related_item_mtrls';
        const OPTION_LAST_RUN        = 'softone_wc_integration_last_item_sync';
        const LOGGER_SOURCE          = 'softone-item-sync';
        const DEFAULT_CRON_EVENT     = 'hourly';
        const MAX_STORED_PAGE_HASHES = 5000;

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

        /** @var array<int, array<string, mixed>> */
        protected $pending_colour_variation_syncs = array();

        /** @var array<int, array<string, mixed>> */
        protected $pending_single_product_variations = array();

        /** @var Softone_Item_Stale_Handler */
        protected $stale_handler;

        public function __construct( ?Softone_API_Client $api_client = null, $logger = null, $category_logger = null, ?Softone_Sync_Activity_Logger $activity_logger = null, ?Softone_Item_Stale_Handler $stale_handler = null ) {
            $this->api_client = $api_client ?: new Softone_API_Client();
            $this->logger     = $logger ?: $this->get_default_logger();

            if ( null !== $category_logger && method_exists( $category_logger, 'log_assignment' ) ) {
                $this->category_logger = $category_logger;
            } else {
                $this->category_logger = new Softone_Category_Sync_Logger( $this->logger );
            }

            $this->activity_logger = $activity_logger;
            $this->stale_handler   = $stale_handler ?: new Softone_Item_Stale_Handler( $this->logger );

            $this->reset_caches();
            $this->pending_colour_variation_syncs     = array();
            $this->pending_single_product_variations = array();
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

            $this->pending_single_product_variations = array();

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
                        $this->log_activity(
                            'product_imports',
                            'error',
                            __( 'Failed to import product during Softone sync.', 'softone-woocommerce-integration' ),
                            array(
                                'error' => $exception->getMessage(),
                                'row'   => $this->prepare_api_payload_for_logging( $row ),
                            )
                        );
                    }
                }

                $this->process_pending_single_product_variations();
                $this->process_pending_colour_variation_syncs();

                $stale_processed = $this->stale_handler ? $this->stale_handler->handle( $started_at ) : 0;

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
                $this->pending_colour_variation_syncs     = array();
                $this->pending_single_product_variations = array();
                $this->force_taxonomy_refresh             = $previous_force_taxonomy_refresh;
            }
        }

        /**
         * Prepare the initial state for an asynchronous item import run.
         *
         * @param bool|null $force_full_import Whether to force a full import.
         * @param bool      $force_taxonomy_refresh Whether to refresh taxonomy assignments.
         * @return array<string,mixed> Import state payload.
         * @throws Softone_API_Client_Exception When the API client initialisation fails.
         * @throws Exception When WooCommerce is not available.
         */
        public function begin_async_import( $force_full_import = null, $force_taxonomy_refresh = false ) {
            if ( ! class_exists( 'WC_Product' ) ) {
                throw new Exception( __( 'WooCommerce is required to sync items.', 'softone-woocommerce-integration' ) );
            }

            $started_at = time();
            $last_run   = (int) get_option( self::OPTION_LAST_RUN );

            $this->reset_caches();
            $this->maybe_adjust_memory_limits();

            $this->log(
                'info',
                'Starting Softone item sync run (async initialisation).',
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
                        'mode'       => 'async',
                    )
                );
            }

            $default_page_size  = 250;
            $filtered_page_size = (int) apply_filters( 'softone_wc_integration_item_sync_page_size', $default_page_size );
            $page_size          = $filtered_page_size > 0 ? $filtered_page_size : $default_page_size;

            return array(
                'state_version'            => 1,
                'started_at'               => $started_at,
                'last_run'                 => $last_run,
                'force_full_import'        => (bool) $force_full_import,
                'force_taxonomy_refresh'   => (bool) $force_taxonomy_refresh,
                'request_extra'            => $extra,
                'page_size'                => $page_size,
                'page'                     => 1,
                'index'                    => 0,
                'stats'                    => array(
                    'processed' => 0,
                    'created'   => 0,
                    'updated'   => 0,
                    'skipped'   => 0,
                ),
                'pending_variations'       => array(),
                'pending_single_variations'=> array(),
                'page_hashes'              => array(),
                'cache_stats'              => $this->cache_stats,
                'total_rows'               => null,
                'complete'                 => false,
            );
        }

        /**
         * Process a batch of catalogue rows for an asynchronous import run.
         *
         * @param array<string,mixed> $state      Current import state payload.
         * @param int                 $batch_size Maximum number of rows to process.
         * @return array<string,mixed> Result payload containing the updated state.
         * @throws Softone_API_Client_Exception When the API request fails.
         * @throws Exception When WooCommerce is unavailable.
         */
        public function run_async_import_batch( array $state, $batch_size = 25 ) {
            if ( ! class_exists( 'WC_Product' ) ) {
                throw new Exception( __( 'WooCommerce is required to sync items.', 'softone-woocommerce-integration' ) );
            }

            $batch_size = (int) $batch_size;
            if ( $batch_size <= 0 ) {
                $batch_size = 25;
            }

            $started_at             = isset( $state['started_at'] ) ? (int) $state['started_at'] : time();
            $request_extra          = isset( $state['request_extra'] ) && is_array( $state['request_extra'] ) ? $state['request_extra'] : array();
            $page_size              = isset( $state['page_size'] ) ? (int) $state['page_size'] : 250;
            $page                   = isset( $state['page'] ) ? max( 1, (int) $state['page'] ) : 1;
            $index                  = isset( $state['index'] ) ? max( 0, (int) $state['index'] ) : 0;
            $hashes                 = isset( $state['page_hashes'] ) && is_array( $state['page_hashes'] ) ? $state['page_hashes'] : array();
            $stats                  = isset( $state['stats'] ) && is_array( $state['stats'] ) ? $state['stats'] : array();
            $aggregate_cache_stats  = isset( $state['cache_stats'] ) && is_array( $state['cache_stats'] ) ? $state['cache_stats'] : $this->cache_stats;
            $total_rows             = isset( $state['total_rows'] ) ? $state['total_rows'] : null;
            $force_tax_refresh      = isset( $state['force_taxonomy_refresh'] ) ? (bool) $state['force_taxonomy_refresh'] : false;
            $pending_variations     = isset( $state['pending_variations'] ) && is_array( $state['pending_variations'] ) ? $state['pending_variations'] : array();
            $pending_single_variations = isset( $state['pending_single_variations'] ) && is_array( $state['pending_single_variations'] ) ? $state['pending_single_variations'] : array();

            $stats = wp_parse_args(
                $stats,
                array(
                    'processed' => 0,
                    'created'   => 0,
                    'updated'   => 0,
                    'skipped'   => 0,
                )
            );

            $this->reset_caches();
            $this->maybe_adjust_memory_limits();

            $previous_force_taxonomy_refresh = $this->force_taxonomy_refresh;
            $this->force_taxonomy_refresh    = $force_tax_refresh;
            $this->pending_colour_variation_syncs     = $pending_variations;
            $this->pending_single_product_variations = $pending_single_variations;

            $remaining = $batch_size;
            $complete  = false;
            $warnings  = array();
            $initial_stats = $stats;
            $stale_processed = 0;

            while ( $remaining > 0 && ! $complete ) {
                $page_data = $this->fetch_item_page( $page, $page_size, $request_extra );
                $rows      = $page_data['rows'];

                if ( null !== $page_data['total_rows'] ) {
                    $total_rows = (int) $page_data['total_rows'];
                }

                if ( empty( $rows ) ) {
                    $complete = true;
                    break;
                }

                $hash = $this->hash_item_rows( $rows );
                if ( 0 === $index && isset( $hashes[ $hash ] ) ) {
                    $warnings[] = __( 'Detected repeated page payload while fetching Softone items. Import halted to prevent an infinite loop.', 'softone-woocommerce-integration' );
                    $this->log(
                        'warning',
                        'Detected repeated page payload when fetching Softone item rows. Aborting further pagination to prevent an infinite loop.',
                        array(
                            'page'      => $page,
                            'page_size' => $page_size,
                            'mode'      => 'async',
                        )
                    );
                    $complete = true;
                    break;
                }

                $hashes[ $hash ] = true;

                if ( count( $hashes ) > self::MAX_STORED_PAGE_HASHES ) {
                    $oldest_hash = array_key_first( $hashes );
                    if ( null !== $oldest_hash && $oldest_hash !== $hash ) {
                        unset( $hashes[ $oldest_hash ] );
                    }
                }
                $row_count       = count( $rows );

                while ( $index < $row_count && $remaining > 0 ) {
                    $row = $rows[ $index ];
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
                        $this->log_activity(
                            'product_imports',
                            'error',
                            __( 'Failed to import product during Softone sync.', 'softone-woocommerce-integration' ),
                            array(
                                'error' => $exception->getMessage(),
                                'row'   => $this->prepare_api_payload_for_logging( $row ),
                            )
                        );
                    }

                    $index++;
                    $remaining--;
                }

                if ( $index >= $row_count ) {
                    $index = 0;
                    $page++;

                    if ( $row_count < $page_size ) {
                        $complete = true;
                    }
                }
            }

            $batch_processed = $stats['processed'] - $initial_stats['processed'];
            $batch_created   = $stats['created'] - $initial_stats['created'];
            $batch_updated   = $stats['updated'] - $initial_stats['updated'];
            $batch_skipped   = $stats['skipped'] - $initial_stats['skipped'];

            $aggregate_cache_stats = $this->merge_cache_stats( $aggregate_cache_stats, $this->cache_stats );

            $state['page']               = $page;
            $state['index']              = $index;
            $state['stats']              = $stats;
            $state['cache_stats']        = $aggregate_cache_stats;
            $state['pending_variations']         = $this->pending_colour_variation_syncs;
            $state['pending_single_variations']  = $this->pending_single_product_variations;
            $state['total_rows']         = $total_rows;
            $state['complete']           = $complete;

            if ( $complete ) {
                $this->process_pending_single_product_variations();
                $this->process_pending_colour_variation_syncs();
                $stale_processed = $this->stale_handler ? $this->stale_handler->handle( $started_at ) : 0;

                if ( $stale_processed > 0 ) {
                    $state['stale_processed'] = (int) $stale_processed;
                }

                $this->log( 'debug', 'Softone item sync cache usage summary.', array( 'cache_stats' => $aggregate_cache_stats, 'mode' => 'async' ) );

                $state['pending_variations']        = array();
                $state['pending_single_variations'] = array();
                $this->pending_colour_variation_syncs     = array();
                $this->pending_single_product_variations = array();
                $hashes = array();
            }

            $state['page_hashes'] = $hashes;

            $this->force_taxonomy_refresh = $previous_force_taxonomy_refresh;

            return array(
                'state'           => $state,
                'complete'        => $complete,
                'batch'           => array(
                    'processed' => $batch_processed,
                    'created'   => $batch_created,
                    'updated'   => $batch_updated,
                    'skipped'   => $batch_skipped,
                ),
                'stats'           => $stats,
                'total_rows'      => $total_rows,
                'stale_processed' => $stale_processed,
                'warnings'        => $warnings,
            );
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
                $page_data = $this->fetch_item_page( $page, $page_size, $extra );
                $rows      = $page_data['rows'];

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

        /**
         * Fetch a single page of catalogue items from Softone.
         *
         * @param int               $page      Page number (1-indexed).
         * @param int               $page_size Number of rows to request.
         * @param array<string,mixed> $extra   Additional request parameters.
         * @return array<string,mixed> Array containing the rows and any detected totals.
         * @throws Softone_API_Client_Exception When the API request fails.
         */
        protected function fetch_item_page( $page, $page_size, array $extra ) {
            $page       = max( 1, (int) $page );
            $page_size  = max( 1, (int) $page_size );
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

            return array(
                'rows'       => $rows,
                'total_rows' => $this->extract_total_rows_from_response( $response ),
            );
        }

        /**
         * Attempt to extract a total row count from an API response payload.
         *
         * @param mixed $response API response payload.
         * @return int|null Total row count when available.
         */
        protected function extract_total_rows_from_response( $response ) {
            if ( ! is_array( $response ) ) {
                return null;
            }

            $candidates = array(
                'total',
                'total_rows',
                'totalcount',
                'totalCount',
                'rowcount',
                'rowCount',
            );

            foreach ( $candidates as $key ) {
                if ( isset( $response[ $key ] ) && is_numeric( $response[ $key ] ) ) {
                    $value = (int) $response[ $key ];
                    if ( $value >= 0 ) {
                        return $value;
                    }
                }
            }

            if ( isset( $response['pagination'] ) && is_array( $response['pagination'] ) ) {
                $pagination = $response['pagination'];
                foreach ( array( 'total', 'total_rows' ) as $key ) {
                    if ( isset( $pagination[ $key ] ) && is_numeric( $pagination[ $key ] ) ) {
                        $value = (int) $pagination[ $key ];
                        if ( $value >= 0 ) {
                            return $value;
                        }
                    }
                }
            }

            if ( isset( $response['meta'] ) && is_array( $response['meta'] ) ) {
                $meta = $response['meta'];
                foreach ( array( 'total', 'total_rows' ) as $key ) {
                    if ( isset( $meta[ $key ] ) && is_numeric( $meta[ $key ] ) ) {
                        $value = (int) $meta[ $key ];
                        if ( $value >= 0 ) {
                            return $value;
                        }
                    }
                }
            }

            if ( isset( $response['sql_totals'] ) && is_array( $response['sql_totals'] ) ) {
                $totals = $response['sql_totals'];
                foreach ( array( 'rows', 'total' ) as $key ) {
                    if ( isset( $totals[ $key ] ) && is_numeric( $totals[ $key ] ) ) {
                        $value = (int) $totals[ $key ];
                        if ( $value >= 0 ) {
                            return $value;
                        }
                    }
                }
            }

            return null;
        }

        /**
         * Merge cache statistics gathered across multiple batches.
         *
         * @param array<string,int> $aggregate Aggregated statistics collected so far.
         * @param array<string,int> $current   Statistics captured during the latest batch.
         * @return array<string,int> Updated statistics.
         */
        protected function merge_cache_stats( array $aggregate, array $current ) {
            foreach ( $current as $key => $value ) {
                if ( ! is_numeric( $value ) ) {
                    continue;
                }

                if ( ! isset( $aggregate[ $key ] ) || ! is_numeric( $aggregate[ $key ] ) ) {
                    $aggregate[ $key ] = (int) $value;
                } else {
                    $aggregate[ $key ] += (int) $value;
                }
            }

            return $aggregate;
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
         * Determine whether variable product handling is enabled.
         *
         * @return bool
         */
        protected function is_variable_product_handling_enabled() {
            return (bool) apply_filters( 'softone_wc_integration_enable_variable_product_handling', true );
        }

        /**
         * @throws Exception
         * @return string created|updated|skipped
         */
        protected function import_row( array $data, $run_timestamp ) {
    $mtrl                  = isset( $data['mtrl'] ) ? (string) $data['mtrl'] : '';
    $sku_requested         = $this->determine_sku( $data );
    $original_product_name = $this->get_value( $data, array( 'varchar02', 'desc', 'description', 'code' ) );
    $normalized_name       = '';
    $derived_colour        = '';

    if ( '' !== $original_product_name ) {
        list( $maybe_normalized_name, $maybe_derived_colour ) = $this->split_product_name_and_colour( $original_product_name );
        if ( '' === $maybe_normalized_name ) {
            $maybe_normalized_name = $original_product_name;
        }

        $normalized_name = $maybe_normalized_name;
        $derived_colour  = $maybe_derived_colour;
    }

    $anticipated_colour = $this->normalize_colour_value(
        trim( $this->get_value( $data, array( 'colour_name', 'color_name', 'colour', 'color' ) ) )
    );

    if ( '' === $anticipated_colour && '' !== $derived_colour ) {
        $anticipated_colour = $derived_colour;
    }

    $colour_value_for_variation = $anticipated_colour;

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

    $category_ids = $this->prepare_category_ids( $data );

    if ( ! $is_new ) {
        $existing_hash    = (string) get_post_meta( $product_id, self::META_PAYLOAD_HASH, true );
        $categories_match = $this->product_categories_match( $product_id, $category_ids );

        if ( ! $this->force_taxonomy_refresh && '' !== $existing_hash && $existing_hash === $payload_hash ) {
            if ( $categories_match ) {
                $skip_context = array(
                    'product_id'             => $product_id,
                    'sku'                    => $sku_requested,
                    'mtrl'                   => $mtrl,
                    'payload_hash'           => $payload_hash,
                    'existing_hash'          => $existing_hash,
                    'category_ids'           => $category_ids,
                    'categories_match'       => true,
                    'force_taxonomy_refresh' => (bool) $this->force_taxonomy_refresh,
                );

                $this->log(
                    'debug',
                    'Skipping product import because the payload hash matches the existing product.',
                    $skip_context
                );

                $this->log_activity(
                    'product_imports',
                    'skipped_payload_match',
                    __( 'Skipped importing product because the payload hash matched the stored data.', 'softone-woocommerce-integration' ),
                    $skip_context
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
    $fallback_metadata  = array();
    $price_value        = null;
    $stock_amount       = null;
    $should_backorder   = false;
    $colour_term_id     = 0;
    $colour_taxonomy    = '';
    $should_create_colour_variation = ( '' !== $colour_value_for_variation );

    if ( '' !== $normalized_name ) {
        $product->set_name( $normalized_name );
    }

    if ( '' !== $derived_colour ) {
        $fallback_metadata['colour'] = $derived_colour;
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
        $price_value = wc_format_decimal( $price );
        $product->set_regular_price( $price_value );
    }

    // ---------- SKU (ensure unique, but if someone else owns it, we UPDATE THAT product) ----------
    $extra_suffixes = array();
    $variable_product_handling_enabled = $this->is_variable_product_handling_enabled();

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
        if ( $should_create_colour_variation && $variable_product_handling_enabled ) {
            $product->set_sku( '' );
        } else {
            $product->set_sku( $effective_sku );
        }
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

    // ---------- CATEGORIES ----------
    if ( ! empty( $category_ids ) ) {
        $product->set_category_ids( $category_ids );
    }

    // ---------- ATTRIBUTES ----------
    $brand_value           = trim( $this->get_value( $data, array( 'brand_name', 'brand' ) ) );
    $attribute_assignments = $this->prepare_attribute_assignments( $data, $product, $fallback_metadata );
    $related_item_mtrl_value = '';
    $related_item_mtrls      = array();
    if ( isset( $attribute_assignments['related_item_mtrl'] ) ) {
        $related_item_mtrl_value = (string) $attribute_assignments['related_item_mtrl'];
    }
    if ( isset( $attribute_assignments['related_item_mtrls'] ) && is_array( $attribute_assignments['related_item_mtrls'] ) ) {
        $related_item_mtrls = array_values( array_filter( array_map( 'strval', $attribute_assignments['related_item_mtrls'] ) ) );
    }

    $colour_taxonomy = $this->normalize_attribute_taxonomy_name( $this->resolve_colour_attribute_slug() );
    $received_related_payload = $this->received_related_item_payload( $data );

    if ( isset( $attribute_assignments['values'][ $colour_taxonomy ] ) ) {
        if ( '' === $colour_value_for_variation ) {
            $colour_value_for_variation = $this->normalize_colour_value( $attribute_assignments['values'][ $colour_taxonomy ] );
        }

        if ( '' !== $colour_value_for_variation ) {
            $should_create_colour_variation = true;
        }
    }

    if ( $colour_taxonomy && ! empty( $attribute_assignments['terms'][ $colour_taxonomy ] ) ) {
        $colour_terms   = (array) $attribute_assignments['terms'][ $colour_taxonomy ];
        $first_term     = reset( $colour_terms );
        $colour_term_id = (int) $first_term;
        if ( $colour_term_id > 0 ) {
            $should_create_colour_variation = true;
        }
    } elseif ( $should_create_colour_variation && '' !== $colour_value_for_variation ) {
        $recovered_term_id = $this->ensure_colour_attribute_assignment(
            $attribute_assignments,
            $colour_taxonomy,
            $colour_value_for_variation
        );

        if ( $recovered_term_id > 0 ) {
            $colour_term_id                            = (int) $recovered_term_id;
            $attribute_assignments['terms'][ $colour_taxonomy ]  = array( $colour_term_id );
            $attribute_assignments['values'][ $colour_taxonomy ] = $colour_value_for_variation;
        } else {
            $should_create_colour_variation = false;
        }
    } else {
        $should_create_colour_variation = false;
    }

    $sku_adjusted_after_save = false;
    if ( ! $should_create_colour_variation && '' !== $effective_sku && '' === $product->get_sku() ) {
        $product->set_sku( $effective_sku );
    }

    if ( ! empty( $attribute_assignments['attributes'] ) ) {
        $product->set_attributes( $attribute_assignments['attributes'] );
    } elseif ( empty( $attribute_assignments['attributes'] ) && $is_new ) {
        $product->set_attributes( array() );
    }

    // ---------- SAVE FIRST ----------
    $product_id = $product->save();
    if ( ! $product_id ) {
        throw new Exception( __( 'Unable to save the WooCommerce product.', 'softone-woocommerce-integration' ) );
    }

    // If we learned MTRL during import, ensure it’s set on reused products too
    if ( $mtrl ) {
        update_post_meta( $product_id, self::META_MTRL, $mtrl );
    }

    $this->sync_related_item_relationships(
        $product_id,
        $mtrl,
        $related_item_mtrl_value,
        $related_item_mtrls,
        $received_related_payload
    );

    $all_related_item_mtrls = $related_item_mtrls;
    $stored_related = get_post_meta( $product_id, self::META_RELATED_ITEM_MTRLS, true );
    if ( is_array( $stored_related ) ) {
        $all_related_item_mtrls = array_merge( $all_related_item_mtrls, array_map( 'strval', $stored_related ) );
    } elseif ( is_string( $stored_related ) && '' !== trim( $stored_related ) ) {
        $all_related_item_mtrls = array_merge( $all_related_item_mtrls, $this->parse_related_item_mtrls( $stored_related ) );
    }

    $all_related_item_mtrls = array_values( array_unique( array_filter( array_map( 'strval', $all_related_item_mtrls ) ) ) );

    $related_variation_candidates = array();
    if ( ! empty( $all_related_item_mtrls ) ) {
        $related_variation_candidates = array_values(
            array_filter(
                array_map(
                    'strval',
                    array_diff( $all_related_item_mtrls, array( $mtrl ) )
                )
            )
        );
    }

    if ( ! $should_create_colour_variation && '' !== $effective_sku && '' === $product->get_sku() ) {
        $product->set_sku( $effective_sku );
        $sku_adjusted_after_save = true;
    }

    $additional_variation_attributes = array();
    $size_taxonomy = $this->normalize_attribute_taxonomy_name( 'size' );

    if ( '' !== $size_taxonomy && ! empty( $attribute_assignments['terms'][ $size_taxonomy ] ) ) {
        $size_terms   = (array) $attribute_assignments['terms'][ $size_taxonomy ];
        $size_term_id = (int) reset( $size_terms );

        if ( $size_term_id > 0 ) {
            $additional_variation_attributes[] = array(
                'taxonomy'       => $size_taxonomy,
                'term_id'        => $size_term_id,
                'attribute_slug' => 'size',
                'is_variation'   => true,
            );
        }
    }

    if ( '' !== $colour_taxonomy ) {
        $related_colour_term_ids = array();
        $missing_related_products = array();
        $related_without_colour  = array();
        $ready_for_colour_aggregation = true;

        if ( $colour_term_id > 0 ) {
            $related_colour_term_ids[] = (int) $colour_term_id;
        }

        if ( ! empty( $all_related_item_mtrls ) && function_exists( 'wc_get_product' ) ) {
            foreach ( $all_related_item_mtrls as $related_mtrl ) {
                $related_product_id = $this->find_product_id_by_mtrl( $related_mtrl );
                if ( $related_product_id <= 0 ) {
                    $missing_related_products[] = (string) $related_mtrl;
                    $ready_for_colour_aggregation = false;
                    continue;
                }

                $related_product = wc_get_product( $related_product_id );
                if ( ! $related_product ) {
                    $missing_related_products[] = (string) $related_mtrl;
                    $ready_for_colour_aggregation = false;
                    continue;
                }

                $related_term_id = $this->find_colour_term_id_for_product( $related_product, $colour_taxonomy );
                if ( $related_term_id > 0 ) {
                    $related_colour_term_ids[] = (int) $related_term_id;
                } else {
                    $related_without_colour[] = (string) $related_mtrl;
                    $ready_for_colour_aggregation = false;
                }
            }
        }

        $related_colour_term_ids = array_values( array_unique( array_filter( array_map( 'intval', $related_colour_term_ids ) ) ) );

        if ( $ready_for_colour_aggregation && ! empty( $related_colour_term_ids ) ) {
            $this->ensure_parent_colour_attribute_terms( $product_id, $colour_taxonomy, $related_colour_term_ids );
        } elseif ( ! $ready_for_colour_aggregation && ( ! empty( $missing_related_products ) || ! empty( $related_without_colour ) ) ) {
            $this->log(
                'debug',
                'SOFTONE_ATTR_SYNC_021 Deferred colour aggregation until related items are imported.',
                array(
                    'product_id'               => $product_id,
                    'colour_taxonomy'          => $colour_taxonomy,
                    'missing_related_products' => $missing_related_products,
                    'related_without_colour'   => $related_without_colour,
                )
            );
        }

        if ( $ready_for_colour_aggregation && ! empty( $related_variation_candidates ) ) {
            $this->queue_colour_variation_sync( $product_id, $mtrl, $all_related_item_mtrls, $colour_taxonomy );
        }
    }

    if ( $should_create_colour_variation ) {
        $this->queue_single_product_variation(
            $product_id,
            $colour_term_id,
            $colour_taxonomy,
            $effective_sku,
            $price_value,
            $stock_amount,
            $mtrl,
            $should_backorder,
            $additional_variation_attributes
        );
    }

    if ( $sku_adjusted_after_save ) {
        $product->save();
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

        $this->log(
            'debug',
            'Assigning attribute terms to product.',
            array(
                'product_id' => $product_id,
                'taxonomy'   => $taxonomy,
                'term_ids'   => $normalized_term_ids,
            )
        );

        wp_set_object_terms( $product_id, $normalized_term_ids, $taxonomy );
    }

    // ---------- CLEAR TAXONOMIES ----------
    foreach ( $attribute_assignments['clear'] as $taxonomy ) {
        if ( '' === $taxonomy ) { continue; }
        $this->log(
            'debug',
            'Clearing attribute terms from product.',
            array(
                'product_id' => $product_id,
                'taxonomy'   => $taxonomy,
            )
        );
        wp_set_object_terms( $product_id, array(), $taxonomy );
    }

    // ---------- BRAND (attribute + WooCommerce Brands taxonomy) ----------
    $this->assign_brand_term( $product_id, $brand_value );               // attribute pa_brand
    $this->assign_product_brand_term( $product_id, $brand_value );       // taxonomy product_brand

    $action = $is_new ? 'created' : 'updated';
    $activity_context = array(
        'product_id' => $product_id,
        'sku'        => $sku_for_images,
        'mtrl'       => $mtrl,
        'timestamp'  => $run_timestamp,
    );

    $message = sprintf( __( 'Product %s via Softone sync.', 'softone-woocommerce-integration' ), $action );

    $this->log(
        'info',
        $message,
        $activity_context
    );

    $this->log_activity(
        'product_imports',
        $action,
        $message,
        $activity_context
    );

    return $action;
}



        /**
         * Ensure a variable product exists with a colour variation.
         *
         * @param int         $product_id
         * @param int         $colour_term_id
         * @param string      $colour_taxonomy
         * @param string      $sku
         * @param string|null $price_value
         * @param int|null    $stock_amount
         * @param string      $mtrl
         * @param bool        $should_backorder
         * @param array       $extra_meta
         * @param array       $additional_attributes
         * @return int Variation identifier or 0 on failure.
         */
        protected function ensure_colour_variation( $product_id, $colour_term_id, $colour_taxonomy, $sku, $price_value, $stock_amount, $mtrl, $should_backorder, array $extra_meta = array(), array $additional_attributes = array() ) {
            $product_id      = (int) $product_id;
            $colour_term_id  = (int) $colour_term_id;
            $colour_taxonomy = (string) $colour_taxonomy;
            $sku             = (string) $sku;
            $mtrl            = (string) $mtrl;

            $base_context = array(
                'product_id'      => $product_id,
                'colour_term_id'  => $colour_term_id,
                'colour_taxonomy' => $colour_taxonomy,
                'sku'             => $sku,
                'mtrl'            => $mtrl,
            );

            $log_variation_failure = function( $reason, array $extra_context = array(), $message = 'Failed to ensure colour variation during Softone sync.', $level = 'warning' ) use ( $base_context ) {
                $failure_context = array_merge(
                    $base_context,
                    array( 'reason' => $reason ),
                    $extra_context
                );

                $this->log( $level, $message, $failure_context );
                $this->log_activity(
                    'variable_products',
                    'variation_failure',
                    __( 'Failed to ensure colour variation during Softone sync.', 'softone-woocommerce-integration' ),
                    $failure_context
                );

                return 0;
            };

            if ( $product_id <= 0 || $colour_term_id <= 0 || '' === $colour_taxonomy ) {
                return $log_variation_failure( 'invalid_variation_arguments' );
            }

            if ( ! $this->is_variable_product_handling_enabled() ) {
                return $log_variation_failure(
                    'variable_product_handling_disabled',
                    array(),
                    'Skipping colour variation creation because variable product handling is disabled.',
                    'debug'
                );
            }

            if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Variation' ) ) {
                return $log_variation_failure( 'missing_wc_variation_support' );
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return $log_variation_failure( 'product_not_found' );
            }

            if ( 'variable' !== $product->get_type() ) {
                return $log_variation_failure(
                    'product_not_variable',
                    array( 'product_type' => $product->get_type() )
                );
            }

            $term = get_term( $colour_term_id, $colour_taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                $error_context = array();
                if ( is_wp_error( $term ) ) {
                    $error_context['term_error'] = $term->get_error_message();
                }

                return $log_variation_failure( 'term_not_found', $error_context );
            }

            $term_slug = isset( $term->slug ) ? (string) $term->slug : '';
            if ( '' === $term_slug ) {
                $term_name = isset( $term->name ) ? (string) $term->name : '';
                if ( function_exists( 'sanitize_title' ) ) {
                    $term_slug = sanitize_title( $term_name );
                } else {
                    $term_slug = strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '-', $term_name ) );
                }
            }

            if ( '' === $term_slug ) {
                return $log_variation_failure( 'term_slug_empty' );
            }

            $attribute_key = $colour_taxonomy;
            if ( function_exists( 'wc_variation_attribute_name' ) ) {
                $attribute_key = wc_variation_attribute_name( $colour_taxonomy );
            } else {
                $attribute_key = 'attribute_' . ltrim( $colour_taxonomy, '_' );
            }

            $prepared_attribute_values = array();

            if ( ! is_array( $additional_attributes ) ) {
                $additional_attributes = array();
            }

            foreach ( $additional_attributes as $attribute_payload ) {
                if ( ! is_array( $attribute_payload ) ) {
                    continue;
                }

                $taxonomy = isset( $attribute_payload['taxonomy'] ) ? (string) $attribute_payload['taxonomy'] : '';
                $term_id  = isset( $attribute_payload['term_id'] ) ? (int) $attribute_payload['term_id'] : 0;

                if ( '' === $taxonomy || $term_id <= 0 ) {
                    continue;
                }

                $term_object = get_term( $term_id, $taxonomy );
                if ( ! $term_object || is_wp_error( $term_object ) ) {
                    continue;
                }

                $term_value = '';
                if ( isset( $term_object->slug ) && '' !== (string) $term_object->slug ) {
                    $term_value = (string) $term_object->slug;
                } elseif ( isset( $term_object->name ) && '' !== (string) $term_object->name ) {
                    if ( function_exists( 'sanitize_title' ) ) {
                        $term_value = sanitize_title( (string) $term_object->name );
                    } else {
                        $term_value = strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '-', (string) $term_object->name ) );
                    }
                }

                if ( '' === $term_value ) {
                    continue;
                }

                $attribute_name = $taxonomy;
                if ( function_exists( 'wc_variation_attribute_name' ) ) {
                    $attribute_name = wc_variation_attribute_name( $taxonomy );
                } else {
                    $attribute_name = 'attribute_' . ltrim( $taxonomy, '_' );
                }

                if ( '' === $attribute_name ) {
                    continue;
                }

                $prepared_attribute_values[ $attribute_name ] = $term_value;
            }

            $target_attributes = array( $attribute_key => $term_slug );

            foreach ( $prepared_attribute_values as $prepared_attribute_name => $prepared_attribute_value ) {
                $target_attributes[ $prepared_attribute_name ] = $prepared_attribute_value;
            }

            $variation_id = $this->find_existing_variation_by_attributes( $product_id, $target_attributes );

            if ( $variation_id <= 0 ) {
                $variation_id = $this->find_existing_variation_id( $product_id, $sku, $mtrl );
            }

            $is_new = $variation_id <= 0;

            if ( $is_new ) {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id( $product_id );
            } else {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation ) {
                    $variation = new WC_Product_Variation( $variation_id );
                }
            }

            if ( ! $variation || ! $variation instanceof WC_Product_Variation ) {
                return $log_variation_failure( 'invalid_variation_object', array( 'variation_id' => $variation_id ) );
            }

            $attributes = $variation->get_attributes();
            if ( ! is_array( $attributes ) ) {
                $attributes = array();
            }

            foreach ( $target_attributes as $target_attribute_name => $target_attribute_value ) {
                $attributes[ $target_attribute_name ] = $target_attribute_value;
            }

            $variation->set_attributes( $attributes );

            if ( '' !== (string) $sku ) {
                $variation->set_sku( (string) $sku );
            } elseif ( $is_new ) {
                $variation->set_sku( '' );
            }

            if ( null !== $price_value && '' !== $price_value ) {
                $variation->set_regular_price( (string) $price_value );
                $variation->set_sale_price( '' );
                $variation->set_price( (string) $price_value );
            } elseif ( $is_new ) {
                $variation->set_regular_price( '' );
                $variation->set_sale_price( '' );
                $variation->set_price( '' );
            }

            $quantity = null;
            if ( null !== $stock_amount ) {
                $quantity = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $stock_amount ) : (int) $stock_amount;
            }

            if ( null !== $quantity ) {
                $variation->set_manage_stock( true );
                $variation->set_stock_quantity( $quantity );

                if ( $should_backorder ) {
                    $variation->set_backorders( 'notify' );
                    $variation->set_stock_status( $quantity > 0 ? 'instock' : 'onbackorder' );
                } else {
                    $variation->set_backorders( 'no' );
                    $variation->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
                }
            } else {
                $variation->set_manage_stock( false );
                $variation->set_stock_quantity( null );

                if ( $should_backorder ) {
                    $variation->set_backorders( 'notify' );
                    $variation->set_stock_status( 'onbackorder' );
                } else {
                    $variation->set_backorders( 'no' );
                    $variation->set_stock_status( 'instock' );
                }
            }

            $variation->set_status( 'publish' );

            $variation_id = $variation->save();
            $variation_id = (int) $variation_id;

            if ( $variation_id <= 0 ) {
                return $log_variation_failure( 'failed_to_save_variation' );
            }

            if ( '' !== $mtrl ) {
                update_post_meta( $variation_id, self::META_MTRL, $mtrl );
            }

            if ( ! isset( $extra_meta[ self::META_PAYLOAD_HASH ] ) ) {
                $parent_hash = get_post_meta( $product_id, self::META_PAYLOAD_HASH, true );
                if ( '' !== $parent_hash ) {
                    $extra_meta[ self::META_PAYLOAD_HASH ] = $parent_hash;
                }
            }

            if ( ! isset( $extra_meta[ self::META_LAST_SYNC ] ) ) {
                $parent_sync = get_post_meta( $product_id, self::META_LAST_SYNC, true );
                if ( '' !== $parent_sync ) {
                    $extra_meta[ self::META_LAST_SYNC ] = $parent_sync;
                }
            }

            foreach ( $extra_meta as $meta_key => $meta_value ) {
                if ( '' === $meta_value || null === $meta_value ) {
                    continue;
                }

                update_post_meta( $variation_id, $meta_key, $meta_value );
            }

            if ( $is_new ) {
                $success_context = array_merge(
                    $base_context,
                    array(
                        'variation_id' => $variation_id,
                        'attributes'   => $variation->get_attributes(),
                    )
                );

                $this->log(
                    'info',
                    'Created colour variation for variable product.',
                    $success_context
                );

                $this->log_activity(
                    'variable_products',
                    'variation_created',
                    __( 'Created colour variation for variable product during Softone sync.', 'softone-woocommerce-integration' ),
                    $success_context
                );
            }

            return $variation_id;
        }

        /**
         * Move single-product records to draft once they are represented as variations.
         *
         * @param string $mtrl             Softone material identifier for the variation source.
         * @param int    $parent_product_id Parent variable product ID.
         * @return void
         */
        protected function maybe_draft_single_product_source( $mtrl, $parent_product_id ) {
            $mtrl = trim( (string) $mtrl );
            $parent_product_id = (int) $parent_product_id;

            if ( '' === $mtrl || $parent_product_id <= 0 ) {
                return;
            }

            $source_product_id = $this->find_product_id_by_mtrl( $mtrl );

            if ( $source_product_id <= 0 || $source_product_id === $parent_product_id ) {
                return;
            }

            $source_product = wc_get_product( $source_product_id );
            if ( ! $source_product ) {
                return;
            }

            if ( 'draft' === $source_product->get_status() ) {
                return;
            }

            $source_product->set_status( 'draft' );
            $source_product->save();

            $this->log(
                'info',
                'Drafted single product after creating variable variation.',
                array(
                    'source_product_id' => $source_product_id,
                    'parent_product_id' => $parent_product_id,
                    'mtrl'              => $mtrl,
                )
            );
        }

        /**
         * Locate an existing variation that matches the supplied attributes.
         *
         * @param int   $product_id
         * @param array $attributes
         * @return int
         */
        protected function find_existing_variation_by_attributes( $product_id, array $attributes ) {
            $product_id = (int) $product_id;

            if ( $product_id <= 0 || empty( $attributes ) ) {
                return 0;
            }

            if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Variation' ) ) {
                return 0;
            }

            $normalized_attributes = array();
            foreach ( $attributes as $attribute_name => $attribute_value ) {
                $attribute_name = (string) $attribute_name;

                if ( '' === $attribute_name ) {
                    continue;
                }

                if ( is_array( $attribute_value ) ) {
                    $attribute_value = reset( $attribute_value );
                }

                $attribute_value = (string) $attribute_value;

                if ( '' === $attribute_value ) {
                    continue;
                }

                $normalized_attributes[ $attribute_name ] = $attribute_value;
            }

            if ( empty( $normalized_attributes ) ) {
                return 0;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return 0;
            }

            $child_ids = $product->get_children();

            if ( empty( $child_ids ) ) {
                return 0;
            }

            ksort( $normalized_attributes );

            foreach ( $child_ids as $child_id ) {
                $child_id = (int) $child_id;

                if ( $child_id <= 0 ) {
                    continue;
                }

                $variation = wc_get_product( $child_id );

                if ( ! $variation || ! $variation instanceof WC_Product_Variation ) {
                    continue;
                }

                $variation_attributes = $variation->get_attributes();

                if ( ! is_array( $variation_attributes ) ) {
                    $variation_attributes = array();
                }

                $normalized_variation_attributes = array();

                foreach ( $variation_attributes as $variation_attribute_name => $variation_attribute_value ) {
                    $variation_attribute_name = (string) $variation_attribute_name;

                    if ( '' === $variation_attribute_name ) {
                        continue;
                    }

                    if ( is_array( $variation_attribute_value ) ) {
                        $variation_attribute_value = reset( $variation_attribute_value );
                    }

                    $variation_attribute_value = (string) $variation_attribute_value;

                    if ( '' === $variation_attribute_value ) {
                        continue;
                    }

                    $normalized_variation_attributes[ $variation_attribute_name ] = $variation_attribute_value;
                }

                if ( empty( $normalized_variation_attributes ) ) {
                    continue;
                }

                ksort( $normalized_variation_attributes );

                if ( $normalized_variation_attributes === $normalized_attributes ) {
                    $this->log(
                        'debug',
                        'Located existing variation via attribute match.',
                        array(
                            'product_id'   => $product_id,
                            'variation_id' => $child_id,
                            'attributes'   => $normalized_variation_attributes,
                        )
                    );

                    return $child_id;
                }
            }

            return 0;
        }

        /**
         * Locate an existing variation for the product.
         *
         * @param int    $product_id
         * @param string $sku
         * @param string $mtrl
         * @return int
         */
        protected function find_existing_variation_id( $product_id, $sku, $mtrl ) {
            $product_id = (int) $product_id;
            $sku        = trim( (string) $sku );
            $mtrl       = trim( (string) $mtrl );

            if ( $product_id <= 0 ) {
                return 0;
            }

            if ( '' !== $mtrl ) {
                global $wpdb;
                $query = $wpdb->prepare(
                    "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_parent = %d LIMIT 1",
                    self::META_MTRL,
                    $mtrl,
                    $product_id
                );

                $existing = (int) $wpdb->get_var( $query );
                if ( $existing > 0 ) {
                    $this->log(
                        'debug',
                        'Located existing colour variation via Softone material match.',
                        array(
                            'product_id'   => $product_id,
                            'variation_id' => $existing,
                            'mtrl'         => $mtrl,
                        )
                    );
                    return $existing;
                }
            }

            if ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
                $existing = (int) wc_get_product_id_by_sku( $sku );
                if ( $existing > 0 && (int) wp_get_post_parent_id( $existing ) === $product_id ) {
                    $existing_mtrl = get_post_meta( $existing, self::META_MTRL, true );
                    $existing_mtrl = is_array( $existing_mtrl ) ? (string) reset( $existing_mtrl ) : (string) $existing_mtrl;

                    if ( '' === $mtrl || '' === $existing_mtrl || $existing_mtrl === $mtrl ) {
                        $this->log(
                            'debug',
                            'Located existing colour variation via SKU match.',
                            array(
                                'product_id'   => $product_id,
                                'variation_id' => $existing,
                                'sku'          => $sku,
                            )
                        );
                        return $existing;
                    }

                    $this->log(
                        'warning',
                        'Skipped SKU-based variation match because the Softone material conflicted.',
                        array(
                            'product_id'      => $product_id,
                            'variation_id'    => $existing,
                            'sku'             => $sku,
                            'existing_mtrl'   => $existing_mtrl,
                            'requested_mtrl'  => $mtrl,
                        )
                    );
                }
            }

            return 0;
        }

        /**
         * Ensure related item pointers remain synchronised between Softone products.
         *
         * @param int    $product_id          Current product identifier.
         * @param string $mtrl                Current Softone material identifier.
         * @param string $related_item_mtrl   Related Softone material identifier from the payload.
         * @param array<int,string> $related_item_mtrls List of related Softone material identifiers from the payload.
         * @param bool   $received_related_payload Whether the Softone payload included related item fields.
         * @return void
         */
        protected function sync_related_item_relationships( $product_id, $mtrl, $related_item_mtrl, array $related_item_mtrls = array(), $received_related_payload = true ) {
            $product_id        = (int) $product_id;
            $mtrl              = trim( (string) $mtrl );
            $related_item_mtrl = trim( (string) $related_item_mtrl );

            if ( '' !== $related_item_mtrl && $related_item_mtrl === $mtrl ) {
                $related_item_mtrl = '';
            }

            $sanitized_related_item_mtrls = array();
            foreach ( $related_item_mtrls as $candidate_mtrl ) {
                $candidate_mtrl = trim( (string) $candidate_mtrl );

                if ( '' === $candidate_mtrl || $candidate_mtrl === $mtrl ) {
                    continue;
                }

                $sanitized_related_item_mtrls[] = $candidate_mtrl;
            }

            $related_item_mtrls = array_values( array_unique( $sanitized_related_item_mtrls ) );

            if ( $product_id <= 0 ) {
                return;
            }

            if ( ! $received_related_payload ) {
                return;
            }

            $primary_related = '';
            if ( '' !== $related_item_mtrl ) {
                $primary_related = $related_item_mtrl;
            } elseif ( ! empty( $related_item_mtrls ) ) {
                $primary_related = (string) reset( $related_item_mtrls );
            }

            $previous_related_meta = get_post_meta( $product_id, self::META_RELATED_ITEM_MTRL, true );
            $previous_related      = is_array( $previous_related_meta ) ? (string) reset( $previous_related_meta ) : (string) $previous_related_meta;

            if ( '' === $primary_related ) {
                delete_post_meta( $product_id, self::META_RELATED_ITEM_MTRL );
            } else {
                update_post_meta( $product_id, self::META_RELATED_ITEM_MTRL, $primary_related );
            }

            $existing_related_meta = get_post_meta( $product_id, self::META_RELATED_ITEM_MTRLS, true );
            $existing_related_list = array();
            if ( is_array( $existing_related_meta ) ) {
                $existing_related_list = array_map( 'strval', $existing_related_meta );
            } elseif ( is_string( $existing_related_meta ) && '' !== trim( $existing_related_meta ) ) {
                $existing_related_list = $this->parse_related_item_mtrls( $existing_related_meta );
            }

            $sanitized_existing_related = array();
            foreach ( $existing_related_list as $existing_mtrl ) {
                $existing_mtrl = trim( (string) $existing_mtrl );

                if ( '' === $existing_mtrl || $existing_mtrl === $mtrl ) {
                    continue;
                }

                $sanitized_existing_related[] = $existing_mtrl;
            }

            $merged_related = array_values( array_unique( array_merge( $sanitized_existing_related, $related_item_mtrls ) ) );

            if ( empty( $merged_related ) ) {
                delete_post_meta( $product_id, self::META_RELATED_ITEM_MTRLS );
            } else {
                update_post_meta( $product_id, self::META_RELATED_ITEM_MTRLS, $merged_related );
            }

            if ( '' !== $mtrl && ( ! empty( $related_item_mtrls ) || ! empty( $sanitized_existing_related ) ) ) {
                $this->sync_child_parent_relationships( $mtrl, $related_item_mtrls );
            }

            if ( '' !== $previous_related && $previous_related !== $primary_related ) {
                $this->refresh_related_item_children( $previous_related );
            }

            if ( '' !== $primary_related ) {
                $this->refresh_related_item_children( $primary_related );
            }

            if ( '' !== $mtrl ) {
                $this->refresh_related_item_children( $mtrl );
            }
        }

        /**
         * Ensure related items reference the supplied parent material.
         *
         * @param string              $parent_mtrl
         * @param array<int,string>   $child_mtrls
         * @return void
         */
        protected function sync_child_parent_relationships( $parent_mtrl, array $child_mtrls ) {
            $parent_mtrl = trim( (string) $parent_mtrl );
            if ( '' === $parent_mtrl ) {
                return;
            }

            $child_mtrls = array_values( array_unique( array_filter( array_map( 'strval', $child_mtrls ) ) ) );

            $existing_child_mtrls = $this->find_child_mtrls_for_parent( $parent_mtrl );
            $children_to_remove   = array_diff( $existing_child_mtrls, $child_mtrls );

            foreach ( $children_to_remove as $child_mtrl ) {
                $child_mtrl = trim( (string) $child_mtrl );
                if ( '' === $child_mtrl ) {
                    continue;
                }

                $child_product_id = $this->find_product_id_by_mtrl( $child_mtrl );
                if ( $child_product_id <= 0 ) {
                    continue;
                }

                $existing_parent_meta = get_post_meta( $child_product_id, self::META_RELATED_ITEM_MTRL, true );
                $existing_parent      = is_array( $existing_parent_meta ) ? (string) reset( $existing_parent_meta ) : (string) $existing_parent_meta;

                if ( $existing_parent === $parent_mtrl ) {
                    delete_post_meta( $child_product_id, self::META_RELATED_ITEM_MTRL );
                }
            }

            foreach ( $child_mtrls as $child_mtrl ) {
                $child_mtrl = trim( (string) $child_mtrl );
                if ( '' === $child_mtrl || $child_mtrl === $parent_mtrl ) {
                    continue;
                }

                $child_product_id = $this->find_product_id_by_mtrl( $child_mtrl );
                if ( $child_product_id <= 0 ) {
                    continue;
                }

                $existing_parent_meta = get_post_meta( $child_product_id, self::META_RELATED_ITEM_MTRL, true );
                $existing_parent      = is_array( $existing_parent_meta ) ? (string) reset( $existing_parent_meta ) : (string) $existing_parent_meta;

                if ( $existing_parent !== $parent_mtrl ) {
                    update_post_meta( $child_product_id, self::META_RELATED_ITEM_MTRL, $parent_mtrl );
                }
            }
        }

        /**
         * Schedule a colour variation synchronisation for after the import run completes.
         *
         * @param int               $product_id
         * @param string            $mtrl
         * @param array<int,string> $related_item_mtrls
         * @param string            $colour_taxonomy
         * @return void
         */
        protected function queue_colour_variation_sync( $product_id, $mtrl, array $related_item_mtrls, $colour_taxonomy ) {
            $product_id      = (int) $product_id;
            $colour_taxonomy = (string) $colour_taxonomy;

            if ( $product_id <= 0 || '' === $colour_taxonomy ) {
                return;
            }

            if ( ! $this->is_variable_product_handling_enabled() ) {
                $this->log(
                    'debug',
                    'Skipping colour variation queue because variable product handling is disabled.',
                    array(
                        'product_id'      => $product_id,
                        'mtrl'            => (string) $mtrl,
                        'colour_taxonomy' => $colour_taxonomy,
                    )
                );
                return;
            }

            $related_item_mtrls = array_values(
                array_unique(
                    array_filter(
                        array_map( 'strval', $related_item_mtrls )
                    )
                )
            );

            if ( empty( $related_item_mtrls ) ) {
                return;
            }

            $payload = array(
                'product_id'          => $product_id,
                'mtrl'                => (string) $mtrl,
                'related_item_mtrls'  => $related_item_mtrls,
                'colour_taxonomy'     => $colour_taxonomy,
            );

            $hash = md5( wp_json_encode( $payload ) );
            $this->pending_colour_variation_syncs[ $hash ] = $payload;
        }

        /**
         * Queue a single product for conversion into a variable product with a matching variation.
         *
         * @param int         $product_id
         * @param int         $colour_term_id
         * @param string      $colour_taxonomy
         * @param string      $sku
         * @param string|null $price_value
         * @param int|null    $stock_amount
         * @param string      $mtrl
         * @param bool        $should_backorder
         * @param array       $additional_attributes
         * @return void
         */
        protected function queue_single_product_variation( $product_id, $colour_term_id, $colour_taxonomy, $sku, $price_value, $stock_amount, $mtrl, $should_backorder, array $additional_attributes = array() ) {
            $product_id      = (int) $product_id;
            $colour_taxonomy = (string) $colour_taxonomy;

            if ( $product_id <= 0 || '' === $colour_taxonomy ) {
                return;
            }

            if ( ! $this->is_variable_product_handling_enabled() ) {
                $this->log(
                    'debug',
                    'Skipping variable product conversion queue because variable product handling is disabled.',
                    array(
                        'product_id'      => $product_id,
                        'colour_term_id'  => (int) $colour_term_id,
                        'colour_taxonomy' => $colour_taxonomy,
                        'mtrl'            => (string) $mtrl,
                    )
                );
                return;
            }

            $sanitized_additional = array();

            foreach ( $additional_attributes as $attribute_payload ) {
                if ( ! is_array( $attribute_payload ) ) {
                    continue;
                }

                $taxonomy       = isset( $attribute_payload['taxonomy'] ) ? (string) $attribute_payload['taxonomy'] : '';
                $term_id        = isset( $attribute_payload['term_id'] ) ? (int) $attribute_payload['term_id'] : 0;
                $attribute_slug = isset( $attribute_payload['attribute_slug'] ) ? (string) $attribute_payload['attribute_slug'] : '';
                $is_variation   = isset( $attribute_payload['is_variation'] ) ? (bool) $attribute_payload['is_variation'] : true;

                if ( '' === $taxonomy || $term_id <= 0 ) {
                    continue;
                }

                $sanitized_additional[] = array(
                    'taxonomy'       => $taxonomy,
                    'term_id'        => $term_id,
                    'attribute_slug' => $attribute_slug,
                    'is_variation'   => $is_variation,
                );
            }

            $payload = array(
                'product_id'      => $product_id,
                'colour_term_id'  => (int) $colour_term_id,
                'colour_taxonomy' => $colour_taxonomy,
                'sku'             => (string) $sku,
                'price_value'     => ( null === $price_value || '' === $price_value ) ? null : (string) $price_value,
                'stock_amount'    => ( null === $stock_amount ) ? null : (int) $stock_amount,
                'mtrl'            => (string) $mtrl,
                'backorder'       => (bool) $should_backorder,
                'additional_attributes' => $sanitized_additional,
            );

            $hash = md5( wp_json_encode( $payload ) );
            $this->pending_single_product_variations[ $hash ] = $payload;
        }

        /**
         * Ensure the supplied product is stored as a variable product.
         *
         * @param int $product_id
         * @return WC_Product|false
         */
        protected function ensure_product_is_variable( $product_id ) {
            $product_id = (int) $product_id;

            if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
                return false;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return false;
            }

            if ( 'variable' === $product->get_type() ) {
                return $product;
            }

            if ( function_exists( 'wp_set_object_terms' ) ) {
                wp_set_object_terms( $product_id, 'variable', 'product_type' );
            }

            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $product_id );
            }

            if ( function_exists( 'clean_post_cache' ) ) {
                clean_post_cache( $product_id );
            }

            if ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) {
                WC_Cache_Helper::invalidate_cache_group( 'products' );
            }

		if ( class_exists( 'WC_Product_Variable' ) ) {
			$product = new WC_Product_Variable( $product_id );
		} else {
			$product = wc_get_product( $product_id );
		}
		if ( ! $product ) {
			return false;
		}

		if ( method_exists( $product, 'get_status' ) && method_exists( $product, 'set_status' ) ) {
			$current_status = $product->get_status();
			if ( 'publish' !== $current_status ) {
				$product->set_status( 'publish' );
			}
		}

		if ( method_exists( $product, 'set_regular_price' ) ) {
			$product->set_regular_price( '' );
		}

            if ( method_exists( $product, 'set_sale_price' ) ) {
                $product->set_sale_price( '' );
            }

            if ( method_exists( $product, 'set_price' ) ) {
                $product->set_price( '' );
            }

            if ( method_exists( $product, 'set_manage_stock' ) ) {
                $product->set_manage_stock( false );
            }

            if ( method_exists( $product, 'set_stock_quantity' ) ) {
                $product->set_stock_quantity( null );
            }

            if ( method_exists( $product, 'set_backorders' ) ) {
                $product->set_backorders( 'no' );
            }

            if ( method_exists( $product, 'save' ) ) {
                $product->save();
            }

            return $product;
        }

        /** @return void */
        protected function process_pending_single_product_variations() {
            $queue_size = count( $this->pending_single_product_variations );

            if ( $queue_size > 0 && ! $this->is_variable_product_handling_enabled() ) {
                $this->log(
                    'debug',
                    'Skipping queued single product variation conversions because variable product handling is disabled.',
                    array( 'queue_size' => $queue_size )
                );
                $this->pending_single_product_variations = array();
                return;
            }

            if ( ! function_exists( 'wc_get_product' ) ) {
                $this->pending_single_product_variations = array();
                return;
            }

            $queue = $this->pending_single_product_variations;
            $this->pending_single_product_variations = array();

            foreach ( $queue as $payload ) {
                if ( ! is_array( $payload ) ) {
                    continue;
                }

                $product_id      = isset( $payload['product_id'] ) ? (int) $payload['product_id'] : 0;
                $colour_term_id  = isset( $payload['colour_term_id'] ) ? (int) $payload['colour_term_id'] : 0;
                $colour_taxonomy = isset( $payload['colour_taxonomy'] ) ? (string) $payload['colour_taxonomy'] : '';

                if ( $product_id <= 0 || $colour_term_id <= 0 || '' === $colour_taxonomy ) {
                    continue;
                }

                $term = get_term( $colour_term_id, $colour_taxonomy );
                if ( ! $term || is_wp_error( $term ) ) {
                    $this->log(
                        'debug',
                        'Skipped single product variation conversion because the colour term was missing.',
                        array(
                            'product_id'     => $product_id,
                            'colour_term_id' => $colour_term_id,
                            'colour_taxonomy'=> $colour_taxonomy,
                        )
                    );
                    continue;
                }

                $product = $this->ensure_product_is_variable( $product_id );
                if ( ! $product ) {
                    continue;
                }

                $this->ensure_parent_colour_attribute_terms( $product_id, $colour_taxonomy, array( $colour_term_id ) );

                $additional_attributes = array();

                if ( isset( $payload['additional_attributes'] ) && is_array( $payload['additional_attributes'] ) ) {
                    foreach ( $payload['additional_attributes'] as $attribute_payload ) {
                        if ( ! is_array( $attribute_payload ) ) {
                            continue;
                        }

                        $taxonomy       = isset( $attribute_payload['taxonomy'] ) ? (string) $attribute_payload['taxonomy'] : '';
                        $term_id        = isset( $attribute_payload['term_id'] ) ? (int) $attribute_payload['term_id'] : 0;
                        $attribute_slug = isset( $attribute_payload['attribute_slug'] ) ? (string) $attribute_payload['attribute_slug'] : '';
                        $is_variation   = isset( $attribute_payload['is_variation'] ) ? (bool) $attribute_payload['is_variation'] : true;

                        if ( '' === $taxonomy || $term_id <= 0 ) {
                            continue;
                        }

                        $this->ensure_parent_attribute_terms( $product_id, $taxonomy, array( $term_id ), $attribute_slug, $is_variation );

                        $additional_attributes[] = array(
                            'taxonomy' => $taxonomy,
                            'term_id'  => $term_id,
                        );
                    }
                }

                $price_value      = isset( $payload['price_value'] ) ? $payload['price_value'] : null;
                $stock_amount     = isset( $payload['stock_amount'] ) ? $payload['stock_amount'] : null;
                $sku              = isset( $payload['sku'] ) ? (string) $payload['sku'] : '';
                $mtrl             = isset( $payload['mtrl'] ) ? (string) $payload['mtrl'] : '';
                $should_backorder = isset( $payload['backorder'] ) ? (bool) $payload['backorder'] : false;

                $variation_id = $this->ensure_colour_variation(
                    $product_id,
                    $colour_term_id,
                    $colour_taxonomy,
                    $sku,
                    $price_value,
                    $stock_amount,
                    $mtrl,
                    $should_backorder,
                    array(),
                    $additional_attributes
                );

                if ( $variation_id > 0 && '' !== $mtrl ) {
                    update_post_meta( $variation_id, self::META_MTRL, $mtrl );
                }
            }
        }

        /** @return void */
        protected function process_pending_colour_variation_syncs() {
            $queue_size = count( $this->pending_colour_variation_syncs );

            if ( $queue_size > 0 && ! $this->is_variable_product_handling_enabled() ) {
                $this->log(
                    'debug',
                    'Skipping queued colour variation synchronisation requests because variable product handling is disabled.',
                    array( 'queue_size' => $queue_size )
                );
                $this->pending_colour_variation_syncs = array();
                return;
            }

            if ( ! function_exists( 'wc_get_product' ) ) {
                $this->pending_colour_variation_syncs = array();
                return;
            }

            $queue = $this->pending_colour_variation_syncs;
            $this->pending_colour_variation_syncs = array();

            foreach ( $queue as $payload ) {
                if ( ! is_array( $payload ) ) {
                    continue;
                }

                $product_id      = isset( $payload['product_id'] ) ? (int) $payload['product_id'] : 0;
                $colour_taxonomy = isset( $payload['colour_taxonomy'] ) ? (string) $payload['colour_taxonomy'] : '';
                $related_mtrls   = isset( $payload['related_item_mtrls'] ) && is_array( $payload['related_item_mtrls'] )
                    ? array_values( array_unique( array_filter( array_map( 'strval', $payload['related_item_mtrls'] ) ) ) )
                    : array();
                $mtrl = isset( $payload['mtrl'] ) ? (string) $payload['mtrl'] : '';

                if ( $product_id <= 0 || '' === $colour_taxonomy || empty( $related_mtrls ) ) {
                    continue;
                }

                $this->sync_related_colour_variations( $product_id, $mtrl, $related_mtrls, $colour_taxonomy );
            }
        }

        /**
         * Ensure the parent product exposes the supplied colour term IDs as attribute options.
         *
         * @param int               $product_id
         * @param string            $colour_taxonomy
         * @param array<int,int>    $term_ids
         * @return void
         */
        protected function ensure_parent_colour_attribute_terms( $product_id, $colour_taxonomy, array $term_ids ) {
            $product_id      = (int) $product_id;
            $colour_taxonomy = (string) $colour_taxonomy;
            $term_ids        = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );

            if ( empty( $term_ids ) ) {
                if ( $product_id <= 0 || '' === $colour_taxonomy ) {
                    return;
                }

                if ( ! $this->is_variable_product_handling_enabled() ) {
                    return;
                }

                if ( ! function_exists( 'wc_get_product' ) ) {
                    return;
                }

                $product = wc_get_product( $product_id );
                if ( ! $product || ! method_exists( $product, 'get_attributes' ) ) {
                    return;
                }

                $attribute_slug = $this->resolve_colour_attribute_slug();
                $attribute_key  = $colour_taxonomy;

                if ( function_exists( 'wc_attribute_taxonomy_name' ) && '' !== $attribute_slug ) {
                    $resolved_name = wc_attribute_taxonomy_name( $attribute_slug );
                    if ( '' !== $resolved_name ) {
                        $attribute_key = $resolved_name;
                    }
                }

                $attributes = $product->get_attributes();
                $removed    = false;

                foreach ( $attributes as $key => $attribute ) {
                    $name = '';

                    if ( $attribute instanceof WC_Product_Attribute ) {
                        $name = $attribute->get_name();
                    } elseif ( is_array( $attribute ) && isset( $attribute['name'] ) ) {
                        $name = (string) $attribute['name'];
                    } elseif ( is_string( $key ) ) {
                        $name = (string) $key;
                    }

                    if ( $name === $colour_taxonomy || $name === $attribute_key ) {
                        unset( $attributes[ $key ] );
                        $removed = true;
                        break;
                    }
                }

                if ( $removed ) {
                    $product->set_attributes( $attributes );
                    if ( method_exists( $product, 'save' ) ) {
                        $product->save();
                    }
                }

                return;
            }

            $this->ensure_parent_attribute_terms(
                $product_id,
                $colour_taxonomy,
                $term_ids,
                $this->resolve_colour_attribute_slug(),
                true,
                'colour'
            );
        }

        /**
         * Ensure the parent product exposes the supplied attribute terms as options.
         *
         * @param int            $product_id
         * @param string         $taxonomy
         * @param array<int,int> $term_ids
         * @param string         $attribute_slug
         * @param bool           $is_variation
         * @param string         $log_attribute
         * @return void
         */
        protected function ensure_parent_attribute_terms( $product_id, $taxonomy, array $term_ids, $attribute_slug, $is_variation = true, $log_attribute = '' ) {
            $product_id = (int) $product_id;
            $taxonomy   = (string) $taxonomy;
            $term_ids   = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );

            if ( $product_id <= 0 || '' === $taxonomy || empty( $term_ids ) ) {
                return;
            }

            if ( ! $this->is_variable_product_handling_enabled() ) {
                $this->log(
                    'debug',
                    'Skipping parent attribute assignment because variable product handling is disabled.',
                    array(
                        'product_id'      => $product_id,
                        'taxonomy'        => $taxonomy,
                        'attribute_slug'  => $attribute_slug,
                        'attribute_label' => $log_attribute,
                        'term_ids'        => $term_ids,
                    )
                );
                return;
            }

            if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Attribute' ) ) {
                return;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return;
            }

            $attribute_key = $taxonomy;
            $attribute_id  = 0;

            if ( function_exists( 'wc_attribute_taxonomy_name' ) && '' !== $attribute_slug ) {
                $resolved_name = wc_attribute_taxonomy_name( $attribute_slug );
                if ( '' !== $resolved_name ) {
                    $attribute_key = $resolved_name;
                }
            }

            if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) && '' !== $attribute_slug ) {
                $attribute_id = (int) wc_attribute_taxonomy_id_by_name( $attribute_slug );
            }

            if ( '' === $attribute_key ) {
                $attribute_key = $taxonomy;
            }

            $attributes       = $product->get_attributes();
            $matched_key      = null;
            $existing_options = array();

            foreach ( $attributes as $key => $attribute ) {
                $name = '';

                if ( $attribute instanceof WC_Product_Attribute ) {
                    $name             = $attribute->get_name();
                    $existing_options = (array) $attribute->get_options();
                } elseif ( is_array( $attribute ) ) {
                    $name             = isset( $attribute['name'] ) ? (string) $attribute['name'] : '';
                    $existing_options = isset( $attribute['options'] ) ? (array) $attribute['options'] : array();
                }

                if ( $name === $taxonomy || ( '' !== $attribute_key && $name === $attribute_key ) ) {
                    $matched_key = $key;
                    break;
                }
            }

            $sorted_existing = array_map( 'intval', $existing_options );
            sort( $sorted_existing );

            $sorted_terms = array_map( 'intval', $term_ids );
            sort( $sorted_terms );

            if ( $sorted_existing === $sorted_terms && null !== $matched_key ) {
                $attribute = $attributes[ $matched_key ];
                if ( $attribute instanceof WC_Product_Attribute && (bool) $attribute->get_variation() === (bool) $is_variation ) {
                    return;
                }
            }

            $attribute_object = new WC_Product_Attribute();
            $attribute_object->set_id( $attribute_id );
            $attribute_object->set_name( $attribute_key );
            $attribute_object->set_options( $term_ids );
            $attribute_object->set_visible( true );
            $attribute_object->set_variation( (bool) $is_variation );
            $this->set_attribute_taxonomy_flag( $attribute_object, true );

            if ( null === $matched_key ) {
                $attributes[ $attribute_key ] = $attribute_object;
            } else {
                $attributes[ $matched_key ] = $attribute_object;
            }

            $product->set_attributes( $attributes );
            $product->save();
        }

        /**
         * Ensure the current product exposes colour variations for related Softone materials.
         *
         * @param int                 $product_id
         * @param string              $current_mtrl
         * @param array<int,string>   $related_item_mtrls
         * @param string              $colour_taxonomy
         * @return void
         */
        protected function sync_related_colour_variations( $product_id, $current_mtrl, array $related_item_mtrls, $colour_taxonomy ) {
            $product_id      = (int) $product_id;
            $colour_taxonomy = (string) $colour_taxonomy;
            $related_item_mtrls = array_values( array_filter( array_map( 'strval', $related_item_mtrls ) ) );

            if ( $product_id <= 0 || '' === $colour_taxonomy || empty( $related_item_mtrls ) ) {
                return;
            }

            if ( ! $this->is_variable_product_handling_enabled() ) {
                $this->log(
                    'debug',
                    'Skipping related colour variation synchronisation because variable product handling is disabled.',
                    array(
                        'product_id'         => $product_id,
                        'mtrl'               => (string) $current_mtrl,
                        'colour_taxonomy'    => $colour_taxonomy,
                        'related_item_mtrls' => $related_item_mtrls,
                    )
                );
                return;
            }

            if ( ! function_exists( 'wc_get_product' ) ) {
                return;
            }

            $variation_payloads = array();
            $parent_term_ids    = array();
            $additional_parent_terms = array();

            foreach ( $related_item_mtrls as $related_mtrl ) {
                $source_product_id = $this->find_product_id_by_mtrl( $related_mtrl );
                $source_is_variation = false;

                if ( $source_product_id <= 0 ) {
                    $variation_id = $this->find_variation_id_by_mtrl( $related_mtrl );
                    if ( $variation_id > 0 ) {
                        $source_product_id  = $variation_id;
                        $source_is_variation = true;
                    }
                }

                if ( $source_product_id <= 0 ) {
                    continue;
                }

                $source_product = wc_get_product( $source_product_id );
                if ( ! $source_product ) {
                    continue;
                }

                $colour_term_id = $this->find_colour_term_id_for_product( $source_product, $colour_taxonomy );
                if ( $colour_term_id <= 0 ) {
                    continue;
                }

                $parent_term_ids[] = $colour_term_id;

                $additional_attributes = array();
                $size_taxonomy         = $this->normalize_attribute_taxonomy_name( 'size' );

                if ( '' !== $size_taxonomy ) {
                    $size_term_id = $this->find_attribute_term_id_for_product( $source_product, $size_taxonomy );

                    if ( $size_term_id > 0 ) {
                        $additional_attributes[] = array(
                            'taxonomy'       => $size_taxonomy,
                            'term_id'        => $size_term_id,
                            'attribute_slug' => 'size',
                            'is_variation'   => true,
                        );

                        if ( ! isset( $additional_parent_terms[ $size_taxonomy ] ) ) {
                            $additional_parent_terms[ $size_taxonomy ] = array(
                                'term_ids'       => array(),
                                'attribute_slug' => 'size',
                                'is_variation'   => true,
                            );
                        }

                        $additional_parent_terms[ $size_taxonomy ]['term_ids'][] = $size_term_id;
                    }
                }

                $sku = method_exists( $source_product, 'get_sku' ) ? (string) $source_product->get_sku() : '';

                $price_value = null;
                if ( method_exists( $source_product, 'get_regular_price' ) ) {
                    $price_value = (string) $source_product->get_regular_price();
                    if ( '' === $price_value && method_exists( $source_product, 'get_price' ) ) {
                        $price_value = (string) $source_product->get_price();
                    }
                    if ( '' === $price_value ) {
                        $price_value = null;
                    }
                }

                $manage_stock = method_exists( $source_product, 'get_manage_stock' ) ? (bool) $source_product->get_manage_stock() : false;
                $stock_amount = null;
                if ( $manage_stock && method_exists( $source_product, 'get_stock_quantity' ) ) {
                    $stock_quantity = $source_product->get_stock_quantity();
                    if ( null !== $stock_quantity ) {
                        $stock_amount = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $stock_quantity ) : (int) $stock_quantity;
                    }
                }

                $backorders         = method_exists( $source_product, 'get_backorders' ) ? (string) $source_product->get_backorders() : 'no';
                $should_backorder   = in_array( $backorders, array( 'notify', 'yes' ), true );

                $source_payload_hash = get_post_meta( $source_product_id, self::META_PAYLOAD_HASH, true );
                $source_last_sync    = get_post_meta( $source_product_id, self::META_LAST_SYNC, true );

                $variation_payloads[] = array(
                    'colour_term_id'   => $colour_term_id,
                    'sku'              => $sku,
                    'price_value'      => $price_value,
                    'stock_amount'     => $stock_amount,
                    'mtrl'             => $related_mtrl,
                    'backorder'        => $should_backorder,
                    'meta'             => array(
                        self::META_PAYLOAD_HASH => $source_payload_hash,
                        self::META_LAST_SYNC    => $source_last_sync,
                    ),
                    'source_id'        => $source_product_id,
                    'source_is_variation' => $source_is_variation,
                    'additional_attributes' => $additional_attributes,
                );
            }

            $parent_term_ids = array_values( array_unique( array_filter( array_map( 'intval', $parent_term_ids ) ) ) );

            if ( empty( $variation_payloads ) ) {
                $this->ensure_parent_colour_attribute_terms( $product_id, $colour_taxonomy, array() );
                return;
            }

            if ( ! $this->ensure_product_is_variable( $product_id ) ) {
                return;
            }

            $this->ensure_parent_colour_attribute_terms( $product_id, $colour_taxonomy, $parent_term_ids );

            foreach ( $additional_parent_terms as $taxonomy => $attribute_data ) {
                $term_ids = array();
                if ( isset( $attribute_data['term_ids'] ) && is_array( $attribute_data['term_ids'] ) ) {
                    $term_ids = array_values( array_unique( array_filter( array_map( 'intval', $attribute_data['term_ids'] ) ) ) );
                }

                if ( empty( $term_ids ) ) {
                    continue;
                }

                $attribute_slug = isset( $attribute_data['attribute_slug'] ) ? (string) $attribute_data['attribute_slug'] : '';
                $is_variation   = isset( $attribute_data['is_variation'] ) ? (bool) $attribute_data['is_variation'] : true;

                $this->ensure_parent_attribute_terms( $product_id, $taxonomy, $term_ids, $attribute_slug, $is_variation );
            }

            foreach ( $variation_payloads as $variation_payload ) {
                $variation_id = $this->ensure_colour_variation(
                    $product_id,
                    $variation_payload['colour_term_id'],
                    $colour_taxonomy,
                    $variation_payload['sku'],
                    $variation_payload['price_value'],
                    $variation_payload['stock_amount'],
                    $variation_payload['mtrl'],
                    $variation_payload['backorder'],
                    $variation_payload['meta'],
                    isset( $variation_payload['additional_attributes'] ) ? $variation_payload['additional_attributes'] : array()
                );

                if ( $variation_id <= 0 ) {
                    continue;
                }

                if ( '' !== $variation_payload['mtrl'] ) {
                    update_post_meta( $variation_id, self::META_MTRL, $variation_payload['mtrl'] );
                }

                $meta = $variation_payload['meta'];

                if ( isset( $meta[ self::META_PAYLOAD_HASH ] ) && '' !== $meta[ self::META_PAYLOAD_HASH ] ) {
                    update_post_meta( $variation_id, self::META_PAYLOAD_HASH, $meta[ self::META_PAYLOAD_HASH ] );
                }

                if ( isset( $meta[ self::META_LAST_SYNC ] ) && '' !== $meta[ self::META_LAST_SYNC ] ) {
                    update_post_meta( $variation_id, self::META_LAST_SYNC, $meta[ self::META_LAST_SYNC ] );
                }

                if ( ! $variation_payload['source_is_variation'] && $variation_payload['source_id'] !== $product_id ) {
                    // Disabled to avoid drafting the source single product when variations are generated.
                    // $this->maybe_draft_single_product_source( $variation_payload['mtrl'], $product_id );
                }
            }
        }

        /**
         * Determine the colour attribute term ID assigned to a product.
         *
         * @param WC_Product|false $product
         * @param string           $colour_taxonomy
         * @return int
         */
        protected function find_colour_term_id_for_product( $product, $colour_taxonomy ) {
			if ( ! $product || ! method_exists( $product, 'get_attributes' ) ) {
				return 0;
			}

			$colour_taxonomy = (string) $colour_taxonomy;
			if ( '' === $colour_taxonomy ) {
				return 0;
			}

			$normalized_key = $colour_taxonomy;
			if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
				$normalized_key = wc_attribute_taxonomy_name( $this->resolve_colour_attribute_slug() );
			}

			if ( class_exists( 'WC_Product_Variation' ) && $product instanceof WC_Product_Variation ) {
				$attribute_keys = array( $colour_taxonomy, $normalized_key );

				if ( function_exists( 'wc_variation_attribute_name' ) ) {
					$attribute_keys[] = wc_variation_attribute_name( $colour_taxonomy );
					if ( $normalized_key !== $colour_taxonomy ) {
						$attribute_keys[] = wc_variation_attribute_name( $normalized_key );
					}
				} else {
					$attribute_keys[] = 'attribute_' . $colour_taxonomy;
					if ( $normalized_key !== $colour_taxonomy ) {
						$attribute_keys[] = 'attribute_' . $normalized_key;
					}
				}

				$attribute_keys = array_values( array_unique( array_filter( array_map( 'strval', $attribute_keys ) ) ) );

				foreach ( $attribute_keys as $attribute_key ) {
					if ( '' === $attribute_key ) {
						continue;
					}

					$raw_option = $product->get_attribute( $attribute_key );
					if ( '' === $raw_option ) {
						continue;
					}

					$term_id = $this->normalise_colour_term_option( $raw_option, $colour_taxonomy );
					if ( $term_id > 0 ) {
						return $term_id;
					}
				}
			}

			$attributes = $product->get_attributes();
			foreach ( $attributes as $key => $attribute ) {
				$name = '';
				$options = array();

				if ( $attribute instanceof WC_Product_Attribute ) {
					$name    = $attribute->get_name();
					$options = (array) $attribute->get_options();
				} elseif ( is_array( $attribute ) ) {
					$name    = isset( $attribute['name'] ) ? (string) $attribute['name'] : '';
					$options = isset( $attribute['options'] ) ? (array) $attribute['options'] : array();
				} elseif ( is_scalar( $attribute ) ) {
					$name    = (string) $key;
					$options = array( $attribute );
				} else {
					continue;
				}

				$name = (string) $name;
				if ( 0 === strpos( $name, 'attribute_' ) ) {
					$name = substr( $name, strlen( 'attribute_' ) );
				}

				if ( $name === $colour_taxonomy || $name === $normalized_key ) {
					foreach ( $options as $option ) {
						$term_id = $this->normalise_colour_term_option( $option, $colour_taxonomy );
						if ( $term_id > 0 ) {
							return $term_id;
						}
					}
				}
			}

			if ( method_exists( $product, 'get_id' ) && function_exists( 'wp_get_post_terms' ) ) {
				$terms = wp_get_post_terms( (int) $product->get_id(), $colour_taxonomy, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$term = reset( $terms );
					if ( false !== $term ) {
						return (int) $term;
					}
				}
			}

			return 0;
		}

        /**
         * Refresh the list of Softone materials associated with a parent product.
         *
         * @param string $parent_mtrl Parent Softone material identifier.
         * @return void
         */
        protected function refresh_related_item_children( $parent_mtrl ) {
            $parent_mtrl = trim( (string) $parent_mtrl );
            if ( '' === $parent_mtrl ) {
                return;
            }

            $product_id = $this->find_product_id_by_mtrl( $parent_mtrl );
            if ( $product_id <= 0 ) {
                return;
            }

            $child_mtrls = $this->find_child_mtrls_for_parent( $parent_mtrl );
            $child_mtrls = array_values( array_unique( array_filter( array_map( 'strval', $child_mtrls ) ) ) );

            if ( empty( $child_mtrls ) ) {
                delete_post_meta( $product_id, self::META_RELATED_ITEM_MTRLS );
            } else {
                update_post_meta( $product_id, self::META_RELATED_ITEM_MTRLS, $child_mtrls );
            }

            if ( ! empty( $child_mtrls ) && function_exists( 'wc_attribute_taxonomy_name' ) ) {
                $colour_taxonomy = wc_attribute_taxonomy_name( $this->resolve_colour_attribute_slug() );

                if ( '' !== $colour_taxonomy ) {
                    $queue_mtrls = array_values(
                        array_unique(
                            array_filter(
                                array_map(
                                    'strval',
                                    array_merge( array( $parent_mtrl ), $child_mtrls )
                                )
                            )
                        )
                    );

                    if ( ! empty( $queue_mtrls ) ) {
                        $this->queue_colour_variation_sync( $product_id, $parent_mtrl, $queue_mtrls, $colour_taxonomy );
                    }
                }
            }

            if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Attribute' ) ) {
                return;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return;
            }

            $attributes       = $product->get_attributes();
            $related_key      = null;
            $current_options  = array();

            foreach ( $attributes as $key => $attribute ) {
                $name = '';
                if ( $attribute instanceof WC_Product_Attribute ) {
                    $name = $attribute->get_name();
                } elseif ( is_array( $attribute ) && isset( $attribute['name'] ) ) {
                    $name = (string) $attribute['name'];
                }

                if ( 'related_item_mtrl' === $name ) {
                    $related_key = $key;
                    if ( $attribute instanceof WC_Product_Attribute ) {
                        $current_options = array_map( 'strval', (array) $attribute->get_options() );
                    } elseif ( is_array( $attribute ) && isset( $attribute['options'] ) ) {
                        $current_options = array_map( 'strval', (array) $attribute['options'] );
                    }
                    break;
                }
            }

            $sorted_children = $child_mtrls;
            sort( $sorted_children, SORT_NATURAL | SORT_FLAG_CASE );
            $sorted_existing = $current_options;
            sort( $sorted_existing, SORT_NATURAL | SORT_FLAG_CASE );

            if ( empty( $sorted_children ) ) {
                if ( null === $related_key ) {
                    return;
                }

                unset( $attributes[ $related_key ] );
                $product->set_attributes( $attributes );
                $product->save();
                return;
            }

            if ( null !== $related_key && $sorted_children === $sorted_existing ) {
                return;
            }

            $attribute_object = new WC_Product_Attribute();
            $attribute_object->set_id( 0 );
            $attribute_object->set_name( 'related_item_mtrl' );
            $attribute_object->set_options( $sorted_children );
            $attribute_object->set_visible( false );
            $attribute_object->set_variation( false );
            $this->set_attribute_taxonomy_flag( $attribute_object, false );

            if ( null === $related_key ) {
                $attributes['related_item_mtrl'] = $attribute_object;
            } else {
                $attributes[ $related_key ] = $attribute_object;
            }

            $product->set_attributes( $attributes );
            $product->save();
        }

        /**
         * Determine the term ID assigned to a product for a specific attribute taxonomy.
         *
         * @param WC_Product|false $product
         * @param string           $taxonomy
         * @return int
         */
        protected function find_attribute_term_id_for_product( $product, $taxonomy ) {
            if ( ! $product || ! method_exists( $product, 'get_attributes' ) ) {
                return 0;
            }

            $taxonomy = (string) $taxonomy;
            if ( '' === $taxonomy ) {
                return 0;
            }

            if ( class_exists( 'WC_Product_Variation' ) && $product instanceof WC_Product_Variation ) {
                $attribute_keys = array( $taxonomy );

                if ( function_exists( 'wc_variation_attribute_name' ) ) {
                    $attribute_keys[] = wc_variation_attribute_name( $taxonomy );
                } else {
                    $attribute_keys[] = 'attribute_' . ltrim( $taxonomy, '_' );
                }

                $attribute_keys = array_values( array_unique( array_filter( array_map( 'strval', $attribute_keys ) ) ) );

                foreach ( $attribute_keys as $attribute_key ) {
                    if ( '' === $attribute_key ) {
                        continue;
                    }

                    $raw_option = $product->get_attribute( $attribute_key );
                    if ( '' === $raw_option ) {
                        continue;
                    }

                    $term_id = $this->normalise_attribute_term_option( $raw_option, $taxonomy );
                    if ( $term_id > 0 ) {
                        return $term_id;
                    }
                }
            }

            $attributes = $product->get_attributes();

            foreach ( $attributes as $key => $attribute ) {
                $name    = '';
                $options = array();

                if ( $attribute instanceof WC_Product_Attribute ) {
                    $name    = $attribute->get_name();
                    $options = (array) $attribute->get_options();
                } elseif ( is_array( $attribute ) ) {
                    $name    = isset( $attribute['name'] ) ? (string) $attribute['name'] : '';
                    $options = isset( $attribute['options'] ) ? (array) $attribute['options'] : array();
                } elseif ( is_scalar( $attribute ) ) {
                    $name    = (string) $key;
                    $options = array( $attribute );
                } else {
                    continue;
                }

                if ( $name !== $taxonomy ) {
                    continue;
                }

                foreach ( (array) $options as $option ) {
                    $term_id = $this->normalise_attribute_term_option( $option, $taxonomy );
                    if ( $term_id > 0 ) {
                        return $term_id;
                    }
                }
            }

            return 0;
        }

        /**
         * Find the product post ID that owns the supplied Softone material identifier.
         *
         * @param string $mtrl Softone material identifier.
         * @return int
         */
		protected function find_product_id_by_mtrl( $mtrl ) {
			global $wpdb;

			$mtrl = trim( (string) $mtrl );
			if ( '' === $mtrl ) {
				return 0;
			}

			$query = $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s ORDER BY p.ID ASC LIMIT 1",
				self::META_MTRL,
				$mtrl,
				'product'
			);

			return (int) $wpdb->get_var( $query );
		}

		/**
		 * Find the variation post ID that owns the supplied Softone material identifier.
		 *
		 * @param string $mtrl Softone material identifier.
		 * @return int
		 */
		protected function find_variation_id_by_mtrl( $mtrl ) {
			global $wpdb;

			$mtrl = trim( (string) $mtrl );
			if ( '' === $mtrl ) {
				return 0;
			}

			$query = $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s ORDER BY p.ID ASC LIMIT 1",
				self::META_MTRL,
				$mtrl,
				'product_variation'
			);

			return (int) $wpdb->get_var( $query );
		}

        /**
         * Locate all Softone materials that reference the supplied parent material.
         *
         * @param string $parent_mtrl Softone material identifier referenced by related items.
         * @return array<int,string>
         */
        protected function find_child_mtrls_for_parent( $parent_mtrl ) {
            global $wpdb;

            $parent_mtrl = trim( (string) $parent_mtrl );
            if ( '' === $parent_mtrl ) {
                return array();
            }

            $query = $wpdb->prepare(
                "SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} rel INNER JOIN {$wpdb->posts} p ON p.ID = rel.post_id LEFT JOIN {$wpdb->postmeta} m ON m.post_id = rel.post_id AND m.meta_key = %s WHERE rel.meta_key = %s AND rel.meta_value = %s AND p.post_type = %s",
                self::META_MTRL,
                self::META_RELATED_ITEM_MTRL,
                $parent_mtrl,
                'product'
            );

            $results  = (array) $wpdb->get_col( $query );
            $children = array();

            foreach ( $results as $value ) {
                $value = trim( (string) $value );
                if ( '' !== $value ) {
                    $children[] = $value;
                }
            }

            $children = array_values( array_unique( $children ) );
            sort( $children, SORT_NATURAL | SORT_FLAG_CASE );

            return $children;
        }

        /**
         * Parse a list of related Softone material identifiers from the payload.
         *
         * @param string $value
         * @return array<int,string>
         */
        protected function parse_related_item_mtrls( $value ) {
            $value = trim( (string) $value );
            if ( '' === $value ) {
                return array();
            }

            $normalized = preg_replace( '/[\r\n]+/', ' ', $value );
            $parts      = preg_split( '/[\s\|,;]+/', (string) $normalized, -1, PREG_SPLIT_NO_EMPTY );

            if ( false === $parts ) {
                return array();
            }

            $tokens = array();
            foreach ( $parts as $part ) {
                $part = trim( (string) $part );
                if ( '' !== $part ) {
                    $tokens[] = $part;
                }
            }

            $tokens = array_values( array_unique( $tokens ) );

            return $tokens;
        }

        /**
         * Determine whether the Softone payload included any related item fields.
         *
         * @param array<string,mixed> $data Payload from Softone.
         * @return bool
         */
        protected function received_related_item_payload( array $data ) {
            $related_keys = array(
                'softone_related_item_mtrl',
                'related_item_mtrl',
                'related_mtrl',
                'rel_mtrl',
                'softone_related_item_mtrls',
                'softone_related_item_mtrll',
                'related_item_mtrls',
                'related_item_mtrll',
            );

            foreach ( $related_keys as $key ) {
                if ( array_key_exists( $key, $data ) ) {
                    return true;
                }
            }

            return false;
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
 * @return array{attributes: array<int,WC_Product_Attribute>, terms: array, values: array, clear: array}
 */
protected function prepare_attribute_assignments( array $data, $product, array $fallback_attributes = array() ) {
        $assignments = array(
            'attributes'        => array(),
            'terms'             => array(),
            'values'            => array(),
            'clear'             => array(),
            'related_item_mtrl' => '',
        );

        $product_id_for_logging = 0;
        if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
            $product_id_for_logging = (int) $product->get_id();
        }

        $this->log(
            'debug',
            'Preparing attribute assignments for product.',
            array( 'product_id' => $product_id_for_logging )
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
            $this->set_attribute_taxonomy_flag( $attr, false );
            $assignments['attributes'][] = $attr;

            $this->log(
                'debug',
                'Added hidden softone_mtrl attribute to assignments.',
                array(
                    'product_id' => $product_id_for_logging,
                    'mtrl_value' => $mtrl_value,
                )
            );
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
    $related_mtrl = (string) $this->get_value(
        $data,
        array( 'softone_related_item_mtrl', 'related_item_mtrl', 'related_mtrl', 'rel_mtrl' )
    );
    $related_mtrl = trim( $related_mtrl );

    $related_mtrl_tokens = array();
    $related_list_keys   = array(
        'softone_related_item_mtrls',
        'softone_related_item_mtrll',
        'related_item_mtrls',
        'related_item_mtrll',
    );

    foreach ( $related_list_keys as $list_key ) {
        if ( isset( $data[ $list_key ] ) && '' !== trim( (string) $data[ $list_key ] ) ) {
            $raw_value = $data[ $list_key ];

            if ( is_array( $raw_value ) ) {
                foreach ( $raw_value as $token_value ) {
                    $related_mtrl_tokens = array_merge(
                        $related_mtrl_tokens,
                        $this->parse_related_item_mtrls( (string) $token_value )
                    );
                }
            } else {
                $related_mtrl_tokens = array_merge(
                    $related_mtrl_tokens,
                    $this->parse_related_item_mtrls( (string) $raw_value )
                );
            }
        }
    }

    if ( empty( $related_mtrl_tokens ) ) {
        $related_mtrl_tokens = $this->parse_related_item_mtrls( $related_mtrl );
    }

    if ( '' !== $related_mtrl ) {
        $related_mtrl_tokens[] = $related_mtrl;
    }

    $related_mtrl_tokens = array_values(
        array_unique(
            array_filter( array_map( 'strval', $related_mtrl_tokens ) )
        )
    );
    if ( $related_mtrl !== '' && class_exists( 'WC_Product_Attribute' ) ) {
        try {
            $attr = new WC_Product_Attribute();
            $attr->set_id( 0 );
            $attr->set_name( 'related_item_mtrl' );
            $attr->set_options( empty( $related_mtrl_tokens ) ? array( $related_mtrl ) : $related_mtrl_tokens );
            $attr->set_visible( false );
            $attr->set_variation( false );
            $this->set_attribute_taxonomy_flag( $attr, false );
            $assignments['attributes'][] = $attr;

            $this->log(
                'debug',
                'Added hidden related_item_mtrl attribute to assignments.',
                array(
                    'product_id'           => $product_id_for_logging,
                    'raw_value'            => $related_mtrl,
                    'parsed_related_count' => count( $related_mtrl_tokens ),
                )
            );
        } catch ( \Throwable $e ) {
            if ( method_exists( $product, 'get_id' ) ) {
                $pid = (int) $product->get_id();
                if ( $pid > 0 ) {
                    update_post_meta( $pid, self::META_RELATED_ITEM_MTRL, $related_mtrl );
                    if ( ! empty( $related_mtrl_tokens ) ) {
                        update_post_meta( $pid, self::META_RELATED_ITEM_MTRLS, $related_mtrl_tokens );
                    }
                }
            }
        }
    }

    // ---------------- Visible taxonomy attributes: Colour, Size, Brand ----------------
    $colour_value = $this->normalize_colour_value(
        trim( $this->get_value( $data, array( 'colour_name', 'color_name', 'colour', 'color' ) ) )
    );
    if ( $colour_value === '' && isset( $fallback_attributes['colour'] ) ) {
        $colour_value = $this->normalize_colour_value( (string) $fallback_attributes['colour'] );
    }

    $size_value  = $this->normalize_simple_attribute_value(
        $this->get_value( $data, array( 'size_name', 'size' ) )
    );
    $brand_value = $this->normalize_simple_attribute_value(
        $this->get_value( $data, array( 'brand_name', 'brand' ) )
    );

    // (slug => [Label, Value, Position])
    // For colour we resolve to pa_colour or pa_color safely
    $colour_slug = $this->resolve_colour_attribute_slug(); // 'colour' or 'color'
    $attribute_map = array(
        $colour_slug => array( __( 'Colour', 'softone-woocommerce-integration' ), $colour_value, 0, true ),
        'size'       => array( __( 'Size',   'softone-woocommerce-integration' ), $size_value,   1, true ),
        'brand'      => array( __( 'Brand',  'softone-woocommerce-integration' ), $brand_value,  2, false ),
    );

    foreach ( $attribute_map as $slug => $tuple ) {
        $tuple = array_values( $tuple );
        $tuple = array_pad( $tuple, 4, false );
        list( $label, $value, $position, $is_variation ) = $tuple;

        $taxonomy = $this->normalize_attribute_taxonomy_name( $slug );

        if ( '' === $value ) {
            if ( $taxonomy !== '' ) {
                $assignments['clear'][] = $taxonomy;
            }
            $this->log(
                'debug',
                'Skipping attribute because the value was empty.',
                array(
                    'product_id'    => $product_id_for_logging,
                    'attribute_slug'=> $slug,
                    'taxonomy'      => $taxonomy,
                )
            );
            continue;
        }

        $attribute_id = $this->ensure_attribute_taxonomy( $slug, $label );
        if ( ! $attribute_id ) {
            $this->log(
                'warning',
                'Unable to ensure attribute taxonomy for assignment.',
                array(
                    'product_id'    => $product_id_for_logging,
                    'attribute_slug'=> $slug,
                    'taxonomy'      => $taxonomy,
                )
            );
            continue;
        }

        $term_id = $this->ensure_attribute_term( $taxonomy, $value );
        if ( ! $term_id ) {
            $this->log(
                'warning',
                'Unable to ensure attribute term for assignment.',
                array(
                    'product_id'    => $product_id_for_logging,
                    'attribute_slug'=> $slug,
                    'taxonomy'      => $taxonomy,
                    'attribute_value' => $value,
                )
            );
            continue;
        }

        if ( class_exists( 'WC_Product_Attribute' ) ) {
            $attr = new WC_Product_Attribute();
            $attr->set_id( (int) $attribute_id );
            $attr->set_name( $taxonomy );
            $attr->set_options( array( (int) $term_id ) );
            $attr->set_position( (int) $position );
            $attr->set_visible( true );
            $attr->set_variation( (bool) $is_variation );
            $this->set_attribute_taxonomy_flag( $attr, true );

            $assignments['attributes'][]      = $attr;
            $assignments['terms'][ $taxonomy ] = array( (int) $term_id );
            $assignments['values'][ $taxonomy ] = $value;

            $this->log(
                'debug',
                'Prepared taxonomy attribute assignment.',
                array(
                    'product_id'      => $product_id_for_logging,
                    'attribute_slug'  => $slug,
                    'taxonomy'        => $taxonomy,
                    'attribute_id'    => (int) $attribute_id,
                    'term_id'         => (int) $term_id,
                    'is_variation'    => (bool) $is_variation,
                    'position'        => (int) $position,
                )
            );
        }
    }

    $assignments['related_item_mtrl']  = $related_mtrl;
    $assignments['related_item_mtrls'] = $related_mtrl_tokens;

    $this->log(
        'debug',
        'Finished preparing attribute assignments for product.',
        array(
            'product_id'               => $product_id_for_logging,
            'attribute_count'          => count( $assignments['attributes'] ),
            'taxonomy_assignment_keys' => array_keys( $assignments['terms'] ),
            'clear_taxonomies'         => $assignments['clear'],
        )
    );

    return $assignments;
}

/** @return string 'colour' or 'color' */
protected function resolve_colour_attribute_slug() {
    // If pa_colour already exists, use 'colour'
    if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) && wc_attribute_taxonomy_id_by_name( 'colour' ) ) {
        return 'colour';
    }
    // If pa_color exists (some stores use US spelling), use 'color'
    if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) && wc_attribute_taxonomy_id_by_name( 'color' ) ) {
        return 'color';
    }
    // Default to creating 'colour'
    return 'colour';
}


    /**
     * Determine the taxonomy name for a WooCommerce product attribute slug.
     *
     * Mirrors wc_attribute_taxonomy_name() while providing a graceful fallback
     * when WooCommerce helpers are not loaded yet.
     *
     * @param string $slug Attribute slug (e.g. 'colour').
     * @return string
     */
    protected function normalize_attribute_taxonomy_name( $slug ) {
        $slug = trim( (string) $slug );
        if ( '' === $slug ) {
            return '';
        }

        if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
            $taxonomy = wc_attribute_taxonomy_name( $slug );
            if ( '' !== $taxonomy ) {
                return $taxonomy;
            }
        }

        if ( function_exists( 'sanitize_title' ) ) {
            $normalized = sanitize_title( $slug );
        } else {
            $normalized = strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '_', $slug ) );
        }

        $normalized = trim( $normalized, '_' );
        if ( '' === $normalized ) {
            $normalized = strtolower( $slug );
        }

        if ( 0 !== strpos( $normalized, 'pa_' ) ) {
            $normalized = 'pa_' . $normalized;
        }

        return $normalized;
    }


    /**
     * Ensure the colour attribute assignment exists so we can create a variation.
     *
     * @param array  $attribute_assignments Attribute preparation output (passed by reference).
     * @param string $colour_taxonomy       Attribute taxonomy name.
     * @param string $colour_value          Human readable colour label.
     * @return int Colour term identifier or 0 on failure.
     */
    protected function ensure_colour_attribute_assignment( array &$attribute_assignments, $colour_taxonomy, $colour_value ) {
        $colour_taxonomy = trim( (string) $colour_taxonomy );
        $colour_value    = $this->normalize_colour_value( $colour_value );

        if ( '' === $colour_taxonomy || '' === $colour_value ) {
            return 0;
        }

        $attribute_id = $this->ensure_attribute_taxonomy(
            $this->resolve_colour_attribute_slug(),
            __( 'Colour', 'softone-woocommerce-integration' )
        );

        if ( ! $attribute_id ) {
            return 0;
        }

        $term_id = $this->ensure_attribute_term( $colour_taxonomy, $colour_value );
        if ( ! $term_id ) {
            return 0;
        }

        $term_id = (int) $term_id;

        if ( ! isset( $attribute_assignments['terms'] ) || ! is_array( $attribute_assignments['terms'] ) ) {
            $attribute_assignments['terms'] = array();
        }
        $attribute_assignments['terms'][ $colour_taxonomy ] = array( $term_id );

        if ( ! isset( $attribute_assignments['values'] ) || ! is_array( $attribute_assignments['values'] ) ) {
            $attribute_assignments['values'] = array();
        }
        $attribute_assignments['values'][ $colour_taxonomy ] = $colour_value;

        if ( ! isset( $attribute_assignments['clear'] ) || ! is_array( $attribute_assignments['clear'] ) ) {
            $attribute_assignments['clear'] = array();
        }

        $attribute_assignments['clear'] = array_values(
            array_diff( $attribute_assignments['clear'], array( $colour_taxonomy ) )
        );

        if ( ! isset( $attribute_assignments['attributes'] ) || ! is_array( $attribute_assignments['attributes'] ) ) {
            $attribute_assignments['attributes'] = array();
        }

        $updated = false;
        foreach ( $attribute_assignments['attributes'] as $index => $attribute ) {
            if ( $attribute instanceof WC_Product_Attribute ) {
                if ( $attribute->get_name() === $colour_taxonomy ) {
                    $attribute->set_id( (int) $attribute_id );
                    $attribute->set_options( array( $term_id ) );
                    $attribute->set_visible( true );
                    $attribute->set_variation( true );
                    $this->set_attribute_taxonomy_flag( $attribute, true );
                    $attribute_assignments['attributes'][ $index ] = $attribute;
                    $updated = true;
                    break;
                }
            } elseif ( is_array( $attribute ) && isset( $attribute['name'] ) && $attribute['name'] === $colour_taxonomy ) {
                $attribute['id']        = (int) $attribute_id;
                $attribute['options']   = array( $term_id );
                $attribute['position']  = isset( $attribute['position'] ) ? (int) $attribute['position'] : 0;
                $attribute['visible']   = true;
                $attribute['variation'] = true;
                $attribute_assignments['attributes'][ $index ] = $attribute;
                $updated = true;
                break;
            }
        }

        if ( ! $updated ) {
            if ( class_exists( 'WC_Product_Attribute' ) ) {
                $attribute_object = new WC_Product_Attribute();
                $attribute_object->set_id( (int) $attribute_id );
                $attribute_object->set_name( $colour_taxonomy );
                $attribute_object->set_options( array( $term_id ) );
                $attribute_object->set_position( 0 );
                $attribute_object->set_visible( true );
                $attribute_object->set_variation( true );
                $this->set_attribute_taxonomy_flag( $attribute_object, true );
                $attribute_assignments['attributes'][] = $attribute_object;
            } else {
                $attribute_assignments['attributes'][] = array(
                    'id'        => (int) $attribute_id,
                    'name'      => $colour_taxonomy,
                    'options'   => array( $term_id ),
                    'position'  => 0,
                    'visible'   => true,
                    'variation' => true,
                );
            }
        }

        return $term_id;
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

            $placeholders = array( '-', 'n/a', 'na', 'none' );
            if ( in_array( strtolower( $colour ), $placeholders, true ) ) {
                return '';
            }

            if ( function_exists( 'mb_convert_case' ) ) {
                return mb_convert_case( $colour, MB_CASE_TITLE, 'UTF-8' );
            }
            return ucwords( strtolower( $colour ) );
        }

        /**
         * Prepare a colour value for inclusion within an SKU suffix.
         *
         * Ensures the value is normalised, replaces separators with hyphens,
         * and strips out characters that WooCommerce may reject in SKUs.
         *
         * @param string $colour Colour name.
         * @return string
         */
        protected function format_colour_for_sku( $colour ) {
            $colour = $this->normalize_colour_value( $colour );
            if ( '' === $colour ) {
                return '';
            }

            $formatted = preg_replace( '/[^\p{L}\p{N}]+/u', '-', $colour );
            if ( null === $formatted ) {
                $formatted = $colour;
            }

            $formatted = preg_replace( '/-+/u', '-', (string) $formatted );
            if ( null === $formatted ) {
                $formatted = $colour;
            }

            return trim( (string) $formatted, '-' );
        }

    /**
     * Normalise simple attribute values (e.g., Size, Brand) and strip placeholders.
     *
     * Treats common placeholders like '-', 'n/a', 'na', 'none' as empty so that
     * we do not create meaningless attribute terms on products.
     *
     * @param mixed $value Raw attribute value.
     * @return string Normalised value or empty string when not meaningful.
     */
    protected function normalize_simple_attribute_value( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        $placeholders = array( '-', 'n/a', 'na', 'none' );
        if ( in_array( strtolower( $value ), $placeholders, true ) ) {
            return '';
        }

        return $value;
    }

    /**
     * Configure the taxonomy flag on a product attribute when supported.
     *
     * @param WC_Product_Attribute $attribute  Attribute instance.
     * @param bool                 $is_taxonomy Whether the attribute references a taxonomy.
     * @return void
     */
    protected function set_attribute_taxonomy_flag( $attribute, $is_taxonomy ) {
        if ( ! $attribute instanceof WC_Product_Attribute ) {
            return;
        }

        if ( method_exists( $attribute, 'set_taxonomy' ) ) {
            $attribute->set_taxonomy( (bool) $is_taxonomy );
            return;
        }

        if ( method_exists( $attribute, 'set_is_taxonomy' ) ) {
            $attribute->set_is_taxonomy( (bool) $is_taxonomy );
        }
    }

        /**
         * Convert a stored attribute option into a colour term ID.
         *
         * WooCommerce stores taxonomy attribute options as term IDs but stores custom
         * attributes as raw strings. Some integrations have historically persisted
         * colour taxonomy attributes with slugs instead of IDs, so we attempt to
         * resolve both formats.
         *
         * @param mixed  $option           Raw attribute option (ID, slug, or name).
         * @param string $colour_taxonomy Colour taxonomy name (e.g. `pa_colour`).
         * @return int Term ID or 0 when it cannot be resolved.
         */
        protected function normalise_colour_term_option( $option, $colour_taxonomy ) {
            return $this->normalise_attribute_term_option( $option, $colour_taxonomy );
        }

        /**
         * Normalise a WooCommerce attribute option into a term ID for the given taxonomy.
         *
         * @param mixed  $option
         * @param string $taxonomy
         * @return int
         */
        protected function normalise_attribute_term_option( $option, $taxonomy ) {
            if ( is_int( $option ) ) {
                return $option > 0 ? $option : 0;
            }

            if ( is_numeric( $option ) ) {
                $int_option = (int) $option;
                return $int_option > 0 ? $int_option : 0;
            }

            $taxonomy = (string) $taxonomy;
            if ( '' === $taxonomy ) {
                return 0;
            }

            $option = trim( (string) $option );
            if ( '' === $option ) {
                return 0;
            }

            if ( ! function_exists( 'get_term_by' ) ) {
                return 0;
            }

            $term = get_term_by( 'slug', $option, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                $term = get_term_by( 'name', $option, $taxonomy );
            }

            if ( $term && ! is_wp_error( $term ) && isset( $term->term_id ) ) {
                return (int) $term->term_id;
            }

            if ( function_exists( 'sanitize_title' ) ) {
                $sanitized = sanitize_title( $option );
                if ( $sanitized !== $option ) {
                    $term = get_term_by( 'slug', $sanitized, $taxonomy );
                    if ( $term && ! is_wp_error( $term ) && isset( $term->term_id ) ) {
                        return (int) $term->term_id;
                    }
                }
            }

            return 0;
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
                    $this->log(
                        'warning',
                        'Attribute taxonomy cache hit failed registration check.',
                        array(
                            'slug'          => $slug,
                            'label'         => $label,
                            'attribute_id'  => $attribute_id,
                        )
                    );
                    return 0;
                }

                $this->log(
                    'debug',
                    'Using cached attribute taxonomy.',
                    array(
                        'slug'         => $slug,
                        'label'        => $label,
                        'attribute_id' => $attribute_id,
                    )
                );
                return $attribute_id;
            }

            $this->cache_stats['attribute_taxonomy_cache_misses']++;

            $attribute_id = wc_attribute_taxonomy_id_by_name( $slug );
            if ( $attribute_id ) {
                $attribute_id = (int) $attribute_id;
                $this->attribute_taxonomy_cache[ $key ] = $attribute_id;

                if ( ! $this->ensure_attribute_taxonomy_is_registered( $slug, $label ) ) {
                    unset( $this->attribute_taxonomy_cache[ $key ] );
                    $this->log(
                        'warning',
                        'Existing attribute taxonomy failed registration check during ensure.',
                        array(
                            'slug'         => $slug,
                            'label'        => $label,
                            'attribute_id' => $attribute_id,
                        )
                    );
                    return 0;
                }

                $this->log(
                    'debug',
                    'Located existing attribute taxonomy.',
                    array(
                        'slug'         => $slug,
                        'label'        => $label,
                        'attribute_id' => $attribute_id,
                    )
                );
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
                $this->log(
                    'warning',
                    'New attribute taxonomy failed registration check after creation.',
                    array(
                        'slug'         => $slug,
                        'label'        => $label,
                        'attribute_id' => $attribute_id,
                    )
                );
                return 0;
            }

            $this->log(
                'info',
                'Created attribute taxonomy for assignment.',
                array(
                    'slug'         => $slug,
                    'label'        => $label,
                    'attribute_id' => $attribute_id,
                )
            );

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
                $this->log(
                    'debug',
                    'Skipping attribute term ensure because value was empty.',
                    array(
                        'taxonomy' => $taxonomy,
                    )
                );
                return 0;
            }

            $key = $this->build_attribute_term_cache_key( $taxonomy, $value );
            if ( array_key_exists( $key, $this->attribute_term_cache ) ) {
                $this->cache_stats['attribute_term_cache_hits']++;
                $term_id = (int) $this->attribute_term_cache[ $key ];
                $this->log(
                    'debug',
                    'Using cached attribute term.',
                    array(
                        'taxonomy' => $taxonomy,
                        'value'    => $value,
                        'term_id'  => $term_id,
                    )
                );
                return $term_id;
            }

            $this->cache_stats['attribute_term_cache_misses']++;

            $term = get_term_by( 'name', $value, $taxonomy );
            if ( $term && ! is_wp_error( $term ) ) {
                $term_id = (int) $term->term_id;
                $this->attribute_term_cache[ $key ] = $term_id;
                $this->log(
                    'debug',
                    'Located existing attribute term by name.',
                    array(
                        'taxonomy' => $taxonomy,
                        'value'    => $value,
                        'term_id'  => $term_id,
                    )
                );
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
                        $this->log(
                            'debug',
                            'Reused existing attribute term after term_exists error.',
                            array(
                                'taxonomy' => $taxonomy,
                                'value'    => $value,
                                'term_id'  => $existing_term_id,
                            )
                        );
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
            $this->log(
                'info',
                'Created new attribute term.',
                array(
                    'taxonomy' => $taxonomy,
                    'value'    => $value,
                    'term_id'  => $term_id,
                )
            );
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

        /**
         * Expose the logger used for sync operations.
         *
         * @return WC_Logger|Psr\Log\LoggerInterface|null
         */
        public function get_logger() {
            return $this->logger;
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
