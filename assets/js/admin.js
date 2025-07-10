jQuery(function($) {
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
            security: woo_update_api.nonce,
            _: Date.now() // Cache buster
        })
        .done(function(response) {
            if (response.success) {
                $statusContainer.html(response.data.status_html);
            }
        })
        .fail(function(jqXHR) {
            console.error('Status update failed:', jqXHR.responseText);
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
                security: woo_update_api.nonce
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
        $document.on('click', '.refresh-api-data', function(e) {
            e.preventDefault();
            if (isProcessing) return;
            
            isProcessing = true;
            const $button = $(this);
            const product_id = $button.data('product-id');
            const $spinner = $button.next('.spinner');
            
            $button.prop('disabled', true);
            $spinner.addClass('is-active').css('visibility', 'visible');
            
            $.post(woo_update_api.ajaxurl, {
                action: 'woo_update_api_refresh_product',
                security: woo_update_api.nonce,
                product_id: product_id
            })
            .done(function(response) {
                if (response.success) {
                    updateProductDisplay(response.data);
                    showNotice(response.data.message, 'success');
                } else {
                    showNotice(response.data.message, 'error');
                }
            })
            .fail(function() {
                showNotice(woo_update_api.i18n.request_failed, 'error');
            })
            .always(function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active').css('visibility', 'hidden');
                isProcessing = false;
            });
        });
    }

    // Update product display after refresh
    function updateProductDisplay(data) {
        if (data.price) {
            $('input[name="_regular_price"]').val(data.price).trigger('change');
        }
        if (data.stock) {
            $('input[name="_stock"]').val(data.stock).trigger('change');
        }
    }

    // Show admin notice
    function showNotice(message, type = 'success') {
        const notice = $(`<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`);
        $('#wpbody-content .wrap h1').first().after(notice);
        
        // Auto-dismiss after 5 seconds
        if (type !== 'error') {
            setTimeout(() => notice.fadeOut(), 5000);
        }
    }

    // Initialize
    init();
});