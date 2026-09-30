<?php
/**
 * CAPTCHA provider base.
 *
 * @package Authlify
 */

namespace Authlify\Captcha;

use Authlify\Net\Ip;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Shared behaviour for providers that verify a token with a remote
 * "siteverify" endpoint (Turnstile, hCaptcha, reCAPTCHA). ALTCHA overrides
 * verify() and never makes a remote request.
 *
 * verify() returns array( ok bool, outage bool, reason string ).
 *
 * @since 3.0.0
 */
abstract class Provider
{
    /**
     * Provider key, as stored in captcha_provider.
     *
     * @return string
     */
    abstract public function id();

    /**
     * Human name.
     *
     * @return string
     */
    abstract public function label();

    /**
     * POST field that carries the token.
     *
     * @return string
     */
    abstract public function field();

    /**
     * Siteverify endpoint.
     *
     * @return string
     */
    abstract protected function verify_url();

    /**
     * Provider script URL ('' for none). Loaded async, only on pages that show a widget.
     *
     * @return string
     */
    abstract public function script_url();

    /**
     * Whether the provider needs a site key and secret key.
     *
     * @return bool
     */
    public function needs_keys()
    {
        return true;
    }

    /**
     * Height reserved for the widget so the form does not jump when it loads.
     *
     * @return int Pixels.
     */
    public function min_height()
    {
        return 78;
    }

    /**
     * Widget markup.
     *
     * @param string $form Form key.
     * @return string HTML.
     */
    public function widget_html($form)
    {
        return sprintf(
            '<div class="authlify-captcha__widget" data-provider="%1$s" data-sitekey="%2$s" data-action="%3$s" style="min-height:%4$dpx"></div><noscript><p class="authlify-captcha__noscript">%5$s</p></noscript>',
            esc_attr($this->id()),
            esc_attr((string) Settings::get('captcha_site_key', '')),
            esc_attr($form),
            (int) $this->min_height(),
            esc_html__('Please enable JavaScript to complete the security check.', 'modify-login')
        );
    }

