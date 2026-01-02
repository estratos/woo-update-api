<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class Stock_Synchronizer {
    private static $instance = null;
    private $api_handler;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->api_handler = API_Handler::instance();
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // VALIDACIÓN AL AÑADIR AL CARRITO (¡CRÍTICO!)
        add_filter('woocommerce_add_to_cart_validation', 
            [$this, 'validate_add_to_cart'], 20, 4);
        
        // VALIDACIÓN DURANTE CHECKOUT
        add_action('woocommerce_check_cart_items', 
            [$this, 'validate_cart_stock']);
        
        // SINCRONIZAR STOCK REAL EN BD ANTES DE CHECKOUT
        add_action('woocommerce_before_checkout_process', 
            [$this, 'sync_stock_before_checkout']);
        
        // ACTUALIZAR STOCK DESPUÉS DE COMPRA
        add_action('woocommerce_payment_complete', 
            [$this, 'update_stock_after_purchase'], 10, 1);
        
        // VALIDACIÓN EN CARRITO (AJAX)
        add_filter('woocommerce_cart_item_quantity', 
            [$this, 'validate_cart_item_quantity'], 10, 3);
            
        // SINCRONIZACIÓN ASÍNCRONA DE STOCK
        add_action('woo_update_api_async_stock_sync', 
            [$this, 'async_stock_sync'], 10, 2);
    }
    
    /**
     * VALIDACIÓN AL AÑADIR PRODUCTO AL CARRITO
     */
    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0) {
        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        
        if (!$product || !$product->managing_stock()) {
            return $passed;
        }
        
        // 1. Obtener stock REAL (de API)
        $real_stock = $this->get_real_stock($product_id);
        
        // 2. Obtener cantidad ya en carrito
        $cart = WC()->cart;
        $cart_quantity = 0;
        
        if ($cart) {
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                if ($cart_item['product_id'] == $product_id && 
                    $cart_item['variation_id'] == $variation_id) {
                    $cart_quantity = $cart_item['quantity'];
                    break;
                }
            }
        }
        
        // 3. Validar stock total (carrito + nuevo)
        $total_requested = $cart_quantity + $quantity;
        
        if ($total_requested > $real_stock) {
            $available = max(0, $real_stock - $cart_quantity);
            
            wc_add_notice(
                sprintf(
                    __('No hay suficiente stock. Solo %d disponible(s).', 'woo-update-api'),
                    $available
                ),
                'error'
            );
            
            // Programar sincronización para corregir discrepancia
            $this->schedule_async_sync($product_id, $real_stock);
            
            return false;
        }
        
        return $passed;
    }
    
    /**
     * VALIDAR STOCK EN TODO EL CARRITO
     */
    public function validate_cart_stock() {
        $cart = WC()->cart;
        
        if (!$cart || $cart->is_empty()) {
            return;
        }
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            
            if (!$product->managing_stock()) {
                continue;
            }
            
            // Obtener stock real de API
            $real_stock = $this->get_real_stock($product->get_id());
            
            // Verificar si hay suficiente stock
            if ($cart_item['quantity'] > $real_stock) {
                $cart->set_quantity($cart_item_key, $real_stock);
                
                wc_add_notice(
                    sprintf(
                        __('Stock actualizado para "%s". Solo %d disponible(s).', 'woo-update-api'),
                        $product->get_name(),
                        $real_stock
                    ),
                    'notice'
                );
            }
        }
    }
    
    /**
     * SINCRONIZAR STOCK REAL ANTES DE CHECKOUT
     */
    public function sync_stock_before_checkout() {
        $cart = WC()->cart;
        
        if (!$cart || $cart->is_empty()) {
            return;
        }
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            
            if (!$product->managing_stock()) {
                continue;
            }
            
            // Obtener stock real de API
            $real_stock = $this->get_real_stock($product->get_id());
            $wc_stock = $product->get_stock_quantity();
            
            // Si hay diferencia, ACTUALIZAR BD
            if ($real_stock !== $wc_stock) {
                $product->set_stock_quantity($real_stock);
                $product->save();
                
                // Registrar para debugging
                $this->log_sync($product->get_id(), $wc_stock, $real_stock);
            }
        }
    }
    
    /**
     * ACTUALIZAR STOCK DESPUÉS DE COMPRA EXITOSA
     */
    public function update_stock_after_purchase($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if (!$product || !$product->managing_stock()) {
                continue;
            }
            
            // Forzar sincronización con API después de compra
            $this->force_stock_sync($product->get_id());
        }
    }
    
    /**
     * VALIDAR CANTIDAD EN CARRITO (AJAX)
     */
    public function validate_cart_item_quantity($quantity, $cart_item_key, $cart_item) {
        $product = $cart_item['data'];
        
        if (!$product->managing_stock()) {
            return $quantity;
        }
        
        // Obtener stock real
        $real_stock = $this->get_real_stock($product->get_id());
        
        // Limitar a stock disponible
        if ($quantity > $real_stock) {
            $quantity = $real_stock;
        }
        
        return $quantity;
    }
    
    /**
     * SINCRONIZACIÓN FORZADA DE STOCK
     */
    public function force_stock_sync($product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product || !$product->managing_stock()) {
            return false;
        }
        
        // Obtener datos frescos de API (bypass cache)
        $cache_key = 'woo_update_api_product_' . md5($product_id . $product->get_sku());
        delete_transient($cache_key);
        
        // Obtener datos frescos
        $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());
        
        if ($api_data && isset($api_data['totalstock'])) {
            $api_stock = intval($api_data['totalstock']);
            $current_stock = $product->get_stock_quantity();
            
            if ($api_stock !== $current_stock) {
                $product->set_stock_quantity($api_stock);
                $product->save();
                
                $this->log_sync($product_id, $current_stock, $api_stock);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * OBTENER STOCK REAL (API o WooCommerce)
     */
    public function get_real_stock($product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return 0;
        }
        
        // 1. Intentar obtener de API
        $api_data = $this->api_handler->get_product_data(
            $product->get_id(),
            $product->get_sku()
        );
        
        if ($api_data && isset($api_data['totalstock'])) {
            return intval($api_data['totalstock']);
        }
        
        // 2. Fallback a stock de WooCommerce
        return $product->get_stock_quantity();
    }
    
    /**
     * SINCRONIZACIÓN ASÍNCRONA
     */
    public function async_stock_sync($product_id, $api_stock) {
        $product = wc_get_product($product_id);
        
        if ($product && $product->managing_stock()) {
            $current_stock = $product->get_stock_quantity();
            
            if ($api_stock !== $current_stock) {
                $product->set_stock_quantity($api_stock);
                $product->save();
                
                $this->log_sync($product_id, $current_stock, $api_stock, 'async');
            }
        }
    }
    
    /**
     * PROGRAMAR SINCRONIZACIÓN ASÍNCRONA
     */
    private function schedule_async_sync($product_id, $api_stock) {
        wp_schedule_single_event(
            time() + 30, // 30 segundos después
            'woo_update_api_async_stock_sync',
            [$product_id, $api_stock]
        );
    }
    
    /**
     * REGISTRAR LOG DE SINCRONIZACIÓN
     */
    private function log_sync($product_id, $old_stock, $new_stock, $type = 'sync') {
        $log_entry = sprintf(
            '[%s] [%s] Stock sincronizado - Producto: %d, Viejo: %d, Nuevo: %d',
            current_time('mysql'),
            $type,
            $product_id,
            $old_stock,
            $new_stock
        );
        
        // Guardar en opción para panel admin
        $sync_logs = get_option('woo_update_api_sync_logs', []);
        $sync_logs[] = $log_entry;
        
        // Mantener solo últimos 100 registros
        if (count($sync_logs) > 100) {
            $sync_logs = array_slice($sync_logs, -100);
        }
        
        update_option('woo_update_api_sync_logs', $sync_logs, false);
        
        // También a error log si debug activado
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Woo Update API] ' . $log_entry);
        }
    }
}