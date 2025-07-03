<?php

/**
 * Plugin Name: WooCommerce Update API
 * Plugin URI: https://yourwebsite.com/woo-update-api
 * Description: Fetches real-time product pricing and inventory from external APIs.
 * Version: 1.0.0
 * Author: Estratos
 * Author URI: https://estratos.net
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

// Register AJAX handlers
add_action('wp_ajax_wc_update_api_manual_refresh', 'handle_refresh_request');
add_action('wp_ajax_nopriv_wc_update_api_manual_refresh', 'handle_no_permission');

function handle_no_permission() {
    wp_send_json_error(['message' => 'You must be logged in']);
}

function handle_refresh_request() {
    // Verify nonce
    if (!check_ajax_referer('wc_update_api_refresh', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }

    // Check capabilities
    if (!current_user_can('edit_products')) {
        wp_send_json_error(['message' => 'Insufficient permissions'], 403);
    }

    // Process request
    try {
        $product_id = absint($_POST['product_id']);
        // Your refresh logic here...
        
        wp_send_json_success([
            'message' => 'Product data refreshed',
            'last_refresh' => current_time('mysql')
        ]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// Enqueue scripts
add_action('admin_enqueue_scripts', function() {
    wp_enqueue_script(
        'woo-update-api-admin',
        plugins_url('assets/js/admin.js', __FILE__),
        ['jquery'],
        filemtime(plugin_dir_path(__FILE__) . 'assets/js/admin.js')
    );
    
    wp_localize_script('woo-update-api-admin', 'wc_update_api_params', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wc_update_api_refresh')
    ]);
});


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
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        admin_url('options-general.php?page=woo-update-api'),
        __('Settings', 'woo-update-api')
    );
    array_unshift($links, $settings_link);
    return $links;
});



// Add refresh button to product edit page
add_action('woocommerce_product_options_general_product_data', function () {
    global $post;
    echo '<div class="options_group">';
    echo '<p class="form-field">';
    echo '<label>' . __('Update API Data', 'woo-update-api') . '</label>';
    echo '<button type="button" id="wc-update-api-refresh" class="button" data-product-id="' . esc_attr($post->ID) . '">';
    echo __('Refresh API Data', 'woo-update-api');
    echo '</button>';
    echo '<span class="description">' . __('Manually refresh pricing and stock from API', 'woo-update-api') . '</span>';
    echo '</p>';
    echo '</div>';
});

// Enqueue admin scripts
add_action('admin_enqueue_scripts', function ($hook) {
    if ('post.php' === $hook || 'post-new.php' === $hook) {
        wp_enqueue_script(
            'woo-update-api-admin',
            plugins_url('assets/js/admin.js', __FILE__),
            ['jquery'],
            WOO_UPDATE_API_VERSION,
            true
        );
    }
});

// Handle manual refresh via AJAX
add_action('wp_ajax_wc_update_api_manual_refresh', function () {
    check_ajax_referer('update-post_' . $_POST['post_ID']);

    if (!current_user_can('edit_products')) {
        wp_send_json_error(['message' => __('Permission denied', 'woo-update-api')]);
    }

    $product_id = absint($_POST['product_id']);
    $product = wc_get_product($product_id);

    if (!$product) {
        wp_send_json_error(['message' => __('Product not found', 'woo-update-api')]);
    }

    // Clear any cached data for this product
    $transient_key = 'woo_update_api_data_' . md5($product_id . $product->get_sku());
    delete_transient($transient_key);

    // Force API refresh
    $api_handler = Woo_Update_API\API_Handler::instance();
    $api_data = $api_handler->get_product_data($product_id, $product->get_sku());

    if ($api_data === false) {
        wp_send_json_error(['message' => __('API refresh failed', 'woo-update-api')]);
    }

    // Update product meta to show last refresh time
    update_post_meta($product_id, '_wc_update_api_last_refresh', current_time('mysql'));

    wp_send_json_success([
        'message' => __('Product data refreshed successfully', 'woo-update-api'),
        'data' => $api_data
    ]);
});

// Add refresh option to quick edit
add_action('quick_edit_custom_box', function($column_name, $post_type) {
    if ($post_type === 'product' && $column_name === 'api_refresh') {
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label class="alignleft">
                    <input type="checkbox" name="refresh_api_data" value="1">
                    <span class="checkbox-title"><?php _e('Refresh API data', 'woo-update-api'); ?></span>
                </label>
            </div>
        </fieldset>
        <?php
    }
}, 10, 2);

// Handle quick edit refresh
add_action('save_post_product', function($product_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    if (isset($_REQUEST['refresh_api_data'])) {
        $product = wc_get_product($product_id);
        if ($product) {
            $transient_key = 'woo_update_api_data_' . md5($product_id . $product->get_sku());
            delete_transient($transient_key);
            update_post_meta($product_id, '_wc_update_api_last_refresh', current_time('mysql'));
        }
    }
});

// Load translations
add_action('init', function () {
    load_plugin_textdomain(
        'woo-update-api',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});
