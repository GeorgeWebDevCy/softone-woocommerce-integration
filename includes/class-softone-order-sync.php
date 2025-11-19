<?php
/**
 * SoftOne order synchronisation service.
 *
 * @package    Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Softone_Order_Sync' ) ) {
    /**
     * Handles pushing WooCommerce orders to SoftOne SALDOC documents.
     */
    class Softone_Order_Sync {

        const ORDER_META_DOCUMENT_ID = '_softone_document_id';
        const ORDER_META_TRDR        = '_softone_trdr';
        const LOGGER_SOURCE          = 'softone-order-sync';

        /**
         * API client instance.
         *
         * @var Softone_API_Client
         */
        protected $api_client;

        /**
         * Customer synchronisation service.
         *
         * @var Softone_Customer_Sync
         */
        protected $customer_sync;

        /**
         * Logger instance.
         *
         * @var WC_Logger|Psr\Log\LoggerInterface|null
         */
        protected $logger;

/**
 * Logger used for rich order export diagnostics.
 *
 * @var Softone_Sync_Activity_Logger|null
 */
protected $order_event_logger;

/**
 * Constructor.
 *
 * @param Softone_API_Client|null        $api_client          Optional API client override.
 * @param Softone_Customer_Sync|null     $customer_sync       Optional customer sync service.
 * @param WC_Logger|Psr\Log\LoggerInterface|null $logger     Optional logger instance.
 * @param Softone_Sync_Activity_Logger|null       $order_event_logger Optional order export logger.
 */
public function __construct( ?Softone_API_Client $api_client = null, ?Softone_Customer_Sync $customer_sync = null, $logger = null, ?Softone_Sync_Activity_Logger $order_event_logger = null ) {
$this->api_client        = $api_client ?: new Softone_API_Client();
$this->order_event_logger = $order_event_logger;
$this->customer_sync     = $customer_sync ?: new Softone_Customer_Sync( $this->api_client, null, $this->order_event_logger );

if ( $this->customer_sync && method_exists( $this->customer_sync, 'set_order_event_logger' ) ) {
$this->customer_sync->set_order_event_logger( $this->order_event_logger );
}
$this->logger            = $logger ?: $this->get_default_logger();
}

        /**
         * Register WordPress hooks via the loader.
         *
         * @param Softone_Woocommerce_Integration_Loader $loader Loader instance.
         *
         * @return void
         */
        public function register_hooks( Softone_Woocommerce_Integration_Loader $loader ) {
            $statuses = $this->get_trigger_statuses();

            foreach ( $statuses as $status ) {
                $hook = sprintf( 'woocommerce_order_status_%s', $status );
                $loader->add_action( $hook, $this, 'handle_order_status_transition', 10, 1 );
            }
        }

        /**
         * Handle an order status transition that should trigger a SoftOne sync.
         *
         * @param int $order_id WooCommerce order identifier.
         *
         * @return void
         */
        public function handle_order_status_transition( $order_id ) {
            $order_id = absint( $order_id );

            if ( $order_id <= 0 ) {
                return;
            }

            if ( ! function_exists( 'wc_get_order' ) ) {
                return;
            }

$order = wc_get_order( $order_id );

if ( ! $order ) {
return;
}

$current_status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
$order_number   = method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order_id;

$this->log_order_event(
'order_status_triggered',
sprintf(
/* translators: 1: order number, 2: status. */
__( 'Order %1$s triggered SoftOne export via status “%2$s”.', 'softone-woocommerce-integration' ),
$order_number,
$current_status
),
array(
'order_id'     => $order_id,
'order_number' => $order_number,
'order_status' => $current_status,
)
);

if ( $this->is_order_already_exported( $order ) ) {
return;
}

            try {
                $trdr = $this->determine_order_trdr( $order );
            } catch ( Softone_API_Client_Exception $exception ) {
                $this->log( 'error', $exception->getMessage(), array(
                    'order_id'  => $order_id,
                    'exception' => $exception,
                ) );
                $this->add_order_note( $order, sprintf( /* translators: %s: error message */ __( '[SO-ORD-001] SoftOne customer sync failed: %s', 'softone-woocommerce-integration' ), $exception->getMessage() ) );
                return;
            }

            if ( '' === $trdr ) {
                $this->log( 'error', __( '[SO-ORD-002] Unable to determine SoftOne customer (TRDR) for order.', 'softone-woocommerce-integration' ), array( 'order_id' => $order_id ) );
                $this->add_order_note( $order, __( '[SO-ORD-003] SoftOne order export skipped because a customer record could not be located.', 'softone-woocommerce-integration' ) );
                return;
            }

$payload = $this->build_document_payload( $order, $trdr );

$this->log_order_event(
'saldoc_payload',
__( 'Prepared SoftOne SALDOC payload.', 'softone-woocommerce-integration' ),
array(
'order_id'     => $order_id,
'order_number' => $order_number,
'payload'      => $payload,
)
);

            if ( empty( $payload['SALDOC'] ) || empty( $payload['ITELINES'] ) ) {
                $this->log( 'error', __( '[SO-ORD-004] SoftOne order payload is incomplete (missing SALDOC or ITELINES). Document was not created.', 'softone-woocommerce-integration' ), array( 'order_id' => $order_id ) );
                $this->add_order_note( $order, __( '[SO-ORD-005] SoftOne order export failed due to an incomplete payload.', 'softone-woocommerce-integration' ) );
                return;
            }

            $header = reset( $payload['SALDOC'] );

            if ( empty( $header['SERIES'] ) ) {
                $this->log( 'error', __( '[SO-ORD-006] SoftOne document series is not configured. Order export aborted.', 'softone-woocommerce-integration' ), array( 'order_id' => $order_id ) );
                $this->add_order_note( $order, __( '[SO-ORD-007] SoftOne order export failed because the document series is missing.', 'softone-woocommerce-integration' ) );
                return;
            }

            $response = $this->transmit_document_with_retry( $order, $payload );

            if ( empty( $response ) || empty( $response['id'] ) ) {
                return;
            }

            $document_id = (string) $response['id'];

            $order->update_meta_data( self::ORDER_META_DOCUMENT_ID, $document_id );
            $this->persist_order_meta( $order );

            $this->add_order_note( $order, sprintf( /* translators: %s: document identifier */ __( 'SoftOne document #%s created.', 'softone-woocommerce-integration' ), $document_id ) );
            $this->log( 'info', __( 'SoftOne document created successfully.', 'softone-woocommerce-integration' ), array(
                'order_id'    => $order->get_id(),
                'document_id' => $document_id,
            ) );
        }

        /**
         * Determine whether the order has already been exported to SoftOne.
         *
         * @param WC_Order $order WooCommerce order instance.
         *
         * @return bool
         */
        protected function is_order_already_exported( WC_Order $order ) {
            $existing = $order->get_meta( self::ORDER_META_DOCUMENT_ID, true );

            return is_scalar( $existing ) && '' !== (string) $existing;
        }

        /**
         * Locate or create the SoftOne customer identifier for the order.
         *
         * @param WC_Order $order WooCommerce order instance.
         *
         * @throws Softone_API_Client_Exception When API interactions fail.
         *
         * @return string
         */
protected function determine_order_trdr( WC_Order $order ) {
$trdr = (string) $order->get_meta( self::ORDER_META_TRDR, true );

            if ( '' !== $trdr ) {
                return $trdr;
            }

$customer_id  = method_exists( $order, 'get_customer_id' ) ? absint( $order->get_customer_id() ) : 0;
$order_number = method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order->get_id();

if ( $customer_id > 0 ) {
$trdr = $this->customer_sync->ensure_customer_trdr(
$customer_id,
array(
'order_id'     => $order->get_id(),
'order_number' => $order_number,
'source'       => 'order_export',
)
);

                if ( '' !== $trdr ) {
                    $order->update_meta_data( self::ORDER_META_TRDR, $trdr );
                    $this->persist_order_meta( $order );

                    return $trdr;
                }
            }

            $email = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';

            if ( '' !== $email ) {
                $trdr = $this->locate_trdr_by_email( $email );

                if ( '' !== $trdr ) {
                    $order->update_meta_data( self::ORDER_META_TRDR, $trdr );
                    $this->persist_order_meta( $order );

                    return $trdr;
                }
            }

            if ( '' !== $email ) {
                $trdr = $this->create_guest_customer( $order );

                if ( '' !== $trdr ) {
                    $order->update_meta_data( self::ORDER_META_TRDR, $trdr );
                    $this->persist_order_meta( $order );
                }
            }

            return $trdr;
        }

        /**
         * Attempt to locate a SoftOne customer using the provided email address.
         *
         * @param string $email Customer email address.
         *
         * @throws Softone_API_Client_Exception When the API request fails.
         *
         * @return string
         */
        protected function locate_trdr_by_email( $email ) {
            $email = trim( (string) $email );

            if ( '' === $email ) {
                return '';
            }

            $response = $this->api_client->sql_data( 'getCustomers', array( 'EMAIL' => $email ) );
            $rows     = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array();

            foreach ( $rows as $row ) {
                if ( isset( $row['EMAIL'] ) && strcasecmp( (string) $row['EMAIL'], $email ) !== 0 ) {
                    continue;
                }

                if ( empty( $row['TRDR'] ) ) {
                    continue;
                }

                return (string) $row['TRDR'];
            }

            return '';
        }

        /**
         * Create a SoftOne customer record for guest orders.
         *
         * @param WC_Order $order WooCommerce order instance.
         *
         * @throws Softone_API_Client_Exception When the API request fails.
         *
         * @return string
         */
        protected function create_guest_customer( WC_Order $order ) {
            $name = trim( implode( ' ', array_filter( array(
                $order->get_billing_first_name(),
                $order->get_billing_last_name(),
            ) ) ) );

            if ( '' === $name ) {
                $name = trim( implode( ' ', array_filter( array(
                    $order->get_shipping_first_name(),
                    $order->get_shipping_last_name(),
                ) ) ) );
            }

            if ( '' === $name ) {
                $name = (string) $order->get_billing_email();
            }

            if ( '' === $name ) {
                return '';
            }

            $billing_country = strtoupper( trim( (string) $order->get_billing_country() ) );

            if ( '' === $billing_country && method_exists( $order, 'get_shipping_country' ) ) {
                $billing_country = strtoupper( trim( (string) $order->get_shipping_country() ) );
            }

            $softone_country = '';

            if ( '' !== $billing_country && $this->customer_sync ) {
                $softone_country = $this->customer_sync->map_country_to_softone_id( $billing_country );

                if ( '' === $softone_country ) {
                    $this->log(
                        'error',
                        sprintf(
                            /* translators: %s: ISO 3166-1 alpha-2 country code. */
                            __( '[SO-CNTRY-001] SoftOne country mapping missing for ISO code %s.', 'softone-woocommerce-integration' ),
                            $billing_country
                        ),
                        array(
                            'order_id' => $order->get_id(),
                            'country'  => $billing_country,
                        )
                    );

                    $this->add_order_note(
                        $order,
                        __( '[SO-ORD-011] SoftOne guest customer creation skipped because the country mapping is missing.', 'softone-woocommerce-integration' )
                    );

                    return '';
                }
            }

            $record = array(
                'CODE'        => sprintf( '%sG%06d', Softone_Customer_Sync::CODE_PREFIX, $order->get_id() ),
                'NAME'        => $name,
                'EMAIL'       => $order->get_billing_email(),
                'PHONE01'     => $order->get_billing_phone(),
                'ADDRESS'     => $order->get_billing_address_1(),
                'ADDRESS2'    => $order->get_billing_address_2(),
                'CITY'        => $order->get_billing_city(),
                'ZIP'         => $order->get_billing_postcode(),
                'COUNTRY'     => $softone_country,
                'AREAS'       => $this->api_client->get_areas(),
                'SOCURRENCY'  => $this->api_client->get_socurrency(),
                'TRDCATEGORY' => $this->api_client->get_trdcategory(),
            );

            $record = array_filter( $record, array( $this, 'filter_empty_value' ) );

            if ( empty( $record['CODE'] ) || empty( $record['NAME'] ) ) {
                return '';
            }

$payload = array( 'CUSTOMER' => array( $record ) );

$this->log_order_event(
'guest_customer_payload',
__( 'Prepared SoftOne guest customer payload.', 'softone-woocommerce-integration' ),
array(
'order_id' => $order->get_id(),
'payload'  => $payload,
)
);

$response = $this->api_client->set_data( 'CUSTOMER', $payload );

            if ( empty( $response['id'] ) ) {
                return '';
            }

            $trdr = (string) $response['id'];

            $this->log( 'info', __( 'Guest customer created in SoftOne.', 'softone-woocommerce-integration' ), array(
                'order_id' => $order->get_id(),
                'trdr'     => $trdr,
            ) );

            return $trdr;
        }

        /**
         * Build the SoftOne SALDOC payload for the order.
         *
         * @param WC_Order $order WooCommerce order instance.
         * @param string   $trdr  SoftOne customer identifier.
         *
         * @return array<string,array<int,array<string,mixed>>>
         */
        protected function build_document_payload( WC_Order $order, $trdr ) {
            $series    = $this->api_client->get_default_saldoc_series();
            $warehouse = $this->api_client->get_warehouse();
            $order_id  = $order->get_id();
            if ( '' === $series ) {
                $this->log( 'warning', __( '[SO-ORD-009] SoftOne SALDOC series is not configured. Using fallback payload without series.', 'softone-woocommerce-integration' ), array( 'order_id' => $order->get_id() ) );
            }

            $header    = array(
                'SERIES'    => '' !== $series ? $series : null,
                'TRDR'      => (string) $trdr,
                'VARCHAR01' => (string) $order_id,
                'TRNDATE'   => $this->format_order_date( $order ),
                'COMMENTS'  => $this->build_order_comments( $order ),
            );

            $header = array_filter( $header, array( $this, 'filter_empty_value' ) );

            $payload = array(
                'SALDOC'   => array( $header ),
                'ITELINES' => $this->build_item_lines( $order ),
            );

            if ( '' !== $warehouse ) {
                $payload['MTRDOC'] = array(
                    array( 'WHOUSE' => $warehouse ),
                );
            }

            $payload = apply_filters( 'softone_wc_integration_order_payload', $payload, $order, $trdr, $this );

            return $payload;
        }

        /**
         * Format the order creation date for SoftOne.
         *
         * @param WC_Order $order WooCommerce order instance.
         *
         * @return string
         */
        protected function format_order_date( WC_Order $order ) {
            $date = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;

            if ( $date instanceof DateTimeInterface ) {
                return gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
            }

            return gmdate( 'Y-m-d H:i:s' );
        }

        /**
         * Compile an order level comment for the document header.
         *
         * @param WC_Order $order WooCommerce order instance.
         *
         * @return string
         */
        protected function build_order_comments( WC_Order $order ) {
            $comments = array();

            $note = method_exists( $order, 'get_customer_note' ) ? (string) $order->get_customer_note() : '';

            if ( '' !== $note ) {
                $comments[] = $note;
            }

            if ( method_exists( $order, 'get_payment_method_title' ) ) {
                $payment_method = (string) $order->get_payment_method_title();

                if ( '' !== $payment_method ) {
                    $comments[] = sprintf( /* translators: %s: payment method title */ __( 'Payment method: %s', 'softone-woocommerce-integration' ), $payment_method );
                }
            }

            $comments = array_filter( $comments, array( $this, 'filter_empty_value' ) );

            if ( empty( $comments ) ) {
                return sprintf( /* translators: %s: order number */ __( 'WooCommerce order %s', 'softone-woocommerce-integration' ), $order->get_order_number() );
            }

            return implode( ' | ', $comments );
        }

        /**
         * Build the line items payload for the document.
         *
         * @param WC_Order $order WooCommerce order instance.
         *
         * @return array<int,array<string,mixed>>
         */
        protected function build_item_lines( WC_Order $order ) {
            $items = array();

            foreach ( $order->get_items( array( 'line_item' ) ) as $item ) {
                $quantity = $item->get_quantity();

                if ( $quantity <= 0 ) {
                    continue;
                }

                $product = $item->get_product();

                if ( ! $product ) {
                    $this->log( 'warning', __( 'Order line skipped because the product could not be loaded.', 'softone-woocommerce-integration' ), array(
                        'order_id' => $order->get_id(),
                        'item_id'  => $item->get_id(),
                    ) );
                    continue;
                }

                $mtrl = $this->get_product_mtrl( $product );

                if ( '' === $mtrl ) {
                    $this->log( 'warning', __( '[SO-ORD-010] Order line skipped because the SoftOne item (MTRL) identifier is missing.', 'softone-woocommerce-integration' ), array(
                        'order_id'   => $order->get_id(),
                        'item_id'    => $item->get_id(),
                        'product_id' => $product->get_id(),
                    ) );
                    continue;
                }

                $line = array(
                    'MTRL'     => (string) $mtrl,
                    'QTY1'     => $this->format_quantity( $quantity ),
                    'COMMENTS1'=> $item->get_name(),
                );

                $line = array_filter( $line, array( $this, 'filter_empty_value' ) );

                $items[] = $line;
            }

            return $items;
        }

        /**
         * Retrieve the SoftOne item identifier for a WooCommerce product.
         *
         * @param WC_Product $product WooCommerce product instance.
         *
         * @return string
         */
        protected function get_product_mtrl( WC_Product $product ) {
            $product_id = $product->get_id();
            $mtrl       = get_post_meta( $product_id, Softone_Item_Sync::META_MTRL, true );

            if ( '' === $mtrl && method_exists( $product, 'get_parent_id' ) ) {
                $parent_id = $product->get_parent_id();

                if ( $parent_id ) {
                    $mtrl = get_post_meta( $parent_id, Softone_Item_Sync::META_MTRL, true );
                }
            }

            return is_scalar( $mtrl ) ? (string) $mtrl : '';
        }

        /**
         * Format the quantity for transmission.
         *
         * @param float|int $quantity Quantity value.
         *
         * @return float
         */
        protected function format_quantity( $quantity ) {
            return (float) $quantity;
        }

        /**
         * Attempt to transmit the document to SoftOne with retry support.
         *
         * @param WC_Order $order   WooCommerce order instance.
         * @param array    $payload Prepared payload.
         *
         * @return array
         */
        protected function transmit_document_with_retry( WC_Order $order, array $payload ) {
            $attempts      = (int) apply_filters( 'softone_wc_integration_order_sync_max_attempts', 3, $order, $payload, $this );
            $attempts      = max( 1, $attempts );
            $last_response = array();

            for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
                try {
                    $response = $this->api_client->set_data( 'SALDOC', $payload );

                    $this->log( 'info', __( 'SoftOne SALDOC request succeeded.', 'softone-woocommerce-integration' ), array(
                        'order_id' => $order->get_id(),
                        'attempt'  => $attempt,
                        'response' => $response,
                    ) );

                    return $response;
                } catch ( Softone_API_Client_Exception $exception ) {
                    $this->log( 'error', $exception->getMessage(), array(
                        'order_id'  => $order->get_id(),
                        'attempt'   => $attempt,
                        'exception' => $exception,
                    ) );
                    $this->add_order_note( $order, sprintf( /* translators: 1: attempt, 2: error message */ __( '[SO-ORD-008] SoftOne order export attempt %1$d failed: %2$s', 'softone-woocommerce-integration' ), $attempt, $exception->getMessage() ) );
                    $last_response = array();
                }

                if ( $attempt < $attempts ) {
                    $delay = (int) apply_filters( 'softone_wc_integration_order_sync_retry_delay', $this->calculate_retry_delay( $attempt ), $order, $payload, $this );

                    if ( $delay > 0 ) {
                        sleep( $delay );
                    }
                }
            }

            return $last_response;
        }

        /**
         * Calculate the delay between retries.
         *
         * @param int $attempt Current attempt (1-indexed).
         *
         * @return int
         */
        protected function calculate_retry_delay( $attempt ) {
            $attempt = max( 1, (int) $attempt );

            return min( 30, (int) pow( 2, $attempt - 1 ) );
        }

        /**
         * Retrieve the list of order statuses that trigger synchronisation.
         *
         * @return array<int,string>
         */
        protected function get_trigger_statuses() {
            $statuses = array( 'completed', 'processing' );
            $statuses = apply_filters( 'softone_wc_integration_order_statuses', $statuses, $this );

            $statuses = array_filter( array_map( 'sanitize_key', (array) $statuses ) );
            $statuses = array_unique( $statuses );

            return $statuses;
        }

        /**
         * Ensure a value is considered non-empty for payload purposes.
         *
         * @param mixed $value Value to inspect.
         *
         * @return bool
         */
        protected function filter_empty_value( $value ) {
            if ( null === $value ) {
                return false;
            }

            if ( is_string( $value ) ) {
                return '' !== trim( $value );
            }

            return ! empty( $value );
        }

        /**
         * Add a private note to the order when possible.
         *
         * @param WC_Order $order  WooCommerce order instance.
         * @param string   $note   Note content.
         *
         * @return void
         */
        protected function add_order_note( WC_Order $order, $note ) {
            if ( ! method_exists( $order, 'add_order_note' ) ) {
                return;
            }

            $note = (string) $note;

            if ( '' === $note ) {
                return;
            }

            $order->add_order_note( $note );
        }

        /**
         * Persist order meta changes using the most efficient method available.
         *
         * @param WC_Order $order WooCommerce order instance.
         *
         * @return void
         */
protected function persist_order_meta( WC_Order $order ) {
if ( method_exists( $order, 'save_meta_data' ) ) {
$order->save_meta_data();
return;
}

$order->save();
}

/**
 * Emit an entry to the order export logger when available.
 *
 * @param string $action  Action identifier.
 * @param string $message Summary describing the event.
 * @param array  $context Additional structured context.
 */
protected function log_order_event( $action, $message, array $context = array() ) {
if ( ! $this->order_event_logger || ! method_exists( $this->order_event_logger, 'log' ) ) {
return;
}

$this->order_event_logger->log( 'order_exports', $action, $message, $context );
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
         * Log a message with the configured logger.
         *
         * @param string $level   Log level (debug, info, warning, error).
         * @param string $message Log message.
         * @param array  $context Additional context information.
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
