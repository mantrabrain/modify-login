<?php
/**
 * Ultimate Member forms.
 *
 * @package Authlify
 */

namespace Authlify\Captcha\Integrations;

use Authlify\Captcha\Captcha;
use Authlify\Captcha\Integrations;

defined('ABSPATH') || exit;

/**
 * Ultimate Member's login, registration and password-reset forms.
 *
 * UM checks the password itself before it runs the `authenticate` filters,
 * and replaces any error it does not know with "Password is incorrect". So
 * the block list, lockouts and the CAPTCHA are checked first (priority 1 on
 * UM's login validation), and when one of them refuses, UM's own password
 * check is skipped for that request and Authlify's message is shown
 * (CMPT-08). A locked-out member who knows their password is never told it
 * is wrong, and a refused bot never costs a password hash.
 *
 * @since 3.1.0
 */
final class UltimateMember
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('um_after_login_fields', array(__CLASS__, 'render_login'), 500);
        add_action('um_after_register_fields', array(__CLASS__, 'render_register'), 500);
        add_action('um_after_password_reset_fields', array(__CLASS__, 'render_lostpassword'), 500);
        add_action('um_before_form', array(__CLASS__, 'print_error'), 5, 1);

        add_action('um_submit_form_errors_hook_login', array(__CLASS__, 'check_login'), 1, 1);
        add_action('um_submit_form_errors_hook__registration', array(__CLASS__, 'check_register'), 1, 1);
        add_action('um_reset_password_errors_hook', array(__CLASS__, 'check_lostpassword'), 1, 1);
        add_filter('um_custom_authenticate_error_codes', array(__CLASS__, 'error_codes'));
        add_filter('authlify_captcha_login_form', array(__CLASS__, 'login_form'));
    }

    /**
     * Widget on the login form (UM prints its submit button at 1000).
     */
    public static function render_login()
    {
        echo Captcha::render('login'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * Widget on the registration form.
     */
    public static function render_register()
    {
        echo Captcha::render('register'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * Widget on the password-reset form.
     */
    public static function render_lostpassword()
    {
        echo Captcha::render('lostpassword'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * UM's login validation runs `authenticate` itself: the request is a login.
     *
     * @param string $form Form key so far.
     * @return string
     */
    public static function login_form($form)
    {
        return '' === $form && doing_action('um_submit_form_errors_hook_login') ? 'login' : $form;
    }

    /**
     * Show Authlify's errors as themselves, not as "Password is incorrect".
     *
     * @param array $codes Codes.
     * @return array
     */
    public static function error_codes($codes)
    {
        return array_values(array_unique(array_merge((array) $codes, Integrations::error_codes())));
    }

    /**
     * The login name UM was given.
     *
     * @param array $data Submitted data.
     * @return string
     */
    private static function login_name($data)
    {
        foreach (array('username', 'user_login', 'user_email') as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && '' !== trim($data[$key])) {
                return sanitize_text_field($data[$key]);
            }
        }

        return '';
    }

    /**
     * Login: block list, lockout and CAPTCHA before UM checks the password.
     *
     * @param array $data Submitted data.
     */
    public static function check_login($data)
    {
        if (!is_array($data) || !self::form()) {
            return;
        }

        $error = Integrations::login_gate(self::login_name($data));
        if (!$error) {
            return;
        }

        UM()->form()->add_error($error->get_error_code(), $error->get_error_message());
        // Refused before the password: UM's own check would say "Password is
        // incorrect" (or confirm a right one) and cost a password hash.
        remove_action('um_submit_form_errors_hook_login', 'um_submit_form_errors_hook_login', 10);
    }

    /**
     * Registration.
     *
     * @param array $data Submitted data.
     */
    public static function check_register($data)
    {
        if (!is_array($data) || !self::form()) {
            return;
        }

        $error = Captcha::check('register', isset($data['user_login']) && is_string($data['user_login']) ? sanitize_user($data['user_login']) : '');
        if ($error) {
            UM()->form()->add_error('authlify_captcha', Integrations::plain($error));
        }
    }

    /**
     * Password reset request.
     *
     * @param array $data Submitted data.
     */
    public static function check_lostpassword($data)
    {
        if (!is_array($data) || !self::form()) {
            return;
        }

        $user = '';
        foreach ($data as $key => $value) {
            if (is_string($key) && false !== strpos($key, 'username_b') && is_string($value)) {
                $user = sanitize_text_field($value);
            }
        }

        $error = Captcha::check('lostpassword', $user);
        if ($error) {
            UM()->form()->add_error('username_b', Integrations::plain($error));
        }
    }

    /**
     * Registration forms do not print errors that belong to no field: print ours.
     *
     * @param array $args Form arguments.
     */
    public static function print_error($args = array())
    {
        $form = self::form();
        if (!$form || empty($form->errors['authlify_captcha']) || (is_array($args) && isset($args['mode']) && 'login' === $args['mode'])) {
            return;
        }

        echo '<p class="um-notice err">' . esc_html((string) $form->errors['authlify_captcha']) . '</p>';
    }

    /**
     * UM's form object, or null.
     *
     * @return object|null
     */
    private static function form()
    {
        if (!function_exists('UM')) {
            return null;
        }
        $form = UM()->form();

        return is_object($form) && method_exists($form, 'add_error') ? $form : null;
    }
}
