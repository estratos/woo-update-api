<?php
if (!defined('ABSPATH')) exit;

class WOO_Update_API_Price_Updater {
    private static $instance = null;
    private $api_handler;

    public static function init() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_handler = WOO_Update_API_Handler::init();

        // Hook into product display to update price and stock
        add_filter('woocommerce_product_get_price', [$this, 'update_product_price'], 10, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'update_product_price'], 10, 2);
        add_filter('woocommerce_product_get_sale_price', [$this, 'update_product_price'], 10, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'update_product_price'], 10, 2);
        add_filter('woocommerce_product_variation_get_regular_price', [$this, 'update_product_price'], 10, 2);
        add_filter('woocommerce_product_variation_get_sale_price', [$this, 'update_product_price'], 10, 2);
        
        // Update stock
        add_filter('woocommerce_product_get_stock_quantity', [$this, 'update_product_stock'], 10, 2);
        add_filter('woocommerce_variation_get_stock_quantity', [$this, 'update_product_stock'], 10, 2);
        add_filter('woocommerce_product_is_in_stock', [$this, 'update_product_stock_status'], 10, 2);
    }

    public function update_product_price($price, $product) {
        // Only update if we're on the frontend
        if (is_admin() && !defined('DOING_AJAX')) {
            return $price;
        }

        $product_id = $product->get_id();
        $sku = $product->get_sku();

        $api_data = $this->api_handler->get_product_data($product_id, $sku);

        if ($api_data && isset($api_data['price'])) {
            return floatval($api_data['price']);
        }

        return $price;
    }

    public function update_product_stock($stock, $product) {
        // Only update if we're on the frontend
        if (is_admin() && !defined('DOING_AJAX')) {
            return $stock;
        }

        $product_id = $product->get_id();
        $sku = $product->get_sku();

        $api_data = $this->api_handler->get_product_data($product_id, $sku);

        if ($api_data && isset($api_data['stock_quantity'])) {
            return intval($api_data['stock_quantity']);
        }

        return $stock;
    }

    public function update_product_stock_status($in_stock, $product) {
        // Only update if we're on the frontend
        if (is_admin() && !defined('DOING_AJAX')) {
            return $in_stock;
        }

        $product_id = $product->get_id();
        $sku = $product->get_sku();

        $api_data = $this->api_handler->get_product_data($product_id, $sku);

        if ($api_data && isset($api_data['in_stock'])) {
            return (bool) $api_data['in_stock'];
        }

        return $in_stock;
    }
}
