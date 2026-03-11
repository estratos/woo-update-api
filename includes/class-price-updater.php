<?php
class Woo_Update_API_Price_Updater {

    private $api_handler;
    private $processed_products = [];

    public function __construct($api_handler) {
        $this->api_handler = $api_handler;

        // Solo agregar hooks si no estamos en admin (excepto AJAX)
        if (!is_admin() || wp_doing_ajax()) {
            $this->add_filters();
        }
    }

    /**
     * Agregar todos los filtros de WooCommerce
     */
    private function add_filters() {
        $filters = [
            'woocommerce_product_get_price',
            'woocommerce_product_get_regular_price',
            'woocommerce_product_get_sale_price',
            'woocommerce_product_variation_get_price',
            'woocommerce_product_variation_get_regular_price',
            'woocommerce_product_variation_get_sale_price'
        ];

        foreach ($filters as $filter) {
            add_filter($filter, [$this, 'filter_price'], 10, 2);
        }

        add_filter('woocommerce_product_get_stock_quantity', [$this, 'filter_stock_quantity'], 10, 2);
        add_filter('woocommerce_product_variation_get_stock_quantity', [$this, 'filter_stock_quantity'], 10, 2);
        
        add_filter('woocommerce_product_get_stock_status', [$this, 'filter_stock_status'], 10, 2);
        add_filter('woocommerce_product_variation_get_stock_status', [$this, 'filter_stock_status'], 10, 2);
        
        // Filtro para disponibilidad
        add_filter('woocommerce_product_is_in_stock', [$this, 'filter_is_in_stock'], 10, 2);
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

        // Verificar si ya procesamos este producto
        if (isset($this->processed_products[$product_id])) {
            return $this->get_price_from_processed($product_id, $price, current_filter());
        }

        // Obtener datos de API (con caché automático)
        $api_data = $this->api_handler->get_product_data($product_id, $sku);

        if ($api_data) {
            $this->processed_products[$product_id] = $api_data;
            return $this->get_price_from_api($api_data, $price, current_filter());
        }

        return $price;
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

        // Forzar carga de datos
        $this->filter_price($quantity, $product);

        return $this->processed_products[$product_id]['stock_quantity'] ?? $quantity;
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

        // Forzar carga de datos
        $this->filter_price(0, $product);

        return $this->processed_products[$product_id]['stock_status'] ?? $status;
    }

    /**
     * Filtrar si el producto está en stock
     */
    public function filter_is_in_stock($in_stock, $product) {
        $status = $this->filter_stock_status($in_stock ? 'instock' : 'outofstock', $product);
        return $status === 'instock';
    }

    /**
     * Verificar si debemos actualizar el producto
     */
    private function should_update_product($product) {
        // Verificar si el producto existe y tiene SKU
        if (!$product || !$product instanceof WC_Product) {
            return false;
        }

        $sku = $product->get_sku();
        if (empty($sku)) {
            return false;
        }

        // Verificar si el plugin está activo para este tipo de producto
        $product_type = $product->get_type();
        $allowed_types = apply_filters('woo_update_api_allowed_product_types', ['simple', 'variation']);
        
        if (!in_array($product_type, $allowed_types)) {
            return false;
        }

        return true;
    }

    /**
     * Obtener precio correcto según el filtro actual
     */
    private function get_price_from_processed($product_id, $default_price, $current_filter) {
        $data = $this->processed_products[$product_id];
        
        switch ($current_filter) {
            case 'woocommerce_product_get_sale_price':
            case 'woocommerce_product_variation_get_sale_price':
                return $data['sale_price'] ?? $default_price;
            
            case 'woocommerce_product_get_regular_price':
            case 'woocommerce_product_variation_get_regular_price':
                return $data['regular_price'] ?? $default_price;
            
            default:
                return $data['price'] ?? $default_price;
        }
    }

    /**
     * Obtener precio de datos de API
     */
    private function get_price_from_api($api_data, $default_price, $current_filter) {
        switch ($current_filter) {
            case 'woocommerce_product_get_sale_price':
            case 'woocommerce_product_variation_get_sale_price':
                return $api_data['sale_price'] ?? $default_price;
            
            case 'woocommerce_product_get_regular_price':
            case 'woocommerce_product_variation_get_regular_price':
                return $api_data['regular_price'] ?? $default_price;
            
            default:
                return $api_data['price'] ?? $default_price;
        }
    }

    /**
     * Limpiar caché de producto
     */
    public function clear_product_cache($product_id) {
        if (isset($this->processed_products[$product_id])) {
            unset($this->processed_products[$product_id]);
        }
    }
}