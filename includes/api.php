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
        $this->password = get_option('softone_api_password');
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
            if (!empty($item['CCCSOCYLODES'])) {
                $desc = mb_convert_encoding($item['CCCSOCYLODES'], 'UTF-8', 'UTF-8');
                $product->set_description(wp_kses_post($desc));
            }
            if (!empty($item['REMARKS'])) {
                $short = mb_convert_encoding($item['REMARKS'], 'UTF-8', 'UTF-8');
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
            // Use human-readable brand name fields and ignore brand codes.
            foreach (['BRAND NAME','BRANDS NAME','MTRBRAND NAME','MTRBRANDS NAME'] as $bk) {
                if (!empty($item[$bk])) {
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
