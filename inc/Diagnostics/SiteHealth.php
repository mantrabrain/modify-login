<?php
/**
 * Site Health tests and debug data.
 *
 * @package Authlify
 */

namespace Authlify\Diagnostics;

use Authlify\Admin\Menu;
use Authlify\Log\Log;
use Authlify\Login\Router;
use Authlify\Net\Ip;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Adds Authlify's checks to Tools → Site Health: the Leak Check result, the
 * IP source, activity-log write errors, page-cache exclusions, recovery
 * constants and passkey support. The debug tab gets a summary of the setup
 * that never includes the login slug itself.
 */
final class SiteHealth
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_filter('site_status_tests', array(__CLASS__, 'tests'));
        add_filter('debug_information', array(__CLASS__, 'debug_information'));
    }

    /**
     * Register the tests.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function tests($tests)
    {
        $direct = array(
            'authlify_leak_check' => array(__('Authlify Leak Check', 'modify-login'), 'test_leak_check'),
            'authlify_ip_source' => array(__('Authlify IP source', 'modify-login'), 'test_ip_source'),
            'authlify_log_error' => array(__('Authlify activity log', 'modify-login'), 'test_log_error'),
            'authlify_cache' => array(__('Authlify login page caching', 'modify-login'), 'test_cache'),
            'authlify_recovery' => array(__('Authlify recovery settings', 'modify-login'), 'test_recovery'),
            'authlify_passkeys' => array(__('Authlify passkey support', 'modify-login'), 'test_passkeys'),
        );

        foreach ($direct as $key => $test) {
            $tests['direct'][$key] = array(
                'label' => $test[0],
                'test' => array(__CLASS__, $test[1]),
            );
        }

        return $tests;
    }

    /**
     * Result skeleton.
     *
     * @param string $test        Test key.
     * @param string $status      good, recommended or critical.
     * @param string $label       Headline.
     * @param string $description HTML description.
     * @param string $actions     HTML actions.
     * @return array
     */
    private static function result($test, $status, $label, $description, $actions = '')
    {
        return array(
            'label' => $label,
            'status' => $status,
            'badge' => array(
                'label' => __('Security', 'modify-login'),
                'color' => 'critical' === $status ? 'red' : ('recommended' === $status ? 'orange' : 'blue'),
            ),
            'description' => $description,
            'actions' => $actions,
            'test' => $test,
        );
    }

    /**
     * A paragraph.
     *
     * @param string $text Text.
     * @return string
     */
    private static function p($text)
    {
        return '<p>' . wp_kses($text, array('code' => array(), 'strong' => array(), 'em' => array())) . '</p>';
    }

    /**
     * A link action.
     *
     * @param string $url   URL.
     * @param string $label Label.
     * @return string
     */
    private static function link($url, $label)
    {
        return '<p><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
    }

    /*
     * ---------------------------------------------------------------------
     * Tests
     * ---------------------------------------------------------------------
     */

    /**
     * Leak Check result (read from the last run; the check itself runs from
     * the dashboard, after settings changes and weekly).
     *
     * @return array
     */
    public static function test_leak_check()
    {
        $test = 'authlify_leak_check';
        $action = self::link(Menu::url('dashboard') . '#authlify-leak-check', __('Open the Leak Check', 'modify-login'));

        if ('' === Router::slug()) {
            return self::result($test, 'good', __('Authlify Leak Check: nothing to check', 'modify-login'), self::p(__('The login page is at wp-login.php, so there is no hidden URL to leak.', 'modify-login')));
        }

        $result = LeakCheck::last();

        if (!$result) {
            return self::result($test, 'recommended', __('The Authlify Leak Check has not run yet', 'modify-login'), self::p(__('The Leak Check visits your site as a logged-out visitor and confirms that nothing reveals your custom login URL.', 'modify-login')), $action);
        }

        $when = sprintf(
            /* translators: %s: time ago */
            __('Last run %s ago.', 'modify-login'),
            human_time_diff((int) $result['time'])
        );
        $summary = LeakCheck::summary($result) . ' ' . $when;

        switch ($result['state']) {
            case 'passed':
                return self::result($test, 'good', __('Authlify Leak Check passed', 'modify-login'), self::p($summary), $action);
            case 'failed':
                $list = '';
                foreach ($result['probes'] as $probe) {
                    if ('fail' === $probe['status']) {
                        $list .= '<li><strong>' . esc_html($probe['label']) . '</strong>: ' . esc_html($probe['detail']) . '</li>';
                    }
                }

                return self::result($test, 'critical', __('The Authlify Leak Check found places that reveal your login URL', 'modify-login'), self::p($summary) . '<ul>' . $list . '</ul>', $action);
            case 'untested':
                return self::result($test, 'recommended', __('The Authlify Leak Check could not test your site', 'modify-login'), self::p(__('This server cannot make loopback requests to itself, so the Leak Check could not run. That is not a pass. See the loopback test on this page for the cause.', 'modify-login')) . self::p($when), $action);
        }

        return self::result($test, 'recommended', __('The Authlify Leak Check needs attention', 'modify-login'), self::p($summary), $action);
    }

    /**
     * IP source matches how requests arrive.
     *
     * @return array
     */
    public static function test_ip_source()
    {
        $test = 'authlify_ip_source';
        $source = (string) Settings::get('ip_source', 'remote_addr');
        $detected = Ip::detect();
        $action = self::link(Menu::url('protection'), __('Change the IP source', 'modify-login'));
        $names = array(
            'remote_addr' => __('Direct connection (REMOTE_ADDR)', 'modify-login'),
            'cloudflare' => __('Cloudflare', 'modify-login'),
            'proxy' => __('Trusted proxy / load balancer', 'modify-login'),
        );
        $current = sprintf(
            /* translators: 1: configured source, 2: resolved IP */
            __('Setting: %1$s. Your IP as Authlify sees it: %2$s.', 'modify-login'),
            isset($names[$source]) ? $names[$source] : $source,
            '<code>' . esc_html(Ip::client()) . '</code>'
        );

        if ('cloudflare' === $detected['source'] && 'cloudflare' !== $source) {
            return self::result($test, 'critical', __('Authlify sees Cloudflare\'s address instead of your visitors\' IPs', 'modify-login'), self::p(__('Requests reach this site through Cloudflare, but the IP source is set to the direct connection. Every visitor then appears to come from a few Cloudflare addresses, so one attacker\'s lockout would lock out everyone who shares that edge server, including you.', 'modify-login')) . self::p($current) . self::p(__('Set the IP source to Cloudflare.', 'modify-login')), $action);
        }

        if ('proxy' === $detected['source'] && 'remote_addr' === $source) {
            return self::result($test, 'recommended', __('Authlify may see your proxy\'s address instead of visitors\' IPs', 'modify-login'), self::p($detected['reason']) . self::p($current), $action);
        }

        if ('cloudflare' === $source && 'cloudflare' !== $detected['source']) {
            return self::result($test, 'recommended', __('Authlify is set to Cloudflare, but this request did not come through Cloudflare', 'modify-login'), self::p(__('That is harmless (Authlify then uses the connection address), but if the site is no longer behind Cloudflare, switch the IP source back to the direct connection. If you reached the admin by bypassing Cloudflare on purpose, ignore this.', 'modify-login')) . self::p($current), $action);
        }

        return self::result($test, 'good', __('Authlify sees your visitors\' real IP addresses', 'modify-login'), self::p($detected['reason']) . self::p($current));
    }

    /**
     * Recent activity-log write failures.
     *
     * @return array
     */
    public static function test_log_error()
    {
        $test = 'authlify_log_error';
        $error = get_option('authlify_log_error');

        if (!is_array($error) || empty($error['time']) || (int) $error['time'] < time() - WEEK_IN_SECONDS) {
            return self::result($test, 'good', __('Authlify\'s activity log is being written', 'modify-login'), self::p(__('No database errors while saving login activity in the last week.', 'modify-login')));
        }

        $message = sprintf(
            /* translators: 1: time ago, 2: database error */
            __('%1$s ago the database refused a log entry: %2$s', 'modify-login'),
            human_time_diff((int) $error['time']),
            '<code>' . esc_html(isset($error['error']) ? (string) $error['error'] : '') . '</code>'
        );

        return self::result($test, 'recommended', __('Authlify could not save login activity', 'modify-login'), self::p($message) . self::p(__('Lockouts still work, but the activity log is incomplete. Deactivate and reactivate Authlify to recreate its tables, or ask your host to check the database user\'s permissions.', 'modify-login')), self::link(Menu::url('activity'), __('Open the activity log', 'modify-login')));
    }

    /**
     * Page caches that must skip the custom login URL.
     *
     * @return array
     */
    public static function test_cache()
    {
        $test = 'authlify_cache';
        $slug = Router::slug();

        if ('' === $slug) {
            return self::result($test, 'good', __('Authlify login page caching: nothing to exclude', 'modify-login'), self::p(__('The custom login URL is off. wp-login.php is never cached by page caches.', 'modify-login')));
        }

        $path = '/' . $slug . '/';
        $found = self::caches($path);

        if (!$found) {
            return self::result($test, 'good', __('No page cache needs a login-page exclusion', 'modify-login'), self::p(__('Authlify sends no-store headers on the login page. No page-cache plugin or managed-host cache that ignores them was detected.', 'modify-login')));
        }

        $manual = false;
        $items = '';
        foreach ($found as $cache) {
            $manual = $manual || !$cache['auto'];
            $items .= '<li><strong>' . esc_html($cache['name']) . '</strong> ' . ($cache['auto'] ? '(' . esc_html__('handled automatically', 'modify-login') . ')' : '') . ': ' . wp_kses($cache['steps'], array('code' => array(), 'strong' => array(), 'em' => array())) . '</li>';
        }

        $description = self::p(sprintf(
            /* translators: %s: login path */
            __('A cached login page breaks logins (expired security tokens) and can serve one visitor\'s page to another. Keep %s out of every cache:', 'modify-login'),
            '<code>' . esc_html($path) . '</code>'
        )) . '<ul>' . $items . '</ul>';

        return self::result(
            $test,
            $manual ? 'recommended' : 'good',
            $manual ? __('Exclude the Authlify login URL from your page cache', 'modify-login') : __('The Authlify login URL is excluded from your page cache', 'modify-login'),
            $description
        );
    }

    /**
     * Detected caches with exclusion steps.
     *
     * @param string $path Login path.
     * @return array[] name, auto, steps.
     */
    private static function caches($path)
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $code = '<code>' . esc_html($path) . '</code>';
        $found = array();

        if (defined('WP_ROCKET_VERSION')) {
            $found[] = array(
                'name' => 'WP Rocket',
                'auto' => true,
                /* translators: %s: login path */
                'steps' => sprintf(__('Authlify adds %s to "Never Cache URL(s)". Check it under Settings → WP Rocket → Advanced Rules and clear the cache once.', 'modify-login'), $code),
            );
        }
        if (defined('LSCWP_V')) {
            $found[] = array(
                'name' => 'LiteSpeed Cache',
                'auto' => true,
                /* translators: %s: login path */
                'steps' => sprintf(__('Authlify marks the login page as not cacheable. To be safe, also add %s under LiteSpeed Cache → Cache → Excludes → Do Not Cache URIs.', 'modify-login'), $code),
            );
        }
        if (defined('W3TC') || defined('W3TC_VERSION')) {
            $found[] = array(
                'name' => 'W3 Total Cache',
                'auto' => false,
                /* translators: %s: login path */
                'steps' => sprintf(__('Go to Performance → Page Cache → Advanced → "Never cache the following pages", add %s on its own line, save and empty all caches.', 'modify-login'), $code),
            );
        }
        if (defined('WPCACHEHOME') || function_exists('wp_cache_phase2')) {
            $found[] = array(
                'name' => 'WP Super Cache',
                'auto' => false,
                /* translators: %s: login path */
                'steps' => sprintf(__('Go to Settings → WP Super Cache → Advanced → "Rejected URL strings", add %s, save and delete the cache.', 'modify-login'), $code),
            );
        }
        if (is_plugin_active('sg-cachepress/sg-cachepress.php')) {
            $found[] = array(
                'name' => 'SiteGround Speed Optimizer',
                'auto' => true,
                /* translators: %s: login path */
                'steps' => sprintf(__('Authlify adds %s to the dynamic-cache exclusions. Check it under Speed Optimizer → Caching → Exclude URLs, and purge the SG cache once.', 'modify-login'), $code),
            );
        }
        if (defined('WPE_APIKEY') || function_exists('wpe_param')) {
            $found[] = array(
                'name' => 'WP Engine',
                'auto' => false,
                /* translators: %s: login path */
                'steps' => sprintf(__('WP Engine\'s server cache does not know the custom URL. In the WP Engine User Portal open the environment → Cache → Cache exclusions (or ask support) and exclude paths starting with %s. WP Engine\'s own ?wpe-login=true login link is served by wp-login.php, so it stays hidden.', 'modify-login'), $code),
            );
        }
        if (defined('KINSTAMU_VERSION')) {
            $found[] = array(
                'name' => 'Kinsta',
                'auto' => false,
                /* translators: %s: login path */
                'steps' => sprintf(__('Kinsta\'s server cache skips wp-login.php but not a custom URL. Ask Kinsta support (or use MyKinsta → Caching → cache bypass, where available) to exclude %s from the server and edge cache, then clear the cache in MyKinsta.', 'modify-login'), $code),
            );
        }
        if (self::cloudflare_apo()) {
            $found[] = array(
                'name' => 'Cloudflare APO',
                'auto' => false,
                /* translators: %s: login path */
                'steps' => sprintf(__('In the Cloudflare dashboard open Caching → Cache Rules, create a rule "URI Path starts with %s" with the action "Bypass cache", deploy it, then purge the cache.', 'modify-login'), $code),
            );
        }

        /**
         * Filters the page caches reported by the Site Health cache test.
         *
         * @param array[] $found name, auto (bool), steps (HTML).
         * @param string  $path  Login path.
         * @since 3.0.0
         */
        return (array) apply_filters('authlify_detected_caches', $found, $path);
    }

    /**
     * Whether Cloudflare Automatic Platform Optimization is on.
     *
     * @return bool
     */
    private static function cloudflare_apo()
    {
        if (!defined('CLOUDFLARE_PLUGIN_DIR')) {
            return false;
        }

        $apo = get_option('automatic_platform_optimization');

        return is_array($apo) ? !empty($apo['value']) : !empty($apo);
    }

    /**
     * Recovery constants (information).
     *
     * @return array
     */
    public static function test_recovery()
    {
        $test = 'authlify_recovery';

        if (defined('AUTHLIFY_DISABLE_HIDE') && AUTHLIFY_DISABLE_HIDE) {
            return self::result($test, 'recommended', __('Authlify\'s custom login URL is switched off in wp-config.php', 'modify-login'), self::p(__('<code>AUTHLIFY_DISABLE_HIDE</code> is set, so wp-login.php is the login page and your custom URL is ignored. That is meant for recovery. Remove the line from wp-config.php once you can log in again.', 'modify-login')));
        }

        $lines = array();
        if (defined('AUTHLIFY_SLUG')) {
            $lines[] = __('<code>AUTHLIFY_SLUG</code> is set in wp-config.php and overrides the login URL chosen in the admin.', 'modify-login');
        }
        $lines[] = __('If you ever lose the login URL, add <code>define( \'AUTHLIFY_DISABLE_HIDE\', true );</code> to wp-config.php, or run <code>wp authlify url reset</code>.', 'modify-login');

        return self::result($test, 'good', __('Authlify recovery options are available', 'modify-login'), implode('', array_map(array(__CLASS__, 'p'), $lines)));
    }

    /**
     * PHP version for passkeys (information).
     *
     * @return array
     */
    public static function test_passkeys()
    {
        $test = 'authlify_passkeys';

        if (PHP_VERSION_ID >= 80000) {
            /* translators: %s: PHP version */
            return self::result($test, 'good', __('Your PHP version supports passkeys', 'modify-login'), self::p(sprintf(__('PHP %s can verify passkey (WebAuthn) logins.', 'modify-login'), PHP_VERSION)));
        }

        return self::result(
            $test,
            'recommended',
            __('Passkeys need PHP 8.0 or newer', 'modify-login'),
            /* translators: %s: PHP version */
            self::p(sprintf(__('This site runs PHP %s. Authenticator-app codes and backup codes work; passkeys stay off until your host upgrades PHP to 8.0 or newer.', 'modify-login'), PHP_VERSION))
        );
    }

    /*
     * ---------------------------------------------------------------------
     * Debug information
     * ---------------------------------------------------------------------
     */

    /**
     * Add Authlify to the Site Health "Info" tab (never the slug itself).
     *
     * @param array $info Info.
     * @return array
     */
    public static function debug_information($info)
    {
        $on = __('On', 'modify-login');
        $off = __('Off', 'modify-login');
        $slug = Router::slug();
        $leak = LeakCheck::last();
        $log = self::log_size();

        $fields = array(
            'version' => array(__('Version', 'modify-login'), AUTHLIFY_VERSION),
            'pro' => array(__('Authlify Pro', 'modify-login'), defined('AUTHLIFY_PRO_VERSION') ? AUTHLIFY_PRO_VERSION : __('Not active', 'modify-login')),
            'slug_set' => array(__('Custom login URL set', 'modify-login'), '' !== $slug ? __('Yes', 'modify-login') : __('No', 'modify-login')),
            'slug_constant' => array(__('Login URL from wp-config.php', 'modify-login'), defined('AUTHLIFY_SLUG') ? __('Yes', 'modify-login') : __('No', 'modify-login')),
            'disable_hide' => array(__('AUTHLIFY_DISABLE_HIDE', 'modify-login'), defined('AUTHLIFY_DISABLE_HIDE') && AUTHLIFY_DISABLE_HIDE ? __('Set', 'modify-login') : __('Not set', 'modify-login')),
            'hiding' => array(__('wp-login.php hidden', 'modify-login'), Router::is_hiding() ? $on : $off),
            'blocked_response' => array(__('Hidden URL response', 'modify-login'), (string) Settings::get('blocked_response', '404')),
            'limits' => array(__('Brute-force limits', 'modify-login'), Settings::get('limit_enabled', true) ? sprintf('%d / %d min', (int) Settings::get('limit_attempts'), (int) Settings::get('limit_window')) : $off),
            'ip_source' => array(__('IP source', 'modify-login'), (string) Settings::get('ip_source', 'remote_addr')),
            'xmlrpc' => array(__('XML-RPC', 'modify-login'), (string) Settings::get('xmlrpc', 'on')),
            'app_passwords' => array(__('Application passwords', 'modify-login'), (string) Settings::get('app_passwords', 'on')),
            'user_enumeration' => array(__('Username discovery blocked', 'modify-login'), Settings::get('block_user_enumeration', false) ? $on : $off),
            'captcha' => array(__('CAPTCHA provider', 'modify-login'), (string) Settings::get('captcha_provider', 'none')),
            'twofa' => array(__('Two-factor login', 'modify-login'), Settings::get('twofa_enabled', true) ? $on : $off),
            'log' => array(__('Activity log', 'modify-login'), Settings::get('log_enabled', true) ? $log : $off),
            'leak_check' => array(__('Last Leak Check', 'modify-login'), $leak ? sprintf('%s (%s, %s)', $leak['state'], gmdate('Y-m-d H:i', (int) $leak['time']) . ' UTC', implode('/', array_map('intval', $leak['counts']))) : __('Never', 'modify-login')),
        );

        $info['authlify'] = array(
            'label' => 'Authlify',
            'description' => __('Login security settings. The custom login URL itself is never included here.', 'modify-login'),
            'fields' => array(),
        );

        foreach ($fields as $key => $field) {
            $info['authlify']['fields'][$key] = array(
                'label' => $field[0],
                'value' => $field[1],
            );
        }

        return $info;
    }

    /**
     * Activity log rows and size, e.g. "1,234 rows, 0.4 MB".
     *
     * @return string
     */
    private static function log_size()
    {
        global $wpdb;

        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', Log::table())); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if (!$status) {
            return __('Table missing', 'modify-login');
        }

        return sprintf(
            /* translators: 1: approximate row count, 2: size */
            __('about %1$s rows, %2$s', 'modify-login'),
            number_format_i18n((int) $status->Rows),
            size_format((int) $status->Data_length + (int) $status->Index_length, 1)
        );
    }
}
