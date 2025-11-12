<?php
if ( isset( $_GET['debug'] ) ) {
ini_set( 'display_errors', '1' );
ini_set( 'display_startup_errors', '1' );
error_reporting( E_ALL );
}

if ( ! ini_get( 'date.timezone' ) ) {
date_default_timezone_set( 'UTC' );
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
 * Pretty-print arrays/objects as JSON when possible.
 *
 * @param mixed $value Value to render.
 *
 * @return string
 */
function softone_debug_pretty_json( $value ) {
if ( is_string( $value ) ) {
$decoded = json_decode( $value, true );

if ( null !== $decoded && JSON_ERROR_NONE === json_last_error() ) {
$value = $decoded;
}
}

if ( is_array( $value ) ) {
$encoded = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

if ( false !== $encoded ) {
return $encoded;
}
}

return (string) $value;
}

/**
 * Mask sensitive identifiers leaving the trailing characters visible.
 *
 * @param string $value Raw identifier.
 *
 * @return string
 */
function softone_debug_mask_value( $value ) {
$value = (string) $value;

if ( '' === $value ) {
return '';
}

$length = strlen( $value );

if ( $length <= 4 ) {
return str_repeat( '•', $length );
}

return str_repeat( '•', max( 0, $length - 4 ) ) . substr( $value, -4 );
}

/**
 * Locate the WordPress bootstrap file relative to this script.
 *
 * @return string|false
 */
function softone_debug_locate_wp_load() {
if ( defined( 'ABSPATH' ) ) {
return ABSPATH . 'wp-load.php';
}

$paths   = array();
$current = __DIR__;

for ( $depth = 0; $depth < 8; $depth++ ) {
$paths[] = $current . '/wp-load.php';
$paths[] = $current . '/../wp-load.php';
$paths[] = $current . '/../../wp-load.php';
$current = dirname( $current );
}

$paths = array_unique( array_map( 'realpath', array_filter( $paths, 'file_exists' ) ) );

if ( empty( $paths ) ) {
return false;
}

return array_shift( $paths );
}

$environment_messages = array();
$wp_load_path          = softone_debug_locate_wp_load();
$wp_loaded             = false;

if ( $wp_load_path && file_exists( $wp_load_path ) ) {
require_once $wp_load_path;
$wp_loaded              = true;
$environment_messages[] = 'WordPress environment loaded from: ' . $wp_load_path;
} elseif ( defined( 'ABSPATH' ) ) {
$wp_loaded              = true;
$environment_messages[] = 'WordPress was already loaded by the hosting environment.';
} else {
$environment_messages[] = 'Unable to locate wp-load.php. Checked parent directories relative to ' . __DIR__ . '.';
}

$settings_summary = array();
$raw_settings     = array();
$client           = null;
$trace            = null;
$trace_entries    = array();

if ( $wp_loaded ) {
$plugin_root = dirname( __DIR__ );

if ( file_exists( $plugin_root . '/includes/softone-woocommerce-integration-settings.php' ) ) {
require_once $plugin_root . '/includes/softone-woocommerce-integration-settings.php';
}

if ( file_exists( $plugin_root . '/includes/class-softone-process-trace.php' ) ) {
require_once $plugin_root . '/includes/class-softone-process-trace.php';
}

if ( function_exists( 'softone_wc_integration_get_settings' ) ) {
$raw_settings = softone_wc_integration_get_settings();

$settings_summary = array(
'Endpoint'              => isset( $raw_settings['endpoint'] ) ? (string) $raw_settings['endpoint'] : '',
'Username'              => isset( $raw_settings['username'] ) ? (string) $raw_settings['username'] : '',
'Password'              => softone_debug_mask_value( isset( $raw_settings['password'] ) ? $raw_settings['password'] : '' ),
'App ID'                => isset( $raw_settings['app_id'] ) ? (string) $raw_settings['app_id'] : '',
'Company'               => isset( $raw_settings['company'] ) ? (string) $raw_settings['company'] : '',
'Branch'                => isset( $raw_settings['branch'] ) ? (string) $raw_settings['branch'] : '',
'Module'                => isset( $raw_settings['module'] ) ? (string) $raw_settings['module'] : '',
'RefID'                 => isset( $raw_settings['refid'] ) ? (string) $raw_settings['refid'] : '',
'Default SALDOC Series' => isset( $raw_settings['default_saldoc_series'] ) ? (string) $raw_settings['default_saldoc_series'] : '',
'Warehouse'             => isset( $raw_settings['warehouse'] ) ? (string) $raw_settings['warehouse'] : '',
'Areas'                 => isset( $raw_settings['areas'] ) ? (string) $raw_settings['areas'] : '',
'Currency'              => isset( $raw_settings['socurrency'] ) ? (string) $raw_settings['socurrency'] : '',
'Trading Category'      => isset( $raw_settings['trdcategory'] ) ? (string) $raw_settings['trdcategory'] : '',
'Request Timeout'       => isset( $raw_settings['timeout'] ) ? (string) $raw_settings['timeout'] : '',
);
}

if ( class_exists( 'Softone_Process_Trace' ) && class_exists( 'Softone_Process_Trace_Api_Client' ) ) {
$trace         = new Softone_Process_Trace();
$stream_logger = class_exists( 'Softone_Process_Trace_Stream_Logger' ) ? new Softone_Process_Trace_Stream_Logger( $trace ) : null;
$client        = new Softone_Process_Trace_Api_Client( $trace, array(), $stream_logger );
} elseif ( class_exists( 'Softone_API_Client' ) ) {
$client = new Softone_API_Client();
}
}

/**
 * Decode JSON strings while recording errors.
 *
 * @param string $raw        Raw JSON string.
 * @param array  $messages   Collector for validation messages.
 * @param string $field_name Field label.
 *
 * @return array|null
 */
function softone_debug_parse_json( $raw, array &$messages, $field_name ) {
$raw = trim( (string) $raw );

if ( '' === $raw ) {
return null;
}

$decoded = json_decode( $raw, true );

if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
$messages[] = sprintf( '%1$s contains invalid JSON: %2$s', $field_name, json_last_error_msg() );

return null;
}

return $decoded;
}

