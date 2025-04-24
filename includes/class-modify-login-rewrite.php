<?php
/**
 * Rewrite rules handling for Modify Login plugin
 *
 * @package ModifyLogin
 * @since 2.0.0
 */

namespace ModifyLogin\Core;

defined('ABSPATH') || exit;

/**
 * Class to handle login endpoint rewrite rules and URL handling
 */
class Modify_Login_Rewrite {
    /**
     * Singleton instance
     *
     * @var Modify_Login_Rewrite
     */
    private static $instance = null;

    /**
     * Login endpoint from settings
     *
     * @var string
     */
    private $login_endpoint = '';

    /**
     * Using plain permalinks flag
     *
     * @var bool
     */
    private $using_plain_permalinks = false;

    /**
     * Get the singleton instance
     *
     * @return Modify_Login_Rewrite
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Get login endpoint from options
        $this->load_endpoint_settings();

        // Register hooks
        $this->init_hooks();
    }

    /**
     * Load endpoint settings
     */
    private function load_endpoint_settings() {
        // Get settings
        $settings = get_option('modify_login_settings', array());
        
        // Get login endpoint from settings
        $this->login_endpoint = isset($settings['login_endpoint']) ? sanitize_text_field($settings['login_endpoint']) : 'setup';
        
        // Store as individual option for compatibility with rewrite rules
        if (!empty($this->login_endpoint)) {
            update_option('modify_login_login_endpoint', $this->login_endpoint);
        }

        // Check permalink structure
        $permalink_structure = get_option('permalink_structure');
        $this->using_plain_permalinks = empty($permalink_structure);
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Exit if no endpoint set
        if (empty($this->login_endpoint)) {
            return;
        }

        // Register rewrite rules early
        add_action('init', array($this, 'register_rewrite_rules'), 10);
        
        // Handle parse_request at priority 1 for early detection
        add_action('parse_request', array($this, 'handle_endpoint_request'), 1);
        
        // Register custom query vars
        add_filter('query_vars', array($this, 'add_query_vars'));
        
        // Handle template redirect for custom endpoint
        add_action('template_redirect', array($this, 'handle_login_endpoint'));
        
        // Force flush rewrite rules if needed
        add_action('init', array($this, 'maybe_flush_rewrite_rules'), 999);
        
        // Modify login form and URLs
        add_filter('login_form_middle', array($this, 'add_endpoint_input'));
        add_filter('site_url', array($this, 'change_login_url'), 10, 4);
        add_filter('network_site_url', array($this, 'change_login_url'), 10, 3);
        add_filter('wp_redirect', array($this, 'modify_login_redirect'), 10, 2);
        add_action('login_form', array($this, 'add_login_form_hidden_fields'));
        
        // Handle login and logout redirects
        add_filter('login_redirect', array($this, 'handle_login_redirect'), 10, 3);
        add_filter('logout_redirect', array($this, 'handle_logout_redirect'), 10, 3);
    }

    /**
     * Register rewrite rules for the login endpoint
     */
    public function register_rewrite_rules() {
        // Only register rules if we have an endpoint
        if (empty($this->login_endpoint)) {
            return;
        }
        
        // For pretty permalinks, add a rewrite rule
        if (!$this->using_plain_permalinks) {
            add_rewrite_rule(
                '^' . $this->login_endpoint . '/?$',
                'index.php?modify_login_endpoint=1',
                'top'
            );
            
            // Register the tag
            add_rewrite_tag('%modify_login_endpoint%', '([^&]+)');
        }
    }
    
    /**
     * Handle the endpoint request during parse_request
     * 
     * @param \WP $wp The WordPress object
     */
    public function handle_endpoint_request($wp) {
        // Get the current request URI
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        
        // Check if the endpoint is being accessed
        $is_endpoint = false;
        
        if ($this->using_plain_permalinks) {
            // For plain permalinks, check the query string directly
            // Example: /?setup or /?setup&redirect_to=xxx
            $is_endpoint = isset($_GET[$this->login_endpoint]) || array_key_exists($this->login_endpoint, $_GET);
        } else {
            // For pretty permalinks, check the path
            // Example: /setup
            $path = parse_url($request_uri, PHP_URL_PATH);
            $path = rtrim($path, '/');
            $endpoint_path = '/' . $this->login_endpoint;
            $is_endpoint = ($path === $endpoint_path);
        }
        
        if ($is_endpoint) {
            // This is our custom login endpoint - load the login page
            define('MODIFY_LOGIN_CUSTOM_ENDPOINT', true);
            require_once(ABSPATH . 'wp-login.php');
            exit;
        }
    }

