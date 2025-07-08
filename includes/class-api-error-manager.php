<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class API_Error_Manager {
    const ERROR_THRESHOLD = 5;
    const TRANSIENT_KEY = 'wc_update_api_error_count';
    const FALLBACK_MODE_KEY = 'wc_update_api_fallback_mode';

    public function increment_error() {
        $count = $this->get_error_count() + 1;
        set_transient(self::TRANSIENT_KEY, $count, 12 * HOUR_IN_SECONDS);
        
        if ($count >= self::ERROR_THRESHOLD) {
            update_option(self::FALLBACK_MODE_KEY, 'yes');
        }
        return $count;
    }

    public function reset_errors() {
        delete_transient(self::TRANSIENT_KEY);
        update_option(self::FALLBACK_MODE_KEY, 'no');
    }

    public function get_error_count() {
        return (int) get_transient(self::TRANSIENT_KEY) ?: 0;
    }

    public function is_fallback_active() {
        return get_option(self::FALLBACK_MODE_KEY) === 'yes';
    }

    public function get_status() {
        return [
            'errors' => $this->get_error_count(),
            'threshold' => self::ERROR_THRESHOLD,
            'fallback_active' => $this->is_fallback_active()
        ];
    }
}