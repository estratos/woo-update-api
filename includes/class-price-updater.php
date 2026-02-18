<?php
class Woo_Update_API_Price_Updater {

    private $api_handler;
    private $processed_products = []; // Caché en memoria PHP

    public function __construct($api_handler) {
        $this->api_handler = $api_handler;

        // Hooks para filtrar precios en frontend
        add_filter('woocommerce_product_get_price', [$this, 'filter_price'], 10, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'filter_regular_price'], 10, 2);
        add_filter('woocommerce_product_get_stock_quantity', [$this, 'filter_stock_quantity'], 10, 2);
        add_filter('woocommerce_product_get_stock_status', [$this, 'filter_stock_status'], 10, 2);
        
        // Para productos variables
        add_filter('woocommerce_product_variation_get_price', [$this, 'filter_price'], 10, 2);
        add_filter('woocommerce_product_variation_get_regular_price', [$this, 'filter_regular_price'], 10, 2);
        add_filter('woocommerce_product_variation_get_stock_quantity', [$this, 'filter_stock_quantity'], 10, 2);
        add_filter('woocommerce_product_variation_get_stock_status', [$this, 'filter_stock_status'], 10, 2);
    }

    /**
     * Filtrar precio del producto
     */
    public function filter_price($price, $product) {
        if (!$this->should_update_product($product)) {
            return $price;
        }

        $product_id = $product->get_id();
        $sku = $product->get_sku();

        // Verificar si ya procesamos este producto en esta petición
        if (isset($this->processed_products[$product_id])) {
            Woo_Update_API()->log('Usando precio cacheado en memoria para producto ID: ' . $product_id, 'price');
            return $this->processed_products[$product_id]['price'] ?? $price;
        }

        // Obtener datos de API con caché en memoria
        $api_data = $this->api_handler->get_product_data_with_memory_cache($product_id, $sku);

        if ($api_data && isset($api_data['price'])) {
            // Guardar en caché de memoria
            $this->processed_products[$product_id] = [
                'price' => $api_data['price'],
                'regular_price' => $api_data['regular_price'] ?? $api_data['price'],
                'stock_quantity' => $api_data['stock_quantity'] ?? null,
                'stock_status' => $api_data['stock_status'] ?? ($api_data['in_stock'] ? 'instock' : 'outofstock')
            ];

            Woo_Update_API()->log('Precio actualizado desde API para producto ID: ' . $product_id . ' - Nuevo precio: ' . $api_data['price'], 'price');
            return $api_data['price'];
        }

        return $price;
    }

    /**
     * Filtrar precio regular
     */
    public function filter_regular_price($price, $product) {
        if (!$this->should_update_product($product)) {
            return $price;
        }

        $product_id = $product->get_id();

        if (isset($this->processed_products[$product_id])) {
            return $this->processed_products[$product_id]['regular_price'] ?? $price;
        }

        // Forzar actualización llamando a filter_price primero
        $this->filter_price($price, $product);

        return isset($this->processed_products[$product_id]['regular_price']) 
            ? $this->processed_products[$product_id]['regular_price'] 
            : $price;
    }

    /**
     * Filtrar cantidad de stock
     */
    public function filter_stock_quantity($quantity, $product) {
        if (!$this->should_update_product($product)) {
            return $quantity;
        }

        $product_id = $product->get_id();

        if (isset($this->processed_products[$product_id])) {
            return $this->processed_products[$product_id]['stock_quantity'] ?? $quantity;
        }

        // Forzar actualización
        $this->filter_price($quantity, $product);

        return isset($this->processed_products[$product_id]['stock_quantity']) 
            ? $this->processed_products[$product_id]['stock_quantity'] 
            : $quantity;
    }

    /**
     * Filtrar estado de stock
     */
    public function filter_stock_status($status, $product) {
        if (!$this->should_update_product($product)) {
            return $status;
        }

        $product_id = $product->get_id();

        if (isset($this->processed_products[$product_id])) {
            return $this->processed_products[$product_id]['stock_status'] ?? $status;
        }

        // Forzar actualización
        $this->filter_price(0, $product);

        return isset($this->processed_products[$product_id]['stock_status']) 
            ? $this->processed_products[$product_id]['stock_status'] 
            : $status;
    }

    /**
     * Verificar si debemos actualizar el producto
     */
    private function should_update_product($product) {
        // Solo actualizar en frontend y para productos simples/variaciones
        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }

        if (!$product || !$product->get_sku()) {
            return false;
        }

        return true;
    }

    /**
     * Limpiar caché en memoria para un producto
     */
    public function clear_product_cache($product_id) {
        if (isset($this->processed_products[$product_id])) {
            unset($this->processed_products[$product_id]);
            Woo_Update_API()->log('Caché de precio limpiado para producto ID: ' . $product_id, 'price');
        }
    }
}