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
        
        // VALIDACIÓN AL AÑADIR AL CARRITO (siempre activa)
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 20, 4);

        // ACTUALIZAR BD EN BACKGROUND al agregar al carrito
        add_action('woocommerce_add_to_cart', [$this, 'schedule_cart_update'], 1, 6);
        add_action('woocommerce_ajax_added_to_cart', [$this, 'schedule_ajax_cart_update'], 1, 1);
        
        // ACTUALIZAR BD EN PÁGINA DE CARRITO (máx cada 5 min)
        add_action('template_redirect', [$this, 'maybe_update_cart_items']);
        
        // *** VALIDACIÓN CRÍTICA EN CHECKOUT ***
        add_action('woocommerce_checkout_process', [$this, 'validate_checkout_stock']);
        add_action('woocommerce_before_checkout_process', [$this, 'validate_checkout_stock']);
        
        // VALIDACIÓN AL CARGAR PÁGINA DE CHECKOUT
        add_action('template_redirect', [$this, 'maybe_validate_checkout_page']);
        
        // ACTUALIZAR DESPUÉS DE COMPRA
        add_action('woocommerce_payment_complete', [$this, 'update_stock_after_purchase'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'update_stock_after_purchase'], 10, 1);

        // ========== BACKEND (ADMIN) - INTACTO ==========
        
        // Sincronización programada
        add_action('woo_update_api_daily_stock_sync', [$this, 'sync_all_products']);
        add_action('woo_update_api_hourly_sync', [$this, 'sync_recent_products']);
        
        // Acción para sincronización en background
        add_action('woo_update_api_single_sync', [$this, 'sync_single_product']);
        
        // AJAX handlers (admin)
        add_action('wp_ajax_woo_update_api_sync_to_db', [$this, 'ajax_sync_to_db']);
        add_action('wp_ajax_woo_update_api_validate_stock', [$this, 'ajax_validate_stock']);
        add_action('wp_ajax_nopriv_woo_update_api_validate_stock', [$this, 'ajax_validate_stock']);
    }

    /**
     * PROGRAMAR ACTUALIZACIÓN DESPUÉS DE AÑADIR AL CARRITO
     */
    public function schedule_cart_update($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        $actual_product_id = $variation_id ? $variation_id : $product_id;
        
        // Programar actualización en 2 segundos (no bloquear)
        wp_schedule_single_event(time() + 2, 'woo_update_api_single_sync', [$actual_product_id]);
    }

    public function schedule_ajax_cart_update($product_id)
    {
        wp_schedule_single_event(time() + 2, 'woo_update_api_single_sync', [$product_id]);
    }

    /**
     * ACTUALIZAR PRODUCTOS DEL CARRITO EN BD (máx cada 5 min)
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

        error_log('[Stock Sync] Actualizando ' . count($cart_items) . ' productos en BD');

        foreach ($cart_items as $cart_item) {
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            
            // En checkout, actualizar inmediatamente
            if (is_checkout()) {
                $this->sync_single_product_now($product_id);
            } else {
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

        $has_errors = false;
        $needs_refresh = false;

        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            $quantity = $cart_item['quantity'];
            $product = $cart_item['data'];
            
            // FORZAR consulta a API (sin caché)
            $api_data = $this->get_fresh_api_data($product_id, $product->get_sku());
            
            if ($api_data && isset($api_data['stock_quantity'])) {
                $real_stock = intval($api_data['stock_quantity']);
                $current_stock = $product->get_stock_quantity();
                
                // Si el stock cambió, marcar para refrescar
                if ($real_stock !== $current_stock) {
                    $needs_refresh = true;
                }
                
                // VALIDACIÓN CRÍTICA
                if ($real_stock < $quantity) {
                    $has_errors = true;
                    
                    if ($real_stock > 0) {
                        // Ajustar cantidad automáticamente
                        WC()->cart->set_quantity($cart_item_key, $real_stock);
                        wc_add_notice(
                            sprintf(
                                __('La cantidad de "%s" ha sido ajustada a %d unidades disponibles (stock actualizado).', 'woo-update-api'),
                                $product->get_name(),
                                $real_stock
                            ),
                            'notice'
                        );
                    } else {
                        // Remover del carrito
                        WC()->cart->remove_cart_item($cart_item_key);
                        wc_add_notice(
                            sprintf(
                                __('"%s" ha sido removido del carrito porque ya no hay stock disponible.', 'woo-update-api'),
                                $product->get_name()
                            ),
                            'error'
                        );
                    }
                }
            }
        }

        // Si hubo cambios de stock, actualizar BD
        if ($needs_refresh) {
            foreach ($cart_items as $cart_item) {
                $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
                $this->sync_single_product_now($product_id);
            }
        }

        if ($has_errors) {
            error_log('[Checkout] Stock validation failed - payment prevented');
        }
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

            // Obtener stock actualizado (sin caché para validación)
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

    /**
     * OBTENER DATOS FRESCOS DE API (SIN CACHÉ)
     */
    private function get_fresh_api_data($product_id, $sku)
    {
        // Limpiar todos los caches
        $cache_key = 'woo_api_product_' . md5($product_id . $sku);
        delete_transient($cache_key);
        
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('woo_api_price_' . $product_id, null);
            WC()->session->set('woo_api_stock_' . $product_id, null);
        }
        
        return $this->api_handler->get_product_data($product_id, $sku);
    }

    /**
     * SINCRONIZAR PRODUCTO INMEDIATAMENTE
     */
    public function sync_single_product_now($product_id)
    {
        try {
            $product = wc_get_product($product_id);
            if (!$product || !$product->managing_stock()) {
                return false;
            }

            $api_data = $this->get_fresh_api_data($product_id, $product->get_sku());
            
            if ($api_data === false) {
                return false;
            }

            $updated = false;

            // Actualizar precio
            if (isset($api_data['price_mxn']) || isset($api_data['price'])) {
                $price = isset($api_data['price_mxn']) ? floatval($api_data['price_mxn']) : floatval($api_data['price']);
                update_post_meta($product_id, '_price', $price);
                update_post_meta($product_id, '_regular_price', $price);
                
                if ($product->is_type('variation')) {
                    update_post_meta($product_id, '_variation_price', $price);
                }
                
                $updated = true;
            }

            // Actualizar stock
            if (isset($api_data['stock_quantity'])) {
                $api_stock = intval($api_data['stock_quantity']);
                wc_update_product_stock($product_id, $api_stock);
                update_post_meta($product_id, '_stock', $api_stock);
                $updated = true;
            }

            if ($updated) {
                update_post_meta($product_id, '_last_api_sync', current_time('mysql'));
                update_post_meta($product_id, '_api_data_cache', $api_data);
                wc_delete_product_transients($product_id);
                
                error_log('[Stock Sync] Producto actualizado: ' . $product_id);
            }

            return $updated;

        } catch (Exception $e) {
            error_log('[Stock Sync Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * SINCRONIZAR PRODUCTO EN BACKGROUND
     */
    public function sync_single_product($product_id)
    {
        return $this->sync_single_product_now($product_id);
    }

    /**
     * ACTUALIZAR STOCK DESPUÉS DE COMPRA
     */
    public function update_stock_after_purchase($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_variation_id() ?: $item->get_product_id();
            $this->sync_single_product_now($product_id);
        }
    }

    /**
     * ========== MÉTODOS ADMIN (INTACTOS) ==========
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
            $this->sync_single_product_now($product_id);
            
            $product = wc_get_product($product_id);
            
            wp_send_json_success([
                'message' => __('Product synchronized successfully', 'woo-update-api'),
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

            $api_data = $this->get_fresh_api_data($actual_product_id, $product->get_sku());
            
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

            // Cache corto para evitar muchas llamadas
            $cache_key = 'woo_api_stock_' . $product_id;
            
            if (function_exists('WC') && WC()->session) {
                $cached = WC()->session->get($cache_key);
                if ($cached !== null) {
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
            error_log('[Get Real Stock Error] ' . $e->getMessage());
            $product = wc_get_product($product_id);
            return $product ? $product->get_stock_quantity() : 0;
        }
    }
}