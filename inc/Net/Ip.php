<?php
/**
 * Client IP address resolution.
 *
 * @package Authlify
 */

namespace Authlify\Net;

use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Works out the visitor's real IP without trusting forgeable headers.
 *
 * REMOTE_ADDR is the only value the web server guarantees. Forwarding headers
 * (CF-Connecting-IP, X-Forwarded-For) are only honoured when the connection
 * itself comes from a proxy the site trusts: Cloudflare's published ranges, or
 * addresses the admin listed. Spoofed headers are the most common vulnerability
 * class in lockout plugins, because they let attackers dodge or frame lockouts.
 */
final class Ip
{
    /**
     * Cloudflare edge ranges (https://www.cloudflare.com/ips/).
     */
    const CLOUDFLARE = array(
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    );

    /**
     * Resolved IP for this request.
     *
     * @var string|null
     */
    private static $ip = null;

    /**
     * The visitor's IP address.
     *
     * @return string A valid IP, or '0.0.0.0' when none can be determined.
     */
    public static function client()
    {
        if (null !== self::$ip) {
            return self::$ip;
        }

        $remote = self::normalize(self::server('REMOTE_ADDR'));
        $ip = $remote;
        $source = Settings::get('ip_source', 'remote_addr');

        if ('cloudflare' === $source && self::in_ranges($remote, self::CLOUDFLARE)) {
            $cf = self::normalize(self::server('HTTP_CF_CONNECTING_IP'));
            if (self::valid($cf)) {
                $ip = $cf;
            }
        } elseif ('proxy' === $source && self::in_ranges($remote, self::trusted_proxies())) {
            $ip = self::from_forwarded_chain($remote);
        }

        /**
         * Filters the resolved client IP.
         *
         * @param string $ip     Resolved IP.
         * @param string $remote REMOTE_ADDR.
         * @since 3.0.0
         */
        $ip = (string) apply_filters('authlify_client_ip', $ip, $remote);

        // 2.x filter name.
        $ip = (string) apply_filters('modify_login_client_ip', $ip);

        $ip = self::normalize($ip);
        self::$ip = self::valid($ip) ? $ip : '0.0.0.0';

        return self::$ip;
    }

    /**
     * Normalise how an address is written: drop a port ("203.0.113.7:4711",
     * "[2001:db8::1]:443") and brackets, and turn an IPv4-mapped IPv6 address
     * ("::ffff:203.0.113.7") into plain IPv4, so one visitor always has one key.
     *
     * @param string $ip Raw value.
     * @return string The cleaned value (not necessarily a valid IP).
     */
    public static function normalize($ip)
    {
        $ip = trim((string) $ip);
        if ('' === $ip) {
            return '';
        }

        if ('[' === $ip[0]) {
            $end = strpos($ip, ']');
            $ip = false !== $end ? substr($ip, 1, $end - 1) : $ip;
        } elseif (1 === substr_count($ip, ':') && false !== strpos($ip, '.')) {
            $ip = (string) strstr($ip, ':', true);
        }

        if (false !== strpos($ip, ':') && self::valid($ip)) {
            $bin = inet_pton($ip);
            if (16 === strlen($bin) && str_repeat("\0", 10) . "\xff\xff" === substr($bin, 0, 12)) {
                $ip = inet_ntop(substr($bin, 12));
            }
        }

        return $ip;
    }

    /**
     * Reset the per-request cache (tests, CLI).
     */
    public static function reset()
    {
        self::$ip = null;
    }

    /**
     * Guess the right IP source for this site from the current request.
     *
     * @return array { source: string, reason: string }
     */
    public static function detect()
    {
        $remote = self::server('REMOTE_ADDR');

        if (self::in_ranges($remote, self::CLOUDFLARE) && self::server('HTTP_CF_CONNECTING_IP')) {
            return array(
                'source' => 'cloudflare',
                'reason' => __('Requests reach this site through Cloudflare.', 'modify-login'),
            );
        }

        if (self::server('HTTP_X_FORWARDED_FOR') && self::is_private($remote)) {
            return array(
                'source' => 'proxy',
                'reason' => sprintf(
                    /* translators: %s: proxy IP address */
                    __('Requests arrive from a private address (%s) with an X-Forwarded-For header, which usually means a load balancer or reverse proxy. Add it as a trusted proxy.', 'modify-login'),
                    $remote
                ),
            );
        }

        return array(
            'source' => 'remote_addr',
            'reason' => __('Visitors connect directly, so the connection address is their real IP.', 'modify-login'),
        );
    }

