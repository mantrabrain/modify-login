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
        wp_enqueue_style(
            $this->plugin_name,
            MODIFY_LOGIN_URL . 'src/css/frontend.css',
            array(),
            $this->version,
            'all'
        );
    }

    /**
     * Register the JavaScript for the login page.
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            $this->plugin_name,
            MODIFY_LOGIN_URL . 'src/js/frontend.js',
            array('jquery'),
            $this->version,
            false
        );

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
        $background_color = get_option('modify_login_background_color', '#ffffff');
        $text_color = get_option('modify_login_text_color', '#333333');
        $button_color = get_option('modify_login_button_color', '#0073aa');
        $button_text_color = get_option('modify_login_button_text_color', '#ffffff');
        $logo_url = get_option('modify_login_logo_url', '');
        $background_image = get_option('modify_login_background_image', '');

        echo '<style type="text/css">';
        echo 'body.login {';
        echo 'background-color: ' . esc_attr($background_color) . ';';
        if (!empty($background_image)) {
            echo 'background-image: url(' . esc_url($background_image) . ');';
            echo 'background-size: cover;';
            echo 'background-position: center;';
        }
        echo '}';

        echo '.login h1 a {';
        if (!empty($logo_url)) {
            echo 'background-image: url(' . esc_url($logo_url) . ');';
            echo 'background-size: contain;';
            echo 'width: 100%;';
            echo 'height: 100px;';
        }
        echo '}';

        echo '.login form {';
        echo 'background-color: rgba(255, 255, 255, 0.9);';
        echo 'border-radius: 8px;';
        echo 'box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);';
        echo '}';

        echo '.login label {';
        echo 'color: ' . esc_attr($text_color) . ';';
        echo '}';

        echo '.wp-core-ui .button-primary {';
        echo 'background-color: ' . esc_attr($button_color) . ';';
        echo 'border-color: ' . esc_attr($button_color) . ';';
        echo 'color: ' . esc_attr($button_text_color) . ';';
        echo '}';

        echo '.wp-core-ui .button-primary:hover {';
        echo 'background-color: ' . esc_attr($this->adjust_brightness($button_color, -20)) . ';';
        echo 'border-color: ' . esc_attr($this->adjust_brightness($button_color, -20)) . ';';
        echo '}';

        echo '.login .message {';
        echo 'border-left: 4px solid ' . esc_attr($button_color) . ';';
        echo '}';

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