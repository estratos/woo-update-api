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
// Add this right after the plugin header comments
add_action('admin_init', function() {
    // Check if our settings class exists
    if (!class_exists('Woo_Update_API\Admin\Settings')) {
        error_log('Woo Update API: Settings class not found');
        return;
    }
    
    // Verify WooCommerce admin menu exists
    global $submenu;
    if (!isset($submenu['woocommerce'])) {
        error_log('Woo Update API: WooCommerce admin menu not found');
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>WooCommerce admin menu not detected!</p></div>';
        });
    }
    
    // Check if our menu was added
    $menu_found = false;
    if (isset($submenu['woocommerce'])) {
        foreach ($submenu['woocommerce'] as $item) {
            if (strpos($item[2], 'woo-update-api') !== false) {
                $menu_found = true;
                break;
            }
        }
    }
    
    if (!$menu_found) {
        error_log('Woo Update API: Menu item not found in WooCommerce submenu');
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>Update API menu item not found!</p></div>';
        });
    }
});

defined('ABSPATH') || exit;

// Define plugin constants
define('WOO_UPDATE_API_VERSION', '1.0.0');
define('WOO_UPDATE_API_PLUGIN_FILE', __FILE__);
define('WOO_UPDATE_API_PATH', plugin_dir_path(__FILE__));
define('WOO_UPDATE_API_URL', plugin_dir_url(__FILE__));

// Activation checks
register_activation_hook(__FILE__, function () {
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
add_action('plugins_loaded', function () {
    // Load core files
    $core_files = [
        'includes/class-api-handler.php',
        'includes/class-price-updater.php'
    ];

    foreach ($core_files as $file) {
        if (file_exists(WOO_UPDATE_API_PATH . $file)) {
            require_once WOO_UPDATE_API_PATH . $file;
        }
    }

    // Initialize core components
    if (class_exists('Woo_Update_API\API_Handler')) {
        Woo_Update_API\API_Handler::instance();
    }

    if (class_exists('Woo_Update_API\Price_Updater')) {
        Woo_Update_API\Price_Updater::instance();
    }

    // Add this right after the plugin header comments
    add_action('admin_init', function () {
        // Check if our settings class exists
        if (!class_exists('Woo_Update_API\Admin\Settings')) {
            error_log('Woo Update API: Settings class not found');
            return;
        }

        // Verify WooCommerce admin menu exists
        global $submenu;
        if (!isset($submenu['woocommerce'])) {
            error_log('Woo Update API: WooCommerce admin menu not found');
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>WooCommerce admin menu not detected!</p></div>';
            });
        }

        // Check if our menu was added
        $menu_found = false;
        if (isset($submenu['woocommerce'])) {
            foreach ($submenu['woocommerce'] as $item) {
                if (strpos($item[2], 'woo-update-api') !== false) {
                    $menu_found = true;
                    break;
                }
            }
        }

        if (!$menu_found) {
            error_log('Woo Update API: Menu item not found in WooCommerce submenu');
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>Update API menu item not found!</p></div>';
            });
        }
    });
    // Load admin files only in admin area
    if (is_admin()) {
        $admin_file = WOO_UPDATE_API_PATH . 'admin/class-settings.php';
        if (file_exists($admin_file)) {
            require_once $admin_file;
            if (class_exists('Woo_Update_API\Admin\Settings')) {
                Woo_Update_API\Admin\Settings::instance();
            } else {

                wp_die(
                    sprintf(
                        __('%s Error loading classes!!.', 'woo-update-api'),
                        'WooCommerce Update API'
                    )
                );
            }
        }
    }
}, 20);

// Load translations
add_action('init', function () {
    load_plugin_textdomain(
        'woo-update-api',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});
