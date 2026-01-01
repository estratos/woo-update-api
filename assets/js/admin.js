jQuery(function($) {
    'use strict';
    
    const $document = $(document);
    let isProcessing = false;

    // Initialize everything
    function init() {
        setupStatusPolling();
        setupReconnectButton();
        setupRefreshButtons();
        setupStockValidation(); // NUEVO
    }

    // Status polling with proper error handling
    function setupStatusPolling() {
        updateStatus();
        setInterval(updateStatus, 30000);
    }

    function updateStatus() {
        if (isProcessing) return;
        
        isProcessing = true;
        const $statusContainer = $('#woo_update_api_status');
        const $spinner = $statusContainer.find('.spinner');
        
        $spinner.addClass('is-active');
        
        $.get(woo_update_api.ajaxurl, {
            action: 'woo_update_api_get_status',
            nonce: woo_update_api.nonce,
            _: Date.now() // Cache buster
        })
        .done(function(response) {
            if (response.success) {
                // Format status display
                const status = response.data;
                let statusClass = 'success';
                let statusIcon = 'yes';
                let statusText = '';
                
                if (status.fallback_mode) {
                    statusClass = 'warning';
                    statusIcon = 'warning';
                    statusText = '<strong>' + woo_update_api.i18n.fallback_active + '</strong><br>' +
                                 woo_update_api.i18n.errors + ': ' + status.error_count + '/' + status.error_threshold;
                } else if (status.connected) {
                    statusClass = 'success';
                    statusIcon = 'yes';
                    statusText = '<strong>' + woo_update_api.i18n.connected + '</strong><br>' +
                                 woo_update_api.i18n.recent_errors + ': ' + status.error_count + '/' + status.error_threshold;
                } else {
                    statusClass = 'error';
                    statusIcon = 'no';
                    statusText = '<strong>' + woo_update_api.i18n.disconnected + '</strong><br>' +
                                 woo_update_api.i18n.check_settings;
                }
                
                $statusContainer.html(
                    '<div class="notice notice-' + statusClass + ' inline" style="margin: 0; padding: 10px;">' +
                    '<p>' + statusText + '</p>' +
                    '</div>'
                );
            }
        })
        .fail(function(jqXHR) {
            console.error('Status update failed:', jqXHR.responseText);
            $statusContainer.html(
                '<div class="notice notice-error inline" style="margin: 0; padding: 10px;">' +
                '<p>' + woo_update_api.i18n.status_error + '</p>' +
                '</div>'
            );
        })
        .always(function() {
            $spinner.removeClass('is-active');
            isProcessing = false;
        });
    }

    // Reconnect button handler
    function setupReconnectButton() {
        $document.on('click', '#woo_update_api_reconnect', function(e) {
            e.preventDefault();
            if (isProcessing) return;
            
            isProcessing = true;
            const $button = $(this);
            const originalText = $button.text();
            
            $button.text(woo_update_api.i18n.connecting).prop('disabled', true);
            
            $.post(woo_update_api.ajaxurl, {
                action: 'woo_update_api_reconnect',
                nonce: woo_update_api.nonce
            })
            .done(function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    updateStatus();
                } else {
                    showNotice(response.data.message, 'error');
                }
            })
            .fail(function() {
                showNotice(woo_update_api.i18n.connection_failed, 'error');
            })
            .always(function() {
                $button.text(originalText).prop('disabled', false);
                isProcessing = false;
            });
        });
    }

    // NUEVO: Validación de stock en frontend
    function setupStockValidation() {
        // Solo en páginas de producto single
        if ($('body').hasClass('single-product')) {
            // Interceptar botón "Añadir al carrito"
            $document.on('click', '.single_add_to_cart_button', function(e) {
                e.preventDefault();
                
                if (isProcessing) return;
                
                const $button = $(this);
                const $form = $button.closest('form.cart');
                const product_id = $form.find('input[name="add-to-cart"]').val();
                const variation_id = $form.find('input[name="variation_id"]').val() || 0;
                const quantity = $form.find('input[name="quantity"]').val() || 1;
                
                isProcessing = true;
                $button.prop('disabled', true).addClass('loading');
                
                // Validar stock antes de añadir
                $.ajax({
                    url: woo_update_api.ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'woo_update_api_validate_stock',
                        product_id: product_id,
                        variation_id: variation_id,
                        quantity: quantity,
                        nonce: woo_update_api.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Stock disponible, proceder con añadir al carrito
                            $form.submit();
                        } else {
                            // Mostrar error de stock
                            showFrontendNotice(response.data.message, 'error');
                            $button.prop('disabled', false).removeClass('loading');
                            isProcessing = false;
                        }
                    },
                    error: function() {
                        showFrontendNotice(woo_update_api.i18n.request_failed, 'error');
                        $button.prop('disabled', false).removeClass('loading');
                        isProcessing = false;
                    }
                });
            });
            
            // Actualización periódica de stock en página
            setInterval(function() {
                const product_id = $('input[name="add-to-cart"]').val();
                if (product_id) {
                    checkStockUpdate(product_id);
                }
            }, 60000); // Cada minuto
        }
    }
    
    // Verificar actualizaciones de stock
    function checkStockUpdate(product_id) {
        $.get(woo_update_api.ajaxurl, {
            action: 'woo_update_api_get_status',
            product_id: product_id,
            nonce: woo_update_api.nonce,
            _: Date.now()
        })
        .done(function(response) {
            if (response.success && response.data.stock_changed) {
                // Actualizar display de stock sin recargar
                $('.stock.in-stock').text(response.data.stock_message);
                showFrontendNotice(
                    woo_update_api.i18n.stock_updated + ': ' + response.data.stock_message,
                    'success'
                );
            }
        });
    }
    
    // Mostrar notificaciones en frontend
    function showFrontendNotice(message, type) {
        // Remover notificaciones existentes
        $('.woo-update-api-frontend-notice').remove();
        
        const noticeClass = type === 'error' ? 'woocommerce-error' : 'woocommerce-message';
        const notice = $(
            '<div class="' + noticeClass + ' woo-update-api-frontend-notice">' +
            '<p>' + message + '</p>' +
            '</div>'
        );
        
        // Insertar antes del formulario
        $('form.cart').before(notice);
        
        // Auto-remover después de 5 segundos
        setTimeout(function() {
            notice.fadeOut(500, function() {
                $(this).remove();
            });
        }, 5000);
    }

    // Product refresh handler
    function setupRefreshButtons() {
        $document.on('click', '.refresh-api-data, .wc-update-api-refresh', function(e) {
            e.preventDefault();
            if (isProcessing) return;
            
            isProcessing = true;
            const $button = $(this);
            const product_id = $button.data('product-id');
            const $spinner = $button.find('.spinner');
            const $resultContainer = $button.siblings('.woo-update-api-result');
            
            $button.prop('disabled', true);
            $button.addClass('loading');
            $spinner.css('visibility', 'visible');
            
            // Determine which AJAX action to use based on button class
            const action = $button.hasClass('refresh-api-data') ? 
                'woo_update_api_refresh_product' : 'wc_update_api_manual_refresh';
            
            // Get nonce
            let nonce = $button.data('nonce');
            if (!nonce) {
                nonce = $button.hasClass('refresh-api-data') ? 
                    woo_update_api.nonce : wp_create_nonce('wc_update_api_refresh');
            }
            
            $.post(woo_update_api.ajaxurl, {
                action: action,
                nonce: nonce,
                product_id: product_id
            })
            .done(function(response) {
                if (response.success) {
                    updateProductDisplay(response.data, $button);
                    showNotice(response.data.message, 'success');
                    
                    // Update result container if it exists
                    if ($resultContainer.length) {
                        let resultHtml = '<div class="notice notice-success inline" style="margin-top: 10px;">' +
                                        '<p>' + response.data.message;
                        
                        if (response.data.last_refresh) {
                            resultHtml += '<br>' + woo_update_api.i18n.last_refresh + ': ' + response.data.last_refresh;
                        }
                        if (response.data.price) {
                            resultHtml += '<br>' + woo_update_api.i18n.price + ': ' + response.data.price;
                        }
                        if (response.data.stock) {
                            resultHtml += '<br>' + woo_update_api.i18n.stock + ': ' + response.data.stock;
                            if (response.data.stock_synced) {
                                resultHtml += ' ' + woo_update_api.i18n.stock_synced;
                            }
                        }
                        
                        resultHtml += '</p></div>';
                        $resultContainer.html(resultHtml);
                    }
                } else {
                    showNotice(response.data.message, 'error');
                    if ($resultContainer.length) {
                        $resultContainer.html(
                            '<div class="notice notice-error inline" style="margin-top: 10px;">' +
                            '<p>' + response.data.message + '</p>' +
                            '</div>'
                        );
                    }
                }
            })
            .fail(function(jqXHR) {
                const errorMsg = jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ?
                    jqXHR.responseJSON.data.message : woo_update_api.i18n.request_failed;
                showNotice(errorMsg, 'error');
                if ($resultContainer.length) {
                    $resultContainer.html(
                        '<div class="notice notice-error inline" style="margin-top: 10px;">' +
                        '<p>' + errorMsg + '</p>' +
                        '</div>'
                    );
                }
            })
            .always(function() {
                $button.prop('disabled', false);
                $button.removeClass('loading');
                $spinner.css('visibility', 'hidden');
                isProcessing = false;
            });
        });
    }

    // Update product display after refresh
    function updateProductDisplay(data, $button) {
        // Check which price field to update based on button location
        if (data.price_raw) {
            const $regularPrice = $('input[name="_regular_price"], #_regular_price');
            const $salePrice = $('input[name="_sale_price"], #_sale_price');
            
            if ($regularPrice.length) {
                $regularPrice.val(data.price_raw).trigger('change');
            }
            
            if (data.sale_price_raw && $salePrice.length) {
                $salePrice.val(data.sale_price_raw).trigger('change');
            }
        }
        
        if (data.stock) {
            const $stockField = $('input[name="_stock"], #_stock');
            const $manageStock = $('input[name="_manage_stock"], #_manage_stock');
            
            if ($stockField.length) {
                $stockField.val(data.stock).trigger('change');
            }
            
            if ($manageStock.length && !$manageStock.prop('checked')) {
                $manageStock.prop('checked', true).trigger('change');
            }
        }
    }

    // Show admin notice
    function showNotice(message, type = 'success') {
        $('.notice.woo-update-api-notice').remove();
        
        const notice = $('<div class="notice notice-' + type + ' is-dismissible woo-update-api-notice"><p>' + message + '</p></div>');
        
        const $target = $('#wpbody-content .wrap h1:first').length ? 
            $('#wpbody-content .wrap h1:first') : $('.wrap h1:first');
        
        if ($target.length) {
            $target.after(notice);
        } else {
            $('.wrap').prepend(notice);
        }
        
        notice.find('.notice-dismiss').on('click', function() {
            notice.fadeOut(300, function() {
                $(this).remove();
            });
        });
        
        if (type === 'success') {
            setTimeout(() => {
                if (notice.is(':visible')) {
                    notice.fadeOut(500, function() {
                        $(this).remove();
                    });
                }
            }, 5000);
        }
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        init();
        
        // Add additional localization strings
        if (typeof woo_update_api !== 'undefined' && !woo_update_api.i18n) {
            woo_update_api.i18n = {};
        }
        
        const defaultI18n = {
            connecting: 'Connecting...',
            connected: 'Connected!',
            disconnected: 'Disconnected',
            fallback_active: 'Fallback Mode Active',
            recent_errors: 'Recent errors',
            errors: 'Errors',
            check_settings: 'Please check your API settings',
            status_error: 'Could not load status',
            connection_failed: 'API connection failed',
            request_failed: 'Request failed. Please try again.',
            refreshing: 'Refreshing...',
            success: 'Success!',
            error: 'Error',
            price: 'Price',
            stock: 'Stock',
            last_refresh: 'Last refresh',
            validating_stock: 'Validating stock...',
            insufficient_stock: 'Insufficient stock',
            stock_updated: 'Stock updated',
            stock_synced: '(synced with database)'
        };
        
        for (const key in defaultI18n) {
            if (!woo_update_api.i18n[key]) {
                woo_update_api.i18n[key] = defaultI18n[key];
            }
        }
    });
});