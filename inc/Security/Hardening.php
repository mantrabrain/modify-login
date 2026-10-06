<?php
/**
 * Login-related hardening switches.
 *
 * @package Authlify
 */

namespace Authlify\Security;

use Authlify\Login\Router;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * XML-RPC, user enumeration, application passwords, generic login errors
 * and "force login" (private site).
 *
 * Every switch defaults to WordPress's normal behaviour; the admin opts in.
 */
final class Hardening
{
    /**
     * Whether the switches' hooks were added.
     *
     * @var bool
     */
    private static $wired = false;

    /**
     * Wire up.
     */
    public static function init()
    {
        // On a network, Authlify Pro applies a site's own settings (per-site
        // overrides) on plugins_loaded, after Authlify has booted: read the
        // switches once it has (PQA-AG-01). Every hook below runs later.
        if (!did_action('plugins_loaded') && !doing_action('plugins_loaded')) {
            add_action('plugins_loaded', array(__CLASS__, 'wire'), 7);

            return;
        }

        self::wire();
    }

    /**
     * Add the hooks for the switches that are on.
     *
     * @since 3.0.2
     */
    public static function wire()
    {
        if (self::$wired) {
            return;
        }
        self::$wired = true;

        // XML-RPC.
        $xmlrpc = Settings::get('xmlrpc', 'on');
        if ('off' === $xmlrpc) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', array(__CLASS__, 'no_xmlrpc_methods'), 999);
            add_action('init', array(__CLASS__, 'block_xmlrpc'), 1);
            add_filter('wp_headers', array(__CLASS__, 'remove_pingback_header'));
            remove_action('wp_head', 'rsd_link');
        } elseif ('no_multicall' === $xmlrpc) {
            // system.multicall is built into the XML-RPC server itself, so the
            // methods filter cannot remove it; refuse such requests up front.
            add_filter('xmlrpc_methods', array(__CLASS__, 'no_multicall'), 999);
            add_action('init', array(__CLASS__, 'block_multicall'), 1);
        }

        // User enumeration.
        if (Settings::get('block_user_enumeration', false)) {
            add_filter('rest_endpoints', array(__CLASS__, 'hide_user_endpoints'));
            add_action('template_redirect', array(__CLASS__, 'block_author_scan'), 1);
            add_filter('wp_sitemaps_add_provider', array(__CLASS__, 'no_user_sitemap'), 10, 2);
            add_filter('oembed_response_data', array(__CLASS__, 'strip_oembed_author'));
        }

        // Application passwords.
        $app = Settings::get('app_passwords', 'on');
        if ('off' === $app) {
            add_filter('wp_is_application_passwords_available', '__return_false');
        } elseif ('admins' === $app) {
            add_filter('wp_is_application_passwords_available_for_user', array(__CLASS__, 'app_passwords_admins_only'), 10, 2);
        }

        // One error for both unknown user and wrong password.
        if (Settings::get('generic_errors', false)) {
            add_filter('login_errors', array(__CLASS__, 'generic_login_error'));
            add_action('lostpassword_post', array(__CLASS__, 'generic_lost_password'), 999, 2);
        }

