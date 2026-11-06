<?php
/**
 * ALTCHA: self-hosted proof of work.
 *
 * @package Authlify
 */

namespace Authlify\Captcha;

defined('ABSPATH') || exit;

/**
 * Self-hosted proof-of-work check, compatible with the ALTCHA protocol
 * (https://altcha.org, MIT). No keys, no cookies, no third-party request.
 *
 * The server issues a challenge: a random salt carrying an expiry, the
 * SHA-256 of salt + a secret random number, and an HMAC signature of that
 * hash. The browser finds the number by brute force (up to MAX_NUMBER
 * hashes, in a web worker) and posts it back. Each signature is accepted
 * once; used signatures are remembered in a transient until they expire.
 *
 * @since 3.0.0
 */
final class Altcha extends Provider
{
    /**
     * Largest secret number (the average solve takes half as many hashes).
     */
    const MAX_NUMBER = 1000000;

    /**
     * Search range past the soft rate limit: a few seconds in a person's
     * browser, and an expensive way for a script to collect challenges.
     */
    const HARD_MAX = 5000000;

    /**
     * Challenge lifetime in seconds.
     */
    const TTL = 1200;

    /**
     * {@inheritdoc}
     */
    public function id()
    {
        return 'altcha';
    }

    /**
     * {@inheritdoc}
     */
    public function label()
    {
        return __('ALTCHA (self-hosted)', 'modify-login');
    }

    /**
     * {@inheritdoc}
     */
    public function field()
    {
        return 'altcha';
    }

    /**
     * {@inheritdoc}
     */
    protected function verify_url()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function script_url()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function needs_keys()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function min_height()
    {
        return 48;
    }

    /**
     * {@inheritdoc}
     */
    public function widget_html($form)
    {
        // The login screen is never cached, so it can carry a challenge. Other
        // pages (comments, shop) may be cached, so the browser fetches a fresh one.
        $challenge = did_action('login_init') ? wp_json_encode(self::challenge()) : '';

        return sprintf(
            '<div class="authlify-captcha__widget authlify-altcha" data-provider="altcha" data-state="idle" data-challenge="%1$s" style="min-height:%2$dpx">'
            . '<span class="authlify-altcha__icon" aria-hidden="true"></span>'
            . '<span class="authlify-altcha__text" role="status" aria-live="polite">%3$s</span>'
            . '<input type="hidden" name="altcha" value="">'
            . '</div><noscript><p class="authlify-captcha__noscript">%4$s</p></noscript>',
            esc_attr((string) $challenge),
            (int) $this->min_height(),
            esc_html__('Checking that you are human…', 'modify-login'),
            esc_html__('Please enable JavaScript to complete the security check.', 'modify-login')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function verify($form)
    {
        $reason = self::verify_payload($this->token());

        return self::result('' === $reason, $reason);
    }

    /**
     * {@inheritdoc}
     */
    public function check_secret($secret)
    {
        return 'ok';
    }

    /**
     * {@inheritdoc}
     */
    public function check_keys($secret, $token = '')
    {
        return 'ok';
    }

    /**
     * HMAC key, derived from the site's auth salt.
     *
     * @return string
     */
    private static function key()
    {
        return hash_hmac('sha256', 'authlify-altcha', wp_salt('auth'));
    }

    /**
     * Largest secret number for new challenges (difficulty).
     *
     * ALTCHA slows bots down and filters spam; it is not a brute-force
     * defence on its own (the lockouts are).
     *
     * @return int
     */
    public static function max_number()
    {
        /**
         * Filters the ALTCHA difficulty (largest secret number). Default 1,000,000:
         * about half a second of hashing in a desktop browser.
         *
         * @param int $max Largest number.
         * @since 3.0.0
         */
        return max(1000, min(10000000, (int) apply_filters('authlify_altcha_max_number', self::MAX_NUMBER)));
    }

    /**
     * Create a challenge.
     *
     * @param int|null $max Search range (default: the normal one).
     * @return array { algorithm, challenge, maxnumber, salt, signature }
     */
    public static function challenge($max = null)
    {
        $salt = bin2hex(random_bytes(12)) . '?expires=' . (time() + self::TTL);
        $max = null === $max ? self::max_number() : max(self::max_number(), (int) $max);
        $number = random_int(0, $max);
        $challenge = hash('sha256', $salt . $number);

        return array(
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'maxnumber' => $max,
            'salt' => $salt,
            'signature' => hash_hmac('sha256', $challenge, self::key()),
        );
    }

    /**
     * Verify a solution payload (base64 JSON).
     *
     * @param string $payload Payload.
     * @param bool   $consume Remember the signature so it cannot be reused.
     * @return string '' when valid, otherwise the reason.
     */
    public static function verify_payload($payload, $consume = true)
    {
        if ('' === $payload) {
            return 'missing-input-response';
        }

        $data = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($data)) {
            return 'malformed';
        }

        foreach (array('algorithm', 'challenge', 'number', 'salt', 'signature') as $key) {
            if (!isset($data[$key]) || !is_scalar($data[$key])) {
                return 'malformed';
            }
        }

        if ('SHA-256' !== $data['algorithm']) {
            return 'bad-algorithm';
        }

        $salt = (string) $data['salt'];
        $expires = 0;
        $query = strpos($salt, '?');
        if (false !== $query) {
            parse_str(substr($salt, $query + 1), $params);
            $expires = isset($params['expires']) ? (int) $params['expires'] : 0;
        }
        if ($expires <= 0 || $expires < time()) {
            return 'expired';
        }

        $number = (string) $data['number'];
        if (!ctype_digit($number) || strlen($number) > 9 || (int) $number > 10000000) {
            return 'malformed';
        }

        $challenge = (string) $data['challenge'];
        $signature = (string) $data['signature'];

        if (!hash_equals(hash_hmac('sha256', $challenge, self::key()), $signature)) {
            return 'bad-signature';
        }

        if (!hash_equals(hash('sha256', $salt . $number), $challenge)) {
            return 'wrong-solution';
        }

        $used = 'authlify_altcha_' . substr($signature, 0, 40);
        if (get_transient($used)) {
            return 'replayed';
        }

        if ($consume) {
            set_transient($used, 1, max(MINUTE_IN_SECONDS, $expires - time() + MINUTE_IN_SECONDS));
        }

        return '';
    }

    /**
     * AJAX: a fresh challenge (for cached pages and retries).
     */
    public static function ajax_challenge()
    {
        nocache_headers();

        // A shared office or carrier address must not lose its forms: past the
        // soft limit a script pays with a much harder puzzle (one that a person's
        // browser still finishes in seconds), and only far past it is it refused.
        $key = 'authlify_altcha_rate_' . md5(\Authlify\Net\Ip::client() . '|' . (int) floor(time() / (10 * MINUTE_IN_SECONDS)));
        $count = (int) get_transient($key);

        /**
         * Filters how many ALTCHA challenges one address may fetch per 10 minutes.
         *
         * @param int $limit Soft limit; ten times this is refused. Default 120.
         * @since 3.0.0
         */
        $limit = max(1, (int) apply_filters('authlify_altcha_rate_limit', 120));
        if ($count >= 10 * $limit) {
            wp_send_json(array('error' => 'rate_limited'), 429);
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);

        if ($count === $limit) {
            \Authlify\Log\Log::add('altcha_limited', array('context' => array('limit' => $limit)));
        }

        wp_send_json($count >= $limit ? self::challenge(self::HARD_MAX) : self::challenge());
    }
}
