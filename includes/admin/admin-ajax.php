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
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'modify_login_builder_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Get and sanitize the settings
        $settings = array(
            'background_color' => isset($_POST['background_color']) ? sanitize_hex_color($_POST['background_color']) : '#ffffff',
            'background_image' => isset($_POST['background_image']) ? esc_url_raw($_POST['background_image']) : '',
            'background_size' => isset($_POST['background_size']) ? sanitize_text_field($_POST['background_size']) : 'cover',
            'background_position' => isset($_POST['background_position']) ? sanitize_text_field($_POST['background_position']) : 'center center',
            'background_repeat' => isset($_POST['background_repeat']) ? sanitize_text_field($_POST['background_repeat']) : 'no-repeat',
            'background_opacity' => isset($_POST['background_opacity']) ? floatval($_POST['background_opacity']) : 1,
            'logo_url' => isset($_POST['logo_url']) ? esc_url_raw($_POST['logo_url']) : '',
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
        foreach ($settings as $key => $value) {
            update_option('modify_login_' . $key, $value);
        }

        wp_send_json_success('Settings saved successfully');
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
            'login_redirect_url' => isset($_POST['login_redirect_url']) ? esc_url_raw($_POST['login_redirect_url']) : '',
            'logout_redirect_url' => isset($_POST['logout_redirect_url']) ? esc_url_raw($_POST['logout_redirect_url']) : '',
            'enable_recaptcha' => isset($_POST['enable_recaptcha']) ? 'yes' : 'no',
            'recaptcha_site_key' => isset($_POST['recaptcha_site_key']) ? sanitize_text_field($_POST['recaptcha_site_key']) : '',
            'recaptcha_secret_key' => isset($_POST['recaptcha_secret_key']) ? sanitize_text_field($_POST['recaptcha_secret_key']) : '',
        );

        // Save settings
        update_option('modify_login_settings', $settings);

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
     * Reset all builder settings to default values
     */
    public function reset_builder_settings() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'modify_login_builder_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Default settings
        $defaults = array(
            'background_color' => '#ffffff',
            'background_image' => '',
            'background_size' => 'cover',
            'background_position' => 'center center',
            'background_repeat' => 'no-repeat',
            'background_opacity' => 1,
            'logo_url' => '',
            'logo_width' => '84px',
            'logo_height' => '84px',
            'logo_position' => 'center',
            'form_background' => '#ffffff',
            'form_border_radius' => '4px',
            'form_padding' => '20px',
            'button_color' => '#0073aa',
            'button_text_color' => '#ffffff',
            'custom_css' => '',
            'label_color' => '#444444',
            'link_color' => '#0073aa',
            'link_hover_color' => '#00a0d2',
        );

        // Delete and reset all builder settings
        foreach ($defaults as $key => $value) {
            delete_option('modify_login_' . $key);
            if (!empty($value)) {
                update_option('modify_login_' . $key, $value);
            }
        }

        wp_send_json_success('Settings reset successfully');
    }
} 