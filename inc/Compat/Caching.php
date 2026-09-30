<?php
/**
 * Page-cache integration.
 *
 * @package Authlify
 */

namespace Authlify\Compat;

use Authlify\Login\Router;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Keeps the custom login URL out of page caches.
 *
 * The login page already sends no-store headers and DONOTCACHEPAGE (Router).
 * Caches that ignore headers get the URL added to their exclusion lists.
 */
final class Caching
{
    /**
     * Wire up.
     */
    public static function init()
    {
        // WP Rocket.
        add_filter('rocket_cache_reject_uri', array(__CLASS__, 'add_paths'));
        // LiteSpeed Cache.
        add_action('litespeed_init', array(__CLASS__, 'litespeed'));
        // SiteGround Optimizer.
        add_filter('sgo_bypass_cache_urls', array(__CLASS__, 'add_paths'));
        // Breeze (Cloudways).
        add_filter('breeze_exclude_urls', array(__CLASS__, 'add_paths'));

        add_action('authlify_settings_updated', array(__CLASS__, 'on_slug_change'), 10, 2);
    }

    /**
     * Paths to exclude from caching.
     *
     * @return string[]
     */
    public static function paths()
    {
        $paths = array();
        foreach (array(Router::slug(), Router::pending_slug()) as $slug) {
            if ('' !== $slug) {
                $paths[] = '/' . $slug . '/?(.*)';
            }
        }

        return $paths;
    }

    /**
     * Append our paths to a list filter.
     *
     * @param array $list List.
     * @return array
     */
    public static function add_paths($list)
    {
        return array_values(array_unique(array_merge((array) $list, self::paths())));
    }

    /**
     * LiteSpeed: mark the login page non-cacheable.
     */
    public static function litespeed()
    {
        if (Router::is_login_request()) {
            do_action('litespeed_control_set_nocache', 'authlify login page');
        }
    }

    /**
     * Refresh cache exclusions when the slug changes.
     *
     * @param array $new New settings.
     * @param array $old Old settings.
     */
    public static function on_slug_change($new, $old)
    {
        if ($new['login_slug'] === $old['login_slug'] && $new['pending_slug'] === $old['pending_slug']) {
            return;
        }

        if (function_exists('flush_rocket_htaccess')) {
            flush_rocket_htaccess();
        }
        if (function_exists('rocket_generate_config_file')) {
            rocket_generate_config_file();
        }

        /**
         * Fires when cache exclusions should be refreshed.
         *
         * @since 3.0.0
         */
        do_action('authlify_refresh_cache_exclusions');
    }
}
