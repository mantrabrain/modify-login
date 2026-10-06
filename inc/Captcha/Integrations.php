<?php
/**
 * CAPTCHA and lockout coverage for other plugins' login forms.
 *
 * @package Authlify
 */

namespace Authlify\Captcha;

use Authlify\Security\Limiter;

defined('ABSPATH') || exit;

/**
 * Loads one small integration per plugin, only while that plugin is active:
 * WooCommerce block checkout, Easy Digital Downloads, MemberPress, Ultimate
 * Member and BuddyPress/BuddyBoss.
 *
 * The integrations add no settings of their own. Their forms follow the
 * existing form switches: a login form is covered when "Login" is ticked,
 * a sign-up form when "Registration" is, a lost-password form when "Lost
 * password" is, and the block checkout when "WooCommerce checkout" is (or
 * "WooCommerce registration" when the order creates an account). Lockouts
 * and the block list already apply to every login that goes through
 * WordPress; the integrations make the plugin show the real message and stop
 * before the password is checked.
 *
 * @since 3.1.0
 */
final class Integrations
{
    /**
     * Integration classes by key: key => array( class, detect callable ).
     *
     * @return array
     */
    public static function all()
    {
        $all = array(
            'woo_blocks' => array(Integrations\WooBlocks::class, function () {
                return class_exists('WooCommerce') && function_exists('woocommerce_store_api_register_endpoint_data');
            }),
            'edd' => array(Integrations\Edd::class, function () {
                return function_exists('edd_log_user_in') || class_exists('Easy_Digital_Downloads');
            }),
            'memberpress' => array(Integrations\MemberPress::class, function () {
                return defined('MEPR_VERSION') || class_exists('MeprLoginCtrl');
            }),
            'ultimate_member' => array(Integrations\UltimateMember::class, function () {
                return function_exists('UM') && class_exists('UM');
            }),
            'buddypress' => array(Integrations\BuddyPress::class, function () {
                return function_exists('buddypress') || function_exists('bp_core_screen_signup');
            }),
        );

        /**
         * Filters the form integrations: key => array( class with static init(), detect callable ).
         *
         * @param array $all Integrations.
         * @since 3.1.0
         */
        return (array) apply_filters('authlify_captcha_integrations', $all);
    }

    /**
     * Keys of integrations that loaded on this request.
     *
     * @var string[]
     */
    private static $loaded = array();

    /**
     * Wire up once every plugin has loaded.
     */
    public static function init()
    {
        if (did_action('plugins_loaded')) {
            self::load();
        } else {
            add_action('plugins_loaded', array(__CLASS__, 'load'), 30);
        }
    }

    /**
     * Load the integrations whose plugin is active.
     */
    public static function load()
    {
        if (self::$loaded) {
            return;
        }

        foreach (self::all() as $key => $def) {
            if (!is_array($def) || !isset($def[0], $def[1]) || !is_callable($def[1]) || !call_user_func($def[1])) {
                continue;
            }
            if (class_exists($def[0]) && method_exists($def[0], 'init')) {
                call_user_func(array($def[0], 'init'));
                self::$loaded[] = (string) $key;
            }
        }
    }

    /**
     * Keys of the integrations running on this site.
     *
     * @return string[]
     */
    public static function loaded()
    {
        return self::$loaded;
    }

    /**
     * Names of the plugins covered right now, for the settings screen.
     *
     * @return string[]
     */
    public static function labels()
    {
        $names = array(
            'woo_blocks' => __('WooCommerce block checkout', 'modify-login'),
            'edd' => __('Easy Digital Downloads', 'modify-login'),
            'memberpress' => __('MemberPress', 'modify-login'),
            'ultimate_member' => __('Ultimate Member', 'modify-login'),
            'buddypress' => function_exists('buddypress') && defined('BP_PLATFORM_VERSION') ? __('BuddyBoss', 'modify-login') : __('BuddyPress', 'modify-login'),
        );

        $out = array();
        foreach (self::$loaded as $key) {
            if (isset($names[$key])) {
                $out[$key] = $names[$key];
            }
        }

        return $out;
    }

    /**
     * Everything that stops a login on another plugin's form before the
     * password is checked: the block list, a lockout or account pause, then
     * the CAPTCHA. Returns the error to show, or null.
     *
     * @param string $username Submitted username or email.
     * @return \WP_Error|null
     */
    public static function login_gate($username)
    {
        $gate = Limiter::gate_error((string) $username);
        if ($gate) {
            return $gate;
        }

        return Captcha::check('login', (string) $username);
    }

    /**
     * Error codes Authlify returns from a login, for plugins that replace
     * unknown errors with their own "wrong password" text.
     *
     * @return string[]
     */
    public static function error_codes()
    {
        return array('authlify_locked', 'authlify_denied', 'authlify_captcha', 'authlify_2fa_api', 'authlify_sign_in_blocked');
    }

    /**
     * An error message as plain text (for plugins that escape what they print).
     *
     * @param \WP_Error $error Error.
     * @return string
     */
    public static function plain(\WP_Error $error)
    {
        // Drop core's "<strong>Error:</strong>" label: these plugins print their own.
        $text = preg_replace('#^\s*<strong>[^<]*:\s*</strong>\s*#u', '', (string) $error->get_error_message());
        $text = wp_strip_all_tags(str_replace(array('<br>', '<br/>', '<br />'), ' ', $text));

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Whether this is a POST request.
     *
     * @return bool
     */
    public static function is_post()
    {
        return isset($_SERVER['REQUEST_METHOD']) && 'POST' === strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])));
    }

    /**
     * A posted string field.
     *
     * @param string $name Field.
     * @return string
     */
    public static function posted($name)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the integrations check the plugin's own nonce first.
        return isset($_POST[$name]) && is_string($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
    }
}
