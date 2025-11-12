<?php
if ( isset( $_GET['debug'] ) ) {
ini_set( 'display_errors', '1' );
ini_set( 'display_startup_errors', '1' );
error_reporting( E_ALL );
}

if ( ! ini_get( 'date.timezone' ) ) {
date_default_timezone_set( 'UTC' );
}

$softone_debug_session_capture = array(
    'login_response'        => null,
    'authenticate_response' => null,
    'ttl'                   => null,
);

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

/**
 * Capture SoftOne session bootstrap details when refreshing via the API client.
 *
 * @param int                     $ttl                    Resolved TTL value.
 * @param array<string,mixed>     $login_response         Login response payload.
 * @param array<string,mixed>     $authenticate_response  Authenticate response payload.
 * @param Softone_API_Client|null $client                 API client instance.
 *
 * @return int
 */
function softone_debug_capture_session_data( $ttl, $login_response, $authenticate_response, $client ) {
    global $softone_debug_session_capture;

    if ( ! is_array( $softone_debug_session_capture ) ) {
        $softone_debug_session_capture = array();
    }

    $softone_debug_session_capture['login_response']        = is_array( $login_response ) ? $login_response : array();
    $softone_debug_session_capture['authenticate_response'] = is_array( $authenticate_response ) ? $authenticate_response : array();
    $softone_debug_session_capture['ttl']                   = is_numeric( $ttl ) ? (int) $ttl : $ttl;

    return $ttl;
}

/**
 * Format a timestamp for display using the current WordPress timezone when available.
 *
 * @param int|string $timestamp Raw timestamp.
 *
 * @return string
 */
function softone_debug_format_timestamp( $timestamp ) {
    if ( ! is_numeric( $timestamp ) ) {
        return '';
    }

    $timestamp = (int) $timestamp;
    $callback  = function_exists( 'wp_date' ) ? 'wp_date' : 'date';

    return call_user_func( $callback, 'Y-m-d H:i:s', $timestamp );
}

/**
 * Summarise cached session metadata for display.
 *
 * @param array<string,mixed> $meta Raw metadata stored by the client.
 * @param int|null            $resolved_ttl TTL determined during the refresh.
 *
 * @return array<string,mixed>
 */
function softone_debug_summarise_session_cache( array $meta, $resolved_ttl = null ) {
    $summary = array();

    if ( isset( $meta['client_id'] ) && '' !== (string) $meta['client_id'] ) {
        $summary['Cached client ID'] = softone_debug_mask_value( $meta['client_id'] );
    }

    if ( null !== $resolved_ttl && is_numeric( $resolved_ttl ) ) {
        $ttl_seconds = (int) $resolved_ttl;
        $summary['Resolved TTL (seconds)'] = $ttl_seconds;
        $summary['Resolved TTL (minutes)'] = round( $ttl_seconds / 60, 2 );
    }

    if ( isset( $meta['ttl'] ) && is_numeric( $meta['ttl'] ) ) {
        $stored_ttl = (int) $meta['ttl'];
        $summary['Stored TTL (seconds)'] = $stored_ttl;
        $summary['Stored TTL (minutes)'] = round( $stored_ttl / 60, 2 );
    }

    if ( isset( $meta['cached_at'] ) && is_numeric( $meta['cached_at'] ) ) {
        $summary['Cached at'] = softone_debug_format_timestamp( $meta['cached_at'] );
    }

    if ( isset( $meta['expires_at'] ) && is_numeric( $meta['expires_at'] ) ) {
        $expires_at = (int) $meta['expires_at'];
        $summary['Expires at']            = softone_debug_format_timestamp( $expires_at );
        $summary['Seconds until expiry'] = max( 0, $expires_at - time() );
    }

    return $summary;
}

/**
 * Normalise checkbox/flag style inputs to booleans.
 *
 * @param mixed $value Raw value.
 *
 * @return bool
 */
function softone_debug_normalize_flag( $value ) {
    if ( is_bool( $value ) ) {
        return $value;
    }

    if ( is_numeric( $value ) ) {
        if ( function_exists( 'absint' ) ) {
            return (bool) absint( $value );
        }

        return abs( (int) $value ) > 0;
    }

    $value = strtolower( trim( (string) $value ) );

    return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
}

/**
 * Render a checked attribute when the value is truthy.
 *
 * @param bool $is_checked Value to normalise.
 *
 * @return string
 */
function softone_debug_checked_attribute( $is_checked ) {
    return $is_checked ? ' checked="checked"' : '';
}

/**
 * Format boolean values as human readable strings.
 *
 * @param bool $value Raw boolean.
 *
 * @return string
 */
