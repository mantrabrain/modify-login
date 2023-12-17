<?php
/**
 *  Plugin Name:     Modify Login
 *  Version:         1.1
 *  Plugin URI:      https://wordpress.org/plugins/modify-login
 *  Description:     Modify WordPress admin login technique. This plugin will modify your login url so that your website will be more secure.
 *  Author:          Mantrabrain
 *  Author URI:      https://mantrabrain.com
 *  Text Domain:     modify-login
 *  Domain Path:     /languages/
 **/


define('MODIFY_LOGIN_FILE', __FILE__);

// Include the Core file
if (!class_exists('Modify_Login')) {
    include_once dirname(__FILE__) . '/includes/class-modify-login.php';
}

/**
 * Main instance of Mantrabrain Modify_Login
 *
 * Returns the main instance to prevent the need to use globals.
 *
 * @since 1.0.0
 * @return Modify_Login
 */
function modify_login()
{
    return Modify_Login::instance();
}


$GLOBALS['modify-login'] = modify_login();