$action_messages = array();
$session_result  = array();
$sql_result      = array();

$inputs = array(
'sql_name'      => 'getItems',
'sql_params'    => '{"pMins":99999}',
'extra_payload' => '',
'limit_rows'    => 10,
);

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
$post_values = $_POST;

if ( function_exists( 'wp_unslash' ) ) {
$post_values = wp_unslash( $post_values );
}

if ( isset( $post_values['sql_name'] ) ) {
$inputs['sql_name'] = is_callable( 'sanitize_text_field' ) ? sanitize_text_field( $post_values['sql_name'] ) : (string) $post_values['sql_name'];
}

if ( isset( $post_values['sql_params'] ) ) {
$inputs['sql_params'] = (string) $post_values['sql_params'];
}

if ( isset( $post_values['extra_payload'] ) ) {
$inputs['extra_payload'] = (string) $post_values['extra_payload'];
}

if ( isset( $post_values['limit_rows'] ) ) {
$inputs['limit_rows'] = (int) $post_values['limit_rows'];
}
}

$action = '';

if ( isset( $_POST['softone_debug_action'] ) ) {
$raw_action = $_POST['softone_debug_action'];

if ( function_exists( 'wp_unslash' ) ) {
$raw_action = wp_unslash( $raw_action );
}

$action = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $raw_action ) );
}

