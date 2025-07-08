<?php
/**
 * Plugin Name: WooCommerce Update API
 * Plugin URI: https://github.com/estratos/woo-update-api
 * Description: Fetches real-time product pricing and inventory from external APIs with manual refresh capability.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: woo-update-api
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('WOO_UPDATE_API_VERSION', '1.0.0');
define('WOO_UPDATE_API_PATH', plugin_dir_path(__FILE__));
define('WOO_UPDATE_API_URL', plugin_dir_url(__FILE__));
define('WOO_UPDATE_API_FILE', __FILE__);

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'Woo_Update_API\\';
    $base_dir = WOO_UPDATE_API_PATH . 'includes/';
    
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    
    $relative_class = substr($class, strlen($prefix));
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

class Woo_Update_API {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Check if WooCommerce is active
        register_activation_hook(__FILE__, [$this, 'check_woocommerce_active']);
        
        // Initialize plugin
        add_action('plugins_loaded', [$this, 'init'], 20);
        add_action('wp_ajax_wc_update_api_reconnect', [$this, 'ajax_reconnect']);

    }

   

    public function check_woocommerce_active() {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                __('WooCommerce Update API requires WooCommerce to be installed and active.', 'woo-update-api'),
                __('Plugin dependency error', 'woo-update-api'),
                ['back_link' => true]
            );
        }
    }

    public function init() {
        // Load text domain
        load_plugin_textdomain(
            'woo-update-api',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );

        // Initialize components
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once WOO_UPDATE_API_PATH . 'includes/class-api-handler.php';
        require_once WOO_UPDATE_API_PATH . 'includes/class-price-updater.php';
        require_once WOO_UPDATE_API_PATH . 'includes/class-ajax-handler.php';
         require_once WOO_UPDATE_API_PATH . 'includes/class-api-error-manager.php';

        if (is_admin()) {
            require_once WOO_UPDATE_API_PATH . 'admin/class-settings.php';
        }
    }

    private function init_hooks() {
        // Initialize classes
        new Woo_Update_API\API_Handler();
        new Woo_Update_API\Price_Updater();
        new Woo_Update_API\Ajax_Handler();
        new Woo_Update_API\API_Error_Manager();
        
        if (is_admin()) {
            new Woo_Update_API\Admin\Settings();
            add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        }

        // Add settings link to plugin page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
    }

    public function admin_scripts($hook) {
        // Only load on product edit pages
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }
        
        global $post;
        if ('product' !== $post->post_type) {
            return;
        }

        // Enqueue admin JS
        wp_enqueue_script(
            'woo-update-api-admin',
            WOO_UPDATE_API_URL . 'assets/js/admin.js',
            ['jquery', 'woocommerce_admin'],
            filemtime(WOO_UPDATE_API_PATH . 'assets/js/admin.js'),
            true
        );

        // Localize script data
        

        wp_localize_script('wc-update-api-admin', 'woo_update_api', [
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('woo_update_api_nonce'),
    'i18n'    => [
        'connecting'      => __('Connecting...', 'woo-update-api'),
        'connected'      => __('Connected!', 'woo-update-api'),
        'failed'         => __('Connection Failed', 'woo-update-api'),
        'connection_failed' => __('API connection failed', 'woo-update-api'),
        'status_error'   => __('Could not load status', 'woo-update-api'),
        'fallback_updated' => __('Fallback settings updated', 'woo-update-api')
    ]
]);

        // Add inline CSS
        wp_add_inline_style('woocommerce_admin_styles', '
            .wc-update-api-container {
                padding: 15px;
                margin: 15px 0;
                border: 1px solid #ccd0d4;
                background: #f6f7f7;
            }
            .wc-update-api-refresh .spinner {
                float: none;
                margin: 0 5px 0 0;
            }
        ');
    }

    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('options-general.php?page=woo-update-api'),
            __('Settings', 'woo-update-api')
        );
        array_unshift($links, $settings_link);
        return $links;
    }


    public function ajax_reconnect() {
    check_ajax_referer('woo_update_api_nonce', 'security');

    try {
        $api = new WC_Update_API_Handler();
        $api->test_connection();
        $error_manager = new WC_Update_API_Error_Manager();
        $error_manager->reset_errors();
        
        ob_start();
        $this->display_api_status();
        $status_html = ob_get_clean();
        
        wp_send_json_success([
            'message' => __('Reconnected successfully! Error counter reset.', 'woo-update-api'),
            'status_html' => $status_html
        ]);
    } catch (Exception $e) {
        wp_send_json_error([
            'message' => __('Reconnect failed: ', 'woo-update-api') . $e->getMessage()
        ]);
    }
}




}

// Initialize the plugin
Woo_Update_API::instance();