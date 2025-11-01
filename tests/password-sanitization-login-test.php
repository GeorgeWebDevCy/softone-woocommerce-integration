<?php
/**
 * Ensure password sanitization preserves special characters and allows login.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( '__' ) ) {
    function __( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        return $value;
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) {
        return trim( (string) $url );
    }
}

if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $value ) {
        return rtrim( (string) $value, "/\\" );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        $str = (string) $str;
        $str = strip_tags( $str );
        $str = preg_replace( '/[\r\n\t\0\x0B]+/', '', $str );

        return trim( $str );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        if ( is_array( $value ) ) {
            return array_map( 'wp_unslash', $value );
        }

        if ( is_string( $value ) ) {
            return stripslashes( $value );
        }

        return $value;
    }
}

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_object( $args ) ) {
            $args = get_object_vars( $args );
        }

        if ( ! is_array( $args ) ) {
            $args = array();
        }

        return array_merge( $defaults, $args );
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        return $default;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) {
        return isset( $GLOBALS['softone_transients'][ $key ] ) ? $GLOBALS['softone_transients'][ $key ] : false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $expiration = 0 ) {
        $GLOBALS['softone_transients'][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $key ) {
        unset( $GLOBALS['softone_transients'][ $key ] );
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $key ) {
        unset( $GLOBALS['softone_options'][ $key ] );
        return true;
    }
}

if ( ! class_exists( 'Softone_Item_Sync' ) ) {
    class Softone_Item_Sync {
        public const ADMIN_ACTION   = 'softone_action';
        public const OPTION_LAST_RUN = 'softone_last_run';
    }
}

require_once dirname( __DIR__ ) . '/includes/class-softone-api-client.php';
require_once dirname( __DIR__ ) . '/admin/class-softone-woocommerce-integration-admin.php';

class Softone_API_Client_Login_Test extends Softone_API_Client {
    /**
     * @var array|null
     */
    public $captured_payload = null;

    /**
     * Capture the login payload prepared by the client.
     *
     * @param string     $service   Service name.
     * @param array      $data      Payload data.
     * @param string|nil $client_id Client ID (optional).
     *
     * @return array
     */
    protected function prepare_request_body( $service, array $data, $client_id = null ) {
        $body = parent::prepare_request_body( $service, $data, $client_id );

        if ( 'login' === $service ) {
            $this->captured_payload = $body;
        }

        return $body;
    }

    /**
     * Simulate a successful SoftOne response.
     *
     * @param array  $body    Request payload.
     * @param string $service Service name.
     *
     * @return array
     */
    protected function dispatch_request( array $body, $service ) {
        return array( 'clientID' => 'client-123' );
    }
}

$admin = new Softone_Woocommerce_Integration_Admin( 'softone', '1.0.0', new Softone_Item_Sync() );

$password = 'pa%ss!word+2024';

$sanitized = $admin->sanitize_settings(
    array(
        'endpoint' => 'https://example.test/api',
        'username' => 'example-user',
        'password' => $password,
        'app_id'   => '0',
    )
);

if ( ! isset( $sanitized['password'] ) || $sanitized['password'] !== $password ) {
    fwrite( STDERR, "Password sanitization failed to preserve special characters.\n" );
    exit( 1 );
}

$client = new Softone_API_Client_Login_Test( $sanitized );
$response = $client->login();

if ( empty( $response['clientID'] ) ) {
    fwrite( STDERR, "Login response did not contain a client ID.\n" );
    exit( 1 );
}

if ( empty( $client->captured_payload ) || $client->captured_payload['password'] !== $password ) {
    fwrite( STDERR, "Login payload did not include the expected password value.\n" );
    exit( 1 );
}

if ( ! isset( $client->captured_payload['appId'] ) ) {
    fwrite( STDERR, "Login payload did not include the configured appId value.\n" );
    exit( 1 );
}

$password_with_spaces = "  spaced password  ";

$sanitized_with_spaces = $admin->sanitize_settings(
    array(
        'endpoint' => 'https://example.test/api',
        'username' => 'example-user',
        'password' => $password_with_spaces,
        'app_id'   => '0',
    )
);

if ( ! isset( $sanitized_with_spaces['password'] ) || $sanitized_with_spaces['password'] !== $password_with_spaces ) {
    fwrite( STDERR, "Password sanitization trimmed leading or trailing spaces.\n" );
    exit( 1 );
}

$client_with_spaces = new Softone_API_Client_Login_Test( $sanitized_with_spaces );
$response_with_spaces = $client_with_spaces->login();

if ( empty( $response_with_spaces['clientID'] ) ) {
    fwrite( STDERR, "Login response for spaced password did not contain a client ID.\n" );
    exit( 1 );
}

if ( empty( $client_with_spaces->captured_payload ) || $client_with_spaces->captured_payload['password'] !== $password_with_spaces ) {
    fwrite( STDERR, "Login payload did not preserve leading/trailing spaces in the password.\n" );
    exit( 1 );
}

if ( '0' !== $client->captured_payload['appId'] ) {
    fwrite( STDERR, "Login payload did not preserve an appId configured as '0'.\n" );
    exit( 1 );
}

$padded_settings = $admin->sanitize_settings(
    array(
        'endpoint' => 'https://example.test/api',
        'username' => 'example-user',
        'password' => $password,
        'app_id'   => '0010',
    )
);

$padded_client = new Softone_API_Client_Login_Test( $padded_settings );
$padded_client->login();

if ( empty( $padded_client->captured_payload ) || ! isset( $padded_client->captured_payload['appId'] ) ) {
    fwrite( STDERR, "Login payload with padded appId did not include the expected value.\n" );
    exit( 1 );
}

if ( '0010' !== $padded_client->captured_payload['appId'] ) {
    fwrite( STDERR, "Login payload did not preserve leading zeros in appId.\n" );
    exit( 1 );
}

echo "Password sanitization retained special characters and login succeeded.\n";
exit( 0 );