if ( $client && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
if ( function_exists( 'check_admin_referer' ) && isset( $_POST['softone_debug_nonce'] ) ) {
check_admin_referer( 'softone_debug_action', 'softone_debug_nonce' );
}

switch ( $action ) {
case 'refresh_session':
try {
if ( method_exists( $client, 'clear_cached_client_id' ) ) {
$client->clear_cached_client_id();
}

if ( method_exists( $client, 'login' ) ) {
$login_response = $client->login();
} else {
throw new RuntimeException( 'Softone client is missing the login method.' );
}

$session_result['login_response'] = $login_response;

$client_id = isset( $login_response['clientID'] ) ? (string) $login_response['clientID'] : '';

if ( '' === $client_id ) {
throw new Softone_API_Client_Exception( '[SO-DBG-001] SoftOne login did not return a clientID.' );
}

if ( method_exists( $client, 'authenticate' ) ) {
$authenticate_response = $client->authenticate( $client_id );
} else {
throw new RuntimeException( 'Softone client is missing the authenticate method.' );
}

$session_result['authenticate_response'] = $authenticate_response;
$session_result['client_id']            = isset( $authenticate_response['clientID'] ) ? (string) $authenticate_response['clientID'] : '';
$session_result['status']               = 'success';

$action_messages[] = 'SoftOne session refreshed successfully.';
} catch ( Exception $exception ) {
$session_result['status'] = 'error';
$session_result['error']  = $exception->getMessage();
$action_messages[]        = 'Error refreshing SoftOne session: ' . $exception->getMessage();
}
break;

case 'sql_data':
$sql_messages = array();
$sql_name     = isset( $inputs['sql_name'] ) ? trim( $inputs['sql_name'] ) : '';

$params = softone_debug_parse_json( $inputs['sql_params'], $sql_messages, 'SQL parameters' );
$extra  = softone_debug_parse_json( $inputs['extra_payload'], $sql_messages, 'Extra payload' );

if ( '' === $sql_name ) {
$sql_messages[] = 'SQL name is required.';
}

if ( empty( $sql_messages ) ) {
try {
$params_array = is_array( $params ) ? $params : array();
$extra_array  = is_array( $extra ) ? $extra : array();
$response     = $client->sql_data( $sql_name, $params_array, $extra_array );

$sql_result['status']    = 'success';
$sql_result['response']  = $response;
$sql_result['row_count'] = isset( $response['rows'] ) && is_array( $response['rows'] ) ? count( $response['rows'] ) : 0;
$sql_result['messages']  = $sql_messages;

$action_messages[] = sprintf( 'SqlData request "%s" executed successfully.', $sql_name );
} catch ( Exception $exception ) {
$sql_result['status']   = 'error';
$sql_result['error']    = $exception->getMessage();
$sql_result['messages'] = $sql_messages;

$action_messages[] = 'SqlData request failed: ' . $exception->getMessage();
}
} else {
$sql_result['status']   = 'error';
$sql_result['messages'] = $sql_messages;

$action_messages[] = 'SqlData request aborted due to validation errors.';
}
break;
}
}

if ( $trace instanceof Softone_Process_Trace ) {
$trace_entries = $trace->get_entries();
}

$wp_site_url = function_exists( 'get_site_url' ) ? get_site_url() : '';
$wp_home_url = function_exists( 'home_url' ) ? home_url() : '';
$wp_timezone = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
$wp_version  = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version', 'display' ) : '';
$plugin_active = false;

if ( function_exists( 'is_plugin_active' ) ) {
$plugin_active = is_plugin_active( 'softone-woocommerce-integration/softone-woocommerce-integration.php' );
} elseif ( class_exists( 'Softone_Woocommerce_Integration' ) ) {
$plugin_active = true;
}

function softone_debug_render_trace( $entries ) {
if ( empty( $entries ) ) {
return '';
}

$output = '<table class="trace-table">';
$output .= '<thead><tr><th>Time</th><th>Type</th><th>Action</th><th>Level</th><th>Message</th><th>Context</th></tr></thead>';
$output .= '<tbody>';

foreach ( $entries as $entry ) {
$timestamp = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : time();
$time      = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $timestamp ) : date( 'Y-m-d H:i:s', $timestamp );

$output .= '<tr>';
$output .= '<td>' . softone_debug_escape( $time ) . '</td>';
$output .= '<td>' . softone_debug_escape( isset( $entry['type'] ) ? $entry['type'] : '' ) . '</td>';
$output .= '<td>' . softone_debug_escape( isset( $entry['action'] ) ? $entry['action'] : '' ) . '</td>';
$output .= '<td>' . softone_debug_escape( isset( $entry['level'] ) ? $entry['level'] : '' ) . '</td>';
$output .= '<td>' . softone_debug_escape( isset( $entry['message'] ) ? $entry['message'] : '' ) . '</td>';
$output .= '<td><pre>' . softone_debug_escape( softone_debug_pretty_json( isset( $entry['context'] ) ? $entry['context'] : array() ) ) . '</pre></td>';
$output .= '</tr>';
}

$output .= '</tbody></table>';

return $output;
}

function softone_debug_render_messages( $messages, $class = 'notice' ) {
if ( empty( $messages ) ) {
return '';
}

$output = '<div class="' . softone_debug_escape( $class ) . '"><ul>';

foreach ( $messages as $message ) {
$output .= '<li>' . softone_debug_escape( $message ) . '</li>';
}

$output .= '</ul></div>';

return $output;
}

function softone_debug_render_response_block( $title, $data ) {
if ( empty( $data ) || ! is_array( $data ) ) {
return '';
}

return '<details><summary>' . softone_debug_escape( $title ) . '</summary><pre>' . softone_debug_escape( softone_debug_pretty_json( $data ) ) . '</pre></details>';
}

