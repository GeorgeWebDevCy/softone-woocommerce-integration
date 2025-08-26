<?php
/**
 * Handles interactions with the Softone API and WooCommerce product sync.
 */
class Softone_API {
    private $endpoint = 'https://ptkids.oncloud.gr/s1services';
    private $username;
    private $password;
    private $client_id;
    private $session;

    public function __construct() {
        $this->username = get_option('softone_api_username');
        $encrypted      = get_option('softone_api_password');
        $this->password = $encrypted ? softone_decrypt($encrypted) : '';
        $this->client_id = get_option('softone_client_id');
        $this->session = get_option('softone_api_session');
        $expires = get_option('softone_api_session_expires');

        if (!$this->session || !$expires || $expires < time()) {
            $this->login_and_authenticate();
        }
    }

    private function login_and_authenticate() {
        softone_log('Login', 'Starting login process.');
        $login_payload = [
            'service' => 'login',
            'username' => sanitize_text_field($this->username),
            'password' => sanitize_text_field($this->password),
            'appId' => 1000
        ];
        $login_response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode($login_payload),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        $login_body = wp_remote_retrieve_body($login_response);
        $login_body = mb_convert_encoding($login_body, 'UTF-8', 'UTF-8');
        $login_body = preg_replace('/[^\x00-\x7F\xC2-\xF4][\x80-\xBF]*/', '', $login_body);
        $login_data = json_decode($login_body, true);
        if (!empty($login_data['success'])) {
            $this->client_id = $login_data['clientID'];
            update_option('softone_client_id', $this->client_id);
        }
        $auth_payload = [
            'service' => 'authenticate',
            'clientID' => $this->client_id,
            'company' => 10,
            'branch' => 101,
            'module' => 0,
            'refid' => 1000
        ];
        $auth_response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode($auth_payload),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        $auth_body = wp_remote_retrieve_body($auth_response);
        $auth_body = mb_convert_encoding($auth_body, 'UTF-8', 'UTF-8');
        $auth_body = preg_replace('/[^\x00-\x7F\xC2-\xF4][\x80-\xBF]*/', '', $auth_body);
        $auth_data = json_decode($auth_body, true);
        if (!empty($auth_data['success'])) {
            $this->session = $auth_data['clientID'];
            update_option('softone_api_session', $this->session);
            update_option('softone_api_session_expires', time() + 1800); // 30 min
        }
    }

    public function get_products($minutes = 99999) {
        $response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode([
                'service' => 'SqlData',
                'clientid' => $this->session,
                'appId' => 1000,
                'SqlName' => 'getItems',
                'pMins' => $minutes,
                'exportAllFields' => 1
            ]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        $body = wp_remote_retrieve_body($response);
        $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-7,UTF-8');
        $body = iconv('UTF-8', 'UTF-8//IGNORE', $body);
        if ($body === false) return [];
        $data = json_decode($body, true);
        return isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
    }

    public function get_customer_by_email($email) {
        $response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode([
                'service' => 'SqlData',
                'clientid' => $this->session,
                'appId' => 1000,
                'SqlName' => 'findCustomerByEmail',
                'EMAIL' => sanitize_email($email)
            ]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        $body = wp_remote_retrieve_body($response);
        $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        $body = preg_replace('/[^\x00-\x7F\xC2-\xF4][\x80-\xBF]*/', '', $body);
        $data = json_decode($body, true);
        if (!empty($data['rows'][0]['TRDR'])) {
            return intval($data['rows'][0]['TRDR']);
        }
        return 0;
    }

    private function create_customer_from_order(WC_Order $order) {
        $code = 'WEB' . ($order->get_customer_id() ?: $order->get_id());
        $payload = [
            'service' => 'setData',
            'clientID' => $this->session,
            'appID' => 1000,
            'object' => 'CUSTOMER',
            'data' => [
                'CUSTOMER' => [[
                    'CODE' => $code,
                    'NAME' => $order->get_formatted_billing_full_name(),
                    'COUNTRY' => $order->get_billing_country(),
                    'PHONE01' => $order->get_billing_phone(),
                    'EMAIL' => $order->get_billing_email(),
                    'ADDRESS' => $order->get_billing_address_1(),
                    'CITY' => $order->get_billing_city(),
                    'ZIP' => $order->get_billing_postcode(),
                    'TRDCATEGORY' => 1
                ]]
            ]
        ];
        softone_log('create_customer_from_order_request', $payload);

        $response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode($payload),
            'headers' => ['Content-Type' => 'application/json']
        ]);

        if (is_wp_error($response)) {
            softone_log('create_customer_from_order_error', $response->get_error_message());
            return 0;
        }

        $body = wp_remote_retrieve_body($response);
        $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        $body = preg_replace('/[^\x00-\x7F\xC2-\xF4][\x80-\xBF]*/', '', $body);
        $data = json_decode($body, true);

        softone_log('create_customer_from_order_response', $data);

        return !empty($data['id']) ? intval($data['id']) : 0;
    }

