<?php
/**
 * Verify that configured handshake fields are forwarded during login.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! isset( $GLOBALS['softone_filters'] ) ) {
    $GLOBALS['softone_filters'] = array();
}

if ( ! function_exists( '__' ) ) {
    function __( $text ) {
        return $text;
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

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value ) {
        $GLOBALS['softone_options'][ $option ] = $value;
        return true;
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

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        global $softone_filters;

        $args = func_get_args();

        if ( empty( $softone_filters[ $tag ] ) ) {
            return $value;
        }

        ksort( $softone_filters[ $tag ] );

        foreach ( $softone_filters[ $tag ] as $priority => $callbacks ) {
            foreach ( $callbacks as $callback ) {
                $params = array( $value );

                $accepted_args = (int) $callback['accepted_args'];

                if ( $accepted_args > 1 ) {
                    $additional = array_slice( $args, 2, $accepted_args - 1 );
                    $params     = array_merge( $params, $additional );
                }

                $value = call_user_func_array( $callback['function'], $params );
            }
        }

        return $value;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
        global $softone_filters;

        if ( ! isset( $softone_filters[ $tag ] ) ) {
            $softone_filters[ $tag ] = array();
        }

        if ( ! isset( $softone_filters[ $tag ][ $priority ] ) ) {
            $softone_filters[ $tag ][ $priority ] = array();
        }

        $softone_filters[ $tag ][ $priority ][] = array(
            'function'      => $function_to_add,
            'accepted_args' => $accepted_args,
        );

        return true;
    }
}

if ( ! function_exists( 'remove_filter' ) ) {
    function remove_filter( $tag, $function_to_remove, $priority = 10 ) {
        global $softone_filters;

        if ( empty( $softone_filters[ $tag ][ $priority ] ) ) {
            return false;
        }

        foreach ( $softone_filters[ $tag ][ $priority ] as $index => $callback ) {
            if ( $callback['function'] === $function_to_remove ) {
                unset( $softone_filters[ $tag ][ $priority ][ $index ] );

                if ( empty( $softone_filters[ $tag ][ $priority ] ) ) {
                    unset( $softone_filters[ $tag ][ $priority ] );
                }

                return true;
            }
        }

        return false;
    }
}

if ( ! class_exists( 'Softone_Item_Sync' ) ) {
    class Softone_Item_Sync {
        public const ADMIN_ACTION    = 'softone_action';
        public const OPTION_LAST_RUN = 'softone_last_run';
    }
}

require_once dirname( __DIR__ ) . '/includes/class-softone-api-client.php';

class Softone_API_Client_Handshake_Test extends Softone_API_Client {
    /**
     * @var array|null
     */
    public $captured_payload = null;

    /**
     * Capture the prepared login payload.
     */
    protected function prepare_request_body( $service, array $data, $client_id = null ) {
        $body = parent::prepare_request_body( $service, $data, $client_id );

        if ( 'login' === $service ) {
            $this->captured_payload = $body;
        }

        return $body;
    }

    /**
     * Simulate a successful SoftOne login response.
     */
    protected function dispatch_request( array $body, $service ) {
        return array( 'clientID' => 'client-xyz' );
    }
}

$handshake_settings = array(
    'endpoint' => 'https://example.test/api',
    'username' => 'handshake-user',
    'password' => 'handshake-pass',
    'company'  => '101',
    'branch'   => '202',
    'module'   => '303',
    'refid'    => '404',
);

$client = new Softone_API_Client_Handshake_Test( $handshake_settings );
$login_response = $client->login();

if ( empty( $login_response['clientID'] ) ) {
    fwrite( STDERR, "Login response did not contain a client ID.\n" );
    exit( 1 );
}

if ( empty( $client->captured_payload ) ) {
    fwrite( STDERR, "Login payload was not captured.\n" );
    exit( 1 );
}

foreach ( array( 'company', 'branch', 'module', 'refid' ) as $field ) {
    if ( ! isset( $client->captured_payload[ $field ] ) ) {
        fwrite( STDERR, sprintf( "Login payload is missing handshake field '%s'.\n", $field ) );
        exit( 1 );
    }

    $expected = $handshake_settings[ $field ];
    $actual   = $client->captured_payload[ $field ];

    if ( (string) $expected !== (string) $actual ) {
        fwrite( STDERR, sprintf( "Handshake field '%s' had unexpected value '%s'.\n", $field, $actual ) );
        exit( 1 );
    }
}

$filter = static function ( $send_handshake ) {
    return false;
};

add_filter( 'softone_wc_integration_send_login_handshake', $filter, 10, 3 );

$client_without_handshake = new Softone_API_Client_Handshake_Test( $handshake_settings );
$client_without_handshake->login();

remove_filter( 'softone_wc_integration_send_login_handshake', $filter, 10 );

foreach ( array( 'company', 'branch', 'module', 'refid' ) as $field ) {
    if ( isset( $client_without_handshake->captured_payload[ $field ] ) ) {
        fwrite( STDERR, sprintf( "Handshake field '%s' was not filtered out.\n", $field ) );
        exit( 1 );
    }
}

echo "Login handshake fields were forwarded and can be disabled via filter.\n";
exit( 0 );
