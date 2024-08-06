<?php
/**
 * Handles interactions with the Softone API.
 */
class Softone_API {

    private $endpoint = 'http://ptkids.oncloud.gr/s1services';
    private $username;
    private $password;
    private $client_id;

    public function __construct() {
        $this->username = get_option('softone_api_username');
        $this->password = get_option('softone_api_password');
        $this->client_id = get_option('softone_client_id');
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
        $response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode(array_map('sanitize_text_field', $data)),
            'headers' => ['Content-Type' => 'application/json']
        ]);

        if (is_wp_error($response)) {
            softone_log($service, 'API request failed: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!$body['success']) {
            softone_log($service, 'API request failed: ' . wp_json_encode($body));
            return false;
        }

        return $body;
    }

    /**
     * Logs into the Softone API and stores the client ID.
     *
     * @return bool True if login was successful, false otherwise.
     */
    public function login() {
        $response = $this->request('login', [
            'username' => sanitize_text_field($this->username),
            'password' => sanitize_text_field($this->password),
            'appId' => 1000
        ]);
        if ($response) {
            $this->client_id = sanitize_text_field($response['clientID']);
            update_option('softone_client_id', $this->client_id);
            return true;
        }
        return false;
    }

    /**
     * Retrieves customers from the Softone API.
     *
     * @return array|bool The customer data or false on failure.
     */
    public function get_customers() {
        if (!$this->client_id) {
            $this->login();
        }
        $response = $this->request('SqlData', [
            'clientid' => sanitize_text_field($this->client_id),
            'appId' => 1000,
            'SqlName' => 'getCustomers'
        ]);
        return $response ? $response['rows'] : false;
    }

    /**
     * Retrieves products from the Softone API.
     *
     * @return array|bool The product data or false on failure.
     */
    public function get_products() {
        if (!$this->client_id) {
            $this->login();
        }
        $response = $this->request('SqlData', [
            'clientid' => sanitize_text_field($this->client_id),
            'appId' => 1000,
            'SqlName' => 'getItems',
            'pMins' => 99999
        ]);
        return $response ? $response['rows'] : false;
    }

    /**
     * Creates an order in the Softone API.
     *
     * @param WC_Order $order The WooCommerce order.
     * @return mixed The response from the API.
     */
    public function create_order($order) {
        if (!$this->client_id) {
            $this->login();
        }
        $data = [
            'SALDOC' => [[
                'SERIES' => 3000,
                'TRDR' => 2937, // Example customer ID
                'VARCHAR01' => $order->get_id(),
                'TRNDATE' => $order->get_date_created()->date('Y-m-d H:i:s'),
                'COMMENTS' => sanitize_text_field($order->get_customer_note())
            ]],
            'MTRDOC' => [[
                'WHOUSE' => 101
            ]],
            'ITELINES' => []
        ];

        foreach ($order->get_items() as $item_id => $item) {
            $data['ITELINES'][] = [
                'MTRL' => sanitize_text_field($item->get_product_id()),
                'QTY1' => sanitize_text_field($item->get_quantity()),
                'COMMENTS1' => sanitize_text_field($item->get_name())
            ];
        }

        $response = $this->request('setData', [
            'clientID' => sanitize_text_field($this->client_id),
            'appID' => 1000,
            'object' => 'SALDOC',
            'data' => $data
        ]);
        return $response;
    }
}