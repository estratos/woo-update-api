<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class API_Handler {
    private static $instance = null;
    private $api_url = '';
    private $api_key = '';
    private $cache_time = 300;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $settings = get_option('woo_update_api_settings', []);
        $this->api_url = $settings['api_url'] ?? '';
        $this->api_key = $settings['api_key'] ?? '';
        $this->cache_time = absint($settings['cache_time'] ?? 300);
    }

    public function get_product_data($product_id, $sku = '') {
        $transient_key = 'woo_update_api_data_' . md5($product_id . $sku);
        $cached_data = get_transient($transient_key);

        if ($cached_data !== false) {
            return $cached_data;
        }

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
        ];

        $endpoint = add_query_arg([
            'product_id' => $product_id,
            'sku' => $sku,
            'timestamp' => time(),
        ], $this->api_url);

        $response = wp_safe_remote_get($endpoint, $args);

        if (is_wp_error($response)) {
            error_log('[Woo Update API] Request failed: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[Woo Update API] JSON decode error: ' . json_last_error_msg());
            return false;
        }

        if (empty($data) || !isset($data['success'])) {
            error_log('[Woo Update API] Invalid API response: ' . print_r($data, true));
            return false;
        }

        set_transient($transient_key, $data, $this->cache_time);
        return $data;
    }
}