<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package ModifyLogin
 * @since 2.0.0
 */

namespace ModifyLogin\Admin;

defined('ABSPATH') || exit;

/**
 * The admin-specific functionality of the plugin.
 */
class Modify_Login_Admin {
    /**
     * The ID of this plugin.
     *
     * @var string
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @var string
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @param string $plugin_name The name of this plugin.
     * @param string $version     The version of this plugin.
     */
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add color picker support
        add_action('admin_enqueue_scripts', array($this, 'enqueue_color_picker'));

        // Initialize AJAX handler
        new Modify_Login_Admin_Ajax();
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles($hook) {
        // Register all styles first
        wp_register_style(
            'modify-login-tailwind',
            MODIFY_LOGIN_URL . 'assets/dist/admin/css/tailwind.min.css',
            array(),
            $this->version
        );

        wp_register_style(
            'modify-login-settings',
            MODIFY_LOGIN_URL . 'assets/dist/admin/css/settings.min.css',
            array('modify-login-tailwind'),
            $this->version
        );

        wp_register_style(
            'modify-login-logs',
            MODIFY_LOGIN_URL . 'assets/dist/admin/css/login-logs.min.css',
            array('modify-login-tailwind'),
            $this->version
        );

        // Always load the tailwind styles
        wp_enqueue_style('modify-login-tailwind');

        // Load specific styles based on page
        if ($hook === 'toplevel_page_modify-login') {
            wp_enqueue_style('modify-login-settings');
        } elseif ($hook === 'modify-login_page_modify-login-logs') {
            wp_enqueue_style('modify-login-logs');
        }
    }

