/**
 * WooCommerce Update API - Admin JavaScript
 * Handles API reconnection, status display, and fallback settings
 * @version 1.0.0
 */
(function($) {
    'use strict';

    // DOM Elements
    const $document = $(document);
    const $reconnectBtn = $('#woo_update_api_reconnect');
    const $statusContainer = $('#woo_update_api_status');
    const $disableFallback = $('input[name="woo_update_api_settings[disable_fallback]"]');
    const originalBtnText = $reconnectBtn.text();

    // Initialize the plugin
    function init() {
        setupReconnectButton();
        setupStatusPolling();
        setupFallbackToggle();
    }

    /**
     * Setup the reconnect button functionality
     */
    function setupReconnectButton() {
        $reconnectBtn.on('click', function(e) {
            e.preventDefault();
            
            // Set loading state
            setButtonState('loading');
            
            // Make the AJAX call
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
     * Handle successful reconnect response
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
    function handleReconnectError(jqXHR, textStatus) {
        const errorMessage = woo_update_api.i18n.connection_failed + ': ' + textStatus;
        showNotice(errorMessage, 'error');
        console.error('API Reconnect Error:', textStatus);
    }

    /**
     * Reset button after operation completes
     */
    function resetButtonState() {
        setTimeout(() => {
            setButtonState('default');
        }, 1500);
    }

    /**
     * Set button visual state
     */
    function setButtonState(state) {
        $reconnectBtn
            .removeClass('button-primary button-error')
            .prop('disabled', state === 'loading');

        switch(state) {
            case 'loading':
                $reconnectBtn.text(woo_update_api.i18n.connecting);
                break;
            case 'success':
                $reconnectBtn
                    .addClass('button-primary')
                    .text(woo_update_api.i18n.connected);
                break;
            case 'error':
                $reconnectBtn
                    .addClass('button-error')
                    .text(woo_update_api.i18n.failed);
                break;
            default:
                $reconnectBtn
                    .addClass('button-secondary')
                    .text(originalBtnText);
        }
    }

    /**
     * Setup periodic status updates
     */
    function setupStatusPolling() {
        // Initial load
        updateStatusDisplay();
        
        // Update every 30 seconds
        setInterval(updateStatusDisplay, 30000);
    }

    /**
     * Fetch and update the status display
     */
    function updateStatusDisplay() {
        $.get(woo_update_api.ajaxurl, {
            action: 'woo_update_api_get_status',
            security: woo_update_api.nonce
        })
        .done(function(response) {
            if (response.success) {
                $statusContainer.html(response.data.status_html);
            }
        })
        .fail(function() {
            $statusContainer.html(`
                <div class="notice notice-error inline">
                    <p>${woo_update_api.i18n.status_error}</p>
                </div>
            `);
        });
    }

    /**
     * Setup fallback toggle handler
     */
    function setupFallbackToggle() {
        $disableFallback.on('change', function() {
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
        $('.wrap > h1').after(notice.fadeIn());
        
        // Make dismissible
        notice.on('click', '.notice-dismiss', function() {
            $(this).parent().fadeOut();
        });
        
        // Auto-dismiss
        if (type !== 'error') {
            setTimeout(() => notice.fadeOut(), 5000);
        }
    }

    // Initialize when ready
    $document.ready(init);

})(jQuery);