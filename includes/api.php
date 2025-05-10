<?php
/**
 * Handles interactions with the Softone API and WooCommerce product sync.
 */
class Softone_API {

    private $endpoint = 'http://ptkids.oncloud.gr/s1services';
    private $username;
    private $password;
    private $client_id;
    private $session;

    public function __construct() {
        $this->username = get_option('softone_api_username');
        $this->password = get_option('softone_api_password');
        $this->client_id = get_option('softone_client_id');
        $this->session = get_option('softone_api_session');

        $this->login_and_authenticate();
    }

    private function login_and_authenticate() {
        // Log credential presence safely
        softone_log('Login', 'Starting login process.');
        softone_log('Login', 'Username length: ' . strlen($this->username));
        softone_log('Login', 'Password length: ' . strlen($this->password));
    
        $login_payload = [
            'service' => 'login',
            'username' => sanitize_text_field($this->username),
            'password' => sanitize_text_field($this->password),
            'appId' => 1000
        ];
    
        // Log full payload for debugging (without password)
        $safe_payload = $login_payload;
        $safe_payload['password'] = str_repeat('*', strlen($login_payload['password']));
        softone_log('Login', 'Login request payload: ' . json_encode($safe_payload));
    
        // Make login request
        $login_response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode($login_payload),
            'headers' => ['Content-Type' => 'application/json']
        ]);
    
        if (is_wp_error($login_response)) {
            softone_log('Login', 'Login request failed: ' . $login_response->get_error_message());
            return false;
        }
    
        $login_body = wp_remote_retrieve_body($login_response);
        softone_log('Login', 'Raw response body: ' . $login_body);
    
        $login_data = json_decode($login_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            softone_log('Login', 'Login failed: Invalid JSON response - ' . json_last_error_msg());
            return false;
        }
    
        if (!empty($login_data['success'])) {
            $this->client_id = $login_data['clientID'];
            update_option('softone_client_id', $this->client_id);
            softone_log('Login', 'Login successful.');
        } else {
            softone_log('Login', 'Login failed: ' . json_encode($login_data));
            return false;
        }
    
        // Authenticate
        $auth_payload = [
            'service' => 'authenticate',
            'clientID' => $this->client_id,
            'company' => 10,
            'branch' => 101,
            'module' => 0,
            'refid' => 1000
        ];
    
        softone_log('Authenticate', 'Authentication request payload: ' . json_encode($auth_payload));
    
        $auth_response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode($auth_payload),
            'headers' => ['Content-Type' => 'application/json']
        ]);
    
        if (is_wp_error($auth_response)) {
            softone_log('Authenticate', 'Authenticate request failed: ' . $auth_response->get_error_message());
            return false;
        }
    
        $auth_body = wp_remote_retrieve_body($auth_response);
        softone_log('Authenticate', 'Raw response body: ' . $auth_body);
    
        $auth_data = json_decode($auth_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            softone_log('Authenticate', 'Authenticate failed: Invalid JSON response - ' . json_last_error_msg());
            return false;
        }
    
        if (!empty($auth_data['success'])) {
            $this->session = $auth_data['clientID'];
            update_option('softone_api_session', $this->session);
            softone_log('Authenticate', 'Authentication successful.');
            return true;
        } else {
            softone_log('Authenticate', 'Authentication failed: ' . json_encode($auth_data));
            return false;
        }
    }
    
    public function get_products($offset = 0, $limit = 10) {
        $response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode([
                'service' => 'SqlData',
                'clientid' => $this->session,
                'appId' => 1000,
                'SqlName' => 'getItems',
                'pMins' => 99999
            ]),
            'headers' => ['Content-Type' => 'application/json']
        ]);

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['rows']) ? array_slice($data['rows'], $offset, $limit) : [];
    }

    public function sync_product_to_woocommerce($item) {
        $sku = $item['SKU'];
        $existing_id = wc_get_product_id_by_sku($sku);

        $product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Simple();
        $product->set_name($item['DESC']);
        $product->set_regular_price($item['RETAILPRICE']);
        $product->set_sku($sku);
        $product->set_stock_quantity($item['Stock QTY']);
        $product->set_manage_stock(true);

        // Category assignment
        $cat_name = $item['COMMECATEGORY NAME'];
        $subcat_name = $item['SUBMECATEGORY NAME'];

        $cat_id = term_exists($cat_name, 'product_cat');
        if (!$cat_id) {
            $cat_id = wp_insert_term($cat_name, 'product_cat');
        }
        $cat_id = is_array($cat_id) ? $cat_id['term_id'] : $cat_id;

        $subcat_id = term_exists($subcat_name, 'product_cat');
        if (!$subcat_id) {
            $subcat_id = wp_insert_term($subcat_name, 'product_cat', ['parent' => $cat_id]);
        }
        $subcat_id = is_array($subcat_id) ? $subcat_id['term_id'] : $subcat_id;

        $product->set_category_ids([$cat_id, $subcat_id]);

        $id = $product->save();
        return $existing_id ? "Updated: $sku (ID $id)" : "Added: $sku (ID $id)";
    }
}

// AJAX handler
add_action('wp_ajax_softone_sync_products', function () {
    check_ajax_referer('softone_sync_products_nonce');

    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;

    $api = new Softone_API();
    $items = $api->get_products($offset, $limit);
    $log = [];

    foreach ($items as $item) {
        try {
            $result = $api->sync_product_to_woocommerce($item);
            $log[] = '✅ ' . $result;
        } catch (Exception $e) {
            $log[] = '❌ Error for SKU ' . $item['SKU'] . ': ' . $e->getMessage();
        }
    }

    wp_send_json([
        'message' => implode("\n", $log),
        'done' => count($items) < $limit,
        'progress' => min(100, round((($offset + count($items)) / 200) * 100))
    ]);
});
