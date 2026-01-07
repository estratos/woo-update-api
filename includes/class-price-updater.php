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
        // Price hooks con prioridades optimizadas
        add_filter('woocommerce_product_get_price', [$this, 'update_price'], 20, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'update_price'], 20, 2);
        add_filter('woocommerce_product_get_sale_price', [$this, 'update_price'], 20, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'update_price'], 20, 2);
        add_filter('woocommerce_product_variation_get_regular_price', [$this, 'update_price'], 20, 2);
        add_filter('woocommerce_product_variation_get_sale_price', [$this, 'update_price'], 20, 2);

        // Stock hooks (solo para visualización) - prioridad optimizada
        add_filter('woocommerce_product_get_stock_quantity', [$this, 'update_stock_display'], 20, 2);
        add_filter('woocommerce_variation_get_stock_quantity', [$this, 'update_stock_display'], 20, 2);
        add_filter('woocommerce_product_get_stock_status', [$this, 'update_stock_status'], 20, 2);
        add_filter('woocommerce_variation_get_stock_status', [$this, 'update_stock_status'], 20, 2);

        // Admin hooks
        add_action('admin_notices', [$this, 'admin_notice_fallback_mode']);
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_refresh_ui']);
        
        // NUEVO: Mostrar errores en carrito y checkout también
        add_action('woocommerce_before_cart', [$this, 'display_cart_api_errors']);
        add_action('woocommerce_before_checkout_form', [$this, 'display_cart_api_errors']);
        
        // NUEVO: Mostrar errores en páginas de producto
        add_action('woocommerce_before_single_product', [$this, 'display_product_api_errors']);
        
        // NUEVO: Cache compatibility
        add_action('template_redirect', [$this, 'add_cache_compatibility']);
        
        // NUEVO: Debug hooks para diagnóstico
        add_action('wp', [$this, 'debug_hooks_execution']);
    }
    
    /**
     * COMPATIBILIDAD CON CACHE
     */
    public function add_cache_compatibility() {
        // Excluir páginas de producto del cache si es necesario
        if (is_product()) {
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            
            // Headers para evitar cache
            nocache_headers();
        }
    }
    
    /**
     * DEBUG DE HOOKS PARA DIAGNÓSTICO
     */
    public function debug_hooks_execution() {
        if (current_user_can('administrator') && isset($_GET['debug_api_hooks'])) {
            global $wp_filter;
            
            $hooks_to_check = [
                'woocommerce_product_get_price',
                'woocommerce_product_get_stock_quantity',
                'woocommerce_add_to_cart_validation'
            ];
            
            foreach ($hooks_to_check as $hook_name) {
                if (isset($wp_filter[$hook_name])) {
                    error_log("[DEBUG] Hook {$hook_name} tiene " . 
                        count($wp_filter[$hook_name]->callbacks) . " callbacks registrados");
                    
                    // Listar todos los callbacks
                    foreach ($wp_filter[$hook_name]->callbacks as $priority => $callbacks) {
                        foreach ($callbacks as $callback) {
                            if (is_array($callback['function'])) {
                                $class = is_object($callback['function'][0]) ? 
                                    get_class($callback['function'][0]) : $callback['function'][0];
                                $method = $callback['function'][1];
                                error_log("[DEBUG]   Prioridad {$priority}: {$class}->{$method}");
                            } else {
                                error_log("[DEBUG]   Prioridad {$priority}: " . $callback['function']);
                            }
                        }
                    }
                }
            }
            
            // También debuggear datos de producto actual
            if (is_product()) {
                global $post;
                $product = wc_get_product($post->ID);
                if ($product) {
                    error_log("[DEBUG] Producto ID: " . $product->get_id());
                    error_log("[DEBUG] Precio original: " . $product->get_price());
                    error_log("[DEBUG] Stock original: " . $product->get_stock_quantity());
                    
                    // Forzar actualización para ver qué pasa
                    $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());
                    error_log("[DEBUG] Datos API: " . print_r($api_data, true));
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
        
        // Botón NUEVO: Sincronizar a BD
        echo ' <button class="button button-secondary wc-update-api-sync-db" data-product-id="' . esc_attr($post->ID) . '" data-nonce="' . wp_create_nonce('wc_update_api_sync_db') . '">';
        echo esc_html__('Sync to Database', 'woo-update-api');
        echo '</button>';
        
        // Mostrar estado de sincronización
        $product = wc_get_product($post->ID);
        if ($product) {
            $last_sync = get_post_meta($post->ID, '_last_api_sync', true);
            $api_price_in_db = get_post_meta($post->ID, '_price', true);
            $wc_price = $product->get_price();
            
            echo '<div class="sync-status" style="margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 4px;">';
            echo '<strong>' . esc_html__('Sync Status:', 'woo-update-api') . '</strong><br>';
            
            if ($last_sync) {
                $time_diff = human_time_diff(strtotime($last_sync), current_time('timestamp'));
                echo esc_html__('Last sync:', 'woo-update-api') . ' ' . $time_diff . ' ' . esc_html__('ago', 'woo-update-api');
                
                if ($api_price_in_db && $api_price_in_db != $wc_price) {
                    echo '<br><span style="color: #d63638;">' . esc_html__('Price in DB differs from displayed price', 'woo-update-api') . '</span>';
                }
            } else {
                echo esc_html__('Never synced to database', 'woo-update-api');
            }
            echo '</div>';
            
            if ($product->managing_stock()) {
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
        }
        
        echo '<div class="woo-update-api-result" style="margin-top: 10px;"></div>';
        echo '</div>';
        
        // NUEVO: Script para sincronización a BD
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.wc-update-api-sync-db').on('click', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var productId = button.data('product-id');
                var nonce = button.data('nonce');
                
                button.prop('disabled', true).text('Syncing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'woo_update_api_sync_to_db',
                        product_id: productId,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('.woo-update-api-result').html(
                                '<div class="notice notice-success"><p>' + 
                                response.data.message + 
                                '</p></div>'
                            );
                            
                            // Actualizar status
                            if (response.data.updates) {
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            }
                        } else {
                            $('.woo-update-api-result').html(
                                '<div class="notice notice-error"><p>' + 
                                response.data.message + 
                                '</p></div>'
                            );
                        }
                    },
                    error: function() {
                        $('.woo-update-api-result').html(
                            '<div class="notice notice-error"><p>Request failed</p></div>'
                        );
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Sync to Database');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * ACTUALIZAR PRECIO CON PRIORIDAD: BD > API > WC original
     */
    public function update_price($price, $product) {
        // Don't override prices in admin area (except AJAX requests)
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }
        
        // NUEVO: Debug logging
        $debug = defined('WP_DEBUG') && WP_DEBUG && isset($_GET['debug_price']);
        if ($debug) {
            error_log('[Price Updater] Hook triggered for product: ' . $product->get_id());
        }

        try {
            $product_id = $product->get_id();
            
            // 1. PRIMERO: Verificar si hay precio recientemente actualizado en BD
            $last_sync = get_post_meta($product_id, '_last_api_sync', true);
            $api_price_in_db = get_post_meta($product_id, '_price', true);
            
            // Si se sincronizó hace menos de 5 minutos, usar BD
            if ($last_sync && strtotime($last_sync) > (time() - 300) && $api_price_in_db) {
                if ($debug) {
                    error_log('[Price Updater] Using DB price (recent sync): ' . $product_id . ' = ' . $api_price_in_db);
                }
                return floatval($api_price_in_db);
            }
            
            if ($debug) {
                error_log('[Price Updater] No recent DB sync for: ' . $product_id . ', fetching from API');
            }

            // 2. SEGUNDO: Obtener de API
            $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());

            // If API is unavailable or returns false, return original price
            if ($api_data === false) {
                $this->maybe_show_api_error($product_id);
                return $price;
            }

            // Check for price in API response
            if ($api_data && isset($api_data['price_mxn'])) {
                $api_price = floatval($api_data['price_mxn']);
                if ($debug) {
                    error_log('[Price Updater] Using API price (MXN): ' . $product_id . ' = ' . $api_price);
                }
                return $api_price;
            }
            
            if ($api_data && isset($api_data['price'])) {
                $api_price = floatval($api_data['price']);
                if ($debug) {
                    error_log('[Price Updater] Using API price: ' . $product_id . ' = ' . $api_price);
                }
                return $api_price;
            }

            if ($debug) {
                error_log('[Price Updater] No API price found, using original: ' . $price);
            }
            return $price;
            
        } catch (Exception $e) {
            error_log('[Price Updater Error] ' . $e->getMessage());
            $this->maybe_show_api_error($product->get_id());
            return $price;
        }
    }

    /**
     * ACTUALIZAR STOCK PARA VISUALIZACIÓN CON PRIORIDAD: BD > API > WC original
     */
    public function update_stock_display($quantity, $product) {
        // Don't override stock in admin area (except AJAX requests)
        if (is_admin() && !wp_doing_ajax()) {
            return $quantity;
        }
        
        // NUEVO: Debug logging
        $debug = defined('WP_DEBUG') && WP_DEBUG && isset($_GET['debug_stock']);
        if ($debug) {
            error_log('[Stock Display] Hook triggered for product: ' . $product->get_id());
        }

        try {
            $product_id = $product->get_id();
            
            // 1. PRIMERO: Verificar stock en BD si es reciente
            $last_sync = get_post_meta($product_id, '_last_api_sync', true);
            
            if ($last_sync && strtotime($last_sync) > (time() - 300)) {
                // Usar stock de BD (ya debería estar actualizado)
                $stock_in_db = $product->get_stock_quantity();
                if ($debug) {
                    error_log('[Stock Display] Using DB stock (recent sync): ' . $product_id . ' = ' . $stock_in_db);
                }
                return max(0, $stock_in_db);
            }
            
            if ($debug) {
                error_log('[Stock Display] No recent DB sync for: ' . $product_id . ', fetching from API');
            }

            // 2. SEGUNDO: Obtener de API
            $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());

            // If API is unavailable or returns false, return original quantity
            if ($api_data === false) {
                $this->maybe_show_api_error($product_id);
                return $quantity;
            }

            if ($api_data && isset($api_data['stock_quantity'])) {
                $stock = intval($api_data['stock_quantity']);
                if ($debug) {
                    error_log('[Stock Display] Using API stock: ' . $product_id . ' = ' . $stock);
                }
                // Ensure stock is not negative
                return max(0, $stock);
            }

            if ($debug) {
                error_log('[Stock Display] No API stock found, using original: ' . $quantity);
            }
            return $quantity;
            
        } catch (Exception $e) {
            error_log('[Stock Display Error] ' . $e->getMessage());
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
     * LIMPIAR CACHE DE PRODUCTO
     */
    public function clear_product_cache($product_id) {
        // Limpiar cache de WooCommerce
        wc_delete_product_transients($product_id);
        
        // Limpiar cache del plugin
        $product = wc_get_product($product_id);
        if ($product) {
            $cache_key = 'woo_update_api_product_' . md5($product_id . $product->get_sku());
            delete_transient($cache_key);
            
            // Limpiar cache de error
            $session_id = $this->api_handler->get_session_id();
            delete_transient('woo_update_api_frontend_errors_' . $session_id);
        }
        
        error_log('[Cache Cleared] Producto: ' . $product_id);
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