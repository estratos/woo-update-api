<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class Price_Updater
{
    private static $instance = null;
    private $api_handler;
    private $should_update = false;

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
        // Detectar si debemos actualizar DESPUÉS de que WordPress cargue
        add_action('wp', [$this, 'check_if_should_update']);
        
        // Admin hooks (siempre activos)
        if (is_admin()) {
            add_action('woocommerce_product_options_general_product_data', [$this, 'add_refresh_ui']);
            add_action('admin_notices', [$this, 'admin_notice_fallback_mode']);
        }
    }

    /**
     * Verificar si estamos en página de producto
     */
    public function check_if_should_update()
    {
        if (is_product()) {
            $this->should_update = true;
            
            // Agregar filters SOLO en página de producto
            add_filter('woocommerce_product_get_price', [$this, 'update_price'], 20, 2);
            add_filter('woocommerce_product_get_regular_price', [$this, 'update_price'], 20, 2);
            add_filter('woocommerce_product_get_sale_price', [$this, 'update_price'], 20, 2);
            add_filter('woocommerce_product_variation_get_price', [$this, 'update_price'], 20, 2);
            add_filter('woocommerce_product_variation_get_regular_price', [$this, 'update_price'], 20, 2);
            add_filter('woocommerce_product_variation_get_sale_price', [$this, 'update_price'], 20, 2);
            
            // Stock
            add_filter('woocommerce_product_get_stock_quantity', [$this, 'update_stock_display'], 20, 2);
            add_filter('woocommerce_variation_get_stock_quantity', [$this, 'update_stock_display'], 20, 2);
        }
    }

    /**
     * ACTUALIZAR PRECIO - SOLO EN PÁGINA DE PRODUCTO
     */
    public function update_price($price, $product)
    {
        // Seguridad: solo ejecutar en página de producto
        if (!$this->should_update) {
            return $price;
        }

        try {
            $product_id = $product->get_id();
            
            // Cache de sesión (5 minutos)
            $cache_key = 'woo_api_price_' . $product_id;
            $cached_price = $this->get_session_cache($cache_key);
            
            if ($cached_price !== null) {
                return $cached_price;
            }

            // Obtener de API
            $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());

            if ($api_data === false) {
                return $price;
            }

            // Verificar precio
            if (isset($api_data['price_mxn'])) {
                $api_price = floatval($api_data['price_mxn']);
            } elseif (isset($api_data['price'])) {
                $api_price = floatval($api_data['price']);
            } else {
                return $price;
            }

            // Guardar en cache de sesión
            $this->set_session_cache($cache_key, $api_price, 300);

            return $api_price;

        } catch (Exception $e) {
            error_log('[Price Updater] Error: ' . $e->getMessage());
            return $price;
        }
    }

    /**
     * ACTUALIZAR STOCK - SOLO EN PÁGINA DE PRODUCTO
     */
    public function update_stock_display($quantity, $product)
    {
        if (!$this->should_update) {
            return $quantity;
        }

        try {
            $product_id = $product->get_id();
            
            $cache_key = 'woo_api_stock_' . $product_id;
            $cached_stock = $this->get_session_cache($cache_key);
            
            if ($cached_stock !== null) {
                return $cached_stock;
            }

            $api_data = $this->api_handler->get_product_data($product_id, $product->get_sku());

            if ($api_data && isset($api_data['stock_quantity'])) {
                $stock = intval($api_data['stock_quantity']);
                $this->set_session_cache($cache_key, $stock, 300);
                return max(0, $stock);
            }

            return $quantity;

        } catch (Exception $e) {
            error_log('[Stock Display] Error: ' . $e->getMessage());
            return $quantity;
        }
    }

    /**
     * CACHE DE SESIÓN
     */
    private function get_session_cache($key)
    {
        if (!function_exists('WC') || !WC()->session) {
            return null;
        }
        return WC()->session->get($key);
    }

    private function set_session_cache($key, $value, $expire = 300)
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        WC()->session->set($key, $value);
    }

    /**
     * ADMIN UI - SIN CAMBIOS
     */
    public function add_refresh_ui()
    {
        global $post;

        if (!$post || 'product' !== $post->post_type) {
            return;
        }

        $product = wc_get_product($post->ID);
        if (!$product) {
            return;
        }

        ?>
        <div class="wc-update-api-container">
            <h3><?php esc_html_e('API Data Refresh', 'woo-update-api'); ?></h3>
            
            <button class="button button-primary wc-update-api-refresh" 
                    data-product-id="<?php echo esc_attr($post->ID); ?>" 
                    data-nonce="<?php echo wp_create_nonce('wc_update_api_refresh'); ?>">
                <span class="spinner"></span>
                <?php esc_html_e('Refresh Now', 'woo-update-api'); ?>
            </button>
            
            <button class="button button-secondary wc-update-api-sync-db" 
                    data-product-id="<?php echo esc_attr($post->ID); ?>" 
                    data-nonce="<?php echo wp_create_nonce('wc_update_api_sync_db'); ?>">
                <?php esc_html_e('Sync to Database', 'woo-update-api'); ?>
            </button>
            
            <div class="sync-status">
                <strong><?php esc_html_e('Sync Status:', 'woo-update-api'); ?></strong><br>
                <?php
                $last_sync = get_post_meta($post->ID, '_last_api_sync', true);
                if ($last_sync) {
                    $time_diff = human_time_diff(strtotime($last_sync), current_time('timestamp'));
                    echo esc_html__('Last sync:', 'woo-update-api') . ' ' . $time_diff . ' ' . esc_html__('ago', 'woo-update-api');
                } else {
                    esc_html_e('Never synced to database', 'woo-update-api');
                }
                ?>
            </div>
            
            <div class="woo-update-api-result"></div>
        </div>
        <?php
    }

    public function admin_notice_fallback_mode()
    {
        if ($this->api_handler->is_in_fallback_mode()) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php _e('WooCommerce Update API is currently in fallback mode. The external API service is unavailable, so default WooCommerce pricing and inventory data is being used.', 'woo-update-api'); ?></p>
            </div>
            <?php
        }
    }
}