<?php
/**
 * Plugin Name: WooCommerce Update API
 * Plugin URI: 
 * Description: Plugin que consulta API externa para obtener precios y stock en tiempo real
 * Version: 2.0.3
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

define('WOO_UPDATE_API_VERSION', '2.0.3'
define('WOO_UPDATE_API_PATH', plugin_dir_path(__FILE__));
define('WOO_UPDATE_API_URL', plugin_dir_url(__FILE__));

// Include required files
require_once WOO_UPDATE_API_PATH . 'admin/class-settings.php';
require_once WOO_UPDATE_API_PATH . 'includes/class-api-handler.php';
require_once WOO_UPDATE_API_PATH . 'includes/class-stock-synchronizer.php';
require_once WOO_UPDATE_API_PATH . 'includes/class-price-updater.php';
require_once WOO_UPDATE_API_PATH . 'includes/class-ajax-handler.php';
require_once WOO_UPDATE_API_PATH . 'includes/class-api-error-manager.php';

class Woo_Update_API {

    private static $instance = null;
    public $api_handler;
    public $stock_synchronizer;
    public $price_updater;
    public $error_manager;
    
    // Eliminamos $settings de aquí como propiedad pública
    private $settings; // Cambiamos a private para evitar duplicación

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
        // Solo instanciamos UNA VEZ cada clase
        $this->settings = new Woo_Update_API_Settings(); // Una sola instancia
        $this->api_handler = new Woo_Update_API_Handler($this->settings); // Pasamos la instancia
        $this->stock_synchronizer = new Woo_Update_API_Stock_Synchronizer($this->api_handler);
        $this->price_updater = new Woo_Update_API_Price_Updater($this->api_handler);
        $this->error_manager = new Woo_Update_API_Error_Manager();
    }

    private function init_hooks() {
        // Inicializar componentes - solo una vez
        add_action('init', [$this, 'init']);
        
        // Hooks para actualización en carrito
        add_action('woocommerce_add_to_cart', [$this->stock_synchronizer, 'update_on_add_to_cart'], 10, 6);
        
        // Validación en checkout
        add_action('woocommerce_after_checkout_validation', [$this->stock_synchronizer, 'validate_checkout_stock'], 10, 2);
        
        // Después de completar la compra
        add_action('woocommerce_checkout_order_processed', [$this->stock_synchronizer, 'update_stock_after_purchase'], 10, 3);
        
        // AJAX handlers
        add_action('wp_ajax_woo_update_api_get_product', ['Woo_Update_API_Ajax_Handler', 'get_product_data']);
        add_action('wp_ajax_nopriv_woo_update_api_get_product', ['Woo_Update_API_Ajax_Handler', 'get_product_data']);
    }

    public function init() {
        // Cargar text domain
        load_plugin_textdomain('woo-update-api', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function log($message, $type = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            $prefix = '';
            switch ($type) {
                case 'api':
                    $prefix = '[API Direct]';
                    break;
                case 'cart':
                    $prefix = '[Add to Cart]';
                    break;
                case 'price':
                    $prefix = '[Price Updater]';
                    break;
                case 'validate':
                    $prefix = '[Validate]';
                    break;
                default:
                    $prefix = '[Woo Update API]';
            }
            error_log($prefix . ' ' . $message);
        }
    }
    
    // Método getter para acceder a settings si es necesario
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