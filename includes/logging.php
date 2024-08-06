<?php
/**
 * Logs events for the Softone WooCommerce Integration.
 *
 * @param string $action The action being logged.
 * @param string $message The log message.
 */
function softone_log($action, $message) {
    $logs = get_option('softone_api_logs', []);
    $logs[] = [
        'timestamp' => current_time('mysql'),
        'action' => sanitize_text_field($action),
        'message' => sanitize_text_field($message),
    ];
    update_option('softone_api_logs', $logs);
}