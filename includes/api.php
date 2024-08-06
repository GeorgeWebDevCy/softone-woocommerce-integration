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
    }

    /**
     * Logs in to the Softone API and stores the session.
     */
    private function login() {
        $response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode([
                'service' => 'Login',
                'username' => sanitize_text_field($this->username),
                'password' => sanitize_text_field($this->password),
                'clientID' => sanitize_text_field($this->client_id),
            ]),
            'headers' => ['Content-Type' => 'application/json']
        ]);

        if (is_wp_error($response)) {
            softone_log('Login', 'Login request failed: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['success']) && $body['success']) {
            $this->session = $body['session'];
            update_option('softone_api_session', $this->session);
            return true;
        } else {
            softone_log('Login', 'Login failed: ' . json_encode($body));
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
        // Ensure we are logged in
        if (!$this->session && !$this->login()) {
            return false;
        }

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

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Check if session expired and re-login if needed
        if (isset($body['errorcode']) && $body['errorcode'] == -1 && $body['error'] == 'Invalid request. Please login first') {
            if ($this->login()) {
                // Retry the request with new session
                $data['session'] = $this->session;
                $response = wp_remote_post($this->endpoint, [
                    'body' => wp_json_encode(array_map('sanitize_text_field', $data)),
                    'headers' => ['Content-Type' => 'application/json']
                ]);

                if (is_wp_error($response)) {
                    softone_log($service, 'API request failed: ' . $response->get_error_message());
                    return false;
                }

                $body = json_decode(wp_remote_retrieve_body($response), true);
            } else {
                return false;
            }
        }

        if (!isset($body['success']) || !$body['success']) {
            softone_log($service, 'API request failed: ' . json_encode($body));
            return false;
        }

        return $body;
    }

    /**
     * Fetches customers from the Softone API.
     *
     * @return array|false The customers data or false on failure.
     */
    public function get_customers() {
        return $this->request('GetCustomers', []);
    }

    /**
     * Fetches products from the Softone API.
     *
     * @return array|false The products data or false on failure.
     */
    public function get_products() {
        return $this->request('GetProducts', []);
    }

    /**
     * Creates an order in the Softone API.
     *
     * @param WC_Order $order The WooCommerce order.
     * @return bool True on success, false on failure.
     */
    public function create_order($order) {
        $order_data = [
            // Map WooCommerce order data to Softone order data format
        ];
        return $this->request('CreateOrder', $order_data);
    }
}
?>