    /**
     * Add custom query var for login endpoint
     * 
     * @param array $vars Query vars
     * @return array Modified query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'modify_login_endpoint';
        return $vars;
    }

    /**
     * Handle login endpoint requests during template_redirect
     */
    public function handle_login_endpoint() {
        global $wp_query;
        
        // If already handled by parse_request, don't do anything
        if (defined('MODIFY_LOGIN_CUSTOM_ENDPOINT') && MODIFY_LOGIN_CUSTOM_ENDPOINT) {
            return;
        }
        
        // Check if our endpoint is being accessed
        $is_endpoint = isset($wp_query->query_vars['modify_login_endpoint']) || 
                      (isset($wp_query->query) && isset($wp_query->query['pagename']) && 
                       $wp_query->query['pagename'] == $this->login_endpoint);
        
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
            if (isset($_REQUEST['login']) && sanitize_text_field($_REQUEST['login']) === 'failed') {
                $_REQUEST['login'] = 'failed';
            }
            
            // Define special globals needed by wp-login.php
            global $error, $interim_login, $action, $user_login;
            
            // Set these variables to ensure proper login handling
            $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';
            $interim_login = isset($_REQUEST['interim-login']) ? sanitize_text_field($_REQUEST['interim-login']) : '';
            $action = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : '';
            $user_login = isset($_POST['log']) ? sanitize_user($_POST['log']) : '';
            
            // Load the login functionality
            require_once(ABSPATH . 'wp-login.php');
            exit;
        }
    }

    /**
     * Maybe flush rewrite rules if this is the first time or if the endpoint has changed
     */
    public function maybe_flush_rewrite_rules() {
        // Get current saved endpoint
        $current_endpoint = get_option('modify_login_login_endpoint', '');
        
        // If endpoint has changed or rewrite rules not flushed, flush them
        if (!empty($current_endpoint) && 
            (get_option('modify_login_rewrite_rules_flushed', false) === false || 
             get_option('modify_login_previous_endpoint', '') !== $current_endpoint)) {
            
            // Delay the flush to ensure rules are registered first
            add_action('wp_loaded', function() use ($current_endpoint) {
                flush_rewrite_rules();
                update_option('modify_login_rewrite_rules_flushed', true);
                update_option('modify_login_previous_endpoint', $current_endpoint);
            });
        }
    }

    /**
     * Add hidden input field to the login form
     * 
     * @param string $content The current content of the login form
     * @return string Modified content
     */
    public function add_endpoint_input($content) {
        return $content . '<input type="hidden" name="modify_login_endpoint" value="1" />';
    }

    /**
     * Add all necessary hidden fields to the login form
     */
    public function add_login_form_hidden_fields() {
        echo '<input type="hidden" name="using_custom_endpoint" value="1" />';
        
        // Only add script if custom endpoint is set
        if (!empty($this->login_endpoint)) {
            // Add script to modify form action
            ?>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function() {
                    // Get all forms on the login page
                    var forms = document.querySelectorAll('form');
                    
                    // Create the site URL base
                    var siteUrl = '<?php echo esc_js(site_url()); ?>';
                    var customEndpoint = '<?php echo esc_js($this->login_endpoint); ?>';
                    var usingPlainPermalinks = <?php echo $this->using_plain_permalinks ? 'true' : 'false'; ?>;
                    
                    // Loop through all forms
                    forms.forEach(function(form) {
                        // Get the current action and check if it contains wp-login.php
                        var currentAction = form.getAttribute('action');
                        
                        if (currentAction && currentAction.indexOf('wp-login.php') !== -1) {
                            // Parse the current URL to get any existing query parameters
                            var queryParams = {};
                            var actionParts = currentAction.split('?');
                            
                            if (actionParts.length > 1) {
                                var paramPairs = actionParts[1].split('&');
                                for (var i = 0; i < paramPairs.length; i++) {
                                    var pair = paramPairs[i].split('=');
                                    queryParams[pair[0]] = pair[1] || '';
                                }
                            }
                            
                            if (usingPlainPermalinks) {
                                // For plain permalinks, use query parameter format /?setup
                                // First parameter needs to be the endpoint without an equals sign
                                var newAction = siteUrl + '/?' + customEndpoint;
                                
                                // Add any other query parameters
                                for (var key in queryParams) {
                                    newAction += '&' + key + '=' + queryParams[key];
                                }
                            } else {
                                // For pretty permalinks, use path format /setup
                                var newAction = siteUrl + '/' + customEndpoint;
                                
                                // Add query parameters to the URL if any exist
                                var queryString = '';
                                for (var key in queryParams) {
                                    if (queryString !== '') {
                                        queryString += '&';
                                    }
                                    queryString += key + '=' + queryParams[key];
                                }
                                
                                if (queryString) {
                                    newAction += '?' + queryString;
                                }
                            }
                            
                            // Set the form action to the new URL
                            form.action = newAction;
                        }
                    });
                });
            </script>
            <?php
        }
    }

    /**
     * Change login URLs to use our custom endpoint
     * 
     * @param string $url    Site URL
     * @param string $path   Path
     * @param string $scheme Scheme
     * @param int    $blog_id Blog ID
     * @return string Modified URL
     */
    public function change_login_url($url, $path, $scheme = null, $blog_id = null) {
        // Only modify the URL if our custom endpoint is set
        if (empty($this->login_endpoint)) {
            return $url;
        }
        
        // Check if this is a login-related URL
        if (strpos($url, 'wp-login.php') !== false) {
            // Get the site URL with the appropriate scheme
            $site_url = get_site_url(null, '', $scheme);
            
            // Parse the original URL to extract any query parameters
            $url_parts = parse_url($url);
            $query_params = [];
            if (isset($url_parts['query'])) {
                parse_str($url_parts['query'], $query_params);
            }
            
            if ($this->using_plain_permalinks) {
                // For plain permalinks, use query parameter format /?endpoint
                $new_url = $site_url . '/?' . $this->login_endpoint;
                
                // Add any other query parameters
                foreach ($query_params as $key => $value) {
                    $new_url .= '&' . $key . '=' . $value;
                }
            } else {
                // For pretty permalinks, use path format /endpoint
                $new_url = trailingslashit($site_url) . $this->login_endpoint;
                
                // Add any query parameters
                if (!empty($query_params)) {
                    $new_url .= '?' . http_build_query($query_params);
                }
            }
            
            return $new_url;
        }
        
        // Return original URL for non-login URLs
        return $url;
    }

    /**
     * Fix redirects to wp-login.php when using custom login endpoints
     *
     * @param string $location Redirect location
     * @param int    $status   HTTP status code
     * @return string Modified location
     */
    public function modify_login_redirect($location, $status) {
        // Only modify if our custom endpoint is set
        if (empty($this->login_endpoint)) {
            return $location;
        }
        
        // Check if this is a login-related redirect
        if (strpos($location, 'wp-login.php') !== false) {
            // Get site URL for building new URL
            $site_url = get_site_url();
            
            // Parse the location URL to extract query parameters
            $url_parts = parse_url($location);
            $query_params = [];
            if (isset($url_parts['query'])) {
                parse_str($url_parts['query'], $query_params);
            }
            
            if ($this->using_plain_permalinks) {
                // For plain permalinks, use query parameter format /?endpoint
                $new_location = $site_url . '/?' . $this->login_endpoint;
                
                // Add any other query parameters
                foreach ($query_params as $key => $value) {
                    $new_location .= '&' . $key . '=' . $value;
                }
            } else {
                // For pretty permalinks, use path format /endpoint
                $new_location = trailingslashit($site_url) . $this->login_endpoint;
                
                // Add any query parameters
                if (!empty($query_params)) {
                    $new_location .= '?' . http_build_query($query_params);
                }
            }
            
            return esc_url_raw($new_location);
        }
        
        // Return original location for non-login redirects
        return $location;
    }

    /**
     * Handle login redirect
     *
     * @param string $redirect_to Redirect to URL
     * @param string $request     Request URL
     * @param object $user        User object
     * @return string Modified redirect to URL
     */
    public function handle_login_redirect($redirect_to, $request, $user) {
        // If user isn't logged in or isn't valid, return the default
        if (!is_a($user, 'WP_User') || !$user->exists()) {
            return $redirect_to;
        }
        
        // Get settings
        $settings = get_option('modify_login_settings', array());
        
        // Get the login redirect URL from settings
        $login_redirect_url = isset($settings['login_redirect_url']) ? esc_url_raw($settings['login_redirect_url']) : '';
        
        // Only apply custom redirect if URL is set and is valid
        if (!empty($login_redirect_url) && wp_validate_redirect($login_redirect_url, false)) {
            // Verify if the URL is valid and on the same domain or is an allowed external domain
            $login_redirect_url = wp_validate_redirect($login_redirect_url, home_url('/'));
            
            // If redirecting to admin but user doesn't have admin access, redirect to home
            if (strpos($login_redirect_url, admin_url()) !== false && !current_user_can('read')) {
                // If user doesn't have permission for admin, redirect to home
                return home_url('/');
            }
            
            return $login_redirect_url;
        }
        
        // If we're redirecting to wp-login.php, use our custom endpoint instead
        if (empty($this->login_endpoint)) {
            return $redirect_to;
        }
        
        if (strpos($redirect_to, 'wp-login.php') !== false) {
            // Get site URL for building new URL
            $site_url = get_site_url();
            
            // Parse the redirect_to URL to extract query parameters
            $url_parts = parse_url($redirect_to);
            $query_params = [];
            if (isset($url_parts['query'])) {
                parse_str($url_parts['query'], $query_params);
            }
            
            if ($this->using_plain_permalinks) {
                // For plain permalinks, use query parameter format /?endpoint
                $new_redirect_to = $site_url . '/?' . $this->login_endpoint;
                
                // Add any other query parameters
                foreach ($query_params as $key => $value) {
                    $new_redirect_to .= '&' . $key . '=' . $value;
                }
            } else {
                // For pretty permalinks, use path format /endpoint
                $new_redirect_to = trailingslashit($site_url) . $this->login_endpoint;
                
                // Add any query parameters
                if (!empty($query_params)) {
                    $new_redirect_to .= '?' . http_build_query($query_params);
                }
            }
            
            return esc_url_raw($new_redirect_to);
        }
        
        // Return original redirect_to if no custom redirect applicable
        return $redirect_to;
    }

    /**
     * Handle logout redirect
     *
     * @param string $redirect_to Redirect to URL
     * @param string $request     Request URL
     * @param object $user        User object
     * @return string Modified redirect to URL
     */
    public function handle_logout_redirect($redirect_to, $request, $user) {
        // Get settings
        $settings = get_option('modify_login_settings', array());
        
        // Get logout redirect URL from settings
        $logout_redirect_url = isset($settings['logout_redirect_url']) ? esc_url_raw($settings['logout_redirect_url']) : '';
        
        // If we have a logout redirect URL in settings and it's valid, use that URL
        if (!empty($logout_redirect_url) && wp_validate_redirect($logout_redirect_url, false)) {
            return wp_validate_redirect($logout_redirect_url, home_url('/'));
        }
        
        // If we're redirecting to wp-login.php, use our custom endpoint instead
        if (empty($this->login_endpoint)) {
            return $redirect_to;
        }
        
        if (strpos($redirect_to, 'wp-login.php') !== false) {
            // Get site URL for building new URL
            $site_url = get_site_url();
            
            // Parse the redirect_to URL to extract query parameters
            $url_parts = parse_url($redirect_to);
            $query_params = [];
            if (isset($url_parts['query'])) {
                parse_str($url_parts['query'], $query_params);
            }
            
            if ($this->using_plain_permalinks) {
                // For plain permalinks, use query parameter format /?endpoint
                $new_redirect_to = $site_url . '/?' . $this->login_endpoint;
                
                // Add any other query parameters
                foreach ($query_params as $key => $value) {
                    $new_redirect_to .= '&' . $key . '=' . $value;
                }
            } else {
                // For pretty permalinks, use path format /endpoint
                $new_redirect_to = trailingslashit($site_url) . $this->login_endpoint;
                
                // Add any query parameters
                if (!empty($query_params)) {
                    $new_redirect_to .= '?' . http_build_query($query_params);
                }
            }
            
            return esc_url_raw($new_redirect_to);
        }
        
        // Return original redirect_to if no custom redirect applicable
        return $redirect_to;
    }
} 