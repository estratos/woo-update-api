<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class Price_Updater
{
    private static $instance = null;
    private $api_handler;

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
        // Price hooks
        add_filter('woocommerce_product_get_price', [$this, 'update_price'], 99, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'update_price'], 99, 2);
        add_filter('woocommerce_product_get_sale_price', [$this, 'update_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'update_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_regular_price', [$this, 'update_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_sale_price', [$this, 'update_price'], 99, 2);

        // Stock hooks (solo para visualización)
        add_filter('woocommerce_product_get_stock_quantity', [$this, 'update_stock_display'], 99, 2);
        add_filter('woocommerce_variation_get_stock_quantity', [$this, 'update_stock_display'], 99, 2);
        add_filter('woocommerce_product_get_stock_status', [$this, 'update_stock_status'], 99, 2);
        add_filter('woocommerce_variation_get_stock_status', [$this, 'update_stock_status'], 99, 2);

        // Admin hooks
        add_action('admin_notices', [$this, 'admin_notice_fallback_mode']);
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_refresh_ui']);
        
        // Detectar diferencias grandes de stock para sincronización automática
        add_action('template_redirect', [$this, 'detect_stock_discrepancy']);
        
        // NUEVO: Mostrar errores en carrito y checkout también
        add_action('woocommerce_before_cart', [$this, 'display_cart_api_errors']);
        add_action('woocommerce_before_checkout_form', [$this, 'display_cart_api_errors']);
        
        // NUEVO: Mostrar errores en páginas de producto
        add_action('woocommerce_before_single_product', [$this, 'display_product_api_errors']);
    }
    
    /**
     * DETECTAR DISCREPANCIAS DE STOCK
     */
    public function detect_stock_discrepancy() {
        if (is_product()) {
            global $post;
            $product = wc_get_product($post->ID);
            
            if ($product && $product->managing_stock()) {
                $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());
                
                if ($api_data && isset($api_data['stock_quantity'])) {
                    $api_stock = intval($api_data['stock_quantity']);
                    $wc_stock = $product->get_stock_quantity();
                    
                    // Si diferencia es significativa (>20% o >5 unidades)
                    $diff = abs($api_stock - $wc_stock);
                    $threshold = max(5, $wc_stock * 0.2);
                    
                    if ($diff > $threshold) {
                        // Programar sincronización async
                        $sync = Stock_Synchronizer::instance();
                        wp_schedule_single_event(
                            time() + 10, // 10 segundos después
                            'woo_update_api_async_stock_sync',
                            [$product->get_id(), $api_stock]
                        );
                    }
                }
            }
        }
    }

    public function add_refresh_ui()
    {
        global $post;

        if (!$post || 'product' !== $post->post_type) {
            return;
        }

        echo '<div class="wc-update-api-container">';
        echo '<h3>' . esc_html__('API Data Refresh', 'woo-update-api') . '</h3>';
        echo '<button class="button button-primary wc-update-api-refresh" data-product-id="' . esc_attr($post->ID) . '" data-nonce="' . wp_create_nonce('wc_update_api_refresh') . '">';
        echo '<span class="spinner"></span>';
        echo esc_html__('Refresh Now', 'woo-update-api');
        echo '</button>';
        
        // Mostrar estado de sincronización
        $product = wc_get_product($post->ID);
        if ($product && $product->managing_stock()) {
            $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());
            if ($api_data && isset($api_data['stock_quantity'])) {
                $api_stock = $api_data['stock_quantity'];
                $wc_stock = $product->get_stock_quantity();
                
                if ($api_stock != $wc_stock) {
                    echo '<div class="notice notice-warning" style="margin-top: 10px;">';
                    echo '<p>' . sprintf(
                        __('Stock desincronizado: API: %d | WooCommerce: %d', 'woo-update-api'),
                        $api_stock,
                        $wc_stock
                    ) . '</p>';
                    echo '</div>';
                }
            }
        }
        
        echo '<div class="woo-update-api-result" style="margin-top: 10px;"></div>';
        echo '</div>';
    }

    /**
     * ACTUALIZAR PRECIO CON MANEJO DE ERRORES Y NOTIFICACIONES
     */
    public function update_price($price, $product) {
        // Don't override prices in admin area (except AJAX requests)
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }

        try {
            $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());

            // If API is unavailable or returns false, return original price
            if ($api_data === false) {
                $this->maybe_show_api_error($product->get_id());
                return $price;
            }

            // Check for price in API response
            if ($api_data && isset($api_data['price_mxn'])) {
                return floatval($api_data['price_mxn']);
            }
            
            if ($api_data && isset($api_data['price'])) {
                return floatval($api_data['price']);
            }

            return $price;
            
        } catch (Exception $e) {
            // Esto no debería pasar ahora, pero por seguridad
            $this->maybe_show_api_error($product->get_id());
            return $price;
        }
    }

    /**
     * ACTUALIZAR STOCK PARA VISUALIZACIÓN CON MANEJO DE ERRORES
     */
    public function update_stock_display($quantity, $product) {
        // Don't override stock in admin area (except AJAX requests)
        if (is_admin() && !wp_doing_ajax()) {
            return $quantity;
        }

        try {
            $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());

            // If API is unavailable or returns false, return original quantity
            if ($api_data === false) {
                $this->maybe_show_api_error($product->get_id());
                return $quantity;
            }

            if ($api_data && isset($api_data['stock_quantity'])) {
                $stock = intval($api_data['stock_quantity']);
                // Ensure stock is not negative
                return max(0, $stock);
            }

            return $quantity;
            
        } catch (Exception $e) {
            $this->maybe_show_api_error($product->get_id());
            return $quantity;
        }
    }

    /**
     * ACTUALIZAR ESTADO DE STOCK CON MANEJO DE ERRORES
     */
    public function update_stock_status($status, $product) {
        // Don't override status in admin area (except AJAX requests)
        if (is_admin() && !wp_doing_ajax()) {
            return $status;
        }

        try {
            $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());

            // If API is unavailable or returns false, return original status
            if ($api_data === false) {
                $this->maybe_show_api_error($product->get_id());
                return $status;
            }

            if ($api_data && isset($api_data['in_stock'])) {
                return $api_data['in_stock'] ? 'instock' : 'outofstock';
            }
            
            // Calculate stock status from quantity if available
            if ($api_data && isset($api_data['stock_quantity'])) {
                return intval($api_data['stock_quantity']) > 0 ? 'instock' : 'outofstock';
            }

            return $status;
            
        } catch (Exception $e) {
            // En caso de error, mantener el estado actual
            $this->maybe_show_api_error($product->get_id());
            return $status;
        }
    }

    /**
     * MOSTRAR ERROR DE API EN FRONTEND (UNA SOLA VEZ POR PRODUCTO)
     */
    private function maybe_show_api_error($product_id) {
        // Solo en frontend
        if (is_admin() || wp_doing_ajax()) {
            return;
        }
        
        // Solo si estamos en modo depuración
        if (!$this->api_handler->is_fallback_disabled_by_config()) {
            return; // Modo producción, no mostrar errores
        }
        
        // Obtener errores no mostrados
        $errors = $this->api_handler->get_frontend_errors();
        $error_key = 'product_' . $product_id;
        
        if (isset($errors[$error_key]) && !$errors[$error_key]['displayed']) {
            $error = $errors[$error_key];
            
            // Mostrar notificación de WooCommerce
            wc_add_notice(
                sprintf(
                    __('⚠️ ERROR DE API: %s (Producto ID: %d, SKU: %s)', 'woo-update-api'),
                    $error['message'],
                    $product_id,
                    $error['sku'] ?: 'N/A'
                ),
                'error'
            );
            
            // Marcar como mostrado
            $this->api_handler->mark_error_as_displayed($product_id);
            
            error_log('[Frontend Error Displayed] Producto ' . $product_id . ': ' . $error['message']);
        }
    }

    /**
     * MOSTRAR TODOS LOS ERRORES DE API EN CARRITO/CHECKOUT
     */
    public function display_cart_api_errors() {
        // Solo si estamos en modo depuración
        if (!$this->api_handler->is_fallback_disabled_by_config()) {
            return;
        }
        
        $errors = $this->api_handler->get_frontend_errors();
        
        if (!empty($errors)) {
            $error_count = count($errors);
            $product_ids = array_map(function($error) {
                return $error['product_id'];
            }, $errors);
            
            // Mostrar solo si hay errores no mostrados
            $has_unshown_errors = false;
            foreach ($errors as $error) {
                if (!$error['displayed']) {
                    $has_unshown_errors = true;
                    break;
                }
            }
            
            if ($has_unshown_errors) {
                wc_add_notice(
                    sprintf(
                        __('⚠️ ADVERTENCIA: %d producto(s) están usando datos locales debido a errores de API. Revisa los logs para más detalles.', 'woo-update-api'),
                        $error_count
                    ),
                    'warning'
                );
            }
        }
    }

    /**
     * MOSTRAR ERRORES DE API EN PÁGINA DE PRODUCTO
     */
    public function display_product_api_errors() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        // Solo si estamos en modo depuración
        if (!$this->api_handler->is_fallback_disabled_by_config()) {
            return;
        }
        
        $product_id = $product->get_id();
        $errors = $this->api_handler->get_frontend_errors();
        $error_key = 'product_' . $product_id;
        
        if (isset($errors[$error_key]) && !$errors[$error_key]['displayed']) {
            $error = $errors[$error_key];
            
            // Mostrar banner especial en página de producto
            echo '<div class="woocommerce-message woocommerce-error" style="background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; margin: 20px 0; padding: 15px; border-radius: 4px;">';
            echo '<strong>⚠️ ERROR DE CONEXIÓN CON API</strong><br>';
            echo 'Este producto está usando datos locales de WooCommerce.<br>';
            echo '<small>Error: ' . esc_html($error['message']) . '</small>';
            echo '</div>';
            
            // Marcar como mostrado
            $this->api_handler->mark_error_as_displayed($product_id);
        }
    }

    public function admin_notice_fallback_mode()
    {
        // EN MODO DEPURACIÓN: NO mostrar el notice de fallback
        if ($this->api_handler->is_fallback_disabled_by_config()) {
            return;
        }
        
        if ($this->api_handler->is_in_fallback_mode()) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php _e('WooCommerce Update API is currently in fallback mode. The external API service is unavailable, so default WooCommerce pricing and inventory data is being used.', 'woo-update-api'); ?></p>
            </div>
            <?php
        }
    }
}