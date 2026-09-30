<?php
/**
 * Login and logout redirects.
 *
 * @package Authlify
 */

namespace Authlify\Login;

use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Global and per-role redirects after login and logout.
 *
 * Per-role rules win over the global URL. An explicit redirect_to on the
 * request wins over both unless it is the default dashboard, so links like
 * "log in to continue" still land where the user was going.
 */
final class Redirects
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_filter('login_redirect', array(__CLASS__, 'login'), 20, 3);
        add_filter('logout_redirect', array(__CLASS__, 'logout'), 20, 3);
    }

    /**
     * After login.
     *
     * @param string             $redirect_to Target.
     * @param string             $requested   Requested redirect_to.
     * @param \WP_User|\WP_Error $user        User.
     * @return string
     */
    public static function login($redirect_to, $requested, $user)
    {
        if (!$user instanceof \WP_User || !$user->exists()) {
            return $redirect_to;
        }

        // Respect an explicit destination other than the dashboard.
        if ('' !== (string) $requested && !self::is_default_admin($requested)) {
            return $redirect_to;
        }

        $url = self::rule_for($user, 'login');
        if ('' === $url) {
            return $redirect_to;
        }

        // Users who cannot use wp-admin are never sent there.
        if (0 === strpos($url, admin_url()) && !user_can($user, 'read')) {
            return home_url('/');
        }

        return wp_validate_redirect($url, $redirect_to);
    }

    /**
     * After logout.
     *
     * @param string   $redirect_to Target.
     * @param string   $requested   Requested redirect_to.
     * @param \WP_User $user        User.
     * @return string
     */
    public static function logout($redirect_to, $requested, $user)
    {
        if ('' !== (string) $requested) {
            return $redirect_to;
        }

        $url = $user instanceof \WP_User ? self::rule_for($user, 'logout') : (string) Settings::get('logout_redirect_url', '');

        return '' === $url ? $redirect_to : wp_validate_redirect($url, $redirect_to);
    }

    /**
     * The configured URL for a user: first matching role rule, then the global URL.
     *
     * @param \WP_User $user User.
     * @param string   $type login or logout.
     * @return string
     */
    public static function rule_for(\WP_User $user, $type)
    {
        $rules = (array) Settings::get('role_redirects', array());

        foreach ((array) $user->roles as $role) {
            if (!empty($rules[$role][$type])) {
                return self::expand((string) $rules[$role][$type], $user);
            }
        }

        $global = (string) Settings::get('login' === $type ? 'login_redirect_url' : 'logout_redirect_url', '');

        /**
         * Filters the redirect URL for a user.
         *
         * @param string   $url  URL ('' for WordPress's default).
         * @param \WP_User $user User.
         * @param string   $type login or logout.
         * @since 3.0.0
         */
        return (string) apply_filters('authlify_redirect_url', self::expand($global, $user), $user, $type);
    }

    /**
     * Replace {username} and {user_id} placeholders.
     *
     * @param string   $url  URL.
     * @param \WP_User $user User.
     * @return string
     */
    private static function expand($url, \WP_User $user)
    {
        return str_replace(array('{username}', '{user_id}'), array(rawurlencode($user->user_nicename), (string) $user->ID), $url);
    }

    /**
     * Whether a requested URL is just the default dashboard.
     *
     * @param string $url URL.
     * @return bool
     */
    private static function is_default_admin($url)
    {
        return untrailingslashit($url) === untrailingslashit(admin_url()) || untrailingslashit($url) === untrailingslashit(admin_url('index.php'));
    }
}
