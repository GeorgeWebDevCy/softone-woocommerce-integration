<?php
/**
 * Handles interactions with the Softone API.
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

        // Perform login and authentication
        $this->login_and_authenticate();
    }

    /**
     * Logs in to the Softone API and authenticates.
     */
    private function login_and_authenticate() {
        // Log credentials used for debugging
        softone_log('Login', 'Attempting login with username: ' . $this->username . ', password: ' . $this->password);
    
        // Login
        $login_response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode([
                'service' => 'login',
                'username' => sanitize_text_field($this->username),
                'password' => sanitize_text_field($this->password),
                'appId' => 1000
            ]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
    
        if (is_wp_error($login_response)) {
            softone_log('Login', 'Login request failed: ' . $login_response->get_error_message());
            return false;
        }
    
        $login_body = wp_remote_retrieve_body($login_response);
        if (!$login_body) {
            softone_log('Login', 'Login failed: Empty response body');
            return false;
        }
    
        // Log the raw response body for debugging
        softone_log('Login', 'Raw response body: ' . $login_body);
    
        $login_body = mb_convert_encoding($login_body, 'UTF-8', 'UTF-8');
        $login_data = json_decode($login_body, true);
    
        if (json_last_error() !== JSON_ERROR_NONE) {
            softone_log('Login', 'Login failed: Invalid JSON response - ' . json_last_error_msg());
            return false;
        }
    
        if (isset($login_data['success']) && $login_data['success']) {
            $this->client_id = $login_data['clientID'];
            update_option('softone_client_id', $this->client_id);
            softone_log('Login', 'Login successful');
        } else {
            softone_log('Login', 'Login failed: ' . json_encode($login_data));
            return false;
        }
    
        // Authenticate
        $auth_response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode([
                'service' => 'authenticate',
                'clientID' => sanitize_text_field($this->client_id),
                'company' => 10,
                'branch' => 101,
                'module' => 0,
                'refid' => 1000
            ]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
    
        if (is_wp_error($auth_response)) {
            softone_log('Authenticate', 'Authenticate request failed: ' . $auth_response->get_error_message());
            return false;
        }
    
        $auth_body = wp_remote_retrieve_body($auth_response);
        if (!$auth_body) {
            softone_log('Authenticate', 'Authenticate failed: Empty response body');
            return false;
        }
    
        softone_log('Authenticate', 'Raw response body: ' . $auth_body);
    
        $auth_body = mb_convert_encoding($auth_body, 'UTF-8', 'UTF-8');
        $auth_data = json_decode($auth_body, true);
    
        if (json_last_error() !== JSON_ERROR_NONE) {
            softone_log('Authenticate', 'Authenticate failed: Invalid JSON response - ' . json_last_error_msg());
            return false;
        }
    
        if (isset($auth_data['success']) && $auth_data['success']) {
            $this->session = $auth_data['clientID'];
            update_option('softone_api_session', $this->session);
            softone_log('Authenticate', 'Authenticate successful');
            return true;
        } else {
            softone_log('Authenticate', 'Authenticate failed: ' . json_encode($auth_data));
            return false;
        }
    }
    

    /**
     * Makes a request to the Softone API.
     *
     * @param string $service The service to call.
     * @param array $data The data to send.
     * @return mixed The response from the API.
     */
    private function request($service, $data) {
        $data['service'] = sanitize_text_field($service);
        $data['session'] = $this->session;

        $response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode(array_map('sanitize_text_field', $data)),
            'headers' => ['Content-Type' => 'application/json']
        ]);

        if (is_wp_error($response)) {
            softone_log($service, 'API request failed: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            softone_log($service, 'API request failed: Empty response body');
            return false;
        }

        // Ensure the response is properly encoded in UTF-8
        $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            softone_log($service, 'API request failed: Invalid JSON response - ' . json_last_error_msg());
            return false;
        }

        if (!isset($data['success']) || !$data['success']) {
            softone_log($service, 'API request failed: ' . json_encode($data));
            return false;
        }

        return $data;
    }

    /**
     * Fetches customers from the Softone API.
     *
     * @return array|false The customers data or false on failure.
     */
    public function get_customers() {
        return $this->request('SqlData', [
            'clientid' => $this->session,
            'appId' => 1000,
            'SqlName' => 'getCustomers'
        ]);
    }

    /**
     * Fetches products from the Softone API.
     *
     * @return array|false The products data or false on failure.
     */
    public function get_products() {
        return $this->request('SqlData', [
            'clientid' => $this->session,
            'appId' => 1000,
            'SqlName' => 'getItems'
        ]);
    }

    /**
     * Creates an order in the Softone API.
     *
     * @param WC_Order $order The WooCommerce order.
     * @return bool True on success, false on failure.
     */
    public function create_order($order) {
        $items = [];

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $items[] = [
                'MTRL' => $product->get_sku(),
                'QTY1' => $item->get_quantity(),
                'PRICE' => $item->get_total(),
            ];
        }

        // Capture payment method
        $payment_method = $order->get_payment_method(); // e.g., 'cod', 'paypal', etc.
        $payment_method_title = $order->get_payment_method_title(); // e.g., 'Cash on Delivery', 'PayPal'

        // Include payment method in comments
        $order_comments = $order->get_customer_note();
        $order_comments .= "\nPayment Method: " . $payment_method_title;

        $order_data = [
            'SALDOC' => [
                [
                    'SERIES' => 3000, // This should be defined based on your Softone settings
                    'TRDR' => $order->get_billing_email(), // Map this appropriately
                    'TRNDATE' => gmdate('Y-m-d H:i:s', strtotime($order->get_date_created())),
                    'COMMENTS' => $order_comments, // Add payment method to the comments
                ]
            ],
            'ITELINES' => $items
        ];

        $response = $this->request('setData', [
            'clientID' => $this->session,
            'appID' => 1000,
            'object' => 'SALDOC',
            'data' => $order_data
        ]);

        if ($response) {
            softone_log('create_order', 'Order sent to Softone successfully: ' . $order->get_id());
            return true;
        } else {
            softone_log('create_order', 'Failed to send order to Softone: ' . $order->get_id());
            return false;
        }
    }
}
?>