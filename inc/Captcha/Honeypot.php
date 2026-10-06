<?php
/**
 * Honeypot and time trap.
 *
 * @package Authlify
 */

namespace Authlify\Captcha;

defined('ABSPATH') || exit;

/**
 * An invisible field that only bots fill in, plus a signed timestamp: a form
 * sent back faster than a person can type, or without the timestamp at all
 * (a script posting straight to the login URL), is refused.
 *
 * The stamp is "time.nonce.signature". The random nonce makes every rendered
 * form unique, so one visitor's stamp never collides with another's. A stamp
 * may be submitted again within its lifetime (pages served from a cache hand
 * the same stamp to every visitor), but each address can use one stamp only a
 * few times an hour, and one stamp only so many times an hour overall, so a
 * fetched stamp is not a reusable ticket for a bot.
 *
 * The field is off-screen rather than display:none (some bots skip hidden
 * inputs), hidden from screen readers, out of the tab order and marked so
 * browsers and password managers do not fill it.
 *
 * @since 3.0.0
 */
final class Honeypot
{
    /**
     * Trap field name.
     */
    const FIELD = 'authlify_website';

    /**
     * Timestamp field name.
     */
    const STAMP = 'authlify_ts';

    /**
     * Rendered count (unique IDs when a page has several forms).
     *
     * @var int
     */
    private static $count = 0;

    /**
     * Markup.
     *
     * @return string HTML.
     */
    public static function html()
    {
        ++self::$count;
        $id = 'authlify-hp-' . self::$count;
        $time = time();
        $nonce = self::nonce();

        return sprintf(
            '<div class="authlify-hp" aria-hidden="true" style="position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">'
            . '<label for="%1$s">%2$s</label>'
            . '<input type="text" id="%1$s" name="%3$s" value="" tabindex="-1" autocomplete="off" data-1p-ignore="true" data-lpignore="true" data-bwignore="true" data-form-type="other">'
            . '</div>'
            . '<input type="hidden" name="%4$s" value="%5$s">',
            esc_attr($id),
            esc_html__('Leave this field empty', 'modify-login'),
            esc_attr(self::FIELD),
            esc_attr(self::STAMP),
            esc_attr($time . '.' . $nonce . '.' . self::sign($time, $nonce))
        );
    }

    /**
     * A random nonce for one rendered form.
     *
     * @return string 16 hex characters.
     */
    private static function nonce()
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            return substr(md5(wp_generate_password(20, true, true)), 0, 16);
        }
    }

    /**
     * How long a rendered form stays valid (page caches keep pages for hours or days).
     *
     * @return int Seconds.
     */
    public static function lifetime()
    {
        /**
         * Filters how long a honeypot stamp stays valid.
         *
         * @param int $seconds Seconds. Default 7 days.
         * @since 3.0.0
         */
        return max(HOUR_IN_SECONDS, (int) apply_filters('authlify_honeypot_lifetime', 7 * DAY_IN_SECONDS));
    }

    /**
     * Submissions allowed per stamp and hour: per address, and overall.
     *
     * @return array { ip: int, total: int }
     */
    public static function limits()
    {
        /**
         * Filters how often one honeypot stamp may be submitted per hour.
         *
         * @param array $limits ip: per address, total: from every address together.
         * @since 3.0.0
         */
        $limits = (array) apply_filters('authlify_honeypot_stamp_limits', array('ip' => 10, 'total' => 300));

        return array(
            'ip' => max(1, isset($limits['ip']) ? (int) $limits['ip'] : 10),
            'total' => max(1, isset($limits['total']) ? (int) $limits['total'] : 300),
        );
    }

    /**
     * Check the submitted form.
     *
     * @return string '' when it looks human, otherwise the reason.
     */
    public static function check()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $trap = isset($_POST[self::FIELD]) && is_string($_POST[self::FIELD]) ? trim(wp_unslash($_POST[self::FIELD])) : '';
        $stamp = isset($_POST[self::STAMP]) && is_string($_POST[self::STAMP]) ? sanitize_text_field(wp_unslash($_POST[self::STAMP])) : '';
        // phpcs:enable

        if ('' !== $trap) {
            return 'honeypot';
        }

        $parts = explode('.', $stamp);
        if (3 !== count($parts) || !ctype_digit($parts[0]) || !preg_match('/^[a-f0-9]{16}$/', $parts[1]) || !hash_equals(self::sign((int) $parts[0], $parts[1]), $parts[2])) {
            return 'honeypot_missing';
        }

        /**
         * Filters the minimum seconds between showing a form and submitting it.
         *
         * @param int $seconds Seconds.
         * @since 3.0.0
         */
        $min = (int) apply_filters('authlify_honeypot_min_seconds', 2);
        if (time() - (int) $parts[0] < $min) {
            return 'too_fast';
        }

        if (time() - (int) $parts[0] > self::lifetime()) {
            return 'honeypot_missing';
        }

        // Rate per stamp: a few uses per address, a cap for all addresses together.
        $limits = self::limits();
        $hour = (int) floor(time() / HOUR_IN_SECONDS);
        $total_key = 'authlify_hp_' . substr(md5($parts[1] . '|' . $hour), 0, 20);
        // Per address as lockouts count it (an IPv6 /64 is one address), so one
        // network cannot use up a cached page's stamp by rotating addresses.
        $ip_key = 'authlify_hp_' . substr(md5($parts[1] . '|' . \Authlify\Security\Limiter::ip_key(\Authlify\Net\Ip::client()) . '|' . $hour), 0, 20);
        $total = (int) get_transient($total_key);
        $mine = (int) get_transient($ip_key);
        if ($mine >= $limits['ip'] || $total >= $limits['total']) {
            return 'honeypot_replayed';
        }
        set_transient($total_key, $total + 1, HOUR_IN_SECONDS);
        set_transient($ip_key, $mine + 1, HOUR_IN_SECONDS);

        return '';
    }

    /**
     * Signature for a timestamp.
     *
     * @param int    $time  Unix time.
     * @param string $nonce Per-form nonce.
     * @return string
     */
    private static function sign($time, $nonce)
    {
        return substr(hash_hmac('sha256', 'authlify-hp|' . (int) $time . '|' . $nonce, wp_salt('nonce')), 0, 24);
    }
}
