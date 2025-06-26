<?php
if (!defined('ABSPATH')) exit;

class WOO_Update_API_Handler {
    private static $instance = null;
    private $api_url;
    private $api_key;
    private $cache_time;

    public static function init() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $settings = get_option('woo_update_api_settings');
        $this->api_url = $settings['api_url'] ?? '';
        $this->api_key = $settings['api_key'] ?? '';
        $this->cache_time = isset($settings['cache_time']) ? absint($settings['cache_time']) : 300; // Default 5 minutes
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
                'Content-Type' => 'application/json',
            ],
        ];

        // Use either product ID or SKU based on your API requirements
        $endpoint = add_query_arg([
            'product_id' => $product_id,
            'sku' => $sku,
        ], $this->api_url);

        $response = wp_remote_get($endpoint, $args);

        if (is_wp_error($response)) {
            error_log('External API Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['success']) || !$data['success']) {
            error_log('External API Data Error: ' . $body);
            return false;
        }

        // Cache the response
        set_transient($transient_key, $data, $this->cache_time);

        return $data;
    }

}