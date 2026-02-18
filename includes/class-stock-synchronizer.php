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

        // ACTUALIZAR BD AL AGREGAR AL CARRITO (con precio y stock)
        add_action('woocommerce_add_to_cart', [$this, 'update_on_add_to_cart'], 1, 6);
        add_action('woocommerce_ajax_added_to_cart', [$this, 'update_on_ajax_add_to_cart'], 1, 1);
        
        // ACTUALIZAR BD EN PÁGINA DE CARRITO (máx cada 5 min)
        add_action('template_redirect', [$this, 'maybe_update_cart_items']);
        
        // VALIDACIÓN CRÍTICA EN CHECKOUT
        add_action('woocommerce_checkout_process', [$this, 'validate_checkout_stock']);
        add_action('woocommerce_before_checkout_process', [$this, 'validate_checkout_stock']);
        
        // VALIDACIÓN AL CARGAR PÁGINA DE CHECKOUT
        add_action('template_redirect', [$this, 'maybe_validate_checkout_page']);
        
        // ACTUALIZAR DESPUÉS DE COMPRA
        add_action('woocommerce_payment_complete', [$this, 'update_stock_after_purchase'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'update_stock_after_purchase'], 10, 1);

        // ========== BACKEND (ADMIN) ==========
        
        add_action('woo_update_api_daily_stock_sync', [$this, 'sync_all_products']);
        add_action('woo_update_api_hourly_sync', [$this, 'sync_recent_products']);
        add_action('woo_update_api_single_sync', [$this, 'sync_single_product']);
        
        add_action('wp_ajax_woo_update_api_sync_to_db', [$this, 'ajax_sync_to_db']);
        add_action('wp_ajax_woo_update_api_validate_stock', [$this, 'ajax_validate_stock']);
        add_action('wp_ajax_nopriv_woo_update_api_validate_stock', [$this, 'ajax_validate_stock']);
    }

    /**
     * ACTUALIZAR BD AL AÑADIR AL CARRITO
     */
    public function update_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        try {
            $actual_product_id = $variation_id ? $variation_id : $product_id;
            
            error_log('[Add to Cart] Actualizando producto: ' . $actual_product_id);
            
            // Prevenir múltiples ejecuciones
            $lock_key = 'update_lock_' . $actual_product_id;
            if (isset($this->update_lock[$lock_key]) && $this->update_lock[$lock_key] > time() - 5) {
                error_log('[Add to Cart] Bloqueado - ya se está actualizando: ' . $actual_product_id);
                return;
            }
            
            $this->update_lock[$lock_key] = time();
            
            // SINCRONIZACIÓN COMPLETA (precio + stock) con datos FRESCOS
            $this->sync_single_product_now($actual_product_id, true); // true = forzar fresco
            
        } catch (Exception $e) {
            error_log('[Add to Cart Error] ' . $e->getMessage());
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
     * ACTUALIZAR PRODUCTOS DEL CARRITO EN BD
     */
    public function maybe_update_cart_items()
    {
        if (!is_cart() && !is_checkout()) {
            return;
        }

        if (!function_exists('WC') || !WC()->cart || !WC()->session) {
            return;
        }

        $session = WC()->session;
        $last_update = $session->get('woo_api_cart_updated');
        
        // Actualizar máximo cada 5 minutos
        if ($last_update && $last_update > (time() - 300)) {
            return;
        }

        $cart = WC()->cart;
        $cart_items = $cart->get_cart();

        if (empty($cart_items)) {
            return;
        }

        error_log('[Cart Sync] Actualizando ' . count($cart_items) . ' productos en BD');

        foreach ($cart_items as $cart_item) {
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            
            if (is_checkout()) {
                // En checkout, actualizar inmediatamente con datos frescos
                $this->sync_single_product_now($product_id, true);
            } else {
                // En carrito, programar en background
                wp_schedule_single_event(time(), 'woo_update_api_single_sync', [$product_id]);
            }
        }

        $session->set('woo_api_cart_updated', time());
    }

    /**
     * VALIDAR EN PÁGINA DE CHECKOUT (cada 5 min)
     */
    public function maybe_validate_checkout_page()
    {
        if (!is_checkout() || is_wc_endpoint_url('order-pay')) {
            return;
        }
        
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        
        $session = WC()->session;
        $last_validation = $session->get('woo_api_checkout_validation');
        
        // Validar cada 5 minutos
        if (!$last_validation || $last_validation < (time() - 300)) {
            $this->validate_checkout_stock();
            $session->set('woo_api_checkout_validation', time());
        }
    }

    /**
     * VALIDACIÓN CRÍTICA DE STOCK EN CHECKOUT
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

        error_log('[Checkout] Validando stock para ' . count($cart_items) . ' productos');

        $has_errors = false;
        $needs_refresh = false;

        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            $quantity = $cart_item['quantity'];
            $product = $cart_item['data'];
            
            // ¡FORZAR consulta a API sin caché!
            $api_data = $this->get_fresh_api_data($product_id, $product->get_sku());
            
            if ($api_data && isset($api_data['stock_quantity'])) {
                $real_stock = intval($api_data['stock_quantity']);
                $current_stock = $product->get_stock_quantity();
                
                // Si el stock cambió, marcar para refrescar
                if ($real_stock !== $current_stock) {
                    $needs_refresh = true;
                    error_log('[Checkout] Stock cambiado para ' . $product_id . ': ' . $current_stock . ' → ' . $real_stock);
                }
                
                // VALIDACIÓN CRÍTICA
                if ($real_stock < $quantity) {
                    $has_errors = true;
                    
                    if ($real_stock > 0) {
                        // Ajustar cantidad automáticamente
                        WC()->cart->set_quantity($cart_item_key, $real_stock);
                        wc_add_notice(
                            sprintf(
                                __('La cantidad de "%s" ha sido ajustada a %d unidades disponibles.', 'woo-update-api'),
                                $product->get_name(),
                                $real_stock
                            ),
                            'notice'
                        );
                        error_log('[Checkout] Cantidad ajustada para ' . $product_id . ': ' . $real_stock);
                    } else {
                        // Remover del carrito
                        WC()->cart->remove_cart_item($cart_item_key);
                        wc_add_notice(
                            sprintf(
                                __('"%s" ha sido removido del carrito por falta de stock.', 'woo-update-api'),
                                $product->get_name()
                            ),
                            'error'
                        );
                        error_log('[Checkout] Producto removido por falta de stock: ' . $product_id);
                    }
                }
            }
        }

        // Si hubo cambios de stock, actualizar BD
        if ($needs_refresh) {
            foreach ($cart_items as $cart_item) {
                $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
                $this->sync_single_product_now($product_id, true);
            }
        }

        if ($has_errors) {
            error_log('[Checkout] Stock validation failed - payment prevented');
        }
    }

    /**
     * SINCRONIZACIÓN COMPLETA DE PRODUCTO (PRECIO + STOCK)
     * 
     * @param int $product_id ID del producto
     * @param bool $force_fresh Si es true, fuerza consulta fresca a API sin caché
     * @return bool True si se actualizó, False si no
     */
    public function sync_single_product_now($product_id, $force_fresh = false)
    {
        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                error_log('[Sync Error] Producto no encontrado: ' . $product_id);
                return false;
            }

            // Obtener datos de API (frescos si se solicita)
            if ($force_fresh) {
                error_log('[Sync] ⚠️ FORZANDO consulta FRESCA a API para: ' . $product_id);
                $api_data = $this->get_fresh_api_data($product_id, $product->get_sku());
            } else {
                $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());
            }
            
            if ($api_data === false) {
                error_log('[Sync Error] No se pudo obtener datos de API para: ' . $product_id);
                return false;
            }

            $updated = false;
            $updates = [];

            // ===== 1. ACTUALIZAR PRECIO =====
            if (isset($api_data['price_mxn']) || isset($api_data['price'])) {
                $api_price = isset($api_data['price_mxn']) ? floatval($api_data['price_mxn']) : floatval($api_data['price']);
                $current_price = floatval($product->get_price());
                
                // Redondear a 2 decimales para comparación precisa
                $api_price_rounded = round($api_price, 2);
                $current_price_rounded = round($current_price, 2);
                
                // Solo actualizar si hay diferencia REAL
                if (abs($api_price_rounded - $current_price_rounded) > 0.001) {
                    
                    error_log('[Sync] DIFERENCIA DETECTADA - API: ' . $api_price . ' vs BD: ' . $current_price);
                    
                    // Actualizar metadatos de precio
                    update_post_meta($product_id, '_price', $api_price);
                    update_post_meta($product_id, '_regular_price', $api_price);
                    
                    // Si hay sale_price en API, también actualizarlo
                    if (isset($api_data['sale_price'])) {
                        update_post_meta($product_id, '_sale_price', floatval($api_data['sale_price']));
                    }
                    
                    // Para variaciones
                    if ($product->is_type('variation')) {
                        update_post_meta($product_id, '_variation_price', $api_price);
                        
                        // Limpiar caché del producto padre
                        $parent_id = $product->get_parent_id();
                        if ($parent_id) {
                            wc_delete_product_transients($parent_id);
                            delete_transient('wc_product_children_' . $parent_id);
                        }
                    }
                    
                    $updates[] = 'precio: ' . $current_price . ' → ' . $api_price;
                    $updated = true;
                    
                    error_log('[Sync] ✅ Precio ACTUALIZADO: ' . $product_id . ' = ' . $api_price);
                } else {
                    error_log('[Sync] ℹ️ Precio SIN CAMBIOS: ' . $product_id . ' = ' . $api_price);
                }
            }

            // ===== 2. ACTUALIZAR STOCK =====
            if (isset($api_data['stock_quantity']) && $product->managing_stock()) {
                $api_stock = intval($api_data['stock_quantity']);
                $current_stock = intval($product->get_stock_quantity());
                
                if ($api_stock !== $current_stock) {
                    wc_update_product_stock($product_id, $api_stock);
                    update_post_meta($product_id, '_stock', $api_stock);
                    
                    $updates[] = 'stock: ' . $current_stock . ' → ' . $api_stock;
                    $updated = true;
                    
                    error_log('[Sync] ✅ Stock ACTUALIZADO: ' . $product_id . ' = ' . $api_stock);
                } else {
                    error_log('[Sync] ℹ️ Stock SIN CAMBIOS: ' . $product_id . ' = ' . $api_stock);
                }
            }

            // ===== 3. GUARDAR TIMESTAMP Y CACHÉ =====
            if ($updated) {
                update_post_meta($product_id, '_last_api_sync', current_time('mysql'));
                update_post_meta($product_id, '_api_data_cache', $api_data);
                
                // Limpiar todos los caches de WooCommerce
                wc_delete_product_transients($product_id);
                clean_post_cache($product_id);
                
                error_log('[Sync] ✅ Producto ACTUALIZADO: ' . $product_id . ' - ' . implode(', ', $updates));
            } else {
                // Aún así actualizar timestamp de verificación
                update_post_meta($product_id, '_last_api_check', current_time('mysql'));
                error_log('[Sync] ℹ️ Producto SIN CAMBIOS: ' . $product_id);
            }

            return $updated;

        } catch (Exception $e) {
            error_log('[Sync Error] ' . $e->getMessage() . ' - Producto: ' . $product_id);
            return false;
        }
    }

    /**
     * OBTENER DATOS FRESCOS DE API (SIN NINGÚN CACHÉ)
     */
    private function get_fresh_api_data($product_id, $sku)
    {
        // 1. Limpiar caché de transients del plugin
        $cache_key = 'woo_api_product_' . md5($product_id . $sku);
        delete_transient($cache_key);
        error_log('[Fresh API] Caché transient eliminado: ' . $cache_key);
        
        // 2. Limpiar caché de sesión de WooCommerce
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('woo_api_price_' . $product_id, null);
            WC()->session->set('woo_api_stock_' . $product_id, null);
            error_log('[Fresh API] Caché de sesión eliminado para: ' . $product_id);
        }
        
        // 3. Forzar al API_Handler a ignorar su propio caché
        add_filter('woo_update_api_skip_cache', '__return_true');
        
        // 4. Obtener datos (el API_Handler respetará el filtro)
        error_log('[Fresh API] Consultando API sin caché para: ' . $product_id);
        $data = $this->api_handler->get_product_data($product_id, $sku);
        
        // 5. Remover el filtro
        remove_filter('woo_update_api_skip_cache', '__return_true');
        
        // 6. Log para debugging
        if ($data !== false) {
            error_log('[Fresh API] ✅ Datos obtenidos para: ' . $product_id);
            if (isset($data['price'])) {
                error_log('[Fresh API] Precio API: ' . $data['price']);
            }
            if (isset($data['stock_quantity'])) {
                error_log('[Fresh API] Stock API: ' . $data['stock_quantity']);
            }
        } else {
            error_log('[Fresh API] ❌ No se obtuvieron datos para: ' . $product_id);
        }
        
        return $data;
    }

    /**
     * SINCRONIZACIÓN EN BACKGROUND
     */
    public function sync_single_product($product_id)
    {
        return $this->sync_single_product_now($product_id, false);
    }

    /**
     * VALIDACIÓN AL AÑADIR AL CARRITO
     */
    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0)
    {
        try {
            $actual_product_id = $variation_id ? $variation_id : $product_id;
            
            $product = wc_get_product($actual_product_id);
            if (!$product || !$product->managing_stock()) {
                return $passed;
            }

            // Obtener stock actualizado (SIEMPRE fresco para validación)
            $api_data = $this->get_fresh_api_data($actual_product_id, $product->get_sku());
            
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

    public function update_stock_after_purchase($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        error_log('[Post Purchase] Actualizando productos después de compra: ' . $order_id);

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_variation_id() ?: $item->get_product_id();
            // Después de compra, usar datos frescos
            $this->sync_single_product_now($product_id, true);
        }
    }

    /**
     * OBTENER STOCK REAL (CON CACHE DE 30 SEGUNDOS PARA FRONTEND)
     */
    public function get_real_stock($product_id)
    {
        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                return 0;
            }

            // Cache de sesión (30 segundos)
            $cache_key = 'woo_api_stock_' . $product_id;
            
            if (function_exists('WC') && WC()->session) {
                $cached = WC()->session->get($cache_key);
                if ($cached !== null && !isset($_GET['force_fresh'])) {
                    return $cached;
                }
            }

            $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());

            if ($api_data && isset($api_data['stock_quantity'])) {
                $stock = intval($api_data['stock_quantity']);
                
                if (function_exists('WC') && WC()->session) {
                    WC()->session->set($cache_key, $stock);
                }
                
                return $stock;
            }

            return $product->get_stock_quantity();

        } catch (Exception $e) {
            error_log('[Get Stock Error] ' . $e->getMessage());
            $product = wc_get_product($product_id);
            return $product ? $product->get_stock_quantity() : 0;
        }
    }

    // ========== MÉTODOS ADMIN ==========

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
            // Forzar fresco en sincronización manual
            $updated = $this->sync_single_product_now($product_id, true);
            
            $product = wc_get_product($product_id);
            
            wp_send_json_success([
                'message' => $updated ? 
                    __('Product synchronized successfully', 'woo-update-api') : 
                    __('No changes needed', 'woo-update-api'),
                'price' => $product ? $product->get_price() : null,
                'stock' => $product ? $product->get_stock_quantity() : null,
                'last_sync' => get_post_meta($product_id, '_last_api_sync', true)
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

            $real_stock = $this->get_real_stock($actual_product_id);
            
            if ($real_stock >= $quantity) {
                wp_send_json_success([
                    'stock' => $real_stock,
                    'message' => __('Stock available', 'woo-update-api')
                ]);
            } else {
                wp_send_json_error([
                    'stock' => $real_stock,
                    'message' => sprintf(__('Only %d available', 'woo-update-api'), $real_stock)
                ]);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}