<?php
/**
 * Logs events for the Softone WooCommerce Integration.
 *
 * @param string $action The action being logged.
 * @param string $message The log message.
 */
function softone_log($action, $message) {
    if (!is_string($message)) {
        $message = wp_json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $logs = get_option('softone_api_logs', []);
    $logs[] = [
        'timestamp' => current_time('mysql'),
        'action'   => sanitize_text_field($action),
        'message'  => sanitize_textarea_field($message),
    ];
    update_option('softone_api_logs', $logs);
}

/**
 * Writes debugging information to the log and error log when WP_DEBUG is enabled.
 *
 * @param string $action  Context for the log entry.
 * @param string $message Debug message.
 */
function softone_debug_log($action, $message) {
    if (!is_string($message)) {
        $message = wp_json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    softone_log($action, $message);
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Softone] ' . $action . ': ' . $message);
    }
}