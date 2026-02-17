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
        // VALIDACIÓN AL AÑADIR AL CARRITO
        add_filter('woocommerce_add_to_cart_validation', 
            [$this, 'validate_add_to_cart'], 20, 4);

        // NUEVO: Actualizar BD al agregar al carrito - PRIORIDAD ALTA
        add_action('woocommerce_add_to_cart', 
            [$this, 'update_on_add_to_cart'], 1, 6); // Prioridad 1 para ejecutar primero
        
        // También hook para AJAX add to cart
        add_action('woocommerce_ajax_added_to_cart', 
            [$this, 'update_on_ajax_add_to_cart'], 1, 1);

        // VALIDACIÓN DURANTE CHECKOUT
        add_action('woocommerce_check_cart_items', 
            [$this, 'validate_cart_stock']);
        
        // Validar durante checkout
        add_action('woocommerce_after_checkout_validation', 
            [$this, 'validate_checkout_stock'], 10, 2);

        // SINCRONIZAR STOCK REAL EN BD ANTES DE CHECKOUT
        add_action('woocommerce_before_checkout_process', 
            [$this, 'sync_stock_before_checkout']);

        // VALIDACIÓN DE INVENTARIO ANTES DE PAGO
        add_action('woocommerce_checkout_order_processed', 
            [$this, 'validate_inventory_before_payment'], 10, 1);

        // ACTUALIZAR STOCK DESPUÉS DE COMPRA
        add_action('woocommerce_payment_complete', 
            [$this, 'update_stock_after_purchase'], 10, 1);

        // VALIDACIÓN EN CARRITO (AJAX)
        add_filter('woocommerce_cart_item_quantity', 
            [$this, 'validate_cart_item_quantity'], 10, 3);

        // SINCRONIZACIÓN ASÍNCRONA DE STOCK
        add_action('woo_update_api_async_stock_sync', 
            [$this, 'async_stock_sync'], 10, 2);
        
        // Sincronización en vista de producto
        add_action('woocommerce_before_single_product', 
            [$this, 'sync_stock_on_product_view']);
        
        // Sincronización en página de carrito
        add_action('woocommerce_before_cart', 
            [$this, 'sync_stock_on_cart_page']);
        add_action('woocommerce_before_checkout_form', 
            [$this, 'sync_stock_on_cart_page']);
        
        // Mostrar errores de API en carrito
        add_action('woocommerce_before_cart', 
            [$this, 'show_api_errors_in_cart']);
        add_action('woocommerce_before_checkout_form', 
            [$this, 'show_api_errors_in_cart']);
        
        // DEBUG: Log para diagnóstico
        add_action('wp_head', [$this, 'debug_cart_actions']);
    }
    
    /**
     * DEBUG: Log para diagnóstico de acciones del carrito
     */
    public function debug_cart_actions() {
        if (isset($_GET['debug_cart']) && current_user_can('administrator')) {
            error_log('[DEBUG CART] Current action: ' . current_action());
            error_log('[DEBUG CART] POST data: ' . print_r($_POST, true));
            error_log('[DEBUG CART] GET data: ' . print_r($_GET, true));
            
            if (function_exists('WC') && WC()->cart) {
                error_log('[DEBUG CART] Cart items count: ' . count(WC()->cart->get_cart()));
            }
        }
    }

    /**
     * ACTUALIZAR BD AL AÑADIR AL CARRITO (VERSIÓN CORREGIDA)
     */
    public function update_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        try {
            // Determinar ID real del producto (variación o producto simple)
            $actual_product_id = $variation_id ? $variation_id : $product_id;
            
            error_log('[Add to Cart START] Product ID: ' . $product_id . 
                     ' | Variation ID: ' . $variation_id . 
                     ' | Actual ID: ' . $actual_product_id . 
                     ' | Quantity: ' . $quantity);
            
            // Prevenir múltiples ejecuciones para el mismo producto en corto tiempo
            $lock_key = 'update_lock_' . $actual_product_id;
            if (isset($this->update_lock[$lock_key]) && 
                $this->update_lock[$lock_key] > time() - 2) {
                error_log('[Add to Cart SKIP] Already updating product: ' . $actual_product_id);
                return;
            }
            
            $this->update_lock[$lock_key] = time();
            
            // Obtener el producto
            $product = wc_get_product($actual_product_id);
            
            if (!$product) {
                error_log('[Add to Cart ERROR] Product not found: ' . $actual_product_id);
                return;
            }
            
            // Verificar si el producto maneja stock
            if (!$product->managing_stock()) {
                error_log('[Add to Cart SKIP] Product does not manage stock: ' . $actual_product_id);
                return;
            }
            
            // Obtener SKU del producto
            $sku = $product->get_sku();
            error_log('[Add to Cart] Product SKU: ' . $sku . ' | Name: ' . $product->get_name());
            
            // Limpiar cache para obtener datos frescos
            $cache_key = 'woo_update_api_product_' . md5($actual_product_id . $sku);
            delete_transient($cache_key);
            
            // Obtener datos de API
            $api_data = $this->api_handler->get_product_data($actual_product_id, $sku);
            
            if ($api_data === false) {
                error_log('[Add to Cart ERROR] Could not get API data for: ' . $actual_product_id);
                return;
            }
            
            error_log('[Add to Cart] API Data received: ' . print_r($api_data, true));
            
            $updated = false;
            $updates_log = [];
            
            // 1. ACTUALIZAR PRECIO EN BD si está en API
            if (isset($api_data['price_mxn']) || isset($api_data['price'])) {
                $price = isset($api_data['price_mxn']) ? floatval($api_data['price_mxn']) : floatval($api_data['price']);
                $current_price = $product->get_price();
                
                // Solo actualizar si hay diferencia
                if (floatval($price) !== floatval($current_price)) {
                    // Actualizar metadatos de precio
                    update_post_meta($actual_product_id, '_price', $price);
                    update_post_meta($actual_product_id, '_regular_price', $price);
                    
                    // Para variaciones
                    if ($product->is_type('variation')) {
                        update_post_meta($actual_product_id, '_variation_price', $price);
                        // Actualizar también el padre para cache
                        $parent_id = $product->get_parent_id();
                        if ($parent_id) {
                            wc_delete_product_transients($parent_id);
                        }
                    }
                    
                    $updates_log[] = 'Price updated: ' . $current_price . ' → ' . $price;
                    $updated = true;
                }
            }
            
            // 2. ACTUALIZAR STOCK EN BD si está en API
            if (isset($api_data['stock_quantity'])) {
                $api_stock = intval($api_data['stock_quantity']);
                $current_stock = $product->get_stock_quantity();
                
                error_log('[Add to Cart] Stock comparison - API: ' . $api_stock . ' | Current: ' . $current_stock);
                
                if ($api_stock !== $current_stock) {
                    // Actualizar stock en WooCommerce
                    $product->set_stock_quantity($api_stock);
                    
                    // IMPORTANTE: Guardar el producto para persistir cambios
                    $product->save();
                    
                    // Actualizar también el meta directamente por si acaso
                    update_post_meta($actual_product_id, '_stock', $api_stock);
                    
                    $updates_log[] = 'Stock updated: ' . $current_stock . ' → ' . $api_stock;
                    $updated = true;
                    
                    // Log detallado
                    error_log('[Add to Cart SUCCESS] Stock updated in DB: ' . 
                             $actual_product_id . ' = ' . $api_stock . 
                             ' (was: ' . $current_stock . ')');
                } else {
                    error_log('[Add to Cart] Stock already up to date: ' . $api_stock);
                }
            } else {
                error_log('[Add to Cart WARNING] No stock_quantity in API data');
            }
            
            // 3. Guardar timestamp y datos de API
            if ($updated || isset($api_data['stock_quantity']) || isset($api_data['price_mxn']) || isset($api_data['price'])) {
                update_post_meta($actual_product_id, '_last_api_sync', current_time('mysql'));
                update_post_meta($actual_product_id, '_api_data_cache', $api_data);
                
                // Limpiar cache de WooCommerce
                wc_delete_product_transients($actual_product_id);
                
                if (!empty($updates_log)) {
                    error_log('[Add to Cart] Updates made: ' . implode(', ', $updates_log));
                } else {
                    error_log('[Add to Cart] No changes needed, but API data cached');
                }
            }
            
            // 4. Verificar que los cambios se guardaron
            $product_after = wc_get_product($actual_product_id);
            if ($product_after) {
                $final_stock = $product_after->get_stock_quantity();
                error_log('[Add to Cart VERIFICATION] Final stock in DB: ' . $final_stock);
                
                // Verificar en la base de datos directamente
                $db_stock = get_post_meta($actual_product_id, '_stock', true);
                error_log('[Add to Cart VERIFICATION] Direct DB meta _stock: ' . $db_stock);
            }
            
        } catch (Exception $e) {
            error_log('[Add to Cart EXCEPTION] ' . $e->getMessage());
            error_log('[Add to Cart EXCEPTION] Trace: ' . $e->getTraceAsString());
        } finally {
            // Liberar lock
            if (isset($lock_key)) {
                unset($this->update_lock[$lock_key]);
            }
        }
    }
    
    /**
     * ACTUALIZAR BD PARA AJAX ADD TO CART
     */
    public function update_on_ajax_add_to_cart($product_id) {
        error_log('[AJAX Add to Cart] Triggered for product: ' . $product_id);
        $this->update_on_add_to_cart('', $product_id, 1, 0, null, null);
    }

    /**
     * MÉTODO SIMPLIFICADO PARA FORZAR ACTUALIZACIÓN DE STOCK
     */
    public function force_update_stock_from_api($product_id) {
        try {
            error_log('[Force Stock Update] Starting for product: ' . $product_id);
            
            $product = wc_get_product($product_id);
            if (!$product || !$product->managing_stock()) {
                error_log('[Force Stock Update] Product not found or no stock management: ' . $product_id);
                return false;
            }
            
            $sku = $product->get_sku();
            
            // Limpiar cache
            $cache_key = 'woo_update_api_product_' . md5($product_id . $sku);
            delete_transient($cache_key);
            
            // Obtener datos frescos
            $api_data = $this->api_handler->get_product_data($product_id, $sku);
            
            if ($api_data === false) {
                error_log('[Force Stock Update] API data false for: ' . $product_id);
                return false;
            }
            
            if (isset($api_data['stock_quantity'])) {
                $api_stock = intval($api_data['stock_quantity']);
                $current_stock = $product->get_stock_quantity();
                
                error_log('[Force Stock Update] API Stock: ' . $api_stock . ' | Current: ' . $current_stock);
                
                if ($api_stock !== $current_stock) {
                    // Método directo de actualización
                    wc_update_product_stock($product_id, $api_stock);
                    
                    // También actualizar meta directamente
                    update_post_meta($product_id, '_stock', $api_stock);
                    
                    // Para variaciones
                    if ($product->is_type('variation')) {
                        update_post_meta($product_id, '_stock', $api_stock);
                    }
                    
                    error_log('[Force Stock Update SUCCESS] Updated: ' . $product_id . ' = ' . $api_stock);
                    
                    // Actualizar timestamp
                    update_post_meta($product_id, '_last_api_sync', current_time('mysql'));
                    
                    return true;
                } else {
                    error_log('[Force Stock Update] Stock already correct: ' . $api_stock);
                }
            } else {
                error_log('[Force Stock Update] No stock_quantity in API data');
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log('[Force Stock Update ERROR] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * VALIDACIÓN AL AÑADIR PRODUCTO AL CARRITO (VERSIÓN MEJORADA)
     */
    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0) {
        try {
            $actual_product_id = $variation_id ? $variation_id : $product_id;
            error_log('[Validate Add to Cart] Starting for: ' . $actual_product_id);
            
            $product = wc_get_product($actual_product_id);
            
            if (!$product || !$product->managing_stock()) {
                return $passed;
            }
            
            // 1. FORZAR ACTUALIZACIÓN DE STOCK DESDE API
            $this->force_update_stock_from_api($actual_product_id);
            
            // 2. Obtener stock actualizado
            $real_stock = $product->get_stock_quantity();
            error_log('[Validate Add to Cart] Current stock: ' . $real_stock);
            
            // 3. Calcular cantidad total en carrito
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
            
            $total_requested = $cart_quantity + $quantity;
            error_log('[Validate Add to Cart] Cart qty: ' . $cart_quantity . ' | New qty: ' . $quantity . ' | Total: ' . $total_requested);
            
            // 4. Validar stock
            if ($total_requested > $real_stock) {
                $available = max(0, $real_stock - $cart_quantity);
                
                wc_add_notice(
                    sprintf(__('No hay suficiente stock. Solo %d disponible(s).', 'woo-update-api'), $available),
                    'error'
                );
                
                error_log('[Validate Add to Cart] FAILED: Requested ' . $total_requested . ' but only ' . $real_stock . ' available');
                return false;
            }
            
            error_log('[Validate Add to Cart] PASSED: Stock sufficient');
            return $passed;
            
        } catch (Exception $e) {
            error_log('[Validate Add to Cart ERROR] ' . $e->getMessage());
            return $passed; // Permitir continuar con validación normal
        }
    }

    /**
     * OBTENER STOCK REAL (VERSIÓN MEJORADA)
     */
    public function get_real_stock($product_id) {
        try {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                error_log('[Get Real Stock] Product not found: ' . $product_id);
                return 0;
            }
            
            if (!$product->managing_stock()) {
                return $product->get_stock_quantity();
            }
            
            // Obtener datos de API
            $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());
            
            if ($api_data && isset($api_data['stock_quantity'])) {
                $api_stock = intval($api_data['stock_quantity']);
                $current_stock = $product->get_stock_quantity();
                
                // Si hay diferencia, actualizar BD
                if ($api_stock !== $current_stock) {
                    error_log('[Get Real Stock] Updating DB from API: ' . $current_stock . ' → ' . $api_stock);
                    wc_update_product_stock($product_id, $api_stock);
                    update_post_meta($product_id, '_last_api_sync', current_time('mysql'));
                }
                
                return $api_stock;
            }
            
            // Fallback a stock de WooCommerce
            return $product->get_stock_quantity();
            
        } catch (Exception $e) {
            error_log('[Get Real Stock ERROR] ' . $e->getMessage());
            $product = wc_get_product($product_id);
            return $product ? $product->get_stock_quantity() : 0;
        }
    }

    // ============ MÉTODOS FALTANTES ============
    
    /**
     * VALIDACIÓN DE STOCK EN EL CARRITO
     */
    public function validate_cart_stock() {
        try {
            error_log('[Stock_Synchronizer] validate_cart_stock called');
            
            if (!function_exists('WC') || !WC()->cart) {
                return;
            }
            
            $cart = WC()->cart;
            $cart_items = $cart->get_cart();
            
            if (empty($cart_items)) {
                return;
            }
            
            $has_errors = false;
            
            foreach ($cart_items as $cart_item_key => $cart_item) {
                $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
                $quantity = $cart_item['quantity'];
                $product = $cart_item['data'];
                
                // Forzar actualización de stock
                $this->force_update_stock_from_api($product_id);
                
                // Verificar stock
                $real_stock = $this->get_real_stock($product_id);
                
                if ($real_stock < $quantity) {
                    $has_errors = true;
                    
                    $message = sprintf(
                        __('Lo sentimos, "%s" no tiene suficiente stock. Solo %d disponible(s) (has solicitado %d).', 'woo-update-api'),
                        $product->get_name(),
                        $real_stock,
                        $quantity
                    );
                    
                    wc_add_notice($message, 'error');
                    
                    // Ajustar cantidad si hay algo de stock
                    if ($real_stock > 0) {
                        $cart->set_quantity($cart_item_key, $real_stock);
                    } else {
                        $cart->remove_cart_item($cart_item_key);
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in validate_cart_stock: ' . $e->getMessage());
        }
    }
    
    /**
     * VALIDAR CHECKOUT STOCK
     */
    public function validate_checkout_stock($data, $errors) {
        try {
            error_log('[Stock_Synchronizer] validate_checkout_stock called');
            
            if (!function_exists('WC') || !WC()->cart) {
                return;
            }
            
            $cart = WC()->cart;
            $cart_items = $cart->get_cart();
            
            foreach ($cart_items as $cart_item_key => $cart_item) {
                $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
                $quantity = $cart_item['quantity'];
                $product = $cart_item['data'];
                
                // Forzar actualización de stock
                $this->force_update_stock_from_api($product_id);
                
                // Verificar stock
                $real_stock = $this->get_real_stock($product_id);
                
                if ($real_stock < $quantity) {
                    $errors->add(
                        'out_of_stock',
                        sprintf(
                            __('Lo sentimos, "%s" no tiene suficiente stock. Solo %d disponible(s) y has solicitado %d.', 'woo-update-api'),
                            $product->get_name(),
                            $real_stock,
                            $quantity
                        )
                    );
                }
            }
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in validate_checkout_stock: ' . $e->getMessage());
        }
    }
    
    /**
     * SINCRONIZAR STOCK ANTES DE CHECKOUT
     */
    public function sync_stock_before_checkout() {
        try {
            error_log('[Stock_Synchronizer] sync_stock_before_checkout called');
            
            if (!function_exists('WC') || !WC()->cart) {
                return;
            }
            
            $cart = WC()->cart;
            $cart_items = $cart->get_cart();
            
            foreach ($cart_items as $cart_item) {
                $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
                $this->force_update_stock_from_api($product_id);
            }
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in sync_stock_before_checkout: ' . $e->getMessage());
        }
    }
    
    /**
     * VALIDAR INVENTARIO ANTES DE PAGO
     */
    public function validate_inventory_before_payment($order_id) {
        try {
            error_log('[Stock_Synchronizer] validate_inventory_before_payment called for order: ' . $order_id);
            
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }
            
            $items = $order->get_items();
            
            foreach ($items as $item) {
                $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
                $quantity = $item->get_quantity();
                
                // Verificar stock
                $real_stock = $this->get_real_stock($product_id);
                
                if ($real_stock < $quantity) {
                    $order->update_status('on-hold', __('Stock insuficiente verificado antes de pago.'));
                    break;
                }
            }
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in validate_inventory_before_payment: ' . $e->getMessage());
        }
    }
    
    /**
     * ACTUALIZAR STOCK DESPUÉS DE COMPRA
     */
    public function update_stock_after_purchase($order_id) {
        try {
            error_log('[Stock_Synchronizer] update_stock_after_purchase called for order: ' . $order_id);
            
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }
            
            $items = $order->get_items();
            
            foreach ($items as $item) {
                $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
                
                // Actualizar stock
                $this->force_update_stock_from_api($product_id);
            }
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in update_stock_after_purchase: ' . $e->getMessage());
        }
    }
    
    /**
     * VALIDAR CANTIDAD DE ITEM EN CARRITO
     */
    public function validate_cart_item_quantity($quantity, $cart_item_key, $cart_item) {
        try {
            error_log('[Stock_Synchronizer] validate_cart_item_quantity called');
            
            if (!isset($cart_item['data'])) {
                return $quantity;
            }
            
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            
            // Obtener stock real
            $real_stock = $this->get_real_stock($product_id);
            
            // Asegurar que la cantidad no exceda el stock
            if ($quantity > $real_stock) {
                $quantity = max(0, $real_stock);
            }
            
            return $quantity;
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in validate_cart_item_quantity: ' . $e->getMessage());
            return $quantity;
        }
    }
    
    /**
     * SINCRONIZACIÓN ASÍNCRONA DE STOCK
     */
    public function async_stock_sync($product_id, $force = false) {
        try {
            error_log('[Stock_Synchronizer] async_stock_sync called for product: ' . $product_id);
            
            if ($force) {
                $this->force_update_stock_from_api($product_id);
            } else {
                $product = wc_get_product($product_id);
                if ($product && $product->managing_stock()) {
                    $sku = $product->get_sku();
                    $api_data = $this->api_handler->get_product_data($product_id, $sku);
                    
                    if ($api_data && isset($api_data['stock_quantity'])) {
                        $current_stock = $product->get_stock_quantity();
                        $api_stock = intval($api_data['stock_quantity']);
                        
                        if ($api_stock !== $current_stock) {
                            wc_update_product_stock($product_id, $api_stock);
                            update_post_meta($product_id, '_last_api_sync', current_time('mysql'));
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in async_stock_sync: ' . $e->getMessage());
        }
    }
    
    /**
     * SINCRONIZACIÓN EN VISTA DE PRODUCTO
     */
    public function sync_stock_on_product_view() {
        try {
            error_log('[Stock_Synchronizer] sync_stock_on_product_view called');
            
            if (!is_product()) {
                return;
            }
            
            global $product;
            if ($product) {
                $this->force_update_stock_from_api($product->get_id());
            }
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in sync_stock_on_product_view: ' . $e->getMessage());
        }
    }
    
    /**
     * SINCRONIZACIÓN EN PÁGINA DE CARRITO
     */
    public function sync_stock_on_cart_page() {
        try {
            error_log('[Stock_Synchronizer] sync_stock_on_cart_page called');
            
            if (!function_exists('WC') || !WC()->cart) {
                return;
            }
            
            $cart = WC()->cart;
            $cart_items = $cart->get_cart();
            
            foreach ($cart_items as $cart_item) {
                $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
                $this->force_update_stock_from_api($product_id);
            }
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in sync_stock_on_cart_page: ' . $e->getMessage());
        }
    }
    
    /**
     * MOSTRAR ERRORES DE API EN CARRITO
     */
    public function show_api_errors_in_cart() {
        try {
            error_log('[Stock_Synchronizer] show_api_errors_in_cart called');
            
            $api_errors = get_transient('woo_update_api_errors');
            
            if (!empty($api_errors) && is_array($api_errors)) {
                foreach ($api_errors as $error) {
                    wc_add_notice($error, 'error');
                }
                delete_transient('woo_update_api_errors');
            }
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in show_api_errors_in_cart: ' . $e->getMessage());
        }
    }

    /**
     * SINCRONIZACIÓN FORZADA DE STOCK (VERSIÓN SIMPLIFICADA)
     */
    public function force_stock_sync($product_id) {
        return $this->force_update_stock_from_api($product_id);
    }

    /**
     * VERIFICAR ESTADO DE SINCRONIZACIÓN
     */
    public function check_sync_status($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return ['error' => 'Product not found'];
        }
        
        $status = [
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'manages_stock' => $product->managing_stock(),
            'current_stock' => $product->get_stock_quantity(),
            'last_sync' => get_post_meta($product_id, '_last_api_sync', true),
            'api_cache' => get_post_meta($product_id, '_api_data_cache', true)
        ];
        
        // Obtener datos frescos de API para comparar
        $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());
        
        if ($api_data && isset($api_data['stock_quantity'])) {
            $status['api_stock'] = $api_data['stock_quantity'];
            $status['needs_sync'] = $api_data['stock_quantity'] != $product->get_stock_quantity();
        }
        
        return $status;
    }
}