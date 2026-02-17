<?php
namespace Woo_Update_API;

use Exception;

defined('ABSPATH') || exit;

class Stock_Synchronizer
{
    private static $instance = null;
    private $api_handler;
    private $update_lock = [];

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->api_handler = API_Handler::instance();
        $this->init_hooks();
    }

    private function init_hooks()
    {
        // ========== FRONTEND ==========
        
        // 1. VALIDACIÓN AL AÑADIR AL CARRITO (siempre activa)
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 20, 4);

        // 2. ACTUALIZAR BD EN PÁGINA DE CARRITO (UNA VEZ POR SESIÓN)
        add_action('woocommerce_before_cart', [$this, 'update_cart_items_in_db']);
        add_action('woocommerce_before_checkout_form', [$this, 'update_cart_items_in_db']);
        
        // 3. ACTUALIZAR BD DESPUÉS DE COMPRA
        add_action('woocommerce_payment_complete', [$this, 'update_stock_after_purchase'], 10, 1);

        // ========== BACKEND (ADMIN) - INTACTO ==========
        
        // Sincronización programada
        add_action('woo_update_api_daily_stock_sync', [$this, 'sync_all_products']);
        add_action('woo_update_api_hourly_sync', [$this, 'sync_recent_products']);
        
        // Hooks para sincronización individual (usados por admin)
        add_action('wp_ajax_woo_update_api_sync_to_db', [$this, 'ajax_sync_to_db']);
        add_action('wp_ajax_woo_update_api_validate_stock', [$this, 'ajax_validate_stock']);
        
        // Acción para sincronización en background
        add_action('woo_update_api_single_sync', [$this, 'sync_single_product']);
    }

    /**
     * ACTUALIZAR PRODUCTOS DEL CARRITO EN BD (UNA VEZ POR SESIÓN)
     */
    public function update_cart_items_in_db()
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        // Verificar si ya actualizamos en esta sesión
        $session = WC()->session;
        if (!$session) {
            return;
        }

        $updated = $session->get('woo_api_cart_updated');
        if ($updated) {
            return; // Ya actualizamos en esta sesión
        }

        $cart = WC()->cart;
        $cart_items = $cart->get_cart();

        if (empty($cart_items)) {
            return;
        }

        error_log('[Stock Sync] Actualizando ' . count($cart_items) . ' productos en BD');

        foreach ($cart_items as $cart_item) {
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            
            // Programar actualización en background (no bloquear)
            wp_schedule_single_event(time(), 'woo_update_api_single_sync', [$product_id]);
        }

        // Marcar como actualizado en esta sesión
        $session->set('woo_api_cart_updated', true);
    }

    /**
     * SINCRONIZAR PRODUCTO INDIVIDUAL (EN BACKGROUND)
     */
    public function sync_single_product($product_id)
    {
        try {
            $product = wc_get_product($product_id);
            if (!$product || !$product->managing_stock()) {
                return;
            }

            $sku = $product->get_sku();
            
            // Obtener datos de API
            $api_data = $this->api_handler->get_product_data($product_id, $sku);
            
            if ($api_data === false) {
                return;
            }

            $updated = false;

            // 1. ACTUALIZAR PRECIO EN BD
            if (isset($api_data['price_mxn']) || isset($api_data['price'])) {
                $price = isset($api_data['price_mxn']) ? floatval($api_data['price_mxn']) : floatval($api_data['price']);
                
                update_post_meta($product_id, '_price', $price);
                update_post_meta($product_id, '_regular_price', $price);
                
                if ($product->is_type('variation')) {
                    update_post_meta($product_id, '_variation_price', $price);
                }
                
                $updated = true;
            }

            // 2. ACTUALIZAR STOCK EN BD
            if (isset($api_data['stock_quantity'])) {
                $api_stock = intval($api_data['stock_quantity']);
                
                wc_update_product_stock($product_id, $api_stock);
                update_post_meta($product_id, '_stock', $api_stock);
                
                $updated = true;
            }

            // 3. GUARDAR TIMESTAMP
            if ($updated) {
                update_post_meta($product_id, '_last_api_sync', current_time('mysql'));
                update_post_meta($product_id, '_api_data_cache', $api_data);
                wc_delete_product_transients($product_id);
                
                error_log('[Stock Sync] Producto actualizado: ' . $product_id);
            }

        } catch (Exception $e) {
            error_log('[Stock Sync Error] ' . $e->getMessage());
        }
    }

    /**
     * VALIDACIÓN AL AÑADIR AL CARRITO (MEJORADA)
     */
    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0)
    {
        try {
            $actual_product_id = $variation_id ? $variation_id : $product_id;
            
            $product = wc_get_product($actual_product_id);
            if (!$product || !$product->managing_stock()) {
                return $passed;
            }

            // Obtener stock ACTUALIZADO (consulta API directa)
            $api_data = $this->api_handler->get_product_data($actual_product_id, $product->get_sku());
            
            if ($api_data && isset($api_data['stock_quantity'])) {
                $real_stock = intval($api_data['stock_quantity']);
            } else {
                $real_stock = $product->get_stock_quantity();
            }

            // Calcular cantidad en carrito
            $cart_quantity = 0;
            $cart = WC()->cart;
            if ($cart) {
                foreach ($cart->get_cart() as $cart_item) {
                    if ($cart_item['product_id'] == $product_id && 
                        $cart_item['variation_id'] == $variation_id) {
                        $cart_quantity = $cart_item['quantity'];
                        break;
                    }
                }
            }

            $total_requested = $cart_quantity + $quantity;

            if ($total_requested > $real_stock) {
                $available = max(0, $real_stock - $cart_quantity);
                
                wc_add_notice(
                    sprintf(__('No hay suficiente stock. Solo %d disponible(s).', 'woo-update-api'), $available),
                    'error'
                );
                
                return false;
            }

            return $passed;

        } catch (Exception $e) {
            error_log('[Validate Error] ' . $e->getMessage());
            return $passed;
        }
    }

    /**
     * ========== MÉTODOS DE ADMIN (INTACTOS) ==========
     */

    public function sync_all_products()
    {
        $products = wc_get_products([
            'limit' => -1,
            'return' => 'ids'
        ]);
        
        foreach ($products as $product_id) {
            wp_schedule_single_event(time(), 'woo_update_api_single_sync', [$product_id]);
            usleep(100000);
        }
    }

    public function sync_recent_products()
    {
        global $wpdb;
        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_last_api_sync' 
                AND meta_value >= %s",
                date('Y-m-d H:i:s', time() - 86400)
            )
        );
        
        foreach ($product_ids as $product_id) {
            wp_schedule_single_event(time(), 'woo_update_api_single_sync', [$product_id]);
            usleep(50000);
        }
    }

    public function ajax_sync_to_db()
    {
        check_ajax_referer('wc_update_api_sync_db', 'nonce');
        
        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied', 'woo-update-api')]);
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID', 'woo-update-api')]);
        }

        try {
            $this->sync_single_product($product_id);
            
            $product = wc_get_product($product_id);
            
            wp_send_json_success([
                'message' => __('Product synchronized successfully', 'woo-update-api'),
                'price' => $product ? $product->get_price() : null,
                'stock' => $product ? $product->get_stock_quantity() : null
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_validate_stock()
    {
        // Mantener funcionalidad AJAX existente
        check_ajax_referer('woo_update_api_nonce', 'nonce');
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
        
        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID', 'woo-update-api')]);
        }

        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                throw new Exception(__('Product not found', 'woo-update-api'));
            }

            $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());
            
            if ($api_data && isset($api_data['stock_quantity'])) {
                $stock = intval($api_data['stock_quantity']);
                
                if ($stock >= $quantity) {
                    wp_send_json_success([
                        'stock' => $stock,
                        'message' => __('Stock available', 'woo-update-api')
                    ]);
                } else {
                    wp_send_json_error([
                        'stock' => $stock,
                        'message' => sprintf(__('Only %d available', 'woo-update-api'), $stock)
                    ]);
                }
            } else {
                wp_send_json_success([
                    'stock' => $product->get_stock_quantity(),
                    'message' => __('Using local stock', 'woo-update-api')
                ]);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function update_stock_after_purchase($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_variation_id() ?: $item->get_product_id();
            wp_schedule_single_event(time() + 5, 'woo_update_api_single_sync', [$product_id]);
        }
    }
}