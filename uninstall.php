<?php
/**
 * WooCommerce Update API - Uninstall Script
 * 
 * @package Woo_Update_API
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('woo_update_api_settings');
delete_option('woo_update_api_version');

// Delete transients
$transients = [
    'woo_update_api_fallback_mode',
    'woo_update_api_fallback_start',
    'woo_update_api_error_count',
    'woo_update_api_fallback_active'
];

foreach ($transients as $transient) {
    delete_transient($transient);
}

// Clear all product transients
global $wpdb;
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_woo_update_api_product_%',
        '_transient_timeout_woo_update_api_product_%'
    )
);

// Delete product meta
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)",
        '_api_price',
        '_api_stock',
        '_wc_update_api_last_refresh'
    )
);

// Clear any scheduled hooks
wp_clear_scheduled_hook('woo_update_api_daily_cleanup');