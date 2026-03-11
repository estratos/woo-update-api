<?php
/**
 * Plugin Name: WooCommerce Update API
 * Plugin URI: 
 * Description: Plugin que consulta API externa para obtener precios y stock en tiempo real
 * Version: 2.2.0
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * Author: 
 * License: GPL v2 or later
 * Text Domain: woo-update-api
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

define('WOO_UPDATE_API_VERSION', '2.2.0');
define('WOO_UPDATE_API_PATH', plugin_dir_path(__FILE__));
define('WOO_UPDATE_API_URL', plugin_dir_url(__FILE__));

// Include required files
require_once WOO_UPDATE_API_PATH . 'admin/class-settings.php';
require_once WOO_UPDATE_API_PATH . 'includes/class-api-handler.php';
require_once WOO_UPDATE_API_PATH . 'includes/class-stock-synchronizer.php';
require_once WOO_UPDATE_API_PATH . 'includes/class-price-updater.php';
require_once WOO_UPDATE_API_PATH . 'includes/class-ajax-handler.php';
require_once WOO_UPDATE_API_PATH . 'includes/class-cache.php';

class Woo_Update_API {

    private static $instance = null;
    
    public $api_handler;
    public $stock_synchronizer;
    public $price_updater;
    
    private $settings;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        $this->settings = new Woo_Update_API_Settings();
        $this->api_handler = new Woo_Update_API_Handler($this->settings);
        $this->stock_synchronizer = new Woo_Update_API_Stock_Synchronizer($this->api_handler);
        $this->price_updater = new Woo_Update_API_Price_Updater($this->api_handler);
    }

    private function init_hooks() {
        // Inicialización
        add_action('init', [$this, 'init']);
        add_action('init', ['Woo_Update_API_Ajax_Handler', 'init']);
        
        // Hooks para actualización en carrito
        add_action('woocommerce_add_to_cart', [$this->stock_synchronizer, 'update_on_add_to_cart'], 10, 6);
        
        // Validación en checkout
        add_action('woocommerce_after_checkout_validation', [$this->stock_synchronizer, 'validate_checkout_stock'], 10, 2);
        
        // Después de completar la compra
        add_action('woocommerce_checkout_order_processed', [$this->stock_synchronizer, 'update_stock_after_purchase'], 10, 3);
        
        // Limpiar caché cuando se actualiza un producto en admin
        add_action('save_post_product', [$this, 'clear_product_cache_on_save'], 10, 3);
        
        // Registrar endpoint para reset de stats (solo debug)
        if ($this->settings->get_debug_mode()) {
            add_action('wp_ajax_woo_update_api_reset_stats', [$this, 'ajax_reset_cache_stats']);
        }
    }

    public function init() {
        load_plugin_textdomain('woo-update-api', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Limpiar caché cuando se guarda un producto en admin
     */
    public function clear_product_cache_on_save($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $product = wc_get_product($post_id);
        if ($product && $product->get_sku()) {
            Woo_Update_API_Cache::delete($product->get_sku());
            $this->log('Cache cleared for product ID: ' . $post_id . ' (SKU: ' . $product->get_sku() . ')', 'cache');
        }
    }

    /**
     * AJAX: Resetear estadísticas de caché
     */
    public function ajax_reset_cache_stats() {
        if (!current_user_can('manage_options') || 
            !isset($_POST['nonce']) || 
            !wp_verify_nonce($_POST['nonce'], 'woo_update_api_admin')) {
            wp_die('Security check failed');
        }

        Woo_Update_API_Cache::reset_stats();
        wp_send_json_success();
    }

    /**
     * Sistema de logging mejorado
     */
    public function log($message, $type = 'info') {
        // Solo log si WP_DEBUG está activado o debug mode está habilitado
        if (!defined('WP_DEBUG') || WP_DEBUG !== true) {
            if (!$this->settings->get_debug_mode()) {
                return;
            }
        }

        $prefixes = [
            'api' => '[API]',
            'cache' => '[Cache]',
            'cart' => '[Cart]',
            'price' => '[Price]',
            'validate' => '[Validate]',
            'ajax' => '[AJAX]',
            'info' => '[Info]',
            'error' => '[ERROR]'
        ];

        $prefix = $prefixes[$type] ?? '[Woo Update API]';
        $log_message = $prefix . ' ' . $message;

        error_log($log_message);

        // También guardar en un log file si debug mode está activado
        if ($this->settings->get_debug_mode()) {
            $log_file = WP_CONTENT_DIR . '/uploads/woo-update-api-debug.log';
            $timestamp = current_time('mysql');
            file_put_contents($log_file, "[$timestamp] $log_message\n", FILE_APPEND);
        }
    }
    
    public function get_settings() {
        return $this->settings;
    }
}

// Initialize the plugin
function Woo_Update_API() {
    return Woo_Update_API::get_instance();
}

// Iniciar el plugin
Woo_Update_API();