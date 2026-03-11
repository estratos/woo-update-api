<?php
class Woo_Update_API_Handler {

    private $settings;

    public function __construct($settings) {
        $this->settings = $settings;
    }

    /**
     * Obtener datos de producto con sistema de caché completo
     *
     * @param int $product_id ID del producto
     * @param string $sku SKU del producto
     * @param bool $skip_cache Si es true, omite caché y fuerza llamada API
     * @return array|false Datos del producto o false si hay error
     */
    public function get_product_data($product_id, $sku, $skip_cache = false) {
        // Validar configuración básica
        if (empty($sku)) {
            Woo_Update_API()->log("SKU vacío para producto ID: {$product_id}", 'api');
            return false;
        }

        // Verificar si el caché está habilitado en settings
        $cache_enabled = $this->settings->is_cache_enabled();
        
        // Intentar obtener del caché (si está habilitado y no se fuerza fresh)
        if ($cache_enabled && !$skip_cache) {
            $cached_data = Woo_Update_API_Cache::get($sku);
            if ($cached_data !== false) {
                Woo_Update_API()->log("Datos servidos desde caché para SKU: {$sku}", 'api');
                return $cached_data;
            }
        }

        // Si llegamos aquí, necesitamos llamar a la API
        Woo_Update_API()->log("Llamando a API para SKU: {$sku}" . ($skip_cache ? ' (forzado fresh)' : ''), 'api');
        
        $api_data = $this->get_product_data_direct($product_id, $sku);

        // Guardar en caché si la llamada fue exitosa y el caché está habilitado
        if ($api_data !== false && $cache_enabled) {
            $ttl = $this->settings->get_cache_ttl();
            Woo_Update_API_Cache::set($sku, $api_data, $ttl);
        }

        return $api_data;
    }

    /**
     * Consulta API directa sin caché
     */
    public function get_product_data_direct($product_id, $sku) {
        $api_url = $this->settings->get_api_url();
        $api_key = $this->settings->get_api_key();

        if (empty($api_url) || empty($api_key)) {
            Woo_Update_API()->log('API URL o API Key no configuradas', 'api');
            return false;
        }

        // Construir URL con parámetros
        $url = add_query_arg([
            'sku' => urlencode($sku),
            'api_key' => $api_key,
            'product_id' => $product_id
        ], $api_url);

        // Headers para evitar caché en el camino
        $args = [
            'timeout' => 10,
            'headers' => [
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'User-Agent' => 'Woo-Update-API/' . WOO_UPDATE_API_VERSION
            ]
        ];

        // Medir tiempo de respuesta
        $start_time = microtime(true);
        $response = wp_remote_get($url, $args);
        $response_time = round((microtime(true) - $start_time) * 1000, 2);

        if (is_wp_error($response)) {
            Woo_Update_API()->log('Error en API: ' . $response->get_error_message() . " (tiempo: {$response_time}ms)", 'api');
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        Woo_Update_API()->log("Respuesta API - Código: {$response_code}, Tiempo: {$response_time}ms", 'api');

        if ($response_code !== 200) {
            Woo_Update_API()->log("Error HTTP {$response_code} en API", 'api');
            return false;
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            Woo_Update_API()->log('Error decodificando JSON: ' . json_last_error_msg(), 'api');
            return false;
        }

        // Validar estructura de respuesta según tu API real
        if (!isset($data['success']) || $data['success'] !== true) {
            Woo_Update_API()->log('Respuesta API indica error o success=false', 'api');
            return false;
        }

        if (!isset($data['product']) || !is_array($data['product'])) {
            Woo_Update_API()->log('Respuesta API inválida: no contiene product', 'api');
            return false;
        }

        // Enriquecer datos con metadata de la respuesta
        $product_data = $data['product'];
        if (isset($data['meta']['timestamp'])) {
            $product_data['api_timestamp'] = $data['meta']['timestamp'];
        }
        $product_data['last_fetch'] = current_time('mysql');
        $product_data['response_time_ms'] = $response_time;

        Woo_Update_API()->log('Respuesta API exitosa para SKU: ' . $sku, 'api');
        
        return $product_data;
    }

    /**
     * Obtener datos de múltiples productos (batch)
     * Nota: Requiere soporte en la API externa
     *
     * @param array $products Array de [product_id => sku]
     * @return array Resultados indexados por SKU
     */
    public function get_products_data_batch($products) {
        $results = [];
        $skus_to_fetch = [];
        
        // Verificar caché primero para cada producto
        foreach ($products as $product_id => $sku) {
            $cached = Woo_Update_API_Cache::get($sku);
            if ($cached !== false) {
                $results[$sku] = $cached;
            } else {
                $skus_to_fetch[$product_id] = $sku;
            }
        }

        // Si hay productos para fetch en API
        if (!empty($skus_to_fetch) && $this->settings->is_batch_enabled()) {
            $batch_data = $this->get_products_data_direct_batch($skus_to_fetch);
            
            // Mezclar resultados y guardar en caché
            foreach ($batch_data as $sku => $data) {
                $results[$sku] = $data;
                if ($data !== false && $this->settings->is_cache_enabled()) {
                    Woo_Update_API_Cache::set($sku, $data, $this->settings->get_cache_ttl());
                }
            }
        }

        return $results;
    }

    /**
     * Llamada batch a API (implementar según especificaciones de tu API)
     */
    private function get_products_data_direct_batch($products) {
        // Esta función debería implementarse según la API específica
        // Por ahora retornamos array vacío
        Woo_Update_API()->log('Batch API no implementada', 'api');
        return [];
    }

    /**
     * Limpiar caché para un producto específico
     */
    public function clear_product_cache($sku) {
        return Woo_Update_API_Cache::delete($sku);
    }

    /**
     * Obtener timestamp de la última actualización
     */
    public function get_last_update_time($sku) {
        $data = Woo_Update_API_Cache::get($sku);
        if ($data && isset($data['last_fetch'])) {
            return $data['last_fetch'];
        }
        return false;
    }
}