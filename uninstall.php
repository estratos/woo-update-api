<?php
/**
 * Uninstall script for WooCommerce Update API
 * 
 * This file runs when the plugin is uninstalled (deleted) via WordPress admin.
 * It cleans up all plugin data from the database.
 * 
 * @package Woo_Update_API
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Elimina todas las opciones de configuración del plugin
 */
function woo_update_api_uninstall() {
    
    // 1. Eliminar la opción principal con toda la configuración
    $option_name = 'woo_update_api_settings';
    delete_option($option_name);
    
    // 2. Por si acaso, también eliminar de las opciones de red (multisite)
    delete_site_option($option_name);
    
    // 3. Eliminar cualquier transient que haya podido quedar
    delete_transient('woo_update_api_test_connection');
    
    // 4. Limpiar meta datos de productos si existieran (opcional)
    // Por ahora no guardamos meta datos persistentes, pero si en futuro se agregan,
    // aquí se pueden limpiar
    
    /**
     * Nota: No eliminamos metadatos de productos como '_last_api_sync' 
     * porque son datos útiles que otros plugins podrían necesitar.
     * Además, si el plugin se reactiva, esos datos pueden ser reutilizados.
     * 
     * Si quisieras eliminarlos, podrías usar:
     * 
     * global $wpdb;
     * $wpdb->delete($wpdb->postmeta, ['meta_key' => '_last_api_sync']);
     */
    
    // 5. Log de desinstalación (solo si WP_DEBUG está activado)
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('[Woo Update API] Plugin uninstalled - Settings removed');
    }
}

// Ejecutar la desinstalación
woo_update_api_uninstall();