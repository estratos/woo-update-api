jQuery(document).ready(function($) {
    $(document).on('click', '#wc-update-api-refresh', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var productId = $button.data('product-id');
        var $container = $button.closest('.options_group');
        
        // Clear previous messages
        $container.find('.notice').remove();
        
        // Show loading state
        $button.prop('disabled', true).append('<span class="spinner is-active" style="float:none;margin-left:5px;"></span>');
        
        // Get AJAX URL and nonce from localized data
        var ajaxData = typeof wc_update_api_params !== 'undefined' ? wc_update_api_params : {
            ajax_url: ajaxurl,
            nonce: $button.closest('form').find('#_wpnonce').val()
        };
        
        $.ajax({
            url: ajaxData.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'wc_update_api_manual_refresh',
                product_id: productId,
                security: ajaxData.nonce
            },
            success: function(response) {
                if (response.success) {
                    $container.prepend(
                        '<div class="notice notice-success is-dismissible"><p>' + 
                        response.data.message + 
                        '</p></div>'
                    );
                    
                    if (response.data.last_refresh) {
                        $container.find('.description').text(
                            wc_update_api_params.i18n_last_refresh + ' ' + 
                            response.data.last_refresh
                        );
                    }
                } else {
                    showError($container, response.data?.message || 'Unknown error occurred');
                }
            },
            error: function(xhr) {
                var errorMessage = 'AJAX request failed';
                
                if (xhr.status === 403) {
                    errorMessage = 'Permission denied (403 Forbidden)';
                } else if (xhr.status === 400) {
                    errorMessage = 'Bad request (400)';
                }
                
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                }
                
                showError($container, errorMessage);
                
                console.error('AJAX Error:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    response: xhr.responseText
                });
            },
            complete: function() {
                $button.prop('disabled', false).find('.spinner').remove();
            }
        });
    });
    
    function showError($container, message) {
        $container.prepend(
            '<div class="notice notice-error is-dismissible"><p>' + 
            message + 
            '</p></div>'
        );
    }
});