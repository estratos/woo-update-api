<?php

namespace Woo_Update_API;

use Exception;

defined('ABSPATH') || exit;

class API_Handler
{
    private static $instance = null;
    private $api_url;
    private $api_key;
    private $cache_time;
    private $reconnect_time;
    private $fallback_mode = false;
    private $fallback_start_time = 0;

    protected $error_manager;

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $settings = get_option('woo_update_api_settings');
        $this->api_url = $settings['api_url'] ?? '';
        $this->api_key = $settings['api_key'] ?? '';
        $this->cache_time = isset($settings['cache_time']) ? absint($settings['cache_time']) : 300;
        $this->reconnect_time = isset($settings['reconnect_time']) ? absint($settings['reconnect_time']) : 3600;

        // Check if we're in fallback mode
        $this->fallback_mode = get_transient('woo_update_api_fallback_mode');
        $this->fallback_start_time = get_transient('woo_update_api_fallback_start');
        /// error manager
        // In __construct():
        $this->error_manager = new API_Error_Manager();
    }

    public function get_product_data($product_id, $sku = '')
    {
        // If in fallback mode, return false to use WooCommerce defaults
        // temporaly disable fallabacck
        // $this->deactivate_fallback_mode();
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

        //$response = wp_safe_remote_get($endpoint, $args); old way

        $response  = $this->make_api_request($endpoint, $args);


        // API Request failed - activate fallback mode
        if (is_wp_error($response)) {
            $this->activate_fallback_mode();
            error_log('[Woo Update API] API request failed: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $productData = $data['product'];

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['success']) || !$data['success']) {
            $this->activate_fallback_mode();
            error_log('[Woo Update API] Invalid API response');
            return false;
        }

        // API is working - ensure we're not in fallback mode
        $this->deactivate_fallback_mode();

        set_transient($transient_key, $data, $this->cache_time);
        // var_dump($productData);
        return $productData;
    }



    public function make_api_request($endpoint, $args = [])
    {
        if ($this->should_use_fallback()) {
            return $this->get_cached_data();
        }

        try {
            $response = $this->execute_api_call($endpoint, $args);
            $this->error_manager->reset_errors();
            return $response;
        } catch (Exception $e) {
            $this->handle_api_error($e);
            return $this->get_fallback_data();
        }
    }

    private function execute_api_call($endpoint, $args)
    {
              

        $response = wp_remote_get($endpoint, $args);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            throw new Exception("API returned status: $status_code");
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    private function should_use_fallback()
    {
        return $this->error_manager->is_fallback_active() ||
            ($this->api_settings['disable_fallback'] ?? 'no') === 'yes';
    }

    private function get_fallback_data() {
        return $this->error_manager->get_error_count() < 5 ? 
            $this->get_cached_data() : 
            false;
    }





    private function activate_fallback_mode()
    {
        if (!$this->fallback_mode) {
            set_transient('woo_update_api_fallback_mode', true, $this->reconnect_time);
            set_transient('woo_update_api_fallback_start', time(), $this->reconnect_time);
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

    private function deactivate_fallback_mode()
    {
        if ($this->fallback_mode) {
            delete_transient('woo_update_api_fallback_mode');
            delete_transient('woo_update_api_fallback_start');
            $this->fallback_mode = false;
            $this->fallback_start_time = 0;
        }
    }

    public function is_in_fallback_mode()
    {
        // Only use fallback mode for maximum 1 hour
        if ($this->fallback_mode && (time() - $this->fallback_start_time) < HOUR_IN_SECONDS) {
            return true;
        }

        // Fallback period expired - try API again
        $this->deactivate_fallback_mode();
        return false;
    }
}
