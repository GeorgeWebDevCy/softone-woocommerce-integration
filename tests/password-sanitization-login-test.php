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
     * Capture the login payload and simulate a successful response.
     *
     * @param string $service Service name.
     * @param array  $data    Payload data.
     *
     * @return array
     */
    public function call_service( $service, array $data = array(), $requires_client_id = true, $retry_on_authentication = true ) {
        if ( 'login' === $service ) {
            $this->captured_payload = $data;

            return array( 'clientID' => 'client-123' );
        }

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

echo "Password sanitization retained special characters and login succeeded.\n";
exit( 0 );
