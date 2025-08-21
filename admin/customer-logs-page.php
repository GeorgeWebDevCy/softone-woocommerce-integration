<?php
/**
 * Displays customer-related log entries for the Softone WooCommerce Integration.
 */
function softone_customer_logs_page() {
    $logs = get_option('softone_api_logs', []);
    $filtered = array_filter($logs, function ($log) {
        return isset($log['action']) && false !== strpos($log['action'], 'customer');
    });
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Softone Customer Logs', 'softone-woocommerce-integration') . '</h1>';
    echo '<pre style="background:#111;color:#0f0;padding:10px;height:400px;overflow:auto;font-size:13px;">';
    foreach ($filtered as $row) {
        $line = sprintf('[%s] %s: %s', $row['timestamp'], $row['action'], $row['message']);
        echo esc_html($line) . "\n";
    }
    echo '</pre>';
    echo '</div>';
}
