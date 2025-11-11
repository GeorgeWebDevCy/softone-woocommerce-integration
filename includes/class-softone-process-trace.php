<?php
/**
 * Helpers for capturing detailed process traces during manual sync runs.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Softone_Process_Trace' ) ) {
	/**
	 * Collects structured trace entries for display in the admin UI.
	 */
	class Softone_Process_Trace {

		/**
		 * Recorded trace entries.
		 *
		 * @var array<int,array<string,mixed>>
		 */
		protected $entries = array();

		/**
		 * Add a new entry to the trace log.
		 *
		 * @param string               $type    Entry type identifier (e.g. api, log, activity, note).
		 * @param string               $action  Short action key describing the event.
		 * @param string               $message Human readable summary of the event.
		 * @param array<string,mixed>  $context Additional context values.
		 * @param string               $level   Severity level (info, warning, error).
		 *
		 * @return void
		 */
		public function add_event( $type, $action, $message, array $context = array(), $level = 'info' ) {
			$timestamp = time();

			$this->entries[] = array(
				'timestamp' => $timestamp,
				'type'      => (string) $type,
				'action'    => (string) $action,
				'level'     => (string) $level,
				'message'   => (string) $message,
				'context'   => $this->sanitize_context( $context ),
			);
		}

		/**
		 * Retrieve the recorded entries.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_entries() {
			return $this->entries;
		}

		/**
		 * Mask sensitive identifiers before display.
		 *
		 * @param string $value Raw identifier.
		 *
		 * @return string
		 */
		public function mask_identifier( $value ) {
			$value = (string) $value;

			if ( '' === $value ) {
				return '';
			}

			if ( strlen( $value ) <= 4 ) {
				return str_repeat( '•', strlen( $value ) );
			}

			return str_repeat( '•', max( 0, strlen( $value ) - 4 ) ) . substr( $value, -4 );
		}

		/**
		 * Recursively sanitise context data for safe output.
		 *
		 * @param mixed $context Raw context value.
		 * @param int   $depth   Current recursion depth.
		 *
		 * @return mixed
		 */
		protected function sanitize_context( $context, $depth = 0 ) {
			if ( $depth > 5 ) {
				return '…';
			}

			if ( is_array( $context ) ) {
				$sanitized = array();

				foreach ( $context as $key => $value ) {
					$key_string = is_scalar( $key ) ? (string) $key : (string) maybe_serialize( $key );

					if ( $this->is_sensitive_key( $key_string ) ) {
						$sanitized[ $key_string ] = '••••';
						continue;
					}

					$sanitized[ $key_string ] = $this->sanitize_context( $value, $depth + 1 );
				}

				return $sanitized;
			}

			if ( is_object( $context ) ) {
				if ( method_exists( $context, '__toString' ) ) {
					return (string) $context;
				}

				if ( $context instanceof WP_Error ) {
					return array(
						'code'    => $context->get_error_code(),
						'message' => $context->get_error_message(),
						'data'    => $this->sanitize_context( $context->get_error_data(), $depth + 1 ),
					);
				}

				return (string) maybe_serialize( $context );
			}

			if ( is_bool( $context ) ) {
				return $context;
			}

			if ( is_scalar( $context ) || null === $context ) {
				return $context;
			}

			return (string) maybe_serialize( $context );
		}

		/**
		 * Determine whether a context key is sensitive.
		 *
		 * @param string $key Context key.
		 *
		 * @return bool
		 */
		protected function is_sensitive_key( $key ) {
			$key = strtolower( (string) $key );

			$sensitive = array( 'password', 'pass', 'secret', 'token', 'authorization', 'auth', 'clientsecret' );

			return in_array( $key, $sensitive, true );
		}
	}
}

if ( ! class_exists( 'Softone_Process_Trace_Stream_Logger' ) ) {
	/**
	 * Minimal logger that forwards messages to the trace buffer.
	 */
	class Softone_Process_Trace_Stream_Logger {

		/**
		 * Trace collector instance.
		 *
		 * @var Softone_Process_Trace
		 */
		protected $trace;

		/**
		 * Constructor.
		 *
		 * @param Softone_Process_Trace $trace Trace collector.
		 */
		public function __construct( Softone_Process_Trace $trace ) {
			$this->trace = $trace;
		}

		/**
		 * Record a log entry in the trace.
		 *
		 * @param string               $level   Severity level.
		 * @param string               $message Log message.
		 * @param array<string,mixed>  $context Additional context values.
		 *
		 * @return void
		 */
		public function log( $level, $message, $context = array() ) {
			if ( ! is_array( $context ) ) {
				$context = array();
			}

			$this->trace->add_event( 'log', 'log_' . strtolower( (string) $level ), (string) $message, $context, (string) $level );
		}
	}
}

