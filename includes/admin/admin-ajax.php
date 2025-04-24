<?php
/**
 * Admin AJAX handlers for Modify Login plugin
 *
 * @package ModifyLogin
 * @since 2.0.0
 */

namespace ModifyLogin\Admin;

defined('ABSPATH') || exit;

/**
 * Class to handle all admin AJAX requests
 */
class Modify_Login_Admin_Ajax {
    /**
     * Initialize the class and set up AJAX handlers
     */
    public function __construct() {
        // Builder related AJAX handlers
        add_action('wp_ajax_modify_login_save_builder_settings', array($this, 'save_builder_settings'));
        add_action('wp_ajax_modify_login_reset_builder_settings', array($this, 'reset_builder_settings'));
        add_action('wp_ajax_modify_login_upload_media', array($this, 'handle_media_upload'));
        
        // Settings related AJAX handlers
        add_action('wp_ajax_modify_login_save_settings', array($this, 'save_settings'));
        add_action('wp_ajax_modify_login_reset_settings', array($this, 'reset_settings'));
        
        // Logs related AJAX handlers
        add_action('wp_ajax_modify_login_get_logs', array($this, 'get_logs'));
        add_action('wp_ajax_modify_login_clear_logs', array($this, 'clear_logs'));
    }

    /**
     * Handle saving builder settings
     */
    public function save_builder_settings() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'modify_login_builder_nonce')) {
            wp_send_json_error(array(
                'message' => 'Security verification failed. Please refresh the page and try again.',
                'code' => 'invalid_nonce'
            ));
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => 'You do not have permission to perform this action.',
                'code' => 'insufficient_permissions'
            ));
        }

        // Get and sanitize the settings
        $settings = array(
            'background_color' => isset($_POST['background_color']) ? sanitize_hex_color($_POST['background_color']) : '#ffffff',
            'background_image' => isset($_POST['background_image']) ? esc_url_raw($_POST['background_image']) : '',
            'background_size' => isset($_POST['background_size']) ? sanitize_text_field($_POST['background_size']) : 'cover',
            'background_position' => isset($_POST['background_position']) ? sanitize_text_field($_POST['background_position']) : 'center center',
            'background_repeat' => isset($_POST['background_repeat']) ? sanitize_text_field($_POST['background_repeat']) : 'no-repeat',
            'background_opacity' => isset($_POST['background_opacity']) ? (float) $_POST['background_opacity'] : 1,
            'logo_url' => isset($_POST['logo_url']) ? esc_url_raw($_POST['logo_url']) : '',
            'logo_width' => isset($_POST['logo_width']) ? sanitize_text_field($_POST['logo_width']) : '',
            'logo_height' => isset($_POST['logo_height']) ? sanitize_text_field($_POST['logo_height']) : '',
            'form_background' => isset($_POST['form_background']) ? sanitize_hex_color($_POST['form_background']) : '#ffffff',
            'form_border_radius' => isset($_POST['form_border_radius']) ? sanitize_text_field($_POST['form_border_radius']) : '4px',
            'form_padding' => isset($_POST['form_padding']) ? sanitize_text_field($_POST['form_padding']) : '20px',
            'button_color' => isset($_POST['button_color']) ? sanitize_hex_color($_POST['button_color']) : '#0073aa',
            'button_text_color' => isset($_POST['button_text_color']) ? sanitize_hex_color($_POST['button_text_color']) : '#ffffff',
            'custom_css' => isset($_POST['custom_css']) ? wp_strip_all_tags($_POST['custom_css']) : '',
            'label_color' => isset($_POST['label_color']) ? sanitize_hex_color($_POST['label_color']) : '#444444',
            'link_color' => isset($_POST['link_color']) ? sanitize_hex_color($_POST['link_color']) : '#0073aa',
            'link_hover_color' => isset($_POST['link_hover_color']) ? sanitize_hex_color($_POST['link_hover_color']) : '#00a0d2',
        );

        // Save each setting
        $saved_count = 0;
        foreach ($settings as $key => $value) {
            $option_name = 'modify_login_' . $key;
            $result = update_option($option_name, $value);
            if ($result) {
                $saved_count++;
            }
        }

        if ($saved_count === count($settings)) {
            wp_send_json_success(array(
                'message' => 'Settings saved successfully',
                'count' => $saved_count
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'Some settings may not have been saved. Please verify your changes.',
                'count' => $saved_count
            ));
        }
    }

    /**
     * Handle media upload
     */
    public function handle_media_upload() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'modify_login_builder_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Check if file was uploaded
        if (!isset($_FILES['file'])) {
            wp_send_json_error('No file uploaded');
        }

        // Include WordPress media handling functions
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Handle the upload
        $attachment_id = media_handle_upload('file', 0);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error($attachment_id->get_error_message());
        }

        // Get the URL of the uploaded file
        $file_url = wp_get_attachment_url($attachment_id);

        wp_send_json_success(array(
            'url' => $file_url,
            'id' => $attachment_id
        ));
    }

    /**
     * Handle saving general settings
     */
    public function save_settings() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'modify_login_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Get and sanitize settings
        $settings = array(
            // General settings
            'login_redirect_url' => isset($_POST['login_redirect_url']) ? esc_url_raw($_POST['login_redirect_url']) : '',
            'logout_redirect_url' => isset($_POST['logout_redirect_url']) ? esc_url_raw($_POST['logout_redirect_url']) : '',
            
            // Login Security settings
            'login_endpoint' => isset($_POST['login_endpoint']) ? sanitize_text_field($_POST['login_endpoint']) : '',
            'enable_redirect' => isset($_POST['enable_redirect']) ? true : false,
            'redirect_url' => isset($_POST['redirect_url']) ? esc_url_raw($_POST['redirect_url']) : '',
            
            // reCAPTCHA settings
            'enable_recaptcha' => isset($_POST['enable_recaptcha']) ? 'yes' : 'no',
            'recaptcha_site_key' => isset($_POST['recaptcha_site_key']) ? sanitize_text_field($_POST['recaptcha_site_key']) : '',
            'recaptcha_secret_key' => isset($_POST['recaptcha_secret_key']) ? sanitize_text_field($_POST['recaptcha_secret_key']) : '',
        );

        // Save settings
        update_option('modify_login_settings', $settings);
        
        // If login endpoint changed, flag that we need to flush rewrite rules
        if (isset($_POST['login_endpoint'])) {
            $current_endpoint = get_option('modify_login_login_endpoint', '');
            if ($_POST['login_endpoint'] !== $current_endpoint) {
                update_option('modify_login_rewrite_rules_flushed', false);
            }
        }

        wp_send_json_success('Settings saved successfully');
    }

    /**
     * Handle resetting settings to defaults
     */
    public function reset_settings() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'modify_login_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Delete all plugin options
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'modify_login_%'");

        wp_send_json_success('Settings reset successfully');
    }

    /**
     * Handle getting login logs
     */
    public function get_logs() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'modify_login_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Get logs from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'modify_login_logs';
        
        $logs = $wpdb->get_results(
            "SELECT * FROM {$table_name} ORDER BY login_time DESC LIMIT 100"
        );

        wp_send_json_success($logs);
    }

    /**
     * Handle clearing login logs
     */
    public function clear_logs() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'modify_login_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Clear logs from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'modify_login_logs';
        
        $wpdb->query("TRUNCATE TABLE {$table_name}");

        wp_send_json_success('Logs cleared successfully');
    }

    /**
     * Reset builder settings
     */
    public function reset_builder_settings() {
        // Verify nonce with specific action name
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'modify_login_builder_nonce')) {
            wp_send_json_error(array(
                'message' => 'Security verification failed. Please refresh the page and try again.',
                'code' => 'invalid_nonce'
            ));
        }

        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => 'You do not have permission to perform this action.',
                'code' => 'insufficient_permissions'
            ));
        }

        // Default settings
        $defaults = array(
            'background_color' => '#f0f0f1',
            'background_image' => '',
            'background_position' => 'center',
            'background_size' => 'cover',
            'background_repeat' => 'no-repeat',
            'background_opacity' => '1',
            'logo_url' => '',
            'logo_width' => '',
            'logo_height' => '',
            'logo_link' => '',
            'logo_link_title' => '',
            'form_background_color' => '#ffffff',
            'form_text_color' => '#3c434a',
            'form_width' => '320px',
            'form_border_radius' => '4px',
            'form_padding' => '26px 24px 34px',
            'form_border' => '1px solid #c3c4c7',
            'form_shadow' => '0 1px 3px rgba(0, 0, 0, 0.04)',
            'input_background_color' => '#ffffff',
            'input_text_color' => '#3c434a',
            'input_border_color' => '#8c8f94',
            'input_border_radius' => '3px',
            'button_background_color' => '#2271b1',
            'button_text_color' => '#ffffff',
            'button_border_radius' => '3px',
            'custom_css' => '',
            // Maintain backward compatibility with old field names
            'form_background' => '#ffffff',
            'button_color' => '#2271b1',
            'label_color' => '#3c434a',
            'link_color' => '#0073aa',
            'link_hover_color' => '#00a0d2',
        );

        // Reset all settings
        foreach ($defaults as $key => $value) {
            update_option("modify_login_{$key}", $value);
        }

        // Send response with sanitized values
        wp_send_json_success(array(
            'message' => 'All settings have been reset to default values.',
            'defaults' => array_map('esc_attr', $defaults)
        ));
    }
} 