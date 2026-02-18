<?php
class Woo_Update_API_Ajax_Handler {

    public static function get_product_data() {
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'woo_update_api_ajax')) {
            wp_die('Security check failed');
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $sku = sanitize_text_field($_POST['sku'] ?? '');

        if (!$product_id || empty($sku)) {
            wp_send_json_error('Invalid product data');
        }

        $api_handler = new Woo_Update_API_Handler();
        
        // Consulta directa sin caché para AJAX
        $api_data = $api_handler->get_product_data_direct($product_id, $sku);

        if ($api_data === false) {
            wp_send_json_error('Could not fetch product data');
        }

        wp_send_json_success($api_data);
    }
}