<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class Ajax_Handler {
    public function __construct() {
        add_action('wp_ajax_wc_update_api_manual_refresh', [$this, 'handle_refresh']);
        add_action('wp_ajax_nopriv_wc_update_api_manual_refresh', [$this, 'handle_no_permission']);
    }
    
    public function handle_no_permission() {
        wp_send_json_error([
            'message' => __('You must be logged in to perform this action.', 'woo-update-api')
        ], 403);
    }
    
    public function handle_refresh() {
        check_ajax_referer('wc_update_api_refresh', 'security');
        
        if (!current_user_can('edit_products')) {
            wp_send_json_error([
                'message' => __('You do not have permission to refresh products.', 'woo-update-api')
            ], 403);
        }
        
        $product_id = absint($_POST['product_id']);
        $product = wc_get_product($product_id);
        
        if (!$product) {
            wp_send_json_error([
                'message' => __('Product not found.', 'woo-update-api')
            ], 404);
        }
        
        try {
            // Get API handler instance
            $api_handler = API_Handler::instance();
            
            // Clear cached data
            $transient_key = 'woo_update_api_data_' . md5($product_id . $product->get_sku());
            delete_transient($transient_key);
            
            // Force API refresh
            $api_data = $api_handler->get_product_data($product_id, $product->get_sku());
            
            if ($api_data === false) {
                throw new \Exception(__('API refresh failed. Please check your API settings.', 'woo-update-api'));
            }
            
            // Update last refresh time
            $last_refresh = current_time('mysql');
            update_post_meta($product_id, '_wc_update_api_last_refresh', $last_refresh);
            
            wp_send_json_success([
                'message' => __('Product data refreshed successfully!', 'woo-update-api'),
                'last_refresh' => date_i18n(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    strtotime($last_refresh)
                ),
                'data' => $api_data
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
}