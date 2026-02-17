<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class API_Error_Manager
{
    private static $instance = null;
    const ERROR_THRESHOLD = 10;
    const TRANSIENT_KEY = 'woo_update_api_error_count';
    const FALLBACK_MODE_KEY = 'woo_update_api_fallback_active';

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function increment_error()
    {
        $count = $this->get_error_count() + 1;
        set_transient(self::TRANSIENT_KEY, $count, 12 * HOUR_IN_SECONDS);
        
        if ($count >= self::ERROR_THRESHOLD) {
            $this->activate_fallback_mode();
        }
        
        return $count;
    }

    public function reset_errors()
    {
        delete_transient(self::TRANSIENT_KEY);
        $this->deactivate_fallback_mode();
        return true;
    }

    public function get_error_count()
    {
        $count = get_transient(self::TRANSIENT_KEY);
        return $count ? (int) $count : 0;
    }

    public function is_fallback_active()
    {
        $fallback = get_transient(self::FALLBACK_MODE_KEY);
        return $fallback ? true : false;
    }

    private function activate_fallback_mode()
    {
        set_transient(self::FALLBACK_MODE_KEY, true, HOUR_IN_SECONDS);
    }

    private function deactivate_fallback_mode()
    {
        delete_transient(self::FALLBACK_MODE_KEY);
    }
}