<?php
/**
 * MemberPress login form.
 *
 * @package Authlify
 */

namespace Authlify\Captcha\Integrations;

use Authlify\Captcha\Captcha;
use Authlify\Captcha\Integrations;

defined('ABSPATH') || exit;

/**
 * MemberPress's login form (the [mepr-login-form] shortcode, its login page
 * and widget), through MemberPress's documented hooks:
 * `mepr-login-form-before-submit` (render) and `mepr-validate-login` (an
 * array of error strings; MemberPress signs in with wp_signon() only when it
 * stays empty).
 *
 * MemberPress is a paid plugin: this integration is written against those
 * documented hooks and was not run against MemberPress itself.
 *
 * @since 3.1.0
 */
final class MemberPress
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('mepr-login-form-before-submit', array(__CLASS__, 'render'));
        add_filter('mepr-validate-login', array(__CLASS__, 'validate'));
        add_filter('authlify_captcha_login_form', array(__CLASS__, 'login_form'));
    }

    /**
     * Whether this request is MemberPress's login form.
     *
     * @return bool
     */
    public static function is_login_post()
    {
        return Integrations::is_post() && '' !== Integrations::posted('mepr_process_login_form');
    }

    /**
     * Widget above the submit button.
     */
    public static function render()
    {
        echo Captcha::render('login'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * Block list, lockout and CAPTCHA before MemberPress calls wp_signon().
     *
     * @param array $errors Error messages.
     * @return array
     */
    public static function validate($errors)
    {
        $errors = is_array($errors) ? $errors : array();
        if (!self::is_login_post()) {
            return $errors;
        }

        $error = Integrations::login_gate(Integrations::posted('log'));
        if ($error) {
            $errors[] = Integrations::plain($error);
        }

        return $errors;
    }

    /**
     * The wp_signon() that follows is the same login (the CAPTCHA result is reused).
     *
     * @param string $form Form key so far.
     * @return string
     */
    public static function login_form($form)
    {
        return '' === $form && self::is_login_post() ? 'login' : $form;
    }
}
