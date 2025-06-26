<?php
namespace Woo_Update_API;

defined('ABSPATH') || exit;

class Price_Updater {
    private static $instance = null;
    private $api_handler;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_handler = API_Handler::instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        // Price hooks
        add_filter('woocommerce_product_get_price', [$this, 'update_price'], 99, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'update_price'], 99, 2);
        add_filter('woocommerce_product_get_sale_price', [$this, 'update_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'update_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_regular_price', [$this, 'update_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_sale_price', [$this, 'update_price'], 99, 2);
        
        // Stock hooks
        add_filter('woocommerce_product_get_stock_quantity', [$this, 'update_stock'], 99, 2);
        add_filter('woocommerce_variation_get_stock_quantity', [$this, 'update_stock'], 99, 2);
        add_filter('woocommerce_product_is_in_stock', [$this, 'update_stock_status'], 99, 2);
    }

    public function update_price($price, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }

        $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());

        if ($api_data && isset($api_data['price']) && is_numeric($api_data['price'])) {
            return (float) $api_data['price'];
        }

        return $price;
    }

    public function update_stock($quantity, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $quantity;
        }

        $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());

        if ($api_data && isset($api_data['stock_quantity']) && is_numeric($api_data['stock_quantity'])) {
            return (int) $api_data['stock_quantity'];
        }

        return $quantity;
    }

    public function update_stock_status($in_stock, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $in_stock;
        }

        $api_data = $this->api_handler->get_product_data($product->get_id(), $product->get_sku());

        if ($api_data && isset($api_data['in_stock'])) {
            return (bool) $api_data['in_stock'];
        }

        return $in_stock;
    }
}