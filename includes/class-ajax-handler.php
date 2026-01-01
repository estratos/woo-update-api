<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class Ajax_Handler
{
    private static $instance = null;

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        // Manual refresh for single product
        add_action('wp_ajax_wc_update_api_manual_refresh', [$this, 'handle_manual_refresh']);
        add_action('wp_ajax_nopriv_wc_update_api_manual_refresh', [$this, 'handle_no_permission']);
        
        // Bulk refresh
        add_action('wp_ajax_wc_update_api_bulk_refresh', [$this, 'handle_bulk_refresh']);
        
        // Stock validation (NUEVO)
        add_action('wp_ajax_woo_update_api_validate_stock', [$this, 'ajax_validate_stock']);
        add_action('wp_ajax_nopriv_woo_update_api_validate_stock', [$this, 'ajax_validate_stock']);
    }

    public function handle_no_permission()
    {
        wp_send_json_error([
            'message' => __('You must be logged in to perform this action.', 'woo-update-api')
        ], 403);
    }
    
    /**
     * VALIDACIÓN DE STOCK VIA AJAX (NUEVO)
     */
    public function ajax_validate_stock() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'woo_update_api_nonce')) {
            wp_send_json_error([
                'message' => __('Security check failed.', 'woo-update-api')
            ], 403);
        }

        // Get product data
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error([
                'message' => __('Invalid product.', 'woo-update-api')
            ], 400);
        }

        try {
            $product = wc_get_product($variation_id ? $variation_id : $product_id);
            
            if (!$product) {
                throw new \Exception(__('Product not found.', 'woo-update-api'));
            }
            
            // Usar Stock_Synchronizer para validación
            $sync = Stock_Synchronizer::instance();
            
            // Simular validación de añadir al carrito
            $cart = WC()->cart;
            $cart_quantity = 0;
            
            if ($cart) {
                foreach ($cart->get_cart() as $cart_item) {
                    if ($cart_item['product_id'] == $product_id && 
                        $cart_item['variation_id'] == $variation_id) {
                        $cart_quantity = $cart_item['quantity'];
                        break;
                    }
                }
            }
            
            $real_stock = $sync->get_real_stock($product_id);
            $total_requested = $cart_quantity + $quantity;
            
            if ($total_requested > $real_stock) {
                $available = max(0, $real_stock - $cart_quantity);
                
                wp_send_json_error([
                    'message' => sprintf(
                        __('Only %d available in stock.', 'woo-update-api'),
                        $available
                    ),
                    'available' => $available,
                    'requested' => $quantity
                ]);
            }
            
            wp_send_json_success([
                'message' => __('Stock available.', 'woo-update-api'),
                'available' => $real_stock,
                'can_add' => true
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function handle_manual_refresh()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_update_api_refresh')) {
            wp_send_json_error([
                'message' => __('Security check failed.', 'woo-update-api')
            ], 403);
        }

        // Check permissions
        if (!current_user_can('edit_products')) {
            wp_send_json_error([
                'message' => __('You do not have permission to refresh products.', 'woo-update-api')
            ], 403);
        }

        // Get product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error([
                'message' => __('Invalid product ID.', 'woo-update-api')
            ], 400);
        }

        try {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                throw new \Exception(__('Product not found.', 'woo-update-api'));
            }

            // Get API handler instance
            $api_handler = API_Handler::instance();
            
            // Clear cached data for this product
            $cache_key = 'woo_update_api_product_' . md5($product_id . $product->get_sku());
            delete_transient($cache_key);
            
            // Force API refresh (bypass cache)
            $api_data = $api_handler->get_product_data($product_id, $product->get_sku());
            
            if ($api_data === false) {
                throw new \Exception(__('API refresh failed. Please check your API settings.', 'woo-update-api'));
            }
            
            // Update last refresh time
            $last_refresh = current_time('mysql');
            update_post_meta($product_id, '_wc_update_api_last_refresh', $last_refresh);
            
            // Update product meta with API data for reference
            if (isset($api_data['price_mxn'])) {
                update_post_meta($product_id, '_api_price', $api_data['price_mxn']);
            }
            if (isset($api_data['stock_quantity'])) {
                update_post_meta($product_id, '_api_stock', $api_data['stock_quantity']);
                
                // NUEVO: Sincronizar stock real en BD
                $product->set_stock_quantity($api_data['stock_quantity']);
                $product->save();
            }

            // Format response data
            $response_data = [
                'message' => __('Product data refreshed successfully!', 'woo-update-api'),
                'last_refresh' => date_i18n(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    strtotime($last_refresh)
                )
            ];

            // Add price and stock if available
            if (isset($api_data['price_mxn'])) {
                $response_data['price'] = wc_price($api_data['price_mxn']);
                $response_data['price_raw'] = $api_data['price_mxn'];
            }
            
            if (isset($api_data['stock_quantity'])) {
                $response_data['stock'] = $api_data['stock_quantity'];
                $response_data['stock_synced'] = true;
            }

            wp_send_json_success($response_data);
            
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function handle_bulk_refresh()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_update_api_bulk_refresh')) {
            wp_send_json_error([
                'message' => __('Security check failed.', 'woo-update-api')
            ], 403);
        }

        // Check permissions
        if (!current_user_can('edit_products')) {
            wp_send_json_error([
                'message' => __('You do not have permission to refresh products.', 'woo-update-api')
            ], 403);
        }

        // Get product IDs
        $product_ids = [];
        if (isset($_POST['product_ids'])) {
            if (is_array($_POST['product_ids'])) {
                $product_ids = array_map('absint', $_POST['product_ids']);
            } else {
                $product_ids = array_map('absint', explode(',', $_POST['product_ids']));
            }
        }

        if (empty($product_ids)) {
            wp_send_json_error([
                'message' => __('No product IDs provided.', 'woo-update-api')
            ], 400);
        }

        $results = [
            'success' => 0,
            'failed' => 0,
            'synced' => 0,
            'details' => []
        ];

        $api_handler = API_Handler::instance();
        $sync = Stock_Synchronizer::instance();

        foreach ($product_ids as $product_id) {
            try {
                $product = wc_get_product($product_id);
                
                if (!$product) {
                    $results['failed']++;
                    $results['details'][] = [
                        'product_id' => $product_id,
                        'status' => 'error',
                        'message' => __('Product not found', 'woo-update-api')
                    ];
                    continue;
                }

                // Clear cache and refresh
                $cache_key = 'woo_update_api_product_' . md5($product_id . $product->get_sku());
                delete_transient($cache_key);
                
                $api_data = $api_handler->get_product_data($product_id, $product->get_sku());
                
                if ($api_data !== false) {
                    $results['success']++;
                    
                    // NUEVO: Sincronizar stock si está disponible
                    if (isset($api_data['stock_quantity'])) {
                        $product->set_stock_quantity($api_data['stock_quantity']);
                        $product->save();
                        $results['synced']++;
                    }
                    
                    $results['details'][] = [
                        'product_id' => $product_id,
                        'status' => 'success',
                        'name' => $product->get_name(),
                        'stock_synced' => isset($api_data['stock_quantity'])
                    ];
                } else {
                    $results['failed']++;
                    $results['details'][] = [
                        'product_id' => $product_id,
                        'status' => 'error',
                        'message' => __('API refresh failed', 'woo-update-api')
                    ];
                }
                
            } catch (\Exception $e) {
                $results['failed']++;
                $results['details'][] = [
                    'product_id' => $product_id,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        wp_send_json_success([
            'message' => sprintf(
                __('Bulk refresh completed: %d successful, %d failed, %d stocks synced.', 'woo-update-api'),
                $results['success'],
                $results['failed'],
                $results['synced']
            ),
            'results' => $results
        ]);
    }
}