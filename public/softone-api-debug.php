<?php
/**
 * SoftOne API troubleshooting helper.
 *
 * Drop this script in a web-accessible directory (e.g., public_html) and load it in a browser.
 * It renders a form for crafting SoftOne API requests and prints the raw payloads, cURL metadata,
 * and formatted responses to help diagnose integration issues.
 */

ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

date_default_timezone_set( 'UTC' );

/**
 * Retrieve a POST value while preserving empty strings.
 *
 * @param string $key     Request key.
 * @param mixed  $default Default value when the key is not present.
 *
 * @return mixed
 */
function softone_debug_request_value( $key, $default = '' ) {
	if ( isset( $_POST[ $key ] ) ) {
		return is_array( $_POST[ $key ] ) ? $_POST[ $key ] : (string) $_POST[ $key ];
	}

	return $default;
}

/**
 * Escape content for safe HTML output.
 *
 * @param string $value Raw value.
 *
 * @return string
 */
function softone_debug_escape( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

/**
 * Output the HTML checked attribute when a condition is truthy.
 *
 * @param bool $is_checked Whether the checkbox should be checked.
 *
 * @return string
 */
function softone_debug_checked_attr( $is_checked ) {
	return $is_checked ? 'checked' : '';
}

/**
 * Parse JSON input into an array.
 *
 * @param string $raw        JSON string supplied by the user.
 * @param array  $messages   Reference to the message list for recording warnings.
 * @param string $field_name Field label for contextual errors.
 *
 * @return array|null Null when the field is empty.
 */
function softone_debug_parse_json_field( $raw, array &$messages, $field_name ) {
	$raw = trim( (string) $raw );

	if ( '' === $raw ) {
		return null;
	}

	$decoded = json_decode( $raw, true );

	if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
		$messages[] = sprintf( 'The %1$s field contains invalid JSON: %2$s', $field_name, json_last_error_msg() );

		return null;
	}

	return $decoded;
}

/**
 * Remove null values from an array recursively.
 *
 * @param array $data Raw data.
 *
 * @return array
 */
function softone_debug_remove_nulls( array $data ) {
	foreach ( $data as $key => $value ) {
		if ( is_array( $value ) ) {
			$data[ $key ] = softone_debug_remove_nulls( $value );
		} elseif ( null === $value ) {
			unset( $data[ $key ] );
		}
	}

	return $data;
}

/**
 * Build the SoftOne payload based on the submitted form.
 *
 * @param array $inputs   Sanitised request values.
 * @param array $messages Collector for validation messages.
 *
 * @return array
 */
