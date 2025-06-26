<?php
/**
 * Plugin Name: WooCommerce Update API External API Pricing & Inventory
 * Description: Fetches product pricing and inventory from an external API in real-time.
 * Version: 1.0.0
 * Author: Estratos
 * Author URI: estratos.net
 * Text Domain: woo-update-api
 * Domain Path: /languages
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('WC_EXTERNAL_API_VERSION', '1.0.0');
define('WC_EXTERNAL_API_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_EXTERNAL_API_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>';
        _e('WooCommerce External API Pricing & Inventory requires WooCommerce to be installed and active.', 'wc-external-api');
        echo '</p></div>';
    });
    return;
}

// Include necessary files
require_once WC_EXTERNAL_API_PLUGIN_DIR . 'includes/class-api-handler.php';
require_once WC_EXTERNAL_API_PLUGIN_DIR . 'includes/class-price-updater.php';
require_once WC_EXTERNAL_API_PLUGIN_DIR . 'admin/settings-page.php';

// Initialize the plugin
function wc_external_api_init() {
    WC_External_API_Price_Updater::init();
    WC_External_API_Settings::init();
}
add_action('plugins_loaded', 'wc_external_api_init');

// Load text domain
function wc_external_api_load_textdomain() {
    load_plugin_textdomain('woo-update-api', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'wc_external_api_load_textdomain');
