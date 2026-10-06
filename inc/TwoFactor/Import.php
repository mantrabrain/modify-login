<?php
/**
 * Import authenticator-app secrets from other two-factor plugins.
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

use Authlify\Log\Log;
use Authlify\Plugin;

defined('ABSPATH') || exit;

/**
 * Copies existing TOTP secrets into Authlify, so people who switch keep the
 * entry in their authenticator app and nobody has to set it up again.
 *
 * - Two Factor (WordPress.org feature plugin): user meta `_two_factor_totp_key`
 *   (plain Base32), imported when "Two_Factor_Totp" is among the user's
 *   `_two_factor_enabled_providers`.
 * - WP 2FA: user meta `wp_2fa_totp_key`, imported when `wp_2fa_enabled_methods`
 *   is "totp". The key is encrypted with AES-256-CTR (prefix "lsc_", older
 *   "ssl_") under the SHA-256 of WP 2FA's own secret (the WP2FA_ENCRYPT_KEY
 *   constant in wp-config.php, else the `wp_2fa_secret_key` option), or
 *   "wps_" under WordPress's auth salt. A key that does not decrypt to a
 *   valid secret is skipped and counted.
 * - Wordfence Login Security keeps its secrets in its own tables in a format
 *   it does not document: not imported (the screen says so).
 *
 * Users who already have an authenticator app in Authlify are skipped. A
 * preview (dry run) counts what would happen without changing anything.
 * Both plugins' data is left as it was.
 *
 * @since 3.1.0
 */
final class Import
{
    /**
     * Sources: key => label.
     *
     * @return array
     */
    public static function sources()
    {
        return array(
            'two-factor' => __('Two Factor', 'modify-login'),
            'wp-2fa' => __('WP 2FA', 'modify-login'),
        );
    }

    /**
     * Meta key holding the secret, per source.
     *
     * @param string $source Source key.
     * @return string
     */
    private static function meta_key($source)
    {
        return 'wp-2fa' === $source ? 'wp_2fa_totp_key' : '_two_factor_totp_key';
    }

