<?php
/**
 * Sistema de caché de tres niveles para Woo Update API
 * - Nivel 1: Memoria (petición actual)
 * - Nivel 2: Transients (persistente)
 * - Nivel 3: API (fuente de verdad)
 */
class Woo_Update_API_Cache {

    /**
     * Caché en memoria para la petición actual
     * @var array
     */
    private static $memory_cache = [];

    /**
     * Estadísticas de caché para debugging
     * @var array
     */
    private static $stats = [
        'hits_memory' => 0,
        'hits_transient' => 0,
        'misses' => 0,
        'sets' => 0
    ];

    /**
     * Obtener datos del caché
     *
     * @param string $sku SKU del producto
     * @param bool $force_fresh Si es true, omite el caché
     * @return mixed|false Datos del producto o false si no existe
     */
    public static function get($sku, $force_fresh = false) {
        if (empty($sku)) {
            return false;
        }

        // Si se fuerza fresh, omitir todo caché
        if ($force_fresh) {
            self::$stats['misses']++;
            return false;
        }

        // Nivel 1: Caché en memoria
        if (isset(self::$memory_cache[$sku])) {
            self::$stats['hits_memory']++;
            Woo_Update_API()->log("Cache HIT (memoria) para SKU: {$sku}", 'cache');
            return self::$memory_cache[$sku];
        }

        // Nivel 2: Caché persistente (transients)
        $transient_key = self::make_transient_key($sku);
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            self::$stats['hits_transient']++;
            Woo_Update_API()->log("Cache HIT (transient) para SKU: {$sku}", 'cache');
            
            // Promover a caché en memoria
            self::$memory_cache[$sku] = $cached;
            return $cached;
        }

        // Miss en todos los niveles
        self::$stats['misses']++;
        Woo_Update_API()->log("Cache MISS para SKU: {$sku}", 'cache');
        return false;
    }

    /**
     * Guardar datos en caché
     *
     * @param string $sku SKU del producto
     * @param mixed $data Datos a guardar
     * @param int $ttl Tiempo de vida en segundos
     * @return bool True si se guardó correctamente
     */
    public static function set($sku, $data, $ttl) {
        if (empty($sku) || empty($data)) {
            return false;
        }

        self::$stats['sets']++;
        
        // Guardar en memoria
        self::$memory_cache[$sku] = $data;

        // Guardar en transient si TTL > 0
        if ($ttl > 0) {
            $transient_key = self::make_transient_key($sku);
            $result = set_transient($transient_key, $data, $ttl);
            
            Woo_Update_API()->log("Cache SET para SKU: {$sku} (TTL: {$ttl}s)", 'cache');
            return $result;
        }

        return true;
    }

    /**
     * Limpiar caché para un SKU específico
     *
     * @param string $sku SKU del producto
     * @return bool True si se limpió correctamente
     */
    public static function delete($sku) {
        if (empty($sku)) {
            return false;
        }

        // Limpiar memoria
        if (isset(self::$memory_cache[$sku])) {
            unset(self::$memory_cache[$sku]);
        }

        // Limpiar transient
        $transient_key = self::make_transient_key($sku);
        $result = delete_transient($transient_key);

        Woo_Update_API()->log("Cache DELETE para SKU: {$sku}", 'cache');
        return $result;
    }

    /**
     * Limpiar todo el caché
     *
     * @return bool True si se limpió correctamente
     */
    public static function clear_all() {
        // Limpiar memoria
        self::$memory_cache = [];

        // Nota: No podemos limpiar todos los transients fácilmente
        // Esta función debería implementarse con un patrón de cache tags
        // o almacenar todas las keys en una opción
        
        Woo_Update_API()->log("Cache CLEAR ALL", 'cache');
        return true;
    }

    /**
     * Obtener estadísticas de caché
     *
     * @return array Estadísticas
     */
    public static function get_stats() {
        $total_requests = self::$stats['hits_memory'] + 
                         self::$stats['hits_transient'] + 
                         self::$stats['misses'];
        
        $hit_rate = $total_requests > 0 
            ? round((self::$stats['hits_memory'] + self::$stats['hits_transient']) / $total_requests * 100, 2)
            : 0;

        return [
            'hits_memory' => self::$stats['hits_memory'],
            'hits_transient' => self::$stats['hits_transient'],
            'total_hits' => self::$stats['hits_memory'] + self::$stats['hits_transient'],
            'misses' => self::$stats['misses'],
            'total_requests' => $total_requests,
            'hit_rate' => $hit_rate . '%',
            'sets' => self::$stats['sets'],
            'memory_cache_size' => count(self::$memory_cache)
        ];
    }

    /**
     * Resetear estadísticas
     */
    public static function reset_stats() {
        self::$stats = [
            'hits_memory' => 0,
            'hits_transient' => 0,
            'misses' => 0,
            'sets' => 0
        ];
    }

    /**
     * Generar key para transient
     *
     * @param string $sku SKU del producto
     * @return string Key para transient
     */
    private static function make_transient_key($sku) {
        return 'woo_update_api_' . md5(sanitize_key($sku));
    }
}