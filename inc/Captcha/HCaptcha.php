<?php
/**
 * hCaptcha.
 *
 * @package Authlify
 */

namespace Authlify\Captcha;

defined('ABSPATH') || exit;

/**
 * hCaptcha: privacy-focused, free tier, image puzzles more often.
 *
 * @since 3.0.0
 */
final class HCaptcha extends Provider
{
    /**
     * {@inheritdoc}
     */
    public function id()
    {
        return 'hcaptcha';
    }

    /**
     * {@inheritdoc}
     */
    public function label()
    {
        return __('hCaptcha', 'modify-login');
    }

    /**
     * {@inheritdoc}
     */
    public function field()
    {
        return 'h-captcha-response';
    }

    /**
     * {@inheritdoc}
     */
    protected function verify_url()
    {
        return 'https://api.hcaptcha.com/siteverify';
    }

    /**
     * {@inheritdoc}
     */
    public function script_url()
    {
        return 'https://js.hcaptcha.com/1/api.js?render=explicit&recaptchacompat=off&onload=authlifyCaptchaOnload';
    }
}