    /**
     * Users with a stored secret from a source (cheap count for the screen).
     *
     * @param string $source Source key.
     * @return int
     */
    public static function count($source)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", self::meta_key($source)));
    }

    /**
     * Whether Wordfence Login Security has two-factor users here.
     *
     * @return bool
     */
    public static function wordfence_found()
    {
        global $wpdb;

        $table = $wpdb->base_prefix . 'wfls_2fa_secrets';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") > 0;
    }

    /**
     * Work out what an import would do, without changing anything.
     *
     * @param string $source Source key.
     * @return array import (user ID => secret), skipped (reason => count), total.
     */
    public static function plan($source)
    {
        global $wpdb;

        $plan = array('import' => array(), 'skipped' => array(), 'total' => 0);
        if (!isset(self::sources()[$source])) {
            return $plan;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY user_id", self::meta_key($source)));
        foreach ((array) $rows as $row) {
            $user_id = (int) $row->user_id;
            if (isset($plan['import'][$user_id])) {
                continue;
            }
            ++$plan['total'];

            $reason = '';
            $secret = '';
            if (!get_userdata($user_id)) {
                $reason = 'no_user';
            } elseif (!self::enabled($source, $user_id)) {
                $reason = 'not_enabled';
            } elseif (Totp::is_configured($user_id)) {
                $reason = 'already';
            } else {
                $secret = 'wp-2fa' === $source ? self::wp2fa_secret((string) $row->meta_value) : (string) $row->meta_value;
                if (!self::valid_secret($secret)) {
                    $reason = 'unreadable';
                }
            }

            if ('' !== $reason) {
                $plan['skipped'][$reason] = isset($plan['skipped'][$reason]) ? $plan['skipped'][$reason] + 1 : 1;
            } else {
                $plan['import'][$user_id] = $secret;
            }
        }

        return $plan;
    }

    /**
     * Whether the user had the authenticator app turned on in the other plugin.
     *
     * @param string $source  Source key.
     * @param int    $user_id User ID.
     * @return bool
     */
    private static function enabled($source, $user_id)
    {
        if ('wp-2fa' === $source) {
            return 'totp' === (string) get_user_meta($user_id, 'wp_2fa_enabled_methods', true);
        }

        $providers = get_user_meta($user_id, '_two_factor_enabled_providers', true);

        return is_array($providers) && in_array('Two_Factor_Totp', $providers, true);
    }

    /**
     * Whether a value is a usable Base32 TOTP secret.
     *
     * @param string $secret Secret.
     * @return bool
     */
    public static function valid_secret($secret)
    {
        $key = Totp::base32_decode((string) $secret);

        return false !== $key && strlen($key) >= 10 && strlen($key) <= 128;
    }

    /**
     * Decrypt a WP 2FA secret ('' when it cannot be read).
     *
     * @param string $stored Stored value.
     * @return string
     */
    public static function wp2fa_secret($stored)
    {
        $stored = trim((string) $stored);
        $prefix = substr($stored, 0, 4);

        // Stored without encryption (sites without OpenSSL).
        if (!in_array($prefix, array('lsc_', 'ssl_', 'wps_'), true)) {
            return self::valid_secret($stored) ? $stored : '';
        }

        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        if ('wps_' === $prefix) {
            $material = wp_salt();
        } else {
            $material = defined('WP2FA_ENCRYPT_KEY') ? (string) constant('WP2FA_ENCRYPT_KEY') : (string) (is_multisite() ? get_network_option(null, 'wp_2fa_secret_key', '') : get_option('wp_2fa_secret_key', ''));
            if ('' === $material) {
                return '';
            }
        }

        $raw = base64_decode(substr($stored, 4), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $iv_length = openssl_cipher_iv_length('aes-256-ctr');
        if (false === $raw || strlen($raw) <= $iv_length) {
            return '';
        }

        $key = openssl_digest((string) base64_decode($material), 'SHA256', true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $plain = openssl_decrypt(substr($raw, $iv_length), 'aes-256-ctr', $key, OPENSSL_RAW_DATA, substr($raw, 0, $iv_length));

        return is_string($plain) && self::valid_secret($plain) ? $plain : '';
    }

    /**
     * Run (or preview) an import.
     *
     * @param string $source Source key.
     * @param bool   $dry    Preview only.
     * @return array imported, skipped (reason => count), total, dry.
     */
    public static function run($source, $dry = true)
    {
        $plan = self::plan($source);
        $result = array('imported' => 0, 'skipped' => $plan['skipped'], 'total' => $plan['total'], 'dry' => (bool) $dry, 'source' => $source);

        if ($dry) {
            $result['imported'] = count($plan['import']);

            return $result;
        }

        foreach ($plan['import'] as $user_id => $secret) {
            $done = Totp::import_secret($user_id, $secret);
            if (is_wp_error($done)) {
                $reason = 'authlify_totp_import_exists' === $done->get_error_code() ? 'already' : 'unreadable';
                $result['skipped'][$reason] = isset($result['skipped'][$reason]) ? $result['skipped'][$reason] + 1 : 1;
                continue;
            }
            ++$result['imported'];
            TwoFactor::sync_flag($user_id);
            TwoFactor::log_change($user_id, 'twofa_enabled', array('method' => 'totp', 'imported_from' => $source));
        }
        TwoFactor::flush_coverage();

        Log::add('twofa_import', array(
            'user_id' => get_current_user_id(),
            'username' => wp_get_current_user()->user_login,
            'context' => array_merge(array('source' => $source, 'imported' => $result['imported'], 'total' => $result['total']), array('skipped' => $result['skipped'])),
        ));

        return $result;
    }

    /**
     * One sentence about a result.
     *
     * @param array $result Result of run().
     * @return string
     */
    public static function summary(array $result)
    {
        $sources = self::sources();
        $name = isset($sources[$result['source']]) ? $sources[$result['source']] : $result['source'];
        $n = (int) $result['imported'];

        if ($result['dry']) {
            /* translators: 1: number of users, 2: plugin name */
            $text = sprintf(_n('Preview: %1$d user would be imported from %2$s.', 'Preview: %1$d users would be imported from %2$s.', $n, 'modify-login'), $n, $name);
        } else {
            /* translators: 1: number of users, 2: plugin name */
            $text = sprintf(_n('Imported the authenticator app of %1$d user from %2$s. They keep using the same app entry.', 'Imported the authenticator apps of %1$d users from %2$s. They keep using the same app entries.', $n, 'modify-login'), $n, $name);
        }

        $reasons = array(
            'already' => __('already set up in Authlify', 'modify-login'),
            'not_enabled' => __('app saved but not turned on', 'modify-login'),
            'unreadable' => __('secret could not be read', 'modify-login'),
            'no_user' => __('user no longer exists', 'modify-login'),
        );
        $parts = array();
        foreach ((array) $result['skipped'] as $reason => $count) {
            $parts[] = sprintf('%d %s', (int) $count, isset($reasons[$reason]) ? $reasons[$reason] : $reason);
        }
        if ($parts) {
            /* translators: %s: list such as "2 already set up in Authlify, 1 secret could not be read" */
            $text .= ' ' . sprintf(__('Skipped: %s.', 'modify-login'), implode(', ', $parts));
        }

        if (!$result['dry'] && $n > 0) {
            /* translators: %s: plugin name */
            $text .= ' ' . sprintf(__('Now deactivate %s: Authlify\'s two-factor login takes over once it is off.', 'modify-login'), $name);
        }

        return $text;
    }

    /**
     * Preview or import (Tools → Switch plugins).
     */
    public static function handle()
    {
        if (!current_user_can(Plugin::cap()) || !check_admin_referer('authlify_2fa_import')) {
            wp_die(esc_html__('You are not allowed to do that.', 'modify-login'), 403);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
        $dry = empty($_POST['run']);

        if (isset(self::sources()[$source])) {
            $result = self::run($source, $dry);
            set_transient('authlify_import_result_' . get_current_user_id(), self::summary($result), MINUTE_IN_SECONDS);
        }

        wp_safe_redirect(\Authlify\Admin\Menu::url('tools'));
        exit;
    }
}
