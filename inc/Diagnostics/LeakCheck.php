<?php
/**
 * Leak Check: a self-test for the hidden login URL.
 *
 * @package Authlify
 */

namespace Authlify\Diagnostics;

use Authlify\Admin\Menu;
use Authlify\Admin\UI;
use Authlify\Login\Router;
use Authlify\Plugin;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Visits the site's own URLs as a logged-out visitor and reports whether any
 * of them reveals the custom login URL or serves the login form.
 *
 * Every probe is a loopback request to this site only (no cookies, no
 * redirects followed), so the slug never leaves the server. Probes carry a
 * signed X-Authlify-Leak-Check header so they are not logged as attacks, and
 * none of them submits credentials, so they can never cause a lockout.
 *
 * The probe list mirrors the leak routes closed by \Authlify\Login\Router and
 * the bypasses documented for other hide-login plugins (CVE-2024-2473,
 * CVE-2024-6289, CVE-2021-24917, URL-encoded and double-slash paths).
 */
final class LeakCheck
{
    /**
     * Site option holding the last result.
     */
    const OPTION = 'authlify_leak_check';

    /**
     * Probe request header.
     */
    const HEADER = 'X-Authlify-Leak-Check';

    /**
     * Cron hook for the one-off run after a settings change.
     */
    const CRON_HOOK = 'authlify_leak_check_run';

    /**
     * Seconds between manual runs.
     */
    const RATE_LIMIT = 30;

    /**
     * Timeout per probe in seconds.
     */
    const TIMEOUT = 8;

    /**
     * Total time budget for one run in seconds.
     */
    const BUDGET = 90;

    /**
     * Whether the current request is a verified probe (null: not checked yet).
     *
     * @var bool|null
     */
    private static $is_probe = null;

    /**
     * Unix time the current run must finish by (0: no run in progress).
     *
     * @var int
     */
    private static $deadline = 0;

