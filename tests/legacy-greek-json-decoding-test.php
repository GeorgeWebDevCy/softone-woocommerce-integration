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
	public function decode_legacy_response( $raw_body, array &$diagnostics = array() ) {
		return $this->decode_json_response( $raw_body, $diagnostics );
	}
}

function softone_legacy_test_client() {
	return ( new ReflectionClass( Softone_API_Client_Legacy_Greek_Response_Test::class ) )->newInstanceWithoutConstructor();
}

function softone_legacy_test_assert( $condition, $message ) {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

$expected_message = json_decode( '"\u039f \u03ba\u03c9\u03b4\u03b9\u03ba\u03cc\u03c2 \u03c0\u03b5\u03bb\u03ac\u03c4\u03b7 \u03c5\u03c0\u03ac\u03c1\u03c7\u03b5\u03b9 \u03ae\u03b4\u03b7 C"' );
$legacy_json_hex  = '7b2273756363657373223a66616c73652c226572726f72223a22cf20eaf9e4e9eafcf220f0e5ebdcf4e720f5f0dcf1f7e5e920dee4e72043227d';
$legacy_json      = hex2bin( $legacy_json_hex );

$client      = softone_legacy_test_client();
$diagnostics = array();
$decoded     = $client->decode_legacy_response( $legacy_json, $diagnostics );

softone_legacy_test_assert( is_array( $decoded ), 'Windows-1253 response did not decode to an array.' );
softone_legacy_test_assert( $expected_message === ( $decoded['error'] ?? '' ), 'Windows-1253 Greek error message was not preserved.' );
softone_legacy_test_assert( 'Windows-1253' === ( $diagnostics['selected_encoding'] ?? '' ), 'Windows-1253 was not selected for the legacy response.' );

$client      = softone_legacy_test_client();
$diagnostics = array(
	'response_charset' => 'ISO-8859-7',
);
$decoded     = $client->decode_legacy_response( $legacy_json, $diagnostics );

softone_legacy_test_assert( is_array( $decoded ), 'ISO-8859-7 response did not decode to an array.' );
softone_legacy_test_assert( $expected_message === ( $decoded['error'] ?? '' ), 'ISO-8859-7 Greek error message was not preserved.' );
softone_legacy_test_assert( 'ISO-8859-7' === ( $diagnostics['selected_encoding'] ?? '' ), 'Response charset was not preferred.' );

$client      = softone_legacy_test_client();
$diagnostics = array();
$utf8_json   = json_encode( array( 'success' => false, 'error' => $expected_message ), JSON_UNESCAPED_UNICODE );
$decoded     = $client->decode_legacy_response( $utf8_json, $diagnostics );

softone_legacy_test_assert( is_array( $decoded ), 'UTF-8 response did not decode to an array.' );
softone_legacy_test_assert( $expected_message === ( $decoded['error'] ?? '' ), 'UTF-8 Greek error message was not preserved.' );
softone_legacy_test_assert( 'UTF-8' === ( $diagnostics['selected_encoding'] ?? '' ), 'UTF-8 response should not be legacy-converted.' );

$client      = softone_legacy_test_client();
$diagnostics = array();
$literal_json = '{"success":false,"error":"? ??????? ?????? ?? ????? ??? ?????? C"}';
$decoded     = $client->decode_legacy_response( $literal_json, $diagnostics );

softone_legacy_test_assert( is_array( $decoded ), 'Literal question-mark response did not decode to an array.' );
softone_legacy_test_assert( '? ??????? ?????? ?? ????? ??? ?????? C' === ( $decoded['error'] ?? '' ), 'Literal question marks should remain unchanged.' );
softone_legacy_test_assert( ! empty( $diagnostics['source_returned_literal_question_marks'] ), 'Literal question-mark diagnostics were not recorded.' );
softone_legacy_test_assert( ! empty( $diagnostics['raw_body_sample_hex'] ), 'Raw response hex sample was not recorded.' );
softone_legacy_test_assert( ! empty( $diagnostics['raw_body_sample_base64'] ), 'Raw response base64 sample was not recorded.' );

echo "Legacy Greek JSON decoding regression passed.\n";
