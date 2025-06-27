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
register_activation_hook(__FILE__, function() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                __('%s requires WooCommerce to be installed and active. Please install WooCommerce first.', 'woo-update-api'),
                'WooCommerce Update API'
            )
        );
    }
});

// Initialize plugin
add_action('plugins_loaded', function() {
    // Load core files
    require_once WOO_UPDATE_API_PATH . 'includes/class-api-handler.php';
    require_once WOO_UPDATE_API_PATH . 'includes/class-price-updater.php';
    
    // Initialize core components
    Woo_Update_API\API_Handler::instance();
    Woo_Update_API\Price_Updater::instance();
    
    // Load admin files only in admin area
    if (is_admin()) {
        require_once WOO_UPDATE_API_PATH . 'includes/admin/class-settings.php';
        Woo_Update_API\Admin\Settings::instance();
    }
}, 20);

// Add plugin action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        admin_url('options-general.php?page=woo-update-api'),
        __('Settings', 'woo-update-api')
    );
    array_unshift($links, $settings_link);
    return $links;
});

// Load translations
add_action('init', function() {
    load_plugin_textdomain(
        'woo-update-api',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});