function softone_debug_format_boolean_label( $value ) {
    $value = (bool) $value;

    if ( function_exists( '__' ) ) {
        return $value ? __( 'Yes', 'softone-woocommerce-integration' ) : __( 'No', 'softone-woocommerce-integration' );
    }

    return $value ? 'Yes' : 'No';
}

/**
 * Format integers using the current locale where possible.
 *
 * @param int $value Raw integer value.
 *
 * @return string
 */
function softone_debug_format_number( $value ) {
    if ( function_exists( 'number_format_i18n' ) ) {
        return number_format_i18n( (int) $value );
    }

    return (string) (int) $value;
}

/**
 * Build a concise summary of an item sync run.
 *
 * @param array<string,mixed> $result       Result payload returned by the synchroniser.
 * @param int                 $started_at   Timestamp when the run started.
 * @param int                 $finished_at  Timestamp when the run completed.
 * @param bool                $success      Whether the run completed successfully.
 *
 * @return array<string,mixed>
 */
function softone_debug_prepare_sync_summary( array $result, $started_at, $finished_at, $success ) {
    $started_at  = max( 0, (int) $started_at );
    $finished_at = max( $started_at, (int) $finished_at );
    $duration    = max( 0, $finished_at - $started_at );

    $summary = array(
        'success'               => (bool) $success,
        'processed'             => isset( $result['processed'] ) ? (int) $result['processed'] : 0,
        'created'               => isset( $result['created'] ) ? (int) $result['created'] : 0,
        'updated'               => isset( $result['updated'] ) ? (int) $result['updated'] : 0,
        'skipped'               => isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
        'stale_processed'       => isset( $result['stale_processed'] ) ? (int) $result['stale_processed'] : 0,
        'started_at'            => $started_at,
        'finished_at'           => $finished_at,
        'duration_seconds'      => $duration,
        'started_at_formatted'  => softone_debug_format_timestamp( $started_at ),
        'finished_at_formatted' => softone_debug_format_timestamp( $finished_at ),
        'duration_human'        => '',
    );

    if ( $duration > 0 ) {
        if ( function_exists( 'human_time_diff' ) ) {
            $summary['duration_human'] = human_time_diff( $started_at, $finished_at );
        } else {
            $summary['duration_human'] = $duration . ' seconds';
        }
    } else {
        $summary['duration_human'] = function_exists( '__' ) ? __( 'less than a minute', 'softone-woocommerce-integration' ) : 'less than a minute';
    }

    return $summary;
}

/**
 * Prepare key/value rows for displaying the sync summary table.
 *
 * @param array<string,mixed> $summary Summary payload.
 * @param array<string,bool>  $options Options used for the run.
 *
 * @return array<string,string>
 */
