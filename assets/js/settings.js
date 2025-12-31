(function($) {
    'use strict';

    $(document).ready(function() {
        var wooApiSettings = window.woo_update_api_settings || {};
        
        // Test API connection
        $('#woo_update_api_test_connection').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var $result = $('#woo_update_api_test_result');
            
            $button.prop('disabled', true).text(wooApiSettings.i18n.testing_connection);
            $result.html('').removeClass('updated error');
            
            $.ajax({
                url: wooApiSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'woo_update_api_get_status',
                    nonce: wooApiSettings.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var html = '<div class="notice notice-success inline"><p>';
                        html += '<strong>' + wooApiSettings.i18n.connection_success + '</strong><br>';
                        html += 'Connected: ' + (response.data.connected ? 'Yes' : 'No') + '<br>';
                        html += 'Fallback Mode: ' + (response.data.fallback_mode ? 'Active' : 'Inactive') + '<br>';
                        html += 'Error Count: ' + response.data.error_count + '/' + response.data.error_threshold;
                        html += '</p></div>';
                        $result.html(html).addClass('updated');
                    } else {
                        $result.html(
                            '<div class="notice notice-error inline"><p>' + 
                            wooApiSettings.i18n.connection_failed + ' ' + response.data.message +
                            '</p></div>'
                        ).addClass('error');
                    }
                },
                error: function() {
                    $result.html(
                        '<div class="notice notice-error inline"><p>' + 
                        'Request failed. Please try again.' +
                        '</p></div>'
                    ).addClass('error');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Test API Connection');
                }
            });
        });
        
        // Reconnect API
        $('#woo_update_api_reconnect').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to reconnect? This will clear all cached data and reset error counters.')) {
                return;
            }
            
            var $button = $(this);
            var $result = $('#woo_update_api_test_result');
            
            $button.prop('disabled', true).text(wooApiSettings.i18n.reconnecting);
            $result.html('').removeClass('updated error');
            
            $.ajax({
                url: wooApiSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'woo_update_api_reconnect',
                    nonce: wooApiSettings.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html(
                            '<div class="notice notice-success inline"><p>' + 
                            wooApiSettings.i18n.reconnect_success + '<br>' +
                            response.data.message +
                            '</p></div>'
                        ).addClass('updated');
                    } else {
                        $result.html(
                            '<div class="notice notice-error inline"><p>' + 
                            wooApiSettings.i18n.reconnect_failed + ' ' + response.data.message +
                            '</p></div>'
                        ).addClass('error');
                    }
                },
                error: function() {
                    $result.html(
                        '<div class="notice notice-error inline"><p>' + 
                        'Request failed. Please try again.' +
                        '</p></div>'
                    ).addClass('error');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Reconnect & Reset');
                }
            });
        });
        
        // Clear cache
        $('#woo_update_api_clear_cache').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to clear all cached API data?')) {
                return;
            }
            
            var $button = $(this);
            var $result = $('#woo_update_api_test_result');
            
            $button.prop('disabled', true).text(wooApiSettings.i18n.clearing_cache);
            $result.html('').removeClass('updated error');
            
            // We'll use the reconnect endpoint since it also clears cache
            $.ajax({
                url: wooApiSettings.ajaxurl,
                type: 'POST',
                data: {
                    action: 'woo_update_api_reconnect',
                    nonce: wooApiSettings.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html(
                            '<div class="notice notice-success inline"><p>' + 
                            wooApiSettings.i18n.cache_cleared +
                            '</p></div>'
                        ).addClass('updated');
                    } else {
                        $result.html(
                            '<div class="notice notice-error inline"><p>' + 
                            response.data.message +
                            '</p></div>'
                        ).addClass('error');
                    }
                },
                error: function() {
                    $result.html(
                        '<div class="notice notice-error inline"><p>' + 
                        'Request failed. Please try again.' +
                        '</p></div>'
                    ).addClass('error');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Clear All Cache');
                }
            });
        });
    });

})(jQuery);