        // Private site.
        if (Settings::get('force_login', false)) {
            add_action('template_redirect', array(__CLASS__, 'force_login'), 0);
            add_filter('rest_authentication_errors', array(__CLASS__, 'force_login_rest'), 99);
            // wp-comments-post.php never reaches template_redirect.
            add_action('pre_comment_on_post', array(__CLASS__, 'force_login_comment'), 0);
        }
    }

    /**
     * Remove every XML-RPC method.
     *
     * @return array
     */
    public static function no_xmlrpc_methods()
    {
        return array();
    }

    /**
     * Refuse XML-RPC requests that call system.multicall.
     */
    public static function block_multicall()
    {
        if (!defined('XMLRPC_REQUEST') || !XMLRPC_REQUEST) {
            return;
        }

        $body = file_get_contents('php://input'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $method = is_string($body) ? self::xmlrpc_method($body) : '';

        // system.multicall is registered by the XML-RPC server itself, after
        // every filter: take it out of the method list in the response.
        if ('system.listmethods' === $method) {
            ob_start(array(__CLASS__, 'strip_multicall_listing'));

            return;
        }

        if ('system.multicall' === $method) {
            status_header(403);
            header('Content-Type: text/xml; charset=utf-8');
            echo '<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultCode</name><value><int>403</int></value></member><member><name>faultString</name><value><string>system.multicall is disabled on this site.</string></value></member></struct></value></fault></methodResponse>';
            exit;
        }
    }

    /**
     * Remove system.multicall from a system.listMethods response.
     *
     * @param string $xml Response.
     * @return string
     */
    public static function strip_multicall_listing($xml)
    {
        // Same length (spaces between elements): the server already sent Content-Length.
        return (string) preg_replace_callback('#<value>\s*<string>system\.multicall</string>\s*</value>#i', function ($m) {
            return str_repeat(' ', strlen($m[0]));
        }, (string) $xml);
    }

    /**
     * The method an XML-RPC request calls, decoded exactly as the server will
     * decode it (entities such as "system&#46;multicall", CDATA and comments).
     *
     * @param string $body Raw request body.
     * @return string Lowercase method name, or '' when there is none.
     */
    public static function xmlrpc_method($body)
    {
        if (!class_exists('IXR_Message', false) && defined('ABSPATH') && defined('WPINC') && file_exists(ABSPATH . WPINC . '/class-IXR.php')) {
            require_once ABSPATH . WPINC . '/class-IXR.php';
        }

        if (class_exists('IXR_Message')) {
            $message = new \IXR_Message($body);
            if ($message->parse() && isset($message->methodName)) {
                return strtolower(trim((string) $message->methodName));
            }
        }

        // Fallback: decode the methodName element by hand.
        if (preg_match('#<methodName>(.*?)</methodName>#is', $body, $m)) {
            $name = preg_replace('#<!--.*?-->|<!\[CDATA\[|\]\]>#s', '', $m[1]);

            return strtolower(trim(html_entity_decode((string) $name, ENT_QUOTES | ENT_XML1, 'UTF-8')));
        }

        return '';
    }

    /**
     * Answer xmlrpc.php with 403.
     */
    public static function block_xmlrpc()
    {
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'XML-RPC is disabled on this site.';
            exit;
        }
    }

    /**
     * Drop the X-Pingback header.
     *
     * @param array $headers Headers.
     * @return array
     */
    public static function remove_pingback_header($headers)
    {
        unset($headers['X-Pingback']);

        return $headers;
    }

    /**
     * system.multicall lets one request try hundreds of passwords.
     *
     * @param array $methods Methods.
     * @return array
     */
    public static function no_multicall($methods)
    {
        unset($methods['system.multicall']);

        return $methods;
    }

    /**
     * Hide /wp/v2/users from visitors who cannot list users.
     *
     * @param array $endpoints Endpoints.
     * @return array
     */
    public static function hide_user_endpoints($endpoints)
    {
        // Logged-in users keep the endpoints (the block editor needs /users/me and the author list).
        if (is_user_logged_in()) {
            return $endpoints;
        }

        foreach (array_keys($endpoints) as $route) {
            if (0 === strpos($route, '/wp/v2/users')) {
                unset($endpoints[$route]);
            }
        }

        return $endpoints;
    }

    /**
     * ?author=1 reveals usernames through the redirect to /author/name/.
     */
    public static function block_author_scan()
    {
        if (is_user_logged_in()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        if (isset($_GET['author']) || (is_author() && !is_404())) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
        }
    }

    /**
     * No users sitemap.
     *
     * @param mixed  $provider Provider.
     * @param string $name     Name.
     * @return mixed
     */
    public static function no_user_sitemap($provider, $name)
    {
        return 'users' === $name ? false : $provider;
    }

    /**
     * oEmbed data includes the author's name and URL.
     *
     * @param array $data Data.
     * @return array
     */
    public static function strip_oembed_author($data)
    {
        unset($data['author_name'], $data['author_url']);

        return $data;
    }

    /**
     * Application passwords only for administrators.
     *
     * @param bool     $available Available.
     * @param \WP_User $user      User.
     * @return bool
     */
    public static function app_passwords_admins_only($available, $user)
    {
        return $available && $user instanceof \WP_User && user_can($user, is_multisite() ? 'manage_network' : 'manage_options');
    }

    /**
     * Replace username/password hints with one message.
     *
     * @param string $error Error HTML.
     * @return string
     */
    public static function generic_login_error($error)
    {
        global $errors;

        if ($errors instanceof \WP_Error) {
            $codes = $errors->get_error_codes();
            if (array_intersect($codes, array('invalid_username', 'invalid_email', 'incorrect_password', 'invalidcombo'))) {
                $message = __('<strong>Error:</strong> The username or password is incorrect.', 'modify-login');
                // Notes about this address (attempts left, a lockout that has just
                // started) say nothing about the account, so keep them (FQA-05).
                foreach (array('authlify_attempts_left', 'authlify_locked') as $code) {
                    if (in_array($code, $codes, true)) {
                        $message .= '<br>' . $errors->get_error_message($code);
                    }
                }

                return $message;
            }
        }

        return $error;
    }

    /**
     * Lost password on the login page: an unknown username or email gets the
     * same "check your email" screen as a real one, so the form cannot be used
     * to find out which accounts exist.
     *
     * @param \WP_Error      $errors    Errors so far.
     * @param \WP_User|false $user_data The account, or false.
     */
    public static function generic_lost_password($errors, $user_data = false)
    {
        if ($user_data instanceof \WP_User || !did_action('login_init')) {
            return;
        }

        // Core reports an unknown email address as invalid_email before this
        // hook runs (and an unknown username as invalidcombo after it). Those
        // mean "no such account" and get the same answer as a real one; any
        // other error (an empty field, a failed CAPTCHA) is still shown.
        if ($errors instanceof \WP_Error && array_diff($errors->get_error_codes(), array('invalid_email', 'invalidcombo'))) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $to = !empty($_REQUEST['redirect_to']) ? wp_validate_redirect(wp_unslash($_REQUEST['redirect_to']), '') : '';
        wp_safe_redirect('' !== $to ? $to : add_query_arg('checkemail', 'confirm', wp_login_url()));
        exit;
    }

    /**
     * Send logged-out visitors to the login page.
     */
    public static function force_login()
    {
        if (is_user_logged_in() || Router::is_login_request() || Router::is_blocked_request() || self::is_excluded()) {
            return;
        }

        if (wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || is_robots() || is_feed() && !apply_filters('authlify_force_login_feeds', true)) {
            return;
        }

        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';

        // On a 404 the router prints "#" for the login URL (so hidden-login
        // 404 pages never link to it); a private site must still send the
        // visitor to the real login page, or the redirect loops on "#".
        $hide = has_filter('login_url', array(Router::class, 'filter_login_url_on_404'));
        if (false !== $hide) {
            remove_filter('login_url', array(Router::class, 'filter_login_url_on_404'), $hide);
        }
        $login = wp_login_url($scheme . $host . $uri);
        if (false !== $hide) {
            add_filter('login_url', array(Router::class, 'filter_login_url_on_404'), $hide, 3);
        }

        nocache_headers();
        wp_safe_redirect($login, 302);
        exit;
    }

    /**
     * A private site takes no comments from visitors who are not logged in
     * (wp-comments-post.php would otherwise store them and reveal the post's
     * address), except on pages the site keeps public.
     *
     * @param int $post_id Post the comment is for.
     */
    public static function force_login_comment($post_id)
    {
        if (is_user_logged_in()) {
            return;
        }

        $link = (string) get_permalink((int) $post_id);
        $path = '' !== $link ? (string) wp_parse_url($link, PHP_URL_PATH) : '';
        $home = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        if ('' !== $home && 0 === strpos($path, $home)) {
            $path = substr($path, strlen($home));
        }
        $path = '/' . trim($path, '/');
        $query = '' !== $link ? (string) wp_parse_url($link, PHP_URL_QUERY) : '';

        $public = false;
        foreach ('' === $query ? Settings::lines('force_login_exclude') : array() as $rule) {
            $rule = '/' . trim((string) wp_parse_url($rule, PHP_URL_PATH), '/');
            if ('/' === $rule ? '/' === $path : 0 === strpos($path . '/', rtrim($rule, '/') . '/')) {
                $public = true;
                break;
            }
        }

        /**
         * Filters whether a logged-out visitor may comment on a post of a private site.
         *
         * @param bool $public  Whether the post is on a public page.
         * @param int  $post_id Post ID.
         * @since 3.0.2
         */
        if (apply_filters('authlify_force_login_comment_allowed', $public, (int) $post_id)) {
            return;
        }

        wp_die(esc_html__('Please log in to comment.', 'modify-login'), esc_html__('Comment Submission Failure', 'modify-login'), array('response' => 403));
    }

    /**
     * Refuse anonymous REST requests on a private site.
     *
     * @param mixed $result Result.
     * @return mixed
     */
    public static function force_login_rest($result)
    {
        if (null !== $result || is_user_logged_in()) {
            return $result;
        }

        /**
         * Filters whether anonymous REST requests are allowed on a private site.
         *
         * @param bool $allow Allow.
         * @since 3.0.0
         */
        if (apply_filters('authlify_force_login_allow_rest', false)) {
            return $result;
        }

        return new \WP_Error('rest_not_logged_in', __('You must be logged in to use this site.', 'modify-login'), array('status' => 401));
    }

    /**
     * Whether the current URL is on the admin's exclusion list.
     *
     * The decision uses what WordPress actually resolved, not just the path:
     * query variables sent in the query string or the POST body (?p=6,
     * ?pagename=…) can make an excluded path show any other post, so a
     * request carrying any query variable beyond what the path itself means
     * is never excluded.
     *
     * @return bool
     */
    private static function is_excluded()
    {
        $path = '/' . Router::request_path();
        $extra = self::extra_query_vars();

        foreach (Settings::lines('force_login_exclude') as $rule) {
            $rule = '/' . trim((string) wp_parse_url($rule, PHP_URL_PATH), '/');
            if ($extra) {
                break;
            }
            // "/" means the homepage only: not search results, feeds or other query views.
            if ('/' === $rule) {
                if ('/' === $path) {
                    return true;
                }
                continue;
            }
            if (0 === strpos($path . '/', rtrim($rule, '/') . '/')) {
                return true;
            }
        }

        /**
         * Filters whether the current request is public on a private site.
         *
         * @param bool $excluded Excluded.
         * @since 3.0.0
         */
        return (bool) apply_filters('authlify_force_login_excluded', false);
    }

    /**
     * Query variables of this request that did not come from the URL path
     * (the rewrite rule), such as ?p=6 or a POSTed pagename.
     *
     * @return string[] Names.
     */
    private static function extra_query_vars()
    {
        global $wp;

        if (!$wp instanceof \WP) {
            return array();
        }

        $matched = array();
        if (!empty($wp->matched_query)) {
            wp_parse_str((string) $wp->matched_query, $matched);
        }

        $extra = array_diff(array_keys((array) $wp->query_vars), array_keys($matched));

        /**
         * Filters query variables that may accompany an excluded (public) path on a private site.
         *
         * @param string[] $allowed Names.
         * @since 3.0.0
         */
        $allowed = (array) apply_filters('authlify_force_login_allowed_vars', array('error'));

        return array_values(array_diff($extra, $allowed));
    }
}
