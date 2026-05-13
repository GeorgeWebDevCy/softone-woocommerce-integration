<?php

/**
 * Checkout diagnostics for SoftOne order/customer export flows.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Softone_Checkout_Diagnostics' ) ) {
	/**
	 * Records checkout lifecycle events before an order export exists.
	 */
	class Softone_Checkout_Diagnostics {

		/**
		 * Logger used by the Order Export Logs admin screen.
		 *
		 * @var Softone_Sync_Activity_Logger|null
		 */
		protected $logger;

		/**
		 * Stable identifier for the current request.
		 *
		 * @var string
		 */
		protected $request_id;

		/**
		 * Last checkout stage reached by this request.
		 *
		 * @var string
		 */
		protected $last_stage = '';

		/**
		 * Whether an order was processed in this request.
		 *
		 * @var bool
		 */
		protected $order_processed = false;

		/**
		 * Constructor.
		 *
		 * @param Softone_Sync_Activity_Logger|null $logger Logger instance.
		 */
		public function __construct( ?Softone_Sync_Activity_Logger $logger = null ) {
			$this->logger     = $logger;
			$this->request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'softone-checkout-', true );
		}

		/**
		 * Register checkout diagnostic hooks.
		 *
		 * @param Softone_Woocommerce_Integration_Loader $loader Loader instance.
		 *
		 * @return void
		 */
		public function register_hooks( Softone_Woocommerce_Integration_Loader $loader ) {
			$loader->add_action( 'woocommerce_before_checkout_process', $this, 'handle_before_checkout_process', 1, 0 );
			$loader->add_action( 'woocommerce_checkout_process', $this, 'handle_checkout_process', 1, 0 );
			$loader->add_filter( 'woocommerce_checkout_update_customer_data', $this, 'handle_checkout_update_customer_data_test_bypass', 1, 2 );
			$loader->add_action( 'woocommerce_after_checkout_validation', $this, 'handle_checkout_attempt_test_bypass', 998, 2 );
			$loader->add_action( 'woocommerce_after_checkout_validation', $this, 'handle_after_checkout_validation', 999, 2 );
			$loader->add_action( 'woocommerce_created_customer', $this, 'handle_created_customer', 1, 3 );
			$loader->add_action( 'woocommerce_created_customer', $this, 'handle_created_customer_hook_completed', PHP_INT_MAX, 3 );
			$loader->add_action( 'woocommerce_checkout_update_customer', $this, 'handle_checkout_update_customer', 1, 2 );
			$loader->add_action( 'woocommerce_checkout_update_customer', $this, 'handle_checkout_update_customer_hook_completed', PHP_INT_MAX, 2 );
			$loader->add_action( 'woocommerce_checkout_update_user_meta', $this, 'handle_checkout_update_user_meta', 1, 2 );
			$loader->add_action( 'woocommerce_checkout_update_user_meta', $this, 'handle_checkout_update_user_meta_hook_completed', PHP_INT_MAX, 2 );
			$loader->add_action( 'woocommerce_checkout_customer_created', $this, 'handle_checkout_customer_created', 1, 2 );
			$loader->add_action( 'woocommerce_checkout_create_order', $this, 'handle_checkout_create_order', 1, 2 );
			$loader->add_action( 'woocommerce_checkout_order_processed', $this, 'handle_checkout_order_processed', 1, 3 );
			$loader->add_action( 'shutdown', $this, 'handle_shutdown', 999, 0 );
		}

		/**
		 * Log when WooCommerce starts processing checkout.
		 *
		 * @return void
		 */
		public function handle_before_checkout_process() {
			$this->maybe_defer_created_customer_email_for_test();

			$this->log_stage(
				'checkout_before_process',
				__( 'WooCommerce checkout processing started.', 'softone-woocommerce-integration' ),
				array(
					'hooks' => $this->inspect_checkout_hooks(),
				)
			);
		}

		/**
		 * Log the checkout process hook.
		 *
		 * @return void
		 */
		public function handle_checkout_process() {
			$this->log_stage( 'checkout_process', __( 'WooCommerce checkout process hook fired.', 'softone-woocommerce-integration' ) );
		}

		/**
		 * Skip checkout customer profile updates only for the controlled live diagnostic test.
		 *
		 * @param bool  $update_customer Whether WooCommerce should update the customer object.
		 * @param mixed $checkout        Checkout object.
		 *
		 * @return bool
		 */
		public function handle_checkout_update_customer_data_test_bypass( $update_customer, $checkout = null ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			$data = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data = is_array( $data ) ? $data : array();

			if ( ! $this->should_bypass_order_attempt_validation( is_array( $data ) ? $data : array() ) ) {
				return $update_customer;
			}

			$this->log_stage(
				'checkout_customer_profile_update_bypassed',
				__( 'Bypassed checkout customer profile update for the controlled SoftOne test checkout.', 'softone-woocommerce-integration' ),
				array(
					'posted_email' => isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
					'original'     => (bool) $update_customer,
					'coupon'       => '100george',
				)
			);

			return false;
		}

		/**
		 * Log validation result.
		 *
		 * @param array     $data   Posted checkout data.
		 * @param WP_Error  $errors Validation errors.
		 *
		 * @return void
		 */
		public function handle_after_checkout_validation( $data, $errors ) {
			$error_messages = array();

			if ( is_wp_error( $errors ) ) {
				$error_messages = $errors->get_error_messages();
			}

			$this->log_stage(
				'checkout_after_validation',
				__( 'WooCommerce checkout validation completed.', 'softone-woocommerce-integration' ),
				array(
					'validation_error_count' => count( $error_messages ),
					'validation_errors'      => $this->limit_messages( $error_messages ),
					'posted_email'           => isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
					'create_account'         => isset( $data['createaccount'] ) ? (string) $data['createaccount'] : '',
				)
			);
		}

		/**
		 * Remove the order-attempt throttle only for the controlled live test checkout.
		 *
		 * @param array    $data   Posted checkout data.
		 * @param WP_Error $errors Validation errors.
		 *
		 * @return void
		 */
		public function handle_checkout_attempt_test_bypass( $data, $errors ) {
			if ( ! $this->should_bypass_order_attempt_validation( $data ) || ! is_wp_error( $errors ) ) {
				return;
			}

			$removed = array();

			foreach ( $errors->get_error_codes() as $code ) {
				$messages = $errors->get_error_messages( $code );

				foreach ( $messages as $message ) {
					if ( false === stripos( (string) $message, 'Too many order attempts' ) ) {
						continue;
					}

					if ( method_exists( $errors, 'remove' ) ) {
						$errors->remove( $code );
						$removed[] = $code;
					}

					break;
				}
			}

			if ( empty( $removed ) ) {
				return;
			}

			$this->log_stage(
				'checkout_order_attempt_throttle_bypassed',
				__( 'Bypassed checkout order-attempt throttle for the controlled SoftOne test checkout.', 'softone-woocommerce-integration' ),
				array(
					'posted_email' => isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
					'removed_codes' => array_values( array_unique( $removed ) ),
					'coupon'       => '100george',
				)
			);
		}

		/**
		 * Log base WooCommerce customer creation.
		 *
		 * @param int    $customer_id Customer identifier.
		 * @param array  $new_customer_data New customer data.
		 * @param string $password_generated Generated password flag.
		 *
		 * @return void
		 */
		public function handle_created_customer( $customer_id, $new_customer_data = array(), $password_generated = '' ) {
			$this->log_stage(
				'checkout_created_customer',
				__( 'WooCommerce created a customer during checkout.', 'softone-woocommerce-integration' ),
				array(
					'customer_id'        => absint( $customer_id ),
					'email'              => is_array( $new_customer_data ) && isset( $new_customer_data['user_email'] ) ? sanitize_email( (string) $new_customer_data['user_email'] ) : '',
					'password_generated' => is_scalar( $password_generated ) ? (string) $password_generated : '',
					'has_softone_trdr'    => $this->customer_has_softone_trdr( $customer_id ),
					'hooks'              => $this->inspect_checkout_hooks(),
				)
			);
		}

		/**
		 * Log whether every callback on woocommerce_created_customer completed.
		 *
		 * @param int    $customer_id Customer identifier.
		 * @param array  $new_customer_data New customer data.
		 * @param string $password_generated Generated password flag.
		 *
		 * @return void
		 */
		public function handle_created_customer_hook_completed( $customer_id, $new_customer_data = array(), $password_generated = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			$this->log_stage(
				'checkout_created_customer_hook_completed',
				__( 'All WooCommerce created-customer callbacks completed.', 'softone-woocommerce-integration' ),
				array(
					'customer_id' => absint( $customer_id ),
					'email'       => is_array( $new_customer_data ) && isset( $new_customer_data['user_email'] ) ? sanitize_email( (string) $new_customer_data['user_email'] ) : '',
				)
			);
		}

		/**
		 * Log checkout customer update.
		 *
		 * @param WC_Customer $customer Customer object.
		 * @param array       $data     Posted checkout data.
		 *
		 * @return void
		 */
		public function handle_checkout_update_customer( $customer, $data = array() ) {
			$customer_id = is_object( $customer ) && method_exists( $customer, 'get_id' ) ? $customer->get_id() : 0;

			$this->log_stage(
				'checkout_update_customer',
				__( 'WooCommerce checkout customer-update hook fired.', 'softone-woocommerce-integration' ),
				array(
					'customer_id'     => absint( $customer_id ),
					'posted_email'    => is_array( $data ) && isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
					'has_softone_trdr' => $this->customer_has_softone_trdr( $customer_id ),
					'hooks'           => $this->inspect_checkout_hooks(),
				)
			);
		}

		/**
		 * Log whether every callback on woocommerce_checkout_update_customer completed.
		 *
		 * @param WC_Customer $customer Customer object.
		 * @param array       $data     Posted checkout data.
		 *
		 * @return void
		 */
		public function handle_checkout_update_customer_hook_completed( $customer, $data = array() ) {
			$customer_id = is_object( $customer ) && method_exists( $customer, 'get_id' ) ? $customer->get_id() : 0;

			$this->log_stage(
				'checkout_update_customer_hook_completed',
				__( 'All WooCommerce checkout customer-update callbacks completed.', 'softone-woocommerce-integration' ),
				array(
					'customer_id'  => absint( $customer_id ),
					'posted_email' => is_array( $data ) && isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
				)
			);
		}

		/**
		 * Log checkout user meta updates.
		 *
		 * @param int   $customer_id Customer identifier.
		 * @param array $data        Posted checkout data.
		 *
		 * @return void
		 */
		public function handle_checkout_update_user_meta( $customer_id, $data = array() ) {
			$this->log_stage(
				'checkout_update_user_meta',
				__( 'WooCommerce checkout user-meta update hook fired.', 'softone-woocommerce-integration' ),
				array(
					'customer_id'  => absint( $customer_id ),
					'posted_email' => is_array( $data ) && isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
					'hooks'        => $this->inspect_checkout_hooks(),
				)
			);
		}

		/**
		 * Log whether every callback on woocommerce_checkout_update_user_meta completed.
		 *
		 * @param int   $customer_id Customer identifier.
		 * @param array $data        Posted checkout data.
		 *
		 * @return void
		 */
		public function handle_checkout_update_user_meta_hook_completed( $customer_id, $data = array() ) {
			$this->log_stage(
				'checkout_update_user_meta_hook_completed',
				__( 'All WooCommerce checkout user-meta update callbacks completed.', 'softone-woocommerce-integration' ),
				array(
					'customer_id'  => absint( $customer_id ),
					'posted_email' => is_array( $data ) && isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
				)
			);
		}

		/**
		 * Log checkout-specific customer creation.
		 *
		 * @param int   $customer_id Customer identifier.
		 * @param array $data        Posted checkout data.
		 *
		 * @return void
		 */
		public function handle_checkout_customer_created( $customer_id, $data = array() ) {
			$this->log_stage(
				'checkout_customer_created',
				__( 'WooCommerce checkout customer-created hook fired.', 'softone-woocommerce-integration' ),
				array(
					'customer_id'     => absint( $customer_id ),
					'posted_email'    => is_array( $data ) && isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
					'has_softone_trdr' => $this->customer_has_softone_trdr( $customer_id ),
				)
			);
		}

		/**
		 * Log order object creation before it is persisted.
		 *
		 * @param WC_Order $order Order object.
		 * @param array    $data  Posted checkout data.
		 *
		 * @return void
		 */
		public function handle_checkout_create_order( $order, $data = array() ) {
			$this->log_stage(
				'checkout_create_order',
				__( 'WooCommerce is creating the order object from checkout data.', 'softone-woocommerce-integration' ),
				array_merge(
					$this->build_order_context( $order ),
					array(
						'posted_email' => is_array( $data ) && isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
					)
				)
			);
		}

		/**
		 * Log completed checkout order processing.
		 *
		 * @param int      $order_id    Order identifier.
		 * @param array    $posted_data Posted checkout data.
		 * @param WC_Order $order       Order object.
		 *
		 * @return void
		 */
		public function handle_checkout_order_processed( $order_id, $posted_data = array(), $order = null ) {
			$this->order_processed = true;

			$this->log_stage(
				'checkout_order_processed',
				__( 'WooCommerce checkout order was processed.', 'softone-woocommerce-integration' ),
				array_merge(
					array(
						'order_id'     => absint( $order_id ),
						'posted_email' => is_array( $posted_data ) && isset( $posted_data['billing_email'] ) ? sanitize_email( (string) $posted_data['billing_email'] ) : '',
					),
					$this->build_order_context( $order )
				)
			);
		}

		/**
		 * Log checkout request shutdown if it exits before order processing.
		 *
		 * @return void
		 */
		public function handle_shutdown() {
			if ( ! $this->is_checkout_request() || $this->order_processed ) {
				return;
			}

			$error = error_get_last();
			$fatal = array();

			if ( is_array( $error ) && isset( $error['type'] ) && in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				$fatal = array(
					'type'    => (int) $error['type'],
					'message' => isset( $error['message'] ) ? (string) $error['message'] : '',
					'file'    => isset( $error['file'] ) ? basename( (string) $error['file'] ) : '',
					'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
				);
			}

			$this->log_stage(
				'checkout_shutdown_before_order_processed',
				__( 'Checkout request ended before WooCommerce reported an order as processed.', 'softone-woocommerce-integration' ),
				array(
					'last_stage'        => $this->last_stage,
					'response_code'     => function_exists( 'http_response_code' ) ? (int) http_response_code() : 0,
					'redirect_location' => $this->get_redirect_location(),
					'fatal_error'       => $fatal,
					'memory_peak_mb'    => round( memory_get_peak_usage( true ) / 1048576, 2 ),
				)
			);
		}

		/**
		 * Log a checkout diagnostic stage.
		 *
		 * @param string $action  Action key.
		 * @param string $message Human-readable message.
		 * @param array  $context Extra context.
		 *
		 * @return void
		 */
		protected function log_stage( $action, $message, array $context = array() ) {
			$this->last_stage = (string) $action;

			if ( ! $this->logger || ! method_exists( $this->logger, 'log' ) ) {
				return;
			}

			$this->logger->log(
				'order_exports',
				$action,
				$message,
				array_merge( $this->build_request_context(), $context )
			);
		}

		/**
		 * Build request-level context.
		 *
		 * @return array<string,mixed>
		 */
		protected function build_request_context() {
			$context = array(
				'request_id'      => $this->request_id,
				'is_ajax'         => function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX ),
				'wc_ajax'         => isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'request_uri'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				'cart_item_count' => $this->get_cart_item_count(),
				'cart_total'      => $this->get_cart_total(),
				'needs_payment'   => $this->cart_needs_payment(),
			);

			if ( function_exists( 'WC' ) && WC()->session ) {
				$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
				$context['chosen_shipping_methods'] = is_array( $chosen_methods ) ? array_values( $chosen_methods ) : array();
			}

			return $context;
		}

		/**
		 * Build order context when an order object exists.
		 *
		 * @param mixed $order Order object.
		 *
		 * @return array<string,mixed>
		 */
		protected function build_order_context( $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				return array();
			}

			return array(
				'order_id'      => absint( $order->get_id() ),
				'order_status'  => method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '',
				'customer_id'   => method_exists( $order, 'get_customer_id' ) ? absint( $order->get_customer_id() ) : 0,
				'billing_email' => method_exists( $order, 'get_billing_email' ) ? sanitize_email( (string) $order->get_billing_email() ) : '',
				'line_items'    => method_exists( $order, 'get_items' ) ? count( $order->get_items( array( 'line_item' ) ) ) : 0,
			);
		}

		/**
		 * Check whether this request is the WooCommerce checkout endpoint.
		 *
		 * @return bool
		 */
		protected function is_checkout_request() {
			return isset( $_GET['wc-ajax'] ) && 'checkout' === sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		/**
		 * Return the current cart item count.
		 *
		 * @return int
		 */
		protected function get_cart_item_count() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return 0;
			}

			return absint( WC()->cart->get_cart_contents_count() );
		}

		/**
		 * Return the current cart total.
		 *
		 * @return string
		 */
		protected function get_cart_total() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return '';
			}

			return (string) WC()->cart->get_total( 'edit' );
		}

		/**
		 * Return whether the current cart needs payment.
		 *
		 * @return bool
		 */
		protected function cart_needs_payment() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart || ! method_exists( WC()->cart, 'needs_payment' ) ) {
				return false;
			}

			return (bool) WC()->cart->needs_payment();
		}

		/**
		 * Check whether a customer already has a SoftOne TRDR.
		 *
		 * @param int $customer_id Customer identifier.
		 *
		 * @return bool
		 */
		protected function customer_has_softone_trdr( $customer_id ) {
			$customer_id = absint( $customer_id );

			if ( $customer_id <= 0 || ! class_exists( 'Softone_Customer_Sync' ) ) {
				return false;
			}

			$trdr = get_user_meta( $customer_id, Softone_Customer_Sync::META_TRDR, true );

			return is_scalar( $trdr ) && '' !== trim( (string) $trdr );
		}

		/**
		 * Return the Location response header if WordPress is redirecting.
		 *
		 * @return string
		 */
		protected function get_redirect_location() {
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'Location:' ) ) {
					return trim( substr( $header, 9 ) );
				}
			}

			return '';
		}

		/**
		 * Check whether this checkout is the controlled live diagnostic test.
		 *
		 * @param array $data Posted checkout data.
		 *
		 * @return bool
		 */
		protected function should_bypass_order_attempt_validation( array $data ) {
			$email = isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '';

			$allowed_emails = apply_filters(
				'softone_wc_integration_checkout_attempt_bypass_emails',
				array( 'support@georgenicolaou.me' )
			);

			$allowed_emails = is_array( $allowed_emails ) ? array_map( 'strtolower', array_map( 'sanitize_email', $allowed_emails ) ) : array();

			if ( '' === $email || ! in_array( strtolower( $email ), $allowed_emails, true ) ) {
				return false;
			}

			if ( ! function_exists( 'WC' ) || ! WC()->cart || ! method_exists( WC()->cart, 'has_discount' ) ) {
				return false;
			}

			return (bool) WC()->cart->has_discount( '100george' );
		}

		/**
		 * Defer WooCommerce account email sending for the controlled live diagnostic test.
		 *
		 * @return void
		 */
		protected function maybe_defer_created_customer_email_for_test() {
			$data = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data = is_array( $data ) ? $data : array();

			if ( ! $this->should_bypass_order_attempt_validation( $data ) ) {
				return;
			}

			if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
				return;
			}

			$mailer  = WC()->mailer();
			$removed = remove_action( 'woocommerce_created_customer', array( $mailer, 'send_transactional_email' ), 10 );

			if ( ! $removed ) {
				$removed = $this->remove_hook_callback( 'woocommerce_created_customer', 'WC_Emails', 'send_transactional_email', 10 );
			}

			$this->log_stage(
				'checkout_created_customer_email_deferred',
				__( 'Deferred WooCommerce created-customer email for the controlled SoftOne test checkout.', 'softone-woocommerce-integration' ),
				array(
					'posted_email' => isset( $data['billing_email'] ) ? sanitize_email( (string) $data['billing_email'] ) : '',
					'callback'     => 'WC_Emails::send_transactional_email',
					'removed'      => (bool) $removed,
					'coupon'       => '100george',
				)
			);
		}

		/**
		 * Inspect checkout-related hooks so blocked requests reveal the next callback.
		 *
		 * @return array<string,array<int,array<string,mixed>>>
		 */
		protected function inspect_checkout_hooks() {
			$hooks = array(
				'woocommerce_created_customer',
				'woocommerce_checkout_update_customer',
				'woocommerce_checkout_update_user_meta',
				'woocommerce_checkout_customer_created',
				'woocommerce_checkout_create_order',
				'woocommerce_checkout_order_processed',
			);

			$inspection = array();

			foreach ( $hooks as $hook_name ) {
				$inspection[ $hook_name ] = $this->describe_hook_callbacks( $hook_name );
			}

			return $inspection;
		}

		/**
		 * Remove a hook callback by class and method when the original object differs.
		 *
		 * @param string $hook_name Hook name.
		 * @param string $class     Callback class.
		 * @param string $method    Callback method.
		 * @param int    $priority  Hook priority.
		 *
		 * @return bool
		 */
		protected function remove_hook_callback( $hook_name, $class, $method, $priority ) {
			global $wp_filter;

			if ( empty( $wp_filter[ $hook_name ] ) || ! is_object( $wp_filter[ $hook_name ] ) ) {
				return false;
			}

			if ( empty( $wp_filter[ $hook_name ]->callbacks[ $priority ] ) || ! is_array( $wp_filter[ $hook_name ]->callbacks[ $priority ] ) ) {
				return false;
			}

			$removed = false;

			foreach ( $wp_filter[ $hook_name ]->callbacks[ $priority ] as $callback_id => $item ) {
				if ( empty( $item['function'] ) || ! is_array( $item['function'] ) || ! isset( $item['function'][0], $item['function'][1] ) ) {
					continue;
				}

				$target = is_object( $item['function'][0] ) ? get_class( $item['function'][0] ) : (string) $item['function'][0];

				if ( $class !== $target || $method !== (string) $item['function'][1] ) {
					continue;
				}

				unset( $wp_filter[ $hook_name ]->callbacks[ $priority ][ $callback_id ] );
				$removed = true;
			}

			return $removed;
		}

		/**
		 * Describe callbacks registered to a hook.
		 *
		 * @param string $hook_name Hook name.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		protected function describe_hook_callbacks( $hook_name ) {
			global $wp_filter;

			if ( empty( $wp_filter[ $hook_name ] ) || ! is_object( $wp_filter[ $hook_name ] ) ) {
				return array();
			}

			$callbacks = isset( $wp_filter[ $hook_name ]->callbacks ) && is_array( $wp_filter[ $hook_name ]->callbacks ) ? $wp_filter[ $hook_name ]->callbacks : array();
			$described = array();

			foreach ( $callbacks as $priority => $items ) {
				if ( ! is_array( $items ) ) {
					continue;
				}

				foreach ( $items as $item ) {
					if ( ! is_array( $item ) || ! isset( $item['function'] ) ) {
						continue;
					}

					$described[] = array(
						'priority'      => (int) $priority,
						'callback'      => $this->describe_callback( $item['function'] ),
						'accepted_args' => isset( $item['accepted_args'] ) ? absint( $item['accepted_args'] ) : 0,
					);
				}
			}

			return $described;
		}

		/**
		 * Convert a WordPress callback into a log-safe label.
		 *
		 * @param mixed $callback Callback definition.
		 *
		 * @return string
		 */
		protected function describe_callback( $callback ) {
			if ( is_string( $callback ) ) {
				return $callback;
			}

			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$target = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

				return $target . '::' . (string) $callback[1];
			}

			if ( $callback instanceof Closure ) {
				return 'Closure';
			}

			if ( is_object( $callback ) ) {
				return get_class( $callback ) . '::__invoke';
			}

			return 'unknown';
		}

		/**
		 * Keep log noise bounded for validation errors.
		 *
		 * @param array<int,string> $messages Messages.
		 *
		 * @return array<int,string>
		 */
		protected function limit_messages( array $messages ) {
			$messages = array_slice( $messages, 0, 5 );

			return array_map( 'wp_strip_all_tags', $messages );
		}
	}
}
