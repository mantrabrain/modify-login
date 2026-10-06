<?php
/**
 * Custom login URL.
 *
 * @package Authlify
 */

namespace Authlify\Login;

use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Serves the login page at a custom URL and hides wp-login.php and wp-admin.
 *
 * Routing is done in PHP by adjusting $pagenow early, the approach WPS Hide
 * Login proved on millions of sites. No rewrite rules and no .htaccess edits,
 * so it works the same on Apache, Nginx and managed hosts.
 *
 * Leak routes closed here (each has a regression test in the Leak Check):
 * - the wp-login.php / wp-register.php / wp-signup.php / wp-activate.php scripts,
 *   including //wp-login.php, URL-encoded paths and trailing path segments;
 * - logged-out access to wp-admin (core's auth_redirect() would redirect to the
 *   custom URL), including customize.php and options.php with any Referer;
 * - /login, /admin and /dashboard shortcuts (wp_redirect_admin_locations);
 * - action=postpass without a password (CVE-2024-2473 in other plugins);
 * - auth_redirect() triggered by other plugins (CVE-2024-6289 in other plugins);
 * - privacy-request confirmation emails sent to people who are not users;
 * - login URLs printed on 404 pages.
 */
final class Router
{
    /**
     * A path no site uses, so WordPress resolves it to its 404 page.
     */
    const JUNK_PATH = '/-/-/-/-/-/-/-/-/-/-/';

    /**
     * What this request is: '', 'login' (custom URL) or 'blocked'.
     *
     * @var string
     */
    private static $request = '';

    /**
     * Wire up.
     */
    public static function init()
    {
        // Authlify boots while plugins load. On a network, Authlify Pro applies
        // a site's own settings (per-site overrides) on plugins_loaded, so look
        // at the settings again once it has: a site may have a login URL even
        // when the network has none (PQA-AG-01).
        if (!did_action('plugins_loaded') && !doing_action('plugins_loaded')) {
            add_action('plugins_loaded', array(__CLASS__, 'wire'), 7);
        }

        self::wire();
    }

    /**
     * Add the routing hooks when a login URL (active or pending) is set.
     * Safe to call more than once.
     *
     * @since 3.0.2
     */
    public static function wire()
    {
        if (has_action('wp_loaded', array(__CLASS__, 'serve')) || ('' === self::slug() && '' === self::pending_slug())) {
            return;
        }

        add_action('plugins_loaded', array(__CLASS__, 'route'), 9999);
        add_action('setup_theme', array(__CLASS__, 'block_customizer'), 1);
        add_action('wp_loaded', array(__CLASS__, 'serve'), 1);

        add_filter('site_url', array(__CLASS__, 'filter_url'), 10, 4);
        add_filter('network_site_url', array(__CLASS__, 'filter_url'), 10, 3);
        add_filter('wp_redirect', array(__CLASS__, 'filter_redirect'), 10, 2);
        add_filter('redirect_canonical', array(__CLASS__, 'filter_canonical'), 10, 2);
        add_action('wp_before_admin_bar_render', array(__CLASS__, 'hide_toolbar_login_links'));
        // WooCommerce prints its block settings (wcSettings.wpLoginUrl, from
        // wp_login_url()) at wp_print_footer_scripts priority 1, on the cart
        // and checkout pages for every visitor. Its own filter cannot change
        // that value, so wp_login_url() points elsewhere for that one moment.
        add_action('wp_print_footer_scripts', array(__CLASS__, 'start_public_login_url'), 0);
        add_action('wp_print_footer_scripts', array(__CLASS__, 'end_public_login_url'), 2);
        add_filter('login_url', array(__CLASS__, 'filter_login_url_on_404'), 10, 3);
        add_filter('site_option_welcome_email', array(__CLASS__, 'filter_welcome_email'));
        add_filter('user_request_action_email_content', array(__CLASS__, 'privacy_email_content'), 999, 2);
        add_action('template_redirect', array(__CLASS__, 'privacy_confirm'), 1);
        add_action('template_redirect', array(__CLASS__, 'disable_admin_shortcuts'), 1);
        add_action('login_init', array(__CLASS__, 'no_cache'), 1);
    }

