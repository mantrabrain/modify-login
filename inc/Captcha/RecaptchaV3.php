<?php
/**
 * Google reCAPTCHA v3 (score).
 *
 * @package Authlify
 */

namespace Authlify\Captcha;

use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Google reCAPTCHA v3: invisible, returns a score. The token must carry the
 * form's action and this site's hostname, and score at least the threshold.
 *
 * @since 3.0.0
 */
final class RecaptchaV3 extends RecaptchaV2
{
    /**
     * {@inheritdoc}
     */
    public function id()
    {
        return 'recaptcha_v3';
    }

    /**
     * {@inheritdoc}
     */
    public function label()
    {
        return __('Google reCAPTCHA v3', 'modify-login');
    }

    /**
     * {@inheritdoc}
     */
    public function script_url()
    {
        return add_query_arg(array('render' => rawurlencode((string) Settings::get('captcha_site_key', '')), 'onload' => 'authlifyCaptchaOnload'), self::api_base());
    }

    /**
     * {@inheritdoc}
     */
    public function min_height()
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function widget_html($form)
    {
        return sprintf(
            '<div class="authlify-captcha__widget" data-provider="recaptcha_v3" data-sitekey="%1$s" data-action="%2$s"><input type="hidden" name="g-recaptcha-response" value=""></div>',
            esc_attr((string) Settings::get('captcha_site_key', '')),
            esc_attr(self::action($form))
        );
    }

    /**
     * reCAPTCHA action name for a form (letters, digits, slashes and underscores).
     *
     * @param string $form Form key.
     * @return string
     */
    public static function action($form)
    {
        return 'authlify_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string) $form);
    }

    /**
     * Score threshold, 0.0 to 1.0.
     *
     * @return float
     */
    public static function threshold()
    {
        $value = (float) str_replace(',', '.', (string) Settings::get('captcha_v3_threshold', '0.5'));

        return max(0.0, min(1.0, $value));
    }

    /**
     * {@inheritdoc}
     */
    protected function evaluate(array $data, $form)
    {
        if (empty($data['success'])) {
            return self::result(false, self::codes($data));
        }

        if (!isset($data['action']) || self::action($form) !== $data['action']) {
            return self::result(false, 'action-mismatch');
        }

        $host = isset($data['hostname']) ? strtolower((string) $data['hostname']) : '';
        if (!in_array($host, self::hosts(), true)) {
            return self::result(false, 'hostname-mismatch');
        }

        $score = isset($data['score']) ? (float) $data['score'] : 0.0;
        if ($score < self::threshold()) {
            return self::result(false, 'low-score:' . $score);
        }

        return self::result(true);
    }

    /**
     * Hostnames a token may come from.
     *
     * @return string[]
     */
    public static function hosts()
    {
        $hosts = array_filter(array(
            strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)),
            strtolower((string) wp_parse_url(site_url(), PHP_URL_HOST)),
        ));

        /**
         * Filters the hostnames accepted in reCAPTCHA v3 responses.
         *
         * @param string[] $hosts Hostnames.
         * @since 3.0.0
         */
        return array_values(array_unique((array) apply_filters('authlify_captcha_hostnames', $hosts)));
    }
}
