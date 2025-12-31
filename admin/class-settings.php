<?php
namespace Woo_Update_API\Admin;

use Woo_Update_API\API_Handler;
use Woo_Update_API\API_Error_Manager;

defined('ABSPATH') || exit;

class Settings
{
    private static $instance = null;
    private $settings_group = 'woo_update_api_settings_group';
    private $settings_name = 'woo_update_api_settings';
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
        
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        
        // Clear cache on settings update
        add_action('update_option_' . $this->settings_name, [$this, 'clear_api_cache'], 10, 2);
    }

    public function clear_api_cache($old_value, $new_value)
    {
        // Clear all product caches when settings change
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_woo_update_api_product_%',
                '_transient_timeout_woo_update_api_product_%'
            )
        );
        
        // Also clear fallback mode
        delete_transient('woo_update_api_fallback_mode');
        delete_transient('woo_update_api_fallback_start');
        
        // Reset error counter
        $this->error_manager->reset_errors();
    }

    public function add_settings_page()
    {
        add_options_page(
            __('WooCommerce Update API Settings', 'woo-update-api'),
            __('WC Update API', 'woo-update-api'),
            'manage_options',
            'woo-update-api',
            [$this, 'render_settings_page']
        );
    }

    public function admin_scripts($hook)
    {
        if ($hook !== 'settings_page_woo-update-api') {
            return;
        }

        wp_enqueue_script(
            'woo-update-api-settings',
            WOO_UPDATE_API_URL . 'assets/js/settings.js',
            ['jquery'],
            WOO_UPDATE_API_VERSION,
            true
        );

        wp_localize_script('woo-update-api-settings', 'woo_update_api_settings', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_update_api_nonce'),
            'i18n' => [
                'testing_connection' => __('Testing connection...', 'woo-update-api'),
                'connection_success' => __('Connection successful!', 'woo-update-api'),
                'connection_failed' => __('Connection failed:', 'woo-update-api'),
                'reconnecting' => __('Reconnecting...', 'woo-update-api'),
                'reconnect_success' => __('Reconnected successfully!', 'woo-update-api'),
                'reconnect_failed' => __('Reconnect failed:', 'woo-update-api'),
                'clearing_cache' => __('Clearing cache...', 'woo-update-api'),
                'cache_cleared' => __('Cache cleared!', 'woo-update-api')
            ]
        ]);
    }

    public function register_settings()
    {
        register_setting(
            $this->settings_group,
            $this->settings_name,
            [$this, 'sanitize_settings']
        );

        // Main settings section
        add_settings_section(
            'woo_update_api_main',
            __('API Connection Settings', 'woo-update-api'),
            [$this, 'render_main_section'],
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
            [$this, 'render_reconnect_time_field'],
            'woo-update-api',
            'woo_update_api_main'
        );

        // Status section
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

        // Advanced section
        add_settings_section(
            'woo_update_api_advanced',
            __('Advanced Settings', 'woo-update-api'),
            [$this, 'render_advanced_section'],
            'woo-update-api'
        );

        add_settings_field(
            'disable_fallback',
            __('Fallback Behavior', 'woo-update-api'),
            [$this, 'render_disable_fallback_field'],
            'woo-update-api',
            'woo_update_api_advanced'
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'woo-update-api'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields($this->settings_group);
                do_settings_sections('woo-update-api');
                submit_button(__('Save Settings', 'woo-update-api'));
                ?>
            </form>
            
            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('API Test & Tools', 'woo-update-api'); ?></h2>
                <p>
                    <button type="button" id="woo_update_api_test_connection" class="button button-secondary">
                        <?php _e('Test API Connection', 'woo-update-api'); ?>
                    </button>
                    <button type="button" id="woo_update_api_reconnect" class="button button-secondary">
                        <?php _e('Reconnect & Reset', 'woo-update-api'); ?>
                    </button>
                    <button type="button" id="woo_update_api_clear_cache" class="button button-secondary">
                        <?php _e('Clear All Cache', 'woo-update-api'); ?>
                    </button>
                </p>
                <div id="woo_update_api_test_result" style="margin-top: 10px;"></div>
            </div>
        </div>
        <?php
    }

    public function render_main_section()
    {
        echo '<p>' . esc_html__('Configure your external API connection details.', 'woo-update-api') . '</p>';
    }

    public function render_api_url_field()
    {
        $settings = get_option($this->settings_name, []);
        $value = esc_attr($settings['api_url'] ?? '');
        echo '<input type="url" class="regular-text" name="' . esc_attr($this->settings_name) . '[api_url]" value="' . $value . '" placeholder="https://api.example.com/products" required>';
        echo '<p class="description">' . esc_html__('Full URL to your API endpoint', 'woo-update-api') . '</p>';
    }

    public function render_api_key_field()
    {
        $settings = get_option($this->settings_name, []);
        $value = esc_attr($settings['api_key'] ?? '');
        echo '<input type="password" class="regular-text" name="' . esc_attr($this->settings_name) . '[api_key]" value="' . $value . '" required>';
        echo '<p class="description">' . esc_html__('Your API authentication key', 'woo-update-api') . '</p>';
    }

    public function render_cache_time_field()
    {
        $settings = get_option($this->settings_name, []);
        $value = esc_attr($settings['cache_time'] ?? 300);
        echo '<input type="number" min="60" step="60" class="small-text" name="' . esc_attr($this->settings_name) . '[cache_time]" value="' . $value . '">';
        echo '<p class="description">' . esc_html__('How long to cache API responses (in seconds). Minimum 60 seconds.', 'woo-update-api') . '</p>';
    }

    public function render_reconnect_time_field()
    {
        $settings = get_option($this->settings_name, []);
        $value = esc_attr($settings['reconnect_time'] ?? 3600);
        echo '<input type="number" min="300" step="300" class="small-text" name="' . esc_attr($this->settings_name) . '[reconnect_time]" value="' . $value . '">';
        echo '<p class="description">' . esc_html__('How long to stay in fallback mode before retrying (in seconds). Minimum 300 seconds.', 'woo-update-api') . '</p>';
    }

    public function render_status_section()
    {
        echo '<p>' . esc_html__('Current status of your API connection.', 'woo-update-api') . '</p>';
    }

    public function render_connection_status()
    {
        $status = $this->error_manager->get_status();
        $fallback_active = $this->api_handler->is_in_fallback_mode();
        ?>
        <div id="woo_update_api_current_status">
            <div class="notice notice-<?php echo $fallback_active ? 'warning' : 'success'; ?> inline" style="margin: 0; padding: 10px;">
                <p>
                    <?php if ($fallback_active): ?>
                        <strong><?php _e('⚠️ Fallback Mode Active', 'woo-update-api'); ?></strong><br>
                        <?php printf(__('API is currently unavailable. Using WooCommerce default data. Error count: %d/%d', 'woo-update-api'), 
                            $status['errors'], $status['threshold']); ?>
                    <?php else: ?>
                        <strong><?php _e('✅ API Connected', 'woo-update-api'); ?></strong><br>
                        <?php printf(__('Recent errors: %d/%d', 'woo-update-api'), $status['errors'], $status['threshold']); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php
    }

    public function render_advanced_section()
    {
        echo '<p>' . esc_html__('Advanced configuration options.', 'woo-update-api') . '</p>';
    }

    public function render_disable_fallback_field()
    {
        $settings = get_option($this->settings_name, []);
        $value = $settings['disable_fallback'] ?? 'no';
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->settings_name); ?>[disable_fallback]" value="yes" <?php checked('yes', $value); ?>>
            <?php _e('Disable fallback to cached data', 'woo-update-api'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, API errors will show immediately instead of using cached data. Not recommended for production.', 'woo-update-api'); ?>
        </p>
        <?php
    }

    public function sanitize_settings($input)
    {
        $output = [];
        
        // Sanitize API URL
        if (isset($input['api_url'])) {
            $url = esc_url_raw(trim($input['api_url']));
            if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
                add_settings_error(
                    $this->settings_name,
                    'invalid_url',
                    __('Please enter a valid API URL.', 'woo-update-api')
                );
            } else {
                $output['api_url'] = $url;
            }
        }
        
        // Sanitize API Key
        if (isset($input['api_key'])) {
            $output['api_key'] = sanitize_text_field(trim($input['api_key']));
            if (empty($output['api_key'])) {
                add_settings_error(
                    $this->settings_name,
                    'empty_api_key',
                    __('API Key is required.', 'woo-update-api')
                );
            }
        }
        
        // Sanitize cache time
        if (isset($input['cache_time'])) {
            $cache_time = absint($input['cache_time']);
            $output['cache_time'] = $cache_time < 60 ? 60 : $cache_time;
        } else {
            $output['cache_time'] = 300;
        }
        
        // Sanitize reconnect time
        if (isset($input['reconnect_time'])) {
            $reconnect_time = absint($input['reconnect_time']);
            $output['reconnect_time'] = $reconnect_time < 300 ? 300 : $reconnect_time;
        } else {
            $output['reconnect_time'] = 3600;
        }
        
        // Sanitize checkbox
        $output['disable_fallback'] = isset($input['disable_fallback']) ? 'yes' : 'no';
        
        return $output;
    }
}