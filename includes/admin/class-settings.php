<?php
namespace Woo_Update_API\Admin;

defined('ABSPATH') || exit;

class Settings {
    private static $instance = null;
    private $settings_group = 'woo_update_api_settings_group';
    private $settings_name = 'woo_update_api_settings';
    private $menu_slug = 'woo-update-api';

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page'], 20);
        add_action('admin_init', [$this, 'register_settings'], 20);
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __('Update API Settings', 'woo-update-api'),
            __('Update API', 'woo-update-api'),
            'manage_options',
            $this->menu_slug,
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(
            $this->settings_group,
            $this->settings_name,
            ['sanitize_callback' => [$this, 'sanitize_settings']]
        );

        add_settings_section(
            'woo_update_api_section',
            __('API Connection Settings', 'woo-update-api'),
            [$this, 'render_section'],
            $this->menu_slug
        );

        add_settings_field(
            'api_url',
            __('API Endpoint URL', 'woo-update-api'),
            [$this, 'render_api_url_field'],
            $this->menu_slug,
            'woo_update_api_section'
        );

        add_settings_field(
            'api_key',
            __('API Key', 'woo-update-api'),
            [$this, 'render_api_key_field'],
            $this->menu_slug,
            'woo_update_api_section'
        );

        add_settings_field(
            'cache_time',
            __('Cache Duration (seconds)', 'woo-update-api'),
            [$this, 'render_cache_time_field'],
            $this->menu_slug,
            'woo_update_api_section'
        );
    }

    public function sanitize_settings($input) {
        $output = [];
        
        if (isset($input['api_url'])) {
            $output['api_url'] = esc_url_raw(trim($input['api_url']));
        }
        
        if (isset($input['api_key'])) {
            $output['api_key'] = sanitize_text_field(trim($input['api_key']));
        }
        
        if (isset($input['cache_time'])) {
            $output['cache_time'] = absint($input['cache_time']);
            if ($output['cache_time'] < 60) {
                $output['cache_time'] = 60;
            }
        }
        
        return $output;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        settings_errors('woo_update_api_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->settings_group);
                do_settings_sections($this->menu_slug);
                submit_button(__('Save Settings', 'woo-update-api'));
                ?>
            </form>
        </div>
        <?php
    }

    public function render_section() {
        echo '<p>' . esc_html__('Configure your external API connection details.', 'woo-update-api') . '</p>';
    }

    public function render_api_url_field() {
        $settings = get_option($this->settings_name, []);
        $value = $settings['api_url'] ?? '';
        echo '<input type="url" class="regular-text" name="' . esc_attr($this->settings_name) . '[api_url]" value="' . esc_attr($value) . '" required>';
    }

    public function render_api_key_field() {
        $settings = get_option($this->settings_name, []);
        $value = $settings['api_key'] ?? '';
        echo '<input type="password" class="regular-text" name="' . esc_attr($this->settings_name) . '[api_key]" value="' . esc_attr($value) . '" required>';
    }

    public function render_cache_time_field() {
        $settings = get_option($this->settings_name, []);
        $value = $settings['cache_time'] ?? 300;
        echo '<input type="number" min="60" step="1" name="' . esc_attr($this->settings_name) . '[cache_time]" value="' . esc_attr($value) . '">';
        echo '<p class="description">' . esc_html__('Minimum 60 seconds recommended', 'woo-update-api') . '</p>';
    }
}