    /**
     * Walk X-Forwarded-For from the right, skipping trusted proxies.
     *
     * @param string $remote REMOTE_ADDR.
     * @return string
     */
    private static function from_forwarded_chain($remote)
    {
        $header = self::server('HTTP_X_FORWARDED_FOR');
        if ('' === $header) {
            return $remote;
        }

        $chain = array_reverse(array_map(array(__CLASS__, 'normalize'), explode(',', $header)));
        $trusted = self::trusted_proxies();

        foreach ($chain as $hop) {
            if (!self::valid($hop)) {
                return $remote;
            }
            if (!self::in_ranges($hop, $trusted)) {
                return $hop;
            }
        }

        return $remote;
    }

    /**
     * Admin-listed trusted proxies.
     *
     * @return string[]
     */
    public static function trusted_proxies()
    {
        return Settings::lines('trusted_proxies');
    }

    /**
     * Whether an IP is inside any of the given IPs/CIDR ranges.
     *
     * @param string   $ip     IP address.
     * @param string[] $ranges IPs or CIDR ranges.
     * @return bool
     */
    public static function in_ranges($ip, array $ranges)
    {
        foreach ($ranges as $range) {
            if (self::in_range($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an IP matches one IP or CIDR range. IPv4 and IPv6.
     *
     * @param string $ip    IP address.
     * @param string $range IP or CIDR.
     * @return bool
     */
    public static function in_range($ip, $range)
    {
        $range = trim($range);
        $ip = self::normalize((string) $ip);
        if (!self::valid($ip) || '' === $range) {
            return false;
        }

        if (false === strpos($range, '/')) {
            return self::valid($range) && inet_pton($ip) === inet_pton($range);
        }

        list($subnet, $bits) = explode('/', $range, 2);
        if (!self::valid($subnet) || !is_numeric($bits)) {
            return false;
        }

        $ip_bin = inet_pton($ip);
        $subnet_bin = inet_pton($subnet);
        if (strlen($ip_bin) !== strlen($subnet_bin)) {
            return false;
        }

        $bits = (int) $bits;
        $max = strlen($ip_bin) * 8;
        if ($bits < 0 || $bits > $max) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) {
            return false;
        }

        if (0 === $remainder) {
            return true;
        }

        $mask = chr((0xff << (8 - $remainder)) & 0xff);

        return (ord($ip_bin[$bytes]) & ord($mask)) === (ord($subnet_bin[$bytes]) & ord($mask));
    }

    /**
     * The /24 (IPv4) or /64 (IPv6) network an IP belongs to.
     *
     * @param string $ip IP address.
     * @return string CIDR notation, or '' for an invalid IP.
     */
    public static function subnet($ip)
    {
        if (!self::valid($ip)) {
            return '';
        }

        $bin = inet_pton($ip);
        if (4 === strlen($bin)) {
            return inet_ntop(substr($bin, 0, 3) . "\0") . '/24';
        }

        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * The wider network used for network lockouts: /24 (IPv4) or /48 (IPv6).
     *
     * @param string $ip IP address.
     * @return string CIDR notation, or '' for an invalid IP.
     */
    public static function network($ip)
    {
        $ip = self::normalize((string) $ip);
        if (!self::valid($ip)) {
            return '';
        }

        $bin = inet_pton($ip);
        if (4 === strlen($bin)) {
            return self::subnet($ip);
        }

        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /**
     * Anonymize an IP: zero the last octet (IPv4) or keep only the /64 (IPv6),
     * as wp_privacy_anonymize_ip() does.
     *
     * @param string $ip IP address.
     * @return string
     */
    public static function anonymize($ip)
    {
        if (!self::valid($ip)) {
            return $ip;
        }

        return function_exists('wp_privacy_anonymize_ip') ? wp_privacy_anonymize_ip($ip) : $ip;
    }

    /**
     * Whether a string is a valid IP address.
     *
     * @param string $ip Value.
     * @return bool
     */
    public static function valid($ip)
    {
        return is_string($ip) && false !== filter_var($ip, FILTER_VALIDATE_IP);
    }

    /**
     * Whether an IP is private or reserved (LAN, loopback).
     *
     * @param string $ip IP address.
     * @return bool
     */
    public static function is_private($ip)
    {
        return self::valid($ip) && false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * A $_SERVER value as a trimmed string.
     *
     * @param string $key Key.
     * @return string
     */
    private static function server($key)
    {
        return isset($_SERVER[$key]) ? trim(sanitize_text_field(wp_unslash($_SERVER[$key]))) : '';
    }
}
