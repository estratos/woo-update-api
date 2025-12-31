jQuery(function($) {
    'use strict';
    
    const $document = $(document);
    let isProcessing = false;

    // Initialize everything
    function init() {
        setupStatusPolling();
        setupReconnectButton();
        setupRefreshButtons();
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
            
            // Get nonce (either from button data or create new)
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
            // Try to update WooCommerce price fields
            const $regularPrice = $('input[name="_regular_price"], #_regular_price');
            const $salePrice = $('input[name="_sale_price"], #_sale_price');
            
            if ($regularPrice.length) {
                $regularPrice.val(data.price_raw).trigger('change');
            }
            
            // Only update sale price if it's empty or we have specific sale price data
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
            
            // Ensure manage stock is checked
            if ($manageStock.length && !$manageStock.prop('checked')) {
                $manageStock.prop('checked', true).trigger('change');
            }
        }
    }

    // Show admin notice
    function showNotice(message, type = 'success') {
        // Remove any existing notices of the same type
        $('.notice.woo-update-api-notice').remove();
        
        const notice = $('<div class="notice notice-' + type + ' is-dismissible woo-update-api-notice"><p>' + message + '</p></div>');
        
        // Insert after first h1 in wpbody-content or directly in admin notices area
        const $target = $('#wpbody-content .wrap h1:first').length ? 
            $('#wpbody-content .wrap h1:first') : $('.wrap h1:first');
        
        if ($target.length) {
            $target.after(notice);
        } else {
            $('.wrap').prepend(notice);
        }
        
        // Add dismiss functionality
        notice.find('.notice-dismiss').on('click', function() {
            notice.fadeOut(300, function() {
                $(this).remove();
            });
        });
        
        // Auto-dismiss success notices after 5 seconds
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
        
        // Ensure all i18n strings exist
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
            last_refresh: 'Last refresh'
        };
        
        for (const key in defaultI18n) {
            if (!woo_update_api.i18n[key]) {
                woo_update_api.i18n[key] = defaultI18n[key];
            }
        }
    });
});