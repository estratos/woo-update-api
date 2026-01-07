<?php
/**
 * Plugin Name: WooCommerce Update API
 * Plugin URI: https://github.com/estratos/woo-update-api
 * Description: Fetches real-time product pricing and inventory from external APIs with manual refresh capability. Includes stock synchronization.
 * Version: 1.3.0
 * Author: Estratos
 * Author URI: https://estratos.net
 * Text Domain: woo-update-api
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * WC tested up to: 8.2
 */

namespace Woo_Update_API;

defined('ABSPATH') || exit;

// Define plugin constants
define('WOO_UPDATE_API_VERSION', '1.3.0');
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

class Woo_Update_API
{
    private static $instance = null;

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Check if WooCommerce is active
        register_activation_hook(__FILE__, [$this, 'check_woocommerce_active']);

        // Initialize plugin
        add_action('plugins_loaded', [$this, 'init'], 20);
    }

    public function check_woocommerce_active()
    {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                __('WooCommerce Update API requires WooCommerce to be installed and active.', 'woo-update-api'),
                __('Plugin dependency error', 'woo-update-api'),
                ['back_link' => true]
            );
        }
    }

    public function init()
    {
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

    private function includes()
    {
        require_once WOO_UPDATE_API_PATH . 'includes/class-api-handler.php';
        require_once WOO_UPDATE_API_PATH . 'includes/class-price-updater.php';
        require_once WOO_UPDATE_API_PATH . 'includes/class-ajax-handler.php';
        require_once WOO_UPDATE_API_PATH . 'includes/class-api-error-manager.php';
        require_once WOO_UPDATE_API_PATH . 'includes/class-stock-synchronizer.php';

        if (is_admin()) {
            require_once WOO_UPDATE_API_PATH . 'admin/class-settings.php';
        }
    }

    private function init_hooks()
    {
        // Initialize classes
        API_Handler::instance();
        Price_Updater::instance();
        Ajax_Handler::instance();
        API_Error_Manager::instance();
        Stock_Synchronizer::instance();

        // Register AJAX handlers
        add_action('wp_ajax_woo_update_api_get_status', [API_Handler::instance(), 'ajax_get_status']);
        add_action('wp_ajax_woo_update_api_reconnect', [API_Handler::instance(), 'ajax_reconnect']);
        add_action('wp_ajax_woo_update_api_validate_stock', [Ajax_Handler::instance(), 'ajax_validate_stock']);
        add_action('wp_ajax_nopriv_woo_update_api_validate_stock', [Ajax_Handler::instance(), 'ajax_validate_stock']);
        add_action('wp_ajax_woo_update_api_sync_to_db', [API_Handler::instance(), 'ajax_sync_to_db']);
        
        if (is_admin()) {
            Admin\Settings::instance();
            add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        }

        // Add settings link to plugin page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        
        // Schedule daily stock sync
        add_action('init', [$this, 'schedule_daily_sync']);
    }
    
    public function schedule_daily_sync()
    {
        if (!wp_next_scheduled('woo_update_api_daily_stock_sync')) {
            wp_schedule_event(time(), 'daily', 'woo_update_api_daily_stock_sync');
        }
        
        // NUEVO: Schedule hourly sync for critical products
        if (!wp_next_scheduled('woo_update_api_hourly_sync')) {
            wp_schedule_event(time(), 'hourly', 'woo_update_api_hourly_sync');
        }
    }

    public function admin_scripts($hook)
    {
        // Only load on product edit pages and settings page
        if (!in_array($hook, ['post.php', 'post-new.php', 'settings_page_woo-update-api'])) {
            return;
        }

        // Load on product pages
        if (in_array($hook, ['post.php', 'post-new.php'])) {
            global $post;
            if (!$post || 'product' !== $post->post_type) {
                return;
            }
        }

        // Enqueue admin JS
        wp_enqueue_script(
            'woo-update-api-admin',
            WOO_UPDATE_API_URL . 'assets/js/admin.js',
            ['jquery', 'woocommerce_admin'],
            WOO_UPDATE_API_VERSION,
            true
        );

        // Localize script data
        wp_localize_script('woo-update-api-admin', 'woo_update_api', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_update_api_nonce'),
            'sync_nonce' => wp_create_nonce('wc_update_api_sync_db'),
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'i18n' => [
                'connecting' => __('Connecting...', 'woo-update-api'),
                'connected' => __('Connected!', 'woo-update-api'),
                'failed' => __('Connection Failed', 'woo-update-api'),
                'reconnect' => __('Reconnect Now', 'woo-update-api'),
                'connection_failed' => __('API connection failed', 'woo-update-api'),
                'status_error' => __('Could not load status', 'woo-update-api'),
                'request_failed' => __('Request failed. Please try again.', 'woo-update-api'),
                'refreshing' => __('Refreshing...', 'woo-update-api'),
                'success' => __('Success!', 'woo-update-api'),
                'error' => __('Error', 'woo-update-api'),
                'validating_stock' => __('Validating stock...', 'woo-update-api'),
                'insufficient_stock' => __('Insufficient stock', 'woo-update-api'),
                'syncing' => __('Syncing to database...', 'woo-update-api'),
                'sync_complete' => __('Sync complete!', 'woo-update-api')
            ]
        ]);

        // Add inline CSS
        wp_add_inline_style('woocommerce_admin_styles', '
            .wc-update-api-container {
                padding: 15px;
                margin: 15px 0;
                border: 1px solid #ccd0d4;
                background: #f6f7f7;
                border-radius: 4px;
            }
            .wc-update-api-container h3 {
                margin-top: 0;
                margin-bottom: 10px;
            }
            .wc-update-api-refresh,
            .wc-update-api-sync-db {
                position: relative;
                margin-right: 5px;
            }
            .wc-update-api-refresh .spinner {
                float: none;
                margin: 0 5px 0 0;
                visibility: hidden;
            }
            .wc-update-api-refresh.loading .spinner {
                visibility: visible;
            }
            .woo-update-api-result {
                margin-top: 10px;
            }
            .woo-update-api-status {
                margin-top: 10px;
                padding: 10px;
                border-radius: 4px;
            }
            .woo-update-api-status.success {
                background-color: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
            }
            .woo-update-api-status.error {
                background-color: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
            }
            .woo-update-api-status.warning {
                background-color: #fff3cd;
                border: 1px solid #ffeaa7;
                color: #856404;
            }
            .sync-status {
                margin-top: 10px;
                padding: 10px;
                background: #f5f5f5;
                border-radius: 4px;
                font-size: 12px;
            }
            .sync-status span {
                font-weight: bold;
            }
        ');
    }

    public function add_settings_link($links)
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('options-general.php?page=woo-update-api'),
            __('Settings', 'woo-update-api')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
}

