<?php
/**
 * The frontend-specific functionality of the plugin.
 *
 * @package ModifyLogin
 * @since 2.0.0
 */

namespace ModifyLogin\Frontend;

defined('ABSPATH') || exit;

/**
 * The frontend-specific functionality of the plugin.
 */
class Modify_Login_Frontend {
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

        error_log('Modify Login: Initializing frontend class');
        error_log('Modify Login: Registering login tracking hooks');

        // Add actions and filters
        add_action('login_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('login_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('login_head', array($this, 'custom_login_styles'));
        add_filter('login_headerurl', array($this, 'custom_login_logo_url'));
        add_filter('login_headertext', array($this, 'custom_login_logo_title'));
        add_action('login_form', array($this, 'add_recaptcha'));
        add_action('wp_authenticate_user', array($this, 'verify_recaptcha'), 10, 2);
        
        // Register login tracking hooks
        add_action('wp_login_failed', array($this, 'track_login_attempt'));
        add_action('wp_login', array($this, 'track_successful_login'), 10, 2);
        
        error_log('Modify Login: Hooks registered successfully');
    }

    /**
     * Register the stylesheets for the login page.
     */
    public function enqueue_styles() {
        // Frontend CSS loading removed as requested
    }

    /**
     * Register the JavaScript for the login page.
     */
    public function enqueue_scripts() {
    

        // Add reCAPTCHA script if enabled
        if ('yes' === get_option('modify_login_enable_recaptcha', 'no')) {
            wp_enqueue_script(
                'google-recaptcha',
                'https://www.google.com/recaptcha/api.js',
                array(),
                $this->version,
                false
            );
        }
    }

    /**
     * Add custom styles to the login page.
     */
    public function custom_login_styles() {
        // Get all saved settings without default values
        $background_color = get_option('modify_login_background_color');
        $background_image = get_option('modify_login_background_image');
        $background_size = get_option('modify_login_background_size');
        $background_position = get_option('modify_login_background_position');
        $background_repeat = get_option('modify_login_background_repeat');
        $background_opacity = get_option('modify_login_background_opacity', 1);
        
        $logo_url = get_option('modify_login_logo_url');
        $logo_width = get_option('modify_login_logo_width');
        $logo_height = get_option('modify_login_logo_height');
        $logo_position = get_option('modify_login_logo_position');
        
        $form_background = get_option('modify_login_form_background');
        $form_border_radius = get_option('modify_login_form_border_radius');
        $form_padding = get_option('modify_login_form_padding');
        
        $button_color = get_option('modify_login_button_color');
        $button_text_color = get_option('modify_login_button_text_color');
        
        // New color options
        $link_color = get_option('modify_login_link_color');
        $link_hover_color = get_option('modify_login_link_hover_color');
        $label_color = get_option('modify_login_label_color');
        
        $custom_css = get_option('modify_login_custom_css');

        // Output styles
        echo '<style type="text/css" id="modify-login-custom-css">';
        
        // Background styles
        echo 'body.login {';
        if (!empty($background_color)) {
            echo 'background-color: ' . esc_attr($background_color) . ';';
        }
        if (!empty($background_image)) {
            echo 'position: relative;'; // Add position relative for the pseudo-element
            echo '}';
            
            // Create a pseudo-element for the background image with opacity
            echo 'body.login::before {';
            echo 'content: "";';
            echo 'position: absolute;';
            echo 'top: 0;';
            echo 'left: 0;';
            echo 'width: 100%;';
            echo 'height: 100%;';
            echo 'background-image: url(' . esc_url($background_image) . ');';
            if (!empty($background_size)) {
                echo 'background-size: ' . esc_attr($background_size) . ';';
            }
            if (!empty($background_position)) {
                echo 'background-position: ' . esc_attr($background_position) . ';';
            }
            if (!empty($background_repeat)) {
                echo 'background-repeat: ' . esc_attr($background_repeat) . ';';
            }
            echo 'opacity: ' . esc_attr($background_opacity) . ';';
            echo 'z-index: -1;';
        } else {
            echo '}';
        }

        // Logo styles
        if (!empty($logo_url)) {
            echo '.login h1 a {';
            echo 'background-image: url(' . esc_url($logo_url) . ') !important;';
            if (!empty($logo_width)) {
                echo 'width: ' . esc_attr($logo_width) . ' !important;';
            }
            if (!empty($logo_height)) {
                echo 'height: ' . esc_attr($logo_height) . ' !important;';
            }
            echo 'background-size: contain !important;';
            echo 'background-position: center !important;';
            echo 'background-repeat: no-repeat !important;';
            echo 'text-indent: -9999px !important;';
            if (!empty($logo_position)) {
                echo 'text-align: ' . esc_attr($logo_position) . ' !important;';
            }
            echo 'margin: 0 auto 25px auto !important;';
            echo '}';
        }

        // Only output form styles if at least one property is set
        if (!empty($form_background) || !empty($form_border_radius) || !empty($form_padding)) {
            echo '.login form {';
            if (!empty($form_background)) {
                echo 'background: ' . esc_attr($form_background) . ' !important;';
            }
            if (!empty($form_border_radius)) {
                echo 'border-radius: ' . esc_attr($form_border_radius) . ' !important;';
            }
            if (!empty($form_padding)) {
                echo 'padding: ' . esc_attr($form_padding) . ' !important;';
            }
            echo 'box-shadow: 0 1px 3px rgba(0, 0, 0, 0.13);';
            echo '}';
        }

        // Form Label Color
        if (!empty($label_color)) {
            echo '.login form label {';
            echo 'color: ' . esc_attr($label_color) . ' !important;';
            echo '}';
        }
        
        // Button styles
        if (!empty($button_color) || !empty($button_text_color)) {
            echo '.wp-core-ui .button-primary {';
            if (!empty($button_color)) {
                echo 'background: ' . esc_attr($button_color) . ' !important;';
                echo 'border-color: ' . esc_attr($button_color) . ' !important;';
            }
            if (!empty($button_text_color)) {
                echo 'color: ' . esc_attr($button_text_color) . ' !important;';
            }
            echo 'text-decoration: none;';
            echo 'text-shadow: none;';
            echo '}';
        }

        // Only add hover styles if button color is set
        if (!empty($button_color)) {
            echo '.wp-core-ui .button-primary:hover, .wp-core-ui .button-primary:focus {';
            echo 'background: ' . esc_attr($this->adjust_brightness($button_color, -10)) . ' !important;';
            echo 'border-color: ' . esc_attr($this->adjust_brightness($button_color, -10)) . ' !important;';
            echo '}';
            
            // Message styling - only if button color is set
            echo '.login .message, .login .success {';
            echo 'border-left: 4px solid ' . esc_attr($button_color) . ';';
            echo '}';
        }
        
        // Link Colors
        if (!empty($link_color)) {
            echo '.login a, .login #nav a, .login #backtoblog a {';
            echo 'color: ' . esc_attr($link_color) . ' !important;';
            echo '}';
        }
        
        // Link Hover Colors
        if (!empty($link_hover_color)) {
            echo '.login a:hover, .login #nav a:hover, .login #backtoblog a:hover {';
            echo 'color: ' . esc_attr($link_hover_color) . ' !important;';
            echo '}';
        }
        
        // Add user's custom CSS
        if (!empty($custom_css)) {
            echo $custom_css;
        }

        echo '</style>';
    }