    /**
     * The active login slug ('' when the custom URL is off).
     *
     * @return string
     */
    public static function slug()
    {
        return (string) Settings::get('login_slug', '');
    }

    /**
     * Slugs that can never be the login URL (WordPress paths and shortcuts).
     *
     * @return string[]
     */
    public static function reserved_slugs()
    {
        $reserved = array('wp-admin', 'wp-login', 'wp-login-php', 'login', 'admin', 'dashboard', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'xmlrpc', 'register', 'signup', 'wp-signup', 'wp-activate', 'wp-register', 'page', 'comments', 'search', 'author', 'category', 'tag', 'embed', 'sitemap', 'robots', 'favicon');
        global $wp;
        if ($wp instanceof \WP) {
            $reserved = array_merge($reserved, $wp->public_query_vars, $wp->private_query_vars);
        }

        return $reserved;
    }

    /**
     * Whether wp-login.php and wp-admin are hidden from logged-out visitors.
     *
     * @return bool
     */
    public static function is_hiding()
    {
        return '' !== self::slug() && (bool) Settings::get('block_wp_login', true);
    }

    /**
     * The custom login URL.
     *
     * @param string|null $scheme URL scheme.
     * @param string|null $slug   Slug to build the URL for (default: the active one).
     * @return string
     */
    public static function login_url($scheme = null, $slug = null)
    {
        $slug = null === $slug ? self::slug() : $slug;

        if ('' === $slug) {
            return site_url('wp-login.php', null === $scheme ? 'login' : $scheme);
        }

        if (self::plain_permalinks()) {
            return home_url('/', $scheme) . '?' . $slug;
        }

        // $wp_rewrite does not exist yet during plugins_loaded.
        $slug = isset($GLOBALS['wp_rewrite']) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ? user_trailingslashit($slug) : $slug;

        return home_url('/', $scheme) . $slug;
    }

    /**
     * Whether this request is for the custom login URL.
     *
     * @return bool
     */
    public static function is_login_request()
    {
        return 'login' === self::$request;
    }

    /**
     * Whether this request was for a hidden URL (answered with the blocked response).
     *
     * @return bool
     */
    public static function is_blocked_request()
    {
        return 'blocked' === self::$request;
    }

    /**
     * Classify the request as early as possible.
     */
    public static function route()
    {
        global $pagenow;

        $path = self::request_path();
        $slug = self::slug();
        $pending = self::pending_slug();

        $is_slug = self::matches_slug($path, $slug) || ('' !== $pending && self::matches_slug($path, $pending));

        if ($is_slug) {
            self::$request = 'login';
            $pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            // is_login() compares wp_login_url() with SCRIPT_NAME.
            $_SERVER['SCRIPT_NAME'] = (string) wp_parse_url(self::login_url(), PHP_URL_PATH);

            return;
        }

        if (!self::is_hiding() || is_admin() && !self::is_blocked_admin_request()) {
            return;
        }

        if (self::is_login_script($path) || self::is_blocked_admin_request()) {
            if (self::is_allowed_login_action()) {
                return;
            }

            // Modify Login 1.x served its login at wp-login.php?{slug}, so that
            // is what browsers and password managers saved: send it on.
            if (self::is_login_script($path) && self::is_legacy_link($slug)) {
                self::$request = 'legacy';

                return;
            }

            self::$request = 'blocked';

            // Make WordPress treat this as an ordinary front-end request so the
            // theme's 404 page can be served and nothing else hooks into it.
            $pagenow = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            $_SERVER['REQUEST_URI'] = self::JUNK_PATH;
        }
    }

