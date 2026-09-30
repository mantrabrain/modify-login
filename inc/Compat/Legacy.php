<?php
/**
 * Modify Login 2.x compatibility.
 *
 * @package Authlify
 */

namespace Authlify\Compat;

defined('ABSPATH') || exit;

/**
 * Keeps 2.x entry points working.
 *
 * - admin.php?page=modify-login, modify-login-logs and modify-login-builder
 *   are still real pages (see Admin\Menu);
 * - the 1.x settings URL (options-general.php?page=modify-login) redirects;
 * - the modify_login() function, $GLOBALS['modify-login'], the MODIFY_LOGIN_*
 *   constants and the before_modify_login_init / modify_login_init actions
 *   are provided by the main file and Plugin.
 */
final class Legacy
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('admin_init', array(__CLASS__, 'redirect_old_settings_url'), 1);
    }

    /**
     * options-general.php?page=modify-login (1.x) → the Authlify dashboard.
     */
    public static function redirect_old_settings_url()
    {
        global $pagenow;

        // phpcs:ignore WordPress.Security.NonceVerification
        if ('options-general.php' === $pagenow && isset($_GET['page']) && 'modify-login' === $_GET['page']) {
            wp_safe_redirect(admin_url('admin.php?page=modify-login'));
            exit;
        }
    }
}
