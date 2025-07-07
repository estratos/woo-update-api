<?php
namespace Woo_Update_API;

// includes/class-error-manager.php
class Error_Manager {
    const ERROR_THRESHOLD = 5;
    const TRANSIENT_KEY = 'woo_update_api_error_count';
    const FALLBACK_MODE_KEY = 'woo_update_api_fallback_mode';

    public function increment_error_count() {
        $count = $this->get_error_count();
        $count++;
        set_transient(self::TRANSIENT_KEY, $count, HOUR_IN_SECONDS);
        
        if ($count >= self::ERROR_THRESHOLD) {
            update_option(self::FALLBACK_MODE_KEY, 'yes');
        }
    }

    public function reset_error_count() {
        delete_transient(self::TRANSIENT_KEY);
        update_option(self::FALLBACK_MODE_KEY, 'no');
    }

    public function get_error_count() {
        return (int) get_transient(self::TRANSIENT_KEY) ?: 0;
    }

    public function is_fallback_mode() {
        return get_option(self::FALLBACK_MODE_KEY) === 'yes';
    }

    public function get_status() {
        return [
            'error_count' => $this->get_error_count(),
            'is_fallback' => $this->is_fallback_mode(),
            'threshold' => self::ERROR_THRESHOLD
        ];
    }
}