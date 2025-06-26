<?php
if (!defined('ABSPATH')) exit;

class WC_External_API_Settings {
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
            __('External API Settings', 'woo-update-api'),
            __('External API', 'woo-update-api'),
            'manage_options',
            'woo-update-api-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('wc_external_api_settings_group', 'wc_external_api_settings');

        add_settings_section(
            'wc_external_api_main_section',
            __('API Connection Settings', 'woo-update-api'),
            [$this, 'render_section_info'],
            'woo-update-api-settings'
        );

        add_settings_field(
            'api_url',
            __('API Endpoint URL', 'woo-update-api'),
            [$this, 'render_api_url_field'],
            'woo-update-api-settings',
            'wc_external_api_main_section'
        );

        add_settings_field(
            'api_key',
            __('API Key', 'woo-update-api'),
            [$this, 'render_api_key_field'],
            'woo-update-api-settings',
            'wc_external_api_main_section'
        );

        add_settings_field(
            'cache_time',
            __('Cache Time (seconds)', 'woo-update-api'),
            [$this, 'render_cache_time_field'],
            'woo-update-api-settings',
            'wc_external_api_main_section'
        );
    }

    public function render_section_info() {
        echo '<p>' . __('Configure the connection to your external API for real-time pricing and inventory updates.', 'woo-update-api') . '</p>';
    }

    public function render_api_url_field() {
        $settings = get_option('wc_external_api_settings');
        echo '<input type="text" class="regular-text" name="wc_external_api_settings[api_url]" value="' . esc_attr($settings['api_url'] ?? '') . '">';
    }

    public function render_api_key_field() {
        $settings = get_option('wc_external_api_settings');
        echo '<input type="password" class="regular-text" name="wc_external_api_settings[api_key]" value="' . esc_attr($settings['api_key'] ?? '') . '">';
    }

    public function render_cache_time_field() {
        $settings = get_option('wc_external_api_settings');
        echo '<input type="number" name="wc_external_api_settings[cache_time]" value="' . esc_attr($settings['cache_time'] ?? '300') . '" min="60">';
        echo '<p class="description">' . __('How long to cache API responses (in seconds).', 'woo-update-api') . '</p>';
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce External API Settings', 'woo-update-api'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_external_api_settings_group');
                do_settings_sections('woo-update-api-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
