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
        const CODE_WIDTH    = 5;
        const CODE_RANGE_REGISTERED = '8';
        const CODE_RANGE_GUEST      = '9';

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
 * Logger dedicated to detailed order export traces.
 *
 * @var Softone_Sync_Activity_Logger|null
 */
protected $order_event_logger;

        /**
         * Constructor.
         *
 * @param Softone_API_Client|null                $api_client          Optional API client.
 * @param WC_Logger|Psr\Log\LoggerInterface|null $logger              Optional logger instance.
 * @param Softone_Sync_Activity_Logger|null       $order_event_logger Optional order export logger.
 */
public function __construct( ?Softone_API_Client $api_client = null, $logger = null, ?Softone_Sync_Activity_Logger $order_event_logger = null ) {
$this->api_client        = $api_client ?: new Softone_API_Client();
$this->logger            = $logger ?: $this->get_default_logger();
$this->order_event_logger = $order_event_logger;
}

/**
 * Inject the order export logger after construction.
 *
 * @param Softone_Sync_Activity_Logger|null $order_event_logger Logger instance.
 *
 * @return void
 */
public function set_order_event_logger( ?Softone_Sync_Activity_Logger $order_event_logger ) {
$this->order_event_logger = $order_event_logger;
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
 * @param int   $customer_id Customer identifier.
 * @param array $context     Optional context metadata (e.g. order identifiers).
         *
         * @return string
         */
public function ensure_customer_trdr( $customer_id, array $context = array() ) {
$customer_id = absint( $customer_id );

            if ( $customer_id <= 0 ) {
                return '';
            }

$existing = get_user_meta( $customer_id, self::META_TRDR, true );
$existing = is_scalar( $existing ) ? (string) $existing : '';

if ( '' !== $existing ) {
try {
if ( $this->softone_customer_exists( $existing ) ) {
return $existing;
}
} catch ( Softone_API_Client_Exception $exception ) {
$this->log( 'error', $exception->getMessage(), array(
'user_id'   => $customer_id,
'exception' => $exception,
'context'   => array( 'trdr' => $existing ),
) );

return $existing;
}

$this->log( 'warning', __( 'Stored SoftOne customer reference is missing; recreating the record.', 'softone-woocommerce-integration' ), array(
'user_id' => $customer_id,
'context' => array( 'trdr' => $existing ),
) );
delete_user_meta( $customer_id, self::META_TRDR );
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
                $this->sync_customer( $customer, $context );
            } catch ( Softone_API_Client_Exception $exception ) {
                $error_message     = sprintf( /* translators: %s: error message */ __( 'SoftOne customer sync failed: %s', 'softone-woocommerce-integration' ), $exception->getMessage() );
                $exception_context = $exception->get_context();
                $exception_context = is_array( $exception_context ) ? $exception_context : array();

                $log_context = array(
                    'user_id'   => $customer_id,
                    'exception' => $exception,
                );

                if ( ! empty( $exception_context ) ) {
                    $log_context = array_merge( $log_context, $exception_context );
                }

                if ( $this->is_duplicate_customer_code_error( $exception ) ) {
                    $trdr = $this->resolve_duplicate_customer_reference( $customer, $log_context );

                    if ( '' !== $trdr ) {
                        return $trdr;
                    }
                }

                $this->log( 'error', $exception->getMessage(), $log_context );
                $this->log_customer_sync_exception( $error_message, $customer_id, array_merge( $context, $exception_context ) );
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
            if ( $this->defer_checkout_customer_sync( $customer_id, 'woocommerce_created_customer' ) ) {
                return;
            }

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
        public function handle_checkout_customer_created( $customer_id, $data = array() ) {
            if ( $this->defer_checkout_customer_sync( $customer_id, 'woocommerce_checkout_customer_created', $data ) ) {
                return;
            }

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
        public function handle_checkout_update_customer( $customer, $data = array() ) {
            if ( is_object( $customer ) && method_exists( $customer, 'get_id' ) ) {
                $customer_id = $customer->get_id();
            } else {
                $customer_id = $customer;
            }

            if ( $this->defer_checkout_customer_sync( $customer_id, 'woocommerce_checkout_update_customer', $data ) ) {
                return;
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
                $exception_context = $exception->get_context();
                $exception_context = is_array( $exception_context ) ? $exception_context : array();

                $log_context = array(
                    'user_id'   => $customer_id,
                    'exception' => $exception,
                );

                if ( ! empty( $exception_context ) ) {
                    $log_context = array_merge( $log_context, $exception_context );
                }

                $this->log( 'error', $exception->getMessage(), $log_context );
            }
        }

        /**
         * Defer SoftOne customer sync while WooCommerce is still processing checkout.
         *
         * @param int   $customer_id Customer identifier.
         * @param string $hook        Hook name that requested the sync.
         * @param array  $data        Optional checkout data.
         *
         * @return bool
         */
        protected function defer_checkout_customer_sync( $customer_id, $hook, array $data = array() ) {
            if ( ! $this->is_checkout_processing_request() ) {
                return false;
            }

            $this->log_deferred_checkout_customer_sync( $customer_id, $hook, $data );

            return true;
        }

        /**
         * Check whether the current request is WooCommerce's checkout AJAX endpoint.
         *
         * @return bool
         */
        protected function is_checkout_processing_request() {
            return isset( $_GET['wc-ajax'] ) && 'checkout' === sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        /**
         * Log a deferred checkout customer sync.
         *
         * @param int    $customer_id Customer identifier.
         * @param string $hook        Hook name that requested the sync.
         * @param array  $data        Optional checkout data.
         *
         * @return void
         */
        protected function log_deferred_checkout_customer_sync( $customer_id, $hook, array $data = array() ) {
            if ( ! $this->order_event_logger || ! method_exists( $this->order_event_logger, 'log' ) ) {
                return;
            }

            $email = '';

            if ( isset( $data['billing_email'] ) ) {
                $email = sanitize_email( (string) $data['billing_email'] );
            } elseif ( $customer_id > 0 ) {
                $user = get_userdata( absint( $customer_id ) );

                if ( $user && isset( $user->user_email ) ) {
                    $email = sanitize_email( (string) $user->user_email );
                }
            }

            $this->order_event_logger->log(
                'order_exports',
                'checkout_customer_sync_deferred',
                __( 'Deferred SoftOne customer sync until a WooCommerce order exists.', 'softone-woocommerce-integration' ),
                array(
                    'customer_id' => absint( $customer_id ),
                    'email'       => $email,
                    'hook'        => (string) $hook,
                    'wc_ajax'     => isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                )
            );
        }

        /**
         * Synchronise a WooCommerce customer with SoftOne.
         *
 * @param WC_Customer $customer WooCommerce customer instance.
 * @param array       $context  Optional context metadata.
         *
         * @throws Softone_API_Client_Exception When API requests fail.
         *
         * @return void
         */
protected function sync_customer( WC_Customer $customer, array $context = array() ) {
            $customer_id = $customer->get_id();
            $existing    = get_user_meta( $customer_id, self::META_TRDR, true );
            $existing    = is_scalar( $existing ) ? (string) $existing : '';

if ( '' !== $existing ) {
$this->update_customer( $customer, $existing, $context );
return;
}

            $match = $this->locate_existing_customer( $customer );

if ( ! empty( $match['TRDR'] ) ) {
$trdr = (string) $match['TRDR'];
update_user_meta( $customer_id, self::META_TRDR, $trdr );
$this->update_customer( $customer, $trdr, $context );
return;
}

$this->create_customer( $customer, $context );
}

        /**
         * Query SoftOne for an existing customer record matching the WooCommerce customer.
         *
 * @param WC_Customer $customer WooCommerce customer instance.
 * @param array       $context  Optional context metadata.
         *
         * @throws Softone_API_Client_Exception When API requests fail.
         *
         * @return array<string,mixed>
         */
        protected function locate_existing_customer( WC_Customer $customer ) {
            $code  = $this->generate_customer_code( $customer );
            $email = $customer->get_email();

            if ( '' !== $email ) {
                $response = $this->api_client->sql_data( 'getCustomers', array( 'EMAIL' => $email ) );
                $rows     = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array();

                foreach ( $rows as $row ) {
                    $row_email = isset( $row['EMAIL'] ) ? (string) $row['EMAIL'] : '';

                    if ( strcasecmp( $row_email, $email ) === 0 ) {
                        return $row;
                    }
                }
            }

            if ( '' !== $code ) {
                $response = $this->api_client->sql_data( 'getCustomers', array( 'CODE' => $code ) );
                $rows     = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array();

                foreach ( $rows as $row ) {
                    $row_code  = isset( $row['CODE'] ) ? (string) $row['CODE'] : '';
                    $row_email = isset( $row['EMAIL'] ) ? (string) $row['EMAIL'] : '';

                    if ( strcasecmp( $row_code, $code ) === 0 && $this->customer_row_email_is_reusable( $row_email, $email ) ) {
                        return $row;
                    }
                }
            }

            return array();
        }

        /**
         * Check whether a SoftOne customer exists for the provided TRDR.
         *
         * @param string $trdr SoftOne customer identifier.
         *
         * @throws Softone_API_Client_Exception When API requests fail.
         *
         * @return bool
         */
        protected function softone_customer_exists( $trdr ) {
            $trdr = trim( (string) $trdr );

            if ( '' === $trdr ) {
                return false;
            }

            $response = $this->api_client->sql_data( 'getCustomers', array( 'TRDR' => $trdr ) );
            $rows     = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array();

            foreach ( $rows as $row ) {
                if ( ! isset( $row['TRDR'] ) ) {
                    continue;
                }

                if ( strcasecmp( (string) $row['TRDR'], $trdr ) === 0 ) {
                    return true;
                }
            }

            return false;
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
protected function create_customer( WC_Customer $customer, array $context = array() ) {
$code    = $this->find_available_customer_code( $customer );

if ( '' === $code ) {
return;
}

$payload = $this->build_customer_payload( $customer, null, $code );

if ( empty( $payload['CUSTOMER'] ) ) {
return;
}

$this->log_customer_payload( 'customer_payload_create', __( 'Prepared SoftOne customer payload.', 'softone-woocommerce-integration' ), $customer, $payload, $context );

$response = $this->set_customer_data_with_code_seed_fallback( $payload, $customer, $context );

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
 * @param array       $context  Optional context metadata.
         *
         * @throws Softone_API_Client_Exception When API requests fail.
         *
         * @return void
         */
protected function update_customer( WC_Customer $customer, $trdr, array $context = array() ) {
$payload = $this->build_customer_payload( $customer, $trdr );

if ( empty( $payload['CUSTOMER'] ) ) {
return;
}

$this->log_customer_payload( 'customer_payload_update', __( 'Prepared SoftOne customer update payload.', 'softone-woocommerce-integration' ), $customer, $payload, $context );

$this->api_client->set_data( 'CUSTOMER', $payload );
}

        /**
         * Create a SoftOne customer, retrying with automatic web-code seed when required.
         *
         * @param array<string,array<int,array<string,mixed>>> $payload  Customer payload.
         * @param WC_Customer                                 $customer WooCommerce customer.
         * @param array<string,mixed>                         $context  Log context.
         *
         * @throws Softone_API_Client_Exception When the API request fails.
         *
         * @return array<string,mixed>
         */
        protected function set_customer_data_with_code_seed_fallback( array $payload, WC_Customer $customer, array $context = array() ) {
            try {
                return $this->api_client->set_data( 'CUSTOMER', $payload );
            } catch ( Softone_API_Client_Exception $exception ) {
                if ( ! $this->is_customer_code_seed_error( $exception ) ) {
                    throw $exception;
                }
            }

            $retry_payload = $this->build_customer_code_seed_payload( $payload );
            $this->log_customer_payload(
                'customer_code_seed_retry',
                __( 'Retrying SoftOne customer creation with automatic C-code seed.', 'softone-woocommerce-integration' ),
                $customer,
                $retry_payload,
                $context
            );

            try {
                return $this->api_client->set_data( 'CUSTOMER', $retry_payload );
            } catch ( Softone_API_Client_Exception $exception ) {
                if ( ! $this->is_duplicate_customer_code_error( $exception ) || ! $this->payload_uses_literal_customer_code_seed( $retry_payload ) ) {
                    throw $exception;
                }
            }

            $code_less_payload = $this->build_customer_code_seed_payload_without_code( $payload );

            $this->log_customer_payload(
                'customer_code_seed_omitted_retry',
                __( 'Retrying SoftOne customer creation without CODE after the literal C seed already existed.', 'softone-woocommerce-integration' ),
                $customer,
                $code_less_payload,
                $context
            );

            return $this->api_client->set_data( 'CUSTOMER', $code_less_payload );
        }

        /**
         * Replace generated customer code with SoftOne's automatic C-code seed.
         *
         * @param array<string,array<int,array<string,mixed>>> $payload Customer payload.
         *
         * @return array<string,array<int,array<string,mixed>>>
         */
        protected function build_customer_code_seed_payload( array $payload ) {
            if ( isset( $payload['CUSTOMER'][0] ) && is_array( $payload['CUSTOMER'][0] ) ) {
                $payload['CUSTOMER'][0]['CODE'] = 'C';
            }

            return $payload;
        }

        /**
         * Remove the customer code so SoftOne can apply its tenant-side default sequence.
         *
         * @param array<string,array<int,array<string,mixed>>> $payload Customer payload.
         *
         * @return array<string,array<int,array<string,mixed>>>
         */
        protected function build_customer_code_seed_payload_without_code( array $payload ) {
            if ( isset( $payload['CUSTOMER'][0] ) && is_array( $payload['CUSTOMER'][0] ) ) {
                unset( $payload['CUSTOMER'][0]['CODE'] );
            }

            return $payload;
        }

        /**
         * Check whether the payload is using SoftOne's literal C code seed.
         *
         * @param array<string,array<int,array<string,mixed>>> $payload Customer payload.
         *
         * @return bool
         */
        protected function payload_uses_literal_customer_code_seed( array $payload ) {
            if ( ! isset( $payload['CUSTOMER'][0] ) || ! is_array( $payload['CUSTOMER'][0] ) ) {
                return false;
            }

            $code = isset( $payload['CUSTOMER'][0]['CODE'] ) ? trim( (string) $payload['CUSTOMER'][0]['CODE'] ) : '';

            return 'C' === strtoupper( $code );
        }

        /**
         * Emit a log entry to the order export logger when available.
         *
         * @param string      $action   Action key describing the payload.
         * @param string      $message  Human readable summary.
         * @param WC_Customer $customer Customer instance.
         * @param array       $payload  Payload sent to SoftOne.
         * @param array       $context  Additional context (e.g. order ID).
         */
        protected function log_customer_payload( $action, $message, WC_Customer $customer, array $payload, array $context = array() ) {
            if ( ! $this->order_event_logger || ! method_exists( $this->order_event_logger, 'log' ) ) {
                return;
            }

            $log_context = array(
                'customer_id' => $customer->get_id(),
                'email'       => $customer->get_email(),
                'payload'     => $payload,
            );

            foreach ( $context as $key => $value ) {
                $log_context[ $key ] = $value;
            }

            $this->order_event_logger->log( 'order_exports', $action, $message, $log_context );
        }

        /**
         * Log a customer synchronisation exception into the order export activity channel.
         *
         * @param string $message     Error message to display.
         * @param int    $customer_id Customer identifier related to the failure.
         * @param array  $context     Additional context (e.g. order_id, order_number, SoftOne service payloads).
         */
        protected function log_customer_sync_exception( $message, $customer_id, array $context = array() ) {
            if ( ! $this->order_event_logger || ! method_exists( $this->order_event_logger, 'log' ) ) {
                return;
            }

            $log_context = array(
                'customer_id' => absint( $customer_id ),
            );

            if ( isset( $context['order_id'] ) ) {
                $log_context['order_id'] = absint( $context['order_id'] );
            }

            if ( isset( $context['order_number'] ) && '' !== (string) $context['order_number'] ) {
                $log_context['order_number'] = (string) $context['order_number'];
            }

            foreach ( $context as $key => $value ) {
                if ( isset( $log_context[ $key ] ) ) {
                    continue;
                }

                $log_context[ $key ] = $value;
            }

            $this->order_event_logger->log( 'order_exports', 'customer_sync_failed', $message, $log_context );
        }

        /**
         * Determine if a SoftOne error message indicates a duplicate customer code.
         *
         * @param Softone_API_Client_Exception $exception API exception instance.
         *
         * @return bool
         */
        protected function is_duplicate_customer_code_error( Softone_API_Client_Exception $exception ) {
            $message = $exception->getMessage();

            return false !== stripos( $message, 'Ο κωδικός υπάρχει ήδη' )
                || false !== stripos( $message, 'code already exists' );
        }

        /**
         * Check whether SoftOne rejected a generated code because the tenant expects a C seed.
         *
         * @param Softone_API_Client_Exception $exception API exception.
         *
         * @return bool
         */
        protected function is_customer_code_seed_error( Softone_API_Client_Exception $exception ) {
            $message = $exception->getMessage();

            return false !== stripos( $message, 'μορφής C' )
                || false !== stripos( $message, 'form C' );
        }

        /**
         * Attempt to reuse an existing SoftOne customer reference after a duplicate code error.
         *
         * @param WC_Customer $customer    WooCommerce customer instance.
         * @param array       $log_context Context used for logging.
         *
         * @return string
         */
        protected function resolve_duplicate_customer_reference( WC_Customer $customer, array $log_context = array() ) {
            try {
                $match = $this->locate_existing_customer( $customer );
            } catch ( Softone_API_Client_Exception $exception ) {
                $this->log( 'error', $exception->getMessage(), array_merge(
                    array(
                        'user_id'   => $customer->get_id(),
                        'exception' => $exception,
                    ),
                    $log_context
                ) );

                return '';
            }

            $trdr = isset( $match['TRDR'] ) ? (string) $match['TRDR'] : '';

            if ( '' === $trdr ) {
                return '';
            }

            update_user_meta( $customer->get_id(), self::META_TRDR, $trdr );

            $this->log( 'warning', __( 'Reused existing SoftOne customer after duplicate code error.', 'softone-woocommerce-integration' ), array_merge(
                array(
                    'user_id' => $customer->get_id(),
                    'trdr'    => $trdr,
                ),
                $log_context
            ) );

            return $trdr;
        }

        /**
         * Build the payload for SoftOne setData requests.
         *
         * @param WC_Customer   $customer WooCommerce customer instance.
         * @param string|null   $trdr     Existing SoftOne identifier, if available.
         *
         * @return array<string,array<int,array<string,string>>>
         */
        protected function build_customer_payload( WC_Customer $customer, $trdr = null, $code = '' ) {
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

            $country_code      = strtoupper( trim( (string) $country ) );
            $softone_country   = '';
            $country_log_attrs = array(
                'customer_id' => $customer->get_id(),
            );

            if ( null !== $trdr ) {
                $country_log_attrs['trdr'] = (string) $trdr;
            }

            if ( '' !== $country_code ) {
                $country_log_attrs['country'] = $country_code;
                $softone_country              = $this->map_country_to_softone_id( $country_code );

                if ( '' === $softone_country ) {
                    $this->log(
                        'warning',
                        sprintf(
                            /* translators: %s: ISO 3166-1 alpha-2 country code. */
                            __( '[SO-CNTRY-001] SoftOne country mapping missing for ISO code %s.', 'softone-woocommerce-integration' ),
                            $country_code
                        ),
                        $country_log_attrs
                    );

                    return array();
                }
            }

            $record = array(
                'CODE'        => '' !== (string) $code ? (string) $code : $this->generate_customer_code( $customer ),
                'NAME'        => $name,
                'EMAIL'       => $customer->get_email(),
                'PHONE01'     => $primary_phone,
                'PHONE02'     => $secondary,
                'ADDRESS'     => $address_1,
                'ADDRESS2'    => $address_2,
                'CITY'        => $city,
                'ZIP'         => $postcode,
                'COUNTRY'     => $softone_country,
                'AREAS'       => $this->api_client->get_areas(),
                'SOCURRENCY'  => $this->api_client->get_socurrency(),
                'TRDCATEGORY' => $this->api_client->get_trdcategory(),
            );

            if ( null !== $trdr ) {
                $record['TRDR'] = (string) $trdr;
            }

            $record = array_filter( $record, array( $this, 'filter_empty_value' ) );

            if ( empty( $record['CODE'] ) ) {
                $record['CODE'] = '' !== (string) $code ? (string) $code : $this->generate_customer_code( $customer );
            }

            if ( empty( $record['NAME'] ) ) {
                return array();
            }

            return array(
                'CUSTOMER' => array( $record ),
                'CUSEXTRA' => array(
                    array(
                        'BOOL01' => '1', // SoftOne requires BOOL01=1 to expose WooCommerce customers in downstream apps.
                    ),
                ),
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
         * Map an ISO 3166-1 alpha-2 country code to the SoftOne numeric identifier.
         *
         * @param string $country_code ISO country code from WooCommerce data.
         *
         * @return string
         */
        public function map_country_to_softone_id( $country_code ) {
            $country_code = strtoupper( trim( (string) $country_code ) );

            if ( '' === $country_code ) {
                return '';
            }

            $default_mappings = $this->normalize_country_mappings( $this->get_default_country_mappings() );

            $configured_mappings = array();

            if ( function_exists( 'softone_wc_integration_get_setting' ) ) {
                $configured_mappings = softone_wc_integration_get_setting( 'country_mappings', array() );
            }

            $normalized = array_merge(
                $default_mappings,
                $this->normalize_country_mappings( $configured_mappings )
            );

            /**
             * Filter the configured country mappings before they are used.
             *
             * @param array<string,string>   $normalized   Associative array of ISO => SoftOne ID pairs.
             * @param string                 $country_code Requested ISO country code.
             * @param Softone_Customer_Sync  $customer_sync Customer synchronisation service instance.
             */
            $normalized = apply_filters( 'softone_wc_integration_country_mappings', $normalized, $country_code, $this );

            $softone_id = isset( $normalized[ $country_code ] ) ? (string) $normalized[ $country_code ] : '';

            /**
             * Filter the resolved SoftOne country identifier.
             *
             * @param string                $softone_id   The mapped identifier (empty string when missing).
             * @param string                $country_code Requested ISO country code.
             * @param array<string,string>  $normalized   Associative array of ISO => SoftOne ID pairs.
             * @param Softone_Customer_Sync $customer_sync Customer synchronisation service instance.
             */
            $softone_id = apply_filters( 'softone_wc_integration_country_id', $softone_id, $country_code, $normalized, $this );

            return is_scalar( $softone_id ) ? trim( (string) $softone_id ) : '';
        }

        /**
         * Normalise an array of ISO => SoftOne country mappings.
         *
         * @param array<string,mixed> $mappings Country mappings to clean up.
         *
         * @return array<string,string>
         */
        protected function normalize_country_mappings( $mappings ) {
            if ( ! is_array( $mappings ) ) {
                return array();
            }

            $normalized = array();

            foreach ( $mappings as $code => $identifier ) {
                if ( ! is_scalar( $code ) ) {
                    continue;
                }

                $normalized_code = strtoupper( trim( (string) $code ) );

                if ( '' === $normalized_code ) {
                    continue;
                }

                $normalized_identifier = is_scalar( $identifier ) ? trim( (string) $identifier ) : '';

                if ( '' === $normalized_identifier ) {
                    continue;
                }

                $normalized[ $normalized_code ] = $normalized_identifier;
            }

            return $normalized;
        }

        /**
         * Provide built-in SoftOne country IDs required for core flows.
         *
         * Administrators can extend or override these via the Country mappings
         * setting in wp-admin. Entries returned here are guaranteed by the
         * plugin so that mission-critical markets are always supported.
         *
         * @return array<string,string>
         */
        protected function get_default_country_mappings() {
            return array(
                // Guaranteed mapping for Cyprus (SoftOne ID 57) used by PT Kids.
                'CY' => '57',
            );
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

            return $this->format_customer_code_number( self::CODE_RANGE_REGISTERED, $id );
        }

        /**
         * Find a reusable or unused SoftOne customer code within the web customer range.
         *
         * @param WC_Customer $customer WooCommerce customer instance.
         *
         * @throws Softone_API_Client_Exception When API requests fail.
         *
         * @return string
         */
        protected function find_available_customer_code( WC_Customer $customer ) {
            $id    = absint( $customer->get_id() );
            $email = trim( (string) $customer->get_email() );

            if ( $id <= 0 ) {
                return '';
            }

            $range_size = (int) pow( 10, self::CODE_WIDTH );
            $start      = $id % $range_size;

            for ( $offset = 0; $offset < $range_size; $offset++ ) {
                $number = ( $start + $offset ) % $range_size;
                $code   = $this->format_customer_code_number( self::CODE_RANGE_REGISTERED, $number );

                if ( '' === $code ) {
                    continue;
                }

                $response = $this->api_client->sql_data( 'getCustomers', array( 'CODE' => $code ) );
                $rows     = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array();

                if ( empty( $rows ) ) {
                    return $code;
                }

                $code_taken = false;
                foreach ( $rows as $row ) {
                    $row_code = isset( $row['CODE'] ) ? trim( (string) $row['CODE'] ) : '';
                    if ( '' !== $row_code && strcasecmp( $row_code, $code ) === 0 ) {
                        $code_taken = true;
                        break;
                    }
                }

                if ( ! $code_taken ) {
                    return $code;
                }

            }

            return '';
        }

        /**
         * Format a customer code inside a reserved SoftOne C range.
         *
         * @param string $range_digit Reserved range digit.
         * @param int    $number      Numeric suffix.
         *
         * @return string
         */
        protected function format_customer_code_number( $range_digit, $number ) {
            $number = absint( $number ) % (int) pow( 10, self::CODE_WIDTH );

            return sprintf( '%s%0' . self::CODE_WIDTH . 'd', self::CODE_PREFIX, $number );
        }

        /**
         * Check whether a customer row can be reused for the requested email.
         *
         * @param string $row_email      Email stored in SoftOne.
         * @param string $requested_email Email being synchronised.
         *
         * @return bool
         */
        protected function customer_row_email_is_reusable( $row_email, $requested_email ) {
            $row_email       = trim( (string) $row_email );
            $requested_email = trim( (string) $requested_email );

            if ( '' === $requested_email || '' === $row_email ) {
                return true;
            }

            return strcasecmp( $row_email, $requested_email ) === 0;
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

            if ( class_exists( 'WC_Logger' ) && $this->logger instanceof WC_Logger ) {
                $context['source'] = self::LOGGER_SOURCE;
            }

            $this->logger->log( $level, $message, $context );
        }
    }
}
