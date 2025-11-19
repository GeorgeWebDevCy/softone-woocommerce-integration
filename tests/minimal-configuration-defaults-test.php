<?php
/**
 * Confirm that PDF identifier defaults populate payloads when only credentials are configured.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( '__' ) ) {
    function __( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        return $value;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
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
        return isset( $GLOBALS['softone_options'][ $option ] ) ? $GLOBALS['softone_options'][ $option ] : $default;
    }
}

if ( ! function_exists( 'wc_get_logger' ) ) {
    function wc_get_logger() {
        return null;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        return json_encode( $data, $options, $depth );
    }
}

require_once dirname( __DIR__ ) . '/includes/class-softone-api-client.php';

if ( ! isset( $GLOBALS['softone_options'] ) ) {
    $GLOBALS['softone_options'] = array();
}

$GLOBALS['softone_options'][ Softone_API_Client::OPTION_SETTINGS_KEY ] = array(
    'username' => 'demo-user',
    'password' => 'demo-pass',
);

$client = new Softone_API_Client();

$customer_defaults = array(
    'AREAS'       => $client->get_areas(),
    'SOCURRENCY'  => $client->get_socurrency(),
    'TRDCATEGORY' => $client->get_trdcategory(),
);

$document_defaults = array(
    'SERIES'  => $client->get_default_saldoc_series(),
    'WHOUSE'  => $client->get_warehouse(),
);

$expected_customer = array(
    'AREAS'       => Softone_API_Client::PDF_DEFAULT_AREAS,
    'SOCURRENCY'  => Softone_API_Client::PDF_DEFAULT_SOCURRENCY,
    'TRDCATEGORY' => Softone_API_Client::PDF_DEFAULT_TRDCATEGORY,
);

$expected_document = array(
    'SERIES' => Softone_API_Client::PDF_DEFAULT_SALDOC_SERIES,
    'WHOUSE' => Softone_API_Client::PDF_DEFAULT_WAREHOUSE,
);

foreach ( $expected_customer as $field => $expected ) {
    $actual = isset( $customer_defaults[ $field ] ) ? (string) $customer_defaults[ $field ] : '';

    if ( $actual !== $expected ) {
        fwrite( STDERR, sprintf( "Customer default %s mismatch. Expected %s, received %s\n", $field, $expected, $actual ) );
        exit( 1 );
    }
}

foreach ( $expected_document as $field => $expected ) {
    $actual = isset( $document_defaults[ $field ] ) ? (string) $document_defaults[ $field ] : '';

    if ( $actual !== $expected ) {
        fwrite( STDERR, sprintf( "Document default %s mismatch. Expected %s, received %s\n", $field, $expected, $actual ) );
        exit( 1 );
    }
}

echo "Minimal configuration defaults test passed.\n";