$limit_rows = isset( $inputs['limit_rows'] ) ? (int) $inputs['limit_rows'] : 10;
$sql_rows   = array();

if ( isset( $sql_result['response']['rows'] ) && is_array( $sql_result['response']['rows'] ) ) {
$sql_rows = $sql_result['response']['rows'];
}

if ( $limit_rows > 0 && count( $sql_rows ) > $limit_rows ) {
$sql_rows = array_slice( $sql_rows, 0, $limit_rows );
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
background: #f7f7f7;
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
max-width: 1100px;
margin: 0 auto;
}

section {
background: #fff;
border-radius: 8px;
padding: 1.5rem;
margin-bottom: 1.5rem;
box-shadow: 0 1px 3px rgba( 0, 0, 0, 0.08 );
}

section h2 {
margin-top: 0;
}

.notice {
border-left: 4px solid #2271b1;
background: #f0f6fc;
padding: 1rem;
margin-bottom: 1rem;
}

.notice ul {
margin: 0;
padding-left: 1.25rem;
}

.error {
border-left-color: #d63638;
background: #fcf0f1;
}

table.settings-table,
table.trace-table,
table.rows-table {
width: 100%;
border-collapse: collapse;
font-size: 0.95rem;
}

table.settings-table th,
table.settings-table td,
table.trace-table th,
table.trace-table td,
table.rows-table th,
table.rows-table td {
border: 1px solid #e2e4e7;
padding: 0.65rem;
vertical-align: top;
}

table.rows-table thead {
background: #f6f7f7;
}

form .field {
display: flex;
flex-direction: column;
margin-bottom: 1rem;
}

form label {
font-weight: 600;
margin-bottom: 0.4rem;
}

form input[type="text"],
form input[type="number"],
form textarea {
padding: 0.6rem;
border-radius: 4px;
border: 1px solid #8c8f94;
font-size: 1rem;
font-family: inherit;
}

form textarea {
min-height: 120px;
}

form button {
display: inline-flex;
align-items: center;
gap: 0.5rem;
background: #2271b1;
color: #fff;
border: none;
border-radius: 4px;
padding: 0.7rem 1.2rem;
cursor: pointer;
font-size: 1rem;
}

form button.secondary {
background: #50575e;
}

details summary {
cursor: pointer;
font-weight: 600;
margin-bottom: 0.5rem;
}

pre {
font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
font-size: 0.9rem;
background: #f6f7f7;
padding: 0.75rem;
border-radius: 4px;
overflow-x: auto;
}

.action-buttons {
display: flex;
flex-wrap: wrap;
gap: 0.75rem;
}

@media (max-width: 720px) {
main {
padding: 1rem;
}

form .action-buttons {
flex-direction: column;
}
}
</style>
</head>
<body>
<header>
<h1>SoftOne API Debugger</h1>
<p>Inspect WordPress configuration and execute SoftOne API calls using the plugin settings.</p>
</header>
<main>
<?php echo softone_debug_render_messages( $environment_messages ); ?>

<section>
<h2>Environment</h2>
<table class="settings-table">
<tbody>
<tr><th>Site URL</th><td><?php echo softone_debug_escape( $wp_site_url ); ?></td></tr>
<tr><th>Home URL</th><td><?php echo softone_debug_escape( $wp_home_url ); ?></td></tr>
<tr><th>WordPress Version</th><td><?php echo softone_debug_escape( $wp_version ); ?></td></tr>
<tr><th>Timezone</th><td><?php echo softone_debug_escape( $wp_timezone ); ?></td></tr>
<tr><th>Plugin Active</th><td><?php echo $plugin_active ? 'Yes' : 'No'; ?></td></tr>
<tr><th>Client Class</th><td><?php echo softone_debug_escape( $client ? get_class( $client ) : 'Unavailable' ); ?></td></tr>
</tbody>
</table>
</section>

<section>
<h2>Connection Settings Overview</h2>
<?php if ( empty( $settings_summary ) ) : ?>
<p>The plugin settings could not be loaded. Verify that the plugin is active and configured.</p>
<?php else : ?>
<table class="settings-table">
<tbody>
<?php foreach ( $settings_summary as $label => $value ) : ?>
<tr>
<th><?php echo softone_debug_escape( $label ); ?></th>
<td><?php echo softone_debug_escape( $value ); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</section>

<section>
<h2>Actions</h2>
<?php echo softone_debug_render_messages( $action_messages, 'notice' ); ?>

