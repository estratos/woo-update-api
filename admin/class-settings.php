<?php
class Woo_Update_API_Settings {

    private $options;
    private $option_name = 'woo_update_api_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Cargar opciones
        $this->options = get_option($this->option_name, $this->get_defaults());
    }

    /**
     * Valores por defecto
     */
    private function get_defaults() {
        return [
            'api_url' => '',
            'api_key' => '',
            'enable_cache' => true,
            'cache_ttl' => 300, // 5 minutos
            'enable_ajax_cache' => true,
            'enable_batch' => false,
            'batch_size' => 10,
            'debug_mode' => false,
            'excluded_categories' => []
        ];
    }

    /**
     * Agregar al menú de administración
     */
    public function add_admin_menu() {
        add_options_page(
            __('WooCommerce Update API Settings', 'woo-update-api'),
            __('Woo Update API', 'woo-update-api'),
            'manage_options',
            'woo-update-api',
            [$this, 'render_settings_page']
        );

        // Agregar al menú de WooCommerce también
        add_submenu_page(
            'woocommerce',
            __('Update API Settings', 'woo-update-api'),
            __('Update API', 'woo-update-api'),
            'manage_options',
            'woo-update-api-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Registrar settings
     */
    public function register_settings() {
        register_setting(
            'woo_update_api_settings_group',
            $this->option_name,
            [$this, 'sanitize_settings']
        );

        // Sección API
        add_settings_section(
            'woo_update_api_section_api',
            __('API Configuration', 'woo-update-api'),
            [$this, 'render_section_api'],
            'woo-update-api'
        );

        // Campo API URL
        add_settings_field(
            'api_url',
            __('API URL', 'woo-update-api'),
            [$this, 'render_field_api_url'],
            'woo-update-api',
            'woo_update_api_section_api'
        );

        // Campo API Key
        add_settings_field(
            'api_key',
            __('API Key', 'woo-update-api'),
            [$this, 'render_field_api_key'],
            'woo-update-api',
            'woo_update_api_section_api'
        );

        // Sección Caché
        add_settings_section(
            'woo_update_api_section_cache',
            __('Cache Configuration', 'woo-update-api'),
            [$this, 'render_section_cache'],
            'woo-update-api'
        );

        // Campo Enable Cache
        add_settings_field(
            'enable_cache',
            __('Enable Persistent Cache', 'woo-update-api'),
            [$this, 'render_field_enable_cache'],
            'woo-update-api',
            'woo_update_api_section_cache'
        );

        // Campo Cache TTL
        add_settings_field(
            'cache_ttl',
            __('Cache TTL (seconds)', 'woo-update-api'),
            [$this, 'render_field_cache_ttl'],
            'woo-update-api',
            'woo_update_api_section_cache'
        );

        // Campo Enable AJAX Cache
        add_settings_field(
            'enable_ajax_cache',
            __('Enable Cache for AJAX', 'woo-update-api'),
            [$this, 'render_field_enable_ajax_cache'],
            'woo-update-api',
            'woo_update_api_section_cache'
        );

        // Sección Avanzada
        add_settings_section(
            'woo_update_api_section_advanced',
            __('Advanced Settings', 'woo-update-api'),
            [$this, 'render_section_advanced'],
            'woo-update-api'
        );

        // Campo Batch Mode
        add_settings_field(
            'enable_batch',
            __('Enable Batch Mode', 'woo-update-api'),
            [$this, 'render_field_enable_batch'],
            'woo-update-api',
            'woo_update_api_section_advanced'
        );

        // Campo Debug Mode
        add_settings_field(
            'debug_mode',
            __('Debug Mode', 'woo-update-api'),
            [$this, 'render_field_debug_mode'],
            'woo-update-api',
            'woo_update_api_section_advanced'
        );
    }

    /**
     * Sanitizar settings
     */
    public function sanitize_settings($input) {
        $sanitized = [];

        // API URL
        $sanitized['api_url'] = isset($input['api_url']) 
            ? esc_url_raw($input['api_url']) 
            : '';

        // API Key
        $sanitized['api_key'] = isset($input['api_key']) 
            ? sanitize_text_field($input['api_key']) 
            : '';

        // Cache settings
        $sanitized['enable_cache'] = isset($input['enable_cache']) && $input['enable_cache'] == 1;
        $sanitized['cache_ttl'] = isset($input['cache_ttl']) 
            ? max(60, min(3600, intval($input['cache_ttl']))) 
            : 300;
        $sanitized['enable_ajax_cache'] = isset($input['enable_ajax_cache']) && $input['enable_ajax_cache'] == 1;

        // Advanced
        $sanitized['enable_batch'] = isset($input['enable_batch']) && $input['enable_batch'] == 1;
        $sanitized['debug_mode'] = isset($input['debug_mode']) && $input['debug_mode'] == 1;

        return $sanitized;
    }

    /**
     * Renderizar página de settings
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce Update API Settings', 'woo-update-api'); ?></h1>
            
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Settings saved!', 'woo-update-api'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('woo_update_api_settings_group');
                do_settings_sections('woo-update-api');
                submit_button();
                ?>
            </form>

            <?php if ($this->get_debug_mode()): ?>
                <div class="card">
                    <h2><?php _e('Cache Statistics', 'woo-update-api'); ?></h2>
                    <pre><?php print_r(Woo_Update_API_Cache::get_stats()); ?></pre>
                    <button type="button" class="button" id="woo-update-api-reset-stats">
                        <?php _e('Reset Statistics', 'woo-update-api'); ?>
                    </button>
                    <button type="button" class="button" id="woo-update-api-clear-cache">
                        <?php _e('Clear All Cache', 'woo-update-api'); ?>
                    </button>
                </div>

                <script>
                jQuery(document).ready(function($) {
                    $('#woo-update-api-reset-stats').on('click', function() {
                        $.post(ajaxurl, {
                            action: 'woo_update_api_reset_stats',
                            nonce: '<?php echo wp_create_nonce('woo_update_api_admin'); ?>'
                        }, function(response) {
                            if (response.success) {
                                location.reload();
                            }
                        });
                    });

                    $('#woo-update-api-clear-cache').on('click', function() {
                        $.post(ajaxurl, {
                            action: 'woo_update_api_clear_cache',
                            nonce: '<?php echo wp_create_nonce('woo_update_api_admin'); ?>'
                        }, function(response) {
                            if (response.success) {
                                alert('Cache cleared!');
                            }
                        });
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    // Render methods para cada campo
    public function render_section_api() {
        echo '<p>' . __('Configure the external API connection.', 'woo-update-api') . '</p>';
    }

    public function render_field_api_url() {
        $value = $this->get_api_url();
        echo '<input type="url" name="' . $this->option_name . '[api_url]" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">' . __('URL of the external API (e.g., https://api.example.com/products)', 'woo-update-api') . '</p>';
    }

    public function render_field_api_key() {
        $value = $this->get_api_key();
        echo '<input type="text" name="' . $this->option_name . '[api_key]" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function render_section_cache() {
        echo '<p>' . __('Configure caching behavior to reduce API calls.', 'woo-update-api') . '</p>';
    }

    public function render_field_enable_cache() {
        $checked = $this->is_cache_enabled() ? 'checked' : '';
        echo '<input type="checkbox" name="' . $this->option_name . '[enable_cache]" value="1" ' . $checked . '>';
        echo '<label>' . __('Enable persistent caching of API responses', 'woo-update-api') . '</label>';
    }

    public function render_field_cache_ttl() {
        $value = $this->get_cache_ttl();
        echo '<input type="number" name="' . $this->option_name . '[cache_ttl]" value="' . $value . '" min="60" max="3600" step="10">';
        echo '<p class="description">' . __('Time in seconds to cache API responses (60-3600)', 'woo-update-api') . '</p>';
    }

    public function render_field_enable_ajax_cache() {
        $checked = $this->is_ajax_cache_enabled() ? 'checked' : '';
        echo '<input type="checkbox" name="' . $this->option_name . '[enable_ajax_cache]" value="1" ' . $checked . '>';
        echo '<label>' . __('Enable cache for AJAX requests', 'woo-update-api') . '</label>';
    }

    public function render_section_advanced() {
        echo '<p>' . __('Advanced configuration options.', 'woo-update-api') . '</p>';
    }

    public function render_field_enable_batch() {
        $checked = $this->is_batch_enabled() ? 'checked' : '';
        echo '<input type="checkbox" name="' . $this->option_name . '[enable_batch]" value="1" ' . $checked . '>';
        echo '<label>' . __('Enable batch API requests (if supported by API)', 'woo-update-api') . '</label>';
    }

    public function render_field_debug_mode() {
        $checked = $this->get_debug_mode() ? 'checked' : '';
        echo '<input type="checkbox" name="' . $this->option_name . '[debug_mode]" value="1" ' . $checked . '>';
        echo '<label>' . __('Enable debug mode (shows cache stats, logs more info)', 'woo-update-api') . '</label>';
    }

    // Getters
    public function get_api_url() {
        return $this->options['api_url'] ?? '';
    }

    public function get_api_key() {
        return $this->options['api_key'] ?? '';
    }

    public function is_cache_enabled() {
        return (bool)($this->options['enable_cache'] ?? true);
    }

    public function get_cache_ttl() {
        return (int)($this->options['cache_ttl'] ?? 300);
    }

    public function is_ajax_cache_enabled() {
        return (bool)($this->options['enable_ajax_cache'] ?? true);
    }

    public function is_batch_enabled() {
        return (bool)($this->options['enable_batch'] ?? false);
    }

    public function get_debug_mode() {
        return (bool)($this->options['debug_mode'] ?? false);
    }
}