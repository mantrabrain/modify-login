<?php
/**
 * Easy Digital Downloads forms.
 *
 * @package Authlify
 */

namespace Authlify\Captcha\Integrations;

use Authlify\Captcha\Captcha;
use Authlify\Captcha\Integrations;

defined('ABSPATH') || exit;

/**
 * EDD's login, registration and lost-password forms (shortcodes and blocks).
 *
 * - Login: EDD signs in with wp_signon(), so the lockout gate, the CAPTCHA
 *   (checked before the password) and two-factor already run. This class
 *   claims the request for the "Login" switch, prints the widget, and shows
 *   Authlify's message instead of EDD's "Invalid username or password".
 * - Registration: checked on `edd_process_register_form` ("Registration").
 * - Lost password (block): EDD calls retrieve_password(), so the core
 *   lost-password check runs once the request is claimed ("Lost password").
 *
 * @since 3.1.0
 */
final class Edd
{
    /**
     * Whether a login on this request was refused by Authlify.
     *
     * @var bool
     */
    private static $refused = false;

    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('edd_login_fields_after', array(__CLASS__, 'render_login'));
        add_action('edd_register_form_fields_before_submit', array(__CLASS__, 'render_register'));
        add_action('edd_lost_password_fields_after', array(__CLASS__, 'render_lostpassword'));

        add_filter('authlify_captcha_login_form', array(__CLASS__, 'login_form'), 10, 1);
        add_filter('authlify_captcha_lostpassword_form', array(__CLASS__, 'lostpassword_form'));
        add_filter('authlify_captcha_message', array(__CLASS__, 'plain_message'), 10, 2);
        add_action('wp_login_failed', array(__CLASS__, 'login_failed'), 5, 2);
        add_action('template_redirect', array(__CLASS__, 'drop_generic_error'), 0);
        add_action('edd_process_register_form', array(__CLASS__, 'check_register'));
    }

    /**
     * Widget on the login form, moved in front of the submit button.
     */
    public static function render_login()
    {
        echo Captcha::render('login', array('before' => '.edd-login-submit, .edd-blocks-form__group-submit')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * Widget on the registration form.
     */
    public static function render_register()
    {
        echo Captcha::render('register'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * Widget on the lost-password block, moved in front of the submit button.
     */
    public static function render_lostpassword()
    {
        echo Captcha::render('lostpassword', array('before' => '.edd-blocks-form__group-submit')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * Whether this request is EDD's login form, by its own nonce.
     *
     * @return bool
     */
    public static function is_login_post()
    {
        return Integrations::is_post() && 'user_login' === Integrations::posted('edd_action')
            && false !== wp_verify_nonce(Integrations::posted('edd_login_nonce'), 'edd-login-nonce');
    }

    /**
     * Whether this request is EDD's lost-password block, by its own nonce.
     *
     * @return bool
     */
    public static function is_lostpassword_post()
    {
        return Integrations::is_post() && 'user_lost_password' === Integrations::posted('edd_action')
            && false !== wp_verify_nonce(Integrations::posted('edd_lost-password_nonce'), 'edd-lost-password-nonce');
    }

    /**
     * Claim EDD logins for the "Login" switch.
     *
     * @param string $form Form key so far.
     * @return string
     */
    public static function login_form($form)
    {
        return '' === $form && self::is_login_post() ? 'login' : $form;
    }

    /**
     * Claim EDD lost-password requests for the "Lost password" switch.
     *
     * @param string $form Form key so far.
     * @return string
     */
    public static function lostpassword_form($form)
    {
        return '' === $form && self::is_lostpassword_post() ? 'lostpassword' : $form;
    }

    /**
     * EDD prints its own "Error:" label and splits messages on colons: give
     * it the sentence alone.
     *
     * @param string $text Message.
     * @param string $form Form key.
     * @return string
     */
    public static function plain_message($text, $form = '')
    {
        if (!self::is_login_post() && !self::is_lostpassword_post() && 'user_register' !== Integrations::posted('edd_action')) {
            return $text;
        }

        return trim(wp_strip_all_tags(preg_replace('#^\s*<strong>[^<]*:\s*</strong>\s*#u', '', (string) $text)));
    }

    /**
     * Show Authlify's reason (lockout, block list, CAPTCHA) on EDD's form.
     *
     * @param string         $username Username.
     * @param \WP_Error|null $error    Error.
     */
    public static function login_failed($username, $error = null)
    {
        if (!$error instanceof \WP_Error || !in_array($error->get_error_code(), Integrations::error_codes(), true) || !self::is_login_post() || !function_exists('edd_set_error')) {
            return;
        }

        self::$refused = true;
        edd_set_error('authlify_login', Integrations::plain($error));
    }

    /**
     * Drop EDD's "Invalid username or password" when Authlify gave the reason:
     * a locked-out customer who knows their password must not be told it is wrong.
     */
    public static function drop_generic_error()
    {
        if (self::$refused && function_exists('edd_unset_error')) {
            edd_unset_error('edd_invalid_login');
        }
    }

    /**
     * Registration form.
     */
    public static function check_register()
    {
        if (!Integrations::is_post() || 'user_register' !== Integrations::posted('edd_action') || !function_exists('edd_set_error')) {
            return;
        }

        $error = Captcha::check('register', Integrations::posted('edd_user_login'));
        if ($error) {
            edd_set_error('authlify_captcha', Integrations::plain($error));
        }
    }
}
