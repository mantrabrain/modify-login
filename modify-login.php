<?php
/**
 * Plugin Name: Modify Login
 * Version: 2.0.0
 * Plugin URI: https://wordpress.org/plugins/modify-login
 * Description: Enhance and customize the default WordPress login experience with modern design, security features, and improved user experience.
 * Author: MantraBrain
 * Author URI: https://mantrabrain.com
 * Text Domain: modify-login
 * Domain Path: /languages/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('MODIFY_LOGIN_VERSION', '2.0.0');
define('MODIFY_LOGIN_FILE', __FILE__);
define('MODIFY_LOGIN_PATH', plugin_dir_path(__FILE__));
define('MODIFY_LOGIN_URL', plugin_dir_url(__FILE__));
define('MODIFY_LOGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'ModifyLogin\\';
    $base_dir = MODIFY_LOGIN_PATH . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Include required files
require_once MODIFY_LOGIN_PATH . 'includes/class-modify-login.php';
// No need to load these files here since they're already loaded in the Modify_Login class
// require_once MODIFY_LOGIN_PATH . 'includes/class-modify-login-loader.php';
// require_once MODIFY_LOGIN_PATH . 'includes/class-modify-login-install.php';

// Initialize the plugin
function modify_login() {
    return ModifyLogin\Core\Modify_Login::instance();
}

// Start the plugin
$GLOBALS['modify-login'] = modify_login();

// Register activation hook
register_activation_hook(__FILE__, 'modify_login_activate');

/**
 * Plugin activation function
 */
function modify_login_activate() {
    // Make sure the install class is loaded
    require_once MODIFY_LOGIN_PATH . 'includes/class-modify-login-install.php';
    
    // Run the installation
    ModifyLogin\Core\Modify_Login_Install::install();
    
    // Force flush rewrite rules
    flush_rewrite_rules();
}

// Fix for existing installations - flush rewrite rules immediately 
add_action('init', function() {
    $settings = get_option('modify_login_settings', array());
    $login_endpoint = isset($settings['login_endpoint']) ? $settings['login_endpoint'] : '';
    
    if (!empty($login_endpoint) && get_option('modify_login_rewrite_rules_flushed', false) === false) {
        update_option('modify_login_rewrite_rules_flushed', false);
        // Delay the flush to ensure rules are registered first
        add_action('wp_loaded', function() {
            flush_rewrite_rules();
            update_option('modify_login_rewrite_rules_flushed', true);
        });
    }
}, 1);