<?php
/**
 * SoftOne customer synchronisation – ONLY when WooCommerce orders become "completed".
 *
 * Calls SoftOne:
 *   service: setData
 *   object : CUSTOMER
 *   data   : { CUSTOMER: [ { ... } ], CUSEXTRA: [ { BOOL01: "1" } ] }
 *
 * Behavior:
 * - Runs only on woocommerce_order_status_completed
 * - One-time guard per order via _softone_customer_synced = yes
 * - Stores SoftOne returned id into _softone_customer_id
 * - Does NOT create WordPress users; external SoftOne only
 *
 * Filters (to adapt IDs/mappings without editing this file again):
 * - softone_wc_should_sync_customer( bool $should, WC_Order $order ) : default true
 * - softone_wc_country_to_id( int $id, string $wc_country, WC_Order $order ) : default 0
 * - softone_wc_area_to_id( int $id, string $wc_country, string $wc_state, WC_Order $order ) : default 0
 * - softone_wc_currency_to_id( int $id, string $wc_currency, WC_Order $order ) : default 0
 * - softone_wc_trdcategory( int $id, WC_Order $order ) : default 1
 * - softone_wc_customer_code( string $code, WC_Order $order ) : default "WEB" . $order->get_id()
 * - softone_wc_customer_payload( array $payloadRow, WC_Order $order ) : mutate CUSTOMER row
 * - softone_wc_customer_extra_payload( array $cuExtraRow, WC_Order $order ) : mutate CUSEXTRA row
 *
 * Actions:
 * - softone_wc_before_sync_customer( WC_Order $order, array $preparedEnvelope )
 * - softone_wc_after_sync_customer(  WC_Order $order, array $preparedEnvelope, mixed $result )
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Softone_Customer_Sync' ) ) {

    class Softone_Customer_Sync {

        /** Meta guard to ensure we only sync once per order */
        const META_DONE = '_softone_customer_synced';
        /** Save the SoftOne returned id here */
        const META_SOFTONE_ID = '_softone_customer_id';

        /** @var Softone_API_Client|null */
        protected $api_client;

        /** @var WC_Logger|Psr\Log\LoggerInterface|null */
        protected $logger;

        public function __construct( $api_client = null, $logger = null ) {
            $this->api_client = ( $api_client instanceof Softone_API_Client )
                ? $api_client
                : ( class_exists( 'Softone_API_Client' ) ? new Softone_API_Client() : null );

            $this->logger = ( $logger && method_exists( $logger, 'log' ) )
                ? $logger
                : ( function_exists( 'wc_get_logger' ) ? wc_get_logger() : null );
        }

        /**
         * Register hooks with your loader (matches your other components).
         * @param Softone_Woocommerce_Integration_Loader $loader
         * @return void
         */
        public function register_hooks( $loader ) {
            $loader->add_action( 'woocommerce_order_status_completed', $this, 'on_order_completed', 10, 1 );
        }

        /**
         * Executed when an order becomes "completed".
         * @param int $order_id
         * @return void
         */
        public function on_order_completed( $order_id ) {
            $order_id = (int) $order_id;
            if ( $order_id <= 0 ) {
                return;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }

            // One-time guard
            if ( 'yes' === get_post_meta( $order_id, self::META_DONE, true ) ) {
                $this->log( 'debug', 'Customer sync already completed for this order, skipping.', array( 'order_id' => $order_id ) );
                return;
            }

            // Allow store owners to veto
            $should = apply_filters( 'softone_wc_should_sync_customer', true, $order );
            if ( ! $should ) {
                $this->log( 'info', 'Customer sync vetoed by filter.', array( 'order_id' => $order_id ) );
                return;
            }

            // Build SoftOne envelope exactly like the "setData -> CUSTOMER" contract
            $envelope = $this->build_envelope( $order );

            do_action( 'softone_wc_before_sync_customer', $order, $envelope );

            $result = null;
            $error  = null;

            try {
                if ( ! $this->api_client || ! method_exists( $this->api_client, 'sql_data' ) ) {
                    throw new \RuntimeException( 'SoftOne API client is not available.' );
                }

                /**
                 * Your SoftOne client earlier used: sql_data('getItems', array(), $extra)
                 * For setData we pass the request body in the 2nd parameter to stay consistent:
                 *
                 * {
                 *   "service":"setData",
                 *   "object":"CUSTOMER",
                 *   "data": { "CUSTOMER":[{...}], "CUSEXTRA":[{...}] }
                 * }
                 *
                 * clientID/appID are typically handled inside your Softone_API_Client.
                 */
                $result = $this->api_client->sql_data(
                    'setData',
                    array(
                        'object' => 'CUSTOMER',
                        'data'   => $envelope['data'],
                    ),
                    array() // no extras
                );

                // Try to capture SoftOne returned id if present (per your example: {"success":true,"id":"2937"})
                if ( is_array( $result ) && isset( $result['id'] ) && $result['id'] !== '' ) {
                    update_post_meta( $order_id, self::META_SOFTONE_ID, (string) $result['id'] );
                }
            } catch ( \Throwable $e ) {
                $error = $e->getMessage();
                $this->log( 'error', 'SoftOne customer setData failed.', array(
                    'order_id' => $order_id,
                    'error'    => $error,
                    'payload'  => $envelope,
                ) );
            }

            // Mark done (prevents duplicate attempts). If you want retries on failure, only mark on success.
            update_post_meta( $order_id, self::META_DONE, 'yes' );

            do_action( 'softone_wc_after_sync_customer', $order, $envelope, $result );

            if ( $error ) {
                return; // already logged
            }

            $this->log( 'info', 'SoftOne customer sync completed for order.', array(
                'order_id' => $order_id,
                'result'   => $result,
            ) );
        }

        /**
         * Build the SoftOne data envelope for setData/CUSTOMER.
         *
         * Shape:
         * [
         *   'data' => [
         *     'CUSTOMER' => [ { CODE, NAME, COUNTRY, AREAS, PHONE01, PHONE02, SOCURRENCY, EMAIL, ADDRESS, CITY, ZIP, TRDCATEGORY } ],
         *     'CUSEXTRA' => [ { BOOL01: "1" } ]
         *   ]
         * ]
         *
         * @param WC_Order $order
         * @return array
         */
        protected function build_envelope( $order ) {
            /** @var WC_Order $order */
            $email    = (string) $order->get_billing_email();
            $phone    = (string) $order->get_billing_phone();
            $fname    = (string) $order->get_billing_first_name();
            $lname    = (string) $order->get_billing_last_name();
            $company  = (string) $order->get_billing_company();
            $addr1    = (string) $order->get_billing_address_1();
            $addr2    = (string) $order->get_billing_address_2();
            $city     = (string) $order->get_billing_city();
            $state    = (string) $order->get_billing_state();
            $postcode = (string) $order->get_billing_postcode();
            $country  = (string) $order->get_billing_country();
            $wc_curr  = (string) get_woocommerce_currency();

            // Build CODE – must be unique, stable. Default: WEB + order_id (override via filter).
            $code = apply_filters( 'softone_wc_customer_code', 'WEB' . $order->get_id(), $order );

            // Resolve SoftOne numeric IDs via filters (site owner can map properly in theme/plugin).
            $country_id   = apply_filters( 'softone_wc_country_to_id', 0, $country, $order );
            $area_id      = apply_filters( 'softone_wc_area_to_id', 0, $country, $state, $order );
            $currency_id  = apply_filters( 'softone_wc_currency_to_id', 0, $wc_curr, $order );
            $trdcategory  = apply_filters( 'softone_wc_trdcategory', 1, $order );

            // Name priority: company or first+last
            $name = trim( $company ) !== '' ? $company : trim( $fname . ' ' . $lname );

            // PHONE02 is optional; we’ll put the same as billing phone if nothing else is available.
            $phone02 = (string) $order->get_billing_phone();
            // If you store a second phone in meta, you can fetch it here:
            $meta_phone2 = (string) $order->get_meta( '_billing_phone2' );
            if ( $meta_phone2 !== '' ) {
                $phone02 = $meta_phone2;
            }

            // ADDRESS – combine address_1 + address_2 if both exist
            $address = $addr1;
            if ( $addr2 !== '' ) {
                $address .= ' ' . $addr2;
            }
            if ( $address === '' ) {
                $address = 'No address';
            }
            if ( $city === '' ) {
                $city = 'City';
            }
            if ( $postcode === '' ) {
                $postcode = '0000';
            }

            // Build base CUSTOMER row (match your example keys exactly)
            $customer_row = array(
                'CODE'        => (string) $code,
                'NAME'        => (string) $name,
                'COUNTRY'     => (int) $country_id,  // e.g. 57 (map via filter)
                'AREAS'       => (int) $area_id,     // e.g. 22 (map via filter)
                'PHONE01'     => (string) $phone,
                'PHONE02'     => (string) $phone02,
                'SOCURRENCY'  => (int) $currency_id, // e.g. 47 (map via filter)
                'EMAIL'       => (string) $email,
                'ADDRESS'     => (string) $address,
                'CITY'        => (string) $city,
                'ZIP'         => (string) $postcode,
                'TRDCATEGORY' => (int) $trdcategory, // e.g. 1
            );

            // Allow last-mile edits to the core row
            $customer_row = apply_filters( 'softone_wc_customer_payload', $customer_row, $order );

            // CUSEXTRA defaults – your example sets BOOL01 to "1"
            $cusextra_row = array(
                'BOOL01' => '1',
            );
            $cusextra_row = apply_filters( 'softone_wc_customer_extra_payload', $cusextra_row, $order );

            return array(
                'data' => array(
                    'CUSTOMER' => array( $customer_row ),
                    'CUSEXTRA' => array( $cusextra_row ),
                ),
            );
        }

        /**
         * Logger helper.
         * @param string $level
         * @param string $message
         * @param array  $context
         * @return void
         */
        protected function log( $level, $message, array $context = array() ) {
            if ( $this->logger && method_exists( $this->logger, 'log' ) ) {
                $context['source'] = 'softone-customer-sync';
                $this->logger->log( $level, $message, $context );
                return;
            }
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[softone-customer-sync][' . strtoupper( $level ) . '] ' . $message . ' ' . wp_json_encode( $context ) );
            }
        }
    }
}
