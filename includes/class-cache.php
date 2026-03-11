<?php
/**
 * Cache layer for Woo Update API plugin.
 * Uses WordPress transients to store API responses per SKU.
 */
class Woo_Update_API_Cache {
    /**
     * Retrieve cached data for a SKU.
     * @param string $sku
     * @return mixed|false Cached data or false if not found.
     */
    public static function get($sku) {
        $transient_key = self::make_key($sku);
        return get_transient($transient_key);
    }

    /**
     * Store data in cache for a SKU.
     * @param string $sku
     * @param mixed $data
     * @param int $ttl Seconds to live.
     * @return bool
     */
    public static function set($sku, $data, $ttl) {
        $transient_key = self::make_key($sku);
        return set_transient($transient_key, $data, $ttl);
    }

    /**
     * Delete cache for a SKU.
     * @param string $sku
     * @return bool
     */
    public static function delete($sku) {
        $transient_key = self::make_key($sku);
        return delete_transient($transient_key);
    }

    private static function make_key($sku) {
        return 'woo_update_api_' . sanitize_key($sku);
    }
}
?>