    /**
     * The submitted token.
     *
     * @return string
     */
    public function token()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the token is the proof; nonces belong to the form.
        return isset($_POST[$this->field()]) && is_string($_POST[$this->field()]) ? trim(sanitize_text_field(wp_unslash($_POST[$this->field()]))) : '';
    }

    /**
     * Verify the submitted token.
     *
     * @param string $form Form key (used as the expected action).
     * @return array { ok: bool, outage: bool, reason: string }
     */
    public function verify($form)
    {
        $token = $this->token();
        if ('' === $token) {
            return self::result(false, 'missing-input-response');
        }

        $response = $this->siteverify((string) Settings::get('captcha_secret_key', ''), $token);
        if ($response['outage']) {
            return self::result(false, $response['reason'], true);
        }

        return $this->evaluate($response['data'], $form);
    }

    /**
     * Judge a siteverify response.
     *
     * @param array  $data Decoded response.
     * @param string $form Form key.
     * @return array
     */
    protected function evaluate(array $data, $form)
    {
        if (!empty($data['success'])) {
            if (!$this->hostname_ok($data)) {
                return self::result(false, 'hostname-mismatch');
            }

            return self::result(true);
        }

        return self::result(false, self::codes($data));
    }

    /**
     * Whether a dummy token reliably tells a wrong secret from a working one.
     *
     * Turnstile checks the secret first. hCaptcha and Google check the token
     * first, so they only report a wrong secret together with a real answer.
     *
     * @return bool
     */
    protected function dummy_check_works()
    {
        return false;
    }

    /**
     * Check keys before they are enabled.
     *
     * With a token from the settings-screen preview, the site key and secret
     * are proven together. Without one, only providers whose dummy-token check
     * is reliable can be checked.
     *
     * @param string $secret Secret key.
     * @param string $token  Token from the preview, or ''.
     * @return string ok, bad, bad_token, needs_token or unreachable.
     */
    public function check_keys($secret, $token = '')
    {
        if ('' === $token) {
            return $this->dummy_check_works() ? $this->check_secret($secret) : 'needs_token';
        }

        $response = $this->siteverify($secret, $token);
        if ($response['outage']) {
            return 'unreachable';
        }
        if (!empty($response['data']['success'])) {
            return 'ok';
        }

        $codes = isset($response['data']['error-codes']) ? (array) $response['data']['error-codes'] : array();
        if (array_intersect(array('invalid-input-secret', 'missing-input-secret', 'invalid-secret', 'secret-key-not-valid', 'sitekey-secret-mismatch', 'invalid-keys', 'not-using-dummy-secret'), $codes)) {
            return 'bad';
        }

        // An expired or reused preview answer: fall back to the dummy check where it works.
        return $this->dummy_check_works() ? $this->check_secret($secret) : 'bad_token';
    }

    /**
     * Check a secret key with the provider using a dummy token.
     *
     * A wrong secret is answered with invalid-input-secret; a working secret
     * with invalid-input-response (or success, for the providers' test keys).
     *
     * @param string $secret Secret key.
     * @return string ok, bad or unreachable.
     */
    public function check_secret($secret)
    {
        $response = $this->siteverify($secret, 'authlify-key-check-' . wp_generate_password(16, false));
        if ($response['outage']) {
            return 'unreachable';
        }

        $codes = isset($response['data']['error-codes']) ? (array) $response['data']['error-codes'] : array();
        if (array_intersect(array('invalid-input-secret', 'missing-input-secret', 'invalid-secret', 'secret-key-not-valid'), $codes)) {
            return 'bad';
        }

        return 'ok';
    }

    /**
     * POST to the siteverify endpoint.
     *
     * @param string $secret Secret.
     * @param string $token  Token.
     * @return array { outage: bool, reason: string, data: array }
     */
    protected function siteverify($secret, $token)
    {
        /**
         * Filters the siteverify timeout in seconds.
         *
         * @param int    $timeout  Seconds.
         * @param string $provider Provider key.
         * @since 3.0.0
         */
        $timeout = (int) apply_filters('authlify_captcha_timeout', 8, $this->id());

        $body = array(
            'secret' => $secret,
            'response' => $token,
        );
        $ip = Ip::client();
        if (Ip::valid($ip)) {
            $body['remoteip'] = $ip;
        }

        $response = wp_remote_post($this->verify_url(), array(
            'timeout' => max(2, $timeout),
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            return array('outage' => true, 'reason' => 'unreachable: ' . $response->get_error_message(), 'data' => array());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code >= 500 || !is_array($data)) {
            return array('outage' => true, 'reason' => 'http ' . $code, 'data' => array());
        }

        return array('outage' => false, 'reason' => '', 'data' => $data);
    }

    /**
     * Build a result.
     *
     * @param bool   $ok     Passed.
     * @param string $reason Why not.
     * @param bool   $outage Provider unreachable.
     * @return array
     */
    public static function result($ok, $reason = '', $outage = false)
    {
        return array('ok' => (bool) $ok, 'outage' => (bool) $outage, 'reason' => (string) $reason);
    }

    /**
     * Error codes from a response as a string.
     *
     * @param array $data Response.
     * @return string
     */
    protected static function codes(array $data)
    {
        $codes = isset($data['error-codes']) ? array_map('strval', (array) $data['error-codes']) : array();

        return $codes ? implode(',', $codes) : 'rejected';
    }

    /**
     * Whether a site key is one of the providers' public, always-pass test keys.
     *
     * @param string $site_key Site key.
     * @return bool
     */
    public static function is_test_key($site_key)
    {
        $test_keys = array(
            // Turnstile: always passes (visible), always blocks, forces a challenge; invisible variants.
            '1x00000000000000000000AA', '2x00000000000000000000AB', '3x00000000000000000000FF', '1x00000000000000000000BB', '2x00000000000000000000BB',
            // reCAPTCHA v2/v3 test key.
            '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI',
            // hCaptcha test keys.
            '10000000-ffff-ffff-ffff-000000000001', '20000000-ffff-ffff-ffff-000000000002', '30000000-ffff-ffff-ffff-000000000003',
        );

        return in_array(trim((string) $site_key), $test_keys, true);
    }

    /**
     * The token must have been solved on this site (tokens from another site
     * using the same keys are refused). Providers' test keys report fixed
     * hostnames, so they are exempt.
     *
     * @param array $data siteverify response.
     * @return bool
     */
    protected function hostname_ok(array $data)
    {
        if (empty($data['hostname'])) {
            return true;
        }

        if (self::is_test_key((string) \Authlify\Settings::get('captcha_site_key', ''))) {
            return true;
        }

        $hostname = strtolower((string) $data['hostname']);
        $allowed = array(strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)), strtolower((string) wp_parse_url(site_url(), PHP_URL_HOST)));

        /**
         * Filters the hostnames a CAPTCHA token may come from.
         *
         * @param string[] $allowed Hostnames.
         * @since 3.0.0
         */
        return in_array($hostname, (array) apply_filters('authlify_captcha_hostnames', $allowed), true);
    }
}