    /**
     * Change the login logo URL.
     *
     * @return string
     */
    public function custom_login_logo_url() {
        return home_url();
    }

    /**
     * Change the login logo title.
     *
     * @return string
     */
    public function custom_login_logo_title() {
        return get_bloginfo('name');
    }

    /**
     * Add reCAPTCHA to the login form.
     */
    public function add_recaptcha() {
        if ('yes' === get_option('modify_login_enable_recaptcha', 'no')) {
            $site_key = get_option('modify_login_recaptcha_site_key', '');
            if (!empty($site_key)) {
                echo '<div class="g-recaptcha" data-sitekey="' . esc_attr($site_key) . '"></div>';
            }
        }
    }

    /**
     * Verify reCAPTCHA on login.
     *
     * @param WP_User|WP_Error $user     WP_User or WP_Error object.
     * @param string          $password Password string.
     * @return WP_User|WP_Error
     */
    public function verify_recaptcha($user, $password) {
        if ('yes' !== get_option('modify_login_enable_recaptcha', 'no')) {
            return $user;
        }

        $secret_key = get_option('modify_login_recaptcha_secret_key', '');
        if (empty($secret_key)) {
            return $user;
        }

        if (!isset($_POST['g-recaptcha-response'])) {
            return new \WP_Error('recaptcha_missing', __('Please complete the reCAPTCHA verification.', 'modify-login'));
        }

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
            'body' => array(
                'secret' => $secret_key,
                'response' => $_POST['g-recaptcha-response'],
                'remoteip' => $_SERVER['REMOTE_ADDR'],
            ),
        ));

        if (is_wp_error($response)) {
            return $user;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!$body['success']) {
            return new \WP_Error('recaptcha_failed', __('reCAPTCHA verification failed. Please try again.', 'modify-login'));
        }

        return $user;
    }

    /**
     * Get location information from IP address.
     *
     * @param string $ip_address IP address.
     * @return array Location information.
     */
    private function get_location_info($ip_address) {
        $location = array(
            'country' => '',
            'city' => ''
        );

        // Get real client IP address
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        // Clean IP address (in case of multiple IPs in X-Forwarded-For)
        $ip = trim(explode(',', $ip)[0]);

        // Use IP Geolocation API
        $api_url = "http://ip-api.com/json/{$ip}";
        $response = wp_remote_get($api_url);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            
            if ($data && $data['status'] === 'success') {
                $location['country'] = $data['country'] ?? '';
                $location['city'] = $data['city'] ?? '';
            }
        }

        return $location;
    }

    /**
     * Track failed login attempts.
     *
     * @param string $username Username or email address.
     */
    public function track_login_attempt($username) {
        global $wpdb;
        
        error_log('Modify Login: Tracking failed login attempt for username: ' . $username);

        // Try to get user by username or email
        $user = get_user_by('login', $username);
        if (!$user) {
            $user = get_user_by('email', $username);
        }

        $user_id = $user ? $user->ID : 0;
        $attempted_username = $user_id === 0 ? $username : '';
        
        // Get location information
        $location = $this->get_location_info($_SERVER['REMOTE_ADDR']);
        
        error_log('Modify Login: User ID for failed attempt: ' . $user_id);
        error_log('Modify Login: IP Address: ' . $_SERVER['REMOTE_ADDR']);
        error_log('Modify Login: User Agent: ' . $_SERVER['HTTP_USER_AGENT']);

        // Log the failed attempt
        $result = $wpdb->insert(
            $wpdb->prefix . 'modify_login_logs',
            array(
                'user_id' => $user_id,
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'status' => 'failed',
                'attempted_username' => $attempted_username,
                'country' => $location['country'],
                'city' => $location['city']
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        error_log('Modify Login: Insert result for failed attempt: ' . ($result ? 'success' : 'failed'));
        if ($wpdb->last_error) {
            error_log('Modify Login: Database error: ' . $wpdb->last_error);
        }

        // Check if user should be locked out
        $max_attempts = get_option('modify_login_max_login_attempts', 5);
        $lockout_time = get_option('modify_login_lockout_time', 30);

        $failed_attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}modify_login_logs 
            WHERE user_id = %d AND status = 'failed' 
            AND created_at > DATE_SUB(NOW(), INTERVAL %d MINUTE)",
            $user_id,
            $lockout_time
        ));

        if ($failed_attempts >= $max_attempts) {
            wp_die(
                sprintf(
                    __('Too many failed login attempts. Please try again in %d minutes.', 'modify-login'),
                    $lockout_time
                ),
                __('Login Locked', 'modify-login'),
                array('response' => 403)
            );
        }
    }

    /**
     * Track successful logins.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       WP_User object.
     */
    public function track_successful_login($user_login, $user) {
        global $wpdb;
        
        error_log('Modify Login: Tracking successful login for user: ' . $user_login);

        // Get location information
        $location = $this->get_location_info($_SERVER['REMOTE_ADDR']);

        $result = $wpdb->insert(
            $wpdb->prefix . 'modify_login_logs',
            array(
                'user_id' => $user->ID,
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'status' => 'success',
                'country' => $location['country'],
                'city' => $location['city']
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );
        
        error_log('Modify Login: Insert result for successful login: ' . ($result ? 'success' : 'failed'));
        if ($wpdb->last_error) {
            error_log('Modify Login: Database error: ' . $wpdb->last_error);
        }
    }

    /**
     * Adjust color brightness.
     *
     * @param string $hex   Hex color code.
     * @param int    $steps Steps to adjust brightness.
     * @return string
     */
    private function adjust_brightness($hex, $steps) {
        $steps = max(-255, min(255, $steps));

        $hex = str_replace('#', '', $hex);
        if (strlen($hex) == 3) {
            $hex = str_repeat(substr($hex, 0, 1), 2) . str_repeat(substr($hex, 1, 1), 2) . str_repeat(substr($hex, 2, 1), 2);
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = max(0, min(255, $r + $steps));
        $g = max(0, min(255, $g + $steps));
        $b = max(0, min(255, $b + $steps));

        $r_hex = str_pad(dechex($r), 2, '0', STR_PAD_LEFT);
        $g_hex = str_pad(dechex($g), 2, '0', STR_PAD_LEFT);
        $b_hex = str_pad(dechex($b), 2, '0', STR_PAD_LEFT);

        return '#' . $r_hex . $g_hex . $b_hex;
    }
} 