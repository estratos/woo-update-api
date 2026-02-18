<?php
class Woo_Update_API_Stock_Synchronizer {

    private $api_handler;

    public function __construct($api_handler) {
        $this->api_handler = $api_handler;
    }

    /**
     * Actualizar producto al agregar al carrito
     */
    public function update_on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return;
        }

        $sku = $product->get_sku();
        
        Woo_Update_API()->log('Producto agregado al carrito - ID: ' . $product_id . ', SKU: ' . $sku, 'cart');

        // Limpiar caché en memoria antes de consultar
        $this->api_handler->clear_memory_cache($product_id, $sku);
        
        // Consultar API directamente (sin caché)
        $api_data = $this->api_handler->get_product_data_direct($product_id, $sku);

        if ($api_data === false) {
            Woo_Update_API()->log('No se pudo obtener datos de API para producto ID: ' . $product_id, 'cart');
            return;
        }

        // Actualizar en base de datos
        $this->update_product_in_database($product, $api_data);
        
        // Limpiar todos los caches de WooCommerce
        $this->clear_all_woocommerce_caches($product_id);
        
        Woo_Update_API()->log('Producto actualizado en BD desde carrito - ID: ' . $product_id, 'cart');
    }

    /**
     * Validar stock antes del checkout
     */
    public function validate_checkout_stock($data, $errors) {
        Woo_Update_API()->log('Validando stock en checkout', 'validate');

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_id = $product->get_id();
            $sku = $product->get_sku();
            $cart_quantity = $cart_item['quantity'];

            // Limpiar caché y consultar API directamente
            $this->api_handler->clear_memory_cache($product_id, $sku);
            $api_data = $this->api_handler->get_product_data_direct($product_id, $sku);

            if ($api_data === false) {
                Woo_Update_API()->log('No se pudo validar stock para producto ID: ' . $product_id, 'validate');
                continue;
            }

            // Verificar stock
            $api_stock = intval($api_data['stock_quantity'] ?? 0);
            $in_stock = $api_data['in_stock'] ?? ($api_stock > 0);

            if (!$in_stock || $api_stock < $cart_quantity) {
                $error_message = sprintf(
                    __('Lo sentimos, "%s" no tiene suficiente stock disponible. Stock actual: %d', 'woo-update-api'),
                    $product->get_name(),
                    $api_stock
                );
                $errors->add('out-of-stock', $error_message);
                
                Woo_Update_API()->log('Stock insuficiente para producto: ' . $product->get_name() . 
                                     ' - Solicitado: ' . $cart_quantity . 
                                     ', Disponible: ' . $api_stock, 'validate');
                
                // Actualizar cantidad en carrito
                if ($api_stock > 0) {
                    WC()->cart->set_quantity($cart_item_key, $api_stock);
                } else {
                    WC()->cart->remove_cart_item($cart_item_key);
                }
            } else {
                // Actualizar BD con datos actuales
                $this->update_product_in_database($product, $api_data);
                Woo_Update_API()->log('Stock validado correctamente para producto: ' . $product->get_name(), 'validate');
            }
        }
    }

    /**
     * Actualizar stock después de la compra
     */
    public function update_stock_after_purchase($order_id, $posted_data, $order) {
        Woo_Update_API()->log('Procesando actualización post-compra para orden: ' . $order_id, 'cart');

        $order = wc_get_order($order_id);
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            if (!$product) {
                continue;
            }

            $product_id = $product->get_id();
            $sku = $product->get_sku();

            // Limpiar caché y consultar API
            $this->api_handler->clear_memory_cache($product_id, $sku);
            $api_data = $this->api_handler->get_product_data_direct($product_id, $sku);

            if ($api_data === false) {
                Woo_Update_API()->log('No se pudo actualizar stock post-compra para producto ID: ' . $product_id, 'cart');
                continue;
            }

            // Actualizar en base de datos
            $this->update_product_in_database($product, $api_data);
            
            // Limpiar caches
            $this->clear_all_woocommerce_caches($product_id);
            
            Woo_Update_API()->log('Stock post-compra actualizado para producto: ' . $product->get_name(), 'cart');
        }
    }

    /**
     * Actualizar producto en base de datos
     */
    private function update_product_in_database($product, $api_data) {
        $product_id = $product->get_id();

        // Actualizar precios
        if (isset($api_data['price'])) {
            update_post_meta($product_id, '_price', $api_data['price']);
            update_post_meta($product_id, '_regular_price', $api_data['regular_price'] ?? $api_data['price']);
            
            if (isset($api_data['sale_price']) && $api_data['sale_price'] < $api_data['regular_price']) {
                update_post_meta($product_id, '_sale_price', $api_data['sale_price']);
            }
        }

        // Actualizar stock
        if (isset($api_data['stock_quantity'])) {
            wc_update_product_stock($product, $api_data['stock_quantity']);
        }

        // Actualizar estado de stock
        if (isset($api_data['stock_status'])) {
            update_post_meta($product_id, '_stock_status', $api_data['stock_status']);
        } elseif (isset($api_data['in_stock'])) {
            $stock_status = $api_data['in_stock'] ? 'instock' : 'outofstock';
            update_post_meta($product_id, '_stock_status', $stock_status);
        }

        // Actualizar timestamp de última sincronización
        update_post_meta($product_id, '_last_api_sync', current_time('mysql'));

        // Actualizar nombre si es diferente
        if (isset($api_data['name']) && $product->get_name() !== $api_data['name']) {
            wp_update_post([
                'ID' => $product_id,
                'post_title' => $api_data['name']
            ]);
        }

        Woo_Update_API()->log('Producto actualizado en BD - ID: ' . $product_id, 'cart');
    }

    /**
     * Limpiar todos los caches de WooCommerce
     */
    private function clear_all_woocommerce_caches($product_id) {
        // Limpiar transients del producto
        wc_delete_product_transients($product_id);

        // Actualizar tabla de meta lookup
        if (function_exists('wc_update_product_lookup_tables_is_running')) {
            $data_store = WC_Data_Store::load('product');
            if (method_exists($data_store, 'update_lookup_table')) {
                $data_store->update_lookup_table($product_id, 'wc_product_meta_lookup');
            }
        }

        // Limpiar caché de WordPress
        clean_post_cache($product_id);

        // Limpiar caché de memoria del price updater
        if (isset(Woo_Update_API()->price_updater)) {
            Woo_Update_API()->price_updater->clear_product_cache($product_id);
        }

        Woo_Update_API()->log('Caches limpiados para producto ID: ' . $product_id, 'cart');
    }
}