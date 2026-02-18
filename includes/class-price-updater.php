<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class Price_Updater
{
    private static $instance = null;
    private $api_handler;
    
    /**
     * Caché en memoria para esta petición
     */
    private $api_cache = [];

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
        // SOLO en página de producto
        add_action('wp', [$this, 'setup_product_filters']);
        
        // Admin hooks
        if (is_admin()) {
            add_action('woocommerce_product_options_general_product_data', [$this, 'add_refresh_ui']);
        }
    }

    /**
     * Configurar filters SOLO en página de producto
     */
    public function setup_product_filters()
    {
        if (is_product()) {
            error_log('[Price Updater] Activando filters para página de producto');
            
            // SIN CACHÉ PERSISTENTE - SOLO EN MEMORIA PARA ESTA PETICIÓN
            add_filter('woocommerce_product_get_price', [$this, 'get_price_from_api'], 999, 2);
            add_filter('woocommerce_product_get_regular_price', [$this, 'get_price_from_api'], 999, 2);
            add_filter('woocommerce_product_get_sale_price', [$this, 'get_price_from_api'], 999, 2);
            add_filter('woocommerce_product_variation_get_price', [$this, 'get_price_from_api'], 999, 2);
            add_filter('woocommerce_product_variation_get_regular_price', [$this, 'get_price_from_api'], 999, 2);
            
            // Stock
            add_filter('woocommerce_product_get_stock_quantity', [$this, 'get_stock_from_api'], 999, 2);
            add_filter('woocommerce_variation_get_stock_quantity', [$this, 'get_stock_from_api'], 999, 2);
        }
    }

    /**
     * OBTENER DATOS DE API CON CACHÉ EN MEMORIA (UNA SOLA CONSULTA)
     */
    private function get_cached_api_data($product_id, $sku)
    {
        // Si ya tenemos datos en memoria para este producto, usarlos
        if (isset($this->api_cache[$product_id])) {
            error_log('[Price Updater] Usando caché en memoria para: ' . $product_id);
            return $this->api_cache[$product_id];
        }
        
        // Si no, consultar API
        error_log('[Price Updater] Consultando API para: ' . $product_id);
        $data = $this->api_handler->get_product_data_direct($product_id, $sku);
        
        // Guardar en caché en memoria
        $this->api_cache[$product_id] = $data;
        
        return $data;
    }

    /**
     * OBTENER PRECIO DE API - CON CACHÉ EN MEMORIA
     */
    public function get_price_from_api($price, $product)
    {
        try {
            $product_id = $product->get_id();
            
            // Usar caché en memoria
            $api_data = $this->get_cached_api_data($product_id, $product->get_sku());
            
            if ($api_data === false) {
                return $price;
            }
            
            // Obtener precio
            if (isset($api_data['price_mxn'])) {
                return floatval($api_data['price_mxn']);
            }
            
            if (isset($api_data['price'])) {
                return floatval($api_data['price']);
            }
            
            return $price;
            
        } catch (Exception $e) {
            error_log('[Price Updater] Error: ' . $e->getMessage());
            return $price;
        }
    }

    /**
     * OBTENER STOCK DE API - CON CACHÉ EN MEMORIA
     */
    public function get_stock_from_api($quantity, $product)
    {
        try {
            $product_id = $product->get_id();
            
            // Usar caché en memoria
            $api_data = $this->get_cached_api_data($product_id, $product->get_sku());
            
            if ($api_data && isset($api_data['stock_quantity'])) {
                return intval($api_data['stock_quantity']);
            }
            
            return $quantity;
            
        } catch (Exception $e) {
            error_log('[Price Updater] Error stock: ' . $e->getMessage());
            return $quantity;
        }
    }

    /**
     * ADMIN UI - Botones de sincronización
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
                    esc_html_e('Never synced', 'woo-update-api');
                }
                ?>
            </div>
            
            <div class="woo-update-api-result"></div>
        </div>
        <?php
    }
}