jQuery(document).ready(function($) {
    $('#woo_update_api_test_btn').on('click', function() {
        var $button = $(this);
        var $result = $('#woo_update_api_test_result');
        var apiUrl = $('#woo_update_api_url').val();
        var apiKey = $('#woo_update_api_key').val();

        // Validar campos
        if (!apiUrl || !apiKey) {
            $result.html('<span style="color: #d63638;">❌ ' + wooUpdateApi.messages.error + ' URL y API Key son requeridos</span>');
            return;
        }

        // Deshabilitar botón durante la prueba
        $button.prop('disabled', true);
        $result.html('<span style="color: #666;">⏳ ' + wooUpdateApi.messages.testing + '</span>');

        // Realizar petición AJAX
        $.ajax({
            url: wooUpdateApi.ajax_url,
            type: 'POST',
            data: {
                action: 'woo_update_api_test_connection',
                nonce: wooUpdateApi.nonce,
                api_url: apiUrl,
                api_key: apiKey
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span style="color: #46b450;">✅ ' + response.data + '</span>');
                } else {
                    $result.html('<span style="color: #d63638;">❌ ' + wooUpdateApi.messages.error + response.data + '</span>');
                }
            },
            error: function(xhr, status, error) {
                $result.html('<span style="color: #d63638;">❌ ' + wooUpdateApi.messages.error + error + '</span>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
});