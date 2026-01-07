<?php
namespace Woo_Update_API;

use Exception;

defined('ABSPATH') || exit;

class Stock_Synchronizer
{
    private static $instance = null;
    private $api_handler;
    private $update_lock = []; // Prevenir múltiples actualizaciones simultáneas

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

        // NUEVO: Actualizar BD al agregar al carrito
        add_action('woocommerce_add_to_cart', 
            [$this, 'update_on_add_to_cart'], 10, 6);
        
        // VALIDACIÓN DURANTE CHECKOUT
        add_action('woocommerce_check_cart_items', 
            [$this, 'validate_cart_stock']);
        
        // NUEVO: Validar durante checkout (después de validación)
        add_action('woocommerce_after_checkout_validation', 
            [$this, 'validate_checkout_stock'], 10, 2);

        // SINCRONIZAR STOCK REAL EN BD ANTES DE CHECKOUT
        add_action('woocommerce_before_checkout_process', 
            [$this, 'sync_stock_before_checkout']);

        // VALIDACIÓN DE INVENTARIO ANTES DE PAGO
        add_action('woocommerce_checkout_order_processed', 
            [$this, 'validate_inventory_before_payment'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', 
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
        
        // NUEVO: Sincronización en vista de producto
        add_action('woocommerce_before_single_product', 
            [$this, 'sync_stock_on_product_view']);
        
        // NUEVO: Sincronización en página de carrito
        add_action('woocommerce_before_cart', 
            [$this, 'sync_stock_on_cart_page']);
        add_action('woocommerce_before_checkout_form', 
            [$this, 'sync_stock_on_cart_page']);
        
        // NUEVO: Mostrar errores de API en carrito
        add_action('woocommerce_before_cart', 
            [$this, 'show_api_errors_in_cart']);
        add_action('woocommerce_before_checkout_form', 
            [$this, 'show_api_errors_in_cart']);
    }

    /**
     * ACTUALIZAR BD AL AÑADIR AL CARRITO
     */
    public function update_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        try {
            // Prevenir múltiples ejecuciones simultáneas para el mismo producto
            $actual_product_id = $variation_id ? $variation_id : $product_id;
            
            if (isset($this->update_lock[$actual_product_id]) && 
                $this->update_lock[$actual_product_id] > time() - 5) {
                return; // Ya se está actualizando este producto
            }
            
            $this->update_lock[$actual_product_id] = time();
            
            error_log('[Add to Cart] Actualizando BD para producto: ' . $actual_product_id);
            
            // 1. Obtener datos frescos de API (bypass cache parcial)
            $product = wc_get_product($actual_product_id);
            $cache_key = 'woo_update_api_product_' . md5($actual_product_id . $product->get_sku());
            delete_transient($cache_key);
            
            $api_data = $this->api_handler->get_product_data($actual_product_id, $product->get_sku());
            
            if ($api_data === false) {
                error_log('[Add to Cart] No se pudo obtener datos de API para: ' . $actual_product_id);
                return;
            }
            
            $updated = false;
            
            // 2. ACTUALIZAR PRECIO EN BD si está en API
            if (isset($api_data['price_mxn']) || isset($api_data['price'])) {
                $price = isset($api_data['price_mxn']) ? floatval($api_data['price_mxn']) : floatval($api_data['price']);
                
                // Actualizar metadatos de precio
                update_post_meta($actual_product_id, '_price', $price);
                update_post_meta($actual_product_id, '_regular_price', $price);
                
                // Para variaciones
                if ($product->is_type('variation')) {
                    update_post_meta($actual_product_id, '_variation_price', $price);
                    // Actualizar también el padre para cache
                    $parent_id = $product->get_parent_id();
                    wc_delete_product_transients($parent_id);
                }
                
                error_log('[Add to Cart] Precio actualizado en BD: ' . $actual_product_id . ' = ' . $price);
                $updated = true;
            }
            
            // 3. ACTUALIZAR STOCK EN BD si está en API
            if (isset($api_data['stock_quantity']) && $product->managing_stock()) {
                $api_stock = intval($api_data['stock_quantity']);
                $current_stock = $product->get_stock_quantity();
                
                if ($api_stock !== $current_stock) {
                    $product->set_stock_quantity($api_stock);
                    $product->save();
                    
                    error_log('[Add to Cart] Stock actualizado en BD: ' . $actual_product_id . 
                             ' de ' . $current_stock . ' a ' . $api_stock);
                    $updated = true;
                }
            }
            
            // 4. Guardar timestamp y datos de API
            if ($updated) {
                update_post_meta($actual_product_id, '_last_api_sync', current_time('mysql'));
                update_post_meta($actual_product_id, '_api_data_cache', $api_data);
                
                // Limpiar cache de WooCommerce
                wc_delete_product_transients($actual_product_id);
                
                error_log('[Add to Cart] Sincronización completada para: ' . $actual_product_id);
            } else {
                error_log('[Add to Cart] No se requirieron actualizaciones para: ' . $actual_product_id);
            }
            
        } catch (Exception $e) {
            error_log('[Add to Cart Error] ' . $e->getMessage());
        } finally {
            // Liberar lock después de 5 segundos
            if (isset($actual_product_id)) {
                unset($this->update_lock[$actual_product_id]);
            }
        }
    }

