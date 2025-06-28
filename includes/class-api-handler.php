<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class API_Handler {
    private static $instance = null;
    private $api_url;
    private $api_key;
    private $cache_time;
    private $fallback_mode = false;
    private $fallback_start_time = 0;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $settings = get_option('woo_update_api_settings');
        $this->api_url = $settings['api_url'] ?? '';
        $this->api_key = $settings['api_key'] ?? '';
        $this->cache_time = isset($settings['cache_time']) ? absint($settings['cache_time']) : 300;
        
        // Check if we're in fallback mode
        $this->fallback_mode = get_transient('woo_update_api_fallback_mode');
        $this->fallback_start_time = get_transient('woo_update_api_fallback_start');
    }

    public function get_product_data($product_id, $sku = '') {
        // If in fallback mode, return false to use WooCommerce defaults
        if ($this->is_in_fallback_mode()) {
            error_log('[Woo Update API] Currently in fallback mode - using WooCommerce defaults');
            return false;
        }

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
            'timeout' => 10, // Shorter timeout for faster fallback
        ];

        $endpoint = add_query_arg([
            'product_id' => $product_id,
            'sku' => $sku,
        ], $this->api_url);

        $response = wp_safe_remote_get($endpoint, $args);

        // API Request failed - activate fallback mode
        if (is_wp_error($response)) {
            //$this->activate_fallback_mode();
            error_log('[Woo Update API] API request failed: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);   
        $productData = $data->product;

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['success']) || !$data['success']) {
            $this->activate_fallback_mode();
            error_log('[Woo Update API] Invalid API response');
            return false;
        }

        // API is working - ensure we're not in fallback mode
        $this->deactivate_fallback_mode();

        set_transient($transient_key, $data, $this->cache_time);
        var_dump($productData);
        return $productData;
    }

    private function activate_fallback_mode() {
        if (!$this->fallback_mode) {
            set_transient('woo_update_api_fallback_mode', true, HOUR_IN_SECONDS);
            set_transient('woo_update_api_fallback_start', time(), HOUR_IN_SECONDS);
            $this->fallback_mode = true;
            $this->fallback_start_time = time();
            
            // Send admin notification
            $admin_email = get_option('admin_email');
            wp_mail(
                $admin_email,
                __('WooCommerce Update API Fallback Mode Activated', 'woo-update-api'),
                __('The API service is unavailable. The plugin has switched to fallback mode using WooCommerce default data.', 'woo-update-api')
            );
        }
    }

    private function deactivate_fallback_mode() {
        if ($this->fallback_mode) {
            delete_transient('woo_update_api_fallback_mode');
            delete_transient('woo_update_api_fallback_start');
            $this->fallback_mode = false;
            $this->fallback_start_time = 0;
        }
    }

    public function is_in_fallback_mode() {
        // Only use fallback mode for maximum 1 hour
        if ($this->fallback_mode && (time() - $this->fallback_start_time) < HOUR_IN_SECONDS) {
            return true;
        }
        
        // Fallback period expired - try API again
        $this->deactivate_fallback_mode();
        return false;
    }
}