<div class="action-buttons">
<form method="post">
<?php if ( function_exists( 'wp_nonce_field' ) ) { wp_nonce_field( 'softone_debug_action', 'softone_debug_nonce' ); } ?>
<input type="hidden" name="softone_debug_action" value="refresh_session" />
<button type="submit">Refresh SoftOne Session</button>
</form>
</div>

<hr />

<form method="post">
<?php if ( function_exists( 'wp_nonce_field' ) ) { wp_nonce_field( 'softone_debug_action', 'softone_debug_nonce' ); } ?>
<input type="hidden" name="softone_debug_action" value="sql_data" />
<div class="field">
<label for="sql_name">Stored SQL Name</label>
<input type="text" id="sql_name" name="sql_name" value="<?php echo softone_debug_escape( $inputs['sql_name'] ); ?>" placeholder="getItems" />
</div>
<div class="field">
<label for="sql_params">SQL Parameters (JSON)</label>
<textarea id="sql_params" name="sql_params" placeholder='{"pMins":99999}'><?php echo softone_debug_escape( $inputs['sql_params'] ); ?></textarea>
</div>
<div class="field">
<label for="extra_payload">Extra Payload (JSON)</label>
<textarea id="extra_payload" name="extra_payload" placeholder='{"clientid":"123"}'><?php echo softone_debug_escape( $inputs['extra_payload'] ); ?></textarea>
</div>
<div class="field">
<label for="limit_rows">Display first N rows</label>
<input type="number" id="limit_rows" name="limit_rows" min="0" step="1" value="<?php echo softone_debug_escape( (string) $inputs['limit_rows'] ); ?>" />
</div>
<button type="submit" class="secondary">Run SqlData Request</button>
</form>
</section>

<?php if ( ! empty( $session_result ) ) : ?>
<section>
<h2>Session Debug</h2>
<?php if ( 'success' === ( isset( $session_result['status'] ) ? $session_result['status'] : '' ) ) : ?>
<p><strong>Authenticated Client ID:</strong> <?php echo softone_debug_escape( softone_debug_mask_value( isset( $session_result['client_id'] ) ? $session_result['client_id'] : '' ) ); ?></p>
<?php else : ?>
<div class="notice error">
<ul><li><?php echo softone_debug_escape( isset( $session_result['error'] ) ? $session_result['error'] : 'Session refresh failed.' ); ?></li></ul>
</div>
<?php endif; ?>

<?php
echo softone_debug_render_response_block( 'Login Response', isset( $session_result['login_response'] ) ? $session_result['login_response'] : array() );
echo softone_debug_render_response_block( 'Authenticate Response', isset( $session_result['authenticate_response'] ) ? $session_result['authenticate_response'] : array() );
?>
</section>
<?php endif; ?>

<?php if ( ! empty( $sql_result ) ) : ?>
<section>
<h2>SqlData Result</h2>
<?php if ( ! empty( $sql_result['messages'] ) ) : ?>
<?php echo softone_debug_render_messages( $sql_result['messages'], 'notice' ); ?>
<?php endif; ?>

<?php if ( 'success' === ( isset( $sql_result['status'] ) ? $sql_result['status'] : '' ) ) : ?>
<p><strong>Total rows returned:</strong> <?php echo softone_debug_escape( (string) $sql_result['row_count'] ); ?></p>
<?php if ( ! empty( $sql_rows ) ) : ?>
<table class="rows-table">
<thead><tr>
<?php foreach ( array_keys( $sql_rows[0] ) as $column ) : ?>
<th><?php echo softone_debug_escape( $column ); ?></th>
<?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach ( $sql_rows as $row ) : ?>
<tr>
<?php foreach ( $row as $value ) : ?>
<td><?php echo softone_debug_escape( is_scalar( $value ) ? (string) $value : softone_debug_pretty_json( $value ) ); ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<?php echo softone_debug_render_response_block( 'Raw SqlData Response', isset( $sql_result['response'] ) ? $sql_result['response'] : array() ); ?>
<?php else : ?>
<div class="notice error">
<ul><li><?php echo softone_debug_escape( isset( $sql_result['error'] ) ? $sql_result['error'] : 'SqlData request failed.' ); ?></li></ul>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ( ! empty( $trace_entries ) ) : ?>
<section>
<h2>Process Trace</h2>
<?php echo softone_debug_render_trace( $trace_entries ); ?>
</section>
<?php endif; ?>
</main>
</body>
</html>
