<?php
class Woo_Update_API_Handler {

    private $settings;

    public function __construct() {
        $this->settings = new Woo_Update_API_Settings();
    }

    /**
     * Consulta API sin NINGÚN tipo de caché
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

        Woo_Update_API()->log("Consultando API: {$url}", 'api');

        // Realizar petición sin caché
        $args = [
            'timeout' => 10,
            'headers' => [
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ]
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            Woo_Update_API()->log('Error en API: ' . $response->get_error_message(), 'api');
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Woo_Update_API()->log('Error decodificando JSON: ' . json_last_error_msg(), 'api');
            return false;
        }

        // Validar estructura de respuesta
        if (!isset($data['product']) || !is_array($data['product'])) {
            Woo_Update_API()->log('Respuesta API inválida: estructura incorrecta', 'api');
            return false;
        }

        Woo_Update_API()->log('Respuesta API exitosa para SKU: ' . $sku, 'api');
        return $data['product'];
    }

    /**
     * Obtener datos de producto con caché en memoria (solo para la misma petición)
     */
    private $memory_cache = [];

    public function get_product_data_with_memory_cache($product_id, $sku) {
        $cache_key = $product_id . '_' . $sku;

        // Verificar si ya está en caché de memoria
        if (isset($this->memory_cache[$cache_key])) {
            Woo_Update_API()->log('Usando caché en memoria para SKU: ' . $sku, 'api');
            return $this->memory_cache[$cache_key];
        }

        // Consultar API directamente
        $data = $this->get_product_data_direct($product_id, $sku);

        if ($data !== false) {
            $this->memory_cache[$cache_key] = $data;
        }

        return $data;
    }

    /**
     * Limpiar caché en memoria para un producto específico
     */
    public function clear_memory_cache($product_id, $sku) {
        $cache_key = $product_id . '_' . $sku;
        if (isset($this->memory_cache[$cache_key])) {
            unset($this->memory_cache[$cache_key]);
            Woo_Update_API()->log('Caché en memoria limpiado para SKU: ' . $sku, 'api');
        }
    }
}