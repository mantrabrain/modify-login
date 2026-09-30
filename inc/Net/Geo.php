<?php
/**
 * Country lookup for log entries.
 *
 * @package Authlify
 */

namespace Authlify\Net;

use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Finds the country of an IP without calling third-party services.
 *
 * Sources, in order: a header the site's own CDN or host adds (Cloudflare,
 * CloudFront, Fastly, many managed hosts), then the optional local DB-IP Lite
 * country database (a Pro module registers it via the `authlify_geo_lookup`
 * filter).
 */
final class Geo
{
    /**
     * Two-letter country code for an IP, or ''.
     *
     * @param string $ip IP address.
     * @return string
     */
    public static function country($ip)
    {
        $source = Settings::get('geo_source', 'headers');
        if ('off' === $source) {
            return '';
        }

        $country = '';

        // Headers only describe the current visitor, and only a CDN or proxy the
        // site trusts can set them (a direct visitor could send any value).
        // Country headers are trusted only from Cloudflare (verified by its IP
        // ranges in "Through Cloudflare" mode), and never when a local database
        // is the chosen source.
        $trusted = 'headers' === $source && ('cloudflare' === Settings::get('ip_source', 'remote_addr') || apply_filters('authlify_trust_country_headers', false));
        $client = Ip::client();
        if ($trusted && ($client === $ip || Ip::anonymize($client) === $ip)) {
            $country = 'cloudflare' === Settings::get('ip_source', 'remote_addr') ? self::cloudflare_header() : self::from_headers();
        }

        /**
         * Filters the country for an IP (local database lookups hook in here).
         *
         * @param string $country Two-letter code or ''.
         * @param string $ip      IP address.
         * @param string $source  Configured source.
         * @since 3.0.0
         */
        $country = (string) apply_filters('authlify_geo_lookup', $country, $ip, $source);

        return preg_match('/^[A-Z]{2}$/', $country) ? $country : '';
    }

    /**
     * Cloudflare's country header, only for requests that really came through Cloudflare.
     *
     * @return string
     */
    private static function cloudflare_header()
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if (!Ip::in_ranges($remote, Ip::CLOUDFLARE) || empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            return '';
        }

        $code = strtoupper(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'])));

        return preg_match('/^[A-Z]{2}$/', $code) && !in_array($code, array('XX', 'T1'), true) ? $code : '';
    }

    /**
     * Country from CDN/host headers.
     *
     * @return string
     */
    public static function from_headers()
    {
        $headers = array('HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'HTTP_FASTLY_GEO_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE');

        foreach ($headers as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }
            $code = strtoupper(sanitize_text_field(wp_unslash($_SERVER[$header])));
            if (preg_match('/^[A-Z]{2}$/', $code) && !in_array($code, array('XX', 'T1', 'A1', 'A2'), true)) {
                return $code;
            }
        }

        return '';
    }
}
