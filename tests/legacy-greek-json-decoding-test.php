<?php
/**
 * Verify that legacy-encoded SoftOne JSON errors are decoded as readable UTF-8.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require_once dirname( __DIR__ ) . '/includes/class-softone-api-client.php';

class Softone_API_Client_Legacy_Greek_Response_Test extends Softone_API_Client {
	public function decode_legacy_response( $raw_body ) {
		return $this->decode_json_response( $raw_body );
	}
}

$expected_message = 'Ο κωδικός πελάτη υπάρχει ήδη';
$utf8_json        = json_encode( array( 'success' => false, 'error' => $expected_message ), JSON_UNESCAPED_UNICODE );
$legacy_json      = false;

if ( function_exists( 'iconv' ) ) {
	$legacy_json = @iconv( 'UTF-8', 'Windows-1253//IGNORE', $utf8_json );
}

if ( false === $legacy_json && function_exists( 'mb_convert_encoding' ) ) {
	$legacy_json = @mb_convert_encoding( $utf8_json, 'Windows-1253', 'UTF-8' );
}

if ( false === $legacy_json || '' === $legacy_json ) {
	fwrite( STDERR, "Unable to build Windows-1253 test fixture.\n" );
	exit( 1 );
}

$client  = ( new ReflectionClass( Softone_API_Client_Legacy_Greek_Response_Test::class ) )->newInstanceWithoutConstructor();
$decoded = $client->decode_legacy_response( $legacy_json );

if ( ! is_array( $decoded ) ) {
	fwrite( STDERR, "Legacy JSON response did not decode to an array.\n" );
	exit( 1 );
}

if ( $expected_message !== ( $decoded['error'] ?? '' ) ) {
	fwrite( STDERR, "Expected decoded Greek error message was not preserved.\n" );
	exit( 1 );
}

echo "Legacy Greek JSON decoding regression passed.\n";