// Initialize the plugin
Woo_Update_API::instance();

// Hook for daily stock sync
add_action('woo_update_api_daily_stock_sync', function() {
    $sync = Woo_Update_API\Stock_Synchronizer::instance();
    
    // Get all product IDs
    $products = wc_get_products([
        'limit' => -1,
        'return' => 'ids'
    ]);
    
    foreach ($products as $product_id) {
        $sync->force_stock_sync($product_id);
        // Small delay to avoid API overload
        usleep(100000); // 0.1 second
    }
});

// Hook for hourly sync of products with recent activity
add_action('woo_update_api_hourly_sync', function() {
    $sync = Woo_Update_API\Stock_Synchronizer::instance();
    
    // Get products with recent sync (last 24 hours)
    global $wpdb;
    $product_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_last_api_sync' 
            AND meta_value >= %s",
            date('Y-m-d H:i:s', time() - 86400) // Last 24 hours
        )
    );
    
    foreach ($product_ids as $product_id) {
        $sync->force_stock_sync($product_id);
        usleep(50000); // 0.05 second
    }
});

// Debug mode activation
if (isset($_GET['debug_api'])) {
    add_action('init', function() {
        if (current_user_can('administrator')) {
            error_log('[API DEBUG] Debug mode activated');
            
            // Enable detailed logging
            add_filter('woo_update_api_debug', '__return_true');
            
            // Show debug info in admin bar
            add_action('admin_bar_menu', function($wp_admin_bar) {
                $wp_admin_bar->add_node([
                    'id'    => 'api-debug',
                    'title' => 'API Debug ON',
                    'href'  => '#',
                    'meta'  => ['class' => 'api-debug-node']
                ]);
            }, 999);
        }
    });
}