    /**
     * Register the JavaScript for the admin area.
     */
    public function enqueue_scripts() {
        // Enqueue color picker first
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // Then enqueue our admin script with color picker as a dependency
        wp_enqueue_script(
            $this->plugin_name,
            MODIFY_LOGIN_URL . 'assets/dist/admin/js/settings.min.js',
            array('jquery', 'wp-color-picker'),
            $this->version,
            true
        );

        // Add media uploader scripts
        wp_enqueue_media();
        
        wp_localize_script($this->plugin_name, 'modifyLoginAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('modify_login_admin_nonce')
        ));
    }

    /**
     * Enqueue color picker scripts and styles
     */
    public function enqueue_color_picker($hook) {
        // Only load on our plugin's builder page
        if ($hook !== 'modify-login_page_modify-login-builder') {
            return;
        }
        
        // Enqueue the WordPress component libraries for Gutenberg color picker
        wp_enqueue_script('wp-components');
        wp_enqueue_script('wp-element');
        wp_enqueue_script('wp-i18n');
        wp_enqueue_script('wp-data');
        
        // Enqueue the styles for components
        wp_enqueue_style('wp-components');
    }

    /**
     * Add plugin admin menu.
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Modify Login', 'modify-login'),
            __('Modify Login', 'modify-login'),
            'manage_options',
            'modify-login',
            array($this, 'display_admin_page'),
            'dashicons-lock',
            30
        );

        add_submenu_page(
            'modify-login',
            __('Settings', 'modify-login'),
            __('Settings', 'modify-login'),
            'manage_options',
            'modify-login',
            array($this, 'display_admin_page')
        );

        add_submenu_page(
            'modify-login',
            __('Login Logs', 'modify-login'),
            __('Login Logs', 'modify-login'),
            'manage_options',
            'modify-login-logs',
            array($this, 'display_logs_page')
        );

        // Design tab removed as requested
    }

    /**
     * Display the admin page.
     */
    public function display_admin_page() {
        $success_message = '';
        $error_message = '';
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';

        // Check if form was submitted
        if (isset($_POST['modify_login_save_settings_submit'])) {
            // Verify nonce
            if (isset($_POST['modify_login_settings_nonce']) && wp_verify_nonce($_POST['modify_login_settings_nonce'], 'modify_login_save_settings')) {
                try {
                    // Get the active tab from the submission
                    $active_tab = isset($_POST['active_tab']) ? sanitize_text_field($_POST['active_tab']) : 'general';
                    
                    // Save settings directly
                    $settings = array(
                        // General Settings
                        'login_redirect_url' => isset($_POST['login_redirect_url']) ? esc_url_raw($_POST['login_redirect_url']) : '',
                        'logout_redirect_url' => isset($_POST['logout_redirect_url']) ? esc_url_raw($_POST['logout_redirect_url']) : '',
                        
                        // Security Settings
                        'login_endpoint' => isset($_POST['login_endpoint']) ? sanitize_text_field($_POST['login_endpoint']) : '',
                        'enable_redirect' => isset($_POST['enable_redirect']) ? true : false,
                        'redirect_url' => isset($_POST['redirect_url']) ? esc_url_raw($_POST['redirect_url']) : '',
                        
                        // reCAPTCHA Settings
                        'enable_recaptcha' => isset($_POST['enable_recaptcha']) ? 'yes' : 'no',
                        'recaptcha_site_key' => isset($_POST['recaptcha_site_key']) ? sanitize_text_field($_POST['recaptcha_site_key']) : '',
                        'recaptcha_secret_key' => isset($_POST['recaptcha_secret_key']) ? sanitize_text_field($_POST['recaptcha_secret_key']) : '',
                    );
                    
                    // Update settings in database
                    update_option('modify_login_settings', $settings);
                    
                    // Set success message
                    $success_message = __('Settings saved successfully.', 'modify-login');
                } catch (Exception $e) {
                    // Set error message if something went wrong
                    $error_message = $e->getMessage();
                }
            } else {
                // Nonce verification failed
                $error_message = __('Security verification failed. Please try again.', 'modify-login');
            }
        }

        // Get current settings
        $settings = $this->get_settings();

        // Include the admin page template
        include MODIFY_LOGIN_PATH . 'templates/admin/settings.php';
    }

    /**
     * Display the logs page.
     */
    public function display_logs_page() {
        // Include the logs page template
        include MODIFY_LOGIN_PATH . 'templates/admin/logs.php';
    }

    /**
     * Display the builder page.
     */
    public function display_builder_page() {
        // Force cache invalidation with current timestamp
        $version = MODIFY_LOGIN_VERSION . '.' . time();
        
        // Enqueue builder scripts and styles
        wp_enqueue_style('modify-login-builder', MODIFY_LOGIN_URL . 'assets/dist/admin/css/builder.min.css', array(), $version);
        wp_enqueue_script('modify-login-builder', MODIFY_LOGIN_URL . 'assets/dist/admin/js/builder.min.js', array('jquery', 'wp-components', 'wp-element', 'wp-i18n'), $version, true);

        // Localize the builder script
        wp_localize_script('modify-login-builder', 'modifyLoginBuilder', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('modify_login_builder_nonce'),
            'colors' => array(
                'background' => get_option('modify_login_background_color', '#ffffff'),
                'form' => get_option('modify_login_form_background', '#ffffff'),
                'button' => get_option('modify_login_button_color', '#0073aa'),
                'buttonText' => get_option('modify_login_button_text_color', '#ffffff'),
            )
        ));

        // Get current settings
        $settings = array(
            'background_color' => get_option('modify_login_background_color', '#ffffff'),
            'background_image' => get_option('modify_login_background_image', ''),
            'background_size' => get_option('modify_login_background_size', 'cover'),
            'background_position' => get_option('modify_login_background_position', 'center center'),
            'background_repeat' => get_option('modify_login_background_repeat', 'no-repeat'),
            'background_opacity' => get_option('modify_login_background_opacity', 1),
            'logo_url' => get_option('modify_login_logo_url', ''),
            'form_background' => get_option('modify_login_form_background', '#ffffff'),
            'form_border_radius' => get_option('modify_login_form_border_radius', '4px'),
            'form_padding' => get_option('modify_login_form_padding', '20px'),
            'button_color' => get_option('modify_login_button_color', '#0073aa'),
            'button_text_color' => get_option('modify_login_button_text_color', '#ffffff'),
            'custom_css' => get_option('modify_login_custom_css', ''),
            'label_color' => get_option('modify_login_label_color', '#444444'),
            'link_color' => get_option('modify_login_link_color', '#0073aa'),
            'link_hover_color' => get_option('modify_login_link_hover_color', '#00a0d2'),
        );

        // Include the builder template
        include MODIFY_LOGIN_PATH . 'templates/admin/builder.php';
    }

    /**
     * Save plugin settings.
     */
    private function save_settings() {
        // General settings
        $settings = array(
            // General Settings
            'login_redirect_url' => esc_url_raw($_POST['login_redirect_url']),
            'logout_redirect_url' => esc_url_raw($_POST['logout_redirect_url']),
            
            // Security Settings
            'enable_recaptcha' => isset($_POST['enable_recaptcha']) ? 'yes' : 'no',
            'recaptcha_site_key' => sanitize_text_field($_POST['recaptcha_site_key']),
            'recaptcha_secret_key' => sanitize_text_field($_POST['recaptcha_secret_key']),
            'enable_social_login' => isset($_POST['enable_social_login']) ? 'yes' : 'no',
            'enable_2fa' => isset($_POST['enable_2fa']) ? 'yes' : 'no',
            '2fa_method' => sanitize_text_field($_POST['2fa_method']),
            
            // Design Settings
            'enable_custom_colors' => isset($_POST['enable_custom_colors']) ? 'yes' : 'no',
            'primary_color' => sanitize_hex_color($_POST['primary_color']),
            'secondary_color' => sanitize_hex_color($_POST['secondary_color']),
            'text_color' => sanitize_hex_color($_POST['text_color']),
            'background_color' => sanitize_hex_color($_POST['background_color']),
            
            // Email Settings
            'enable_login_notifications' => isset($_POST['enable_login_notifications']) ? 'yes' : 'no',
            'notification_email' => sanitize_email($_POST['notification_email']),
            'email_from_name' => sanitize_text_field($_POST['email_from_name']),
            'email_from_address' => sanitize_email($_POST['email_from_address']),
        );

        // Save all settings at once
        update_option('modify_login_settings', $settings);

        // Add success message
        add_settings_error(
            'modify_login_messages',
            'modify_login_message',
            __('Settings Saved', 'modify-login'),
            'updated'
        );
    }

    /**
     * Get plugin settings
     *
     * @return array
     */
    public function get_settings() {
        $defaults = array(
            // General Settings
            'login_redirect_url' => '',
            'logout_redirect_url' => '',
            
            // Security Settings
            'login_endpoint' => '',
            'enable_redirect' => false,
            'redirect_url' => '',
            'enable_recaptcha' => 'no',
            'recaptcha_site_key' => '',
            'recaptcha_secret_key' => '',
            'enable_social_login' => 'no',
            'social_login_providers' => array(),
            'enable_2fa' => 'no',
            '2fa_method' => 'email',
            'login_attempts_limit' => 5,
            'login_lockout_time' => 30,
            'enable_ip_restriction' => 'no',
            'allowed_ips' => array(),
            'enable_brute_force_protection' => 'yes',
            'enable_password_strength_meter' => 'yes',
            'minimum_password_strength' => 'medium',
            
            // Design Settings
            'enable_custom_branding' => 'no',
            'custom_logo_url' => '',
            'custom_background_url' => '',
            'custom_favicon_url' => '',
            'enable_custom_colors' => 'no',
            'primary_color' => '#0073aa',
            'secondary_color' => '#23282d',
            'text_color' => '#1d2327',
            'background_color' => '#f0f0f1',
            
            // Email Settings
            'enable_login_notifications' => 'yes',
            'notification_email' => get_option('admin_email'),
            'email_from_name' => get_bloginfo('name'),
            'email_from_address' => get_option('admin_email'),
        );

        $settings = get_option('modify_login_settings', array());
        return wp_parse_args($settings, $defaults);
    }
} 