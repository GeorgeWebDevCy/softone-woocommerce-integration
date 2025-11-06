<?php
/**
 * SoftOne customer synchronisation – ONLY on Woo "order completed".
 *
 * Guarantees:
 * - Runs ONLY on `woocommerce_order_status_completed`.
 * - Never re-runs for the same order (uses a meta guard).
 * - Does NOT create WP users; this is only for SoftOne (external) customer creation/update.
 * - Provides a safe place to call your SoftOne API client.
 *
 * Extensibility:
 * - Filter:  softone_wc_should_sync_customer (bool, $order) – default true
 * - Action:  softone_wc_before_sync_customer ($order, $prepared)
 * - Action:  softone_wc_after_sync_customer  ($order, $prepared, $result)
 * - Filter:  softone_wc_customer_payload     (array $prepared, $order)
 *
 * Logging:
 * - WooCommerce logger source: softone-customer-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Softone_Customer_Sync' ) ) {

    class Softone_Customer_Sync {

        const META_DONE = '_softone_customer_synced'; // "once" guard per order

        /** @var Softone_API_Client|null */
        protected static $api_client = null;

        /** @var WC_Logger|Psr\Log\LoggerInterface|null */
        protected static $logger = null;

        /**
         * Bootstrap the handler. Call once (e.g. from your main plugin loader).
         *
         * @param Softone_API_Client|null $api_client
         * @param mixed                   $logger
         * @return void
         */
        public static function init( $api_client = null, $logger = null ) {
            self::$api_client = $api_client instanceof Softone_API_Client ? $api_client : ( class_exists( 'Softone_API_Client' ) ? new Softone_API_Client() : null );
            self::$logger     = ( $logger && method_exists( $logger, 'log' ) ) ? $logger : ( function_exists( 'wc_get_logger' ) ? wc_get_logger() : null );

            // Hard gate: ONLY run when orders move to status "completed".
            add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ), 10, 1 );
        }

        /**
         * Runs ONLY when an order becomes "completed".
         *
         * @param int $order_id
         * @return void
         */
        public static function on_order_completed( $order_id ) {
            $order_id = (int) $order_id;
            if ( $order_id <= 0 ) {
                return;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }

            // Guard: never run twice on the same order
            if ( 'yes' === get_post_meta( $order_id, self::META_DONE, true ) ) {
                self::log( 'debug', 'Customer sync already completed for this order, skipping.', array( 'order_id' => $order_id ) );
                return;
            }

            // Allow last-minute veto or conditional control (e.g., store channel)
            $should = apply_filters( 'softone_wc_should_sync_customer', true, $order );
            if ( ! $should ) {
                self::log( 'info', 'Customer sync vetoed by filter.', array( 'order_id' => $order_id ) );
                return;
            }

            // Build a clean customer payload from the order
            $prepared = self::prepare_from_order( $order );
            $prepared = apply_filters( 'softone_wc_customer_payload', $prepared, $order );

            do_action( 'softone_wc_before_sync_customer', $order, $prepared );

            // Call out to SoftOne. If your client/service name differs, adjust below.
            $result = null;
            $error  = null;

            try {
                if ( self::$api_client && method_exists( self::$api_client, 'sql_data' ) ) {
                    // Example: adjust to your service name/contract. Many SoftOne setups use a "setCustomer" or "createOrUpdateCustomer".
                    // Here we send a generic 'setCustomer' with one row (your endpoint may differ – change as needed).
                    $result = self::$api_client->sql_data( 'setCustomer', array( 'rows' => array( $prepared ) ) );
                } else {
                    // If there is no API client, we still mark as done to avoid re-trigger storms.
                    self::log( 'warning', 'SoftOne API client not available; marking as synced without remote call.', array( 'order_id' => $order_id, 'prepared' => $prepared ) );
                }
            } catch ( \Throwable $e ) {
                $error = $e->getMessage();
                self::log( 'error', 'SoftOne customer create/update failed.', array( 'order_id' => $order_id, 'error' => $error, 'prepared' => $prepared ) );
            }

            // Mark done (even if failed – if you want retries, change this logic to only mark done on success).
            update_post_meta( $order_id, self::META_DONE, 'yes' );

            do_action( 'softone_wc_after_sync_customer', $order, $prepared, $result );

            if ( $error ) {
                return; // already logged
            }

            self::log( 'info', 'SoftOne customer sync completed for order.', array( 'order_id' => $order_id, 'result' => $result ) );
        }

        /**
         * Build a portable payload from an order's billing data.
         * Map/rename fields to match your SoftOne service expectations.
         *
         * @param WC_Order $order
         * @return array
         */
        protected static function prepare_from_order( $order ) {
            /** @var WC_Order $order */
            $email   = (string) $order->get_billing_email();
            $phone   = (string) $order->get_billing_phone();
            $fname   = (string) $order->get_billing_first_name();
            $lname   = (string) $order->get_billing_last_name();
            $company = (string) $order->get_billing_company();

            $addr1 = (string) $order->get_billing_address_1();
            $addr2 = (string) $order->get_billing_address_2();
            $city  = (string) $order->get_billing_city();
            $state = (string) $order->get_billing_state();
            $pc    = (string) $order->get_billing_postcode();
            $country = (string) $order->get_billing_country();

            // If you store VAT/Tax ID in a custom field, pull it here (common: _billing_vat, _billing_afm, etc.)
            $vat = (string) $order->get_meta( '_billing_vat' );
            if ( '' === $vat ) {
                $vat = (string) $order->get_meta( 'billing_vat' );
            }

            // EXAMPLE payload keys – change to your SoftOne schema (TRDR name, AFM for VAT, etc.)
            $prepared = array(
                'TRDR_NAME'      => trim( $company ) !== '' ? $company : trim( $fname . ' ' . $lname ),
                'FIRSTNAME'      => $fname,
                'LASTNAME'       => $lname,
                'EMAIL'          => $email,
                'PHONE01'        => $phone,
                'AFM'            => $vat,          // VAT/Tax ID if applicable
                'ADDRESS'        => $addr1,
                'ADDRESS2'       => $addr2,
                'ZIP'            => $pc,
                'CITY'           => $city,
                'STATE'          => $state,
                'COUNTRY'        => $country,
                // A reference to Woo order/customer for idempotency on SoftOne side:
                'VARCHAR01'      => 'WC-ORDER-' . $order->get_id(),
                'COMMENTS'       => 'Auto-created by Woo on order completed',
            );

            return array_filter( $prepared, static function( $v ) { return $v !== null; } );
        }

        /**
         * Logger helper.
         *
         * @param string $level
         * @param string $message
         * @param array  $context
         * @return void
         */
        protected static function log( $level, $message, array $context = array() ) {
            if ( self::$logger && method_exists( self::$logger, 'log' ) ) {
                $context['source'] = 'softone-customer-sync';
                self::$logger->log( $level, $message, $context );
                return;
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[softone-customer-sync][' . strtoupper( $level ) . '] ' . $message . ' ' . wp_json_encode( $context ) );
            }
        }
    }
}
