<?php
namespace Woo_Update_API\Admin;

use Woo_Update_API\API_Error_Manager;

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
        // Clear cache on settings update
        add_action('update_option_woo_update_api_settings', [$this, 'clear_api_cache']);


    }

    public function clear_api_cache() {
        delete_transient('woo_update_api_cached_data');
        
        // If using multiple cache keys
        delete_transient('woo_update_api_product_cache');
        delete_transient('woo_update_api_inventory_cache');
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

        register_setting(
        'woo_update_api_settings_group',
        'woo_update_api_settings',
        array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
            'default' => array(
                // Add the new default
                'disable_fallback' => 'no'
            )
        )
    );

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


        // Add the new settings section if needed
    add_settings_section(
        'woo_update_api_advanced',
        __('Advanced Settings', 'woo-update-api'),
        null,
        'woo-update-api'
    );
    
        // Add the new fields
    add_settings_field(
        'disable_fallback',
        __('Fallback Behavior', 'woo-update-api'),
        array($this, 'render_disable_fallback_field'),
        'woo-update-api',
        'woo_update_api_advanced'
    );
    
    add_settings_field(
        'reconnect_button',
        __('API Connection', 'woo-update-api'),
        array($this, 'render_reconnect_button'),
        'woo-update-api',
        'woo_update_api_advanced'
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


    public function render_disable_fallback_field() {
    $settings = get_option('woo_update_api_settings');
    $value = $settings['disable_fallback'] ?? 'no';
    ?>
    <label>
        <input type="checkbox" name="woo_update_api_settings[disable_fallback]" value="yes" <?php checked('yes', $value); ?>>
        <?php _e('Disable fallback to cached data', 'woo-update-api'); ?>
    </label>
    <p class="description">
        <?php _e('When enabled, errors will show instead of cached data when API fails', 'woo-update-api'); ?>
    </p>
    <?php
}

public function render_reconnect_button() {
    ?>
    <button type="button" id="woo_update_api_reconnect" class="button button-secondary">
        <?php _e('Reconnect Now', 'woo-update-api'); ?>
    </button>
    <p class="description">
        <?php _e('Force reconnect to API and reset error counter', 'woo-update-api'); ?>
    </p>
    <div id="woo_update_api_status" style="margin-top: 10px;">
        <?php $this->display_api_status(); ?>
    </div>
    <?php
}

public function display_api_status() {
    $error_manager = new API_Error_Manager;
    $status = $error_manager->get_status();
    ?>
    <div class="notice notice-<?php echo $status['fallback_active'] ? 'warning' : 'success'; ?>" style="display: inline-block; padding: 5px 10px;">
        <p>
            <?php if ($status['fallback_active']): ?>
                <strong><?php _e('Fallback Active', 'woo-update-api'); ?></strong> -
                <?php printf(__('%d of %d errors', 'woo-update-api'), $status['errors'], $status['threshold']); ?>
            <?php else: ?>
                <strong><?php _e('API Connected', 'woo-update-api'); ?></strong> -
                <?php printf(__('%d recent errors', 'woo-update-api'), $status['errors']); ?>
            <?php endif; ?>
        </p>
    </div>
    <?php
}

public function sanitize_settings($input) {
    $output = array();
    
    // Sanitize API Key
    if (isset($input['api_key'])) {
        $output['api_key'] = sanitize_text_field($input['api_key']);
    }
    
    // Sanitize API URL
    if (isset($input['api_url'])) {
        $output['api_url'] = esc_url_raw($input['api_url']);
    }
    
    // Sanitize Checkbox
    $output['disable_fallback'] = isset($input['disable_fallback']) ? 'yes' : 'no';
    
    // You can add validation here
    if (empty($output['api_key'])) {
        add_settings_error(
            'woo_update_api_messages',
            'woo_update_api_message',
            __('API Key is required', 'woo-update-api'),
            'error'
        );
    }
    
    return $output;
}

}