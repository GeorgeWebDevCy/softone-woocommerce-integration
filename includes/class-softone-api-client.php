<?php
/**
 * SoftOne API client service.
 *
 * @package    Softone_Woocommerce_Integration
 * @subpackage Softone_Woocommerce_Integration\includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Softone_API_Client_Exception' ) ) {
    /**
     * Exception thrown when SoftOne API interactions fail.
     */
    class Softone_API_Client_Exception extends RuntimeException {
    }
}

if ( ! class_exists( 'Softone_API_Client' ) ) {
    /**
     * Wrapper around wp_remote_post() for interacting with the SoftOne API.
     */
    class Softone_API_Client {

        const OPTION_SETTINGS_KEY        = 'softone_woocommerce_integration_settings';
        const OPTION_CLIENT_ID_META_KEY  = 'softone_woocommerce_integration_client_meta';
        const TRANSIENT_CLIENT_ID_KEY    = 'softone_woocommerce_integration_client_id';
        const DEFAULT_TIMEOUT            = 20;
        const DEFAULT_CLIENT_ID_TTL      = 1800; // 30 minutes.

        /**
         * Cached settings.
         *
         * @var array
         */
        protected $settings = array();

        /**
         * API endpoint.
         *
         * @var string
         */
        protected $endpoint = '';

        /**
         * SoftOne username.
         *
         * @var string
         */
        protected $username = '';

        /**
         * SoftOne password.
         *
         * @var string
         */
        protected $password = '';

        /**
         * SoftOne application identifier.
         *
         * @var string
         */
        protected $app_id = '';

        /**
         * Company identifier.
         *
         * @var string
         */
        protected $company = '';

        /**
         * Branch identifier.
         *
         * @var string
         */
        protected $branch = '';

        /**
         * Module identifier.
         *
         * @var string
         */
        protected $module = '';

        /**
         * Reference identifier.
         *
         * @var string
         */
        protected $refid = '';

        /**
         * Request timeout.
         *
         * @var int
         */
        protected $timeout = self::DEFAULT_TIMEOUT;

        /**
         * Cached TTL for the client identifier.
         *
         * @var int
         */
        protected $client_id_ttl = self::DEFAULT_CLIENT_ID_TTL;

        /**
         * Logger instance.
         *
         * @var WC_Logger|Psr\Log\LoggerInterface|null
         */
        protected $logger;

        /**
         * Constructor.
         *
         * @param array                             $settings Optional settings override.
         * @param WC_Logger|Psr\Log\LoggerInterface $logger   Optional logger instance.
         */
        public function __construct( array $settings = array(), $logger = null ) {
            $this->settings = wp_parse_args( $settings, $this->get_settings_from_options() );

            $this->endpoint = isset( $this->settings['endpoint'] ) ? trim( (string) $this->settings['endpoint'] ) : '';
            $this->username = isset( $this->settings['username'] ) ? (string) $this->settings['username'] : '';
            $this->password = isset( $this->settings['password'] ) ? (string) $this->settings['password'] : '';
            $this->app_id   = isset( $this->settings['app_id'] ) ? (string) $this->settings['app_id'] : '';
            $this->company  = isset( $this->settings['company'] ) ? (string) $this->settings['company'] : '';
            $this->branch   = isset( $this->settings['branch'] ) ? (string) $this->settings['branch'] : '';
            $this->module   = isset( $this->settings['module'] ) ? (string) $this->settings['module'] : '';
            $this->refid    = isset( $this->settings['refid'] ) ? (string) $this->settings['refid'] : '';

            $timeout = isset( $this->settings['timeout'] ) ? absint( $this->settings['timeout'] ) : self::DEFAULT_TIMEOUT;
            $this->timeout = $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;

            $configured_ttl      = isset( $this->settings['client_id_ttl'] ) ? absint( $this->settings['client_id_ttl'] ) : 0;
            $this->client_id_ttl = $configured_ttl > 0 ? $configured_ttl : self::DEFAULT_CLIENT_ID_TTL;

            $this->logger = $logger ?: $this->get_default_logger();
        }

        /**
         * Perform the login call.
         *
         * @throws Softone_API_Client_Exception When credentials are missing or the request fails.
         *
         * @return array
         */
        public function login() {
            if ( '' === $this->username || '' === $this->password ) {
                throw new Softone_API_Client_Exception( __( 'SoftOne credentials are missing. Please provide a username and password.', 'softone-woocommerce-integration' ) );
            }

            $payload = array(
                'username' => $this->username,
                'password' => $this->password,
            );

            $response = $this->call_service( 'login', $payload, false );

            if ( empty( $response['clientID'] ) ) {
                $this->log_error( __( 'SoftOne login succeeded but no clientID was returned.', 'softone-woocommerce-integration' ) );
                throw new Softone_API_Client_Exception( __( 'SoftOne login failed to provide a client ID.', 'softone-woocommerce-integration' ) );
            }

            return $response;
        }

        /**
         * Perform the authenticate call.
         *
         * @param string $client_id Client identifier returned by login.
         *
         * @throws Softone_API_Client_Exception When required configuration is missing or the request fails.
         *
         * @return array
         */
        public function authenticate( $client_id ) {
            if ( '' === $client_id ) {
                throw new Softone_API_Client_Exception( __( 'Cannot authenticate without a SoftOne client ID.', 'softone-woocommerce-integration' ) );
            }

            $payload = array(
                'clientID' => $client_id,
                'clientid' => $client_id,
                'company'  => '' === $this->company ? null : $this->company,
                'branch'   => '' === $this->branch ? null : $this->branch,
                'module'   => '' === $this->module ? null : $this->module,
                'refid'    => '' === $this->refid ? null : $this->refid,
            );

            $response = $this->call_service( 'authenticate', $payload, false );

            if ( empty( $response['clientID'] ) ) {
                $this->log_error( __( 'SoftOne authentication did not return a clientID.', 'softone-woocommerce-integration' ) );
                throw new Softone_API_Client_Exception( __( 'SoftOne authentication failed to provide a client ID.', 'softone-woocommerce-integration' ) );
            }

            return $response;
        }

        /**
         * Execute a SqlData request.
         *
         * @param string $sql_name  Stored SQL name configured in SoftOne.
         * @param array  $arguments Optional parameters passed to SoftOne.
         * @param array  $extra     Additional payload values (if required).
         *
         * @throws Softone_API_Client_Exception When the request fails.
         *
         * @return array
         */
        public function sql_data( $sql_name, array $arguments = array(), array $extra = array() ) {
            if ( '' === $sql_name ) {
                throw new Softone_API_Client_Exception( __( 'A SQL name is required for SqlData requests.', 'softone-woocommerce-integration' ) );
            }

            $payload = array_merge(
                array(
                    'SqlName' => $sql_name,
                ),
                $extra
            );

            if ( ! empty( $arguments ) ) {
                $payload['params'] = $arguments;
            }

            return $this->call_service( 'SqlData', $payload );
        }

        /**
         * Execute a setData request.
         *
         * @param string $object    Target SoftOne object (e.g., CUSTOMER, SALDOC).
         * @param array  $data      Payload data structure.
         * @param array  $extra     Additional payload values (if required).
         *
         * @throws Softone_API_Client_Exception When the request fails.
         *
         * @return array
         */
        public function set_data( $object, array $data, array $extra = array() ) {
            if ( '' === $object ) {
                throw new Softone_API_Client_Exception( __( 'A SoftOne object name is required for setData requests.', 'softone-woocommerce-integration' ) );
            }

            $payload = array_merge(
                array(
                    'object' => $object,
                    'data'   => $data,
                ),
                $extra
            );

            return $this->call_service( 'setData', $payload );
        }

        /**
         * Generic SoftOne service call.
         *
         * @param string $service                 Service name.
         * @param array  $data                    Payload data.
         * @param bool   $requires_client_id      Whether a SoftOne client ID is required.
         * @param bool   $retry_on_authentication Whether to retry once when authentication fails.
         *
         * @throws Softone_API_Client_Exception When the request fails.
         *
         * @return array
         */
        public function call_service( $service, array $data = array(), $requires_client_id = true, $retry_on_authentication = true ) {
            if ( '' === $service ) {
                throw new Softone_API_Client_Exception( __( 'A SoftOne service name is required.', 'softone-woocommerce-integration' ) );
            }

            if ( '' === $this->endpoint ) {
                throw new Softone_API_Client_Exception( __( 'SoftOne endpoint is not configured.', 'softone-woocommerce-integration' ) );
            }

            $client_id = null;

            if ( $requires_client_id ) {
                $client_id = $this->get_client_id();

                if ( '' === $client_id ) {
                    throw new Softone_API_Client_Exception( __( 'Unable to determine SoftOne client ID.', 'softone-woocommerce-integration' ) );
                }
            }

            $body     = $this->prepare_request_body( $service, $data, $client_id );
            $response = $this->dispatch_request( $body, $service );

            if ( isset( $response['success'] ) && false === $response['success'] ) {
                if ( $requires_client_id && $retry_on_authentication && $this->is_authentication_error( $response ) ) {
                    $this->log_warning( __( 'SoftOne session appears to have expired. Refreshing credentials.', 'softone-woocommerce-integration' ), array( 'service' => $service ) );
                    $this->clear_cached_client_id();

                    $client_id = $this->get_client_id( true );
                    $body      = $this->prepare_request_body( $service, $data, $client_id );
                    $response  = $this->dispatch_request( $body, $service );
                }
            }

            if ( isset( $response['success'] ) && false === $response['success'] ) {
                $message = $this->extract_error_message( $response );
                $this->log_error( $message, array(
                    'service'  => $service,
                    'response' => $this->redact_sensitive_values( $response ),
                ) );
                throw new Softone_API_Client_Exception( $message );
            }

            if ( $requires_client_id && ! empty( $response['clientID'] ) ) {
                $this->cache_client_id( (string) $response['clientID'] );
            }

            return $response;
        }

        /**
         * Retrieve the cached SoftOne client ID, refreshing when required.
         *
         * @param bool $force_refresh Whether to force a new session.
         *
         * @throws Softone_API_Client_Exception When a new session cannot be established.
         *
         * @return string
         */
        public function get_client_id( $force_refresh = false ) {
            if ( ! $force_refresh ) {
                $client_id = get_transient( self::TRANSIENT_CLIENT_ID_KEY );
                if ( ! empty( $client_id ) ) {
                    return (string) $client_id;
                }

                $meta = $this->get_client_meta();
                if ( ! empty( $meta['client_id'] ) && ! empty( $meta['expires_at'] ) && time() < (int) $meta['expires_at'] ) {
                    $remaining = (int) $meta['expires_at'] - time();

                    if ( $remaining > 0 ) {
                        set_transient( self::TRANSIENT_CLIENT_ID_KEY, $meta['client_id'], $remaining );
                    }

                    return (string) $meta['client_id'];
                }
            }

            return $this->bootstrap_client_session();
        }

        /**
         * Clear any cached SoftOne client IDs.
         */
        public function clear_cached_client_id() {
            delete_transient( self::TRANSIENT_CLIENT_ID_KEY );
            delete_option( self::OPTION_CLIENT_ID_META_KEY );
        }

        /**
         * Create a new SoftOne session and cache the resulting client ID.
         *
         * @throws Softone_API_Client_Exception When session creation fails.
         *
         * @return string
         */
        protected function bootstrap_client_session() {
            $this->log_info( __( 'Requesting a fresh SoftOne session.', 'softone-woocommerce-integration' ) );

            $login_response = $this->login();
            $client_id      = isset( $login_response['clientID'] ) ? (string) $login_response['clientID'] : '';

            if ( '' === $client_id ) {
                throw new Softone_API_Client_Exception( __( 'SoftOne login failed to return a client ID.', 'softone-woocommerce-integration' ) );
            }

            $authenticate_response = $this->authenticate( $client_id );
            $authenticated_id      = isset( $authenticate_response['clientID'] ) ? (string) $authenticate_response['clientID'] : '';

            if ( '' === $authenticated_id ) {
                throw new Softone_API_Client_Exception( __( 'SoftOne authentication did not return a client ID.', 'softone-woocommerce-integration' ) );
            }

            $ttl = $this->determine_client_ttl( $login_response, $authenticate_response );
            $this->cache_client_id( $authenticated_id, $ttl );

            return $authenticated_id;
        }

        /**
         * Determine the cache TTL for the SoftOne client ID.
         *
         * @param array $login_response         Login response payload.
         * @param array $authenticate_response  Authenticate response payload.
         *
         * @return int
         */
        protected function determine_client_ttl( array $login_response, array $authenticate_response ) {
            $ttl = 0;

            if ( isset( $login_response['objs'] ) && is_array( $login_response['objs'] ) ) {
                $object = reset( $login_response['objs'] );
                if ( is_array( $object ) ) {
                    foreach ( array( 'EXPTIME', 'exptime', 'exp_time', 'expires_in' ) as $key ) {
                        if ( isset( $object[ $key ] ) && is_numeric( $object[ $key ] ) ) {
                            $ttl = (int) $object[ $key ] * MINUTE_IN_SECONDS;
                            break;
                        }
                    }
                }
            }

            if ( 0 === $ttl ) {
                foreach ( array( 'expires_in', 'ttl', 'session_ttl' ) as $key ) {
                    if ( isset( $authenticate_response[ $key ] ) && is_numeric( $authenticate_response[ $key ] ) ) {
                        $ttl = (int) $authenticate_response[ $key ];
                        break;
                    }
                }
            }

            if ( $ttl <= 0 ) {
                $ttl = $this->client_id_ttl;
            }

            $ttl = max( MINUTE_IN_SECONDS, (int) apply_filters( 'softone_wc_integration_client_ttl', $ttl, $login_response, $authenticate_response, $this ) );

            $this->client_id_ttl = $ttl;

            return $ttl;
        }

        /**
         * Persist the SoftOne client ID to transients and options.
         *
         * @param string $client_id Client identifier.
         * @param int    $ttl       Cache duration.
         */
        protected function cache_client_id( $client_id, $ttl = 0 ) {
            if ( '' === $client_id ) {
                return;
            }

            $ttl = $ttl > 0 ? (int) $ttl : $this->client_id_ttl;
            $ttl = max( MINUTE_IN_SECONDS, $ttl );
            $this->client_id_ttl = $ttl;

            set_transient( self::TRANSIENT_CLIENT_ID_KEY, $client_id, $ttl );

            $meta = array(
                'client_id' => $client_id,
                'cached_at' => time(),
                'ttl'       => $ttl,
                'expires_at' => time() + $ttl,
            );

            update_option( self::OPTION_CLIENT_ID_META_KEY, $meta, false );
        }

        /**
         * Dispatch a request to SoftOne.
         *
         * @param array  $body    Request payload.
         * @param string $service Service name (for logging).
         *
         * @throws Softone_API_Client_Exception When a network error occurs.
         *
         * @return array
         */
        protected function dispatch_request( array $body, $service ) {
            $endpoint = $this->get_endpoint_url();

            $encoded_body = wp_json_encode( $body );

            if ( false === $encoded_body ) {
                $message = __( 'Unable to encode SoftOne request payload as JSON.', 'softone-woocommerce-integration' );

                $this->log_error( $message, array(
                    'service' => $service,
                ) );

                throw new Softone_API_Client_Exception( $message );
            }

            $args = array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'User-Agent'   => $this->get_user_agent(),
                ),
                'timeout' => $this->timeout,
                'body'    => $encoded_body,
            );

            $args = apply_filters( 'softone_wc_integration_request_args', $args, $service, $body, $this );
            $response = wp_remote_post( $endpoint, $args );

            if ( is_wp_error( $response ) ) {
                $message = sprintf(
                    /* translators: %s: error message */
                    __( 'SoftOne request error: %s', 'softone-woocommerce-integration' ),
                    $response->get_error_message()
                );

                $this->log_error( $message, array(
                    'service' => $service,
                    'body'    => $this->redact_sensitive_values( $body ),
                ) );

                throw new Softone_API_Client_Exception( $message );
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $raw_body    = wp_remote_retrieve_body( $response );

            if ( $status_code < 200 || $status_code >= 300 ) {
                $message = sprintf(
                    /* translators: 1: HTTP status code, 2: response body */
                    __( 'SoftOne responded with HTTP %1$s: %2$s', 'softone-woocommerce-integration' ),
                    $status_code,
                    $raw_body
                );

                $this->log_error( $message, array(
                    'service' => $service,
                ) );

                throw new Softone_API_Client_Exception( $message );
            }

            $decoded = json_decode( $raw_body, true );

            if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
                $message = sprintf(
                    /* translators: %s: raw JSON response */
                    __( 'SoftOne returned invalid JSON: %s', 'softone-woocommerce-integration' ),
                    json_last_error_msg()
                );

                $this->log_error( $message, array(
                    'service'  => $service,
                    'response' => $raw_body,
                ) );

                throw new Softone_API_Client_Exception( $message );
            }

            return is_array( $decoded ) ? $decoded : array();
        }

        /**
         * Retrieve settings from the WordPress options table.
         *
         * @return array
         */
        protected function get_settings_from_options() {
            $stored = get_option( self::OPTION_SETTINGS_KEY, array() );
            $stored = is_array( $stored ) ? $stored : array();

            $defaults = array(
                'endpoint'       => '',
                'username'       => '',
                'password'       => '',
                'app_id'         => '',
                'company'        => '',
                'branch'         => '',
                'module'         => '',
                'refid'          => '',
                'timeout'        => self::DEFAULT_TIMEOUT,
                'client_id_ttl'  => self::DEFAULT_CLIENT_ID_TTL,
            );

            $settings = wp_parse_args( $stored, $defaults );

            /**
             * Filter the SoftOne API client settings prior to use.
             *
             * @param array $settings Settings array.
             */
            return apply_filters( 'softone_wc_integration_settings', $settings );
        }

        /**
         * Retrieve the stored client ID metadata.
         *
         * @return array
         */
        protected function get_client_meta() {
            $meta = get_option( self::OPTION_CLIENT_ID_META_KEY, array() );
            return is_array( $meta ) ? $meta : array();
        }

        /**
         * Prepare the request payload.
         *
         * @param string     $service   Service name.
         * @param array      $data      Request data.
         * @param string|nil $client_id Client ID (optional).
         *
         * @return array
         */
        protected function prepare_request_body( $service, array $data, $client_id = null ) {
            $body = array_merge(
                array( 'service' => $service ),
                $data
            );

            $body['service'] = $service;

            if ( null !== $client_id ) {
                $body['clientID'] = $client_id;
                $body['clientid'] = $client_id;
            }

            if ( $this->app_id && ! isset( $body['appId'] ) ) {
                $body['appId'] = $this->app_id;
            }

            if ( $this->app_id && ! isset( $body['appID'] ) ) {
                $body['appID'] = $this->app_id;
            }

            foreach ( $body as $key => $value ) {
                if ( null === $value ) {
                    unset( $body[ $key ] );
                }
            }

            return $body;
        }

        /**
         * Determine whether a response indicates an authentication issue.
         *
         * @param array $response SoftOne response payload.
         *
         * @return bool
         */
        protected function is_authentication_error( array $response ) {
            $message = strtolower( $this->extract_error_message( $response, false ) );

            if ( '' === $message ) {
                return false;
            }

            $indicators = array( 'clientid', 'client id', 'expired', 'session', 'authenticate', 'authenticat', 'not valid' );

            foreach ( $indicators as $indicator ) {
                if ( false !== strpos( $message, $indicator ) ) {
                    return true;
                }
            }

            if ( isset( $response['errorCode'] ) && in_array( $response['errorCode'], array( '401', '403' ), true ) ) {
                return true;
            }

            return false;
        }

        /**
         * Extract a human-readable error message.
         *
         * @param array $response Response payload.
         * @param bool  $fallback Whether to return a default message when none found.
         *
         * @return string
         */
        protected function extract_error_message( array $response, $fallback = true ) {
            foreach ( array( 'message', 'Message', 'error', 'Error' ) as $key ) {
                if ( isset( $response[ $key ] ) && is_string( $response[ $key ] ) && '' !== $response[ $key ] ) {
                    return $response[ $key ];
                }
            }

            if ( isset( $response['errors'] ) && is_array( $response['errors'] ) ) {
                $messages = array();
                foreach ( $response['errors'] as $error ) {
                    if ( is_string( $error ) ) {
                        $messages[] = $error;
                    } elseif ( is_array( $error ) ) {
                        $messages[] = wp_json_encode( $error );
                    }
                }

                if ( ! empty( $messages ) ) {
                    return implode( '; ', $messages );
                }
            }

            if ( $fallback ) {
                return __( 'SoftOne request failed.', 'softone-woocommerce-integration' );
            }

            return '';
        }

        /**
         * Retrieve the default logger.
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
         * Log an info level message.
         *
         * @param string $message Log message.
         * @param array  $context Log context.
         */
        protected function log_info( $message, array $context = array() ) {
            $this->log( 'info', $message, $context );
        }

        /**
         * Log a warning level message.
         *
         * @param string $message Log message.
         * @param array  $context Log context.
         */
        protected function log_warning( $message, array $context = array() ) {
            $this->log( 'warning', $message, $context );
        }

        /**
         * Log an error level message.
         *
         * @param string $message Log message.
         * @param array  $context Log context.
         */
        protected function log_error( $message, array $context = array() ) {
            $this->log( 'error', $message, $context );
        }

        /**
         * Central logging helper.
         *
         * @param string $level   Log level.
         * @param string $message Log message.
         * @param array  $context Log context.
         */
        protected function log( $level, $message, array $context = array() ) {
            $context = $this->prepare_log_context( $context );

            if ( $this->logger && method_exists( $this->logger, 'log' ) ) {
                $this->logger->log( $level, $message, $context );
                return;
            }

            $line = sprintf( '[softone-api-client][%s] %s %s', strtoupper( $level ), $message, wp_json_encode( $context ) );
            error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        /**
         * Ensure the log context is properly structured and redacted.
         *
         * @param array $context Context data.
         *
         * @return array
         */
        protected function prepare_log_context( array $context ) {
            $context = array_merge(
                array(
                    'source' => 'softone-api-client',
                ),
                $context
            );

            return $this->redact_sensitive_values( $context );
        }

        /**
         * Redact sensitive values from data structures prior to logging.
         *
         * @param mixed $value Data to redact.
         *
         * @return mixed
         */
        protected function redact_sensitive_values( $value ) {
            if ( is_array( $value ) ) {
                $redacted = array();
                foreach ( $value as $key => $item ) {
                    if ( is_string( $key ) ) {
                        $normalized = strtolower( $key );
                        if ( in_array( $normalized, array( 'password', 'pass', 'clientid', 'client_id', 'clientID', 'username' ), true ) ) {
                            $redacted[ $key ] = '***';
                            continue;
                        }
                    }

                    $redacted[ $key ] = $this->redact_sensitive_values( $item );
                }

                return $redacted;
            }

            return $value;
        }

        /**
         * Build the endpoint URL.
         *
         * @return string
         */
        protected function get_endpoint_url() {
            $endpoint = $this->endpoint;
            if ( '' !== $endpoint && false === strpos( $endpoint, '?' ) ) {
                $endpoint = rtrim( $endpoint, '/' );
            }

            /**
             * Filter the SoftOne endpoint URL.
             *
             * @param string             $endpoint Endpoint URL.
             * @param Softone_API_Client $client   Client instance.
             */
            return apply_filters( 'softone_wc_integration_endpoint', $endpoint, $this );
        }

        /**
         * Retrieve the user agent string used for requests.
         *
         * @return string
         */
        protected function get_user_agent() {
            $version = defined( 'SOFTONE_WOOCOMMERCE_INTEGRATION_VERSION' ) ? SOFTONE_WOOCOMMERCE_INTEGRATION_VERSION : 'dev';
            return sprintf( 'Softone-WooCommerce-Integration/%s', $version );
        }
    }
}
