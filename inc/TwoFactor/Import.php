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
 *   is "totp". Since WP 2FA 4.2 the key is "lsc_v2:" plus an authenticated
 *   secretbox, under an HMAC of the SHA-256 of WP 2FA's own secret (the
 *   WP2FA_ENCRYPT_KEY constant in wp-config.php, else the `wp_2fa_secret_key`
 *   option). Keys it has not yet upgraded are AES-256-CTR (prefix "lsc_", older
 *   "ssl_") under that SHA-256, or "wps_" under WordPress's auth salt. A key
 *   that does not decrypt to a valid secret is skipped and counted.
 * - Wordfence Login Security (and the full Wordfence plugin, which bundles it):
 *   table `wfls_2fa_secrets` (`user_id`, `secret`, `recovery`, `vtime`, `mode`).
 *   The secret is a raw 20-byte blob with the same TOTP parameters Authlify
 *   uses, so it only needs Base32 encoding. Recovery codes are 8 raw bytes each,
 *   stored end to end, and people type them as 16 hex characters; they are
 *   hashed into Authlify's backup codes unless the user already has some.
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
            'wordfence' => __('Wordfence Login Security', 'modify-login'),
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

        if ('wordfence' === $source) {
            $table = self::wordfence_table();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return '' === $table ? 0 : (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$table}");
        }

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
        return self::count('wordfence') > 0;
    }

    /**
     * Wordfence's table of two-factor secrets, or '' when it is not there.
     *
     * @return string
     */
    private static function wordfence_table()
    {
        global $wpdb;

        $table = $wpdb->base_prefix . 'wfls_2fa_secrets';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) ? $table : '';
    }

    /**
     * Whether the full Wordfence plugin is active. It bundles login security,
     * so it keeps handling two-factor login until it is deactivated.
     *
     * @return bool
     */
    public static function wordfence_full_active()
    {
        return defined('WORDFENCE_VERSION') || class_exists('wordfence', false);
    }

    /**
     * Work out a Wordfence import.
     *
     * @return array See plan(), plus recovery (user ID => codes) and steps (user ID => last used step).
     */
    private static function wordfence_plan()
    {
        global $wpdb;

        $plan = array('import' => array(), 'skipped' => array(), 'total' => 0, 'recovery' => array(), 'steps' => array());
        $table = self::wordfence_table();
        if ('' === $table) {
            return $plan;
        }

        // HEX(): the columns are binary, and this way no connection charset can change them.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SELECT user_id, HEX(secret) AS secret, HEX(recovery) AS recovery, vtime, mode FROM {$table} ORDER BY user_id");
        foreach ((array) $rows as $row) {
            $user_id = (int) $row->user_id;
            if (isset($plan['import'][$user_id])) {
                continue;
            }
            ++$plan['total'];

            $secret = '';
            if (!get_userdata($user_id)) {
                $reason = 'no_user';
            } elseif (Totp::is_configured($user_id)) {
                $reason = 'already';
            } else {
                $secret = 'authenticator' === (string) $row->mode ? self::wordfence_secret((string) $row->secret) : '';
                $reason = '' === $secret ? 'unreadable' : '';
            }

            if ('' !== $reason) {
                $plan['skipped'][$reason] = isset($plan['skipped'][$reason]) ? $plan['skipped'][$reason] + 1 : 1;
                continue;
            }

            $plan['import'][$user_id] = $secret;
            $plan['recovery'][$user_id] = self::wordfence_recovery((string) $row->recovery);
            $plan['steps'][$user_id] = (int) floor((int) $row->vtime / 30);
        }

        return $plan;
    }

    /**
     * Base32 secret from Wordfence's stored bytes ('' when it is not 20 bytes).
     *
     * @param string $hex Secret as hex.
     * @return string
     */
    public static function wordfence_secret($hex)
    {
        return 40 === strlen((string) $hex) && ctype_xdigit((string) $hex) ? Totp::base32_encode((string) hex2bin((string) $hex)) : '';
    }

    /**
     * Recovery codes from Wordfence's stored bytes, as the 16 hex characters people type.
     *
     * @param string $hex Codes as hex, end to end.
     * @return string[]
     */
    public static function wordfence_recovery($hex)
    {
        $hex = strtolower((string) $hex);
        if ('' === $hex || !ctype_xdigit($hex)) {
            return array();
        }

        return array_values(array_filter(str_split($hex, 16), function ($code) {
            return 16 === strlen($code);
        }));
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

        if ('wordfence' === $source) {
            return self::wordfence_plan();
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

        // The new format needs no OpenSSL, only the older ones do.
        if (0 !== strpos($stored, 'lsc_v2:') && !function_exists('openssl_decrypt')) {
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

        // WP 2FA 4.2 and later: "lsc_v2:" and a sealed box.
        if (0 === strpos($stored, 'lsc_v2:')) {
            $key = hash_hmac('sha256', 'wp-2fa authenticated encryption v2', hash('sha256', (string) base64_decode($material), true), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            $plain = self::open_secretbox(substr($stored, strlen('lsc_v2:')), $key);

            return is_string($plain) && self::valid_secret($plain) ? $plain : '';
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
     * Open base64(nonce + XSalsa20-Poly1305 box), natively or with the
     * sodium_compat library WordPress ships.
     *
     * @param string $encoded Base64 value.
     * @param string $key     32-byte key.
     * @return string|false
     */
    private static function open_secretbox($encoded, $key)
    {
        $raw = base64_decode((string) $encoded, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        if (false === $raw || strlen($raw) < 40) {
            return false;
        }

        $nonce = substr($raw, 0, 24);
        $box = substr($raw, 24);

        try {
            if (function_exists('sodium_crypto_secretbox_open')) {
                return sodium_crypto_secretbox_open($box, $nonce, $key);
            }
            if (!class_exists('ParagonIE_Sodium_Compat', false) && is_readable(ABSPATH . WPINC . '/sodium_compat/autoload.php')) {
                require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
            }
            if (class_exists('ParagonIE_Sodium_Compat')) {
                return \ParagonIE_Sodium_Compat::crypto_secretbox_open($box, $nonce, $key);
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
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
        $result = array('imported' => 0, 'skipped' => $plan['skipped'], 'total' => $plan['total'], 'dry' => (bool) $dry, 'source' => $source, 'recovery' => 0, 'remaining' => 0);

        if ($dry) {
            $result['imported'] = count($plan['import']);
            $result['recovery'] = count(array_filter(isset($plan['recovery']) ? $plan['recovery'] : array()));

            return $result;
        }

        // Hashing recovery codes takes time: a large site finishes over several
        // clicks, and users already done are skipped as "already set up".
        $deadline = time() + (int) apply_filters('authlify_2fa_import_seconds', 20);

        foreach ($plan['import'] as $user_id => $secret) {
            if (time() >= $deadline) {
                ++$result['remaining'];
                continue;
            }

            $done = Totp::import_secret($user_id, $secret);
            if (is_wp_error($done)) {
                $reason = 'authlify_totp_import_exists' === $done->get_error_code() ? 'already' : 'unreadable';
                $result['skipped'][$reason] = isset($result['skipped'][$reason]) ? $result['skipped'][$reason] + 1 : 1;
                continue;
            }
            ++$result['imported'];

            // The code last used in the other plugin cannot be used again here.
            if (!empty($plan['steps'][$user_id])) {
                Totp::burn_step($user_id, min((int) $plan['steps'][$user_id], Totp::step()));
            }
            // Never replaces backup codes the user already has here.
            if (!empty($plan['recovery'][$user_id]) && !BackupCodes::is_configured($user_id) && BackupCodes::import_codes($user_id, $plan['recovery'][$user_id])) {
                ++$result['recovery'];
            }

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
        } elseif (0 === $n) {
            /* translators: %s: plugin name */
            $text = sprintf(__('No authenticator apps were imported from %s. Leave that plugin active, or the people below lose their second step.', 'modify-login'), $name);
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

        if (!empty($result['recovery'])) {
            $count = (int) $result['recovery'];
            /* translators: %d: number of users */
            $text .= ' ' . ($result['dry'] ? sprintf(_n('Recovery codes would be copied for %d user.', 'Recovery codes would be copied for %d users.', $count, 'modify-login'), $count) : sprintf(_n('Recovery codes were copied for %d user.', 'Recovery codes were copied for %d users.', $count, 'modify-login'), $count));
        }

        if (!empty($result['remaining'])) {
            /* translators: %d: number of users */
            $text .= ' ' . sprintf(_n('%d user is left: click Import again to finish.', '%d users are left: click Import again to finish.', (int) $result['remaining'], 'modify-login'), (int) $result['remaining']);
        }

        if (!$result['dry'] && $n > 0) {
            if ('wordfence' === $result['source'] && self::wordfence_full_active()) {
                $text .= ' ' . __('The full Wordfence plugin is active and keeps handling two-factor login. Authlify\'s step takes over only if Wordfence is deactivated, and that also turns off its firewall and scanner.', 'modify-login');
            } else {
                /* translators: %s: plugin name */
                $text .= ' ' . sprintf(__('Now deactivate %s: Authlify\'s two-factor login takes over once it is off.', 'modify-login'), $name);
            }
        }

        return $text;
    }

    /**
     * Preview or import (Tools → Switch plugins).
     */
    public static function handle()
    {
        // Secrets and users are shared by the whole network, so one site's admin may not do this.
        if (!current_user_can(Plugin::cap()) || (is_multisite() && !is_super_admin()) || !check_admin_referer('authlify_2fa_import')) {
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