function softone_debug_build_payload( array $inputs, array &$messages ) {
	$service     = isset( $inputs['service'] ) ? $inputs['service'] : 'login';
	$service_key = strtolower( $service );
	$payload     = array();

	switch ( $service_key ) {
		case 'login':
			$payload = array(
				'username' => $inputs['username'],
				'password' => $inputs['password'],
			);

			if ( '' !== $inputs['app_id'] ) {
				$payload['appId'] = $inputs['app_id'];
			}

			break;

		case 'authenticate':
			$payload = array();

			if ( '' === $inputs['client_id'] ) {
				$messages[] = 'Authenticate requests require a client ID.';
			}

			break;

		case 'sqldata':
			$sql_name = $inputs['sql_name'];

			if ( '' === $sql_name ) {
				$messages[] = 'SqlData requests require the SQL name.';
			}

			$payload = array(
				'SqlName' => $sql_name,
			);

			$params = softone_debug_parse_json_field( $inputs['sql_params'], $messages, 'SqlData parameters' );

			if ( null !== $params ) {
				$payload['params'] = $params;
			}

			if ( '' !== $inputs['app_id'] ) {
				$payload['appId'] = $inputs['app_id'];
			}

			break;

		case 'setdata':
			$object_name = $inputs['object_name'];
			$data_block  = softone_debug_parse_json_field( $inputs['object_data'], $messages, 'setData payload' );

			if ( '' === $object_name ) {
				$messages[] = 'setData requests require the SoftOne object name (e.g., CUSTOMER, SALDOC).';
			}

			if ( null === $data_block ) {
				$messages[] = 'setData requests require JSON data describing the entity.';
			}

			$payload = array(
				'object' => $object_name,
				'data'   => null === $data_block ? array() : $data_block,
			);

			break;

		default:
			$custom = softone_debug_parse_json_field( $inputs['custom_payload'], $messages, 'custom payload' );

			if ( null === $custom ) {
				$messages[] = 'Provide a JSON object for the custom payload.';
			}

			$payload = null === $custom ? array() : $custom;
	}

	if ( '' !== $inputs['client_id'] ) {
		if ( 'sqldata' === $service_key ) {
			$payload['clientid'] = $inputs['client_id'];
		} else {
			$payload['clientID'] = $inputs['client_id'];
		}
	}

	$handshake_fields = array( 'company', 'branch', 'module', 'refid' );

	foreach ( $handshake_fields as $field ) {
		if ( '' !== $inputs[ $field ] && ! isset( $payload[ $field ] ) ) {
			$payload[ $field ] = $inputs[ $field ];
		}
	}

	if ( '' !== $inputs['warehouse'] && ! isset( $payload['warehouse'] ) ) {
		$payload['warehouse'] = $inputs['warehouse'];
	}

	if ( '' !== $inputs['areas'] && ! isset( $payload['areas'] ) ) {
		$payload['areas'] = $inputs['areas'];
	}

	if ( '' !== $inputs['socurrency'] && ! isset( $payload['socurrency'] ) ) {
		$payload['socurrency'] = $inputs['socurrency'];
	}

	if ( '' !== $inputs['trdcategory'] && ! isset( $payload['trdcategory'] ) ) {
		$payload['trdcategory'] = $inputs['trdcategory'];
	}

	$extra = softone_debug_parse_json_field( $inputs['extra_payload'], $messages, 'extra payload' );

	if ( null !== $extra ) {
		if ( ! is_array( $extra ) ) {
			$messages[] = 'Extra payload must decode into an object or array.';
		} else {
			$payload = array_merge( $payload, $extra );
		}
	}

	$payload = array_merge( array( 'service' => $service ), $payload );

	return softone_debug_remove_nulls( $payload );
}

/**
 * Parse raw header output from cURL.
 *
 * @param string $raw_headers Header block.
 *
 * @return array
 */
function softone_debug_parse_headers( $raw_headers ) {
	$headers = array();
	$lines   = preg_split( '/\r?\n/', trim( (string) $raw_headers ) );

	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}

		if ( false === strpos( $line, ':' ) ) {
			$headers[] = $line;
			continue;
		}

		list( $key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
		$headers[ $key ]     = $value;
	}

	return $headers;
}

/**
 * Render a value as pretty-printed JSON when possible.
 *
 * @param mixed $value Data to render.
 *
 * @return string
 */
function softone_debug_pretty_json( $value ) {
	if ( is_string( $value ) ) {
		$json = json_decode( $value, true );

		if ( null !== $json && JSON_ERROR_NONE === json_last_error() ) {
			$value = $json;
		}
	}

	if ( is_array( $value ) ) {
		$encoded = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false !== $encoded ) {
			return $encoded;
		}
	}

	return (string) $value;
}

$defaults = array(
	'endpoint'    => 'https://ptkids.oncloud.gr/s1services',
	'username'    => '',
	'password'    => '',
	'app_id'      => '1000',
	'company'     => '10',
	'branch'      => '101',
	'module'      => '0',
	'client_id'   => '',
	'warehouse'   => '',
	'areas'       => '',
	'socurrency'  => '',
	'trdcategory' => '',
	'refid'       => '1000',
);

