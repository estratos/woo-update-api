jQuery(document).ready(function($) {
    $(document).on('click', '.wc-update-api-refresh', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $container = $button.closest('.wc-update-api-container');
        
        // Clear previous notices
        $container.find('.notice').remove();
        
        // Show loading state
        $button.prop('disabled', true)
               .find('.spinner')
               .addClass('is-active');
        
        $.ajax({
            url: wc_update_api.ajax_url,
            type: 'POST',
            data: {
                action: 'wc_update_api_manual_refresh',
                product_id: $button.data('product-id'),
                security: wc_update_api.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $container.prepend(
                        '<div class="notice notice-success"><p>' + 
                        (response.data.message || wc_update_api.i18n.success) + 
                        '</p></div>'
                    );
                } else {
                    showError(response.data?.message || wc_update_api.i18n.error);
                }
            },
            error: function(xhr) {
                var message = xhr.responseJSON?.data?.message || 
                             wc_update_api.i18n.error;
                showError(message);
                console.error('AJAX Error:', xhr.responseJSON || xhr.statusText);
            },
            complete: function() {
                $button.prop('disabled', false)
                       .find('.spinner')
                       .removeClass('is-active');
            }
        });
        
        function showError(message) {
            $container.prepend(
                '<div class="notice notice-error"><p>' + 
                message + 
                '</p></div>'
            );
        }
    });
});