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

        /**
         * Additional context captured when the exception was thrown.
         *
         * @var array
         */
        protected $context = array();

        /**
         * Constructor.
         *
         * @param string     $message Exception message.
         * @param int        $code    Exception code.
         * @param Throwable  $previous Previous exception.
         * @param array      $context Additional context values.
         */
        public function __construct( $message = '', $code = 0, $previous = null, array $context = array() ) {
            if ( null !== $previous && ! $previous instanceof \Throwable ) {
                $previous = null;
            }

            parent::__construct( $message, (int) $code, $previous );

            $this->context = $context;
        }

        /**
         * Retrieve the captured context.
         *
         * @return array
         */
        public function get_context() {
            return is_array( $this->context ) ? $this->context : array();
        }
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
         * Legacy SoftOne connection defaults used by the PT Kids environment.
         */
        const LEGACY_DEFAULT_ENDPOINT = 'https://ptkids.oncloud.gr/s1services';
        const LEGACY_DEFAULT_APP_ID   = '1000';
        const LEGACY_DEFAULT_COMPANY  = '10';
        const LEGACY_DEFAULT_BRANCH   = '101';
        const LEGACY_DEFAULT_MODULE   = '0';
        const LEGACY_DEFAULT_REFID    = '1000';

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
         * Default SALDOC series.
         *
         * @var string
         */
        protected $default_saldoc_series = '';

        /**
         * Default warehouse code.
         *
         * @var string
         */
        protected $warehouse = '';

        /**
         * Default customer area identifier.
         *
         * @var string
         */
        protected $areas = '';

        /**
         * Default customer currency identifier.
         *
         * @var string
         */
        protected $socurrency = '';

        /**
         * Default customer trading category.
         *
         * @var string
         */
        protected $trdcategory = '';

        /**
         * Handshake values returned by the most recent login response.
         *
         * @var array<string, string>
         */
        protected $login_handshake = array();

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
            $this->username = isset( $this->settings['username'] ) ? trim( (string) $this->settings['username'] ) : '';
            $this->password = isset( $this->settings['password'] ) ? (string) $this->settings['password'] : '';
            $this->app_id   = isset( $this->settings['app_id'] ) ? trim( (string) $this->settings['app_id'] ) : '';
            $this->company  = isset( $this->settings['company'] ) ? trim( (string) $this->settings['company'] ) : '';
            $this->branch   = isset( $this->settings['branch'] ) ? trim( (string) $this->settings['branch'] ) : '';
            $this->module   = isset( $this->settings['module'] ) ? trim( (string) $this->settings['module'] ) : '';
            $this->refid    = isset( $this->settings['refid'] ) ? trim( (string) $this->settings['refid'] ) : '';
            $this->default_saldoc_series = isset( $this->settings['default_saldoc_series'] ) ? trim( (string) $this->settings['default_saldoc_series'] ) : '';
            $this->warehouse             = isset( $this->settings['warehouse'] ) ? trim( (string) $this->settings['warehouse'] ) : '';
            $this->areas                 = isset( $this->settings['areas'] ) ? trim( (string) $this->settings['areas'] ) : '';
            $this->socurrency            = isset( $this->settings['socurrency'] ) ? trim( (string) $this->settings['socurrency'] ) : '';
            $this->trdcategory           = isset( $this->settings['trdcategory'] ) ? trim( (string) $this->settings['trdcategory'] ) : '';

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
                throw new Softone_API_Client_Exception( __( '[SO-API-001] SoftOne credentials are missing. Please provide a username and password.', 'softone-woocommerce-integration' ) );
            }

            $payload = array(
                'username' => $this->username,
                'password' => $this->password,
            );

            /**
             * Filter the login payload before dispatching the request.
             *
             * Historically the plugin forwarded the handshake fields (company, branch,
             * module, refid) during the login request. SoftOne rejects those extra
             * parameters for the PT Kids environment, so the default behaviour is to
             * omit them. Sites that rely on the old behaviour can re-introduce the
             * fields via this filter.
             *
             * @param array                   $payload Login payload.
             * @param Softone_API_Client|null $client  API client instance.
             */
            $payload = apply_filters( 'softone_wc_integration_login_payload', $payload, $this );

            $response = $this->call_service( 'login', $payload, false );

            if ( empty( $response['clientID'] ) ) {
                $this->log_error( __( '[SO-API-002] SoftOne login succeeded but no clientID was returned.', 'softone-woocommerce-integration' ) );
                throw new Softone_API_Client_Exception( __( '[SO-API-003] SoftOne login failed to provide a client ID.', 'softone-woocommerce-integration' ) );
            }

            $this->login_handshake = $this->extract_handshake_from_login_response( $response );

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
                throw new Softone_API_Client_Exception( __( '[SO-API-004] Cannot authenticate without a SoftOne client ID.', 'softone-woocommerce-integration' ) );
            }

            $payload = array(
                'clientID' => $client_id,
                'clientid' => $client_id,
            );

            foreach ( $this->get_handshake_fields() as $key => $value ) {
                if ( '' !== $value ) {
                    $payload[ $key ] = $value;
                }
            }

            $response = $this->call_service( 'authenticate', $payload, false );

            if ( empty( $response['clientID'] ) ) {
                $this->log_error( __( '[SO-API-005] SoftOne authentication did not return a clientID.', 'softone-woocommerce-integration' ) );
                throw new Softone_API_Client_Exception( __( '[SO-API-006] SoftOne authentication failed to provide a client ID.', 'softone-woocommerce-integration' ) );
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
                throw new Softone_API_Client_Exception( __( '[SO-API-007] A SQL name is required for SqlData requests.', 'softone-woocommerce-integration' ) );
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
                throw new Softone_API_Client_Exception( __( '[SO-API-008] A SoftOne object name is required for setData requests.', 'softone-woocommerce-integration' ) );
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
                throw new Softone_API_Client_Exception( __( '[SO-API-009] A SoftOne service name is required.', 'softone-woocommerce-integration' ) );
            }

            if ( '' === $this->endpoint ) {
                throw new Softone_API_Client_Exception( __( '[SO-API-010] SoftOne endpoint is not configured.', 'softone-woocommerce-integration' ) );
            }

            $client_id = null;

            if ( $requires_client_id ) {
                $client_id = $this->get_client_id( true );

                if ( '' === $client_id ) {
                    throw new Softone_API_Client_Exception( __( '[SO-API-011] Unable to determine SoftOne client ID.', 'softone-woocommerce-integration' ) );
                }
            }

            $body     = $this->prepare_request_body( $service, $data, $client_id );
            $response = $this->dispatch_request( $body, $service );

            if ( isset( $response['success'] ) && false === $response['success'] ) {
                if ( $requires_client_id && $retry_on_authentication && $this->is_authentication_error( $response ) ) {
                    $this->log_warning( __( '[SO-API-012] SoftOne session appears to have expired. Refreshing credentials.', 'softone-woocommerce-integration' ), array( 'service' => $service ) );
                    $this->clear_cached_client_id();

                    $client_id = $this->get_client_id( true );
                    $body      = $this->prepare_request_body( $service, $data, $client_id );
                    $response  = $this->dispatch_request( $body, $service );
                }
            }

            if ( isset( $response['success'] ) && false === $response['success'] ) {
                $message = $this->extract_error_message( $response );
                $context = array(
                    'service'  => $service,
                    'request'  => $this->redact_sensitive_values( $body ),
                    'response' => $this->redact_sensitive_values( $response ),
                );
                $this->log_error( $message, $context );
                throw new Softone_API_Client_Exception( $message, 0, null, $context );
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
            $this->log_info( __( '[SO-API-013] Requesting a fresh SoftOne session.', 'softone-woocommerce-integration' ) );

            $login_response = $this->login();
            $client_id      = isset( $login_response['clientID'] ) ? (string) $login_response['clientID'] : '';

            if ( '' === $client_id ) {
                throw new Softone_API_Client_Exception( __( '[SO-API-014] SoftOne login failed to return a client ID.', 'softone-woocommerce-integration' ) );
            }

            $authenticate_response = $this->authenticate( $client_id );
            $authenticated_id      = isset( $authenticate_response['clientID'] ) ? (string) $authenticate_response['clientID'] : '';

            if ( '' === $authenticated_id ) {
                throw new Softone_API_Client_Exception( __( '[SO-API-015] SoftOne authentication did not return a client ID.', 'softone-woocommerce-integration' ) );
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

            $cached_at = time();
            $meta = array(
                'client_id' => $client_id,
                'cached_at' => $cached_at,
                'ttl'       => $ttl,
                'expires_at' => $cached_at + $ttl,
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
                $message = __( '[SO-API-016] Unable to encode SoftOne request payload as JSON.', 'softone-woocommerce-integration' );

                $context = array(
                    'service'  => $service,
                    'endpoint' => $endpoint,
                    'request'  => $this->redact_sensitive_values( $body ),
                );

                $this->log_error( $message, $context );

                throw new Softone_API_Client_Exception( $message, 0, null, $context );
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
                    __( '[SO-API-017] SoftOne request error: %s', 'softone-woocommerce-integration' ),
                    $response->get_error_message()
                );

                $context = array(
                    'service'  => $service,
                    'endpoint' => $endpoint,
                    'request'  => $this->redact_sensitive_values( $body ),
                    'error'    => $response->get_error_messages(),
                );

                $this->log_error( $message, $context );

                throw new Softone_API_Client_Exception( $message, 0, null, $context );
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $raw_body    = wp_remote_retrieve_body( $response );

            if ( $status_code < 200 || $status_code >= 300 ) {
                $message = sprintf(
                    /* translators: 1: HTTP status code, 2: response body */
                    __( '[SO-API-018] SoftOne responded with HTTP %1$s: %2$s', 'softone-woocommerce-integration' ),
                    $status_code,
                    $raw_body
                );

                $context = array(
                    'service'      => $service,
                    'endpoint'     => $endpoint,
                    'request'      => $this->redact_sensitive_values( $body ),
                    'status_code'  => $status_code,
                    'response'     => $raw_body,
                );

                $this->log_error( $message, $context );

                throw new Softone_API_Client_Exception( $message, 0, null, $context );
            }

            $decoded = json_decode( $raw_body, true );

            if ( null === $decoded && JSON_ERROR_UTF8 === json_last_error() ) {
                $normalized_body = $this->normalize_json_encoding( $raw_body );

                if ( $normalized_body !== $raw_body ) {
                    $raw_body = $normalized_body;
                    $decoded  = json_decode( $raw_body, true );
                }

                if ( null === $decoded && defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
                    $decoded = json_decode( $raw_body, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
                }
            }

            if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
                $message = sprintf(
                    /* translators: %s: raw JSON response */
                    __( '[SO-API-019] SoftOne returned invalid JSON: %s', 'softone-woocommerce-integration' ),
                    json_last_error_msg()
                );

                $context = array(
                    'service'  => $service,
                    'endpoint' => $endpoint,
                    'request'  => $this->redact_sensitive_values( $body ),
                    'response' => $raw_body,
                );

                $this->log_error( $message, $context );

                throw new Softone_API_Client_Exception( $message, 0, null, $context );
            }

            return is_array( $decoded ) ? $decoded : array();
        }

        /**
         * Ensure the provided JSON payload is valid UTF-8.
         *
         * @param string $payload Response payload returned by SoftOne.
         *
         * @return string
         */
        protected function normalize_json_encoding( $payload ) {
            if ( ! is_string( $payload ) || '' === $payload ) {
                return $payload;
            }

            if ( function_exists( 'wp_check_invalid_utf8' ) ) {
                $checked = wp_check_invalid_utf8( $payload, true );
                if ( is_string( $checked ) ) {
                    $payload = $checked;
                }
            }

            $encoding            = false;
            $candidate_encodings = array( 'UTF-8', 'ISO-8859-1', 'ISO-8859-7', 'ISO-8859-15', 'Windows-1253', 'Windows-1252', 'ASCII' );

            if ( function_exists( 'mb_list_encodings' ) ) {
                $available_encodings = array_map( 'strtoupper', mb_list_encodings() );
                $candidate_encodings = array_values( array_filter( $candidate_encodings, function( $candidate ) use ( $available_encodings ) {
                    return in_array( strtoupper( $candidate ), $available_encodings, true );
                } ) );
            }

            if ( function_exists( 'mb_detect_encoding' ) && function_exists( 'mb_convert_encoding' ) ) {
                try {
                    $encoding = mb_detect_encoding( $payload, $candidate_encodings, true );
                } catch ( ValueError $exception ) {
                    $encoding = false;
                }

                if ( $encoding && 'UTF-8' !== strtoupper( $encoding ) ) {
                    $converted = @mb_convert_encoding( $payload, 'UTF-8', $encoding );

                    if ( false !== $converted ) {
                        return $converted;
                    }
                }
            }

            if ( function_exists( 'iconv' ) ) {
                $from_encoding = ( $encoding && 'UTF-8' !== strtoupper( $encoding ) ) ? $encoding : 'UTF-8';
                $converted     = @iconv( $from_encoding, 'UTF-8//IGNORE', $payload );

                if ( false !== $converted ) {
                    return $converted;
                }
            }

            return $payload;
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
                'endpoint'              => self::LEGACY_DEFAULT_ENDPOINT,
                'username'              => '',
                'password'              => '',
                'app_id'                => self::LEGACY_DEFAULT_APP_ID,
                'company'               => self::LEGACY_DEFAULT_COMPANY,
                'branch'                => self::LEGACY_DEFAULT_BRANCH,
                'module'                => self::LEGACY_DEFAULT_MODULE,
                'refid'                 => self::LEGACY_DEFAULT_REFID,
                'default_saldoc_series' => '',
                'warehouse'             => '',
                'areas'                 => '',
                'socurrency'            => '',
                'trdcategory'           => '',
                'timeout'               => self::DEFAULT_TIMEOUT,
                'client_id_ttl'         => self::DEFAULT_CLIENT_ID_TTL,
            );

            $settings = wp_parse_args( $stored, $defaults );

            foreach ( $this->get_legacy_default_fields() as $key => $value ) {
                if ( '' === $this->sanitize_default_fallback_value( $settings, $key ) ) {
                    $settings[ $key ] = $value;
                }
            }

            /**
             * Filter the SoftOne API client settings prior to use.
             *
             * @param array $settings Settings array.
             */
            return apply_filters( 'softone_wc_integration_settings', $settings );
        }

        /**
         * Retrieve the legacy default SoftOne connection values.
         *
         * @return array<string,string>
         */
        protected function get_legacy_default_fields() {
            return array(
                'endpoint' => self::LEGACY_DEFAULT_ENDPOINT,
                'app_id'   => self::LEGACY_DEFAULT_APP_ID,
                'company'  => self::LEGACY_DEFAULT_COMPANY,
                'branch'   => self::LEGACY_DEFAULT_BRANCH,
                'module'   => self::LEGACY_DEFAULT_MODULE,
                'refid'    => self::LEGACY_DEFAULT_REFID,
            );
        }

        /**
         * Helper method to normalise stored settings before applying fallbacks.
         *
         * @param array  $settings Settings array.
         * @param string $key      Setting key being inspected.
         *
         * @return string
         */
        protected function sanitize_default_fallback_value( array $settings, $key ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                return '';
            }

            $value = $settings[ $key ];

            if ( is_string( $value ) ) {
                return trim( $value );
            }

            if ( null === $value ) {
                return '';
            }

            return trim( (string) $value );
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
         * Retrieve the configured default SALDOC series.
         *
         * @return string
         */
        public function get_default_saldoc_series() {
            return $this->default_saldoc_series;
        }

        /**
         * Retrieve the configured default warehouse code.
         *
         * @return string
         */
        public function get_warehouse() {
            return $this->warehouse;
        }

        /**
         * Retrieve the configured default customer area identifier.
         *
         * @return string
         */
        public function get_areas() {
            return $this->areas;
        }

        /**
         * Retrieve the configured default customer currency identifier.
         *
         * @return string
         */
        public function get_socurrency() {
            return $this->socurrency;
        }

        /**
         * Retrieve the configured default customer trading category.
         *
         * @return string
         */
        public function get_trdcategory() {
            return $this->trdcategory;
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

            if ( null !== $this->app_id && '' !== $this->app_id && ! isset( $body['appId'] ) ) {
                $body['appId'] = $this->normalize_app_id( $this->app_id );
            }

            foreach ( $body as $key => $value ) {
                if ( null === $value ) {
                    unset( $body[ $key ] );
                }
            }

            return $body;
        }

        /**
         * Retrieve the SoftOne handshake fields configured in the settings.
         *
         * @return array
         */
        protected function get_handshake_fields() {
            $fields = array(
                'company' => $this->company,
                'branch'  => $this->branch,
                'module'  => $this->module,
                'refid'   => $this->refid,
            );

            foreach ( $fields as $key => $value ) {
                if ( is_string( $value ) ) {
                    $fields[ $key ] = trim( $value );
                } elseif ( null === $value ) {
                    $fields[ $key ] = '';
                } else {
                    $fields[ $key ] = trim( (string) $value );
                }
            }

            if ( ! empty( $this->login_handshake ) ) {
                foreach ( $this->login_handshake as $key => $value ) {
                    if ( ! array_key_exists( $key, $fields ) ) {
                        continue;
                    }

                    if ( '' === $value ) {
                        continue;
                    }

                    $current = $fields[ $key ];

                    if ( is_string( $current ) ) {
                        $current = trim( $current );
                    } elseif ( null === $current ) {
                        $current = '';
                    } else {
                        $current = trim( (string) $current );
                    }

                    if ( '' === $current || (string) $current !== (string) $value ) {
                        $fields[ $key ] = $value;
                    }
                }
            }

            return $fields;
        }

        /**
         * Normalise the configured app ID for SoftOne requests.
         *
         * @param string $app_id Raw app identifier from settings.
         *
         * @return string
         */
        protected function normalize_app_id( $app_id ) {
            if ( is_string( $app_id ) ) {
                return trim( $app_id );
            }

            return trim( (string) $app_id );
        }

        /**
         * Derive handshake values from a login response payload.
         *
         * @param array $login_response Login response payload.
         *
         * @return array<string,string>
         */
        protected function extract_handshake_from_login_response( array $login_response ) {
            $handshake = array(
                'company' => '',
                'branch'  => '',
                'module'  => '',
                'refid'   => '',
            );

            if ( empty( $login_response['objs'] ) || ! is_array( $login_response['objs'] ) ) {
                return $handshake;
            }

            $object = null;

            foreach ( $login_response['objs'] as $entry ) {
                if ( is_array( $entry ) ) {
                    $object = $entry;
                    break;
                }
            }

            if ( null === $object ) {
                return $handshake;
            }

            $mapping = array(
                'COMPANY' => 'company',
                'company' => 'company',
                'BRANCH'  => 'branch',
                'branch'  => 'branch',
                'MODULE'  => 'module',
                'module'  => 'module',
                'REFID'   => 'refid',
                'refid'   => 'refid',
            );

            foreach ( $mapping as $source => $target ) {
                if ( array_key_exists( $source, $object ) ) {
                    $value = $object[ $source ];

                    if ( is_string( $value ) ) {
                        $value = trim( $value );
                    } elseif ( is_numeric( $value ) ) {
                        $value = (string) $value;
                    } elseif ( null === $value ) {
                        $value = '';
                    } elseif ( is_bool( $value ) ) {
                        $value = $value ? '1' : '0';
                    } else {
                        $value = trim( (string) $value );
                    }

                    if ( '' !== $value ) {
                        $handshake[ $target ] = $value;
                    }
                }
            }

            return $handshake;
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
                return __( '[SO-API-020] SoftOne request failed.', 'softone-woocommerce-integration' );
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
