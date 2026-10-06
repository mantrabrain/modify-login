<?php
/**
 * Authenticator app codes (TOTP).
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

defined('ABSPATH') || exit;

/**
 * RFC 6238 time-based one-time passwords: HMAC-SHA1, 6 digits, 30-second
 * steps, and one step of clock drift either way.
 *
 * The secret is 20 random bytes (Base32 for the authenticator app), stored
 * encrypted by Crypto. The last accepted time step is stored too, so a code
 * can never be used twice (not even in its neighbouring window).
 *
 * @since 3.0.0
 */
final class Totp
{
    const DIGITS = 6;
    const PERIOD = 30;
    const WINDOW = 1;

    const META_SECRET = 'authlify_totp_secret';
    const META_PENDING = 'authlify_totp_pending';
    const META_LAST_STEP = 'authlify_totp_last_step';

    /**
     * Base32 alphabet (RFC 4648).
     */
    const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new random secret, Base32 encoded.
     *
     * @return string
     * @since 3.0.0
     */
    public static function new_secret()
    {
        return self::base32_encode(random_bytes(20));
    }

    /**
     * Base32 encode.
     *
     * @param string $bytes Binary.
     * @return string
     */
    public static function base32_encode($bytes)
    {
        $bits = '';
        foreach (str_split((string) $bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    /**
     * Base32 decode (ignores spaces, padding and case).
     *
     * @param string $text Base32.
     * @return string|false Binary, or false on invalid input.
     */
    public static function base32_decode($text)
    {
        $text = strtoupper(preg_replace('/[\s=-]+/', '', (string) $text));
        if ('' === $text || strspn($text, self::ALPHABET) !== strlen($text)) {
            return false;
        }

        $bits = '';
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (8 === strlen($byte)) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    /**
     * The code for a time step (RFC 4226 dynamic truncation).
     *
     * @param string $secret Base32 secret.
     * @param int    $step   Time step.
     * @return string
     * @since 3.0.0
     */
    public static function code($secret, $step)
    {
        $key = self::base32_decode($secret);
        if (false === $key) {
            return '';
        }

        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Current time step.
     *
     * @param int|null $time Unix time.
     * @return int
     */
    public static function step($time = null)
    {
        return (int) floor((null === $time ? time() : (int) $time) / self::PERIOD);
    }

    /**
     * Find the step a code matches inside the window.
     *
     * @param string   $secret   Base32 secret.
     * @param string   $code     Code the user typed.
     * @param int      $min_step Steps at or below this are refused (replay protection).
     * @param int|null $time     Unix time.
     * @return int|false Matching step, or false.
     * @since 3.0.0
     */
    public static function match($secret, $code, $min_step = 0, $time = null)
    {
        $code = preg_replace('/\D+/', '', (string) $code);
        if (self::DIGITS !== strlen($code)) {
            return false;
        }

        $now = self::step($time);
        $found = false;

        // Check every step in the window so the timing does not reveal which one matched.
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $now + $i;
            if (hash_equals(self::code($secret, $step), $code) && $step > $min_step && false === $found) {
                $found = $step;
            }
        }

        return $found;
    }

    /**
     * otpauth:// URI for the QR code.
     *
     * @param string   $secret Base32 secret.
     * @param \WP_User $user   User.
     * @return string
     * @since 3.0.0
     */
    public static function uri($secret, $user)
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        if ('' === trim($site)) {
            $site = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        }

        /**
         * Filters the issuer shown in authenticator apps.
         *
         * @param string   $site Site name.
         * @param \WP_User $user User.
         * @since 3.0.0
         */
        $site = (string) apply_filters('authlify_totp_issuer', $site, $user);
        $site = str_replace(':', '', $site);

        return 'otpauth://totp/' . rawurlencode($site) . ':' . rawurlencode($user->user_login)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($site)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /**
     * Whether the user has an authenticator app set up.
     *
     * @param int $user_id User ID.
     * @return bool
     * @since 3.0.0
     */
    public static function is_configured($user_id)
    {
        return '' !== (string) get_user_meta((int) $user_id, self::META_SECRET, true);
    }

    /**
     * The user's secret, decrypted.
     *
     * @param int $user_id User ID.
     * @return string|false
     */
    private static function secret($user_id)
    {
        $user_id = (int) $user_id;
        $stored = (string) get_user_meta($user_id, self::META_SECRET, true);
        if ('' === $stored) {
            return false;
        }

        $secret = Crypto::decrypt($stored, self::context($user_id));
        $upgrade = Crypto::needs_upgrade($stored);

        // A value written by code that did not pass a context (Crypto::encrypt($secret)).
        if (false === $secret && 's2:' === substr($stored, 0, 3)) {
            $secret = Crypto::decrypt($stored);
            $upgrade = true;
        }

        // Values from 3.0.0 (or stored before sodium was available) are
        // encrypted again in the current format, bound to this user.
        if (false !== $secret && $upgrade) {
            update_user_meta($user_id, self::META_SECRET, Crypto::encrypt($secret, self::context($user_id)));
        }

        return $secret;
    }

    /**
     * Encryption context of a user's secrets (binds the ciphertext to the user).
     *
     * @param int $user_id User ID.
     * @return string
     */
    private static function context($user_id)
    {
        return 'totp|' . (int) $user_id;
    }

    /**
     * Start setup: store a pending secret (valid for 30 minutes) and return it.
     *
     * @param int $user_id User ID.
     * @return string Base32 secret.
     * @since 3.0.0
     */
    public static function begin_setup($user_id)
    {
        $secret = self::new_secret();
        update_user_meta((int) $user_id, self::META_PENDING, array(
            'secret' => Crypto::encrypt($secret, self::context($user_id)),
            'expires' => time() + 30 * MINUTE_IN_SECONDS,
        ));

        return $secret;
    }

    /**
     * Finish setup: the code must match the pending secret.
     *
     * @param int    $user_id User ID.
     * @param string $code    Code.
     * @return true|\WP_Error
     * @since 3.0.0
     */
    public static function finish_setup($user_id, $code)
    {
        $user_id = (int) $user_id;
        $pending = get_user_meta($user_id, self::META_PENDING, true);

        if (!is_array($pending) || empty($pending['secret']) || (int) $pending['expires'] < time()) {
            return new \WP_Error('authlify_totp_expired', __('The setup expired. Start again to get a new QR code.', 'modify-login'));
        }

        $secret = Crypto::decrypt($pending['secret'], self::context($user_id));
        $step = false === $secret ? false : self::match($secret, $code);

        if (false === $step) {
            return new \WP_Error('authlify_totp_invalid', __('That code is not right. Check the time on your phone is set automatically, then try the newest code.', 'modify-login'));
        }

        $was_on = TwoFactor::is_active_for($user_id);

        update_user_meta($user_id, self::META_SECRET, $pending['secret']);
        update_user_meta($user_id, self::META_LAST_STEP, $step);
        delete_user_meta($user_id, self::META_PENDING);

        TwoFactor::sync_flag($user_id);
        TwoFactor::log_change($user_id, 'twofa_enabled', array('method' => 'totp', 'first' => !$was_on));

        return true;
    }

    /**
     * Store an authenticator-app secret another plugin set up, so the user
     * keeps the same app entry (Tools → Switch plugins). Never replaces a
     * secret the user already has here.
     *
     * @param int    $user_id User ID.
     * @param string $secret  Base32 secret.
     * @return true|\WP_Error
     * @since 3.1.0
     */
    public static function import_secret($user_id, $secret)
    {
        $user_id = (int) $user_id;
        $secret = strtoupper(preg_replace('/[\s=-]+/', '', (string) $secret));
        $key = self::base32_decode($secret);

        // RFC 4226 asks for at least 128 bits; the plugins we import from use 80-160.
        if (false === $key || strlen($key) < 10) {
            return new \WP_Error('authlify_totp_import_invalid', __('The secret is not a valid authenticator-app key.', 'modify-login'));
        }
        if (self::is_configured($user_id)) {
            return new \WP_Error('authlify_totp_import_exists', __('This user already has an authenticator app in Authlify.', 'modify-login'));
        }

        update_user_meta($user_id, self::META_SECRET, Crypto::encrypt($secret, self::context($user_id)));
        delete_user_meta($user_id, self::META_PENDING);
        delete_user_meta($user_id, self::META_LAST_STEP);

        return true;
    }

    /**
     * Remove the authenticator app.
     *
     * @param int $user_id User ID.
     * @since 3.0.0
     */
    public static function remove($user_id)
    {
        delete_user_meta((int) $user_id, self::META_SECRET);
        delete_user_meta((int) $user_id, self::META_PENDING);
        delete_user_meta((int) $user_id, self::META_LAST_STEP);
    }

    /**
     * Verify a login code and burn its time step.
     *
     * @param int    $user_id User ID.
     * @param string $code    Code.
     * @return bool
     * @since 3.0.0
     */
    public static function verify($user_id, $code)
    {
        $user_id = (int) $user_id;
        $secret = self::secret($user_id);
        if (false === $secret) {
            return false;
        }

        $last = (int) get_user_meta($user_id, self::META_LAST_STEP, true);
        $step = self::match($secret, $code, $last);
        if (false === $step) {
            return false;
        }

        // Two requests with the same code may both get here: only the one
        // that moves the stored step forward wins.
        return self::burn_step($user_id, $step);
    }

    /**
     * Store a used time step atomically: succeeds only when the stored step is
     * older (a compare-and-set in SQL, so parallel requests cannot both pass).
     *
     * @param int $user_id User ID.
     * @param int $step    Step.
     * @return bool
     * @since 3.0.0
     */
    public static function burn_step($user_id, $step)
    {
        global $wpdb;
        $user_id = (int) $user_id;
        $step = (int) $step;

        for ($try = 0; $try < 2; $try++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an atomic compare-and-set; the meta cache is cleared below.
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s AND CAST(meta_value AS SIGNED) < %d",
                (string) $step,
                $user_id,
                self::META_LAST_STEP,
                $step
            ));
            wp_cache_delete($user_id, 'user_meta');

            if ($updated) {
                return true;
            }

            // No row yet (first login after setup): add it; a unique add fails if a parallel request added one.
            $exists = $wpdb->get_var($wpdb->prepare("SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1", $user_id, self::META_LAST_STEP)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($exists) {
                return false;
            }
            if (add_user_meta($user_id, self::META_LAST_STEP, (string) $step, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Login-step fields.
     *
     * @param \WP_User $user User.
     * @since 3.0.0
     */
    public static function render($user)
    {
        ?>
        <p class="authlify-2fa__intro"><?php esc_html_e('Enter the 6-digit code from your authenticator app.', 'modify-login'); ?></p>
        <p>
            <label for="authlify_code"><?php esc_html_e('Authentication code', 'modify-login'); ?></label>
            <input type="text" name="authlify_code" id="authlify_code" class="input authlify-2fa__code" value="" size="20" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" maxlength="7" required autofocus<?php echo LoginFlow::field_error_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attributes. ?>>
        </p>
        <?php
    }

    /**
     * Login-step verification.
     *
     * @param \WP_User $user User.
     * @return bool|\WP_Error
     * @since 3.0.0
     */
    public static function verify_request($user)
    {
        $code = isset($_POST['authlify_code']) ? sanitize_text_field(wp_unslash($_POST['authlify_code'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified by the login nonce.

        if (self::verify($user->ID, $code)) {
            return true;
        }

        // A right code that was already used (say, right after setup): explain.
        $secret = self::secret($user->ID);
        if (false !== $secret && false !== self::match($secret, $code, 0)) {
            return new \WP_Error('authlify_2fa_failed', __('<strong>Error:</strong> That code was just used. Wait for the next code in your app, then enter it.', 'modify-login'));
        }

        return false;
    }
}
