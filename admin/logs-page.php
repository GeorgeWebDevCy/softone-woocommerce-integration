<?php
/**
 * Displays the log page for the Softone WooCommerce Integration.
 */
function softone_logs_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.', 'softone-woocommerce-integration' ) );
    }
    // Handle clear logs request
    if (isset($_POST['clear_logs']) && check_admin_referer('softone_clear_logs_action', 'softone_clear_logs_nonce')) {
        update_option('softone_api_logs', []);
        echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared successfully.', 'softone-woocommerce-integration') . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Softone API Logs', 'softone-woocommerce-integration'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('softone_clear_logs_action', 'softone_clear_logs_nonce'); ?>
            <input type="hidden" name="clear_logs" value="1">
            <?php submit_button(__('Clear Logs', 'softone-woocommerce-integration')); ?>
        </form>
        <pre id="softone-log-output" style="background:#111;color:#0f0;padding:10px;height:400px;overflow:auto;font-size:13px;"></pre>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const logEl = document.getElementById('softone-log-output');
        function fetchLogs() {
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'softone_get_logs',
                    _ajax_nonce: softone_logs.nonce
                })
            })
            .then(res => res.json())
            .then(data => {
                if (Array.isArray(data)) {
                    logEl.textContent = data.map(row => `[${row.timestamp}] ${row.action}: ${row.message}`).join('\n');
                    logEl.scrollTop = logEl.scrollHeight;
                }
            });
        }
        fetchLogs();
        setInterval(fetchLogs, 5000);
    });
    </script>
    <?php
}
?>
