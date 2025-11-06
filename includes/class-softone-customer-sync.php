<?php
/**
 * SoftOne customer synchronisation – ONLY when WooCommerce orders become "completed".
 *
 * Calls SoftOne:
 *   service: setData
 *   object : CUSTOMER
 *   data   : { CUSTOMER: [ { ... } ], CUSEXTRA: [ { BOOL01: "1" } ] }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Softone_Customer_Sync' ) ) {

    class Softone_Customer_Sync {

        const META_DONE         = '_softone_customer_synced';
        const META_SOFTONE_ID   = '_softone_customer_id';

        protected $api_client;
        protected $logger;

        public function __construct( $api_client = null, $logger = null ) {
            $this->api_client = ( $api_client instanceof Softone_API_Client )
                ? $api_client
                : ( class_exists( 'Softone_API_Client' ) ? new Softone_API_Client() : null );

            $this->logger = ( $logger && method_exists( $logger, 'log' ) )
                ? $logger
                : ( function_exists( 'wc_get_logger' ) ? wc_get_logger() : null );
        }

        public function register_hooks( $loader ) {
            $loader->add_action( 'woocommerce_order_status_completed', $this, 'on_order_completed', 10, 1 );
        }

        public function on_order_completed( $order_id ) {
            $order_id = (int) $order_id;
            if ( $order_id <= 0 ) {
                return;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }

            if ( 'yes' === get_post_meta( $order_id, self::META_DONE, true ) ) {
                $this->log( 'debug', 'Already synced', array( 'order_id' => $order_id ) );
                return;
            }

            $envelope = $this->build_envelope( $order );

            try {
                if ( ! $this->api_client || ! method_exists( $this->api_client, 'sql_data' ) ) {
                    throw new \RuntimeException( 'SoftOne API client unavailable' );
                }

                $result = $this->api_client->sql_data(
                    'setData',
                    array(
                        'object' => 'CUSTOMER',
                        'data'   => $envelope['data'],
                    ),
                    array()
                );

                if ( is_array( $result ) && isset( $result['id'] ) ) {
                    update_post_meta( $order_id, self::META_SOFTONE_ID, (string) $result['id'] );
                }

                $this->log( 'info', 'SoftOne customer sync complete', array( 'order_id' => $order_id, 'result' => $result ) );

            } catch ( \Throwable $e ) {
                $this->log( 'error', 'SoftOne customer sync failed', array(
                    'order_id' => $order_id,
                    'error'    => $e->getMessage(),
                ) );
            }

            update_post_meta( $order_id, self::META_DONE, 'yes' );
        }

        protected function build_envelope( $order ) {
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

            $code = 'WEB' . $order->get_id();

            $country_id  = apply_filters( 'softone_wc_country_to_id', 57, $country, $order ); // default Cyprus
            $area_id     = apply_filters( 'softone_wc_area_to_id', 22, $country, $state, $order );
            $currency_id = apply_filters( 'softone_wc_currency_to_id', 47, get_woocommerce_currency(), $order );
            $trdcategory = apply_filters( 'softone_wc_trdcategory', 1, $order );

            $name = $company ?: trim( "$fname $lname" );
            $address = trim( $addr1 . ' ' . $addr2 );

            $customer_row = array(
                'CODE'        => $code,
                'NAME'        => $name ?: 'Web Customer',
                'COUNTRY'     => (int) $country_id,
                'AREAS'       => (int) $area_id,
                'PHONE01'     => $phone,
                'PHONE02'     => $phone,
                'SOCURRENCY'  => (int) $currency_id,
                'EMAIL'       => $email,
                'ADDRESS'     => $address ?: 'No address',
                'CITY'        => $city ?: 'City',
                'ZIP'         => $postcode ?: '0000',
                'TRDCATEGORY' => (int) $trdcategory,
            );

            $customer_row = apply_filters( 'softone_wc_customer_payload', $customer_row, $order );

            $cusextra_row = array( 'BOOL01' => '1' );
            $cusextra_row = apply_filters( 'softone_wc_customer_extra_payload', $cusextra_row, $order );

            return array(
                'data' => array(
                    'CUSTOMER' => array( $customer_row ),
                    'CUSEXTRA' => array( $cusextra_row ),
                ),
            );
        }

        protected function log( $level, $message, array $context = array() ) {
            if ( $this->logger && method_exists( $this->logger, 'log' ) ) {
                $context['source'] = 'softone-customer-sync';
                $this->logger->log( $level, $message, $context );
            } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[softone-customer-sync][' . strtoupper( $level ) . '] ' . $message . ' ' . wp_json_encode( $context ) );
            }
        }
    }
}