$inputs = array(
	'endpoint'       => softone_debug_request_value( 'endpoint', $defaults['endpoint'] ),
	'username'       => softone_debug_request_value( 'username', $defaults['username'] ),
	'password'       => softone_debug_request_value( 'password', $defaults['password'] ),
	'app_id'         => softone_debug_request_value( 'app_id', $defaults['app_id'] ),
	'company'        => softone_debug_request_value( 'company', $defaults['company'] ),
	'branch'         => softone_debug_request_value( 'branch', $defaults['branch'] ),
	'module'         => softone_debug_request_value( 'module', $defaults['module'] ),
	'client_id'      => softone_debug_request_value( 'client_id', $defaults['client_id'] ),
	'refid'          => softone_debug_request_value( 'refid', $defaults['refid'] ),
	'warehouse'      => softone_debug_request_value( 'warehouse', $defaults['warehouse'] ),
	'areas'          => softone_debug_request_value( 'areas', $defaults['areas'] ),
	'socurrency'     => softone_debug_request_value( 'socurrency', $defaults['socurrency'] ),
	'trdcategory'    => softone_debug_request_value( 'trdcategory', $defaults['trdcategory'] ),
	'service'        => softone_debug_request_value( 'service', 'login' ),
	'sql_name'       => softone_debug_request_value( 'sql_name', '' ),
	'sql_params'     => softone_debug_request_value( 'sql_params', '' ),
	'object_name'    => softone_debug_request_value( 'object_name', '' ),
	'object_data'    => softone_debug_request_value( 'object_data', '' ),
	'custom_payload' => softone_debug_request_value( 'custom_payload', '' ),
	'extra_payload'  => softone_debug_request_value( 'extra_payload', '' ),
	'timeout'        => (int) softone_debug_request_value( 'timeout', 20 ),
	'skip_ssl'       => isset( $_POST['skip_ssl'] ) && '1' === $_POST['skip_ssl'],
);

$messages = array();
$results  = null;
$executed = 'POST' === $_SERVER['REQUEST_METHOD'];