if ( ! class_exists( 'Softone_Process_Trace_Activity_Logger' ) && class_exists( 'Softone_Sync_Activity_Logger' ) ) {
	/**
	 * Activity logger proxy that records entries for the process trace.
	 */
	class Softone_Process_Trace_Activity_Logger extends Softone_Sync_Activity_Logger {

		/**
		 * Trace collector instance.
		 *
		 * @var Softone_Process_Trace
		 */
		protected $trace;

		/**
		 * Constructor.
		 *
		 * @param Softone_Process_Trace $trace Trace collector.
		 */
		public function __construct( Softone_Process_Trace $trace ) {
			$this->trace = $trace;
		}

		/**
		 * {@inheritDoc}
		 */
		public function log( $channel, $action, $message, array $context = array() ) {
			$this->trace->add_event(
				'activity',
				(string) $action,
				(string) $message,
				array_merge(
					array( 'channel' => (string) $channel ),
					$context
				),
				'info'
			);

			parent::log( $channel, $action, $message, $context );
		}
	}
}

if ( ! class_exists( 'Softone_Process_Trace_Api_Client' ) && class_exists( 'Softone_API_Client' ) ) {
	/**
	 * API client that emits detailed trace events while communicating with SoftOne.
	 */
	class Softone_Process_Trace_Api_Client extends Softone_API_Client {

		/**
		 * Trace collector instance.
		 *
		 * @var Softone_Process_Trace
		 */
		protected $trace;

		/**
		 * Constructor.
		 *
		 * @param Softone_Process_Trace $trace    Trace collector.
		 * @param array<string,mixed>   $settings Optional client settings.
		 * @param mixed                 $logger   Optional logger instance.
		 */
		public function __construct( Softone_Process_Trace $trace, array $settings = array(), $logger = null ) {
			$this->trace = $trace;

			parent::__construct( $settings, $logger );
		}

		/**
		 * {@inheritDoc}
		 */
		public function login() {
			$this->trace->add_event( 'api', 'login_start', __( 'Authenticating with SoftOne via login service.', 'softone-woocommerce-integration' ) );

			try {
				$response = parent::login();

				$client_id = isset( $response['clientID'] ) ? (string) $response['clientID'] : '';

				$this->trace->add_event(
					'api',
					'login_success',
					__( 'SoftOne login succeeded.', 'softone-woocommerce-integration' ),
					array(
						'client_id' => $this->trace->mask_identifier( $client_id ),
					)
				);

				return $response;
			} catch ( Exception $exception ) {
				$this->trace->add_event(
					'api',
					'login_failed',
					__( 'SoftOne login request failed.', 'softone-woocommerce-integration' ),
					array( 'message' => $exception->getMessage() ),
					'error'
				);

				throw $exception;
			}
		}

		/**
		 * {@inheritDoc}
		 */
		public function authenticate( $client_id ) {
			$this->trace->add_event(
				'api',
				'authenticate_start',
				__( 'Authenticating session with SoftOne.', 'softone-woocommerce-integration' ),
				array( 'client_id' => $this->trace->mask_identifier( $client_id ) )
			);

			try {
				$response = parent::authenticate( $client_id );

				$this->trace->add_event(
					'api',
					'authenticate_success',
					__( 'Softone authentication confirmed.', 'softone-woocommerce-integration' ),
					array( 'client_id' => $this->trace->mask_identifier( isset( $response['clientID'] ) ? $response['clientID'] : '' ) )
				);

				return $response;
			} catch ( Exception $exception ) {
				$this->trace->add_event(
					'api',
					'authenticate_failed',
					__( 'SoftOne authentication request failed.', 'softone-woocommerce-integration' ),
					array( 'message' => $exception->getMessage() ),
					'error'
				);

				throw $exception;
			}
		}

		/**
		 * {@inheritDoc}
		 */
		public function get_client_id( $force_refresh = false ) {
			if ( ! $force_refresh ) {
				$client_id = get_transient( self::TRANSIENT_CLIENT_ID_KEY );

				if ( ! empty( $client_id ) ) {
					$this->trace->add_event(
						'api',
						'client_id_reused_transient',
						__( 'Re-using cached SoftOne client ID from transient cache.', 'softone-woocommerce-integration' ),
						array( 'client_id' => $this->trace->mask_identifier( $client_id ) )
					);

					return (string) $client_id;
				}

				$meta = $this->get_client_meta();

				if ( ! empty( $meta['client_id'] ) && ! empty( $meta['expires_at'] ) && time() < (int) $meta['expires_at'] ) {
					$remaining = (int) $meta['expires_at'] - time();

					if ( $remaining > 0 ) {
						set_transient( self::TRANSIENT_CLIENT_ID_KEY, $meta['client_id'], $remaining );
					}

					$this->trace->add_event(
						'api',
						'client_id_reused_meta',
						__( 'Re-using cached Softone client ID from options store.', 'softone-woocommerce-integration' ),
						array(
							'client_id'  => $this->trace->mask_identifier( $meta['client_id'] ),
							'expires_in' => max( 0, $remaining ),
						)
					);

					return (string) $meta['client_id'];
				}
			}

			$this->trace->add_event(
				'api',
				'client_id_refresh',
				__( 'Cached Softone client ID unavailable – requesting a new session.', 'softone-woocommerce-integration' ),
				array( 'force_refresh' => (bool) $force_refresh )
			);

			return parent::get_client_id( $force_refresh );
		}

		/**
		 * {@inheritDoc}
		 */
		protected function bootstrap_client_session() {
			$this->trace->add_event( 'api', 'session_start', __( 'Starting new Softone session.', 'softone-woocommerce-integration' ) );

			try {
				$client_id = parent::bootstrap_client_session();

				$this->trace->add_event(
					'api',
					'session_ready',
					__( 'Softone session established.', 'softone-woocommerce-integration' ),
					array( 'client_id' => $this->trace->mask_identifier( $client_id ) )
				);

				return $client_id;
			} catch ( Exception $exception ) {
				$this->trace->add_event(
					'api',
					'session_failed',
					__( 'Failed to establish Softone session.', 'softone-woocommerce-integration' ),
					array( 'message' => $exception->getMessage() ),
					'error'
				);

				throw $exception;
			}
		}

		/**
		 * {@inheritDoc}
		 */
		protected function cache_client_id( $client_id, $ttl = 0 ) {
			parent::cache_client_id( $client_id, $ttl );

			$this->trace->add_event(
				'api',
				'client_id_cached',
				__( 'Stored Softone client ID for reuse.', 'softone-woocommerce-integration' ),
				array(
					'client_id' => $this->trace->mask_identifier( $client_id ),
					'ttl'       => (int) $ttl,
				)
			);
		}

		/**
		 * {@inheritDoc}
		 */
		public function clear_cached_client_id() {
			parent::clear_cached_client_id();

			$this->trace->add_event(
				'api',
				'client_id_cleared',
				__( 'Cleared cached Softone client identifiers.', 'softone-woocommerce-integration' )
			);
		}

		/**
		 * {@inheritDoc}
		 */
		public function call_service( $service, array $data = array(), $requires_client_id = true, $retry_on_authentication = true ) {
			$this->trace->add_event(
				'api',
				'call_service',
				sprintf( /* translators: %s: Softone service name. */ __( 'Calling Softone service: %s.', 'softone-woocommerce-integration' ), $service ),
				array(
					'requires_client_id'      => (bool) $requires_client_id,
					'retry_on_authentication' => (bool) $retry_on_authentication,
					'payload_overview'        => $this->summarise_payload( $data ),
				)
			);

			try {
				$response = parent::call_service( $service, $data, $requires_client_id, $retry_on_authentication );

				$this->trace->add_event(
					'api',
					'call_service_completed',
					__( 'Softone service responded successfully.', 'softone-woocommerce-integration' ),
					$this->summarise_response( $response )
				);

				return $response;
			} catch ( Exception $exception ) {
				$this->trace->add_event(
					'api',
					'call_service_failed',
					__( 'Softone service call failed.', 'softone-woocommerce-integration' ),
					array(
						'error'   => $exception->getMessage(),
						'service' => (string) $service,
					),
					'error'
				);

				throw $exception;
			}
		}

		/**
		 * Generate a light-weight summary of the outbound payload.
		 *
		 * @param array<string,mixed> $payload Raw payload.
		 *
		 * @return array<string,mixed>
		 */
		protected function summarise_payload( array $payload ) {
			$summary = array();

			foreach ( $payload as $key => $value ) {
				$key_string = is_scalar( $key ) ? (string) $key : (string) maybe_serialize( $key );

				if ( $this->is_sensitive_key( $key_string ) ) {
					$summary[ $key_string ] = '••••';
					continue;
				}

				if ( is_array( $value ) ) {
					$summary[ $key_string ] = array(
						'type'  => 'array',
						'count' => count( $value ),
					);
				} elseif ( is_scalar( $value ) || null === $value ) {
					$summary[ $key_string ] = (string) $value;
				} else {
					$summary[ $key_string ] = (string) maybe_serialize( $value );
				}
			}

			return $summary;
		}

		/**
		 * Generate a concise summary of the Softone response payload.
		 *
		 * @param array<string,mixed> $response Raw response.
		 *
		 * @return array<string,mixed>
		 */
		protected function summarise_response( array $response ) {
			$summary = array(
				'keys'    => array_keys( $response ),
				'success' => isset( $response['success'] ) ? $response['success'] : null,
			);

			if ( isset( $response['rows'] ) && is_array( $response['rows'] ) ) {
				$summary['row_count'] = count( $response['rows'] );
			}

			if ( isset( $response['clientID'] ) ) {
				$summary['client_id'] = $this->trace->mask_identifier( (string) $response['clientID'] );
			}

			return $summary;
		}

		/**
		 * Identify sensitive payload keys.
		 *
		 * @param string $key Payload key name.
		 *
		 * @return bool
		 */
		protected function is_sensitive_key( $key ) {
			$key = strtolower( (string) $key );

			$sensitive = array( 'password', 'authorization', 'auth', 'token' );

			return in_array( $key, $sensitive, true );
		}
	}
}
