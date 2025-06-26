<?php
/**
 * Plugin Name: WooCommerce Update API
 * Description: Fetches product pricing and inventory from an external API in real-time.
 * Version: 1.0.0
 * Author: Estratos
 * Author URI: estratos.net
 * Text Domain: woo-updateapi
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
defined('ABSPATH') || exit;

// Define plugin constants
define('WOO_UPDATEAPI_VERSION', '1.0.0');
define('WOO_UPDATEAPI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOO_UPDATEAPI_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>';
        _e('WooCommerce Update API requires WooCommerce to be installed and active.', 'woo-updateapi');
        echo '</p></div>';
    });
    return;
}



// Include necessary files
require_once WOO_UPDATEAPI_PLUGIN_DIR . 'includes/class-api-handler.php';
require_once WOO_UPDATEAPI_PLUGIN_DIR . 'includes/class-price-updater.php';
require_once WOO_UPDATEAPI_PLUGIN_DIR . 'admin/settings-page.php';

// Initialize the plugin
function woo_updateapi_init() {
    Woo_UpdateAPI_Price_Updater::init();
    Woo_UpdateAPI_Settings::init();
}
add_action('plugins_loaded', 'woo_updateapi_init');

// Load text domain
function woo_updateapi_load_textdomain() {
    load_plugin_textdomain('woo-updateapi', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'woo_updateapi_load_textdomain');