    private function send_salesdoc(WC_Order $order, $trdr) {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $mtrl = $product ? $product->get_meta('attribute_mtrl', true) : '';
            $items[] = [
                'MTRL' => $mtrl,
                'QTY1' => $item->get_quantity(),
                'COMMENTS1' => ''
            ];
        }
        $payload = [
            'service' => 'setData',
            'clientID' => $this->session,
            'appID' => 1000,
            'object' => 'SALDOC',
            'data' => [
                'SALDOC' => [[
                    'SERIES' => 3000,
                    'TRDR' => $trdr,
                    'VARCHAR01' => $order->get_id(),
                    'TRNDATE' => gmdate('Y-m-d H:i:s', strtotime($order->get_date_created())),
                    'COMMENTS' => $order->get_customer_note()
                ]],
                'MTRDOC' => [[ 'WHOUSE' => 101 ]],
                'ITELINES' => $items
            ]
        ];

        softone_log('send_salesdoc_request', $payload);

        $response = wp_remote_post($this->endpoint, [
            'body' => wp_json_encode($payload),
            'headers' => ['Content-Type' => 'application/json']
        ]);

        if (is_wp_error($response)) {
            softone_log('send_salesdoc_error', $response->get_error_message());
            return ['error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        $body = preg_replace('/[^\x00-\x7F\xC2-\xF4][\x80-\xBF]*/', '', $body);
        $result = json_decode($body, true);

        softone_log('send_salesdoc_response', $result);

        return $result;
    }

    public function create_order($order) {
        if (!$order instanceof WC_Order) {
            return;
        }
        $email = $order->get_billing_email();
        if (!$email) {
            return;
        }
        $trdr = $this->get_customer_by_email($email);
        if (!$trdr) {
            $trdr = $this->create_customer_from_order($order);
        }
        if ($trdr) {
            $result = $this->send_salesdoc($order, $trdr);
            softone_log('create_order', wp_json_encode($result));
        }
    }

    public function create_category_tree($names = []) {
        $parent_id = 0;
        $term_ids = [];
        foreach ($names as $name) {
            $name = sanitize_text_field(mb_convert_encoding(trim($name), 'UTF-8', 'UTF-8'));
            $term = term_exists($name, 'product_cat');
            if (!$term) {
                $term = wp_insert_term($name, 'product_cat', ['parent' => $parent_id]);
                if (is_wp_error($term)) continue;
            }
            $term_id = is_array($term) ? $term['term_id'] : $term;
            $term_ids[] = $term_id;
            $parent_id = $term_id;
        }
        return $term_ids;
    }

    private function get_product_id_by_mtrl($mtrl) {
        $products = get_posts([
            'post_type'      => 'product',
            'meta_key'       => 'attribute_mtrl',
            'meta_value'     => $mtrl,
            'fields'         => 'ids',
            'posts_per_page' => 1,
        ]);
        return !empty($products) ? (int) $products[0] : 0;
    }

    public function sync_product_to_woocommerce($item) {
        softone_log('sync_product', print_r($item, true));
        try {
            $sku = sanitize_text_field(mb_convert_encoding(trim($item['SKU']), 'UTF-8', 'UTF-8'));
            $barcode = '';
            if (!empty($item['BARCODE'])) {
                $barcode = sanitize_text_field(mb_convert_encoding(trim($item['BARCODE']), 'UTF-8', 'UTF-8'));
            } elseif (!empty($item['CODE'])) {
                $barcode = sanitize_text_field(mb_convert_encoding(trim($item['CODE']), 'UTF-8', 'UTF-8'));
            }
            $name = sanitize_text_field(mb_convert_encoding(trim($item['DESC']), 'UTF-8', 'UTF-8'));
            $price = isset($item['RETAILPRICE']) ? floatval($item['RETAILPRICE']) : 0;
            $qty = isset($item['Stock QTY']) ? intval($item['Stock QTY']) : 0;
            $existing_id = wc_get_product_id_by_sku($sku);
            $product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Simple();
            $product->set_name($name);
            $product->set_regular_price($price);
            $product->set_price($price);
            $product->set_sku($sku);
            if ($barcode && method_exists($product, 'set_global_unique_id')) {
                $product->set_global_unique_id($barcode);
            }
            $product->set_stock_quantity($qty);
            $product->set_manage_stock(true);
            if (!empty($item['Long Description']) || !empty($item['CCCSOCYLODES'])) {
                $desc = mb_convert_encoding(trim($item['Long Description'] ?? $item['CCCSOCYLODES']), 'UTF-8', 'UTF-8');
                // Softone long description -> WooCommerce product content
                $product->set_description(wp_kses_post($desc));
            }
            if (!empty($item['Short Description']) || !empty($item['CCCSOCYSHDES'])) {
                $short = mb_convert_encoding(trim($item['Short Description'] ?? $item['CCCSOCYSHDES']), 'UTF-8', 'UTF-8');
                // Softone short description -> WooCommerce short description
                $product->set_short_description(wp_kses_post($short));
            }
            $cat_path = [];
            if (!empty($item['COMMECATEGORY NAME'])) $cat_path[] = $item['COMMECATEGORY NAME'];
            if (!empty($item['SUBMECATEGORY NAME'])) $cat_path[] = $item['SUBMECATEGORY NAME'];
            if (!empty($item['UTBL03 NAME'])) $cat_path[] = $item['UTBL03 NAME'];
            $cat_ids = $this->create_category_tree($cat_path);

            $season_name = '';
            foreach (['MTRSEASON NAME', 'SEASON NAME', 'SEASON CODE_1'] as $sk) {
                if (!empty($item[$sk])) { $season_name = $item[$sk]; break; }
            }
            if ($season_name) {
                $extra_name = sanitize_text_field(mb_convert_encoding(trim($season_name), 'UTF-8', 'UTF-8'));
                $extra_term = term_exists($extra_name, 'product_cat');
                if (!$extra_term) {
                    $extra_term = wp_insert_term($extra_name, 'product_cat');
                }
                if (!is_wp_error($extra_term)) {
                    $extra_id = is_array($extra_term) ? $extra_term['term_id'] : $extra_term;
                    $cat_ids[] = $extra_id;
                }
            }
            $product->set_category_ids($cat_ids);
            $brand_name = '';
            // Use human-readable brand name fields and ignore brand codes or numeric values.
            foreach (
                ['BRAND NAME','BRANDS NAME','MTRBRAND NAME','MTRBRANDS NAME','BRAND','BRANDS','MTRBRAND','MTRBRANDS']
                as $bk
            ) {
                if (!empty($item[$bk]) && !ctype_digit(trim($item[$bk]))) {
                    $brand_name = $item[$bk];
                    break;
                }
            }
            $brand_term_id = 0;
            if ($brand_name && taxonomy_exists('product_brand')) {
                $brand_name = sanitize_text_field(mb_convert_encoding(trim($brand_name), 'UTF-8', 'UTF-8'));
                $term = term_exists($brand_name, 'product_brand');
                if (!$term) { $term = wp_insert_term($brand_name, 'product_brand'); }
                if (!is_wp_error($term)) { $brand_term_id = is_array($term) ? $term['term_id'] : $term; }
            }

            $attributes = [];
            if (!empty($item['MTRL'])) {
                $mtrl_value = sanitize_text_field(mb_convert_encoding(trim($item['MTRL']), 'UTF-8', 'UTF-8'));
                $mtrl_attr = new WC_Product_Attribute();
                $mtrl_attr->set_name('MTRL');
                $mtrl_attr->set_options([$mtrl_value]);
                $mtrl_attr->set_visible(false);
                $mtrl_attr->set_variation(false);
                $attributes[] = $mtrl_attr;
                $product->update_meta_data('attribute_mtrl', $mtrl_value);
            }
            if (!empty($item['COLOUR NAME'])) {
                $colour_attr = new WC_Product_Attribute();
                $colour_attr->set_name('Colour');
                $colour_attr->set_options([sanitize_text_field(mb_convert_encoding(trim($item['COLOUR NAME']), 'UTF-8', 'UTF-8'))]);
                $colour_attr->set_visible(true);
                $colour_attr->set_variation(false);
                $attributes[] = $colour_attr;
            }
            if (!empty($item['SIZE NAME'])) {
                $size_attr = new WC_Product_Attribute();
                $size_attr->set_name('Size');
                $size_attr->set_options([sanitize_text_field(mb_convert_encoding(trim($item['SIZE NAME']), 'UTF-8', 'UTF-8'))]);
                $size_attr->set_visible(true);
                $size_attr->set_variation(false);
                $attributes[] = $size_attr;
            }
            if (!empty($attributes)) {
                $product->set_attributes($attributes);
            }

            $related_field = '';
            if (!empty($item['Related_Item_MTRL'])) {
                $related_field = $item['Related_Item_MTRL'];
            } elseif (!empty($item['APVCODE'])) {
                $related_field = $item['APVCODE'];
            }
            if ($related_field) {
                $related_mtrl = sanitize_text_field(mb_convert_encoding(trim($related_field), 'UTF-8', 'UTF-8'));
                $related_id = $this->get_product_id_by_mtrl($related_mtrl);
                if ($related_id) {
                    $product->set_upsell_ids([$related_id]);
                }
            }

            $id = $product->save();
            if ($brand_term_id) { wp_set_object_terms($id, [$brand_term_id], 'product_brand'); }
            return ($existing_id ? "Updated" : "Added") . ": $sku (ID $id)";
        } catch (Throwable $e) {
            return "❌ Failed to sync SKU {$item['SKU']}: " . $e->getMessage();
        }
    }
}

add_action('wp_ajax_softone_sync_products', function () {
    check_ajax_referer('softone_sync_products_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        wp_die();
    }
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $limit = 20;
    $api = new Softone_API();
    $all_items = $api->get_products();
    $total = count($all_items);
    $log = [];
    $added = 0;
    $updated = 0;
    $failed = 0;
    $batch = array_slice($all_items, $offset, $limit);
    if (empty($batch)) {
        $log[] = "⚠️ No products found in batch at offset $offset.";
        wp_send_json([
            'message' => implode("\n", $log),
            'done' => true,
            'progress' => 100,
            'added' => $added,
            'updated' => $updated,
            'failed' => $failed
        ]);
    }
    foreach ($batch as $i => $item) {
        try {
            $result = $api->sync_product_to_woocommerce($item);
            if (str_starts_with($result, 'Added')) $added++;
            elseif (str_starts_with($result, 'Updated')) $updated++;
            $log[] = "✅ [$offset+$i] $result";
        } catch (Throwable $e) {
            $failed++;
            $log[] = "❌ [$offset+$i] Failed SKU: " . ($item['SKU'] ?? '[UNKNOWN]') . ' – Error: ' . $e->getMessage();
        }
    }
    $progress = min(100, round((($offset + $limit) / $total) * 100));
    $next_offset = $offset + $limit;
    wp_send_json([
        'message' => implode("\n", $log),
        'done' => $next_offset >= $total,
        'offset' => $next_offset,
        'progress' => $progress,
        'added' => $added,
        'updated' => $updated,
        'failed' => $failed
    ]);
});
