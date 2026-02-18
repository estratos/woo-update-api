<?php
namespace Woo_Update_API\Admin;

use Woo_Update_API\API_Handler;
use Woo_Update_API\API_Error_Manager;

defined('ABSPATH') || exit;

class Settings
{
    private static $instance = null;
    private $api_handler;
    private $error_manager;

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->api_handler = API_Handler::instance();
        $this->error_manager = API_Error_Manager::instance();
        $this->init_hooks();
    }

    private function init_hooks()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function add_settings_page()
    {
        add_options_page(
            __('WooCommerce Update API Settings', 'woo-update-api'),
            __('Woo Update API', 'woo-update-api'),
            'manage_options',
            'woo-update-api',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_admin_scripts($hook)
    {
        if ('settings_page_woo-update-api' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'woo-update-api-admin',
            WOO_UPDATE_API_URL . 'assets/css/admin.css',
            [],
            WOO_UPDATE_API_VERSION
        );

        wp_enqueue_script(
            'woo-update-api-admin',
            WOO_UPDATE_API_URL . 'assets/js/admin.js',
            ['jquery'],
            WOO_UPDATE_API_VERSION,
            true
        );

        wp_localize_script('woo-update-api-admin', 'woo_update_api', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_update_api_nonce'),
            'i18n' => [
                'checking' => __('Checking connection...', 'woo-update-api'),
                'success' => __('Connected!', 'woo-update-api'),
                'error' => __('Connection failed', 'woo-update-api')
            ]
        ]);
    }

    public function register_settings()
    {
        register_setting(
            'woo_update_api_settings',
            'woo_update_api_settings',
            [$this, 'validate_settings']
        );

        // API Configuration Section
        add_settings_section(
            'woo_update_api_main',
            __('API Configuration', 'woo-update-api'),
            [$this, 'render_main_section'],
            'woo-update-api'
        );

        add_settings_field(
            'api_url',
            __('API URL', 'woo-update-api'),
            [$this, 'render_api_url_field'],
            'woo-update-api',
            'woo_update_api_main'
        );

        add_settings_field(
            'api_key',
            __('API Key', 'woo-update-api'),
            [$this, 'render_api_key_field'],
            'woo-update-api',
            'woo_update_api_main'
        );

        // Cache Settings Section
        add_settings_section(
            'woo_update_api_cache',
            __('Cache Settings', 'woo-update-api'),
            [$this, 'render_cache_section'],
            'woo-update-api'
        );

        add_settings_field(
            'cache_time',
            __('Cache Time (seconds)', 'woo-update-api'),
            [$this, 'render_cache_time_field'],
            'woo-update-api',
            'woo_update_api_cache'
        );

        add_settings_field(
            'reconnect_time',
            __('Reconnect Time (seconds)', 'woo-update-api'),
            [$this, 'render_reconnect_time_field'],
            'woo-update-api',
            'woo_update_api_cache'
        );

        // Status Section
        add_settings_section(
            'woo_update_api_status',
            __('API Status', 'woo-update-api'),
            [$this, 'render_status_section'],
            'woo-update-api'
        );

        add_settings_field(
            'connection_status',
            __('Connection Status', 'woo-update-api'),
            [$this, 'render_connection_status'],
            'woo-update-api',
            'woo_update_api_status'
        );

        // Debug Section
        add_settings_section(
            'woo_update_api_debug',
            __('Debug Options', 'woo-update-api'),
            [$this, 'render_debug_section'],
            'woo-update-api'
        );

        add_settings_field(
            'clear_cache',
            __('Clear Cache', 'woo-update-api'),
            [$this, 'render_clear_cache_field'],
            'woo-update-api',
            'woo_update_api_debug'
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('woo_update_api_settings'); ?>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('woo_update_api_settings');
                do_settings_sections('woo-update-api');
                submit_button(__('Save Settings', 'woo-update-api'));
                ?>
            </form>
            
            <div class="woo-update-api-info">
                <h2><?php _e('System Information', 'woo-update-api'); ?></h2>
                <table class="widefat fixed" style="max-width: 600px;">
                    <tr>
                        <td><strong><?php _e('Plugin Version:', 'woo-update-api'); ?></strong></td>
                        <td><?php echo WOO_UPDATE_API_VERSION; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('WordPress Version:', 'woo-update-api'); ?></strong></td>
                        <td><?php echo get_bloginfo('version'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('WooCommerce Version:', 'woo-update-api'); ?></strong></td>
                        <td><?php echo defined('WC_VERSION') ? WC_VERSION : __('Not installed', 'woo-update-api'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('PHP Version:', 'woo-update-api'); ?></strong></td>
                        <td><?php echo phpversion(); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    public function render_main_section()
    {
        echo '<p>' . __('Configure your API connection details below.', 'woo-update-api') . '</p>';
    }

    public function render_cache_section()
    {
        echo '<p>' . __('Configure cache and timeout settings.', 'woo-update-api') . '</p>';
    }

    public function render_status_section()
    {
        echo '<p>' . __('Current status of your API connection.', 'woo-update-api') . '</p>';
    }

    public function render_debug_section()
    {
        echo '<p>' . __('Debug and maintenance options.', 'woo-update-api') . '</p>';
    }

    public function render_api_url_field()
    {
        $options = get_option('woo_update_api_settings');
        $value = $options['api_url'] ?? '';
        ?>
        <input type="url" 
               name="woo_update_api_settings[api_url]" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text"
               placeholder="https://api.example.com/products">
        <p class="description"><?php _e('Enter the base URL of your API endpoint.', 'woo-update-api'); ?></p>
        <?php
    }

    public function render_api_key_field()
    {
        $options = get_option('woo_update_api_settings');
        $value = $options['api_key'] ?? '';
        ?>
        <input type="password" 
               name="woo_update_api_settings[api_key]" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text">
        <p class="description"><?php _e('Your API authentication key.', 'woo-update-api'); ?></p>
        <?php
    }

    public function render_cache_time_field()
    {
        $options = get_option('woo_update_api_settings');
        $value = $options['cache_time'] ?? 300;
        ?>
        <input type="number" 
               name="woo_update_api_settings[cache_time]" 
               value="<?php echo esc_attr($value); ?>" 
               min="30" 
               max="3600" 
               step="1">
        <p class="description"><?php _e('How long to cache API responses (in seconds). Minimum 30 seconds.', 'woo-update-api'); ?></p>
        <?php
    }

    public function render_reconnect_time_field()
    {
        $options = get_option('woo_update_api_settings');
        $value = $options['reconnect_time'] ?? 3600;
        ?>
        <input type="number" 
               name="woo_update_api_settings[reconnect_time]" 
               value="<?php echo esc_attr($value); ?>" 
               min="300" 
               max="86400" 
               step="1">
        <p class="description"><?php _e('How long to stay in fallback mode before retrying (in seconds). Minimum 300 seconds.', 'woo-update-api'); ?></p>
        <?php
    }

    /**
     * Render connection status field
     */
    public function render_connection_status()
    {
        $error_manager = \Woo_Update_API\API_Error_Manager::instance();
        
        // Obtener estado actual
        $error_count = $error_manager->get_error_count();
        $fallback_active = $error_manager->is_fallback_active();
        $threshold = $error_manager::ERROR_THRESHOLD;
        
        // Determinar clase CSS y mensaje
        if ($fallback_active) {
            $status_class = 'error';
            $status_text = __('Fallback Mode Active', 'woo-update-api');
            $status_description = __('API is unavailable. Using WooCommerce default data.', 'woo-update-api');
        } elseif ($error_count >= $threshold) {
            $status_class = 'warning';
            $status_text = __('Unstable', 'woo-update-api');
            $status_description = sprintf(
                __('High error rate (%d/%d). Connection may be unstable.', 'woo-update-api'),
                $error_count,
                $threshold
            );
        } else {
            $status_class = 'success';
            $status_text = __('Connected', 'woo-update-api');
            $status_description = __('API connection is working normally.', 'woo-update-api');
        }
        
        // Verificar si hay configuración
        $settings = get_option('woo_update_api_settings', []);
        $api_configured = !empty($settings['api_url']) && !empty($settings['api_key']);
        
        ?>
        <div class="api-status-container">
            <div class="api-status-indicator <?php echo esc_attr($status_class); ?>">
                <span class="status-dot"></span>
                <strong><?php echo esc_html($status_text); ?></strong>
            </div>
            
            <p class="description">
                <?php echo esc_html($status_description); ?>
            </p>
            
            <?php if ($api_configured): ?>
                <p>
                    <button type="button" id="check-api-status" class="button button-secondary">
                        <?php esc_html_e('Check Connection Now', 'woo-update-api'); ?>
                    </button>
                    <span class="spinner" style="float:none; margin-top:0;"></span>
                </p>
                <div id="api-status-result" style="margin-top:10px;"></div>
                
                <?php if ($error_count > 0): ?>
                    <p>
                        <button type="button" id="reset-api-errors" class="button button-link">
                            <?php esc_html_e('Reset error counter', 'woo-update-api'); ?>
                        </button>
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p class="description">
                    <?php esc_html_e('Configure API URL and Key above to test connection.', 'woo-update-api'); ?>
                </p>
            <?php endif; ?>
        </div>
        
        <style>
            .api-status-indicator {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                font-weight: 500;
                margin-bottom: 10px;
            }
            .api-status-indicator.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .api-status-indicator.warning {
                background: #fff3cd;
                color: #856404;
                border: 1px solid #ffeeba;
            }
            .api-status-indicator.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            .status-dot {
                display: inline-block;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                margin-right: 8px;
            }
            .success .status-dot {
                background: #28a745;
            }
            .warning .status-dot {
                background: #ffc107;
            }
            .error .status-dot {
                background: #dc3545;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#check-api-status').on('click', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var spinner = button.siblings('.spinner');
                var resultDiv = $('#api-status-result');
                
                button.prop('disabled', true);
                spinner.addClass('is-active');
                resultDiv.html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'woo_update_api_get_status',
                        nonce: '<?php echo wp_create_nonce('woo_update_api_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var status = response.data;
                            var message = status.connected ? 
                                '✅ <?php echo esc_js(__('API connection successful!', 'woo-update-api')); ?>' : 
                                '❌ <?php echo esc_js(__('API connection failed.', 'woo-update-api')); ?>';
                            
                            resultDiv.html('<div class="notice notice-success inline"><p>' + message + '</p></div>');
                            
                            // Recargar para actualizar el indicador
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            resultDiv.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        resultDiv.html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Request failed.', 'woo-update-api')); ?></p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                        spinner.removeClass('is-active');
                    }
                });
            });
            
            $('#reset-api-errors').on('click', function(e) {
                e.preventDefault();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'woo_update_api_reconnect',
                        nonce: '<?php echo wp_create_nonce('woo_update_api_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function render_clear_cache_field()
    {
        ?>
        <button type="button" id="clear-api-cache" class="button button-secondary">
            <?php esc_html_e('Clear All Cache', 'woo-update-api'); ?>
        </button>
        <span class="spinner" style="float:none; margin-top:0;"></span>
        <div id="clear-cache-result" style="margin-top:10px;"></div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#clear-api-cache').on('click', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var spinner = button.siblings('.spinner');
                var resultDiv = $('#clear-cache-result');
                
                button.prop('disabled', true);
                spinner.addClass('is-active');
                resultDiv.html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'woo_update_api_reconnect',
                        nonce: '<?php echo wp_create_nonce('woo_update_api_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            resultDiv.html('<div class="notice notice-success inline"><p><?php echo esc_js(__('Cache cleared successfully!', 'woo-update-api')); ?></p></div>');
                        } else {
                            resultDiv.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        resultDiv.html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Request failed.', 'woo-update-api')); ?></p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                        spinner.removeClass('is-active');
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function validate_settings($input)
    {
        $output = [];
        
        // Validar API URL
        if (!empty($input['api_url'])) {
            $output['api_url'] = esc_url_raw($input['api_url']);
        } else {
            $output['api_url'] = '';
        }
        
        // Validar API Key
        if (!empty($input['api_key'])) {
            $output['api_key'] = sanitize_text_field($input['api_key']);
        } else {
            $output['api_key'] = '';
        }
        
        // Validar cache time
        $output['cache_time'] = isset($input['cache_time']) ? max(30, min(3600, absint($input['cache_time']))) : 300;
        
        // Validar reconnect time
        $output['reconnect_time'] = isset($input['reconnect_time']) ? max(300, min(86400, absint($input['reconnect_time']))) : 3600;
        
        return $output;
    }
}