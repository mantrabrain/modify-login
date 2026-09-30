<?php
/**
 * Cloudflare Turnstile.
 *
 * @package Authlify
 */

namespace Authlify\Captcha;

defined('ABSPATH') || exit;

/**
 * Cloudflare Turnstile: free, unlimited, usually no puzzle.
 *
 * @since 3.0.0
 */
final class Turnstile extends Provider
{
    /**
     * {@inheritdoc}
     */
    public function id()
    {
        return 'turnstile';
    }

    /**
     * {@inheritdoc}
     */
    public function label()
    {
        return __('Cloudflare Turnstile', 'modify-login');
    }

    /**
     * {@inheritdoc}
     */
    public function field()
    {
        return 'cf-turnstile-response';
    }

    /**
     * {@inheritdoc}
     */
    protected function verify_url()
    {
        return 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    }

    /**
     * {@inheritdoc}
     */
    public function script_url()
    {
        return 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=authlifyCaptchaOnload';
    }

    /**
     * {@inheritdoc}
     */
    protected function dummy_check_works()
    {
        // A dummy token proves the secret, but not the site key: a wrong or
        // always-fail site key would still lock everyone out. Require the preview.
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function min_height()
    {
        return 65;
    }
}