    /**
     * Wire up.
     */
    public static function init()
    {
        // Probe requests must not be logged as attacks on hidden URLs.
        if (isset($_SERVER['HTTP_X_AUTHLIFY_LEAK_CHECK'])) {
            add_action('authlify_blocked_request', array(__CLASS__, 'silence_probe'), PHP_INT_MIN);
        }

        add_action('authlify_settings_updated', array(__CLASS__, 'on_settings_updated'), 10, 2);
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_from_cron'));
        add_action('authlify_daily', array(__CLASS__, 'weekly'));
        add_action('deactivate_' . AUTHLIFY_BASENAME, array(__CLASS__, 'deactivate'));
        add_filter('authlify_dashboard_checks', array(__CLASS__, 'dashboard_check'));
        add_filter('authlify_pre_log', array(__CLASS__, 'skip_probe_log'));

        if (is_admin()) {
            add_action('authlify_login_url_page', array(__CLASS__, 'render_strip'));
            add_action('admin_post_authlify_leak_check', array(__CLASS__, 'handle_run'));
            add_filter('authlify_admin_notice_messages', array(__CLASS__, 'notice_messages'));
        }

        if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('authlify leak-check', array(__CLASS__, 'cli'));
        }
    }

    /*
     * ---------------------------------------------------------------------
     * Probe identification
     * ---------------------------------------------------------------------
     */

    /**
     * A signed, short-lived token for the probe header.
     *
     * @param int|null $time Unix time the token was issued.
     * @return string
     */
    public static function token($time = null)
    {
        $time = null === $time ? time() : (int) $time;

        return $time . '.' . hash_hmac('sha256', 'authlify-leak-check|' . $time . '|' . home_url('/'), wp_salt('auth'));
    }

    /**
     * Whether the current request is one of our own probes (valid signed header).
     *
     * Core modules can call this to skip logging or rate limiting.
     *
     * @return bool
     */
    public static function is_probe()
    {
        if (null !== self::$is_probe) {
            return self::$is_probe;
        }

        $value = isset($_SERVER['HTTP_X_AUTHLIFY_LEAK_CHECK']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_AUTHLIFY_LEAK_CHECK'])) : '';

        if ('' === $value || !function_exists('wp_salt')) {
            return false;
        }

        $valid = false;
        if (preg_match('/^(\d{9,11})\.[a-f0-9]{64}$/', $value, $m) && abs(time() - (int) $m[1]) <= 5 * MINUTE_IN_SECONDS) {
            $valid = hash_equals(self::token((int) $m[1]), $value);
        }

        self::$is_probe = $valid;

        return $valid;
    }

    /**
     * Stop everything else listening to authlify_blocked_request (logging,
     * 404 counters) for a verified probe. The response itself is unchanged,
     * so the probe still sees exactly what a visitor sees.
     */
    public static function silence_probe()
    {
        if (self::is_probe()) {
            remove_all_actions('authlify_blocked_request');
        }
    }

    /*
     * ---------------------------------------------------------------------
     * Scheduling
     * ---------------------------------------------------------------------
     */

    /**
     * Re-test a few seconds after the login URL or hiding changes.
     *
     * @param array $new New settings.
     * @param array $old Old settings.
     */
    public static function on_settings_updated($new, $old)
    {
        $keys = array('login_slug', 'pending_slug', 'block_wp_login', 'blocked_response');
        $changed = false;

        foreach ($keys as $key) {
            $a = isset($new[$key]) ? $new[$key] : null;
            $b = isset($old[$key]) ? $old[$key] : null;
            if ($a !== $b) {
                $changed = true;
                break;
            }
        }

        if (!$changed || !self::automatic()) {
            return;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_single_event(time() + 10, self::CRON_HOOK);
    }

    /**
     * Cron callback for the one-off run.
     */
    public static function run_from_cron()
    {
        self::run('settings');
    }

    /**
     * Daily housekeeping: run the check when the last one is a week old.
     */
    public static function weekly()
    {
        if (!self::automatic()) {
            return;
        }

        $last = self::last();

        if (!$last || (int) $last['time'] < time() - WEEK_IN_SECONDS + HOUR_IN_SECONDS) {
            self::run('weekly');
        }
    }

    /**
     * Whether the check runs by itself (weekly, and after the login URL
     * changes). Sites upgraded from Modify Login start with this off.
     *
     * @return bool
     */
    public static function automatic()
    {
        return (bool) Settings::get('leak_check_schedule', true);
    }

    /**
     * Remove the pending one-off event on deactivation.
     */
    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /*
     * ---------------------------------------------------------------------
     * Running
     * ---------------------------------------------------------------------
     */

    /**
     * The stored result of the last run, or null.
     *
     * @return array|null
     */
    public static function last()
    {
        $result = get_site_option(self::OPTION, null);

        return is_array($result) && isset($result['time'], $result['probes']) ? $result : null;
    }

    /**
     * Run every probe and store the result.
     *
     * @param string $trigger manual, settings, weekly or cli.
     * @return array Result: time, trigger, state, counts, probes.
     */
    public static function run($trigger = 'manual')
    {
        Settings::flush();

        // The whole run must fit the budget, and PHP must not stop it first.
        self::$deadline = time() + self::BUDGET;
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::BUDGET + 30); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        $slugs = array_values(array_unique(array_filter(array(Router::slug(), Router::pending_slug()))));
        $probes = array();

        if (!$slugs) {
            $state = 'off';
            $probes['custom_url'] = self::result(
                'skip',
                __('Custom login URL', 'modify-login'),
                defined('AUTHLIFY_DISABLE_HIDE') && AUTHLIFY_DISABLE_HIDE
                    ? __('Custom login URL is off: AUTHLIFY_DISABLE_HIDE is set in wp-config.php, so wp-login.php is the login page.', 'modify-login')
                    : __('Custom login URL is off, so there is nothing to leak. wp-login.php is the login page.', 'modify-login'),
                __('Choose a custom login URL on the Login URL screen, then run the check again.', 'modify-login')
            );
        } else {
            $probes = self::probe_all($slugs);
            $state = self::state($probes);
        }

        $result = array(
            'time' => time(),
            'trigger' => sanitize_key($trigger),
            'state' => $state,
            'counts' => self::counts($probes),
            'probes' => $probes,
        );

        update_site_option(self::OPTION, $result);
        self::$deadline = 0;

        /**
         * Fires after a Leak Check run.
         *
         * @param array $result Result.
         * @since 3.0.0
         */
        do_action('authlify_leak_check_done', $result);

        return $result;
    }

    /**
     * Run the probes.
     *
     * @param string[] $slugs Active (and pending) slugs.
     * @return array key => result.
     */
    private static function probe_all(array $slugs)
    {
        $hiding = Router::is_hiding();
        $probes = array();
        $start = time();

        // Baseline: can this server reach itself at all?
        $baseline = self::request(home_url('/'));
        if (is_wp_error($baseline)) {
            $probes['loopback'] = self::result(
                'error',
                __('Loopback request', 'modify-login'),
                sprintf(
                    /* translators: %s: error message */
                    __('Couldn\'t test: this server cannot request its own pages (%s). The Leak Check needs these "loopback" requests, the same ones Site Health checks under "Your site could not complete a loopback request". This is not a pass.', 'modify-login'),
                    $baseline->get_error_message()
                ),
                __('Open Tools → Site Health and fix the loopback error (usually a firewall, basic auth or DNS setting on the host). Until then, test from your own computer with tests/leak-suite.sh or a private browser window.', 'modify-login')
            );

            return $probes;
        }

        if (!$hiding) {
            $probes['hiding_off'] = self::result(
                'warn',
                __('wp-login.php is not hidden', 'modify-login'),
                __('A custom login URL is set, but "Hide wp-login.php and wp-admin" is off, so wp-login.php still shows the login form. That is allowed, not a leak, but bots will keep finding the login page.', 'modify-login'),
                __('Turn on hiding on the Login URL screen.', 'modify-login')
            );
        }

        $probes['custom_url'] = self::probe_custom_url();

        foreach (self::definitions() as $key => $def) {
            if (self::out_of_time()) {
                $probes[$key] = self::result('skip', $def['label'], __('Skipped: the check ran out of time. Your server answers slowly; run it again.', 'modify-login'), '');
                continue;
            }

            if (!empty($def['skip'])) {
                $probes[$key] = self::result('skip', $def['label'], $def['skip'], '');
                continue;
            }

            $response = self::request($def['url'], $def['method'], $def['headers'], $def['body']);
            $probes[$key] = self::judge($def, $response, $slugs, $hiding);
        }

        $probes['welcome_email'] = self::probe_welcome_email($slugs);
        $probes['privacy_email'] = self::probe_privacy_email($slugs, $hiding);

        // The budget covers every probe, not only the URL list above.
        $extra = array(
            'xmlrpc' => array(__('XML-RPC', 'modify-login'), 'probe_xmlrpc'),
            'rest_users' => array(__('REST API user list', 'modify-login'), 'probe_rest_users'),
            'author_scan' => array(__('Author archive scan', 'modify-login'), 'probe_author_scan'),
            'app_passwords' => array(__('Application passwords', 'modify-login'), 'probe_app_passwords'),
        );
        foreach ($extra as $key => $probe) {
            $probes[$key] = self::out_of_time()
                ? self::result('skip', $probe[0], __('Skipped: the check ran out of time. Your server answers slowly; run it again.', 'modify-login'), '')
                : call_user_func(array(__CLASS__, $probe[1]));
        }
        unset($start);

        return $probes;
    }

    /**
     * Whether the current run has used up its time budget.
     *
     * @return bool
     */
    private static function out_of_time()
    {
        return self::$deadline > 0 && time() >= self::$deadline;
    }

    /**
     * The HTTP leak probes.
     *
     * Each: label, url, method, headers, body, kind (hidden, public, backdoor),
     * why (what the probe covers), fix (what to do on a leak), skip (reason).
     *
     * @return array
     */
    private static function definitions()
    {
        // Raw option values: site_url() and friends are filtered to the slug.
        $site = untrailingslashit((string) get_option('siteurl'));
        $home = untrailingslashit(home_url());
        $login = $site . '/wp-login.php';
        $multi = is_multisite() ? __('Skipped: on multisite this script handles sign-ups and is not a login page.', 'modify-login') : '';

        $fix_router = __('This route is closed by Authlify\'s router. A leak here usually means another plugin redirects visitors to the login page. Deactivate plugins one at a time and rerun the check to find it, then report it to Authlify support.', 'modify-login');
        $fix_backdoor = __('This is an Authlify 2.x back door. Make sure no old copy of Modify Login (or a must-use plugin copied from it) is still loaded.', 'modify-login');
        $fix_public = __('Some part of this page prints the login URL. If it is the Meta widget, remove it (Appearance → Widgets) or accept that the link is public. For comment "log in to reply" links, turn off "Users must be registered and logged in to comment" or accept it.', 'modify-login');

        $defs = array(
            'wp_login' => array(__('GET wp-login.php', 'modify-login'), $login),
            'wp_login_double' => array(__('GET //wp-login.php (double slash)', 'modify-login'), $site . '//wp-login.php'),
            'wp_login_encoded' => array(__('GET /%77p-login.php (URL-encoded)', 'modify-login'), $site . '/%77p-login.php'),
            'wp_login_trailing' => array(__('GET wp-login.php/x (trailing path)', 'modify-login'), $login . '/x'),
            'wp_login_dir' => array(__('GET /blah/wp-login.php (any folder)', 'modify-login'), $home . '/blah/wp-login.php'),
            'action_register' => array(__('GET wp-login.php?action=register', 'modify-login'), $login . '?action=register'),
            'action_lostpassword' => array(__('GET wp-login.php?action=lostpassword', 'modify-login'), $login . '?action=lostpassword'),
            'action_postpass' => array(__('GET wp-login.php?action=postpass (CVE-2024-2473)', 'modify-login'), $login . '?action=postpass'),
            'action_postpass_post' => array(
                __('POST wp-login.php?action=postpass', 'modify-login'),
                $login . '?action=postpass',
                'POST',
                array('Referer' => $home . '/'),
                array('post_password' => 'authlify-leak-check'),
            ),
            'postpass_smuggle_checkemail' => array(
                __('POST postpass with a smuggled ?checkemail= screen', 'modify-login'),
                $login . '?checkemail=confirm',
                'POST',
                array('Referer' => $home . '/'),
                array('action' => 'postpass', 'post_password' => 'authlify-leak-check'),
            ),
            'postpass_smuggle_key' => array(
                __('POST postpass with a smuggled ?key= reset', 'modify-login'),
                $login . '?key=authlify&login=authlify',
                'POST',
                array('Referer' => $home . '/'),
                array('action' => 'postpass', 'post_password' => 'authlify-leak-check'),
            ),
            'action_confirm' => array(__('GET wp-login.php?action=confirmaction (bad key)', 'modify-login'), $login . '?action=confirmaction&confirm_key=authlify-leak-check'),
            'interim_login' => array(__('GET wp-login.php?interim-login=1', 'modify-login'), $login . '?interim-login=1'),
            'action_logout' => array(__('GET wp-login.php?action=logout', 'modify-login'), $login . '?action=logout'),
            'wp_register' => array(__('GET wp-register.php', 'modify-login'), $site . '/wp-register.php'),
            'wp_signup' => array(__('GET wp-signup.php', 'modify-login'), $site . '/wp-signup.php', 'GET', array(), null, 'hidden', $multi),
            'wp_activate' => array(__('GET wp-activate.php', 'modify-login'), $site . '/wp-activate.php', 'GET', array(), null, 'hidden', $multi),
            'wp_admin' => array(__('GET /wp-admin/ (logged out)', 'modify-login'), $site . '/wp-admin/'),
            'options_referer' => array(
                __('GET wp-admin/options.php with a wp-login.php Referer (CVE-2021-24917)', 'modify-login'),
                $site . '/wp-admin/options.php',
                'GET',
                array('Referer' => $login),
            ),
            'customize' => array(__('GET wp-admin/customize.php', 'modify-login'), $site . '/wp-admin/customize.php'),
            'profile' => array(__('GET wp-admin/profile.php', 'modify-login'), $site . '/wp-admin/profile.php'),
            'gf_page' => array(__('GET wp-admin/?gf_page=x (CVE-2024-6289 style)', 'modify-login'), $site . '/wp-admin/?gf_page=x'),
            'shortcut_login' => array(__('GET /login', 'modify-login'), $home . '/login'),
            'shortcut_admin' => array(__('GET /admin', 'modify-login'), $home . '/admin'),
            'shortcut_dashboard' => array(__('GET /dashboard', 'modify-login'), $home . '/dashboard'),
            'legacy_query' => array(__('GET /?modify_login_endpoint=1 (2.x back door)', 'modify-login'), $home . '/?modify_login_endpoint=1', 'GET', array(), null, 'backdoor'),
            'legacy_post' => array(
                __('POST / with using_custom_endpoint=1 (2.x back door)', 'modify-login'),
                $home . '/',
                'POST',
                array(),
                array('using_custom_endpoint' => '1'),
                'backdoor',
            ),
            'home' => array(__('Homepage HTML', 'modify-login'), $home . '/', 'GET', array(), null, 'public'),
        );

        $post = get_posts(array('numberposts' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'suppress_filters' => false));
        $defs['post'] = $post
            ? array(__('A sample post', 'modify-login'), get_permalink($post[0]), 'GET', array(), null, 'public')
            : array(__('A sample post', 'modify-login'), '', 'GET', array(), null, 'public', __('Skipped: there are no published posts.', 'modify-login'));

        $defs['page_404'] = array(__('A 404 page', 'modify-login'), $home . '/authlify-leak-check-' . wp_generate_password(8, false, false) . '/', 'GET', array(), null, 'public');
        $defs['robots'] = array(__('robots.txt', 'modify-login'), $home . '/robots.txt', 'GET', array(), null, 'public');
        $defs['sitemap'] = array(__('XML sitemap', 'modify-login'), $home . '/wp-sitemap.xml', 'GET', array(), null, 'public');
        $defs['feed'] = array(__('RSS feed', 'modify-login'), get_feed_link(), 'GET', array(), null, 'public');
        $defs['rest_index'] = array(__('REST API index', 'modify-login'), rest_url('/'), 'GET', array(), null, 'public');

        $out = array();
        foreach ($defs as $key => $d) {
            $kind = isset($d[5]) ? $d[5] : 'hidden';
            $out[$key] = array(
                'label' => $d[0],
                'url' => $d[1],
                'method' => isset($d[2]) ? $d[2] : 'GET',
                'headers' => isset($d[3]) ? $d[3] : array(),
                'body' => isset($d[4]) ? $d[4] : null,
                'kind' => $kind,
                'skip' => isset($d[6]) ? $d[6] : '',
                'fix' => 'public' === $kind ? $fix_public : ('backdoor' === $kind ? $fix_backdoor : $fix_router),
            );
        }

        /**
         * Filters the Leak Check HTTP probes (add-ons can add their own).
         *
         * @param array $out key => array( label, url, method, headers, body, kind, skip, fix ).
         * @since 3.0.0
         */
        return apply_filters('authlify_leak_check_probes', $out);
    }

    /**
     * Decide pass or fail for one HTTP probe.
     *
     * @param array           $def      Probe definition.
     * @param array|\WP_Error $response Response.
     * @param string[]        $slugs    Slugs.
     * @param bool            $hiding   Whether hiding is on.
     * @return array
     */
    private static function judge(array $def, $response, array $slugs, $hiding)
    {
        if (is_wp_error($response)) {
            return self::result(
                'error',
                $def['label'],
                sprintf(
                    /* translators: %s: error message */
                    __('Couldn\'t test: the request failed (%s).', 'modify-login'),
                    $response->get_error_message()
                ),
                __('Run the check again. If it keeps failing, test this URL from a private browser window or with tests/leak-suite.sh.', 'modify-login')
            );
        }

        $seen = self::describe($response);
        $leak = self::find_leak($response['location'], $slugs);
        $where = __('in the redirect', 'modify-login');

        if ('' === $leak) {
            $leak = self::find_leak($response['body'], $slugs);
            $where = __('in the page', 'modify-login');
        }

        if ('' === $leak && $response['code'] >= 500) {
            return self::result('error', $def['label'], sprintf(
                /* translators: %d: HTTP status */
                __('Couldn\'t test: the server answered with an error (%d), which can hide a leak.', 'modify-login'),
                $response['code']
            ), __('Check the PHP error log, fix the error and run the check again.', 'modify-login'));
        }

        if ('' === $leak) {
            return self::result('pass', $def['label'], sprintf(
                /* translators: %s: what the server answered, e.g. "404" */
                __('No login URL and no login form. The server answered %s.', 'modify-login'),
                $seen
            ), '');
        }

        $what = 'form' === $leak ? __('The login form is served', 'modify-login') : __('The login URL appears', 'modify-login');
        $detail = sprintf('%1$s %2$s (%3$s).', $what, $where, $seen);

        $fix = $def['fix'];

        // A 200 page that only prints the URL is a public-page leak, not a back door.
        if ('form' !== $leak && ('public' === $def['kind'] || ('backdoor' === $def['kind'] && 200 === $response['code']))) {
            $detail .= ' ' . self::public_source($response['body']);
            $fix = __('Some part of this page prints the login URL. If it is the Meta widget, remove it (Appearance → Widgets) or accept that the link is public. For comment "log in to reply" links, turn off "Users must be registered and logged in to comment" or accept it.', 'modify-login');
        }

        // With hiding off, wp-login.php working is expected, not a leak.
        return self::result($hiding ? 'fail' : 'warn', $def['label'], $detail, $fix);
    }

    /**
     * The custom login URL works and is not cacheable.
     *
     * @return array
     */
    private static function probe_custom_url()
    {
        $label = __('Custom login URL works', 'modify-login');
        $response = self::request(Router::login_url());

        if (is_wp_error($response)) {
            return self::result('error', $label, sprintf(
                /* translators: %s: error message */
                __('Couldn\'t test: the request failed (%s).', 'modify-login'),
                $response->get_error_message()
            ), '');
        }

        if (200 !== $response['code'] || !self::has_form($response['body'])) {
            return self::result('fail', $label, sprintf(
                /* translators: %s: what the server answered */
                __('Your custom login URL did not show the login form (the server answered %s). You may not be able to log in.', 'modify-login'),
                self::describe($response)
            ), __('Open the login URL in a private window. If it shows a 404, re-save Settings → Permalinks, and check that no page, post or security plugin uses the same address. Recovery: add define( \'AUTHLIFY_DISABLE_HIDE\', true ); to wp-config.php.', 'modify-login'));
        }

        if (false === stripos($response['cache'], 'no-store')) {
            return self::result('fail', $label, __('The login page works, but it is sent without a "no-store" Cache-Control header, so a page cache or CDN may store it. Cached login pages break logins (expired nonces) and can reveal the URL.', 'modify-login'), __('Exclude the login URL from your page cache and CDN (see Tools → Site Health for the exact steps for your cache).', 'modify-login'));
        }

        return self::result('pass', $label, __('Shows the login form (200) with no-store cache headers.', 'modify-login'), '');
    }

    /**
     * Multisite welcome emails must point new site owners at the working URL.
     *
     * @param string[] $slugs Slugs.
     * @return array
     */
    private static function probe_welcome_email(array $slugs)
    {
        $label = __('Multisite welcome email', 'modify-login');
        $sample = "Log in here: BLOG_URLwp-login.php\nOr: " . get_option('siteurl') . '/wp-login.php';
        $out = (string) apply_filters('site_option_welcome_email', $sample);

        if (false !== strpos($out, 'wp-login.php')) {
            return self::result(
                Router::is_hiding() ? 'fail' : 'pass',
                $label,
                Router::is_hiding()
                    ? __('The welcome email still links to wp-login.php, which new site owners cannot open while it is hidden.', 'modify-login')
                    : __('The welcome email links to wp-login.php, which works because hiding is off.', 'modify-login'),
                Router::is_hiding() ? __('Another plugin replaces the welcome email text after Authlify. Check network plugins that customise emails.', 'modify-login') : ''
            );
        }

        return self::result('pass', $label, is_multisite()
            ? __('New site owners get the working login URL (the email goes only to them).', 'modify-login')
            : __('Only used on multisite. The filter rewrites wp-login.php links to the working login URL.', 'modify-login'), '');
    }

    /**
     * Privacy-request emails go to people who may not be users: no slug.
     *
     * @param string[] $slugs  Slugs.
     * @param bool     $hiding Whether hiding is on.
     * @return array
     */
    private static function probe_privacy_email(array $slugs, $hiding)
    {
        $label = __('Privacy request confirmation email', 'modify-login');

        if (!$hiding) {
            return self::result('skip', $label, __('Skipped: wp-login.php is not hidden, so the email may link to it.', 'modify-login'), '');
        }

        $confirm = add_query_arg(array(
            'action' => 'confirmaction',
            'request_id' => 0,
            'confirm_key' => 'authlify-leak-check',
        ), site_url('wp-login.php', 'login'));

        $data = array(
            'request' => null,
            'email' => 'someone@example.com',
            'description' => 'Export Personal Data',
            'confirm_url' => $confirm,
            'sitename' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'siteurl' => home_url(),
        );

        $content = (string) apply_filters('user_request_action_email_content', "To confirm this, click here:\n\n###CONFIRM_URL###\n\n###SITEURL###", $data);
        // What core does next.
        $content = str_replace('###CONFIRM_URL###', esc_url_raw($data['confirm_url']), $content);

        if ('' !== self::find_leak($content, $slugs)) {
            return self::result('fail', $label, __('The confirmation email sent to any email address (including people who are not users) contains your login URL.', 'modify-login'), __('Another plugin rewrites this email after Authlify. Check privacy or email-template plugins.', 'modify-login'));
        }

        return self::result('pass', $label, __('The email links to wp-login.php, which forwards to the login page only for a valid confirmation key.', 'modify-login'), '');
    }

    /**
     * XML-RPC: another way in (informational).
     *
     * @return array
     */
    private static function probe_xmlrpc()
    {
        $label = __('XML-RPC (another way to log in)', 'modify-login');
        $fix = __('Security → Hardening → XML-RPC: block multi-password requests, or turn XML-RPC off if you do not use Jetpack or the mobile app.', 'modify-login');
        $body = '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params></params></methodCall>';
        $response = self::request(untrailingslashit((string) get_option('siteurl')) . '/xmlrpc.php', 'POST', array('Content-Type' => 'text/xml'), $body);

        if (is_wp_error($response)) {
            return self::result('error', $label, sprintf(
                /* translators: %s: error message */
                __('Couldn\'t test: the request failed (%s).', 'modify-login'),
                $response->get_error_message()
            ), '');
        }

        if ($response['code'] >= 500) {
            return self::result('error', $label, sprintf(
                /* translators: %d: HTTP status */
                __('Couldn\'t test: the server answered with an error (%d).', 'modify-login'),
                $response['code']
            ), '');
        }

        if (200 !== $response['code'] || false === strpos($response['body'], '<methodResponse')) {
            return self::result('pass', $label, sprintf(
                /* translators: %s: HTTP status */
                __('XML-RPC is off (the server answered %s).', 'modify-login'),
                self::describe($response)
            ), '');
        }

        if (false !== strpos($response['body'], 'system.multicall')) {
            return self::result('warn', $label, __('Not a leak, but XML-RPC is on and system.multicall is available: one request can try hundreds of passwords without knowing your login URL.', 'modify-login'), $fix);
        }

        return self::result('info', $label, __('XML-RPC is on, so passwords can be tried without the login URL (one per request; brute-force limits still apply). system.multicall is blocked.', 'modify-login'), $fix);
    }

    /**
     * REST user listing (username discovery).
     *
     * @return array
     */
    private static function probe_rest_users()
    {
        $label = __('Usernames via the REST API (/wp/v2/users)', 'modify-login');
        $response = self::request(rest_url('wp/v2/users'));

        if (is_wp_error($response)) {
            return self::result('error', $label, sprintf(
                /* translators: %s: error message */
                __('Couldn\'t test: the request failed (%s).', 'modify-login'),
                $response->get_error_message()
            ), '');
        }

        $users = json_decode($response['body'], true);
        if (200 === $response['code'] && is_array($users) && isset($users[0]['slug'])) {
            return self::result('warn', $label, sprintf(
                /* translators: %d: number of users */
                _n('Not a leak, but %d username is public. Attackers collect usernames before guessing passwords.', 'Not a leak, but %d usernames are public. Attackers collect usernames before guessing passwords.', count($users), 'modify-login'),
                count($users)
            ), __('Security → Hardening → turn on "Username discovery".', 'modify-login'));
        }

        return self::result('pass', $label, sprintf(
            /* translators: %s: HTTP status */
            __('No usernames listed (the server answered %s).', 'modify-login'),
            self::describe($response)
        ), '');
    }

    /**
     * ?author=1 redirects reveal usernames.
     *
     * @return array
     */
    private static function probe_author_scan()
    {
        $label = __('Usernames via ?author=1', 'modify-login');
        $response = self::request(add_query_arg('author', 1, home_url('/')));

        if (is_wp_error($response)) {
            return self::result('error', $label, sprintf(
                /* translators: %s: error message */
                __('Couldn\'t test: the request failed (%s).', 'modify-login'),
                $response->get_error_message()
            ), '');
        }

        if ($response['code'] >= 300 && $response['code'] < 400 && false !== strpos($response['location'], '/author/')) {
            return self::result('warn', $label, __('Not a leak, but ?author=1 redirects to an author archive that shows the username.', 'modify-login'), __('Security → Hardening → turn on "Username discovery".', 'modify-login'));
        }

        return self::result('pass', $label, sprintf(
            /* translators: %s: HTTP status */
            __('No username revealed (the server answered %s).', 'modify-login'),
            self::describe($response)
        ), '');
    }

    /**
     * Application passwords: REST and XML-RPC logins without the login URL.
     *
     * @return array
     */
    private static function probe_app_passwords()
    {
        $label = __('Application passwords', 'modify-login');
        $setting = (string) Settings::get('app_passwords', 'on');
        $available = function_exists('wp_is_application_passwords_available') && wp_is_application_passwords_available();

        if (!$available) {
            return self::result('pass', $label, __('Application passwords are not available on this site.', 'modify-login'), '');
        }

        if ('admins' === $setting) {
            return self::result('info', $label, __('Only administrators can use application passwords. Anyone holding one can log in through the REST API or XML-RPC without the login URL; that is how they are meant to work.', 'modify-login'), '');
        }

        return self::result('info', $label, __('Every user can create application passwords, which log in through the REST API and XML-RPC without the login URL. Hiding the login page does not cover them.', 'modify-login'), __('If no app or integration needs them, limit them to administrators or turn them off under Security → Hardening.', 'modify-login'));
    }

    /*
     * ---------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------
     */

    /**
     * One loopback request as a logged-out visitor.
     *
     * @param string       $url     URL on this site.
     * @param string       $method  GET or POST.
     * @param array        $headers Extra headers.
     * @param array|string $body    Body.
     * @return array|\WP_Error { code, body, location, cache }
     */
    private static function request($url, $method = 'GET', array $headers = array(), $body = null)
    {
        // Only ever this site's own URLs, so the slug never reaches anyone else.
        $host = wp_parse_url($url, PHP_URL_HOST);
        $own = array(wp_parse_url(home_url(), PHP_URL_HOST), wp_parse_url((string) get_option('siteurl'), PHP_URL_HOST));
        if (!$host || !in_array($host, $own, true)) {
            return new \WP_Error('authlify_foreign_url', __('Not a URL on this site.', 'modify-login'));
        }

        // Never let one slow probe run past the budget.
        $timeout = self::TIMEOUT;
        if (self::$deadline > 0) {
            if (self::out_of_time()) {
                return new \WP_Error('authlify_out_of_time', __('Skipped: the check ran out of time.', 'modify-login'));
            }
            $timeout = max(1, min(self::TIMEOUT, self::$deadline - time()));
        }

        $args = array(
            'method' => $method,
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'cookies' => array(),
            'headers' => array_merge(array(self::HEADER => self::token()), $headers),
            'limit_response_size' => 2 * MB_IN_BYTES,
            'reject_unsafe_urls' => false,
        );
        if (null !== $body) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $location = wp_remote_retrieve_header($response, 'location');
        $cache = wp_remote_retrieve_header($response, 'cache-control');

        return array(
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
            'location' => is_array($location) ? implode(' ', $location) : (string) $location,
            'cache' => is_array($cache) ? implode(', ', $cache) : (string) $cache,
        );
    }

    /**
     * What leaked: 'form', 'slug' or ''.
     *
     * @param string   $text  Response body or header.
     * @param string[] $slugs Slugs.
     * @return string
     */
    public static function find_leak($text, array $slugs)
    {
        $text = (string) $text;
        if ('' === $text) {
            return '';
        }

        if (self::has_form($text)) {
            return 'form';
        }

        foreach ($slugs as $slug) {
            if ('' !== $slug && preg_match(self::slug_pattern($slug), $text)) {
                return 'slug';
            }
        }

        return '';
    }

    /**
     * Pattern for the slug used as a URL: /slug, ?slug, \/slug (JSON) or %2Fslug.
     *
     * @param string $slug Slug.
     * @return string
     */
    private static function slug_pattern($slug)
    {
        return '#(?:/|\?|%2f)' . preg_quote($slug, '#') . '(?![a-z0-9_-])#i';
    }

    /**
     * Whether HTML contains the core login form.
     *
     * @param string $html HTML.
     * @return bool
     */
    private static function has_form($html)
    {
        return (bool) preg_match('#id\s*=\s*["\']loginform["\']#i', (string) $html);
    }

    /**
     * Guess which part of a public page prints the login URL.
     *
     * @param string $html HTML.
     * @return string
     */
    private static function public_source($html)
    {
        if (false !== strpos($html, 'widget_meta')) {
            return __('It looks like the Meta widget ("Log in" link).', 'modify-login');
        }
        if (preg_match('#must-log-in|comment-reply-login|logged-in-as#', $html)) {
            return __('It looks like a comment "log in to reply" link (comments require registration).', 'modify-login');
        }
        if (false !== strpos($html, 'wp-block-loginout')) {
            return __('It looks like a Login/out block.', 'modify-login');
        }

        return __('A theme or plugin prints the login URL (for example with wp_login_url() or wp_loginout()).', 'modify-login');
    }

    /**
     * Short description of a response, e.g. "404" or "302 to /-/-/".
     *
     * @param array $response Response.
     * @return string
     */
    private static function describe(array $response)
    {
        if ('' === $response['location']) {
            return (string) $response['code'];
        }

        $path = (string) wp_parse_url($response['location'], PHP_URL_PATH);
        $home = (string) wp_parse_url($response['location'], PHP_URL_HOST);

        return sprintf(
            /* translators: 1: HTTP status, 2: redirect target */
            __('%1$s redirect to %2$s', 'modify-login'),
            $response['code'],
            ('' !== $home && wp_parse_url(home_url(), PHP_URL_HOST) !== $home ? $home : '') . ('' !== $path ? $path : '/')
        );
    }

    /**
     * Build one probe result.
     *
     * @param string $status pass, fail, warn, info, skip or error.
     * @param string $label  Label.
     * @param string $detail What happened.
     * @param string $fix    What to do.
     * @return array
     */
    private static function result($status, $label, $detail, $fix)
    {
        return array(
            'status' => $status,
            'label' => $label,
            'detail' => $detail,
            'fix' => $fix,
        );
    }

    /**
     * Overall state from probe results.
     *
     * @param array $probes Probes.
     * @return string passed, failed, warning, untested or incomplete.
     */
    private static function state(array $probes)
    {
        $statuses = wp_list_pluck($probes, 'status');

        if (isset($probes['loopback'])) {
            return 'untested';
        }
        if (in_array('fail', $statuses, true)) {
            return 'failed';
        }
        if (isset($probes['hiding_off'])) {
            return 'warning';
        }
        if (in_array('error', $statuses, true)) {
            return 'incomplete';
        }

        return 'passed';
    }

    /**
     * Counts by bucket.
     *
     * @param array $probes Probes.
     * @return array passed, failed, warnings, skipped.
     */
    private static function counts(array $probes)
    {
        $counts = array('passed' => 0, 'failed' => 0, 'warnings' => 0, 'skipped' => 0);

        foreach ($probes as $probe) {
            switch ($probe['status']) {
                case 'pass':
                case 'info':
                    $counts['passed']++;
                    break;
                case 'fail':
                    $counts['failed']++;
                    break;
                case 'warn':
                    $counts['warnings']++;
                    break;
                default:
                    $counts['skipped']++;
            }
        }

        return $counts;
    }

    /**
     * One-line human summary of a result.
     *
     * @param array|null $result Result.
     * @return string
     */
    public static function summary($result)
    {
        if (!$result) {
            return __('The Leak Check has not run yet.', 'modify-login');
        }

        $c = $result['counts'];

        switch ($result['state']) {
            case 'off':
                return __('Custom login URL is off, so there was nothing to test.', 'modify-login');
            case 'untested':
                return __('Couldn\'t test: this server cannot make loopback requests to itself.', 'modify-login');
            case 'failed':
                /* translators: %d: number of leaks */
                return sprintf(_n('%d place reveals your login URL or login form.', '%d places reveal your login URL or login form.', $c['failed'], 'modify-login'), $c['failed']);
            case 'warning':
                return __('No leaks, but wp-login.php is not hidden.', 'modify-login');
            case 'incomplete':
                return __('No leaks found, but some probes could not run.', 'modify-login');
        }

        /* translators: %d: number of probes */
        return sprintf(__('No leaks: %d probes passed.', 'modify-login'), $c['passed']);
    }

    /*
     * ---------------------------------------------------------------------
     * Admin
     * ---------------------------------------------------------------------
     */

    /**
     * admin-post handler for the "Run Leak Check" button.
     */
    public static function handle_run()
    {
        if (!current_user_can(Plugin::cap())) {
            wp_die(esc_html__('You are not allowed to do this.', 'modify-login'), 403);
        }

        check_admin_referer('authlify_leak_check');

        $notice = 'leak_check_done';
        if (get_site_transient('authlify_leak_check_lock')) {
            $notice = 'leak_check_wait';
        } else {
            set_site_transient('authlify_leak_check_lock', time(), self::RATE_LIMIT);
            self::run('manual');
        }

        $back = wp_get_referer();
        if (!$back) {
            $back = Menu::url('dashboard');
        }

        wp_safe_redirect(add_query_arg('authlify_notice', $notice, remove_query_arg(array('authlify_notice', 'authlify_saved', 'authlify_error'), $back)) . '#authlify-leak-check');
        exit;
    }

    /**
     * Notice texts.
     *
     * @param array $messages Messages.
     * @return array
     */
    public static function notice_messages($messages)
    {
        $messages['leak_check_done'] = __('Leak Check finished. The results are below.', 'modify-login');
        $messages['leak_check_wait'] = __('The Leak Check ran less than 30 seconds ago, so these are its results. Try again in a moment.', 'modify-login');

        return $messages;
    }

    /**
     * Dashboard checklist item.
     *
     * @param array $checks Checks.
     * @return array
     */
    public static function dashboard_check($checks)
    {
        if ('' === Router::slug()) {
            return $checks;
        }

        $result = self::last();

        $checks['leak_check'] = array(
            $result && 'passed' === $result['state'],
            __('Leak Check passed', 'modify-login'),
            self::summary($result),
            Menu::url('dashboard') . '#authlify-leak-check',
        );

        return $checks;
    }

    /**
     * Probe requests are never written to the activity log.
     *
     * @param bool $skip Skip.
     * @return bool
     */
    public static function skip_probe_log($skip)
    {
        return $skip || self::is_probe();
    }

    /**
     * The "Run Leak Check" button.
     *
     * @param bool $primary Primary style.
     */
    public static function button($primary = false)
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="authlify-inline-form">
            <input type="hidden" name="action" value="authlify_leak_check">
            <?php wp_nonce_field('authlify_leak_check'); ?>
            <button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php esc_html_e('Run Leak Check', 'modify-login'); ?></button>
        </form>
        <?php
    }

    /**
     * One-line summary with a button (Login URL screen).
     */
    public static function render_strip()
    {
        $result = self::last();
        $state = $result ? $result['state'] : '';
        $type = 'passed' === $state ? 'ok' : ('failed' === $state ? 'error' : ('off' === $state ? 'info' : 'warning'));
        ?>
        <div class="authlify-strip">
            <?php echo \Authlify\Admin\Dashboard::status_icon('passed' === $state, 'passed' === $state ? __('Passed:', 'modify-login') : __('Needs attention:', 'modify-login')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="authlify-strip__text">
                <strong><?php esc_html_e('Leak Check', 'modify-login'); ?></strong>
                <?php if ($result) : ?>
                    · <?php echo esc_html(self::summary($result)); ?>
                    <span class="authlify-sublabel"> · <?php echo esc_html(sprintf(__('%s ago', 'modify-login'), human_time_diff((int) $result['time']))); ?></span>
                <?php else : ?>
                    · <?php esc_html_e('Not run yet. It checks that nothing on your site gives the login URL away.', 'modify-login'); ?>
                <?php endif; ?>
            </div>
            <?php if ($result && 'passed' !== $state) : ?>
                <a class="button button-small" href="<?php echo esc_url(\Authlify\Admin\Menu::url('dashboard') . '#authlify-leak-check'); ?>"><?php esc_html_e('See details', 'modify-login'); ?></a>
            <?php endif; ?>
            <?php self::button(); ?>
        </div>
        <?php
    }

    /**
     * Dashboard panel: one summary line, then only what needs attention.
     */
    public static function render_panel()
    {
        $result = self::last();
        $triggers = array(
            'manual' => __('run by hand', 'modify-login'),
            'settings' => __('after a settings change', 'modify-login'),
            'weekly' => __('weekly check', 'modify-login'),
            'cli' => __('WP-CLI', 'modify-login'),
        );

        UI::panel_start(
            __('Leak Check', 'modify-login'),
            __('Visits your own site as a logged-out visitor and checks that no page, redirect or email reveals your login URL.', 'modify-login'),
            'authlify-leak-check'
        );
        echo '<p class="authlify-panel__note">' . UI::learn_more('leak-check', __('How Leak Check works', 'modify-login')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in UI::learn_more().

        $state = $result ? $result['state'] : '';
        $meta = array();
        if ($result) {
            $c = $result['counts'];
            /* translators: %s: count */
            $meta[] = sprintf(_n('%s check passed', '%s checks passed', $c['passed'], 'modify-login'), number_format_i18n($c['passed']));
            if ($c['failed']) {
                /* translators: %s: count */
                $meta[] = sprintf(_n('%s leak', '%s leaks', $c['failed'], 'modify-login'), number_format_i18n($c['failed']));
            }
            if ($c['warnings']) {
                /* translators: %s: count */
                $meta[] = sprintf(_n('%s warning', '%s warnings', $c['warnings'], 'modify-login'), number_format_i18n($c['warnings']));
            }
            if ($c['skipped']) {
                /* translators: %s: count */
                $meta[] = sprintf(__('%s skipped', 'modify-login'), number_format_i18n($c['skipped']));
            }
            $meta[] = sprintf(
                /* translators: 1: time ago, 2: how it was started */
                __('last run %1$s ago (%2$s)', 'modify-login'),
                human_time_diff((int) $result['time']),
                isset($triggers[$result['trigger']]) ? $triggers[$result['trigger']] : $result['trigger']
            );
        } else {
            $meta[] = __('It takes a few seconds and changes nothing.', 'modify-login');
        }
        ?>
        <div class="authlify-summary" aria-live="polite">
            <?php echo \Authlify\Admin\Dashboard::status_icon('passed' === $state, 'passed' === $state ? __('Passed:', 'modify-login') : __('Needs attention:', 'modify-login')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="authlify-summary__text">
                <strong><?php echo esc_html('passed' === $state ? __('No leaks found', 'modify-login') : self::summary($result)); ?></strong>
                <span class="authlify-sublabel" title="<?php echo $result ? esc_attr(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $result['time'])) : ''; ?>"><?php echo esc_html(implode(' · ', $meta)); ?></span>
            </div>
            <?php self::button(!$result || in_array($state, array('failed', 'untested'), true)); ?>
        </div>
        <?php
        if ($result) {
            self::render_probes($result['probes']);
        }

        UI::panel_end();
    }

    /**
     * Dashboard Health card: the result in one line, a button, and the
     * details folded away.
     */
    public static function render_health()
    {
        $result = self::last();
        $state = $result ? $result['state'] : '';
        $ok = 'passed' === $state;
        $meta = array();
        if ($result) {
            $c = $result['counts'];
            /* translators: %s: count */
            $meta[] = sprintf(_n('%s check passed', '%s checks passed', $c['passed'], 'modify-login'), number_format_i18n($c['passed']));
            if ($c['warnings']) {
                /* translators: %s: count */
                $meta[] = sprintf(_n('%s warning', '%s warnings', $c['warnings'], 'modify-login'), number_format_i18n($c['warnings']));
            }
            /* translators: %s: time ago */
            $meta[] = sprintf(__('%s ago', 'modify-login'), human_time_diff((int) $result['time']));
        } else {
            $meta[] = __('Checks that nothing on your site gives the login URL away. It takes a few seconds and changes nothing.', 'modify-login');
        }
        $class = $ok ? ' is-ok' : ('failed' === $state ? ' is-error' : '');
        ?>
        <div class="authlify-health" aria-live="polite">
            <p class="authlify-health__line<?php echo esc_attr($class); ?>">
                <?php echo \Authlify\Admin\UI::icon($ok ? 'check' : ('failed' === $state ? 'alert' : 'info'), 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
                <span class="authlify-health__text">
                    <strong><span class="screen-reader-text"><?php echo $ok ? esc_html__('Passed:', 'modify-login') : esc_html__('Needs attention:', 'modify-login'); ?> </span><?php echo esc_html($ok ? __('Leak Check: no leaks found', 'modify-login') : ($result ? self::summary($result) : __('Leak Check has not run yet', 'modify-login'))); ?></strong>
                    <span><?php echo esc_html(implode(' · ', $meta)); ?></span>
                </span>
            </p>
            <div class="authlify-health__actions">
                <?php self::button(false); ?>
                <?php echo UI::learn_more('leak-check', __('How it works', 'modify-login')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in UI::learn_more(). ?>
            </div>
            <?php if ($result && !empty($result['probes'])) : ?>
                <details class="authlify-details">
                    <summary><?php
                        /* translators: %d: number of checks */
                        echo esc_html(sprintf(_n('Details of %d check', 'Details of %d checks', count($result['probes']), 'modify-login'), count($result['probes'])));
                    ?></summary>
                    <div><?php self::render_probes($result['probes']); ?></div>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Probe list: failures and warnings as rows, everything else folded away.
     *
     * @param array $probes Probes.
     */
    private static function render_probes(array $probes)
    {
        $order = array('fail' => 0, 'error' => 1, 'warn' => 2, 'info' => 3, 'skip' => 4, 'pass' => 5);
        $dots = array('fail' => 'error', 'error' => 'warning', 'warn' => 'warning', 'info' => 'info', 'skip' => 'info', 'pass' => 'ok');
        $labels = array(
            'fail' => __('Leak', 'modify-login'),
            'error' => __('Couldn\'t test', 'modify-login'),
            'warn' => __('Warning', 'modify-login'),
            'info' => __('Info', 'modify-login'),
            'skip' => __('Skipped', 'modify-login'),
            'pass' => __('Passed', 'modify-login'),
        );

        uasort($probes, function ($a, $b) use ($order) {
            $x = isset($order[$a['status']]) ? $order[$a['status']] : 9;
            $y = isset($order[$b['status']]) ? $order[$b['status']] : 9;

            return $x - $y;
        });

        $problems = array();
        $rest = array();
        foreach ($probes as $key => $probe) {
            if (in_array($probe['status'], array('fail', 'error', 'warn'), true)) {
                $problems[$key] = $probe;
            } else {
                $rest[$key] = $probe;
            }
        }

        $row = function ($probe, $full) use ($dots, $labels) {
            $status = isset($dots[$probe['status']]) ? $probe['status'] : 'info';
            ?>
            <li class="authlify-row authlify-row--top">
                <span class="authlify-dot authlify-dot--<?php echo esc_attr($dots[$status]); ?>" aria-hidden="true"></span>
                <div class="authlify-row__main">
                    <span class="authlify-row__title">
                        <strong><?php echo esc_html($probe['label']); ?></strong>
                        <?php echo UI::pill($labels[$status], $dots[$status]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </span>
                    <span class="authlify-sublabel"><?php echo esc_html($probe['detail']); ?></span>
                    <?php if ($full && '' !== $probe['fix']) : ?>
                        <span class="authlify-row__fix"><strong><?php esc_html_e('Fix:', 'modify-login'); ?></strong> <?php echo esc_html(str_replace('Protection →', __('Security', 'modify-login') . ' →', $probe['fix'])); // Results stored before the menu rename. ?></span>
                    <?php endif; ?>
                </div>
            </li>
            <?php
        };

        if ($problems) {
            echo '<ul class="authlify-checks">';
            foreach ($problems as $probe) {
                $row($probe, true);
            }
            echo '</ul>';
        }

        if ($rest) {
            ?>
            <details class="authlify-details">
                <summary><?php
                    /* translators: %d: number of probes */
                    echo esc_html(sprintf(_n('%d other check', '%d other checks', count($rest), 'modify-login'), count($rest)));
                ?></summary>
                <div>
                    <ul class="authlify-checks">
                        <?php
                        foreach ($rest as $probe) {
                            $row($probe, false);
                        }
                        ?>
                    </ul>
                </div>
            </details>
            <?php
        }
    }

    /*
     * ---------------------------------------------------------------------
     * WP-CLI
     * ---------------------------------------------------------------------
     */

    /**
     * Run the Leak Check and print the results.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp authlify leak-check
     *
     * @param array $args       Args.
     * @param array $assoc_args Options.
     */
    public static function cli($args, $assoc_args)
    {
        $result = self::run('cli');
        $rows = array();

        foreach ($result['probes'] as $key => $probe) {
            $rows[] = array(
                'probe' => $key,
                'status' => $probe['status'],
                'label' => $probe['label'],
                'detail' => $probe['detail'],
            );
        }

        \WP_CLI\Utils\format_items(isset($assoc_args['format']) ? $assoc_args['format'] : 'table', $rows, array('probe', 'status', 'label', 'detail'));

        $message = self::summary($result);
        if ('failed' === $result['state']) {
            \WP_CLI::error($message);
        }

        \WP_CLI::success($message);
    }
}
