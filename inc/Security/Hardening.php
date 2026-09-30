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
     * Wire up.
     */
    public static function init()
    {
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
        if (is_string($body) && 'system.multicall' === self::xmlrpc_method($body)) {
            status_header(403);
            header('Content-Type: text/xml; charset=utf-8');
            echo '<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultCode</name><value><int>403</int></value></member><member><name>faultString</name><value><string>system.multicall is disabled on this site.</string></value></member></struct></value></fault></methodResponse>';
            exit;
        }
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
                return __('<strong>Error:</strong> The username or password is incorrect.', 'modify-login');
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
        if ($user_data instanceof \WP_User || !did_action('login_init') || ($errors instanceof \WP_Error && $errors->has_errors())) {
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

        nocache_headers();
        wp_safe_redirect(wp_login_url($scheme . $host . $uri), 302);
        exit;
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
