<?php
/**
 * Displays the log page for the Softone WooCommerce Integration.
 */
function softone_logs_page() {
    ?>
    <div class="wrap">
        <h1>Softone API Logs</h1>
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
                foreach ($logs as $log) {
                    echo '<tr><td>' . esc_html($log['timestamp']) . '</td><td>' . esc_html($log['action']) . '</td><td>' . esc_html($log['message']) . '</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}