if ( $executed ) {
	$payload = softone_debug_build_payload( $inputs, $messages );
	$json    = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	if ( false === $json ) {
		$messages[] = 'Unable to encode the payload as JSON. Please review the inputs.';
	} else {
		$endpoint = trim( $inputs['endpoint'] );

		if ( '' === $endpoint ) {
			$messages[] = 'The endpoint URL is required.';
		} else {
			$ch = curl_init( $endpoint );

			if ( false === $ch ) {
				$messages[] = 'Failed to initialise cURL. Verify that the PHP cURL extension is enabled.';
			} else {
                                $headers = array(
                                        'Content-Type: application/json',
                                        'Accept: application/json',
                                        'User-Agent: SoftOne-Debug/1.0 (+https://github.com/)'
                                );

				$timeout = max( 1, (int) $inputs['timeout'] );

				$verbose_stream = fopen( 'php://temp', 'w+' );

				curl_setopt_array(
					$ch,
					array(
						CURLOPT_POST           => true,
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_HTTPHEADER     => $headers,
						CURLOPT_POSTFIELDS     => $json,
						CURLOPT_TIMEOUT        => $timeout,
						CURLOPT_CONNECTTIMEOUT => $timeout,
						CURLOPT_HEADER         => true,
						CURLOPT_VERBOSE        => true,
						CURLOPT_STDERR         => $verbose_stream,
					)
				);

				if ( $inputs['skip_ssl'] ) {
					curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
					curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
				}

				$response      = curl_exec( $ch );
				$curl_error    = curl_error( $ch );
				$error_code    = curl_errno( $ch );
				$info          = curl_getinfo( $ch );
				$header_size   = isset( $info['header_size'] ) ? (int) $info['header_size'] : 0;
				$raw_headers   = $header_size > 0 ? substr( $response, 0, $header_size ) : '';
				$raw_body      = $header_size > 0 ? substr( $response, $header_size ) : $response;
				$decoded_body  = json_decode( $raw_body, true );
				$headers_array = softone_debug_parse_headers( $raw_headers );

				rewind( $verbose_stream );
				$verbose_log = stream_get_contents( $verbose_stream );
				fclose( $verbose_stream );

				curl_close( $ch );

                                $results = array(
                                        'payload'         => $payload,
                                        'payload_json'    => $json,
                                        'request_headers' => $headers,
                                        'endpoint'        => $endpoint,
                                        'response_raw'    => $response,
                                        'http_code'       => isset( $info['http_code'] ) ? (int) $info['http_code'] : null,
                                        'curl_info'       => $info,
                                        'raw_headers'     => $raw_headers,
                                        'headers'         => $headers_array,
                                        'raw_body'        => $raw_body,
                                        'body_decoded'    => ( null !== $decoded_body && JSON_ERROR_NONE === json_last_error() ) ? $decoded_body : null,
                                        'curl_error'      => $curl_error,
                                        'curl_errno'      => $error_code,
                                        'verbose_log'     => $verbose_log,
                                );
			}
		}
	}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<title>SoftOne API Debugger</title>
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			margin: 0;
			padding: 0;
			background: #f5f5f5;
			color: #1d2327;
		}

		header {
			background: #23282d;
			color: #fff;
			padding: 1.5rem 2rem;
		}

		header h1 {
			margin: 0;
			font-size: 1.75rem;
		}

		main {
			padding: 2rem;
		}

		form {
			background: #fff;
			border-radius: 8px;
			box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
			padding: 1.5rem;
			margin-bottom: 2rem;
		}

		fieldset {
			border: 1px solid #ccd0d4;
			border-radius: 6px;
			margin-bottom: 1.5rem;
			padding: 1rem;
		}

		legend {
			font-weight: 600;
			padding: 0 0.5rem;
		}

		label {
			display: block;
			font-weight: 600;
			margin-bottom: 0.35rem;
		}

		input[type="text"],
		input[type="password"],
		input[type="number"],
		select,
		textarea {
			width: 100%;
			box-sizing: border-box;
			padding: 0.5rem 0.75rem;
			border-radius: 4px;
			border: 1px solid #ccd0d4;
			font-size: 0.95rem;
			font-family: inherit;
			margin-bottom: 0.75rem;
		}

		textarea {
			min-height: 120px;
			resize: vertical;
		}

		.button-primary {
			background: #007cba;
			border: none;
			color: #fff;
			padding: 0.75rem 1.5rem;
			font-size: 1rem;
			border-radius: 4px;
			cursor: pointer;
		}

		.button-primary:hover {
			background: #006ba1;
		}

		.notice {
			background: #fff8e5;
			border-left: 4px solid #ffb900;
			padding: 1rem;
			margin-bottom: 1.5rem;
		}

		.notice.error {
			background: #fbeaea;
			border-left-color: #d63638;
		}

		pre {
			background: #1e1e1e;
			color: #f5f5f5;
			padding: 1rem;
			overflow-x: auto;
			border-radius: 6px;
			font-size: 0.9rem;
		}

		details {
			margin-bottom: 1.5rem;
		}

		details summary {
			cursor: pointer;
			font-weight: 600;
			margin-bottom: 0.75rem;
		}

		.output-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
			gap: 1.5rem;
		}

		.output-card {
			background: #fff;
			border-radius: 8px;
			box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
			padding: 1.25rem;
		}

		.output-card h2 {
			margin-top: 0;
		}
	</style>
