jQuery(document).ready(function($) {
    $('#wc-update-api-refresh').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var productId = $button.data('product-id');
        
        $button.prop('disabled', true).text('Refreshing...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wc_update_api_manual_refresh',
                product_id: productId,
                security: $button.closest('form').find('#_wpnonce').val()
            },
            success: function(response) {
                if (response.success) {
                    // Show success notice
                    $button.after('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    
                    // Reload the page to show updated values
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    // Show error notice
                    $button.after('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $button.after('<div class="notice notice-error inline"><p>AJAX request failed</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Refresh API Data');
            }
        });
    });
});