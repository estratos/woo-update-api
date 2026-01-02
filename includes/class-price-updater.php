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

    public function update_price($price, $product)
    {
        // Don't override prices in admin area (except AJAX requests)
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }

        // Get API data
        $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());

        // If API is unavailable or returns false, return original price
        if ($api_data === false) {
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
    }

    /**
     * ACTUALIZAR STOCK PARA VISUALIZACIÓN (NO modifica BD aquí)
     */
    public function update_stock_display($quantity, $product)
    {
        // Don't override stock in admin area (except AJAX requests)
        if (is_admin() && !wp_doing_ajax()) {
            return $quantity;
        }

        $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());

        // If API is unavailable or returns false, return original quantity
        if ($api_data === false) {
            return $quantity;
        }

        if ($api_data && isset($api_data['totalstock'])) {
            $stock = intval($api_data['totalstock']);
            // Ensure stock is not negative
            return max(0, $stock);
        }

        return $quantity;
    }

    public function update_stock_status($status, $product)
    {
        // Don't override status in admin area (except AJAX requests)
        if (is_admin() && !wp_doing_ajax()) {
            return $status;
        }

        $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());

        // If API is unavailable or returns false, return original status
        if ($api_data === false) {
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