</head>
<body>
	<header>
		<h1>SoftOne API Debugger</h1>
		<p>Inspect requests and responses exchanged with your SoftOne endpoint. Sensitive credentials are not stored.</p>
	</header>
	<main>
		<?php if ( ! empty( $messages ) ) : ?>
		<div class="notice error">
			<ul>
				<?php foreach ( $messages as $message ) : ?>
				<li><?php echo softone_debug_escape( $message ); ?></li>
				<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post">
			<fieldset>
				<legend>Connection</legend>
				<label for="endpoint">Endpoint URL</label>
				<input type="text" id="endpoint" name="endpoint" value="<?php echo softone_debug_escape( $inputs['endpoint'] ); ?>" placeholder="https://example.com/s1services" required />

				<label for="timeout">Timeout (seconds)</label>
				<input type="number" id="timeout" name="timeout" min="1" step="1" value="<?php echo softone_debug_escape( $inputs['timeout'] ); ?>" />

				<label>
					<input type="checkbox" name="skip_ssl" value="1" <?php echo softone_debug_checked_attr( $inputs['skip_ssl'] ); ?> />
					Skip SSL verification (only for debugging!)
				</label>
			</fieldset>

			<fieldset>
				<legend>Credentials &amp; Defaults</legend>
				<label for="username">Username</label>
				<input type="text" id="username" name="username" value="<?php echo softone_debug_escape( $inputs['username'] ); ?>" />

				<label for="password">Password</label>
				<input type="password" id="password" name="password" value="<?php echo softone_debug_escape( $inputs['password'] ); ?>" />

				<label for="app_id">App ID</label>
				<input type="text" id="app_id" name="app_id" value="<?php echo softone_debug_escape( $inputs['app_id'] ); ?>" />

				<div class="output-grid">
					<div>
						<label for="company">Company</label>
						<input type="text" id="company" name="company" value="<?php echo softone_debug_escape( $inputs['company'] ); ?>" />
					</div>
					<div>
						<label for="branch">Branch</label>
						<input type="text" id="branch" name="branch" value="<?php echo softone_debug_escape( $inputs['branch'] ); ?>" />
					</div>
					<div>
						<label for="module">Module</label>
						<input type="text" id="module" name="module" value="<?php echo softone_debug_escape( $inputs['module'] ); ?>" />
					</div>
					<div>
						<label for="refid">RefID</label>
						<input type="text" id="refid" name="refid" value="<?php echo softone_debug_escape( $inputs['refid'] ); ?>" />
					</div>
					<div>
						<label for="warehouse">Warehouse</label>
						<input type="text" id="warehouse" name="warehouse" value="<?php echo softone_debug_escape( $inputs['warehouse'] ); ?>" />
					</div>
					<div>
						<label for="areas">Areas</label>
						<input type="text" id="areas" name="areas" value="<?php echo softone_debug_escape( $inputs['areas'] ); ?>" />
					</div>
					<div>
						<label for="socurrency">Currency</label>
						<input type="text" id="socurrency" name="socurrency" value="<?php echo softone_debug_escape( $inputs['socurrency'] ); ?>" />
					</div>
					<div>
						<label for="trdcategory">Trading Category</label>
						<input type="text" id="trdcategory" name="trdcategory" value="<?php echo softone_debug_escape( $inputs['trdcategory'] ); ?>" />
					</div>
				</div>
			</fieldset>

			<fieldset>
				<legend>Request Builder</legend>
				<label for="service">Service</label>
				<select id="service" name="service">
					<?php
					$services = array(
						'login'        => 'login',
						'authenticate' => 'authenticate',
						'sqldata'      => 'SqlData',
						'setdata'      => 'setData',
						'custom'       => 'Custom JSON',
					);

					foreach ( $services as $value => $label ) {
						$selected = ( $inputs['service'] === $value ) ? 'selected' : '';
						printf( '<option value="%1$s" %3$s>%2$s</option>', softone_debug_escape( $value ), softone_debug_escape( $label ), $selected );
					}
					?>
				</select>

				<label for="client_id">Client ID</label>
				<input type="text" id="client_id" name="client_id" value="<?php echo softone_debug_escape( $inputs['client_id'] ); ?>" />

				<div id="sqldata-fields">
					<label for="sql_name">SqlData &ndash; Stored SQL name</label>
					<input type="text" id="sql_name" name="sql_name" value="<?php echo softone_debug_escape( $inputs['sql_name'] ); ?>" />

					<label for="sql_params">SqlData &ndash; Parameters (JSON)</label>
					<textarea id="sql_params" name="sql_params" placeholder='{"param1":"value"}'><?php echo softone_debug_escape( $inputs['sql_params'] ); ?></textarea>
				</div>

				<div id="setdata-fields">
					<label for="object_name">setData &ndash; Object</label>
					<input type="text" id="object_name" name="object_name" value="<?php echo softone_debug_escape( $inputs['object_name'] ); ?>" />

					<label for="object_data">setData &ndash; Data (JSON)</label>
					<textarea id="object_data" name="object_data" placeholder='{"SALDOC":{"TRDR":123}}'><?php echo softone_debug_escape( $inputs['object_data'] ); ?></textarea>
				</div>

                                <div id="custom-payload-wrapper">
                                        <label for="custom_payload">Custom JSON payload</label>
                                        <textarea id="custom_payload" name="custom_payload" placeholder='{"key":"value"}'><?php echo softone_debug_escape( $inputs['custom_payload'] ); ?></textarea>
                                </div>

				<label for="extra_payload">Extra payload to merge (JSON)</label>
				<textarea id="extra_payload" name="extra_payload" placeholder='{"key":"value"}'><?php echo softone_debug_escape( $inputs['extra_payload'] ); ?></textarea>
			</fieldset>

			<button type="submit" class="button-primary">Send request</button>
		</form>

		<?php if ( $results ) : ?>
		<section class="output-grid">
				<div class="output-card">
					<h2>Request</h2>
					<p><strong>Endpoint:</strong> <?php echo softone_debug_escape( $results['endpoint'] ); ?></p>
					<h3>Headers</h3>
					<pre><?php echo softone_debug_escape( implode( "\n", $results['request_headers'] ) ); ?></pre>
					<h3>Payload</h3>
					<pre><?php echo softone_debug_escape( softone_debug_pretty_json( $results['payload'] ) ); ?></pre>
				</div>

				<div class="output-card">
					<h2>Response</h2>
					<p><strong>Status code:</strong> <?php echo softone_debug_escape( $results['http_code'] ); ?></p>
					<h3>Headers</h3>
					<pre><?php echo softone_debug_escape( softone_debug_pretty_json( $results['headers'] ) ); ?></pre>
