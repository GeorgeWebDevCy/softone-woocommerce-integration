<?php
/**
 * Displays the log page for the Softone WooCommerce Integration.
 */
function softone_logs_page() {
    // Handle clear logs request
    if (isset($_POST['clear_logs']) && check_admin_referer('softone_clear_logs_action', 'softone_clear_logs_nonce')) {
        update_option('softone_api_logs', []);
        echo '<div class="notice notice-success"><p>Logs cleared successfully.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Softone API Logs</h1>
        <form method="post">
            <?php wp_nonce_field('softone_clear_logs_action', 'softone_clear_logs_nonce'); ?>
            <input type="hidden" name="clear_logs" value="1">
            <?php submit_button('Clear Logs'); ?>
        </form>
        <table class="widefat">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Action</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $logs = get_option('softone_api_logs', []);
                if (!empty($logs)) {
                    foreach ($logs as $log) {
                        echo '<tr><td>' . esc_html($log['timestamp']) . '</td><td>' . esc_html($log['action']) . '</td><td>' . esc_html($log['message']) . '</td></tr>';
                    }
                } else {
                    echo '<tr><td colspan="3">No logs available.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>