function softone_debug_prepare_sync_display_rows( array $summary, array $options = array() ) {
    $rows = array();

    if ( isset( $summary['success'] ) ) {
        $rows['Status'] = $summary['success'] ? 'Success' : 'Failed';
    }

    if ( array_key_exists( 'force_full_import', $options ) ) {
        $rows['Force full import'] = softone_debug_format_boolean_label( $options['force_full_import'] );
    }

    if ( array_key_exists( 'force_taxonomy_refresh', $options ) ) {
        $rows['Force taxonomy refresh'] = softone_debug_format_boolean_label( $options['force_taxonomy_refresh'] );
    }

    if ( isset( $summary['processed'] ) ) {
        $rows['Processed products'] = softone_debug_format_number( $summary['processed'] );
    }

    if ( isset( $summary['created'] ) ) {
        $rows['Created products'] = softone_debug_format_number( $summary['created'] );
    }

    if ( isset( $summary['updated'] ) ) {
        $rows['Updated products'] = softone_debug_format_number( $summary['updated'] );
    }

    if ( isset( $summary['skipped'] ) ) {
        $rows['Skipped products'] = softone_debug_format_number( $summary['skipped'] );
    }

    if ( isset( $summary['stale_processed'] ) && $summary['stale_processed'] > 0 ) {
        $rows['Stale products handled'] = softone_debug_format_number( $summary['stale_processed'] );
    }

    if ( ! empty( $summary['started_at_formatted'] ) ) {
        $rows['Started at'] = $summary['started_at_formatted'];
    }

    if ( ! empty( $summary['finished_at_formatted'] ) ) {
        $rows['Finished at'] = $summary['finished_at_formatted'];
    }

    if ( isset( $summary['duration_human'] ) && '' !== $summary['duration_human'] ) {
        $rows['Duration'] = $summary['duration_human'];
    }

    if ( isset( $summary['duration_seconds'] ) ) {
        $rows['Duration (seconds)'] = softone_debug_format_number( $summary['duration_seconds'] );
    }

    return $rows;
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
$variation_diagnostics = softone_debug_collect_variation_diagnostics( array() );
$activity_logger  = null;
$item_sync        = null;

if ( $wp_loaded ) {
    $plugin_root = dirname( __DIR__ );

    if ( file_exists( $plugin_root . '/includes/softone-woocommerce-integration-settings.php' ) ) {
        require_once $plugin_root . '/includes/softone-woocommerce-integration-settings.php';
    }

    if ( file_exists( $plugin_root . '/includes/class-softone-process-trace.php' ) ) {
        require_once $plugin_root . '/includes/class-softone-process-trace.php';
    }

    if ( file_exists( $plugin_root . '/includes/class-softone-item-sync.php' ) ) {
        require_once $plugin_root . '/includes/class-softone-item-sync.php';
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

        if ( class_exists( 'Softone_Process_Trace_Activity_Logger' ) ) {
            $activity_logger = new Softone_Process_Trace_Activity_Logger( $trace );
        }
    } elseif ( class_exists( 'Softone_API_Client' ) ) {
        $client = new Softone_API_Client();
    }

    if ( $trace instanceof Softone_Process_Trace && ! $activity_logger && class_exists( 'Softone_Process_Trace_Activity_Logger' ) ) {
        $activity_logger = new Softone_Process_Trace_Activity_Logger( $trace );
    }

    if ( class_exists( 'Softone_Item_Sync' ) && $client instanceof Softone_API_Client ) {
        $item_logger        = isset( $stream_logger ) && $stream_logger ? $stream_logger : null;
        $activity_instance  = ( class_exists( 'Softone_Sync_Activity_Logger' ) && $activity_logger instanceof Softone_Sync_Activity_Logger ) ? $activity_logger : null;

        try {
            $item_sync = new Softone_Item_Sync( $client, $item_logger, null, $activity_instance );
        } catch ( Exception $exception ) {
            $environment_messages[] = 'Unable to initialise item synchroniser: ' . $exception->getMessage();
        }
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
$sync_result     = array();
$variation_flow  = softone_debug_collect_variation_flow();

$sync_options = array(
    'force_full_import'      => false,
    'force_taxonomy_refresh' => false,
);

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

$sync_options['force_full_import']      = isset( $post_values['force_full_import'] ) ? softone_debug_normalize_flag( $post_values['force_full_import'] ) : false;
$sync_options['force_taxonomy_refresh'] = isset( $post_values['force_taxonomy_refresh'] ) ? softone_debug_normalize_flag( $post_values['force_taxonomy_refresh'] ) : false;

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
        $filter_added = false;

        try {
            if ( method_exists( $client, 'clear_cached_client_id' ) ) {
                $client->clear_cached_client_id();
            }

            global $softone_debug_session_capture;
            $softone_debug_session_capture = array(
                'login_response'        => null,
                'authenticate_response' => null,
                'ttl'                   => null,
            );

            if ( function_exists( 'add_filter' ) ) {
                add_filter( 'softone_wc_integration_client_ttl', 'softone_debug_capture_session_data', 10, 4 );
                $filter_added = true;
            }

            if ( method_exists( $client, 'get_client_id' ) ) {
                $client_id = $client->get_client_id( true );
            } else {
                throw new RuntimeException( 'Softone client is missing the get_client_id method.' );
            }

            $session_result['client_id'] = (string) $client_id;
            $session_result['status']    = 'success';

            if ( isset( $softone_debug_session_capture['login_response'] ) && ! empty( $softone_debug_session_capture['login_response'] ) ) {
                $session_result['login_response'] = $softone_debug_session_capture['login_response'];
            }

            if ( isset( $softone_debug_session_capture['authenticate_response'] ) && ! empty( $softone_debug_session_capture['authenticate_response'] ) ) {
                $session_result['authenticate_response'] = $softone_debug_session_capture['authenticate_response'];
            }

            if ( isset( $softone_debug_session_capture['ttl'] ) && is_numeric( $softone_debug_session_capture['ttl'] ) ) {
                $session_result['ttl'] = (int) $softone_debug_session_capture['ttl'];
            }

            if ( function_exists( 'get_option' ) && class_exists( 'Softone_API_Client' ) ) {
                $meta = get_option( Softone_API_Client::OPTION_CLIENT_ID_META_KEY, array() );

                if ( is_array( $meta ) ) {
                    $session_result['cache_summary'] = softone_debug_summarise_session_cache( $meta, isset( $session_result['ttl'] ) ? $session_result['ttl'] : null );
                }
            } elseif ( isset( $session_result['ttl'] ) ) {
                $session_result['cache_summary'] = softone_debug_summarise_session_cache( array(), $session_result['ttl'] );
            }

            $action_messages[] = 'SoftOne session refreshed successfully using the plugin session bootstrap.';
        } catch ( Exception $exception ) {
            $session_result['status'] = 'error';
            $session_result['error']  = $exception->getMessage();
            $action_messages[]        = 'Error refreshing SoftOne session: ' . $exception->getMessage();
        } finally {
            if ( $filter_added && function_exists( 'remove_filter' ) ) {
                remove_filter( 'softone_wc_integration_client_ttl', 'softone_debug_capture_session_data', 10 );
            }
        }
        break;

case 'run_sync':
        if ( ! $item_sync || ! ( $trace instanceof Softone_Process_Trace ) ) {
            $sync_result['status'] = 'error';
            $sync_result['error']  = 'Item sync could not be executed because the synchroniser is unavailable.';
            $action_messages[]     = 'Item sync is unavailable in this environment.';
            break;
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $force_full = ! empty( $sync_options['force_full_import'] );
        $force_tax  = ! empty( $sync_options['force_taxonomy_refresh'] );
        $started_at = time();

        if ( $trace instanceof Softone_Process_Trace ) {
            $trace->add_event(
                'note',
                'debug_sync_start',
                'Starting Softone item sync via debug tool.',
                array(
                    'force_full_import'      => $force_full,
                    'force_taxonomy_refresh' => $force_tax,
                )
            );
        }

        try {
            $result      = $item_sync->sync( $force_full, $force_tax );
            $finished_at = time();
            $summary     = softone_debug_prepare_sync_summary( $result, $started_at, $finished_at, true );

            if ( $trace instanceof Softone_Process_Trace ) {
                $trace->add_event( 'note', 'debug_sync_completed', 'Softone item sync completed via debug tool.', $summary );
            }

            $sync_result = array(
                'status'  => 'success',
                'result'  => $result,
                'summary' => $summary,
                'options' => array(
                    'force_full_import'      => $force_full,
                    'force_taxonomy_refresh' => $force_tax,
                ),
            );

            $processed = isset( $summary['processed'] ) ? (int) $summary['processed'] : 0;
            $action_messages[] = sprintf( 'Item sync completed successfully. Processed %d items.', $processed );
        } catch ( Exception $exception ) {
            $finished_at = time();
            $summary     = softone_debug_prepare_sync_summary( array(), $started_at, $finished_at, false );

            if ( $trace instanceof Softone_Process_Trace ) {
                $trace->add_event(
                    'note',
                    'debug_sync_failed',
                    'Softone item sync failed via debug tool.',
                    array_merge(
                        $summary,
                        array( 'error' => $exception->getMessage() )
                    ),
                    'error'
                );
            }

            $sync_result = array(
                'status'  => 'error',
                'error'   => $exception->getMessage(),
                'summary' => $summary,
                'options' => array(
                    'force_full_import'      => $force_full,
                    'force_taxonomy_refresh' => $force_tax,
                ),
            );

            $action_messages[] = 'Item sync failed: ' . $exception->getMessage();
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
$variation_diagnostics = softone_debug_collect_variation_diagnostics( $trace_entries );
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

$output = '<div class="table-wrapper"><table class="trace-table">';
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

$output .= '</tbody></table></div>';

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

/**
 * Summarise the product import and variation aggregation flow.
 *
 * @return array[]
 */
function softone_debug_collect_variation_flow() {
$flow = array(
array(
'stage'      => '1. Fetch SoftOne inventory',
'single'     => 'Each synchronisation run requests the configured stored SQL (default "getItems") and treats every row as a stand-alone WooCommerce product update.',
'variation'  => 'SoftOne material identifiers and related item tokens are captured for later processing while the product is still simple.',
'references' => 'Softone_API_Client::sql_data()',
),
array(
'stage'      => '2. Map catalogue data onto the product',
'single'     => 'Taxonomies such as colour, size, and brand are ensured and assigned to the imported product.',
'variation'  => 'The synchroniser records attribute values so that they can be reused when building variations after the single product import completes.',
'references' => 'Softone_Item_Sync::prepare_attribute_assignments()',
),
array(
'stage'      => '3. Queue related items for variation sync',
'single'     => 'Once the product is updated, the import flow concludes the single-product stage for that SoftOne material.',
'variation'  => 'Any related SoftOne items identified in the payload are queued for colour aggregation after the batch finishes so the base product can persist before variation logic runs.',
'references' => 'Softone_Item_Sync::queue_colour_variation_sync()',
),
array(
'stage'      => '4. Ensure WooCommerce variations',
'single'     => 'The base product remains published as a simple record and retains its own SKU and stock configuration.',
'variation'  => 'Queued items trigger creation or updates of WC_Product_Variation instances, aligning colour attributes, prices, stock, and generating unique SKUs when duplicates are detected.',
'references' => 'Softone_Item_Sync::ensure_colour_variation()',
),
);

if ( class_exists( 'Softone_Item_Sync' ) ) {
if ( defined( 'Softone_Item_Sync::META_MTRL' ) ) {
$meta_key = Softone_Item_Sync::META_MTRL;
$flow[0]['variation'] .= ' Material IDs are stored on the product using the post meta key "' . $meta_key . '".';
}

if ( method_exists( 'Softone_Item_Sync', 'process_pending_colour_variation_syncs' ) ) {
$flow[2]['references'] .= ' & Softone_Item_Sync::process_pending_colour_variation_syncs()';
}

if ( method_exists( 'Softone_Item_Sync', 'sync_related_colour_variations' ) ) {
$flow[3]['references'] .= ' / Softone_Item_Sync::sync_related_colour_variations()';
}
}

return $flow;
}

function softone_debug_collect_variation_diagnostics( array $entries ) {
$diagnostics = array(
'entries_recorded'      => ! empty( $entries ),
'queue_requests'        => 0,
'queues_processed'      => 0,
'queue_size_total'      => 0,
'sync_runs'             => 0,
'variations_created'    => 0,
'variations_updated'    => 0,
'variations_saved'      => 0,
'related_token_total'   => 0,
'sku_adjustments'       => array(),
'sku_cleared'           => array(),
);

if ( empty( $entries ) ) {
$diagnostics['has_variation_events'] = false;
$diagnostics['average_queue_size']   = 0.0;

return $diagnostics;
}

foreach ( $entries as $entry ) {
if ( ! is_array( $entry ) ) {
continue;
}

$message = isset( $entry['message'] ) ? (string) $entry['message'] : '';
$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

switch ( $message ) {
case 'Queued colour variation synchronisation request.':
$diagnostics['queue_requests']++;

if ( isset( $context['related_item_mtrls'] ) && is_array( $context['related_item_mtrls'] ) ) {
$diagnostics['related_token_total'] += count( array_filter( array_map( 'strval', $context['related_item_mtrls'] ) ) );
}

break;

case 'Processing queued colour variation synchronisation requests.':
$diagnostics['queues_processed']++;

if ( isset( $context['queue_size'] ) && is_numeric( $context['queue_size'] ) ) {
$diagnostics['queue_size_total'] += (int) $context['queue_size'];
}

break;

case 'Synchronising related colour variations.':
$diagnostics['sync_runs']++;
break;

case 'Creating new colour variation for product.':
$diagnostics['variations_created']++;
break;

case 'Updating existing colour variation.':
$diagnostics['variations_updated']++;
break;

case 'Saved colour variation for product.':
$diagnostics['variations_saved']++;
break;

case 'Adjusted colour variation SKU to avoid duplication.':
$diagnostics['sku_adjustments'][] = array(
'product_id'   => isset( $context['product_id'] ) ? (int) $context['product_id'] : 0,
'variation_id' => isset( $context['variation_id'] ) ? (int) $context['variation_id'] : 0,
'requested_sku'=> isset( $context['requested_sku'] ) ? (string) $context['requested_sku'] : '',
'assigned_sku' => isset( $context['assigned_sku'] ) ? (string) $context['assigned_sku'] : '',
);
break;

case 'Unable to assign unique SKU to colour variation; leaving blank to avoid duplication.':
$suffix_parts = array();

if ( isset( $context['suffix_parts'] ) && is_array( $context['suffix_parts'] ) ) {
$suffix_parts = array_values( array_filter( array_map( 'strval', $context['suffix_parts'] ) ) );
}

$diagnostics['sku_cleared'][] = array(
'product_id'   => isset( $context['product_id'] ) ? (int) $context['product_id'] : 0,
'variation_id' => isset( $context['variation_id'] ) ? (int) $context['variation_id'] : 0,
'requested_sku'=> isset( $context['requested_sku'] ) ? (string) $context['requested_sku'] : '',
'suffix'       => implode( '-', $suffix_parts ),
);
break;
}
}

if ( $diagnostics['queues_processed'] > 0 && $diagnostics['queue_size_total'] > 0 ) {
$diagnostics['average_queue_size'] = $diagnostics['queue_size_total'] / $diagnostics['queues_processed'];
} else {
$diagnostics['average_queue_size'] = 0.0;
}

$diagnostics['has_variation_events'] = (
$diagnostics['queue_requests'] > 0 ||
$diagnostics['queues_processed'] > 0 ||
$diagnostics['sync_runs'] > 0 ||
$diagnostics['variations_created'] > 0 ||
$diagnostics['variations_updated'] > 0 ||
$diagnostics['variations_saved'] > 0 ||
! empty( $diagnostics['sku_adjustments'] ) ||
! empty( $diagnostics['sku_cleared'] )
);

return $diagnostics;
}

function softone_debug_render_variation_diagnostics( array $diagnostics ) {
if ( empty( $diagnostics ) || empty( $diagnostics['entries_recorded'] ) ) {
return '<p>No trace entries are available. Run the item sync above to capture diagnostics.</p>';
}

if ( empty( $diagnostics['has_variation_events'] ) ) {
return '<p>No colour variation events were recorded in the latest sync trace.</p>';
}

$summary_items = array();

if ( ! empty( $diagnostics['queue_requests'] ) ) {
$summary_items['Queued variation requests'] = softone_debug_format_number( (int) $diagnostics['queue_requests'] );
}

if ( ! empty( $diagnostics['queues_processed'] ) ) {
$summary_items['Processed queues'] = softone_debug_format_number( (int) $diagnostics['queues_processed'] );
}

if ( ! empty( $diagnostics['sync_runs'] ) ) {
$summary_items['Colour sync batches'] = softone_debug_format_number( (int) $diagnostics['sync_runs'] );
}

if ( ! empty( $diagnostics['variations_created'] ) ) {
$summary_items['Variations created'] = softone_debug_format_number( (int) $diagnostics['variations_created'] );
}

if ( ! empty( $diagnostics['variations_updated'] ) ) {
$summary_items['Variations updated'] = softone_debug_format_number( (int) $diagnostics['variations_updated'] );
}

if ( ! empty( $diagnostics['variations_saved'] ) ) {
$summary_items['Variations saved'] = softone_debug_format_number( (int) $diagnostics['variations_saved'] );
}

if ( ! empty( $diagnostics['related_token_total'] ) ) {
$summary_items['Related item tokens captured'] = softone_debug_format_number( (int) $diagnostics['related_token_total'] );
}

if ( ! empty( $diagnostics['sku_adjustments'] ) ) {
$summary_items['SKU adjustments applied'] = softone_debug_format_number( count( $diagnostics['sku_adjustments'] ) );
}

if ( ! empty( $diagnostics['sku_cleared'] ) ) {
$summary_items['Variations cleared to avoid duplicates'] = softone_debug_format_number( count( $diagnostics['sku_cleared'] ) );
}

if ( ! empty( $diagnostics['average_queue_size'] ) ) {
$average_queue = (float) $diagnostics['average_queue_size'];

if ( function_exists( 'number_format_i18n' ) ) {
$summary_items['Average queue size'] = number_format_i18n( $average_queue, 2 );
} else {
$summary_items['Average queue size'] = number_format( $average_queue, 2 );
}
}

$output = '';

if ( ! empty( $summary_items ) ) {
$output .= '<ul>';

foreach ( $summary_items as $label => $value ) {
$output .= '<li><strong>' . softone_debug_escape( $label ) . ':</strong> ' . softone_debug_escape( $value ) . '</li>';
}

$output .= '</ul>';
}

if ( ! empty( $diagnostics['sku_adjustments'] ) ) {
$output .= '<h3>Adjusted variation SKUs</h3>';
$output .= '<div class="table-wrapper"><table class="rows-table"><thead><tr><th>Product ID</th><th>Variation ID</th><th>Requested SKU</th><th>Assigned SKU</th></tr></thead><tbody>';

foreach ( $diagnostics['sku_adjustments'] as $adjustment ) {
$product_id   = isset( $adjustment['product_id'] ) ? (int) $adjustment['product_id'] : 0;
$variation_id = isset( $adjustment['variation_id'] ) ? (int) $adjustment['variation_id'] : 0;
$requested    = isset( $adjustment['requested_sku'] ) ? (string) $adjustment['requested_sku'] : '';
$assigned     = isset( $adjustment['assigned_sku'] ) ? (string) $adjustment['assigned_sku'] : '';

$output .= '<tr>';
$output .= '<td>' . softone_debug_escape( softone_debug_format_number( $product_id ) ) . '</td>';
$output .= '<td>' . softone_debug_escape( softone_debug_format_number( $variation_id ) ) . '</td>';
$output .= '<td>' . softone_debug_escape( $requested ) . '</td>';
$output .= '<td>' . softone_debug_escape( $assigned ) . '</td>';
$output .= '</tr>';
}

$output .= '</tbody></table></div>';
}

if ( ! empty( $diagnostics['sku_cleared'] ) ) {
$output .= '<h3>Variations cleared to prevent duplicate SKUs</h3>';
$output .= '<div class="table-wrapper"><table class="rows-table"><thead><tr><th>Product ID</th><th>Variation ID</th><th>Requested SKU</th><th>Generated suffix</th></tr></thead><tbody>';

foreach ( $diagnostics['sku_cleared'] as $cleared ) {
$product_id   = isset( $cleared['product_id'] ) ? (int) $cleared['product_id'] : 0;
$variation_id = isset( $cleared['variation_id'] ) ? (int) $cleared['variation_id'] : 0;
$requested    = isset( $cleared['requested_sku'] ) ? (string) $cleared['requested_sku'] : '';
$suffix       = isset( $cleared['suffix'] ) ? (string) $cleared['suffix'] : '';

if ( '' === $suffix ) {
$suffix = '—';
}

$output .= '<tr>';
$output .= '<td>' . softone_debug_escape( softone_debug_format_number( $product_id ) ) . '</td>';
$output .= '<td>' . softone_debug_escape( softone_debug_format_number( $variation_id ) ) . '</td>';
$output .= '<td>' . softone_debug_escape( $requested ) . '</td>';
$output .= '<td>' . softone_debug_escape( $suffix ) . '</td>';
$output .= '</tr>';
}

$output .= '</tbody></table></div>';
}

if ( '' === $output ) {
return '<p>No colour variation diagnostics were generated for the latest sync run.</p>';
}

return $output;
}

/**
 * Render a SqlData table cell with improved formatting.
 *
 * @param mixed $value Value to display.
 *
 * @return string
 */
function softone_debug_render_sql_cell( $value ) {
if ( is_scalar( $value ) ) {
$string    = (string) $value;
$formatted = softone_debug_pretty_json( $string );

if ( $formatted !== $string ) {
return '<pre class="cell-pre">' . softone_debug_escape( $formatted ) . '</pre>';
}

if ( false !== strpos( $string, "\n" ) ) {
return '<pre class="cell-pre">' . softone_debug_escape( $string ) . '</pre>';
}

return '<span class="cell-text">' . softone_debug_escape( $string ) . '</span>';
}

if ( is_array( $value ) || is_object( $value ) ) {
return '<pre class="cell-pre">' . softone_debug_escape( softone_debug_pretty_json( $value ) ) . '</pre>';
}

return '<span class="cell-text">' . softone_debug_escape( (string) $value ) . '</span>';
}

$limit_rows = isset( $inputs['limit_rows'] ) ? (int) $inputs['limit_rows'] : 10;
$sql_rows   = array();

if ( isset( $sql_result['response']['rows'] ) && is_array( $sql_result['response']['rows'] ) ) {
$sql_rows = $sql_result['response']['rows'];
}

if ( $limit_rows > 0 && count( $sql_rows ) > $limit_rows ) {
$sql_rows = array_slice( $sql_rows, 0, $limit_rows );
}

$sync_summary_rows = array();

if ( ! empty( $sync_result['summary'] ) && is_array( $sync_result['summary'] ) ) {
    $options            = isset( $sync_result['options'] ) && is_array( $sync_result['options'] ) ? $sync_result['options'] : array();
    $sync_summary_rows  = softone_debug_prepare_sync_display_rows( $sync_result['summary'], $options );
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

.table-wrapper {
overflow-x: auto;
margin: 0 -0.5rem;
padding: 0.5rem;
border-radius: 6px;
width: 100%;
}

.table-wrapper table {
width: 100%;
border-collapse: collapse;
font-size: 0.95rem;
min-width: 520px;
}

table.settings-table th,
table.settings-table td,
table.trace-table th,
table.trace-table td,
table.rows-table th,
table.rows-table td,
table.flow-table th,
table.flow-table td {
border: 1px solid #e2e4e7;
padding: 0.65rem;
vertical-align: top;
word-break: break-word;
}

table.settings-table th {
background: #f6f7f7;
width: 32%;
font-weight: 600;
}

table.flow-table thead {
background: #f6f7f7;
}

table.flow-table th {
width: 18%;
font-weight: 600;
}

table.flow-table td {
background: #fff;
}

table.rows-table thead {
background: #f6f7f7;
}

.cell-text {
display: block;
}

form .field {
display: flex;
flex-direction: column;
margin-bottom: 1rem;
}

form .field.checkbox-field {
flex-direction: row;
align-items: center;
}

form .field.checkbox-field label {
font-weight: 400;
margin-bottom: 0;
display: flex;
align-items: center;
gap: 0.4rem;
}

form .field.checkbox-field input[type="checkbox"] {
margin: 0;
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

pre.cell-pre {
margin: 0;
background: transparent;
padding: 0;
white-space: pre-wrap;
word-break: break-word;
}

.trace-table pre {
margin: 0;
white-space: pre-wrap;
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

.table-wrapper {
margin: 0 -0.75rem;
padding: 0.5rem 0.75rem;
}

.table-wrapper table {
min-width: 100%;
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
<div class="table-wrapper">
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
</div>
</section>

<section>
<h2>Connection Settings Overview</h2>
<?php if ( empty( $settings_summary ) ) : ?>
<p>The plugin settings could not be loaded. Verify that the plugin is active and configured.</p>
<?php else : ?>
<div class="table-wrapper">
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
</div>
<?php endif; ?>
</section>

<section>
<h2>Product import &amp; variation flow</h2>
<p>This flow illustrates how every SoftOne item is first treated as a single product before any variation logic runs, making it easier to diagnose where a particular item sits in the pipeline.</p>
<?php if ( ! empty( $variation_flow ) ) : ?>
<div class="table-wrapper">
<table class="flow-table">
<thead>
<tr>
<th>Stage</th>
<th>Single product handling</th>
<th>Variation preparation</th>
<th>Key references</th>
</tr>
</thead>
<tbody>
<?php foreach ( $variation_flow as $step ) : ?>
<tr>
<td><?php echo softone_debug_escape( $step['stage'] ); ?></td>
<td><?php echo softone_debug_escape( $step['single'] ); ?></td>
<td><?php echo softone_debug_escape( $step['variation'] ); ?></td>
<td><?php echo softone_debug_escape( $step['references'] ); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else : ?>
<p>Variation flow details are unavailable because the synchroniser class could not be loaded.</p>
<?php endif; ?>
</section>

<section>
<h2>Variation diagnostics</h2>
<p>Review queued batches, creation counts, and SKU adjustments captured during the latest sync trace.</p>
<?php echo softone_debug_render_variation_diagnostics( $variation_diagnostics ); ?>
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
<form method="post" class="sync-form">
<?php if ( function_exists( 'wp_nonce_field' ) ) { wp_nonce_field( 'softone_debug_action', 'softone_debug_nonce' ); } ?>
<input type="hidden" name="softone_debug_action" value="run_sync" />
<div class="field checkbox-field">
<label>
<input type="checkbox" name="force_full_import" value="1"<?php echo softone_debug_checked_attribute( $sync_options['force_full_import'] ); ?> />
Force full import (ignore delta window)
</label>
</div>
<div class="field checkbox-field">
<label>
<input type="checkbox" name="force_taxonomy_refresh" value="1"<?php echo softone_debug_checked_attribute( $sync_options['force_taxonomy_refresh'] ); ?> />
Force taxonomy refresh
</label>
</div>
<button type="submit">Run Item Sync Trace</button>
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

<?php if ( ! empty( $sync_result ) ) : ?>
<section>
<h2>Item Sync Result</h2>
<?php if ( 'success' === ( isset( $sync_result['status'] ) ? $sync_result['status'] : '' ) ) : ?>
    <?php if ( ! empty( $sync_summary_rows ) ) : ?>
    <div class="table-wrapper">
    <table class="settings-table">
    <tbody>
    <?php foreach ( $sync_summary_rows as $label => $value ) : ?>
    <tr>
    <th><?php echo softone_debug_escape( $label ); ?></th>
    <td><?php echo softone_debug_escape( $value ); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php echo softone_debug_render_response_block( 'Raw Sync Result', isset( $sync_result['result'] ) ? $sync_result['result'] : array() ); ?>
<?php else : ?>
    <div class="notice error">
        <ul><li><?php echo softone_debug_escape( isset( $sync_result['error'] ) ? $sync_result['error'] : 'Item sync failed.' ); ?></li></ul>
    </div>
    <?php if ( ! empty( $sync_summary_rows ) ) : ?>
    <div class="table-wrapper">
    <table class="settings-table">
    <tbody>
    <?php foreach ( $sync_summary_rows as $label => $value ) : ?>
    <tr>
    <th><?php echo softone_debug_escape( $label ); ?></th>
    <td><?php echo softone_debug_escape( $value ); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    <?php endif; ?>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ( ! empty( $session_result ) ) : ?>
<section>
<h2>Session Debug</h2>
<?php if ( 'success' === ( isset( $session_result['status'] ) ? $session_result['status'] : '' ) ) : ?>
    <p><strong>Authenticated Client ID:</strong> <?php echo softone_debug_escape( softone_debug_mask_value( isset( $session_result['client_id'] ) ? $session_result['client_id'] : '' ) ); ?></p>
    <?php
    if ( isset( $session_result['ttl'] ) && $session_result['ttl'] > 0 ) {
        echo '<p><strong>Resolved TTL:</strong> ' . softone_debug_escape( (string) $session_result['ttl'] ) . ' seconds</p>';
    }

    echo softone_debug_render_response_block( 'Session Cache Summary', isset( $session_result['cache_summary'] ) ? $session_result['cache_summary'] : array() );
    ?>
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
<div class="table-wrapper">
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
<td><?php echo softone_debug_render_sql_cell( $value ); ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
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
