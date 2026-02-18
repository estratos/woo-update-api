<?php
class Woo_Update_API_Settings {

    private $options;
    private $option_name = 'woo_update_api_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
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
        $value = isset($this->options['api_url']) ? esc_url($this->options['api_url']) : '';
        ?>
        <input type="url" 
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