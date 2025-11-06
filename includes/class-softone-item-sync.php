<?php
/**
 * SoftOne item synchronisation service (variable products by colour).
 *
 * Creates variable parents grouped by cleaned title (DESC without "| colour"), brand and CODE;
 * then creates variations by colour. Safely handles SoftOne's odd field names.
 *
 * @package Softone_Woocommerce_Integration
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

if ( ! class_exists( 'Softone_Item_Sync' ) ) :

class Softone_Item_Sync {

    const CRON_HOOK         = 'softone_wc_integration_sync_items';
    const ADMIN_ACTION      = 'softone_wc_integration_run_item_import';
    const OPTION_LAST_RUN   = 'softone_last_run';
    const META_MTRL         = '_softone_mtrl_id';
    const META_LAST_SYNC    = '_softone_last_synced';
    const META_BARCODE      = '_softone_barcode';
    const META_BRAND        = '_softone_brand';
    const META_SOFTONE_CODE = '_softone_item_code';
    const META_PAYLOAD_HASH = '_softone_payload_hash';

    /**
     * Track whether taxonomy refresh is forced during a manual import.
     *
     * @var bool
     */
    protected $force_taxonomy_refresh = false;

    /**
     * Optional Softone API client used by legacy import helpers.
     *
     * @var Softone_API_Client|null
     */
    protected $api_client;

    /**
     * Optional logger provided by older integrations.
     *
     * @var object|null
     */
    protected $legacy_logger;

    /**
     * Constructor kept for backwards compatibility with older bootstraps.
     */
    public function __construct( $api_client = null, $logger = null ) {
        if ( null !== $api_client ) {
            $this->api_client = $api_client;
        }

        if ( null !== $logger ) {
            $this->legacy_logger = $logger;
        }
    }

    /**
     * Back-compat for older loader code that expects register_hooks().
     * (Your loader is calling ->register_hooks(); keep it working.)
     *
     * @param mixed $loader Optional loader helper used by the plugin bootstrap.
     *
     * @return void
     */
    public function register_hooks( $loader = null ) {
        if ( $this->register_hooks_with_loader( $loader ) ) {
            return;
        }

        $this->init();

        if ( null !== $loader ) {
            $this->log_loader_fallback( $loader );
        }
    }

    /**
     * Preferred hook wiring (new name used internally).
     */
    public function init() {
        add_action( self::CRON_HOOK, [ $this, 'run' ] );
        add_action( 'admin_post_' . self::ADMIN_ACTION, [ $this, 'run_from_admin' ] );
        // Ensure attribute taxonomy exists early (after WC init).
        add_action( 'init', [ $this, 'ensure_colour_taxonomy' ], 11 );
    }

    /**
     * Attempt to register hooks via the plugin loader helper when available.
     *
     * @param mixed $loader Potential loader instance.
     *
     * @return bool True when hooks were registered using the loader, false otherwise.
     */
    protected function register_hooks_with_loader( $loader ) : bool {
        if ( ! is_object( $loader ) || ! method_exists( $loader, 'add_action' ) ) {
            return false;
        }

        $loader->add_action( self::CRON_HOOK, $this, 'run' );
        $loader->add_action( 'init', $this, 'ensure_colour_taxonomy', 11 );

        return true;
    }

    /**
     * Log when the loader fallback path is triggered.
     *
     * @param mixed $loader Loader value that could not be used.
     *
     * @return void
     */
    protected function log_loader_fallback( $loader ) {
        if ( ! class_exists( 'Softone_Sync_Activity_Logger' ) ) {
            return;
        }

        $context = array(
            'loader_type' => is_object( $loader ) ? get_class( $loader ) : gettype( $loader ),
        );

        $logger = new Softone_Sync_Activity_Logger();
        $logger->log(
            'item_sync',
            'loader_fallback',
            'SoftOne item sync registered hooks directly because the loader helper was unavailable.',
            $context
        );
    }

    /**
     * Manual trigger from wp-admin action.
     */
    public function run_from_admin() {
        try {
            $this->sync();
        } catch ( \Throwable $e ) {
            // The sync activity logger already captured the failure; keep fallback handler silent.
        }

        if ( ! headers_sent() ) {
            wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
        }

        exit;
    }

    /**
     * Schedule the recurring cron event that runs the item synchronisation.
     *
     * @return bool True when the event was scheduled, false otherwise.
     */
    public static function schedule_event() {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
            self::log_cron_event(
                'cron_schedule_unsupported',
                'Failed to schedule the SoftOne item sync because WordPress cron helpers are unavailable.'
            );

            return false;
        }

        $next_run = wp_next_scheduled( self::CRON_HOOK );
        if ( $next_run ) {
            self::log_cron_event(
                'cron_schedule_skipped',
                'Skipped scheduling the SoftOne item sync because an event already exists.',
                array( 'scheduled_for' => (int) $next_run )
            );

            return false;
        }

        $scheduled = wp_schedule_event( time(), 'hourly', self::CRON_HOOK );

        if ( false === $scheduled ) {
            self::log_cron_event(
                'cron_schedule_failed',
                'WordPress failed to schedule the SoftOne item sync event.'
            );

            return false;
        }

        self::log_cron_event(
            'cron_scheduled',
            'Scheduled the SoftOne item sync hourly cron event.'
        );

        return true;
    }

    /**
     * Remove the scheduled cron event used by the item synchronisation.
     *
     * @return bool True when an event was removed, false otherwise.
     */
    public static function clear_scheduled_event() {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_unschedule_event' ) ) {
            self::log_cron_event(
                'cron_clear_unsupported',
                'Failed to clear the SoftOne item sync because WordPress cron helpers are unavailable.'
            );

            return false;
        }

        $timestamp = wp_next_scheduled( self::CRON_HOOK );

        if ( ! $timestamp ) {
            self::log_cron_event(
                'cron_clear_skipped',
                'Skipped clearing the SoftOne item sync because no event was scheduled.'
            );

            return false;
        }

        $cleared = wp_unschedule_event( $timestamp, self::CRON_HOOK );

        if ( false === $cleared ) {
            self::log_cron_event(
                'cron_clear_failed',
                'WordPress failed to clear the SoftOne item sync cron event.',
                array( 'scheduled_for' => (int) $timestamp )
            );

            return false;
        }

        self::log_cron_event(
            'cron_cleared',
            'Cleared the scheduled SoftOne item sync cron event.',
            array( 'scheduled_for' => (int) $timestamp )
        );

        return true;
    }

    /**
     * Main sync entrypoint – fetch your SoftOne rows and import.
     * Replace fetch_softone_items() with your real API call.
     */
    public function run() {
        $logger = new Softone_Sync_Activity_Logger();

        try {
            $result = $this->execute_sync();
            $this->log_sync_result( $result, $logger );
        } catch ( \Throwable $e ) {
            $this->log_sync_exception( $e, $logger );
        }
    }

    /**
     * Execute the item import and return a summary.
     *
     * @param bool|null $force_full_import      Whether a full import was requested.
     * @param bool      $force_taxonomy_refresh Whether taxonomy data should be refreshed.
     *
     * @return array<string,mixed>
     */
    protected function execute_sync( $force_full_import = null, $force_taxonomy_refresh = false ) {
        $payload = $this->fetch_softone_items( $force_full_import, $force_taxonomy_refresh );

        if ( ! is_array( $payload ) ) {
            throw new \UnexpectedValueException( 'SoftOne: Unexpected response payload.' );
        }

        $rows = isset( $payload['rows'] ) && is_array( $payload['rows'] ) ? $payload['rows'] : array();

        $result = array(
            'started_at'      => time(),
            'processed'       => 0,
            'created'         => 0,
            'updated'         => 0,
            'skipped'         => 0,
            'rows_count'      => count( $rows ),
            'payload_success' => isset( $payload['success'] ) ? (bool) $payload['success'] : null,
        );

        if ( empty( $rows ) ) {
            return $result;
        }

        $result['processed'] = (int) $this->process_items( $rows );

        if ( $result['rows_count'] > $result['processed'] ) {
            $result['skipped'] = $result['rows_count'] - $result['processed'];
        }

        return $result;
    }

    /**
     * Public sync API used by the admin handler.
     *
     * @param bool|null $force_full_import      Whether a full import was requested.
     * @param bool      $force_taxonomy_refresh Whether taxonomy data should be refreshed.
     *
     * @return array<string,mixed>
     */
    public function sync( $force_full_import = null, $force_taxonomy_refresh = false ) {
        $logger = new Softone_Sync_Activity_Logger();

        try {
            $previous_refresh_state         = $this->force_taxonomy_refresh;
            $this->force_taxonomy_refresh   = (bool) $force_taxonomy_refresh;
            $result = $this->execute_sync( $force_full_import, $force_taxonomy_refresh );
            $this->log_sync_result( $result, $logger );

            $this->force_taxonomy_refresh = $previous_refresh_state;

            return $result;
        } catch ( \Throwable $e ) {
            $this->log_sync_exception( $e, $logger );

            $this->force_taxonomy_refresh = $previous_refresh_state;

            throw $e;
        }
    }

    /**
     * Persist a successful sync result to the activity log.
     *
     * @param array<string,mixed>               $result Summary values.
     * @param Softone_Sync_Activity_Logger|null $logger Logger instance.
     *
     * @return void
     */
    protected function log_sync_result( array $result, $logger ) {
        if ( ! $logger instanceof Softone_Sync_Activity_Logger ) {
            return;
        }

        if ( empty( $result['rows_count'] ) ) {
            $logger->log(
                'item_sync',
                'empty_payload',
                'SoftOne: No items to sync or API error.',
                array(
                    'payload_success' => isset( $result['payload_success'] ) ? $result['payload_success'] : null,
                    'row_count'       => 0,
                )
            );

            return;
        }

        $processed = isset( $result['processed'] ) ? (int) $result['processed'] : 0;

        $logger->log(
            'item_sync',
            'processed',
            sprintf( 'SoftOne: processed %d rows into variable products.', $processed ),
            array( 'processed_rows' => $processed )
        );
    }

    /**
     * Persist sync exceptions to the activity log.
     *
     * @param \Throwable                        $exception Caught exception instance.
     * @param Softone_Sync_Activity_Logger|null $logger    Logger instance.
     *
     * @return void
     */
    protected function log_sync_exception( \Throwable $exception, $logger ) {
        if ( ! $logger instanceof Softone_Sync_Activity_Logger ) {
            return;
        }

        $logger->log(
            'item_sync',
            'exception',
            'SoftOne error: ' . $exception->getMessage(),
            array(
                'exception_class' => get_class( $exception ),
                'code'            => (int) $exception->getCode(),
            )
        );
    }

    /**
     * STUB – replace with your actual SoftOne API request.
     */
    protected function fetch_softone_items( $force_full_import = null, $force_taxonomy_refresh = false ) {
        return [
            'success' => true,
            'rows'    => [],
        ];
    }

    /**
     * Legacy Softone pagination helper used by historical import flows.
     */
    protected function yield_item_rows( array $extra ) {
        if ( ! $this->api_client || ! is_object( $this->api_client ) || ! method_exists( $this->api_client, 'sql_data' ) ) {
            return ( function () { yield from []; } )();
        }

        $default_page_size = 250;
        $page_size         = (int) apply_filters( 'softone_wc_integration_item_sync_page_size', $default_page_size );
        if ( $page_size <= 0 ) {
            $page_size = $default_page_size;
        }

        $max_pages     = (int) apply_filters( 'softone_wc_integration_item_sync_max_pages', 0 );
        $page          = 1;
        $previous_hash = [];

        $generator = function () use ( $extra, $page_size, $max_pages, &$page, &$previous_hash ) {
            while ( true ) {
                $request_extra          = $extra;
                $request_extra['pPage'] = $page;
                $request_extra['pSize'] = $page_size;

                $response = $this->api_client->sql_data( 'getItems', [], $request_extra );
                $rows     = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : [];

                $this->log_activity(
                    'api_requests',
                    'payload_received',
                    'Received payload from Softone API for getItems request.',
                    [
                        'endpoint'  => 'getItems',
                        'page'      => $page,
                        'page_size' => $page_size,
                        'row_count' => count( $rows ),
                        'request'   => $request_extra,
                        'payload'   => $this->prepare_api_payload_for_logging( $response ),
                    ]
                );

                if ( empty( $rows ) ) {
                    break;
                }

                $hash = $this->hash_item_rows( $rows );
                if ( isset( $previous_hash[ $hash ] ) ) {
                    $this->log(
                        'warning',
                        'Detected repeated page payload when fetching Softone item rows. Aborting further pagination to prevent an infinite loop.',
                        [ 'page' => $page, 'page_size' => $page_size ]
                    );
                    break;
                }

                $previous_hash[ $hash ] = true;

                foreach ( $rows as $row ) {
                    yield $row;
                }

                if ( count( $rows ) < $page_size ) {
                    break;
                }

                $page++;

                if ( $max_pages > 0 && $page > $max_pages ) {
                    break;
                }
            }
        };

        return $generator();
    }

    /**
     * Hash the API payload to detect repeated pages.
     */
    protected function hash_item_rows( array $rows ) {
        $context = hash_init( 'md5' );
        $this->hash_append_value( $context, $rows );

        return hash_final( $context );
    }

    /**
     * Append values to a hashing context.
     */
    protected function hash_append_value( $context, $value ) {
        if ( is_array( $value ) ) {
            $keys       = array_keys( $value );
            $item_count = count( $value );
            $is_list    = 0 === $item_count || $keys === range( 0, $item_count - 1 );

            if ( $is_list ) {
                hash_update( $context, '[' );
                $first = true;
                foreach ( $value as $item ) {
                    if ( $first ) { $first = false; } else { hash_update( $context, ',' ); }
                    $this->hash_append_value( $context, $item );
                }
                hash_update( $context, ']' );

                return;
            }

            hash_update( $context, '{' );
            $first = true;
            foreach ( $value as $key => $item ) {
                if ( $first ) { $first = false; } else { hash_update( $context, ',' ); }
                hash_update( $context, $this->encode_json_fragment( (string) $key ) );
                hash_update( $context, ':' );
                $this->hash_append_value( $context, $item );
            }
            hash_update( $context, '}' );

            return;
        }

        hash_update( $context, $this->encode_json_fragment( $value ) );
    }

    /**
     * Encode a value using JSON semantics for hashing purposes.
     */
    protected function encode_json_fragment( $value ) {
        $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
        if ( false === $encoded ) {
            $encoded = '';
        }

        return (string) $encoded;
    }

    /**
     * Lightweight legacy activity logger shim.
     */
    protected function log_activity( $channel, $action, $message, array $context = [] ) {
        if ( is_object( $this->legacy_logger ) && method_exists( $this->legacy_logger, 'log' ) ) {
            $this->legacy_logger->log( $action, $message, $context );
            return;
        }

        $this->log( $action, $message, $context );
    }

    /**
     * Prepare API payloads for logging output.
     */
    protected function prepare_api_payload_for_logging( $payload ) {
        return $payload;
    }

    /**
     * Transform rows => variable parents + colour variations.
     */
    public function process_items( array $rows ) : int {
        $logger = new Softone_Sync_Activity_Logger();

        $groups = [];

        foreach ( $rows as $r ) {
            $desc_raw  = $this->g( $r, 'DESC' );
            $brand     = $this->g( $r, 'BRAND NAME' ) ?: $this->g( $r, 'BRAND' ) ?: '';
            $code      = $this->g( $r, 'CODE' ); // parent CODE (stable id)
            $sku       = $this->g( $r, 'SKU' );  // variation SKU
            $barcode   = $this->g( $r, 'BARCODE' );
            $mtrl      = $this->g( $r, 'MTRL' );
            $price     = $this->g( $r, 'RETAILPRICE' );
            $qty       = $this->g( $r, 'Stock QTY' );

            // Colour: prefer explicit, else parse from " | colour" suffix in DESC.
            $colour    = $this->g( $r, 'COLOUR NAME' ) ?: $this->parse_colour_from_desc( $desc_raw );
            $base_desc = $this->strip_colour_from_desc( $desc_raw );
            $title_for_parent = $base_desc ?: $desc_raw ?: ( $sku ?: $code );

            $groups_key = $this->parent_key( $brand, $title_for_parent, $code );

            $groups[ $groups_key ]['brand']            = $brand;
            $groups[ $groups_key ]['title']            = $title_for_parent;
            $groups[ $groups_key ]['code']             = $code;
            $groups[ $groups_key ]['category_name']    = $this->g( $r, 'COMMECATEGORY NAME' ) ?: $this->g( $r, 'COMMERCATEGORY NAME' ) ?: '';
            $groups[ $groups_key ]['subcategory_name'] = $this->g( $r, 'SUBMECATEGORY NAME' ) ?: $this->g( $r, 'SUBCATEGORY NAME' ) ?: '';

            $groups[ $groups_key ]['items'][] = [
                'sku'     => $sku,
                'barcode' => $barcode,
                'mtrl'    => $mtrl,
                'price'   => is_numeric( $price ) ? (float) $price : null,
                'qty'     => ( $qty === '' || $qty === null ) ? null : (float) $qty,
                'colour'  => $colour ?: 'N/A',
                'raw'     => $r,
            ];
        }

        $processed = 0;

        foreach ( $groups as $key => $group ) {
            $parent_id = $this->upsert_variable_parent( $group );
            if ( ! $parent_id ) {
                $logger->log(
                    'item_sync',
                    'parent_upsert_failed',
                    'Failed to upsert parent for key: ' . $key,
                    array(
                        'brand' => isset( $group['brand'] ) ? $group['brand'] : '',
                        'code'  => isset( $group['code'] ) ? $group['code'] : '',
                    )
                );
                continue;
            }

            // Ensure pa_colour set on parent
            $this->attach_parent_colour_attribute( $parent_id, wp_list_pluck( $group['items'], 'colour' ) );

            // Upsert variations
            foreach ( $group['items'] as $item ) {
                $this->upsert_colour_variation( $parent_id, $item );
            }

            // Categories & brand meta
            $this->apply_categories_and_brand( $parent_id, $group );

            $processed += count( $group['items'] );

            // Force parent type to "variable"
            $product = wc_get_product( $parent_id );
            if ( $product && $product->get_type() !== 'variable' ) {
                wp_set_object_terms( $parent_id, 'variable', 'product_type', false );
            }
        }

        return $processed;
    }

    /**
     * Backwards compatible single row import handler.
     *
     * Older integrations invoke import_row() directly when synchronising
     * catalogue data. The refactored variable product workflow no longer uses
     * this path internally, however the regression suite (and legacy installs)
     * still rely on it. Re-introduce a lightweight implementation that keeps
     * the public surface stable while delegating to modern helpers wherever
     * possible.
     *
     * @param array $data          Normalised SoftOne item data.
     * @param int   $run_timestamp Sync timestamp.
     *
     * @throws \RuntimeException When WooCommerce product APIs are unavailable.
     *
     * @return string created|updated|skipped
     */
    protected function import_row( array $data, $run_timestamp ) {
        if ( ! class_exists( 'WC_Product' ) ) {
            throw new \RuntimeException( __( 'WooCommerce is required to sync items.', 'softone-woocommerce-integration' ) );
        }

        $normalized = $this->normalize_legacy_row( $data );

        $mtrl         = isset( $normalized['mtrl'] ) ? (string) $normalized['mtrl'] : '';
        $sku_requested = $this->determine_sku( $normalized );

        if ( '' === $mtrl && '' === $sku_requested ) {
            throw new \RuntimeException( __( 'Unable to determine a product identifier for the imported row.', 'softone-woocommerce-integration' ) );
        }

        $product_id = $this->find_existing_product( $sku_requested, $mtrl );
        $is_new     = ( $product_id <= 0 );

        $product = $is_new ? new WC_Product_Simple() : wc_get_product( $product_id );

        if ( ! $product ) {
            throw new \RuntimeException( __( 'Failed to load the matching WooCommerce product.', 'softone-woocommerce-integration' ) );
        }

        if ( $is_new && method_exists( $product, 'set_status' ) ) {
            $product->set_status( 'publish' );
        }

        $payload_hash = $this->build_payload_hash( $normalized );
        $category_ids = $this->prepare_category_ids( $normalized );

        if ( ! $is_new ) {
            $existing_hash    = (string) get_post_meta( $product_id, self::META_PAYLOAD_HASH, true );
            $categories_match = $this->product_categories_match( $product_id, $category_ids );

            if ( ! $this->force_taxonomy_refresh && '' !== $existing_hash && $existing_hash === $payload_hash && $categories_match ) {
                return 'skipped';
            }
        }

        $name = $this->first_non_empty( $normalized, [ 'varchar02', 'desc', 'description', 'code', 'name' ] );
        if ( null !== $name && method_exists( $product, 'set_name' ) ) {
            $product->set_name( $name );
        }

        $description = $this->first_non_empty( $normalized, [ 'long_description', 'longdescription', 'remarks', 'remark', 'notes' ] );
        if ( null !== $description && method_exists( $product, 'set_description' ) ) {
            $product->set_description( $description );
        }

        $short_description = $this->first_non_empty( $normalized, [ 'short_description', 'short_desc' ] );
        if ( null !== $short_description && method_exists( $product, 'set_short_description' ) ) {
            $product->set_short_description( $short_description );
        }

        $price = $this->first_non_empty( $normalized, [ 'retailprice', 'price' ] );
        if ( null !== $price && method_exists( $product, 'set_regular_price' ) ) {
            $product->set_regular_price( wc_format_decimal( $price ) );
        }

        $stock_quantity = $this->first_non_empty( $normalized, [ 'stock_qty', 'qty1', 'qty' ] );
        if ( null !== $stock_quantity && method_exists( $product, 'set_manage_stock' ) ) {
            $amount = wc_stock_amount( $stock_quantity );
            $product->set_manage_stock( true );
            if ( method_exists( $product, 'set_stock_quantity' ) ) {
                $product->set_stock_quantity( $amount );
            }
            if ( method_exists( $product, 'set_stock_status' ) ) {
                $product->set_stock_status( $amount > 0 ? 'instock' : 'outofstock' );
            }
        }

        if ( method_exists( $product, 'set_category_ids' ) ) {
            $product->set_category_ids( $category_ids );
        }

        $effective_sku = $this->ensure_unique_sku( $sku_requested, $is_new ? 0 : (int) $product_id );
        if ( '' !== $effective_sku && method_exists( $product, 'set_sku' ) ) {
            $product->set_sku( $effective_sku );
        }

        $attribute_assignments = $this->prepare_attribute_assignments( $normalized, $product, array() );
        if ( method_exists( $product, 'set_attributes' ) ) {
            if ( ! empty( $attribute_assignments['attributes'] ) ) {
                $product->set_attributes( $attribute_assignments['attributes'] );
            } elseif ( $is_new ) {
                $product->set_attributes( array() );
            }
        }

        if ( method_exists( $product, 'save' ) ) {
            $product_id = (int) $product->save();
        }

        if ( $product_id <= 0 ) {
            throw new \RuntimeException( __( 'Unable to save the WooCommerce product.', 'softone-woocommerce-integration' ) );
        }

        if ( '' !== $mtrl ) {
            update_post_meta( $product_id, self::META_MTRL, $mtrl );
        }

        update_post_meta( $product_id, self::META_PAYLOAD_HASH, $payload_hash );
        if ( is_numeric( $run_timestamp ) ) {
            update_post_meta( $product_id, self::META_LAST_SYNC, (int) $run_timestamp );
        }

        if ( ! empty( $category_ids ) && function_exists( 'wp_set_object_terms' ) ) {
            $assignment = wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
            if ( is_wp_error( $assignment ) ) {
                $this->log(
                    'error',
                    'Failed to assign product categories during Softone legacy import.',
                    array(
                        'product_id'    => $product_id,
                        'category_ids'  => $category_ids,
                        'error_message' => $assignment->get_error_message(),
                    )
                );
            }
        }

        if ( ! empty( $attribute_assignments['terms'] ) && function_exists( 'wp_set_object_terms' ) ) {
            foreach ( $attribute_assignments['terms'] as $taxonomy => $term_ids ) {
                if ( empty( $term_ids ) ) {
                    continue;
                }

                wp_set_object_terms( $product_id, array_map( 'intval', (array) $term_ids ), $taxonomy );
            }
        }

        if ( ! empty( $attribute_assignments['clear'] ) && function_exists( 'wp_set_object_terms' ) ) {
            foreach ( $attribute_assignments['clear'] as $taxonomy ) {
                if ( '' === $taxonomy ) {
                    continue;
                }

                wp_set_object_terms( $product_id, array(), $taxonomy );
            }
        }

        $brand_value = $this->first_non_empty( $normalized, [ 'brand_name', 'brand' ] );
        if ( null !== $brand_value ) {
            $this->assign_brand_term( $product_id, $brand_value );
            $this->assign_product_brand_term( $product_id, $brand_value );
        }

        $action = $is_new ? 'created' : 'updated';

        $this->log(
            'info',
            sprintf( 'Product %s via Softone legacy import.', $action ),
            array(
                'product_id' => $product_id,
                'sku'        => $effective_sku ?: $sku_requested,
                'mtrl'       => $mtrl,
                'timestamp'  => $run_timestamp,
            )
        );

        return $action;
    }

    protected function upsert_variable_parent( array $group ) : int {
        $title = $group['title'];
        $code  = $group['code'];

        $existing = $this->find_product_by_meta( self::META_SOFTONE_CODE, $code );
        $post_id  = $existing ?: wc_get_product_id_by_sku( $code );

        if ( ! $post_id ) {
            $post_id = wp_insert_post( [
                'post_title'   => wp_strip_all_tags( $title ),
                'post_status'  => 'publish',
                'post_type'    => 'product',
                'post_content' => '',
            ] );
        }

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            return 0;
        }

        wp_set_object_terms( $post_id, 'variable', 'product_type', false );

        update_post_meta( $post_id, self::META_SOFTONE_CODE, $code );
        update_post_meta( $post_id, self::META_LAST_SYNC, current_time( 'mysql' ) );

        $product = wc_get_product( $post_id );
        if ( $product ) {
            if ( ! $product->get_sku() ) {
                $product->set_sku( $code ); // parent SKU = CODE
            }
            $product->save();
        }

        return (int) $post_id;
    }

    protected function attach_parent_colour_attribute( int $parent_id, array $colours ) {
        $colours = array_filter( array_map( 'trim', $colours ) );
        $colours = array_unique( $colours );

        $taxonomy = 'pa_colour';
        $this->ensure_colour_taxonomy();

        $term_slugs = [];
        foreach ( $colours as $c ) {
            $term = $this->get_or_create_term( $taxonomy, $c );
            if ( $term && ! is_wp_error( $term ) ) {
                $term_slugs[] = $term['slug'];
            }
        }

        $product = wc_get_product( $parent_id );
        if ( ! $product ) return;

        $attributes = $product->get_attributes();
        $tax_obj    = wc_get_attribute_taxonomy_by_name( $taxonomy );

        $attr = new WC_Product_Attribute();
        $attr->set_id( (int) ( $tax_obj ? $tax_obj->attribute_id : 0 ) );
        $attr->set_name( $taxonomy );
        $attr->set_options( $term_slugs );
        $attr->set_visible( true );
        $attr->set_variation( true );

        $attributes[ $taxonomy ] = $attr;
        $product->set_attributes( $attributes );
        $product->save();

        if ( ! empty( $term_slugs ) ) {
            wp_set_object_terms( $parent_id, $term_slugs, $taxonomy, false );
        }
    }

    protected function upsert_colour_variation( int $parent_id, array $item ) {
        $taxonomy   = 'pa_colour';
        $colour_raw = $item['colour'] ?: 'N/A';
        $colour     = $this->normalize_colour_name( $colour_raw );
        $term       = $this->get_or_create_term( $taxonomy, $colour );
        if ( ! $term || is_wp_error( $term ) ) return;

        $variation_id = $this->find_existing_variation( $parent_id, $taxonomy, $term['slug'] );
        if ( ! $variation_id ) {
            $variation_id = wp_insert_post( [
                'post_title'  => get_the_title( $parent_id ) . ' – ' . $colour,
                'post_name'   => sanitize_title( get_the_title( $parent_id ) . ' ' . $colour ),
                'post_status' => 'publish',
                'post_parent' => $parent_id,
                'post_type'   => 'product_variation',
                'menu_order'  => 0,
            ] );
        }
        if ( ! $variation_id || is_wp_error( $variation_id ) ) return;

        update_post_meta( $variation_id, 'attribute_' . $taxonomy, $term['slug'] );

        $v = new WC_Product_Variation( $variation_id );

        if ( ! empty( $item['sku'] ) && $v->get_sku() !== $item['sku'] ) {
            $v->set_sku( $item['sku'] );
        }

        if ( isset( $item['price'] ) && $item['price'] !== null ) {
            $v->set_regular_price( (string) $item['price'] );
        }

        if ( $item['qty'] === null ) {
            $v->set_manage_stock( false );
            $v->set_stock_status( 'instock' );
        } else {
            $v->set_manage_stock( true );
            $v->set_stock_quantity( max( 0, (int) round( $item['qty'] ) ) );
            $v->set_stock_status( ( (float) $item['qty'] ) > 0 ? 'instock' : 'outofstock' );
        }

        $v->save();

        if ( ! empty( $item['barcode'] ) ) {
            update_post_meta( $variation_id, self::META_BARCODE, $item['barcode'] );
        }
        if ( ! empty( $item['mtrl'] ) ) {
            update_post_meta( $variation_id, self::META_MTRL, $item['mtrl'] );
        }
        update_post_meta( $variation_id, self::META_LAST_SYNC, current_time( 'mysql' ) );
    }

    protected function apply_categories_and_brand( int $parent_id, array $group ) {
        $cat  = trim( (string) $group['category_name'] );
        $sub  = trim( (string) $group['subcategory_name'] );
        $cats = array_filter( [ $cat, $sub ] );

        if ( ! empty( $cats ) ) {
            $term_ids = [];
            foreach ( $cats as $label ) {
                $term = term_exists( $label, 'product_cat' );
                if ( ! $term ) {
                    $term = wp_insert_term( $label, 'product_cat' );
                }
                if ( $term && ! is_wp_error( $term ) ) {
                    $term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
                }
            }
            if ( ! empty( $term_ids ) ) {
                wp_set_object_terms( $parent_id, $term_ids, 'product_cat', false );
            }
        }

        if ( ! empty( $group['brand'] ) ) {
            update_post_meta( $parent_id, self::META_BRAND, $group['brand'] );
        }
    }

    /**
     * Ensure pa_colour exists.
     */
    public function ensure_colour_taxonomy() {
        $taxonomy = 'pa_colour';
        if ( taxonomy_exists( $taxonomy ) ) {
            return;
        }
        if ( function_exists( 'wc_create_attribute' ) ) {
            $attr_id = wc_create_attribute( [
                'name'         => 'Colour',
                'slug'         => 'pa_colour',
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ] );
            if ( ! is_wp_error( $attr_id ) ) {
                register_taxonomy(
                    $taxonomy,
                    apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, [ 'product' ] ),
                    apply_filters( 'woocommerce_taxonomy_args_' . $taxonomy, [
                        'labels'       => [ 'name' => __( 'Colours', 'woocommerce' ) ],
                        'hierarchical' => false,
                        'show_ui'      => false,
                        'query_var'    => true,
                        'rewrite'      => false,
                    ] )
                );
            }
        }
    }

    protected function find_existing_variation( int $parent_id, string $taxonomy, string $term_slug ) : int {
        $ids = get_children( [
            'post_parent' => $parent_id,
            'post_type'   => 'product_variation',
            'numberposts' => -1,
            'post_status' => [ 'publish', 'private' ],
            'fields'      => 'ids',
        ] );
        if ( empty( $ids ) ) return 0;
        foreach ( $ids as $vid ) {
            $val = get_post_meta( $vid, 'attribute_' . $taxonomy, true );
            if ( $val === $term_slug ) return (int) $vid;
        }
        return 0;
    }

    protected function get_or_create_term( string $taxonomy, string $label ) {
        $label = $this->normalize_colour_name( $label );
        $exists = term_exists( $label, $taxonomy );
        if ( $exists ) {
            $term = is_array( $exists ) ? get_term( $exists['term_id'], $taxonomy ) : get_term( (int) $exists, $taxonomy );
        } else {
            $term = wp_insert_term( $label, $taxonomy, [ 'slug' => sanitize_title( $label ) ] );
            if ( ! is_wp_error( $term ) ) {
                $term = get_term( $term['term_id'], $taxonomy );
            }
        }
        if ( is_wp_error( $term ) || ! $term ) return null;
        return [ 'term_id' => $term->term_id, 'slug' => $term->slug, 'name' => $term->name ];
    }

    protected function parse_colour_from_desc( ?string $desc ) : ?string {
        if ( ! $desc ) return null;
        if ( preg_match( '/\s*\|\s*([^|]+)\s*$/u', $desc, $m ) ) {
            $colour = trim( $m[1] );
            if ( $colour !== '' && $colour !== '-' ) {
                return $this->normalize_colour_name( $colour );
            }
        }
        return null;
    }

    protected function strip_colour_from_desc( ?string $desc ) : ?string {
        if ( ! $desc ) return $desc;
        return preg_replace( '/\s*\|\s*[^|]+\s*$/u', '', $desc );
    }

    protected function normalize_colour_name( string $c ) : string {
        $c = trim( $c );
        $map = [
            'blk' => 'Black',
            'black' => 'Black',
            'denim blue' => 'Denim Blue',
            'olive green' => 'Olive Green',
            'sepia black' => 'Sepia Black',
            '-' => '',
        ];
        $lc = function_exists('mb_strtolower') ? mb_strtolower( $c, 'UTF-8' ) : strtolower( $c );
        if ( isset( $map[ $lc ] ) ) return $map[ $lc ];
        if ( function_exists('mb_convert_case') ) {
            return mb_convert_case( $c, MB_CASE_TITLE, 'UTF-8' );
        }
        return ucwords( strtolower( $c ) );
    }

    protected function g( array $row, string $key ) {
        if ( isset( $row[ $key ] ) ) return $row[ $key ];
        $alts = [
            $key,
            str_replace( ' ', '_', $key ),
            str_replace( '_', ' ', $key ),
        ];
        foreach ( $alts as $k ) {
            if ( isset( $row[ $k ] ) ) return $row[ $k ];
        }
        foreach ( $row as $k => $v ) {
            if ( strtolower( $k ) === strtolower( $key ) ) {
                return $v;
            }
        }
        return null;
    }

    protected function parent_key( string $brand, string $title, string $code ) : string {
        return md5( implode( '|', [ trim( $brand ), trim( $title ), trim( (string) $code ) ] ) );
    }

    protected function find_product_by_meta( string $meta_key, $meta_val ) : int {
        if ( ! $meta_val ) return 0;
        $q = new WP_Query( [
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'meta_key'       => $meta_key,
            'meta_value'     => $meta_val,
            'fields'         => 'ids',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'no_found_rows'  => true,
        ] );
        if ( $q->have_posts() ) {
            return (int) $q->posts[0];
        }
        return 0;
    }

    /**
     * Locate an existing product by SKU or SoftOne MTRL metadata.
     */
    protected function find_existing_product( $sku, $mtrl ) {
        $sku = trim( (string) $sku );
        if ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
            $existing = (int) wc_get_product_id_by_sku( $sku );
            if ( $existing > 0 ) {
                return $existing;
            }
        }

        $mtrl = trim( (string) $mtrl );
        if ( '' !== $mtrl ) {
            $existing = $this->find_product_by_meta( self::META_MTRL, $mtrl );
            if ( $existing > 0 ) {
                return $existing;
            }
        }

        return 0;
    }

    /**
     * Build a payload hash used to detect identical imports.
     */
    protected function build_payload_hash( array $data ) : string {
        $hash_source = $data;
        ksort( $hash_source );

        $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $hash_source ) : json_encode( $hash_source );
        if ( false === $encoded ) {
            $encoded = '';
        }

        return md5( (string) $encoded );
    }

    /**
     * Determine the preferred SKU candidate.
     */
    protected function determine_sku( array $data ) : string {
        foreach ( [ 'sku', 'barcode', 'code' ] as $key ) {
            if ( isset( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
                return (string) $data[ $key ];
            }
        }

        return '';
    }

    /**
     * Normalise category identifiers embedded in the row payload.
     *
     * @param array $data Normalised row payload.
     *
     * @return array<int>
     */
    protected function prepare_category_ids( array $data ) {
        $ids = [];

        if ( isset( $data['category_ids'] ) ) {
            $ids = (array) $data['category_ids'];
        }

        $normalized = [];
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) {
                $normalized[] = $id;
            }
        }

        $normalized = array_values( array_unique( $normalized ) );

        return $normalized;
    }

    /**
     * Compare existing product category assignments with the target payload.
     */
    protected function product_categories_match( $product_id, array $category_ids ) : bool {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return false;
        }

        $target = array_values( array_unique( array_map( 'intval', array_filter( $category_ids ) ) ) );
        sort( $target );

        if ( function_exists( 'wp_get_object_terms' ) ) {
            $existing_terms = wp_get_object_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
            if ( is_wp_error( $existing_terms ) ) {
                return false;
            }

            $existing = array_map( 'intval', (array) $existing_terms );
        } elseif ( isset( $GLOBALS['softone_object_terms']['product_cat'][ $product_id ] ) ) {
            $existing = array_map( 'intval', (array) $GLOBALS['softone_object_terms']['product_cat'][ $product_id ] );
        } else {
            $product = wc_get_product( $product_id );
            if ( ! $product || ! method_exists( $product, 'get_category_ids' ) ) {
                return false;
            }

            $existing = array_map( 'intval', (array) $product->get_category_ids() );
        }

        $existing = array_values( array_unique( $existing ) );
        sort( $existing );

        return $existing === $target;
    }

    /**
     * Retrieve the first non-empty value from a list of candidate keys.
     */
    protected function first_non_empty( array $data, array $keys ) {
        foreach ( $keys as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $value = $data[ $key ];
                if ( '' !== $value && null !== $value ) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Ensure SKUs remain unique by appending numeric suffixes when necessary.
     */
    protected function ensure_unique_sku( $base_sku, $current_product_id = 0 ) : string {
        $base_sku = trim( (string) $base_sku );
        if ( '' === $base_sku ) {
            return '';
        }

        if ( ! $this->sku_taken_by_other( $base_sku, $current_product_id ) ) {
            return $base_sku;
        }

        $attempts = (int) apply_filters( 'softone_wc_integration_sku_unique_attempts', 50 );
        $suffix   = 2;
        $candidate = $base_sku . '-' . $suffix;

        while ( $this->sku_taken_by_other( $candidate, $current_product_id ) && $suffix <= $attempts ) {
            $suffix++;
            $candidate = $base_sku . '-' . $suffix;
        }

        if ( $this->sku_taken_by_other( $candidate, $current_product_id ) ) {
            return '';
        }

        return $candidate;
    }

    /**
     * Detect when a SKU is already owned by another product.
     */
    protected function sku_taken_by_other( $sku, $current_product_id = 0 ) : bool {
        $sku = trim( (string) $sku );
        if ( '' === $sku ) {
            return false;
        }

        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            return false;
        }

        $owner_id = (int) wc_get_product_id_by_sku( $sku );
        if ( $owner_id <= 0 ) {
            return false;
        }

        $current_product_id = (int) $current_product_id;

        return $current_product_id <= 0 || $owner_id !== $current_product_id;
    }

    /**
     * Normalise a colour attribute value for taxonomy usage.
     */
    protected function normalize_colour_value( $colour ) {
        $colour = is_string( $colour ) ? trim( $colour ) : '';
        if ( '' === $colour ) {
            return '';
        }

        return $this->normalize_colour_name( $colour );
    }

    /**
     * Resolve the colour attribute slug, preferring existing taxonomies.
     */
    protected function resolve_colour_attribute_slug() {
        if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
            if ( wc_attribute_taxonomy_id_by_name( 'colour' ) ) {
                return 'colour';
            }

            if ( wc_attribute_taxonomy_id_by_name( 'color' ) ) {
                return 'color';
            }
        }

        return 'colour';
    }

    /**
     * Ensure the requested attribute taxonomy exists and return its identifier.
     */
    protected function ensure_attribute_taxonomy( $slug, $label ) {
        if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
            return 0;
        }

        $attribute_id = (int) wc_attribute_taxonomy_id_by_name( $slug );

        if ( $attribute_id <= 0 && function_exists( 'wc_create_attribute' ) ) {
            $result = wc_create_attribute(
                [
                    'slug'         => $slug,
                    'name'         => $label,
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ]
            );

            if ( is_wp_error( $result ) ) {
                $this->log( 'error', 'Failed to create attribute taxonomy.', [ 'slug' => $slug, 'error' => $result->get_error_message() ] );
                return 0;
            }

            $attribute_id = (int) $result;
        }

        if ( $attribute_id > 0 && function_exists( 'wc_attribute_taxonomy_name' ) ) {
            $taxonomy = wc_attribute_taxonomy_name( $slug );
            if ( '' !== $taxonomy && function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) && function_exists( 'register_taxonomy' ) ) {
                register_taxonomy( $taxonomy, [ 'product' ], [ 'hierarchical' => false ] );
            }
        }

        return $attribute_id;
    }

    /**
     * Ensure an attribute term exists for the provided taxonomy.
     */
    protected function ensure_attribute_term( $taxonomy, $value ) {
        if ( '' === $taxonomy ) {
            return 0;
        }

        $normalized_value = $this->normalize_colour_value( $value );

        if ( '' === $normalized_value ) {
            return 0;
        }

        $term = false;
        if ( function_exists( 'get_term_by' ) ) {
            $term = get_term_by( 'name', $normalized_value, $taxonomy );
            if ( ! $term && function_exists( 'sanitize_title' ) ) {
                $term = get_term_by( 'slug', sanitize_title( $normalized_value ), $taxonomy );
            }
        }

        if ( $term && ! is_wp_error( $term ) ) {
            $term_id = (int) $term->term_id;

            if ( property_exists( $term, 'name' ) && $term->name !== $normalized_value && function_exists( 'wp_update_term' ) ) {
                wp_update_term( $term_id, $taxonomy, [ 'name' => $normalized_value ] );
            }

            if ( function_exists( 'clean_term_cache' ) ) {
                clean_term_cache( [ $term_id ], $taxonomy );
            }

            return $term_id;
        }

        if ( ! function_exists( 'wp_insert_term' ) ) {
            return 0;
        }

        $args = [];
        if ( function_exists( 'sanitize_title' ) ) {
            $args['slug'] = sanitize_title( $normalized_value );
        }

        $created = wp_insert_term( $normalized_value, $taxonomy, $args );

        if ( is_wp_error( $created ) ) {
            $this->log( 'error', 'Failed to create attribute term.', [ 'taxonomy' => $taxonomy, 'value' => $normalized_value, 'error' => $created->get_error_message() ] );
            return 0;
        }

        $term_id = (int) $created['term_id'];

        if ( function_exists( 'clean_term_cache' ) ) {
            clean_term_cache( [ $term_id ], $taxonomy );
        }

        return $term_id;
    }

    /**
     * Prepare legacy attribute assignments.
     */
    protected function prepare_attribute_assignments( array $data, $product, array $fallback_attributes = array() ) {
        $assignments = [
            'attributes' => [],
            'terms'      => [],
            'values'     => [],
            'clear'      => [],
        ];

        $colour_value = $this->normalize_colour_value( $this->first_non_empty( $data, [ 'colour_name', 'color_name', 'colour', 'color' ] ) );
        if ( '' === $colour_value && isset( $fallback_attributes['colour'] ) ) {
            $colour_value = $this->normalize_colour_value( $fallback_attributes['colour'] );
        }

        $colour_slug = $this->resolve_colour_attribute_slug();
        $taxonomy    = function_exists( 'wc_attribute_taxonomy_name' ) ? wc_attribute_taxonomy_name( $colour_slug ) : '';

        if ( '' !== $colour_value && '' !== $taxonomy ) {
            $attribute_id = $this->ensure_attribute_taxonomy( $colour_slug, __( 'Colour', 'softone-woocommerce-integration' ) );

            if ( $attribute_id ) {
                $term_id = $this->ensure_attribute_term( $taxonomy, $colour_value );

                if ( $term_id && class_exists( 'WC_Product_Attribute' ) ) {
                    $attribute = new WC_Product_Attribute();
                    $attribute->set_id( (int) $attribute_id );
                    $attribute->set_name( $taxonomy );
                    $attribute->set_options( [ (int) $term_id ] );
                    $attribute->set_position( 0 );
                    $attribute->set_visible( true );
                    $attribute->set_variation( false );

                    $assignments['attributes'][ $taxonomy ] = $attribute;
                    $assignments['terms'][ $taxonomy ]      = [ (int) $term_id ];
                    $assignments['values'][ $taxonomy ]     = $colour_value;
                }
            }
        } elseif ( '' === $colour_value && '' !== $taxonomy ) {
            $assignments['clear'][] = $taxonomy;
        }

        return $assignments;
    }

    /**
     * Persist brand metadata and taxonomy assignments when available.
     */
    protected function assign_brand_term( $product_id, $brand_value ) {
        $product_id  = (int) $product_id;
        $brand_value = trim( (string) $brand_value );

        if ( $product_id <= 0 || '' === $brand_value ) {
            return;
        }

        update_post_meta( $product_id, self::META_BRAND, $brand_value );

        if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'pa_brand' ) || ! function_exists( 'wp_set_object_terms' ) ) {
            return;
        }

        $term = get_term_by( 'name', $brand_value, 'pa_brand' );
        if ( ! $term && function_exists( 'wp_insert_term' ) ) {
            $created = wp_insert_term( $brand_value, 'pa_brand' );
            if ( ! is_wp_error( $created ) ) {
                $term = get_term( (int) $created['term_id'], 'pa_brand' );
            }
        }

        if ( $term && ! is_wp_error( $term ) ) {
            wp_set_object_terms( $product_id, [ (int) $term->term_id ], 'pa_brand', false );
        }
    }

    /**
     * Assign WooCommerce product_brand taxonomy terms when present.
     */
    protected function assign_product_brand_term( $product_id, $brand_value ) {
        $product_id  = (int) $product_id;
        $brand_value = trim( (string) $brand_value );

        if ( $product_id <= 0 || '' === $brand_value ) {
            return;
        }

        if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'product_brand' ) || ! function_exists( 'wp_set_object_terms' ) ) {
            return;
        }

        $term = get_term_by( 'name', $brand_value, 'product_brand' );
        if ( ! $term && function_exists( 'wp_insert_term' ) ) {
            $created = wp_insert_term( $brand_value, 'product_brand' );
            if ( is_wp_error( $created ) ) {
                $this->log( 'error', 'Failed to create product_brand term.', [ 'brand' => $brand_value, 'error' => $created->get_error_message() ] );
                return;
            }

            $term = get_term( (int) $created['term_id'], 'product_brand' );
        }

        if ( $term && ! is_wp_error( $term ) ) {
            wp_set_object_terms( $product_id, [ (int) $term->term_id ], 'product_brand', false );
        }
    }

    /**
     * Lightweight logger used by legacy paths.
     */
    protected function log( $level, $message, array $context = [] ) {
        if ( is_object( $this->legacy_logger ) && method_exists( $this->legacy_logger, 'log' ) ) {
            $this->legacy_logger->log( $level, $message, $context );
            return;
        }

        if ( class_exists( 'Softone_Sync_Activity_Logger' ) ) {
            $logger = new Softone_Sync_Activity_Logger();
            $logger->log( 'item_sync', (string) $level, (string) $message, $context );
            return;
        }

        if ( function_exists( 'error_log' ) ) {
            $encoded_context = function_exists( 'wp_json_encode' ) ? wp_json_encode( $context ) : json_encode( $context );
            error_log( sprintf( '[softone-item-sync:%s] %s %s', $level, $message, (string) $encoded_context ) );
        }
    }

    /**
     * Convert incoming row data to a predictable, lower-case keyed array.
     */
    protected function normalize_legacy_row( array $row ) : array {
        $normalized = [];

        foreach ( $row as $key => $value ) {
            $normalized[ strtolower( (string) $key ) ] = $value;
        }

        return $normalized;
    }

    /**
     * Persist cron related log entries.
     *
     * @param string               $action  Log action key.
     * @param string               $message Human readable summary.
     * @param array<string, mixed> $context Additional context for the log entry.
     *
     * @return void
     */
    protected static function log_cron_event( $action, $message, array $context = array() ) {
        if ( ! class_exists( 'Softone_Sync_Activity_Logger' ) ) {
            return;
        }

        $logger = new Softone_Sync_Activity_Logger();
        $logger->log( 'item_sync', (string) $action, (string) $message, $context );
    }
}

endif;

// Optional bootstrap (only if your main loader *doesn't* instantiate this):
// if ( class_exists( 'Softone_Item_Sync' ) && ! isset( $GLOBALS['softone_item_sync'] ) ) {
//     $GLOBALS['softone_item_sync'] = new Softone_Item_Sync();
//     $GLOBALS['softone_item_sync']->register_hooks();
// }
