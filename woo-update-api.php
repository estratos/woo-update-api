<?php
/**
 * Plugin Name: WooCommerce Update API
 * Plugin URI: https://yourwebsite.com/woo-update-api
 * Description: Fetches real-time product pricing and inventory from external APIs.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: woo-update-api
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.0
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('WOO_UPDATE_API_VERSION', '1.0.0');
define('WOO_UPDATE_API_PLUGIN_FILE', __FILE__);
define('WOO_UPDATE_API_PATH', plugin_dir_path(__FILE__));
define('WOO_UPDATE_API_URL', plugin_dir_url(__FILE__));

// Activation checks
register_activation_hook(__FILE__, 'woo_update_api_activate');

function woo_update_api_activate() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                __('%s requires WooCommerce to be installed and active. Please install WooCommerce first.', 'woo-update-api'),
                'WooCommerce Update API'
            )
        );
    }
}

// Initialize plugin
add_action('plugins_loaded', 'woo_update_api_init', 20); // Increased priority to 20

function woo_update_api_init() {
    // Load required files
    $files = [
        'includes/class-api-handler.php',
        'includes/class-price-updater.php',
        'admin/class-settings.php' // Make sure this path is correct
    ];

    foreach ($files as $file) {
        $file_path = WOO_UPDATE_API_PATH . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        } else {
            error_log('[Woo Update API] Missing file: ' . $file_path);
        }
    }

    // Check if classes exist before initialization
    if (class_exists('Woo_Update_API\API_Handler')) {
        Woo_Update_API\API_Handler::instance();
    }

    if (class_exists('Woo_Update_API\Price_Updater')) {
        Woo_Update_API\Price_Updater::instance();
    }
    
    if (is_admin() && class_exists('Woo_Update_API\Admin\Settings')) {
        Woo_Update_API\Admin\Settings::instance();
    }
}

// Load translations
add_action('init', 'woo_update_api_load_textdomain');

function woo_update_api_load_textdomain() {
    load_plugin_textdomain(
        'woo-update-api',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
