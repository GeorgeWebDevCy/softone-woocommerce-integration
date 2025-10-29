<?php
/**
 * SoftOne customer synchronisation service.
 *
 * @package    Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Softone_Customer_Sync' ) ) {
    /**
     * Handles synchronising WooCommerce customers with SoftOne.
     */
    class Softone_Customer_Sync {

        const META_TRDR     = '_softone_trdr';
        const LOGGER_SOURCE = 'softone-customer-sync';
        const CODE_PREFIX   = 'WEB';

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
         * @param Softone_API_Client|null                $api_client Optional API client.
         * @param WC_Logger|Psr\Log\LoggerInterface|null $logger     Optional logger instance.
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
            $loader->add_action( 'woocommerce_created_customer', $this, 'handle_customer_created', 10, 1 );
            $loader->add_action( 'woocommerce_checkout_customer_created', $this, 'handle_checkout_customer_created', 10, 2 );
            $loader->add_action( 'woocommerce_checkout_update_customer', $this, 'handle_checkout_update_customer', 10, 2 );
            $loader->add_action( 'woocommerce_save_account_details', $this, 'handle_account_details_saved', 10, 1 );
            $loader->add_action( 'profile_update', $this, 'handle_profile_update', 10, 1 );
            $loader->add_action( 'woocommerce_customer_save_address', $this, 'handle_customer_save_address', 10, 1 );
        }

        /**
         * Ensure a WooCommerce customer has an associated SoftOne TRDR identifier.
         *
         * @param int $customer_id Customer identifier.
         *
         * @return string
         */
        public function ensure_customer_trdr( $customer_id ) {
            $customer_id = absint( $customer_id );

            if ( $customer_id <= 0 ) {
                return '';
            }

            $existing = get_user_meta( $customer_id, self::META_TRDR, true );
            $existing = is_scalar( $existing ) ? (string) $existing : '';

            if ( '' !== $existing ) {
                return $existing;
            }

            if ( ! class_exists( 'WC_Customer' ) ) {
                return '';
            }

            try {
                $customer = new WC_Customer( $customer_id );
            } catch ( Exception $exception ) {
                $this->log( 'error', $exception->getMessage(), array( 'user_id' => $customer_id, 'exception' => $exception ) );
                return '';
            }

            if ( ! $customer || ! $customer->get_id() ) {
                return '';
            }

            try {
                $this->sync_customer( $customer );
            } catch ( Softone_API_Client_Exception $exception ) {
                $this->log( 'error', $exception->getMessage(), array( 'user_id' => $customer_id, 'exception' => $exception ) );
                return '';
            }

            $updated = get_user_meta( $customer_id, self::META_TRDR, true );

            return is_scalar( $updated ) ? (string) $updated : '';
        }

        /**
         * Handle the WooCommerce customer creation action.
         *
         * @param int $customer_id Customer identifier.
         *
         * @return void
         */
        public function handle_customer_created( $customer_id ) {
            $this->maybe_sync_customer( $customer_id );
        }

        /**
         * Handle the checkout customer creation action.
         *
         * @param int   $customer_id Customer identifier.
         * @param array $data        Raw checkout data (unused).
         *
         * @return void
         */
        public function handle_checkout_customer_created( $customer_id, $data = array() ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
            $this->maybe_sync_customer( $customer_id );
        }

        /**
         * Handle checkout updates for logged-in customers.
         *
         * @param WC_Customer|int $customer Customer instance or identifier.
         * @param array           $data     Checkout data (unused).
         *
         * @return void
         */
        public function handle_checkout_update_customer( $customer, $data = array() ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
            if ( is_object( $customer ) && method_exists( $customer, 'get_id' ) ) {
                $customer_id = $customer->get_id();
            } else {
                $customer_id = $customer;
            }

            $this->maybe_sync_customer( $customer_id );
        }

        /**
         * Handle the account details update action.
         *
         * @param int $customer_id Customer identifier.
         *
         * @return void
         */
        public function handle_account_details_saved( $customer_id ) {
            $this->maybe_sync_customer( $customer_id );
        }

        /**
         * Handle generic profile updates.
         *
         * @param int $customer_id Customer identifier.
         *
         * @return void
         */
        public function handle_profile_update( $customer_id ) {
            $this->maybe_sync_customer( $customer_id );
        }

        /**
         * Handle address updates from the My Account area.
         *
         * @param int $customer_id Customer identifier.
         *
         * @return void
         */
        public function handle_customer_save_address( $customer_id ) {
            $this->maybe_sync_customer( $customer_id );
        }

        /**
         * Attempt to synchronise a customer with SoftOne.
         *
         * @param int $customer_id Customer identifier.
         *
         * @return void
         */
        protected function maybe_sync_customer( $customer_id ) {
            $customer_id = absint( $customer_id );

            if ( $customer_id <= 0 ) {
                return;
            }

            if ( ! class_exists( 'WC_Customer' ) ) {
                return;
            }

            try {
                $customer = new WC_Customer( $customer_id );
            } catch ( Exception $exception ) {
                $this->log( 'error', $exception->getMessage(), array( 'user_id' => $customer_id, 'exception' => $exception ) );
                return;
            }

            if ( ! $customer || ! $customer->get_id() ) {
                return;
            }

            try {
                $this->sync_customer( $customer );
            } catch ( Softone_API_Client_Exception $exception ) {
                $this->log( 'error', $exception->getMessage(), array( 'user_id' => $customer_id, 'exception' => $exception ) );
            }
        }

        /**
         * Synchronise a WooCommerce customer with SoftOne.
         *
         * @param WC_Customer $customer WooCommerce customer instance.
         *
         * @throws Softone_API_Client_Exception When API requests fail.
         *
         * @return void
         */
        protected function sync_customer( WC_Customer $customer ) {
            $customer_id = $customer->get_id();
            $existing    = get_user_meta( $customer_id, self::META_TRDR, true );
            $existing    = is_scalar( $existing ) ? (string) $existing : '';

            if ( '' !== $existing ) {
                $this->update_customer( $customer, $existing );
                return;
            }

            $match = $this->locate_existing_customer( $customer );

            if ( ! empty( $match['TRDR'] ) ) {
                $trdr = (string) $match['TRDR'];
                update_user_meta( $customer_id, self::META_TRDR, $trdr );
                $this->update_customer( $customer, $trdr );
                return;
            }

            $this->create_customer( $customer );
        }

        /**
         * Query SoftOne for an existing customer record matching the WooCommerce customer.
         *
         * @param WC_Customer $customer WooCommerce customer instance.
         *
         * @throws Softone_API_Client_Exception When API requests fail.
         *
         * @return array<string,mixed>
         */
        protected function locate_existing_customer( WC_Customer $customer ) {
            $code  = $this->generate_customer_code( $customer );
            $email = $customer->get_email();

            $arguments = array();

            if ( '' !== $code ) {
                $arguments['CODE'] = $code;
            }

            if ( '' !== $email ) {
                $arguments['EMAIL'] = $email;
            }

            $response = $this->api_client->sql_data( 'getCustomers', $arguments );
            $rows     = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array();

            foreach ( $rows as $row ) {
                $row_code  = isset( $row['CODE'] ) ? (string) $row['CODE'] : '';
                $row_email = isset( $row['EMAIL'] ) ? (string) $row['EMAIL'] : '';

                if ( '' !== $code && strcasecmp( $row_code, $code ) === 0 ) {
                    return $row;
                }

                if ( '' !== $email && strcasecmp( $row_email, $email ) === 0 ) {
                    return $row;
                }
            }

            return array();
        }

        /**
         * Create a new SoftOne customer record.
         *
         * @param WC_Customer $customer WooCommerce customer instance.
         *
         * @throws Softone_API_Client_Exception When API requests fail.
         *
         * @return void
         */
        protected function create_customer( WC_Customer $customer ) {
            $payload = $this->build_customer_payload( $customer );

            if ( empty( $payload['CUSTOMER'] ) ) {
                return;
            }

            $response = $this->api_client->set_data( 'CUSTOMER', $payload );

            if ( empty( $response['id'] ) ) {
                return;
            }

            $trdr = (string) $response['id'];
            update_user_meta( $customer->get_id(), self::META_TRDR, $trdr );
        }

        /**
         * Update an existing SoftOne customer record.
         *
         * @param WC_Customer $customer WooCommerce customer instance.
         * @param string      $trdr     SoftOne customer identifier.
         *
         * @throws Softone_API_Client_Exception When API requests fail.
         *
         * @return void
         */
        protected function update_customer( WC_Customer $customer, $trdr ) {
            $payload = $this->build_customer_payload( $customer, $trdr );

            if ( empty( $payload['CUSTOMER'] ) ) {
                return;
            }

            $this->api_client->set_data( 'CUSTOMER', $payload );
        }

        /**
         * Build the payload for SoftOne setData requests.
         *
         * @param WC_Customer   $customer WooCommerce customer instance.
         * @param string|null   $trdr     Existing SoftOne identifier, if available.
         *
         * @return array<string,array<int,array<string,string>>>
         */
        protected function build_customer_payload( WC_Customer $customer, $trdr = null ) {
            $billing_first_name = $customer->get_billing_first_name();
            $billing_last_name  = $customer->get_billing_last_name();
            $shipping_first     = method_exists( $customer, 'get_shipping_first_name' ) ? $customer->get_shipping_first_name() : '';
            $shipping_last      = method_exists( $customer, 'get_shipping_last_name' ) ? $customer->get_shipping_last_name() : '';

            $name = trim( implode( ' ', array_filter( array(
                $customer->get_first_name(),
                $customer->get_last_name(),
            ), array( $this, 'filter_empty_value' ) ) ) );

            if ( '' === $name ) {
                $name = trim( implode( ' ', array_filter( array( $billing_first_name, $billing_last_name ), array( $this, 'filter_empty_value' ) ) ) );
            }

            if ( '' === $name ) {
                $name = trim( implode( ' ', array_filter( array( $shipping_first, $shipping_last ), array( $this, 'filter_empty_value' ) ) ) );
            }

            if ( '' === $name ) {
                $name = $customer->get_email();
            }

            $billing_phone  = $customer->get_billing_phone();
            $shipping_phone = method_exists( $customer, 'get_shipping_phone' ) ? $customer->get_shipping_phone() : '';
            $primary_phone  = '' !== $billing_phone ? $billing_phone : $shipping_phone;
            $secondary      = ( '' !== $billing_phone && '' !== $shipping_phone && $billing_phone !== $shipping_phone ) ? $shipping_phone : '';

            $address_1 = $customer->get_billing_address_1();
            $address_2 = $customer->get_billing_address_2();

            if ( '' === $address_1 && method_exists( $customer, 'get_shipping_address_1' ) ) {
                $address_1 = $customer->get_shipping_address_1();
                $address_2 = method_exists( $customer, 'get_shipping_address_2' ) ? $customer->get_shipping_address_2() : '';
            }

            $city     = $customer->get_billing_city();
            $postcode = $customer->get_billing_postcode();
            $country  = $customer->get_billing_country();

            if ( '' === $city && method_exists( $customer, 'get_shipping_city' ) ) {
                $city = $customer->get_shipping_city();
            }

            if ( '' === $postcode && method_exists( $customer, 'get_shipping_postcode' ) ) {
                $postcode = $customer->get_shipping_postcode();
            }

            if ( '' === $country && method_exists( $customer, 'get_shipping_country' ) ) {
                $country = $customer->get_shipping_country();
            }

            $record = array(
                'CODE'    => $this->generate_customer_code( $customer ),
                'NAME'    => $name,
                'EMAIL'   => $customer->get_email(),
                'PHONE01' => $primary_phone,
                'PHONE02' => $secondary,
                'ADDRESS' => $address_1,
                'ADDRESS2'=> $address_2,
                'CITY'    => $city,
                'ZIP'     => $postcode,
                'COUNTRY' => $country,
            );

            if ( null !== $trdr ) {
                $record['TRDR'] = (string) $trdr;
            }

            $record = array_filter( $record, array( $this, 'filter_empty_value' ) );

            if ( empty( $record['CODE'] ) ) {
                $record['CODE'] = $this->generate_customer_code( $customer );
            }

            if ( empty( $record['NAME'] ) ) {
                return array();
            }

            return array(
                'CUSTOMER' => array( $record ),
            );
        }

        /**
         * Determine whether a value should be considered empty for payload purposes.
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
         * Generate a deterministic customer code for SoftOne.
         *
         * @param WC_Customer $customer WooCommerce customer instance.
         *
         * @return string
         */
        protected function generate_customer_code( WC_Customer $customer ) {
            $id = absint( $customer->get_id() );

            if ( $id <= 0 ) {
                return '';
            }

            return sprintf( '%s%06d', self::CODE_PREFIX, $id );
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
         * @param string $message Log message.
         * @param array  $context Additional context.
         *
         * @return void
         */
        protected function log( $level, $message, array $context = array() ) {
            if ( ! $this->logger || ! method_exists( $this->logger, 'log' ) ) {
                return;
            }

            if ( $this->logger instanceof WC_Logger ) {
                $context['source'] = self::LOGGER_SOURCE;
            }

            $this->logger->log( $level, $message, $context );
        }
    }
}
