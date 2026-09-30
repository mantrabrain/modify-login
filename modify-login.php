<?php
/**
 * Plugin Name:       Authlify – Custom Login URL, Login Security, 2FA & Login Page Designer
 * Plugin URI:        https://matrixaddons.com/plugins/authlify/
 * Description:       Hide your login page properly, stop brute-force attacks, add two-factor login and passkeys, and brand every login screen. Built so you never lock yourself out.
 * Version:           3.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * Author:            MantraBrain
 * Author URI:        https://mantrabrain.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       modify-login
 * Domain Path:       /languages
 *
 * Authlify was called "Modify Login" before 3.0. The folder, main file, text
 * domain and WordPress.org slug keep the old name so existing sites update.
 *
 * @package Authlify
 */

defined('ABSPATH') || exit;

// The version, in one place: MODIFY_LOGIN_VERSION (the 2.x name, which the
// CI version check reads) holds it, and AUTHLIFY_VERSION follows.
define('MODIFY_LOGIN_VERSION', '3.0.0');
define('AUTHLIFY_VERSION', MODIFY_LOGIN_VERSION);
define('AUTHLIFY_FILE', __FILE__);
define('AUTHLIFY_DIR', plugin_dir_path(__FILE__));
define('AUTHLIFY_URL', plugin_dir_url(__FILE__));
define('AUTHLIFY_BASENAME', plugin_basename(__FILE__));

// 2.x constants, kept for code that reads them.
defined('MODIFY_LOGIN_FILE') || define('MODIFY_LOGIN_FILE', __FILE__);
defined('MODIFY_LOGIN_PATH') || define('MODIFY_LOGIN_PATH', AUTHLIFY_DIR);
defined('MODIFY_LOGIN_URL') || define('MODIFY_LOGIN_URL', AUTHLIFY_URL);
defined('MODIFY_LOGIN_BASENAME') || define('MODIFY_LOGIN_BASENAME', AUTHLIFY_BASENAME);

require_once AUTHLIFY_DIR . 'inc/Autoloader.php';
\Authlify\Autoloader::register();

register_activation_hook(__FILE__, array('\Authlify\Install\Installer', 'activate'));
register_deactivation_hook(__FILE__, array('\Authlify\Install\Installer', 'deactivate'));

if (!function_exists('authlify')) {
    /**
     * The plugin instance.
     *
     * @return \Authlify\Plugin
     */
    function authlify()
    {
        return \Authlify\Plugin::instance();
    }
}

if (!function_exists('modify_login')) {
    /**
     * 2.x accessor, kept for backward compatibility.
     *
     * @return \Authlify\Plugin
     */
    function modify_login()
    {
        return authlify();
    }
}

$GLOBALS['modify-login'] = authlify();
