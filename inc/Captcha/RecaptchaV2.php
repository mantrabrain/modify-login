<?php
/**
 * Google reCAPTCHA v2 (checkbox).
 *
 * @package Authlify
 */

namespace Authlify\Captcha;

defined('ABSPATH') || exit;

/**
 * Google reCAPTCHA v2 "I'm not a robot" checkbox.
 *
 * @since 3.0.0
 */
class RecaptchaV2 extends Provider
{
    /**
     * {@inheritdoc}
     */
    public function id()
    {
        return 'recaptcha_v2';
    }

    /**
     * {@inheritdoc}
     */
    public function label()
    {
        return __('Google reCAPTCHA v2', 'modify-login');
    }

    /**
     * {@inheritdoc}
     */
    public function field()
    {
        return 'g-recaptcha-response';
    }

    /**
     * {@inheritdoc}
     */
    protected function verify_url()
    {
        /**
         * Filters the reCAPTCHA verify URL (use www.recaptcha.net where google.com is blocked).
         *
         * @param string $url URL.
         * @since 3.0.0
         */
        return apply_filters('authlify_recaptcha_verify_url', 'https://www.google.com/recaptcha/api/siteverify');
    }

    /**
     * {@inheritdoc}
     */
    public function script_url()
    {
        return self::api_base() . '?render=explicit&onload=authlifyCaptchaOnload';
    }

    /**
     * Script base URL.
     *
     * @return string
     */
    protected static function api_base()
    {
        /**
         * Filters the reCAPTCHA script URL (use www.recaptcha.net where google.com is blocked).
         *
         * @param string $url URL.
         * @since 3.0.0
         */
        return apply_filters('authlify_recaptcha_api_url', 'https://www.google.com/recaptcha/api.js');
    }
}
