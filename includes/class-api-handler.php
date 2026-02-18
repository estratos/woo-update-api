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

        $this->error_manager = API_Error_Manager::instance();

        // Admin AJAX
        add_action('wp_ajax_woo_update_api_get_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_woo_update_api_reconnect', [$this, 'ajax_reconnect']);
    }

    /**
     * OBTENER DATOS DE PRODUCTO
     */
    public function get_product_data($product_id, $sku = '')
    {
        // Cache por producto
        $cache_key = 'woo_api_product_' . md5($product_id . $sku);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            return $cached_data;
        }

        // Verificar configuración
        if (empty($this->api_url) || empty($this->api_key)) {
            return false;
        }

        try {
            $data = $this->make_api_request($product_id, $sku);

            if ($data !== false) {
                // Cachear respuesta exitosa
                set_transient($cache_key, $data, $this->cache_time);
                $this->error_manager->reset_errors();
                return $data;
            }

            return false;
        } catch (Exception $e) {
            error_log('[API Error] ' . $e->getMessage() . ' - Producto: ' . $product_id);

            // NO incrementar error para 404
            if (strpos($e->getMessage(), '404') === false) {
                $this->error_manager->increment_error();
            }

            return false;
        }
    }

    private function make_api_request($product_id, $sku)
    {
        $base_url = rtrim($this->api_url, '?&');

        $query_args = [
            'sku' => $sku,
            'api_key' => $this->api_key
        ];

        if (!empty($product_id) && $product_id > 0) {
            $query_args['product_id'] = $product_id;
        }

        $endpoint = add_query_arg($query_args, $base_url);

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'WooCommerce-Update-API/1.0'
            ],
            'timeout' => 10,
            'sslverify' => true
        ];

        $response = wp_safe_remote_get($endpoint, $args);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            throw new Exception(sprintf(__('API returned status: %d', 'woo-update-api'), $status_code));
        }

        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Invalid JSON response from API', 'woo-update-api'));
        }

        // Devolver datos del producto
        if (isset($data['product'])) {
            return $data['product'];
        }

        return $data;
    }

    /**
     * MÉTODO DIRECTO - SIN CACHÉ - SIN FILTROS - SOLO API
     */
    public function get_product_data_direct($product_id, $sku = '')
    {
        error_log('[API Direct] ===== CONSULTA DIRECTA A API =====');
        error_log('[API Direct] Producto: ' . $product_id . ' - SKU: ' . $sku);

        if (empty($this->api_url) || empty($this->api_key)) {
            error_log('[API Direct] ERROR - API no configurada');
            return false;
        }

        try {
            // Construir URL
            $base_url = rtrim($this->api_url, '?&');
            $query_args = [
                'sku' => $sku,
                'api_key' => $this->api_key
            ];

            if (!empty($product_id) && $product_id > 0) {
                $query_args['product_id'] = $product_id;
            }

            $endpoint = add_query_arg($query_args, $base_url);
            error_log('[API Direct] URL: ' . str_replace($this->api_key, '***', $endpoint));

            // Hacer request
            $args = [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'WooCommerce-Update-API/1.0'
                ],
                'timeout' => 15,
                'sslverify' => true
            ];

            $response = wp_safe_remote_get($endpoint, $args);

            if (is_wp_error($response)) {
                error_log('[API Direct] WP Error: ' . $response->get_error_message());
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            error_log('[API Direct] Status code: ' . $status_code);

            if ($status_code !== 200) {
                error_log('[API Direct] Error HTTP: ' . $status_code);
                if ($status_code === 404) {
                    error_log('[API Direct] Producto no encontrado en API (SKU: ' . $sku . ')');
                }
                return false;
            }

            $data = json_decode($response_body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('[API Direct] JSON Error: ' . json_last_error_msg());
                return false;
            }

            // Devolver datos del producto
            if (isset($data['product'])) {
                error_log('[API Direct] ✅ Datos obtenidos (formato product)');
                return $data['product'];
            }

            error_log('[API Direct] ✅ Datos obtenidos');
            return $data;
        } catch (Exception $e) {
            error_log('[API Direct] Excepción: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * MÉTODOS ADMIN
     */
    public function test_connection()
    {
        if (empty($this->api_url) || empty($this->api_key)) {
            throw new Exception(__('API URL and Key must be configured', 'woo-update-api'));
        }

        $test_url = add_query_arg([
            'sku' => 'SYS143612',
            'api_key' => $this->api_key,
            'test' => 1
        ], rtrim($this->api_url, '?&'));

        $args = [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 10,
            'sslverify' => true
        ];

        $response = wp_safe_remote_get($test_url, $args);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200) {
            return true;
        }

        throw new Exception(sprintf(__('API returned status: %d', 'woo-update-api'), $status_code));
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
                'settings_configured' => !empty($this->api_url) && !empty($this->api_key),
                'error_count' => $this->error_manager->get_error_count(),
                'fallback_mode' => $this->error_manager->is_fallback_active()
            ];

            if ($status['settings_configured']) {
                $status['connected'] = $this->test_connection();
            }

            wp_send_json_success($status);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_reconnect()
    {
        check_ajax_referer('woo_update_api_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'woo-update-api')]);
        }

        try {
            // Limpiar caché
            global $wpdb;
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    '%_transient_woo_api_product_%'
                )
            );

            $this->error_manager->reset_errors();

            $connected = $this->test_connection();

            wp_send_json_success([
                'message' => __('Reconnected successfully!', 'woo-update-api'),
                'connected' => $connected
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function is_in_fallback_mode()
    {
        return $this->error_manager->is_fallback_active();
    }
}
