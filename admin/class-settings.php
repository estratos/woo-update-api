<?php
class Woo_Update_API_Settings {

    private $options;
    private $option_name = 'woo_update_api_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_woo_update_api_test_connection', [$this, 'ajax_test_connection']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_woo-update-api') {
            return;
        }

        wp_enqueue_script(
            'woo-update-api-admin',
            WOO_UPDATE_API_URL . 'admin/js/admin-scripts.js',
            ['jquery'],
            WOO_UPDATE_API_VERSION,
            true
        );

        wp_localize_script('woo-update-api-admin', 'wooUpdateApi', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_update_api_test_connection'),
            'messages' => [
                'testing' => __('Probando conexión...', 'woo-update-api'),
                'success' => __('¡Conexión exitosa!', 'woo-update-api'),
                'error' => __('Error de conexión: ', 'woo-update-api'),
                'invalid_response' => __('Respuesta inválida de la API', 'woo-update-api')
            ]
        ]);
    }

    public function add_admin_menu() {
        add_options_page(
            __('WooCommerce Update API', 'woo-update-api'),
            __('Woo Update API', 'woo-update-api'),
            'manage_options',
            'woo-update-api',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(
            $this->option_name,
            $this->option_name,
            [$this, 'validate_settings']
        );

        add_settings_section(
            'woo_update_api_main',
            __('Configuración de la API', 'woo-update-api'),
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

        add_settings_field(
            'cache_time',
            __('Cache Time (segundos)', 'woo-update-api'),
            [$this, 'render_cache_time_field'],
            'woo-update-api',
            'woo_update_api_main'
        );

        add_settings_field(
            'test_connection',
            __('Probar Conexión', 'woo-update-api'),
            [$this, 'render_test_connection_field'],
            'woo-update-api',
            'woo_update_api_main'
        );
    }

    public function render_settings_page() {
        $this->options = get_option($this->option_name);
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce Update API Settings', 'woo-update-api'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections('woo-update-api');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_main_section() {
        echo '<p>' . __('Configura los parámetros de conexión a la API externa', 'woo-update-api') . '</p>';
    }

    public function render_api_url_field() {
        $value = isset($this->options['api_url']) ? esc_url($this->options['api_url']) : 'https://catalogdev.estratosdev.top/api/woocommerce/v1/products';
        ?>
        <input type="url" 
               id="woo_update_api_url"
               name="<?php echo $this->option_name; ?>[api_url]" 
               value="<?php echo $value; ?>" 
               class="regular-text"
               placeholder="https://api.example.com/products">
        <p class="description">URL base del endpoint de la API</p>
        <?php
    }

    public function render_api_key_field() {
        $value = isset($this->options['api_key']) ? esc_attr($this->options['api_key']) : '';
        ?>
        <input type="password" 
               id="woo_update_api_key"
               name="<?php echo $this->option_name; ?>[api_key]" 
               value="<?php echo $value; ?>" 
               class="regular-text">
        <p class="description">Clave de autenticación para la API</p>
        <?php
    }

    public function render_cache_time_field() {
        $value = isset($this->options['cache_time']) ? intval($this->options['cache_time']) : 300;
        $value = max(30, min(3600, $value));
        ?>
        <input type="number" 
               name="<?php echo $this->option_name; ?>[cache_time]" 
               value="<?php echo $value; ?>" 
               min="30" 
               max="3600" 
               step="1">
        <p class="description">Tiempo de caché en segundos (mínimo 30, máximo 3600)</p>
        <?php
    }

    public function render_test_connection_field() {
        ?>
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" id="woo_update_api_test_btn" class="button button-secondary">
                <?php _e('Probar Conexión', 'woo-update-api'); ?>
            </button>
            <span id="woo_update_api_test_result" style="display: inline-block; padding: 4px 8px;"></span>
        </div>
        <p class="description">
            <?php _e('Prueba la conexión usando el SKU de prueba "ABC0000"', 'woo-update-api'); ?>
        </p>
        <?php
    }

    public function validate_settings($input) {
        $output = [];

        // Validate API URL
        if (!empty($input['api_url'])) {
            $output['api_url'] = esc_url_raw($input['api_url']);
        }

        // Validate API Key
        if (!empty($input['api_key'])) {
            $output['api_key'] = sanitize_text_field($input['api_key']);
        }

        // Validate Cache Time
        $cache_time = intval($input['cache_time']);
        $output['cache_time'] = max(30, min(3600, $cache_time));

        return $output;
    }

    /**
     * AJAX handler para probar la conexión
     */
    public function ajax_test_connection() {
        // Verificar nonce y permisos
        if (!check_ajax_referer('woo_update_api_test_connection', 'nonce', false)) {
            wp_send_json_error('Error de seguridad');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }

        $api_url = sanitize_text_field($_POST['api_url'] ?? '');
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');

        if (empty($api_url) || empty($api_key)) {
            wp_send_json_error('URL o API Key no proporcionados');
        }

        // SKU de prueba que siempre devuelve success:true
        $test_sku = 'ABC0000';

        // Construir URL de prueba
        $test_url = add_query_arg([
            'sku' => urlencode($test_sku),
            'api_key' => $api_key
        ], $api_url);

        // Realizar petición de prueba
        $response = wp_remote_get($test_url, [
            'timeout' => 15,
            'headers' => [
                'Cache-Control' => 'no-cache, no-store, must-revalidate'
            ]
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Error de conexión: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Respuesta inválida: ' . json_last_error_msg());
        }

        // Verificar que la respuesta sea exactamente {"success":true}
        if (isset($data['success']) && $data['success'] === true) {
            wp_send_json_success('Conexión exitosa - API responde correctamente');
        } else {
            wp_send_json_error('La API no devolvió success:true');
        }
    }

    public function get_api_url() {
        $options = get_option($this->option_name);
        return isset($options['api_url']) ? $options['api_url'] : '';
    }

    public function get_api_key() {
        $options = get_option($this->option_name);
        return isset($options['api_key']) ? $options['api_key'] : '';
    }

    public function get_cache_time() {
        $options = get_option($this->option_name);
        return isset($options['cache_time']) ? intval($options['cache_time']) : 300;
    }
}