    /**
     * VALIDACIÓN DURANTE CHECKOUT
     */
    public function validate_checkout_stock($data, $errors) {
        try {
            $cart = WC()->cart;
            
            if (!$cart || $cart->is_empty()) {
                return;
            }
            
            error_log('[Checkout Validation] Iniciando validación de stock');
            
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                
                if (!$product || !$product->managing_stock()) {
                    continue;
                }
                
                $product_id = $product->get_id();
                $quantity_in_cart = $cart_item['quantity'];
                $stock_in_db = $product->get_stock_quantity();
                
                error_log('[Checkout Validation] Producto: ' . $product->get_name() . 
                         ' | Stock BD: ' . $stock_in_db . 
                         ' | En carrito: ' . $quantity_in_cart);
                
                // 1. Verificar stock en BD (ya debería estar actualizado de add_to_cart)
                if ($quantity_in_cart > $stock_in_db) {
                    $available = max(0, $stock_in_db);
                    
                    $errors->add(
                        'stock_error',
                        sprintf(
                            __('❌ Stock insuficiente para "%s". Solo %d disponible(s).', 'woo-update-api'),
                            $product->get_name(),
                            $available
                        )
                    );
                    
                    error_log('[Checkout Validation] Stock insuficiente: ' . $product_id . 
                             ' - BD: ' . $stock_in_db . ' - Carrito: ' . $quantity_in_cart);
                }
                
                // 2. Verificación FINAL con API (última validación)
                $this->final_api_validation($product_id, $quantity_in_cart);
            }
            
        } catch (Exception $e) {
            error_log('[Checkout Validation Error] ' . $e->getMessage());
        }
    }

    /**
     * VALIDACIÓN FINAL CON API (seguridad extra)
     */
    private function final_api_validation($product_id, $requested_quantity) {
        try {
            $api_data = $this->api_handler->get_product_data($product_id, '');
            
            if ($api_data && isset($api_data['stock_quantity'])) {
                $api_stock = intval($api_data['stock_quantity']);
                $product = wc_get_product($product_id);
                $stock_in_db = $product ? $product->get_stock_quantity() : 0;
                
                if ($requested_quantity > $api_stock) {
                    // Esto sería un error grave - BD no estaba sincronizada
                    error_log('[CRITICAL] Stock en BD desincronizado: ' . 
                             'Producto ' . $product_id . 
                             ' | API: ' . $api_stock . 
                             ' | BD: ' . $stock_in_db);
                    
                    // Forzar actualización de BD
                    if ($product && $product->managing_stock()) {
                        $product->set_stock_quantity($api_stock);
                        $product->save();
                        update_post_meta($product_id, '_last_api_sync', current_time('mysql'));
                        
                        error_log('[CRITICAL] BD actualizada de emergencia: ' . $stock_in_db . ' → ' . $api_stock);
                    }
                    
                    throw new Exception(
                        sprintf(
                            __('Stock actualizado durante checkout. Disponible: %d', 'woo-update-api'),
                            $api_stock
                        )
                    );
                }
            }
        } catch (Exception $e) {
            error_log('[Final Validation Error] ' . $e->getMessage());
        }
    }

    /**
     * VALIDACIÓN AL AÑADIR PRODUCTO AL CARRITO
     */
    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0) {
        try {
            $product = wc_get_product($variation_id ? $variation_id : $product_id);

            if (!$product || !$product->managing_stock()) {
                return $passed;
            }

            // 1. Primero sincronizar stock (llama a update_on_add_to_cart indirectamente)
            error_log('[Cart Validation] Sincronizando stock para validación: ' . $product_id);
            
            // Forzar obtención de datos frescos
            $api_data = $this->api_handler->get_product_data($product_id, '');
            
            if ($api_data && isset($api_data['stock_quantity'])) {
                $api_stock = intval($api_data['stock_quantity']);
                $current_stock = $product->get_stock_quantity();
                
                // Actualizar BD si hay diferencia
                if ($api_stock !== $current_stock) {
                    $product->set_stock_quantity($api_stock);
                    $product->save();
                    update_post_meta($product_id, '_last_api_sync', current_time('mysql'));
                    
                    error_log('[Cart Validation] BD actualizada durante validación: ' . 
                             $current_stock . ' → ' . $api_stock);
                }
                
                $real_stock = $api_stock;
            } else {
                $real_stock = $product->get_stock_quantity();
            }

            // 2. Validar stock contra carrito existente
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
            error_log('[Cart Validation Error] ' . $e->getMessage());
            
            // En modo depuración, mostrar error pero permitir continuar
            if ($this->api_handler->is_fallback_disabled_by_config() && !is_admin()) {
                wc_add_notice(
                    __('Error al verificar stock. La compra puede no completarse.', 'woo-update-api'),
                    'error'
                );
            }
            
            return $passed; // Permitir continuar con validación normal de WooCommerce
        }
    }

    /**
     * VALIDAR STOCK EN TODO EL CARRITO
     */
    public function validate_cart_stock() {
        try {
            $cart = WC()->cart;

            if (!$cart || $cart->is_empty()) {
                return;
            }

            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];

                if (!$product->managing_stock()) {
                    continue;
                }

                $product_id = $product->get_id();
                
                // Obtener stock real (con actualización si es necesario)
                $real_stock = $this->get_real_stock($product_id);

                // Verificar si hay suficiente stock
                if ($cart_item['quantity'] > $real_stock) {
                    $cart->set_quantity($cart_item_key, $real_stock);

                    wc_add_notice(
                        sprintf(__('Stock actualizado para "%s". Solo %d disponible(s).', 'woo-update-api'),
                            $product->get_name(),
                            $real_stock
                        ),
                        'notice'
                    );
                    
                    error_log('[Cart Stock Validation] Stock ajustado: ' . $product_id . 
                             ' - Nuevo: ' . $real_stock);
                }
            }
        } catch (Exception $e) {
            error_log('[Cart Stock Validation Error] ' . $e->getMessage());
        }
    }

    /**
     * SINCRONIZAR STOCK REAL ANTES DE CHECKOUT
     */
    public function sync_stock_before_checkout() {
        try {
            $cart = WC()->cart;

            if (!$cart || $cart->is_empty()) {
                return;
            }

            error_log('[Pre-Checkout Sync] Sincronizando stock antes de checkout');

            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];

                if (!$product->managing_stock()) {
                    continue;
                }

                // Obtener stock real de API y actualizar BD
                $real_stock = $this->get_real_stock($product->get_id());
                $wc_stock = $product->get_stock_quantity();

                // Si hay diferencia, ACTUALIZAR BD
                if ($real_stock !== $wc_stock) {
                    $product->set_stock_quantity($real_stock);
                    $product->save();

                    // Registrar para debugging
                    $this->log_sync($product->get_id(), $wc_stock, $real_stock, 'pre-checkout');
                    
                    error_log('[Pre-Checkout Sync] Actualizado: ' . $product->get_id() . 
                             ' - De: ' . $wc_stock . ' - A: ' . $real_stock);
                }
            }
        } catch (Exception $e) {
            error_log('[Stock Synchronizer Error] sync_stock_before_checkout: ' . $e->getMessage());
            // No bloquear checkout por error de sincronización
        }
    }

    /**
     * VALIDACIÓN DE INVENTARIO ANTES DE PROCESAR PAGO
     */
    public function validate_inventory_before_payment($order_id) {
        try {
            error_log('[Inventory Validation] Iniciando validación para orden: ' . $order_id);
            
            $order = wc_get_order($order_id);
            
            if (!$order) {
                error_log('[Inventory Validation] Orden no encontrada: ' . $order_id);
                return;
            }
            
            $out_of_stock_items = [];
            
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                
                if (!$product || !$product->managing_stock()) {
                    continue;
                }
                
                $quantity = $item->get_quantity();
                $product_id = $product->get_id();
                
                // 1. Obtener stock real actualizado
                $real_stock = $this->get_real_stock($product_id);
                error_log('[Inventory Validation] Producto ' . $product_id . 
                         ' - Stock real: ' . $real_stock . 
                         ' - Cantidad solicitada: ' . $quantity);
                
                // 2. Validar si hay suficiente stock
                if ($quantity > $real_stock) {
                    $out_of_stock_items[] = [
                        'product' => $product,
                        'requested' => $quantity,
                        'available' => $real_stock
                    ];
                    
                    error_log('[Inventory Validation] Stock insuficiente - Producto: ' . $product_id . 
                             ', Solicitado: ' . $quantity . 
                             ', Disponible: ' . $real_stock);
                }
            }
            
            // 3. Si hay productos sin stock, cancelar orden
            if (!empty($out_of_stock_items)) {
                foreach ($out_of_stock_items as $item) {
                    wc_add_notice(
                        sprintf(
                            __('❌ No hay suficiente stock para "%s". Solicitado: %d, Disponible: %d', 'woo-update-api'),
                            $item['product']->get_name(),
                            $item['requested'],
                            $item['available']
                        ),
                        'error'
                    );
                }
                
                // Cancelar orden
                $order->update_status('cancelled', __('Orden cancelada por falta de stock.', 'woo-update-api'));
                error_log('[Inventory Validation] Orden ' . $order_id . ' cancelada por falta de stock');
                
                throw new Exception(__('Orden cancelada por falta de stock.', 'woo-update-api'));
            }
            
            // 4. Si todo está bien, actualizar stock en BD
            error_log('[Inventory Validation] Todo el stock está disponible, actualizando BD');
            $this->update_stock_after_validation($order);
            
        } catch (Exception $e) {
            error_log('[Inventory Validation Error] ' . $e->getMessage());
            throw $e; // Re-lanzar para que WooCommerce maneje el error
        }
    }

    /**
     * ACTUALIZAR STOCK EN BD DESPUÉS DE VALIDACIÓN EXITOSA
     */
    private function update_stock_after_validation($order) {
        try {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                
                if ($product && $product->managing_stock()) {
                    // Obtener stock actual de API (una última vez)
                    $real_stock = $this->get_real_stock($product->get_id());
                    $quantity = $item->get_quantity();
                    
                    // Calcular nuevo stock
                    $new_stock = max(0, $real_stock - $quantity);
                    
                    // Actualizar en BD
                    $product->set_stock_quantity($new_stock);
                    $product->save();
                    
                    error_log('[Stock Update] Producto ' . $product->get_id() . 
                             ' - Stock anterior: ' . $real_stock . 
                             ' - Cantidad comprada: ' . $quantity . 
                             ' - Nuevo stock: ' . $new_stock);
                    
                    // Registrar en log de sincronización
                    $this->log_sync($product->get_id(), $real_stock, $new_stock, 'purchase');
                }
            }
        } catch (Exception $e) {
            error_log('[Stock Update Error] update_stock_after_validation: ' . $e->getMessage());
        }
    }

    /**
     * ACTUALIZAR STOCK DESPUÉS DE COMPRA EXITOSA
     */
    public function update_stock_after_purchase($order_id) {
        try {
            $order = wc_get_order($order_id);

            if (!$order) {
                return;
            }

            error_log('[Post-Purchase] Actualizando stock después de compra: ' . $order_id);
            
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();

                if (!$product || !$product->managing_stock()) {
                    continue;
                }

                // Forzar sincronización con API después de compra
                $this->force_stock_sync($product->get_id());
            }
        } catch (Exception $e) {
            error_log('[Stock Synchronizer Error] update_stock_after_purchase: ' . $e->getMessage());
        }
    }

    /**
     * VALIDAR CANTIDAD EN CARRITO (AJAX)
     */
    public function validate_cart_item_quantity($quantity, $cart_item_key, $cart_item) {
        try {
            $product = $cart_item['data'];

            if (!$product->managing_stock()) {
                return $quantity;
            }

            // Obtener stock real
            $real_stock = $this->get_real_stock($product->get_id());

            // Limitar a stock disponible
            if ($quantity > $real_stock) {
                $quantity = $real_stock;
                error_log('[Cart Item Qty] Cantidad ajustada: ' . $product->get_id() . ' → ' . $real_stock);
            }

            return $quantity;
            
        } catch (Exception $e) {
            error_log('[Stock Synchronizer Error] validate_cart_item_quantity: ' . $e->getMessage());
            return $quantity; // Retornar cantidad original
        }
    }

    /**
     * SINCRONIZACIÓN FORZADA DE STOCK
     */
    public function force_stock_sync($product_id) {
        try {
            $product = wc_get_product($product_id);

            if (!$product || !$product->managing_stock()) {
                return false;
            }

            // Obtener datos frescos de API (bypass cache)
            $cache_key = 'woo_update_api_product_' . md5($product_id . $product->get_sku());
            delete_transient($cache_key);

            // Obtener datos frescos
            $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());

            if ($api_data && isset($api_data['stock_quantity'])) {
                $api_stock = intval($api_data['stock_quantity']);
                $current_stock = $product->get_stock_quantity();

                if ($api_stock !== $current_stock) {
                    $product->set_stock_quantity($api_stock);
                    $product->save();
                    
                    // Actualizar precio también si está disponible
                    if (isset($api_data['price_mxn']) || isset($api_data['price'])) {
                        $price = isset($api_data['price_mxn']) ? floatval($api_data['price_mxn']) : floatval($api_data['price']);
                        update_post_meta($product_id, '_price', $price);
                        update_post_meta($product_id, '_regular_price', $price);
                    }
                    
                    update_post_meta($product_id, '_last_api_sync', current_time('mysql'));
                    update_post_meta($product_id, '_api_data_cache', $api_data);

                    $this->log_sync($product_id, $current_stock, $api_stock, 'forced');
                    return true;
                }
            }

            return false;
            
        } catch (Exception $e) {
            error_log('[Stock Synchronizer Error] force_stock_sync: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * OBTENER STOCK REAL (API o WooCommerce)
     */
    public function get_real_stock($product_id) {
        try {
            $product = wc_get_product($product_id);

            if (!$product) {
                error_log('[GET REAL STOCK] Producto no encontrado: ' . $product_id);
                return 0;
            }

            // 1. Intentar obtener de API
            $api_data = $this->api_handler->get_product_data(
                $product->get_id(),
                $product->get_sku()
            );

            if ($api_data && isset($api_data['stock_quantity'])) {
                $stock = intval($api_data['stock_quantity']);
                return $stock;
            }

            // 2. Fallback a stock de WooCommerce
            $wc_stock = $product->get_stock_quantity();
            return $wc_stock;
            
        } catch (Exception $e) {
            // Si hay una excepción, usar stock de WooCommerce
            error_log('[GET REAL STOCK] Excepción capturada: ' . $e->getMessage());
            $product = wc_get_product($product_id);
            return $product ? $product->get_stock_quantity() : 0;
        }
    }

    /**
     * SINCRONIZACIÓN ASÍNCRONA
     */
    public function async_stock_sync($product_id, $api_stock) {
        try {
            $product = wc_get_product($product_id);

            if ($product && $product->managing_stock()) {
                $current_stock = $product->get_stock_quantity();

                if ($api_stock !== $current_stock) {
                    $product->set_stock_quantity($api_stock);
                    $product->save();

                    $this->log_sync($product_id, $current_stock, $api_stock, 'async');
                }
            }
        } catch (Exception $e) {
            error_log('[Stock Synchronizer Error] async_stock_sync: ' . $e->getMessage());
        }
    }

    /**
     * SINCRONIZAR STOCK CUANDO SE VISITA UN PRODUCTO
     */
    public function sync_stock_on_product_view() {
        try {
            global $post;
            $product = wc_get_product($post->ID);
            
            if ($product && $product->managing_stock()) {
                // Verificar última sincronización
                $last_sync = get_post_meta($post->ID, '_last_api_sync', true);
                
                // Sincronizar solo si pasaron más de 10 minutos
                if (!$last_sync || strtotime($last_sync) < (time() - 600)) {
                    error_log('[Auto Sync] Sincronizando stock al ver producto: ' . $product->get_id());
                    $this->force_stock_sync($product->get_id());
                }
            }
        } catch (Exception $e) {
            error_log('[Auto Sync Error] sync_stock_on_product_view: ' . $e->getMessage());
        }
    }

    /**
     * SINCRONIZAR STOCK AL CARGAR PÁGINA DEL CARRITO
     */
    public function sync_stock_on_cart_page() {
        try {
            $cart = WC()->cart;
            
            if ($cart && !$cart->is_empty()) {
                error_log('[Auto Sync] Sincronizando stock en carrito/checkout');
                
                foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                    $product = $cart_item['data'];
                    if ($product->managing_stock()) {
                        // Verificar última sincronización
                        $last_sync = get_post_meta($product->get_id(), '_last_api_sync', true);
                        
                        // Sincronizar solo si pasaron más de 5 minutos
                        if (!$last_sync || strtotime($last_sync) < (time() - 300)) {
                            error_log('[Auto Sync] Producto en carrito: ' . $product->get_id());
                            $this->force_stock_sync($product->get_id());
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[Auto Sync Error] sync_stock_on_cart_page: ' . $e->getMessage());
        }
    }

    /**
     * MOSTRAR ERRORES DE API EN CARRITO
     */
    public function show_api_errors_in_cart() {
        // Solo si estamos en modo depuración
        if (!$this->api_handler->is_fallback_disabled_by_config()) {
            return;
        }
        
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return;
        }
        
        $products_with_api_errors = [];
        
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $product_id = $product->get_id();
            
            // Verificar si este producto tiene errores de API
            $errors = $this->api_handler->get_frontend_errors();
            $error_key = 'product_' . $product_id;
            
            if (isset($errors[$error_key])) {
                $products_with_api_errors[] = $product->get_name();
            }
        }
        
        if (!empty($products_with_api_errors)) {
            wc_add_notice(
                sprintf(
                    __('⚠️ ADVERTENCIA: Los siguientes productos están usando datos locales debido a errores de API: %s', 'woo-update-api'),
                    implode(', ', array_unique($products_with_api_errors))
                ),
                'warning'
            );
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
            error_log('[Woo Update API Sync] ' . $log_entry);
        }
    }
    
    /**
     * OBTENER LOGS DE SINCRONIZACIÓN
     */
    public function get_sync_logs($limit = 50) {
        $logs = get_option('woo_update_api_sync_logs', []);
        return array_slice($logs, -$limit);
    }
}