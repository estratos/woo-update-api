<?php
namespace Woo_Update_API\Admin;

defined('ABSPATH') || exit;

class Settings {
    private static $instance = null;
    private $settings_group = 'woo_update_api_settings_group';
    private $settings_name = 'woo_update_api_settings';

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_settings_page() {
        add_options_page(
            __('WooCommerce Update API Settings', 'woo-update-api'),
            __('WC Update API', 'woo-update-api'),
            'manage_options',
            'woo-update-api',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting($this->settings_group, $this->settings_name);

        add_settings_section(
            'woo_update_api_main',
            __('API Connection Settings', 'woo-update-api'),
            [$this, 'render_section'],
            'woo-update-api'
        );

        add_settings_field(
            'api_url',
            __('API Endpoint URL', 'woo-update-api'),
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
            __('Cache Duration (seconds)', 'woo-update-api'),
            [$this, 'render_cache_time_field'],
            'woo-update-api',
            'woo_update_api_main'
        );

        add_settings_field(
            'reconnect_time',
            __('Reconnect Time (seconds)', 'woo-update-api'),
            [$this, 'render_cache_time_field'],
            'woo-update-api',
            'woo_update_api_main'
        );


    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WooCommerce Update API Settings', 'woo-update-api'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->settings_group);
                do_settings_sections('woo-update-api');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_section() {
        echo '<p>' . esc_html__('Configure your external API connection details.', 'woo-update-api') . '</p>';
    }

    public function render_api_url_field() {
        $settings = get_option($this->settings_name);
        echo '<input type="url" class="regular-text" name="' . esc_attr($this->settings_name) . '[api_url]" value="' . esc_attr($settings['api_url'] ?? '') . '" required>';
    }

    public function render_api_key_field() {
        $settings = get_option($this->settings_name);
        echo '<input type="password" class="regular-text" name="' . esc_attr($this->settings_name) . '[api_key]" value="' . esc_attr($settings['api_key'] ?? '') . '" required>';
    }

    public function render_cache_time_field() {
        $settings = get_option($this->settings_name);
        echo '<input type="number" min="60" name="' . esc_attr($this->settings_name) . '[cache_time]" value="' . esc_attr($settings['cache_time'] ?? 300) . '">';
        echo '<p class="description">' . esc_html__('Minimum 60 seconds recommended', 'woo-update-api') . '</p>';
    }

    public function render_reconnect_time_field() {
        $settings = get_option($this->settings_name);
        echo '<input type="number" min="500" name="' . esc_attr($this->settings_name) . '[reconnect_time]" value="' . esc_attr($settings['reconnect_time'] ?? 3600) . '">';
        echo '<p class="description">' . esc_html__('Minimum 500 seconds recommended', 'woo-update-api') . '</p>';
    }


}