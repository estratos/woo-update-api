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
    private $error_manager;

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $settings = get_option('woo_update_api_settings', []);
        $this->api_url = $settings['api_url'] ?? '';
        $this->api_key = $settings['api_key'] ?? '';
        $this->cache_time = isset($settings['cache_time']) ? absint($settings['cache_time']) : 300;
        $this->reconnect_time = isset($settings['reconnect_time']) ? absint($settings['reconnect_time']) : 3600;

        $this->error_manager = API_Error_Manager::instance();

        // Check fallback mode from error manager
        $this->fallback_mode = $this->error_manager->is_fallback_active();

        // Register AJAX handlers
        add_action('wp_ajax_woo_update_api_get_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_woo_update_api_reconnect', [$this, 'ajax_reconnect']);
    }

    public function get_product_data($product_id, $sku = '')
    {
        // If in fallback mode, return false to use WooCommerce defaults
        if ($this->is_in_fallback_mode()) {
            error_log('[Woo Update API] Currently in fallback mode - using WooCommerce defaults');
            return false;
        }

        // Check if API is configured
        if (empty($this->api_url) || empty($this->api_key)) {
            error_log('[Woo Update API] API not configured');
            return false;
        }

        $cache_key = 'woo_update_api_product_' . md5($product_id . $sku);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false && !empty($cached_data)) {
            return $cached_data;
        }

        try {
            $data = $this->make_api_request($product_id, $sku);

            if ($data !== false) {
                // Cache successful response
                set_transient($cache_key, $data, $this->cache_time);
                // Reset error counter on successful request
                $this->error_manager->reset_errors();
                return $data;
            }

            // If we get here, API request failed
            $this->error_manager->increment_error();

            // Check if we should activate fallback mode
            if ($this->error_manager->get_error_count() >= $this->error_manager->get_error_threshold()) {
                $this->activate_fallback_mode();
            }

            return false;
        } catch (Exception $e) {
            error_log('[Woo Update API] Exception: ' . $e->getMessage());
            $this->error_manager->increment_error();
            return false;
        }
    }


    private function make_api_request($product_id, $sku)
    {
        // Construir URL con parámetros
        $query_args = [
            'sku' => $sku,
            'api_key' => $this->api_key  // API key como query parameter
        ];

        // Agregar product_id solo si está presente
        if (!empty($product_id)) {
            $query_args['product_id'] = $product_id;
        }

        // Agregar timestamp para evitar cache
        $query_args['timestamp'] = time();

        $endpoint = add_query_arg($query_args, $this->api_url);

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'timeout' => 15,
            'sslverify' => apply_filters('woo_update_api_sslverify', true)
        ];

        // Debug: registrar la URL (solo en modo desarrollo)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Woo Update API] Request URL: ' . $endpoint);
        }

        $response = wp_safe_remote_get($endpoint, $args);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);

        // Registrar respuesta para debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Woo Update API] Response Status: ' . $status_code);
        }

        if ($status_code !== 200) {
            throw new Exception(sprintf(__('API returned status: %d', 'woo-update-api'), $status_code));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Invalid JSON response from API', 'woo-update-api'));
        }

        // Check for API-specific error indicators
        if (isset($data['error']) || (isset($data['success']) && $data['success'] === false)) {
            $error_msg = $data['message'] ?? __('API returned an error', 'woo-update-api');
            throw new Exception($error_msg);
        }

        // Return product data - handle different response structures
        if (isset($data['product'])) {
            return $data['product'];
        }

        // If no 'product' key, assume the entire response is product data
        return $data;
    }

    public function test_connection()
    {
        if (empty($this->api_url) || empty($this->api_key)) {
            throw new Exception(__('API URL and Key must be configured', 'woo-update-api'));
        }

        error_log('[Woo Update API DEBUG] Testing connection...');

        // Construir URL de prueba - usar SKU de prueba
        $test_url = add_query_arg([
            'sku' => 'EAPO1004', // SKU que sabemos que existe
            'api_key' => $this->api_key,
            'timestamp' => time()
        ], rtrim($this->api_url, '?&'));

        error_log('[Woo Update API DEBUG] Test URL: ' . str_replace($this->api_key, 'API_KEY_REDACTED', $test_url));

        $args = [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'WooCommerce-Update-API-Test/1.0'
            ],
            'timeout' => 10,
            'sslverify' => true
        ];

        $response = wp_safe_remote_get($test_url, $args);

        if (is_wp_error($response)) {
            error_log('[Woo Update API DEBUG] Test connection WP_Error: ' . $response->get_error_message());
            throw new Exception($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log('[Woo Update API DEBUG] Test response status: ' . $status_code);
        error_log('[Woo Update API DEBUG] Test response body: ' . substr($response_body, 0, 500));

        if ($status_code === 200) {
            $data = json_decode($response_body, true);

            if (isset($data['success']) && $data['success'] === true) {
                error_log('[Woo Update API DEBUG] Test connection SUCCESS');
                return true;
            }

            error_log('[Woo Update API DEBUG] Test connection failed - success flag false');
            throw new Exception(__('API returned success: false', 'woo-update-api'));
        } else {
            error_log('[Woo Update API DEBUG] Test connection failed with status: ' . $status_code);
            throw new Exception(sprintf(__('API returned status: %d', 'woo-update-api'), $status_code));
        }
    }

    private function activate_fallback_mode()
    {
        if (!$this->fallback_mode) {
            $this->fallback_mode = true;
            set_transient('woo_update_api_fallback_mode', true, $this->reconnect_time);
            set_transient('woo_update_api_fallback_start', time(), $this->reconnect_time);

            // Send admin notification
            $admin_email = get_option('admin_email');
            wp_mail(
                $admin_email,
                __('WooCommerce Update API Fallback Mode Activated', 'woo-update-api'),
                __('The API service is unavailable. The plugin has switched to fallback mode using WooCommerce default data.', 'woo-update-api')
            );

            error_log('[Woo Update API] Fallback mode activated');
        }
    }

    private function deactivate_fallback_mode()
    {
        if ($this->fallback_mode) {
            $this->fallback_mode = false;
            delete_transient('woo_update_api_fallback_mode');
            delete_transient('woo_update_api_fallback_start');
            error_log('[Woo Update API] Fallback mode deactivated');
        }
    }

    public function is_in_fallback_mode()
    {
        // Check transient first
        $fallback_transient = get_transient('woo_update_api_fallback_mode');

        if ($fallback_transient) {
            $this->fallback_mode = true;
            return true;
        }

        // Also check error manager
        if ($this->error_manager->is_fallback_active()) {
            $this->fallback_mode = true;
            return true;
        }

        $this->fallback_mode = false;
        return false;
    }

    public function ajax_get_status()
    {
        check_ajax_referer('woo_update_api_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'woo-update-api')]);
        }

        try {
            $status = [
                'connected' => false,
                'fallback_mode' => $this->is_in_fallback_mode(),
                'error_count' => $this->error_manager->get_error_count(),
                'error_threshold' => $this->error_manager->get_error_threshold(),
                'settings_configured' => !empty($this->api_url) && !empty($this->api_key)
            ];

            // Test connection if configured
            if ($status['settings_configured']) {
                $status['connected'] = $this->test_connection();
            }

            wp_send_json_success($status);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'connected' => false
            ]);
        }
    }

    public function ajax_reconnect()
    {
        check_ajax_referer('woo_update_api_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'woo-update-api')]);
        }

        try {
            // Reset error manager
            $this->error_manager->reset_errors();

            // Clear fallback mode
            $this->deactivate_fallback_mode();

            // Clear all product caches
            global $wpdb;
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    '_transient_woo_update_api_product_%'
                )
            );

            // Test connection
            $connected = $this->test_connection();

            wp_send_json_success([
                'message' => __('Reconnected successfully! All caches cleared.', 'woo-update-api'),
                'connected' => $connected,
                'fallback_mode' => false,
                'error_count' => 0
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => __('Reconnect failed: ', 'woo-update-api') . $e->getMessage()
            ]);
        }
    }

    public function get_api_url()
    {
        return $this->api_url;
    }

    public function get_api_key()
    {
        return $this->api_key;
    }
}