<h3>Body</h3>
<pre><?php echo softone_debug_escape( softone_debug_pretty_json( null !== $results['body_decoded'] ? $results['body_decoded'] : $results['raw_body'] ) ); ?></pre>
				</div>

				<div class="output-card">
					<h2>cURL diagnostics</h2>
					<h3>Info</h3>
					<pre><?php echo softone_debug_escape( softone_debug_pretty_json( $results['curl_info'] ) ); ?></pre>
					<h3>Error</h3>
					<pre><?php echo softone_debug_escape( sprintf( 'Code: %1$s | Message: %2$s', $results['curl_errno'], $results['curl_error'] ) ); ?></pre>
					<h3>Verbose log</h3>
					<pre><?php echo softone_debug_escape( $results['verbose_log'] ); ?></pre>
				</div>
		</section>
		<?php elseif ( $executed && empty( $messages ) ) : ?>
		<div class="notice">No response was returned. Check the cURL diagnostics for clues.</div>
		<?php endif; ?>
	</main>
	<script>
	(function() {
		const serviceSelect = document.getElementById( 'service' );
		const sqlFields = document.getElementById( 'sqldata-fields' );
		const setDataFields = document.getElementById( 'setdata-fields' );
		const customWrapper = document.getElementById( 'custom-payload-wrapper' );

		function toggleFields() {
			const value = serviceSelect.value.toLowerCase();

			if ( sqlFields ) {
				sqlFields.style.display = ( 'sqldata' === value ) ? 'block' : 'none';
			}

			if ( setDataFields ) {
				setDataFields.style.display = ( 'setdata' === value ) ? 'block' : 'none';
			}

			if ( customWrapper ) {
				customWrapper.style.display = ( 'custom' === value ) ? 'block' : 'none';
			}
		}

		toggleFields();
		serviceSelect.addEventListener( 'change', toggleFields );
	})();
	</script>
</body>
</html>
