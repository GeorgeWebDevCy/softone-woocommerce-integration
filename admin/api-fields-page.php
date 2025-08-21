<?php
/**
 * Displays the fields returned by common Softone API calls.
 */
function softone_api_fields_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'softone-woocommerce-integration'));
    }

    $calls = array(
        'getItems'     => __('Products', 'softone-woocommerce-integration'),
        'getCustomers' => __('Customers', 'softone-woocommerce-integration'),
        'getOrders'    => __('Orders', 'softone-woocommerce-integration'),
    );

    $selected = isset($_POST['softone_api_call']) ? sanitize_text_field(wp_unslash($_POST['softone_api_call'])) : 'getItems';
    $rows = array();
    $error = '';

    if (isset($_POST['softone_api_fields_nonce']) && wp_verify_nonce($_POST['softone_api_fields_nonce'], 'softone_api_fields')) {
        $payload = array(
            'service'         => 'SqlData',
            'clientid'        => get_option('softone_api_session'),
            'appId'           => 1000,
            'SqlName'         => $selected,
            'pMins'           => 99999,
            'exportAllFields' => 1,
        );
        $response = wp_remote_post('https://ptkids.oncloud.gr/s1services', array(
            'body'    => wp_json_encode($payload),
            'headers' => array('Content-Type' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
        } else {
            $body = wp_remote_retrieve_body($response);
            $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-7,UTF-8');
            $body = iconv('UTF-8', 'UTF-8//IGNORE', $body);
            $data = json_decode($body, true);
            if (!empty($data['rows']) && is_array($data['rows'])) {
                $rows = $data['rows'];
            } else {
                $error = __('No data returned.', 'softone-woocommerce-integration');
            }
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Softone API Fields', 'softone-woocommerce-integration'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('softone_api_fields', 'softone_api_fields_nonce'); ?>
            <label for="softone_api_call"><?php esc_html_e('API call', 'softone-woocommerce-integration'); ?></label>
            <select name="softone_api_call" id="softone_api_call">
                <?php foreach ($calls as $call => $label) : ?>
                    <option value="<?php echo esc_attr($call); ?>" <?php selected($selected, $call); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Fetch', 'softone-woocommerce-integration'), 'primary', 'softone_api_fetch', false); ?>
        </form>
        <?php if ($error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php elseif (!empty($rows)) : ?>
            <?php $first = $rows[0]; ?>
            <table class="widefat striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Field', 'softone-woocommerce-integration'); ?></th>
                        <th><?php esc_html_e('Sample value', 'softone-woocommerce-integration'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($first as $field => $value) : ?>
                        <tr>
                            <td><?php echo esc_html($field); ?></td>
                            <td><?php echo esc_html(is_scalar($value) ? $value : wp_json_encode($value)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