    /**
     * Serve the custom login page, or the blocked response.
     */
    public static function serve()
    {
        global $pagenow, $error, $interim_login, $action, $user_login, $user, $redirect_to, $errors;

        if ('login' === self::$request) {
            $pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

            // Logged-in users visiting the bare login URL go to the dashboard.
            if (is_user_logged_in() && empty($_REQUEST['action']) && empty($_REQUEST['interim-login']) && empty($_REQUEST['reauth']) && empty($_GET['authlify_confirm']) && empty($_GET['authlify_preview']) && 'GET' === self::method()) { // phpcs:ignore WordPress.Security.NonceVerification
                $user = wp_get_current_user();
                $to = !empty($_GET['redirect_to']) ? wp_validate_redirect(esc_url_raw(wp_unslash($_GET['redirect_to'])), '') : ''; // phpcs:ignore WordPress.Security.NonceVerification
                $to = apply_filters('login_redirect', '' !== $to ? $to : admin_url(), $to, $user);
                wp_safe_redirect(apply_filters('authlify_logged_in_redirect', $to));
                exit;
            }

            self::no_cache();
            require_once ABSPATH . 'wp-login.php';
            exit;
        }

        if ('legacy' === self::$request) {
            nocache_headers();
            wp_safe_redirect(self::login_url(), 302);
            exit;
        }

        if ('blocked' === self::$request) {
            self::respond_blocked();
        }
    }

