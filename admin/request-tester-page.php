<?php
/**
 * Provides an interface to send arbitrary HTTP requests and display the response.
 */
function softone_request_tester_page() {
    $response = null;
    $error = '';

    if (isset($_POST['softone_request_tester_nonce']) && wp_verify_nonce($_POST['softone_request_tester_nonce'], 'softone_request_tester')) {
        $url    = isset($_POST['softone_request_tester_url']) ? esc_url_raw(wp_unslash($_POST['softone_request_tester_url'])) : '';
        $method = isset($_POST['softone_request_tester_method']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['softone_request_tester_method']))) : 'GET';
        $headers_input = isset($_POST['softone_request_tester_headers']) ? wp_unslash($_POST['softone_request_tester_headers']) : '';
        $body   = isset($_POST['softone_request_tester_body']) ? wp_unslash($_POST['softone_request_tester_body']) : '';

        $headers = array();
        if (!empty($headers_input)) {
            $decoded = json_decode($headers_input, true);
            if (is_array($decoded)) {
                $headers = $decoded;
            } else {
                $error = __('Invalid headers JSON.', 'softone-woocommerce-integration');
            }
        }

        if (empty($error) && !empty($url)) {
            $args = array(
                'method'  => $method,
                'headers' => $headers,
                'body'    => $body,
                'timeout' => 20,
            );
            $response = wp_remote_request($url, $args);
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('API Request Tester', 'softone-woocommerce-integration'); ?></h1>
        <?php if ($error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('softone_request_tester', 'softone_request_tester_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="softone_request_tester_url"><?php esc_html_e('Request URL', 'softone-woocommerce-integration'); ?></label></th>
                    <td><input name="softone_request_tester_url" type="text" id="softone_request_tester_url" value="<?php echo isset($_POST['softone_request_tester_url']) ? esc_attr(wp_unslash($_POST['softone_request_tester_url'])) : ''; ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="softone_request_tester_method"><?php esc_html_e('Method', 'softone-woocommerce-integration'); ?></label></th>
                    <td>
                        <select name="softone_request_tester_method" id="softone_request_tester_method">
                            <?php
                            $methods = array('GET','POST','PUT','DELETE','PATCH','HEAD');
                            $current = isset($_POST['softone_request_tester_method']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['softone_request_tester_method']))) : 'GET';
                            foreach ($methods as $m) {
                                printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($m), selected($current, $m, false));
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="softone_request_tester_headers"><?php esc_html_e('Headers (JSON)', 'softone-woocommerce-integration'); ?></label></th>
                    <td><textarea name="softone_request_tester_headers" id="softone_request_tester_headers" rows="5" cols="50" class="large-text code"><?php echo isset($_POST['softone_request_tester_headers']) ? esc_textarea(wp_unslash($_POST['softone_request_tester_headers'])) : ''; ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="softone_request_tester_body"><?php esc_html_e('Body', 'softone-woocommerce-integration'); ?></label></th>
                    <td><textarea name="softone_request_tester_body" id="softone_request_tester_body" rows="8" cols="50" class="large-text code"><?php echo isset($_POST['softone_request_tester_body']) ? esc_textarea(wp_unslash($_POST['softone_request_tester_body'])) : ''; ?></textarea></td>
                </tr>
            </table>
            <?php submit_button(__('Send Request', 'softone-woocommerce-integration')); ?>
        </form>
        <?php if (null !== $response) : ?>
            <h2><?php esc_html_e('Response', 'softone-woocommerce-integration'); ?></h2>
            <pre style="background:#111;color:#0f0;padding:10px;overflow:auto;max-height:500px;">
<?php
if (is_wp_error($response)) {
    echo esc_html($response->get_error_message());
} else {
    echo esc_html('Status: ' . $response['response']['code'] . ' ' . $response['response']['message'] . "\n\n");
    foreach ($response['headers'] as $key => $value) {
        echo esc_html($key . ': ' . $value . "\n");
    }
    echo esc_html("\n" . $response['body']);
}
?>
            </pre>
        <?php endif; ?>
    </div>
    <?php
}
