<?php
class Woo_Update_API_Ajax_Handler {

    public static function init() {
        add_action('wp_ajax_woo_update_api_get_product', [__CLASS__, 'get_product_data']);
        add_action('wp_ajax_nopriv_woo_update_api_get_product', [__CLASS__, 'get_product_data']);
        
        // Endpoint para limpiar caché (solo admins)
        add_action('wp_ajax_woo_update_api_clear_cache', [__CLASS__, 'clear_cache']);
        
        // Endpoint para obtener estadísticas de caché (solo admins)
        add_action('wp_ajax_woo_update_api_cache_stats', [__CLASS__, 'get_cache_stats']);
    }

    public static function get_product_data() {
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'woo_update_api_ajax')) {
            wp_send_json_error('Security check failed', 403);
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $force_fresh = isset($_POST['force_fresh']) && $_POST['force_fresh'] === 'true';

        if (!$product_id || empty($sku)) {
            wp_send_json_error('Invalid product data', 400);
        }

        $api_handler = Woo_Update_API()->api_handler;
        $settings = Woo_Update_API()->get_settings();

        // Decidir si usar caché según configuración y parámetros
        $skip_cache = $force_fresh || !$settings->is_ajax_cache_enabled();
        
        // Obtener datos
        $api_data = $api_handler->get_product_data($product_id, $sku, $skip_cache);

        if ($api_data === false) {
            wp_send_json_error('Could not fetch product data', 404);
        }

        // Agregar metadata de la respuesta
        $response = [
            'product' => $api_data,
            'meta' => [
                'cached' => !$skip_cache && Woo_Update_API_Cache::get($sku) !== false,
                'timestamp' => current_time('timestamp'),
                'response_time' => $api_data['response_time_ms'] ?? null
            ]
        ];

        wp_send_json_success($response);
    }

    public static function clear_cache() {
        // Verificar permisos y nonce
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'woo_update_api_admin')) {
            wp_send_json_error('Security check failed', 403);
        }

        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : null;

        if ($sku) {
            // Limpiar caché de un SKU específico
            $result = Woo_Update_API_Cache::delete($sku);
            $message = $result ? 'Cache cleared for SKU: ' . $sku : 'Failed to clear cache';
        } else {
            // Limpiar todo el caché
            $result = Woo_Update_API_Cache::clear_all();
            $message = $result ? 'All cache cleared' : 'Failed to clear cache';
        }

        wp_send_json_success(['message' => $message]);
    }

    public static function get_cache_stats() {
        // Verificar permisos y nonce
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'woo_update_api_admin')) {
            wp_send_json_error('Security check failed', 403);
        }

        $stats = Woo_Update_API_Cache::get_stats();
        wp_send_json_success($stats);
    }
}