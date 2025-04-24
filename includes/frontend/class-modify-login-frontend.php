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
        
        // Register redirect hooks
        add_filter('login_redirect', array($this, 'handle_login_redirect'), 10, 3);
        add_filter('logout_redirect', array($this, 'handle_logout_redirect'), 10, 3);

        // Initialize custom login endpoint and redirection protection
        $this->init_login_endpoint_protection();
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
            echo '}'; // Close pseudo-element
        } else {
            echo '}'; // Close body.login if no background image
        }

        // Logo styles
        if (!empty($logo_url)) {
            echo '.login h1 a {';
            echo 'background-image: url(' . esc_url($logo_url) . ') !important;';
            
            // Width
            if (!empty($logo_width)) {
                // Ensure width has units
                if (is_numeric($logo_width)) {
                    $logo_width .= 'px';
                }
                echo 'width: ' . esc_attr($logo_width) . ' !important;';
            } else {
                // Default width if not specified
                echo 'width: 84px !important;';
            }
            
            // Height
            if (!empty($logo_height)) {
                // Ensure height has units
                if (is_numeric($logo_height)) {
                    $logo_height .= 'px';
                }
                echo 'height: ' . esc_attr($logo_height) . ' !important;';
            } else {
                // Default height if not specified
                echo 'height: 84px !important;';
            }
            
            // Logo position - text alignment
            if (!empty($logo_position)) {
                echo 'text-align: ' . esc_attr($logo_position) . ' !important;';
            }
            
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
            echo 'text-decoration: none !important;';
            echo 'text-shadow: none !important;';
            echo '}';
            
            // Add additional selectors to ensure button styles are applied
            echo '.wp-core-ui .button.button-primary, .wp-core-ui .button-group.button-primary button, .wp-core-ui input[type="submit"] {';
            if (!empty($button_color)) {
                echo 'background: ' . esc_attr($button_color) . ' !important;';
                echo 'border-color: ' . esc_attr($button_color) . ' !important;';
            }
            if (!empty($button_text_color)) {
                echo 'color: ' . esc_attr($button_text_color) . ' !important;';
            }
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

        // Only output form styles if at least one property is set
        if (!empty($form_background) || !empty($form_border_radius) || !empty($form_padding)) {
            echo '.login form, #loginform {';
            if (!empty($form_background)) {
                echo 'background: ' . esc_attr($form_background) . ' !important;';
            }
            if (!empty($form_border_radius)) {
                echo 'border-radius: ' . esc_attr($form_border_radius) . ' !important;';
            }
            if (!empty($form_padding)) {
                echo 'padding: ' . esc_attr($form_padding) . ' !important;';
            }
            echo 'box-shadow: 0 1px 3px rgba(0, 0, 0, 0.13) !important;';
            echo '}';
        }

        // Form Label Color
        if (!empty($label_color)) {
            echo '.login form label, #loginform label, #login form label {';
            echo 'color: ' . esc_attr($label_color) . ' !important;';
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
        
        // Try to get user by username or email
        $user = get_user_by('login', $username);
        if (!$user) {
            $user = get_user_by('email', $username);
        }

        $user_id = $user ? $user->ID : 0;
        $attempted_username = $user_id === 0 ? $username : '';
        
        // Get location information
        $location = $this->get_location_info($_SERVER['REMOTE_ADDR']);

        // Log the failed attempt
        $wpdb->insert(
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

        // Get location information
        $location = $this->get_location_info($_SERVER['REMOTE_ADDR']);

        $wpdb->insert(
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

    /**
     * Initialize login endpoint protection based on plugin settings
     */
    public function init_login_endpoint_protection() {
        // Get settings using our helper method
        $settings = $this->get_plugin_settings();
        
        $login_endpoint = $settings['login_endpoint'];
        $enable_redirect = $settings['enable_redirect'];
        $redirect_url = $settings['redirect_url'];

        // If login endpoint is set, register the custom endpoint
        if (!empty($login_endpoint)) {
            // Store login endpoint as individual option for compatibility with rewrite rules
            update_option('modify_login_login_endpoint', $login_endpoint);
            
            // Add rewrite rules on init priority 10
            add_action('init', array($this, 'register_login_endpoint'), 10);
            
            // Check for query vars and handle the endpoint
            add_filter('query_vars', array($this, 'add_query_vars'));
            add_action('template_redirect', array($this, 'handle_login_endpoint'));
            
            // Force flush rewrite rules if this is the first time or if the endpoint has changed
            if (get_option('modify_login_rewrite_rules_flushed', false) === false) {
                add_action('wp_loaded', function() {
                    flush_rewrite_rules();
                    update_option('modify_login_rewrite_rules_flushed', true);
                });
            }
            
            // Modify the login form to submit to our custom endpoint
            add_filter('login_form_middle', array($this, 'add_endpoint_input'));
            add_filter('site_url', array($this, 'change_login_url'), 10, 4);
            add_filter('network_site_url', array($this, 'change_login_url'), 10, 3);
            add_filter('wp_redirect', array($this, 'modify_login_redirect'), 10, 2);
            add_action('login_form', array($this, 'add_login_form_hidden_fields'));
        }

        // If redirect protection is enabled, add the protection hooks
        if ($enable_redirect) {
            add_action('init', array($this, 'protect_login_page'), 1);
            add_action('template_redirect', array($this, 'protect_admin_page'), 1);
        }
    }

    /**
     * Add the custom query var
     * 
     * @param array $vars The array of available query variables
     * @return array Modified array of query variables
     */
    public function add_query_vars($vars) {
        $vars[] = 'modify_login_endpoint';
        return $vars;
    }

    /**
     * Register the custom login endpoint.
     */
    public function register_login_endpoint() {
        $login_endpoint = get_option('modify_login_login_endpoint', '');
        
        if (!empty($login_endpoint)) {
            // Use add_rewrite_endpoint instead of add_rewrite_rule for better compatibility
            add_rewrite_rule(
                '^' . $login_endpoint . '/?$',
                'index.php?modify_login_endpoint=1',
                'top'
            );
            
            // Register the tag
            add_rewrite_tag('%modify_login_endpoint%', '([^&]+)');
        }
    }

    /**
     * Handle requests to the custom login endpoint.
     */
    public function handle_login_endpoint() {
        global $wp_query;
        
        // Get settings using our helper method
        $settings = $this->get_plugin_settings();
        $login_endpoint = $settings['login_endpoint'];
        
        // Check if our endpoint is being accessed
        $is_endpoint = isset($wp_query->query_vars['modify_login_endpoint']) || 
                      (isset($wp_query->query) && isset($wp_query->query['pagename']) && 
                       $wp_query->query['pagename'] == $login_endpoint);
        
        // Also check POST data for form submissions
        $is_form_submission = isset($_POST['using_custom_endpoint']) || isset($_POST['modify_login_endpoint']);
        
        if ($is_endpoint || $is_form_submission) {
            // Ensure WordPress authentication cookies are recognized
            if (!defined('AUTH_COOKIE')) {
                define('AUTH_COOKIE', 'wordpress_' . COOKIEHASH);
            }
            if (!defined('SECURE_AUTH_COOKIE')) {
                define('SECURE_AUTH_COOKIE', 'wordpress_sec_' . COOKIEHASH);
            }
            if (!defined('LOGGED_IN_COOKIE')) {
                define('LOGGED_IN_COOKIE', 'wordpress_logged_in_' . COOKIEHASH);
            }
            
            // Preserve error messages from login attempts
            if (isset($_REQUEST['login']) && $_REQUEST['login'] === 'failed') {
                $_REQUEST['login'] = 'failed';
            }
            
            // Define special globals needed by wp-login.php
            global $error, $interim_login, $action, $user_login;
            
            // Set these variables to ensure proper login handling
            $error = isset($_GET['error']) ? $_GET['error'] : '';
            $interim_login = isset($_REQUEST['interim-login']) ? $_REQUEST['interim-login'] : '';
            $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
            $user_login = isset($_POST['log']) ? $_POST['log'] : '';
            
            // Load the login functionality
            require_once(ABSPATH . 'wp-login.php');
            exit;
        }
    }

    /**
     * Protect the default wp-login.php page.
     */
    public function protect_login_page() {
        global $pagenow;
        
        // Skip this check for AJAX requests or special scenarios
        if (defined('DOING_AJAX') || defined('DOING_CRON')) {
            return;
        }
        
        // Get settings using our helper method
        $settings = $this->get_plugin_settings();
        
        $login_endpoint = $settings['login_endpoint'];
        $enable_redirect = $settings['enable_redirect'];
        $redirect_url = $settings['redirect_url'];
        
        // Only apply if we have a login endpoint, redirection is enabled, and we're on the login page
        if (!empty($login_endpoint) && $enable_redirect && $pagenow === 'wp-login.php' && !is_user_logged_in()) {
            // Check for allowlisted actions that should still be accessible
            $allowed_actions = array('resetpass', 'rp', 'lostpassword', 'postpass', 'logout', 'jwforgotPassword', 'renewCredential');
            
            // Get current action from URL
            $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
            
            // If this is not an allowed action, redirect
            if (!in_array($action, $allowed_actions)) {
                wp_redirect(!empty($redirect_url) ? esc_url($redirect_url) : home_url('/'));
                exit;
            }
        }
    }

    /**
     * Protect direct access to wp-admin for non-authenticated users.
     */
    public function protect_admin_page() {
        // Skip this check for AJAX requests or special scenarios
        if (defined('DOING_AJAX') || defined('DOING_CRON') || wp_doing_ajax()) {
            return;
        }
        
        // Get settings using our helper method
        $settings = $this->get_plugin_settings();
        
        $login_endpoint = $settings['login_endpoint'];
        $enable_redirect = $settings['enable_redirect'];
        $redirect_url = $settings['redirect_url'];
        
        // Only apply if we have a login endpoint, redirection is enabled, and trying to access admin
        if (!empty($login_endpoint) && $enable_redirect && !is_user_logged_in() && is_admin()) {
            wp_redirect(!empty($redirect_url) ? esc_url($redirect_url) : home_url('/'));
            exit;
        }
    }

    /**
     * Add hidden input field to the login form to identify our custom endpoint
     */
    public function add_login_form_hidden_fields() {
        echo '<input type="hidden" name="using_custom_endpoint" value="1" />';
    }
    
    /**
     * Add a hidden input field to the login form
     * 
     * @param string $content The current content of the login form.
     * @return string
     */
    public function add_endpoint_input($content) {
        return $content . '<input type="hidden" name="modify_login_endpoint" value="1" />';
    }
    
    /**
     * Change the login URL to use our custom endpoint
     * 
     * @param string $url     The complete site URL including scheme and path.
     * @param string $path    Path relative to the site URL.
     * @param string $scheme  Scheme to give the site URL context.
     * @param int    $blog_id Blog ID, defaults to null.
     * @return string Modified URL
     */
    public function change_login_url($url, $path, $scheme = null, $blog_id = null) {
        // Get settings using our helper method
        $settings = $this->get_plugin_settings();
        
        $login_endpoint = $settings['login_endpoint'];
        
        if (empty($login_endpoint)) {
            return $url;
        }
        
        // Only modify wp-login.php URLs
        if (strpos($path, 'wp-login.php') === false) {
            return $url;
        }
        
        // Get the base site URL
        $site_url = site_url('', $scheme);
        
        // Parse the current URL to extract query parameters
        $url_parts = parse_url($url);
        $query = isset($url_parts['query']) ? $url_parts['query'] : '';
        
        // Build the new endpoint URL
        $new_url = trailingslashit($site_url) . $login_endpoint;
        
        // Append the query parameters if they exist
        if (!empty($query)) {
            $new_url .= '?' . $query;
        }
        
        return $new_url;
    }
    
    /**
     * Modify login redirect to keep using our custom endpoint
     * 
     * @param string $location The redirect location.
     * @param int $status The status code.
     * @return string
     */
    public function modify_login_redirect($location, $status) {
        if (strpos($location, 'wp-login.php') !== false) {
            // Get settings using our helper method
            $settings = $this->get_plugin_settings();
            
            $login_endpoint = $settings['login_endpoint'];
            
            if (!empty($login_endpoint)) {
                $location = str_replace('wp-login.php', $login_endpoint, $location);
            }
        }
        
        return $location;
    }

    /**
     * Handle login redirect based on plugin settings
     *
     * @param string $redirect_to The redirect destination URL.
     * @param string $request The requested redirect destination URL passed as a parameter.
     * @param WP_User|WP_Error $user WP_User object if login was successful, WP_Error object otherwise.
     * @return string Modified redirect URL
     */
    public function handle_login_redirect($redirect_to, $request, $user) {
        // Only proceed if we have a valid user object and the user is logged in
        if (!is_wp_error($user) && $user instanceof \WP_User) {
            // Get settings using our helper method
            $settings = $this->get_plugin_settings();
            
            $login_redirect_url = $settings['login_redirect_url'];
            
            // If we have a redirect URL in settings and user has access to it, use that URL
            if (!empty($login_redirect_url)) {
                // Verify if the URL is valid and on the same domain or is an allowed external domain
                if (wp_validate_redirect($login_redirect_url, false)) {
                    // Check user capabilities if the redirect is to an admin page
                    if (strpos($login_redirect_url, admin_url()) !== false && !current_user_can('read')) {
                        // If user doesn't have permission for admin, redirect to home
                        return home_url('/');
                    }
                    
                    return $login_redirect_url;
                }
            }
        }
        
        // If no custom redirect or not applicable, return the default
        return $redirect_to;
    }

    /**
     * Handle logout redirect based on plugin settings
     *
     * @param string $redirect_to The redirect destination URL.
     * @param string $request The requested redirect destination URL passed as a parameter.
     * @param WP_User $user WP_User object of the user that's logging out.
     * @return string Modified redirect URL
     */
    public function handle_logout_redirect($redirect_to, $request, $user) {
        // Get settings using our helper method
        $settings = $this->get_plugin_settings();
        
        $logout_redirect_url = $settings['logout_redirect_url'];
        
        // If we have a logout redirect URL in settings, use that URL
        if (!empty($logout_redirect_url) && wp_validate_redirect($logout_redirect_url, false)) {
            return $logout_redirect_url;
        }
        
        // If no custom redirect, return the default
        return $redirect_to;
    }

    /**
     * Get settings with proper defaults
     * 
     * @return array Array of settings with defaults
     */
    private function get_plugin_settings() {
        // Try to get settings using admin class get_settings method if available
        if (class_exists('\\ModifyLogin\\Admin\\Modify_Login_Admin')) {
            try {
                // Use the singleton instance instead of creating a new instance
                $admin = \ModifyLogin\Admin\Modify_Login_Admin::instance($this->plugin_name, $this->version);
                return $admin->get_settings();
            } catch (\Exception $e) {
                // Fall back to direct option retrieval below
            }
        }
        
        // Fallback to direct settings retrieval with defaults
        $settings = get_option('modify_login_settings', array());
        
        $default_settings = array(
            // Login Endpoint settings
            'login_endpoint'     => isset($settings['login_endpoint']) ? $settings['login_endpoint'] : 'login',
            'enable_redirect'    => isset($settings['enable_redirect']) ? $settings['enable_redirect'] : 0,
            'redirect_url'       => isset($settings['redirect_url']) ? $settings['redirect_url'] : '',
            
            // Redirect settings
            'login_redirect_url' => isset($settings['login_redirect_url']) ? $settings['login_redirect_url'] : '',
            'logout_redirect_url' => isset($settings['logout_redirect_url']) ? $settings['logout_redirect_url'] : '',
            
            // Additional general settings with defaults to prevent undefined warnings
            'enable_recaptcha'   => isset($settings['enable_recaptcha']) ? $settings['enable_recaptcha'] : 0,
            'recaptcha_site_key' => isset($settings['recaptcha_site_key']) ? $settings['recaptcha_site_key'] : '',
            'recaptcha_secret_key' => isset($settings['recaptcha_secret_key']) ? $settings['recaptcha_secret_key'] : '',
            'enable_tracking'    => isset($settings['enable_tracking']) ? $settings['enable_tracking'] : 0,
            'allowed_login_attempts' => isset($settings['allowed_login_attempts']) ? $settings['allowed_login_attempts'] : 3,
            'lockout_time'       => isset($settings['lockout_time']) ? $settings['lockout_time'] : 15,
        );
        
        return $default_settings;
    }
} 