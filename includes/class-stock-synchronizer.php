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
            [$this, 'update_on_add_to_cart'], 1, 6);
        
        // También hook para AJAX add to cart
        add_action('woocommerce_ajax_added_to_cart', 
            [$this, 'update_on_ajax_add_to_cart'], 1, 1);

        // VALIDACIÓN DURANTE CHECKOUT - Versión corregida con verificación
        add_action('woocommerce_check_cart_items', function() {
            if (method_exists($this, 'validate_cart_stock')) {
                $this->validate_cart_stock();
            } else {
                error_log('[CRITICAL ERROR] validate_cart_stock method missing in Stock_Synchronizer');
                // Fallback: llamar directamente si existe
                if (method_exists($this, 'validate_cart_stock')) {
                    $this->validate_cart_stock();
                }
            }
        }, 10);
        
        // También registrar como método estático por seguridad
        add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart_stock_static'], 20);
        
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
     * Método estático de respaldo
     */
    public static function validate_cart_stock_static() {
        if (self::$instance && method_exists(self::$instance, 'validate_cart_stock')) {
            self::$instance->validate_cart_stock();
        } else {
            error_log('[Stock_Synchronizer] Cannot call validate_cart_stock - instance not available');
        }
    }

    /**
     * VALIDACIÓN DE STOCK EN EL CARRITO - NUEVO MÉTODO
     */
    public function validate_cart_stock() {
        try {
            error_log('[Stock_Synchronizer] validate_cart_stock called at ' . current_time('mysql'));
            
            if (!function_exists('WC') || !WC()->cart) {
                error_log('[Stock_Synchronizer] Cart not available');
                return;
            }
            
            $cart = WC()->cart;
            $cart_items = $cart->get_cart();
            
            if (empty($cart_items)) {
                error_log('[Stock_Synchronizer] Cart is empty');
                return;
            }
            
            error_log('[Stock_Synchronizer] Validating ' . count($cart_items) . ' cart items');
            
            $has_errors = false;
            
            foreach ($cart_items as $cart_item_key => $cart_item) {
                $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
                $quantity = $cart_item['quantity'];
                $product = $cart_item['data'];
                
                error_log('[Stock_Synchronizer] Checking product ' . $product_id . ' - Quantity: ' . $quantity);
                
                // Forzar actualización de stock desde API
                $this->force_update_stock_from_api($product_id);
                
                // Obtener stock actualizado
                $real_stock = $this->get_real_stock($product_id);
                
                error_log('[Stock_Synchronizer] Product ' . $product_id . ' - Real stock: ' . $real_stock);
                
                if ($real_stock < $quantity) {
                    $has_errors = true;
                    $product_name = $product->get_name();
                    
                    $message = sprintf(
                        __('Lo sentimos, "%s" no tiene suficiente stock. Solo %d disponible(s). Has solicitado %d.', 'woo-update-api'),
                        $product_name,
                        $real_stock,
                        $quantity
                    );
                    
                    wc_add_notice($message, 'error');
                    error_log('[Stock_Synchronizer] Stock validation failed for ' . $product_name . ': Requested ' . $quantity . ', Available ' . $real_stock);
                    
                    // Opcional: Ajustar cantidad automáticamente
                    if ($real_stock > 0) {
                        $cart->set_quantity($cart_item_key, $real_stock);
                        wc_add_notice(
                            sprintf(__('La cantidad de "%s" ha sido ajustada a %d unidades disponibles.', 'woo-update-api'),
                                $product_name,
                                $real_stock
                            ),
                            'notice'
                        );
                    } else {
                        $cart->remove_cart_item($cart_item_key);
                        wc_add_notice(
                            sprintf(__('"%s" ha sido removido del carrito porque no hay stock disponible.', 'woo-update-api'),
                                $product_name
                            ),
                            'notice'
                        );
                    }
                }
            }
            
            if (!$has_errors) {
                error_log('[Stock_Synchronizer] All cart items have sufficient stock');
            }
            
        } catch (Exception $e) {
            error_log('[Stock_Synchronizer] Error in validate_cart_stock: ' . $e->getMessage());
            error_log('[Stock_Synchronizer] Stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * ACTUALIZAR BD AL AÑADIR AL CARRITO (tu código existente)
     */
    public function update_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        // Tu código existente...
    }
    
    // El resto de tus métodos existentes...
    
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
            
            // Verificar hooks registrados
            global $wp_filter;
            if (isset($wp_filter['woocommerce_check_cart_items'])) {
                error_log('[DEBUG HOOKS] woocommerce_check_cart_items callbacks: ' . print_r($wp_filter['woocommerce_check_cart_items'], true));
            }
        }
    }
}