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
require_once MODIFY_LOGIN_PATH . 'includes/class-modify-login-loader.php';

// Initialize the plugin
function modify_login() {
    return ModifyLogin\Core\Modify_Login::instance();
}

// Start the plugin
$GLOBALS['modify-login'] = modify_login();