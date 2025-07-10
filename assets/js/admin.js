/**
 * WooCommerce Update API - Admin JavaScript
 * Combines existing functionality with new product refresh features
 * @version 1.1.2
 */
(function($) {
    'use strict';

    // DOM Elements cache
    const elements = {
        reconnectBtn: $('#woo_update_api_reconnect'),
        statusContainer: $('#woo_update_api_status'),
        disableFallback: $('input[name="woo_update_api_settings[disable_fallback]"]'),
        refreshButtons: $('.refresh-api-data')
    };

    // Initialize all functionality
    function init() {
        setupReconnectButton();
        setupStatusPolling();
        setupFallbackToggle();
        setupRefreshButtons();
        setupAdminNotices();
    }

    /**
     * Setup the API reconnect button
     */
    function setupReconnectButton() {
        elements.reconnectBtn.on('click', function(e) {
            e.preventDefault();
            setButtonState('loading');
            
            $.ajax({
                url: woo_update_api.ajaxurl,
                type: 'POST',
                data: {
                    action: 'woo_update_api_reconnect',
                    security: woo_update_api.nonce
                },
                dataType: 'json'
            })
            .done(handleReconnectResponse)
            .fail(handleReconnectError)
            .always(resetButtonState);
        });
    }

    /**
     * Setup product refresh buttons
     */
    function setupRefreshButtons() {
        $(document).on('click', '.refresh-api-data', function(e) {
            e.preventDefault();
            const $button = $(this);
            const product_id = $button.data('product-id');
            const $spinner = $button.next('.spinner');
            
            $button.prop('disabled', true);
            $spinner.addClass('is-active').css('visibility', 'visible');
            
            $.ajax({
                url: woo_update_api.ajaxurl,
                type: 'POST',
                data: {
                    action: 'woo_update_api_refresh_product',
                    security: woo_update_api.nonce,
                    product_id: product_id
                },
                dataType: 'json'
            })
            .done(function(response) {
                if (response.success) {
                    updateProductDisplay(response.data);
                    showNotice(response.data.message, 'success');
                } else {
                    showNotice(response.data.message, 'error');
                }
            })
            .fail(function(xhr) {
                showNotice(woo_update_api.i18n.request_failed, 'error');
                if (woo_update_api.debug) {
                    console.error('Refresh Error:', xhr.responseText);
                }
            })
            .always(function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active').css('visibility', 'hidden');
            });
        });
    }

    /**
     * Update product display after refresh
     */
    function updateProductDisplay(data) {
        // Update price display
        $('.product_price .amount').text(data.price);
        
        // Update stock field
        $('._stock_field input').val(data.stock);
        
        // Update any other fields as needed
        if (data.sku) {
            $('._sku_field input').val(data.sku);
        }
    }

    /**
     * Setup periodic status updates
     */
    function setupStatusPolling() {
        updateStatusDisplay();
        setInterval(updateStatusDisplay, 30000);
    }

    /**
     * Setup fallback toggle handler
     */
    function setupFallbackToggle() {
        elements.disableFallback.on('change', function() {
            const $spinner = $(this).next('.spinner');
            $spinner.addClass('is-active');
            
            $.post(woo_update_api.ajaxurl, {
                action: 'woo_update_api_toggle_fallback',
                security: woo_update_api.nonce,
                state: this.checked ? 'yes' : 'no'
            })
            .done(function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    updateStatusDisplay();
                }
            })
            .always(function() {
                $spinner.removeClass('is-active');
            });
        });
    }

    /**
     * Setup admin notice system
     */
    function setupAdminNotices() {
        // Handle existing notices on page load
        $('.notice').not('.woo-update-api-notice').on('click', '.notice-dismiss', function() {
            $(this).parent().remove();
        });
    }

    /**
     * Handle reconnect response
     */
    function handleReconnectResponse(response) {
        if (response.success) {
            showNotice(response.data.message, 'success');
            updateStatusDisplay();
        } else {
            showNotice(response.data.message, 'error');
        }
    }

    /**
     * Handle reconnect error
     */
    function handleReconnectError(xhr) {
        const errorMessage = woo_update_api.i18n.connection_failed + ': ' + xhr.statusText;
        showNotice(errorMessage, 'error');
        if (woo_update_api.debug) {
            console.error('Reconnect Error:', xhr.responseText);
        }
    }

    /**
     * Update API status display
     */
    function updateStatusDisplay() {
        $.get(woo_update_api.ajaxurl, {
            action: 'woo_update_api_get_status',
            security: woo_update_api.nonce
        })
        .done(function(response) {
            if (response.success) {
                elements.statusContainer.html(response.data.status_html);
            }
        })
        .fail(function() {
            elements.statusContainer.html(`
                <div class="notice notice-error inline">
                    <p>${woo_update_api.i18n.status_error}</p>
                </div>
            `);
        });
    }

    /**
     * Set button visual state
     */
    function setButtonState(state, $button = elements.reconnectBtn) {
        $button
            .removeClass('button-primary button-error')
            .prop('disabled', state === 'loading');

        switch(state) {
            case 'loading':
                $button.text(woo_update_api.i18n.connecting);
                break;
            case 'success':
                $button.addClass('button-primary')
                      .text(woo_update_api.i18n.connected);
                break;
            case 'error':
                $button.addClass('button-error')
                      .text(woo_update_api.i18n.failed);
                break;
            default:
                $button.addClass('button-secondary')
                      .text($button.data('original-text') || woo_update_api.i18n.reconnect);
        }
    }

    /**
     * Reset button to default state
     */
    function resetButtonState() {
        setTimeout(() => {
            setButtonState('default');
        }, 1500);
    }

    /**
     * Show admin notice
     */
    function showNotice(message, type = 'success') {
        // Remove existing notices
        $('.woo-update-api-notice').remove();
        
        // Create new notice
        const notice = $(`
            <div class="notice notice-${type} woo-update-api-notice is-dismissible">
                <p>${message}</p>
            </div>
        `).hide();
        
        // Insert and animate
        $('.wrap > h1').first().after(notice.fadeIn());
        
        // Make dismissible
        notice.on('click', '.notice-dismiss', function() {
            $(this).parent().fadeOut();
        });
        
        // Auto-dismiss success notices
        if (type !== 'error') {
            setTimeout(() => notice.fadeOut(), 5000);
        }
    }

    // Initialize when ready
    $(document).ready(init);

})(jQuery);