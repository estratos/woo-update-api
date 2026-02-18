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
        
        // VALIDACIÓN AL AÑADIR AL CARRITO
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 20, 4);

        // ACTUALIZAR BD AL AGREGAR AL CARRITO
        add_action('woocommerce_add_to_cart', [$this, 'update_on_add_to_cart'], 1, 6);
        add_action('woocommerce_ajax_added_to_cart', [$this, 'update_on_ajax_add_to_cart'], 1, 1);
        
        // VALIDACIÓN EN CHECKOUT
        add_action('woocommerce_checkout_process', [$this, 'validate_checkout_stock']);
        add_action('woocommerce_before_checkout_process', [$this, 'validate_checkout_stock']);
        
        // ACTUALIZAR DESPUÉS DE COMPRA
        add_action('woocommerce_payment_complete', [$this, 'update_stock_after_purchase'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'update_stock_after_purchase'], 10, 1);

        // ========== BACKEND (ADMIN) ==========
        add_action('wp_ajax_woo_update_api_sync_to_db', [$this, 'ajax_sync_to_db']);
        add_action('wp_ajax_woo_update_api_validate_stock', [$this, 'ajax_validate_stock']);
        add_action('wp_ajax_nopriv_woo_update_api_validate_stock', [$this, 'ajax_validate_stock']);
    }

    /**
     * ACTUALIZAR BD AL AÑADIR AL CARRITO - SIN CACHÉ
     */
    public function update_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        try {
            $actual_product_id = $variation_id ? $variation_id : $product_id;
            
            error_log('[Add to Cart] ===== INICIANDO ACTUALIZACIÓN =====');
            error_log('[Add to Cart] Producto ID: ' . $actual_product_id);
            
            // Prevenir múltiples ejecuciones
            $lock_key = 'update_lock_' . $actual_product_id;
            if (isset($this->update_lock[$lock_key]) && $this->update_lock[$lock_key] > time() - 2) {
                error_log('[Add to Cart] BLOQUEADO - Ya se está actualizando');
                return;
            }
            
            $this->update_lock[$lock_key] = time();
            
            // Obtener producto
            $product = wc_get_product($actual_product_id);
            if (!$product) {
                error_log('[Add to Cart] ERROR - Producto no encontrado');
                return;
            }
            
            // CONSULTAR API DIRECTAMENTE - SIN CACHÉ
            error_log('[Add to Cart] Consultando API para SKU: ' . $product->get_sku());
            
            // Llamada directa a API sin ningún filtro ni caché
            $api_data = $this->api_handler->get_product_data_direct($actual_product_id, $product->get_sku());
            
            if ($api_data === false) {
                error_log('[Add to Cart] ERROR - No se pudo obtener datos de API');
                return;
            }
            
            error_log('[Add to Cart] API RESPONSE: ' . print_r($api_data, true));
            
            $updated = false;
            $updates = [];
            
            // 1. ACTUALIZAR PRECIO
            if (isset($api_data['price_mxn'])) {
                $api_price = floatval($api_data['price_mxn']);
            } elseif (isset($api_data['price'])) {
                $api_price = floatval($api_data['price']);
            } else {
                $api_price = null;
            }
            
            if ($api_price !== null) {
                $current_price = floatval($product->get_price());
                error_log('[Add to Cart] Precio API: ' . $api_price . ' | Precio BD: ' . $current_price);
                
                // Actualizar SIEMPRE (forzar actualización)
                update_post_meta($actual_product_id, '_price', $api_price);
                update_post_meta($actual_product_id, '_regular_price', $api_price);
                
                if ($product->is_type('variation')) {
                    update_post_meta($actual_product_id, '_variation_price', $api_price);
                }
                
                $updates[] = 'precio: ' . $current_price . ' → ' . $api_price;
                $updated = true;
                error_log('[Add to Cart] ✅ Precio ACTUALIZADO en BD');
            }
            
            // 2. ACTUALIZAR STOCK
            if (isset($api_data['stock_quantity']) && $product->managing_stock()) {
                $api_stock = intval($api_data['stock_quantity']);
                $current_stock = intval($product->get_stock_quantity());
                
                error_log('[Add to Cart] Stock API: ' . $api_stock . ' | Stock BD: ' . $current_stock);
                
                wc_update_product_stock($actual_product_id, $api_stock);
                update_post_meta($actual_product_id, '_stock', $api_stock);
                
                $updates[] = 'stock: ' . $current_stock . ' → ' . $api_stock;
                $updated = true;
                error_log('[Add to Cart] ✅ Stock ACTUALIZADO en BD');
            }
            
            // 3. GUARDAR TIMESTAMP
            if ($updated) {
                update_post_meta($actual_product_id, '_last_api_sync', current_time('mysql'));
                wc_delete_product_transients($actual_product_id);
                error_log('[Add to Cart] ✅ Producto ACTUALIZADO: ' . implode(', ', $updates));
            } else {
                error_log('[Add to Cart] ⚠️ No se detectaron cambios');
            }
            
            error_log('[Add to Cart] ===== FINALIZADA ACTUALIZACIÓN =====');
            
        } catch (Exception $e) {
            error_log('[Add to Cart] EXCEPCIÓN: ' . $e->getMessage());
        } finally {
            if (isset($lock_key)) {
                unset($this->update_lock[$lock_key]);
            }
        }
    }

    public function update_on_ajax_add_to_cart($product_id)
    {
        $this->update_on_add_to_cart('', $product_id, 1, 0, null, null);
    }

    /**
     * VALIDACIÓN EN CHECKOUT - SIN CACHÉ
     */
    public function validate_checkout_stock()
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        $cart = WC()->cart;
        $cart_items = $cart->get_cart();
        
        if (empty($cart_items)) {
            return;
        }

        error_log('[Checkout] ===== VALIDANDO STOCK =====');
        
        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            $quantity = $cart_item['quantity'];
            $product = $cart_item['data'];
            
            error_log('[Checkout] Producto: ' . $product_id . ' - SKU: ' . $product->get_sku() . ' - Cantidad: ' . $quantity);
            
            // CONSULTA DIRECTA A API
            $api_data = $this->api_handler->get_product_data_direct($product_id, $product->get_sku());
            
            if ($api_data && isset($api_data['stock_quantity'])) {
                $real_stock = intval($api_data['stock_quantity']);
                error_log('[Checkout] Stock real API: ' . $real_stock);
                
                if ($real_stock < $quantity) {
                    error_log('[Checkout] ⚠️ STOCK INSUFICIENTE');
                    
                    if ($real_stock > 0) {
                        WC()->cart->set_quantity($cart_item_key, $real_stock);
                        wc_add_notice(
                            sprintf(
                                __('La cantidad de "%s" ha sido ajustada a %d unidades disponibles.', 'woo-update-api'),
                                $product->get_name(),
                                $real_stock
                            ),
                            'notice'
                        );
                    } else {
                        WC()->cart->remove_cart_item($cart_item_key);
                        wc_add_notice(
                            sprintf(
                                __('"%s" ha sido removido del carrito por falta de stock.', 'woo-update-api'),
                                $product->get_name()
                            ),
                            'error'
                        );
                    }
                }
            }
        }
        
        error_log('[Checkout] ===== VALIDACIÓN COMPLETADA =====');
    }

    /**
     * VALIDACIÓN AL AÑADIR AL CARRITO - SIN CACHÉ
     */
    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0)
    {
        try {
            $actual_product_id = $variation_id ? $variation_id : $product_id;
            
            $product = wc_get_product($actual_product_id);
            if (!$product || !$product->managing_stock()) {
                return $passed;
            }

            error_log('[Validate] ===== VALIDANDO ANTES DE AÑADIR =====');
            error_log('[Validate] Producto: ' . $actual_product_id . ' - SKU: ' . $product->get_sku() . ' - Cantidad: ' . $quantity);
            
            // CONSULTA DIRECTA A API
            $api_data = $this->api_handler->get_product_data_direct($actual_product_id, $product->get_sku());
            
            if ($api_data && isset($api_data['stock_quantity'])) {
                $real_stock = intval($api_data['stock_quantity']);
                error_log('[Validate] Stock real API: ' . $real_stock);
                
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
                error_log('[Validate] En carrito: ' . $cart_quantity . ' - Total solicitado: ' . $total_requested);
                
                if ($total_requested > $real_stock) {
                    $available = max(0, $real_stock - $cart_quantity);
                    error_log('[Validate] ❌ STOCK INSUFICIENTE - Disponible: ' . $available);
                    
                    wc_add_notice(
                        sprintf(__('No hay suficiente stock. Solo %d disponible(s).', 'woo-update-api'), $available),
                        'error'
                    );
                    
                    return false;
                }
                
                error_log('[Validate] ✅ STOCK SUFICIENTE');
            }
            
            return $passed;

        } catch (Exception $e) {
            error_log('[Validate] Error: ' . $e->getMessage());
            return $passed;
        }
    }

    public function update_stock_after_purchase($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        error_log('[Post Purchase] ===== ACTUALIZANDO DESPUÉS DE COMPRA =====');
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_variation_id() ?: $item->get_product_id();
            error_log('[Post Purchase] Producto: ' . $product_id);
            
            // CONSULTA DIRECTA A API
            $product = wc_get_product($product_id);
            if ($product) {
                $api_data = $this->api_handler->get_product_data_direct($product_id, $product->get_sku());
                
                if ($api_data && isset($api_data['stock_quantity'])) {
                    wc_update_product_stock($product_id, intval($api_data['stock_quantity']));
                    error_log('[Post Purchase] Stock actualizado: ' . $api_data['stock_quantity']);
                }
            }
        }
    }

    // ========== MÉTODOS ADMIN ==========

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
            $product = wc_get_product($product_id);
            if (!$product) {
                throw new Exception(__('Product not found', 'woo-update-api'));
            }
            
            // CONSULTA DIRECTA A API
            $api_data = $this->api_handler->get_product_data_direct($product_id, $product->get_sku());
            
            if ($api_data === false) {
                throw new Exception(__('Could not get API data', 'woo-update-api'));
            }
            
            $updates = [];
            
            // Actualizar precio
            if (isset($api_data['price_mxn']) || isset($api_data['price'])) {
                $price = isset($api_data['price_mxn']) ? floatval($api_data['price_mxn']) : floatval($api_data['price']);
                update_post_meta($product_id, '_price', $price);
                update_post_meta($product_id, '_regular_price', $price);
                $updates['price'] = $price;
            }
            
            // Actualizar stock
            if (isset($api_data['stock_quantity']) && $product->managing_stock()) {
                wc_update_product_stock($product_id, intval($api_data['stock_quantity']));
                $updates['stock'] = $api_data['stock_quantity'];
            }
            
            update_post_meta($product_id, '_last_api_sync', current_time('mysql'));
            
            wp_send_json_success([
                'message' => __('Product synchronized successfully', 'woo-update-api'),
                'updates' => $updates
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_validate_stock()
    {
        check_ajax_referer('woo_update_api_nonce', 'nonce');
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID', 'woo-update-api')]);
        }

        try {
            $actual_product_id = $variation_id ? $variation_id : $product_id;
            $product = wc_get_product($actual_product_id);
            
            if (!$product) {
                throw new Exception(__('Product not found', 'woo-update-api'));
            }

            // CONSULTA DIRECTA A API
            $api_data = $this->api_handler->get_product_data_direct($actual_product_id, $product->get_sku());
            
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
}