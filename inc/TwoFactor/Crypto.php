<?php
/**
 * Secret storage for two-factor methods.
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

defined('ABSPATH') || exit;

/**
 * Encrypts secrets (TOTP keys) before they are stored in user meta.
 *
 * Uses sodium crypto_secretbox (XSalsa20-Poly1305). WordPress ships the
 * sodium_compat polyfill, so this works on every host that runs WordPress.
 * The key material comes from AUTH_KEY and SECURE_AUTH_SALT, which means
 * changing those salts makes stored secrets unreadable: affected users then
 * sign in with a backup code or passkey, or an admin resets their two-factor
 * login.
 *
 * Stored formats:
 * - "s2:" + base64(nonce . ciphertext): current. The key is derived with HKDF
 *   from the salts, a fixed label and the caller's context (for TOTP keys the
 *   user ID), so a value copied to another user's meta does not decrypt.
 * - "s1:" + base64(nonce . ciphertext): 3.0.0, one raw SHA-256 key for
 *   everything. Still read; callers re-encrypt it as s2 (see needs_upgrade()).
 * - "p1:" + base64(plain): written only when sodium is missing (reported in
 *   Site Health). Once sodium is available it is read only on sites that
 *   really wrote it (a site flag), so a planted plain value is refused.
 *
 * @since 3.0.0
 */
final class Crypto
{
    /**
     * Site option set when a secret was ever stored without encryption.
     */
    const PLAIN_FLAG = 'authlify_crypto_plain_written';

    /**
     * Whether secrets can be encrypted.
     *
     * @return bool
     * @since 3.0.0
     */
    public static function available()
    {
        return function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open');
    }

    /**
     * Key material from the salts.
     *
     * @return string
     */
    private static function material()
    {
        $auth_key = defined('AUTH_KEY') ? AUTH_KEY : '';
        $salt = defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '';

        // Without salts in wp-config.php, fall back to the database-stored salts.
        if ('' === $auth_key . $salt || 'put your unique phrase here' === $auth_key) {
            $auth_key = wp_salt('auth');
            $salt = wp_salt('secure_auth');
        }

        return $auth_key . $salt;
    }

    /**
     * The 3.0.0 key (s1 values).
     *
     * @return string
     */
    private static function legacy_key()
    {
        return hash('sha256', self::material(), true);
    }

    /**
     * The key for s2 values: HKDF-SHA256 with a label and the context.
     *
     * @param string $context Context, e.g. "totp|42".
     * @return string 32 bytes.
     */
    private static function key($context)
    {
        $info = 'authlify/secret/v2|' . (string) $context;

        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', self::material(), 32, $info, 'authlify');
        }

        // RFC 5869 with one output block (32 bytes), for builds without hash_hkdf().
        $prk = hash_hmac('sha256', self::material(), 'authlify', true);

        return hash_hmac('sha256', $info . chr(1), $prk, true);
    }

    /**
     * Encrypt a secret for storage.
     *
     * @param string $plain   Secret.
     * @param string $context What the secret belongs to (bound into the key); the same value must be given to decrypt().
     * @return string
     * @since 3.0.0
     */
    public static function encrypt($plain, $context = '')
    {
        $plain = (string) $plain;

        if (!self::available()) {
            if (!get_site_option(self::PLAIN_FLAG)) {
                update_site_option(self::PLAIN_FLAG, 1);
            }

            return 'p1:' . base64_encode($plain); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        }

        $nonce = random_bytes(24);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key($context));

        return 's2:' . base64_encode($nonce . $cipher); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Decrypt a stored secret.
     *
     * @param string $stored  Stored value.
     * @param string $context The context given to encrypt() (ignored for older formats).
     * @return string|false The secret, or false when it cannot be read.
     * @since 3.0.0
     */
    public static function decrypt($stored, $context = '')
    {
        $stored = (string) $stored;
        $prefix = substr($stored, 0, 3);
        $raw = base64_decode(substr($stored, 3), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

        if (false === $raw) {
            return false;
        }

        if ('p1:' === $prefix) {
            // Plain values are only trusted where this site wrote them itself.
            return !self::available() || get_site_option(self::PLAIN_FLAG) ? $raw : false;
        }

        if (!in_array($prefix, array('s1:', 's2:'), true) || !self::available() || strlen($raw) < 24 + 16) {
            return false;
        }

        try {
            $plain = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), 's2:' === $prefix ? self::key($context) : self::legacy_key());
        } catch (\Throwable $e) {
            return false;
        }

        return false === $plain ? false : $plain;
    }

    /**
     * Whether a stored value is in an older format that should be encrypted
     * again with encrypt() (callers do this after reading it successfully).
     *
     * @param string $stored Stored value.
     * @return bool
     * @since 3.0.0
     */
    public static function needs_upgrade($stored)
    {
        $prefix = substr((string) $stored, 0, 3);

        return self::available() && in_array($prefix, array('s1:', 'p1:'), true);
    }
}