    /**
     * A saved Modify Login 1.x login link (wp-login.php?{slug}) on a site
     * that was upgraded from 1.x.
     *
     * @param string $slug Current slug.
     * @return bool
     */
    private static function is_legacy_link($slug)
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        return '' !== $slug && isset($_GET[$slug]) && '1.x' === \Authlify\Install\Upgrader::get('authlify_migrated_from', '');
    }

    /**
     * Route again after settings appeared mid-request (the first request
     * after an upgrade migrates the old login URL on init, after routing).
     */
    public static function reroute()
    {
        Settings::flush();
        if (has_action('wp_loaded', array(__CLASS__, 'serve')) || ('' === self::slug() && '' === self::pending_slug())) {
            return;
        }

        self::wire();
        self::route();
    }

    /**
     * Logged-out visitors never reach the Customizer (it would redirect to the login URL).
     */
    public static function block_customizer()
    {
        if ('blocked' !== self::$request || !isset($GLOBALS['wp_customize'])) {
            return;
        }

        // The Customizer boots on setup_theme, before a theme 404 page can be
        // rendered, so answer here with a redirect or a plain response.
        if ('redirect' === Settings::get('blocked_response', '404')) {
            self::respond_blocked();
        }

        do_action('authlify_blocked_request');
        nocache_headers();
        $code = '403' === Settings::get('blocked_response', '404') ? 403 : 404;
        wp_die(
            // Plain strings: translations are not loaded yet on setup_theme.
            403 === $code ? 'You do not have permission to access this page.' : 'Page not found.',
            '',
            array('response' => $code)
        );
    }

    /**
     * Send the configured response for a hidden URL.
     */
    public static function respond_blocked()
    {
        /**
         * Fires when a hidden login or admin URL is requested by a logged-out visitor.
         *
         * @since 3.0.0
         */
        do_action('authlify_blocked_request');

        $response = Settings::get('blocked_response', '404');

        if ('redirect' === $response) {
            $url = (string) Settings::get('blocked_redirect_url', '');
            // wp_safe_redirect() falls back to wp-admin for other hosts, which is itself
            // hidden and would loop; fall back to the homepage instead.
            wp_safe_redirect(wp_validate_redirect('' !== $url ? $url : home_url('/'), home_url('/')));
            exit;
        }

        nocache_headers();

        if ('403' === $response) {
            status_header(403);
            wp_die(esc_html__('You do not have permission to access this page.', 'modify-login'), 403);
        }

        // Inside wp-admin the theme cannot render (admin context), so send the
        // visitor to a front-end address that shows the theme's 404 page.
        if (is_admin()) {
            /**
             * Filters where blocked wp-admin requests are sent in "page not found" mode.
             *
             * @param string $url URL.
             * @since 3.0.0
             */
            wp_safe_redirect(apply_filters('authlify_admin_404_url', home_url(user_trailingslashit('404'))));
            exit;
        }

        self::theme_404();
    }

    /**
     * Render the theme's real 404 page.
     */
    private static function theme_404()
    {
        global $pagenow;
        $pagenow = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', true);
        }

        $_SERVER['REQUEST_URI'] = self::JUNK_PATH;

        wp();
        global $wp_query;
        if ($wp_query instanceof \WP_Query && !$wp_query->is_404()) {
            $wp_query->set_404();
        }
        status_header(404);
        nocache_headers();

        if (!did_action('template_redirect')) {
            require_once ABSPATH . WPINC . '/template-loader.php';
        }
        exit;
    }

    /**
     * Request path relative to the WordPress home, decoded and normalised.
     *
     * @return string Lowercase path without leading or trailing slashes.
     */
    public static function request_path()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        // Decode repeatedly so double-encoded paths are caught too.
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($uri);
            if ($decoded === $uri) {
                break;
            }
            $uri = $decoded;
        }

        $path = (string) wp_parse_url('http://x' . '/' . ltrim($uri, '/'), PHP_URL_PATH);
        $path = preg_replace('#/+#', '/', $path);
        $path = strtolower(trim($path, '/'));

        $home = strtolower(trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/'));
        if ('' !== $home && ($path === $home || 0 === strpos($path, $home . '/'))) {
            $path = ltrim(substr($path, strlen($home)), '/');
        }

        return $path;
    }

    /**
     * Whether a path (or the plain-permalink query) is the given slug.
     *
     * @param string $path Request path.
     * @param string $slug Slug.
     * @return bool
     */
    private static function matches_slug($path, $slug)
    {
        if ('' === $slug) {
            return false;
        }

        if ($path === $slug) {
            return true;
        }

        // /?slug, which also works with pretty permalinks as a fallback. Any
        // case, like the pretty /SLUG/ path (FQA-07).
        return '' === $path && isset($_GET) && in_array($slug, array_map('strtolower', array_map('strval', array_keys((array) $_GET))), true); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * Whether a path points at a login-type core script.
     *
     * @param string $path Request path.
     * @return bool
     */
    private static function is_login_script($path)
    {
        $scripts = array('wp-login.php', 'wp-login', 'wp-register.php');

        if (!is_multisite()) {
            $scripts[] = 'wp-signup.php';
            $scripts[] = 'wp-activate.php';
        }

        foreach ($scripts as $script) {
            if ($path === $script || 0 === strpos($path, $script . '/')) {
                return true;
            }
        }

        // The same script names in any folder (CMPT-02): /x/wp-register.php,
        // /wp-content/wp-register.php or, with WordPress in its own folder,
        // /wp/wp-register.php. Core's canonical redirect sends any path ending
        // in wp-register.php to the registration URL, which is the custom
        // login URL, before any redirect filter runs.
        $segments = explode('/', $path);
        foreach (array('wp-login.php', 'wp-register.php', 'wp-signup.php') as $script) {
            if ('wp-signup.php' === $script && is_multisite()) {
                continue;
            }
            if (in_array($script, $segments, true)) {
                return true;
            }
        }

        // Also catch the script reached through any directory, e.g. /anything/wp-login.php.
        global $pagenow;

        return in_array($pagenow, array('wp-login.php', 'wp-register.php'), true)
            || (!is_multisite() && in_array($pagenow, array('wp-signup.php', 'wp-activate.php'), true));
    }

    /**
     * Whether this is a logged-out request to wp-admin that must be hidden.
     *
     * @return bool
     */
    private static function is_blocked_admin_request()
    {
        if (!is_admin() || is_user_logged_in()) {
            return false;
        }

        if (wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return false;
        }

        global $pagenow;

        // admin-post.php handles front-end forms for visitors; load-*.php serve login-page CSS/JS.
        $open = apply_filters('authlify_public_admin_scripts', array('admin-post.php', 'admin-ajax.php', 'load-styles.php', 'load-scripts.php'));

        return !in_array($pagenow, $open, true);
    }

    /**
     * wp-login.php actions that must keep working for everyone.
     *
     * Only a password-protected-post form submission qualifies. Core answers it
     * with a redirect and never renders the login form.
     *
     * @return bool
     */
    private static function is_allowed_login_action()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

        // Core re-derives its action from $_GET (key, checkemail, action), so the
        // query string must hold nothing but action=postpass; otherwise a POST
        // could smuggle another login screen past this allowance.
        $get_keys = array_diff(array_keys((array) $_GET), array('action'));
        $get_action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : 'postpass';

        if ('postpass' === $action && 'postpass' === $get_action && !$get_keys && 'POST' === self::method() && isset($_POST['post_password']) && is_string($_POST['post_password'])) {
            return true;
        }
        // phpcs:enable

        /**
         * Filters whether a wp-login.php request should be let through while hidden.
         *
         * @param bool   $allowed Allowed.
         * @param string $action  The requested action.
         * @since 3.0.0
         */
        return (bool) apply_filters('authlify_allow_wp_login_request', false, $action);
    }

    /**
     * Rewrite wp-login.php URLs to the custom URL.
     *
     * @param string      $url     URL.
     * @param string      $path    Path.
     * @param string|null $scheme  Scheme.
     * @param int|null    $blog_id Blog ID.
     * @return string
     */
    public static function filter_url($url, $path = '', $scheme = null, $blog_id = null)
    {
        return self::replace_login_php($url, $scheme);
    }

    /**
     * Rewrite redirects to wp-login.php.
     *
     * @param string $location Location.
     * @param int    $status   Status.
     * @return string
     */
    public static function filter_redirect($location, $status = 302)
    {
        // Redirects that come from wp-login.php itself (e.g. after a
        // password-protected-post submission) must not point at the custom URL.
        if (false !== strpos((string) wp_get_referer(), 'wp-login.php') && !self::is_login_request()) {
            return $location;
        }

        // A logged-out visitor who is not on the login page must never be
        // redirected to the custom URL: core redirects to wherever a visitor
        // asks (the comment form's redirect_to) or to the canonical form of
        // the requested path (/index.php/wp-login.php), and either would hand
        // out the slug. The redirect keeps pointing at wp-login.php, which
        // answers with the blocked response. Lost password, registration,
        // logout, interim login and the 2FA screens all run on the login page.
        if (self::is_hiding() && !self::is_login_request() && !is_user_logged_in()) {
            return $location;
        }

        return self::replace_login_php($location);
    }

    /**
     * Never let a canonical redirect hand a logged-out visitor the custom
     * login URL (CMPT-02, defence in depth: the wp-register.php case is
     * answered by the router before core's canonical code runs).
     *
     * @param string|false $redirect_url  Canonical URL.
     * @param string       $requested_url Requested URL.
     * @return string|false
     */
    public static function filter_canonical($redirect_url, $requested_url = '')
    {
        if (!is_string($redirect_url) || '' === $redirect_url || !self::is_hiding() || self::is_login_request() || is_user_logged_in()) {
            return $redirect_url;
        }

        return self::points_to_login($redirect_url) ? false : $redirect_url;
    }

    /**
     * Whether a URL points at the custom (or pending) login URL.
     *
     * @param string $url URL.
     * @return bool
     */
    public static function points_to_login($url)
    {
        $path = strtolower(trim((string) wp_parse_url($url, PHP_URL_PATH), '/'));
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        foreach (array_filter(array(self::slug(), self::pending_slug())) as $slug) {
            $login = strtolower(trim((string) wp_parse_url(self::login_url(null, $slug), PHP_URL_PATH), '/'));
            if ('' !== $login && $path === $login) {
                return true;
            }
            // Plain permalinks: /?slug.
            if ('' !== $query && preg_match('#(?:^|&)' . preg_quote($slug, '#') . '(?:=|&|$)#i', $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where a public page may send visitors to log in while the login URL is
     * hidden: WooCommerce My Account when it exists, else the home page.
     *
     * @return string
     * @since 3.0.2
     */
    public static function public_login_url()
    {
        $url = '';
        if (function_exists('wc_get_page_id') && wc_get_page_id('myaccount') > 0) {
            $url = (string) wc_get_page_permalink('myaccount');
        }

        /**
         * Filters the address public pages use instead of the hidden login URL
         * (WooCommerce block settings). Never return the login URL itself.
         *
         * @param string $url URL ('' for the home page).
         * @since 3.0.2
         */
        $url = (string) apply_filters('authlify_public_login_url', $url);

        return '' !== $url ? $url : home_url('/');
    }

    /**
     * Footer scripts on the front end for logged-out visitors: wp_login_url()
     * gives the public login address while WooCommerce prints its settings.
     */
    public static function start_public_login_url()
    {
        if (is_admin() || is_user_logged_in() || !self::is_hiding() || self::is_login_request() || !class_exists('WooCommerce', false)) {
            return;
        }

        add_filter('login_url', array(__CLASS__, 'swap_login_url'), PHP_INT_MAX);
    }

    /**
     * Back to the real login URL once WooCommerce has printed its settings.
     */
    public static function end_public_login_url()
    {
        remove_filter('login_url', array(__CLASS__, 'swap_login_url'), PHP_INT_MAX);
    }

    /**
     * login_url filter used between the two hooks above.
     *
     * @param string $login_url Login URL.
     * @return string
     */
    public static function swap_login_url($login_url)
    {
        return self::points_to_login((string) $login_url) ? self::public_login_url() : $login_url;
    }

    /**
     * Toolbar links to the login page for logged-out visitors (CMPT-04).
     *
     * Core shows the toolbar to logged-in people only, but BuddyPress (and
     * some themes) show it to visitors too, with a "Log In" item built from
     * wp_login_url(): that would print the custom URL on every public page.
     * While the login URL is hidden, those items are removed.
     */
    public static function hide_toolbar_login_links()
    {
        global $wp_admin_bar;

        if (is_user_logged_in() || !self::is_hiding() || self::is_login_request() || !$wp_admin_bar instanceof \WP_Admin_Bar) {
            return;
        }

        /**
         * Filters whether toolbar items that link to the login page are removed for logged-out visitors.
         *
         * @param bool $hide Hide (default true while the login URL is hidden).
         * @since 3.0.2
         */
        if (!apply_filters('authlify_hide_toolbar_login_links', true)) {
            return;
        }

        foreach ((array) $wp_admin_bar->get_nodes() as $id => $node) {
            if (!empty($node->href) && self::points_to_login((string) $node->href)) {
                $wp_admin_bar->remove_node($id);
            }
        }
    }

    /**
     * Replace a wp-login.php URL with the custom URL, keeping its query string.
     *
     * @param string      $url    URL.
     * @param string|null $scheme Scheme.
     * @return string
     */
    private static function replace_login_php($url, $scheme = null)
    {
        // While only a pending slug exists, wp-login.php stays the real login page.
        if (!is_string($url) || false === strpos($url, 'wp-login.php') || '' === self::slug()) {
            return $url;
        }

        // Only the site's own wp-login.php, not any URL that merely contains the
        // name (e.g. a canonical redirect for /anything/wp-login.php/).
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $paths = array(untrailingslashit((string) wp_parse_url(site_url(), PHP_URL_PATH)) . '/wp-login.php');
        // Core's own screens redirect to the relative "wp-login.php?checkemail=…"
        // (after lost password and registration). WordPress resolves that
        // against the current URL, so on the custom login page it becomes
        // /{slug}/wp-login.php: that is ours too.
        if (self::is_login_request()) {
            foreach (array_filter(array(self::slug(), self::pending_slug())) as $slug) {
                $paths[] = untrailingslashit((string) wp_parse_url(self::login_url(null, $slug), PHP_URL_PATH)) . '/wp-login.php';
            }
        }
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if (!in_array(untrailingslashit($path), $paths, true) || ('' !== $host && strtolower($host) !== strtolower((string) wp_parse_url(site_url(), PHP_URL_HOST)))) {
            return $url;
        }

        // Multisite installer links must stay untouched.
        if (is_multisite() && false !== strpos($url, 'install.php')) {
            return $url;
        }

        // Password-protected post forms post to wp-login.php?action=postpass.
        if (false !== strpos($url, 'action=postpass')) {
            return $url;
        }

        // Gravity Forms (and similar) call auth_redirect() for logged-out visitors
        // on their own pages; never hand them the custom URL (CVE-2024-6289).
        if (!is_user_logged_in() && isset($_GET['gf_page'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return $url;
        }

        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $target = self::login_url($scheme);

        if ('' !== $query) {
            $target .= (false === strpos($target, '?') ? '?' : '&') . $query;
        }

        return $target;
    }

    /**
     * wp_login_url() prints "#" on 404 pages, so a hidden-login 404 never links to it.
     *
     * @param string $login_url    Login URL.
     * @param string $redirect     Redirect.
     * @param bool   $force_reauth Force reauth.
     * @return string
     */
    public static function filter_login_url_on_404($login_url, $redirect = '', $force_reauth = false)
    {
        if (self::is_hiding() && !is_user_logged_in() && did_action('template_redirect') && is_404()) {
            return '#';
        }

        // On a network that lets visitors register sites, wp-signup.php tells
        // logged-out visitors to "log in first" with a link to the login URL
        // (SEC2-10). The sign-up page is public, so it must not hand it out.
        if (self::is_hiding() && !is_user_logged_in() && is_multisite() && isset($GLOBALS['pagenow']) && 'wp-signup.php' === $GLOBALS['pagenow']) {
            return '#';
        }

        return $login_url;
    }

    /**
     * Multisite welcome email.
     *
     * @param string $value Email text.
     * @return string
     */
    public static function filter_welcome_email($value)
    {
        if ('' === self::slug()) {
            return $value;
        }

        return str_replace(array('wp-login.php', site_url('wp-login.php')), array(trailingslashit(self::slug()), self::login_url()), (string) $value);
    }

    /**
     * Privacy-request confirmation emails go to people who may not be users, so
     * they link to wp-login.php. privacy_confirm() then forwards only requests
     * that carry a valid confirmation key.
     *
     * @param string $content Email content.
     * @param array  $data    Email data.
     * @return string
     */
    public static function privacy_email_content($content, $data)
    {
        if (!self::is_hiding() || empty($data['confirm_url'])) {
            return $content;
        }

        // Built without site_url(), which this class filters back to the custom URL.
        $wp_login = set_url_scheme(untrailingslashit((string) get_option('siteurl')) . '/wp-login.php', 'login');
        $original = str_replace(self::login_url(), $wp_login, (string) $data['confirm_url']);
        $original = str_replace(trailingslashit(home_url()) . '?' . self::slug() . '&', $wp_login . '?', $original);

        return str_replace('###CONFIRM_URL###', esc_url_raw($original), $content);
    }

    /**
     * Forward a privacy confirmation link to the login page when its key is valid.
     */
    public static function privacy_confirm()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        if (!isset($_GET['action'], $_GET['request_id'], $_GET['confirm_key']) || 'confirmaction' !== $_GET['action']) {
            return;
        }

        $request_id = (int) $_GET['request_id'];
        $key = sanitize_text_field(wp_unslash($_GET['confirm_key']));
        // phpcs:enable

        if (!wp_get_user_request($request_id)) {
            return;
        }

        if (true === wp_validate_user_request_key($request_id, $key)) {
            wp_safe_redirect(add_query_arg(array(
                'action' => 'confirmaction',
                'request_id' => $request_id,
                'confirm_key' => $key,
            ), self::login_url()));
            exit;
        }
    }

    /**
     * /login, /admin and /dashboard would redirect logged-out visitors to the login URL.
     */
    public static function disable_admin_shortcuts()
    {
        if (self::is_hiding() && !is_user_logged_in()) {
            remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
        }
    }

    /**
     * Keep the login page out of every cache.
     */
    public static function no_cache()
    {
        if (!self::is_login_request()) {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        // Core adds no-store for logged-out visitors only from WordPress 6.8;
        // this also covers wp-login.php's own later nocache_headers() call.
        add_filter('nocache_headers', array(__CLASS__, 'no_store'));

        if (!headers_sent()) {
            nocache_headers();
            header('X-Robots-Tag: noindex, nofollow', true);
        }
    }

    /**
     * Make the no-cache headers forbid storing the page.
     *
     * @param array $headers Headers.
     * @return array
     */
    public static function no_store($headers)
    {
        $headers['Cache-Control'] = 'no-cache, must-revalidate, max-age=0, no-store, private';

        return $headers;
    }

    /**
     * Pending slug awaiting confirmation (works alongside the active one).
     *
     * @return string
     */
    public static function pending_slug()
    {
        $slug = (string) Settings::get('pending_slug', '');
        $expires = (int) Settings::get('pending_expires', 0);

        return ('' !== $slug && $expires > time()) ? $slug : '';
    }

    /**
     * Whether plain permalinks are in use.
     *
     * @return bool
     */
    private static function plain_permalinks()
    {
        return '' === (string) get_option('permalink_structure');
    }

    /**
     * HTTP method.
     *
     * @return string
     */
    private static function method()
    {
        return isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
    }
}
