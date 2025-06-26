<?php
if (!defined('ABSPATH')) exit;

class Woo_UpdateAPI_Settings {
    private static $instance = null;

    public static function init() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __('Update API Settings', 'woo-updateapi'),
            __('Update API', 'woo-updateapi'),
            'manage_options',
            'woo-updateapi-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('woo_updateapi_settings_group', 'woo_updateapi_settings');

        add_settings_section(
            'woo_updateapi_main_section',
            __('API Connection Settings', 'woo-updateapi'),
            [$this, 'render_section_info'],
            'woo-updateapi-settings'
        );

        add_settings_field(
            'api_url',
            __('API Endpoint URL', 'woo-updateapi'),
            [$this, 'render_api_url_field'],
            'woo-updateapi-settings',
            'woo_updateapi_main_section'
        );

        add_settings_field(
            'api_key',
            __('API Key', 'woo-updateapi'),
            [$this, 'render_api_key_field'],
            'woo-updateapi-settings',
            'woo_updateapi_main_section'
        );

        add_settings_field(
            'cache_time',
            __('Cache Time (seconds)', 'woo-updateapi'),
            [$this, 'render_cache_time_field'],
            'woo-updateapi-settings',
            'woo_updateapi_main_section'
        );
    }

    public function render_section_info() {
        echo '<p>' . __('Configure the connection to your external API for real-time pricing and inventory updates.', 'woo-updateapi') . '</p>';
    }

    public function render_api_url_field() {
        $settings = get_option('woo_updateapi_settings');
        echo '<input type="text" class="regular-text" name="woo_updateapi_settings[api_url]" value="' . esc_attr($settings['api_url'] ?? '') . '">';
    }

    public function render_api_key_field() {
        $settings = get_option('woo_updateapi_settings');
        echo '<input type="password" class="regular-text" name="woo_updateapi_settings[api_key]" value="' . esc_attr($settings['api_key'] ?? '') . '">';
    }

    public function render_cache_time_field() {
        $settings = get_option('woo_updateapi_settings');
        echo '<input type="number" name="woo_updateapi_settings[cache_time]" value="' . esc_attr($settings['cache_time'] ?? '300') . '" min="60">';
        echo '<p class="description">' . __('How long to cache API responses (in seconds).', 'woo-updateapi') . '</p>';
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce Update API Settings', 'woo-updateapi'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('woo_updateapi_settings_group');
                do_settings_sections('woo-updateapi-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}