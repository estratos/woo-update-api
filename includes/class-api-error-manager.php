<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class API_Error_Manager
{
    private static $instance = null;
    
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * VERSIÓN SIMPLIFICADA - SIN MODO FALLBACK
     */
    public function increment_error()
    {
        // Ya no hace nada
        return 0;
    }

    public function reset_errors()
    {
        return true;
    }

    public function get_error_count()
    {
        return 0; // Siempre 0 errores
    }

    public function is_fallback_active()
    {
        return false; // NUNCA en modo fallback
    }
}