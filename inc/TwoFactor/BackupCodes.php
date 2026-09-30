<?php
/**
 * One-time backup codes.
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

use Authlify\Log\Log;

defined('ABSPATH') || exit;

/**
 * Ten single-use recovery codes per user, stored hashed with wp_hash_password().
 * They are shown once when generated. They are a fallback, not a method on
 * their own: a user with only backup codes does not count as having 2FA.
 *
 * @since 3.0.0
 */
final class BackupCodes
{
    const COUNT = 10;
    const LENGTH = 8;
    const WARN_AT = 2;

    const META = 'authlify_backup_codes';
    const META_CREATED = 'authlify_backup_codes_created';

    /**
     * Characters used in codes (no 0/O, 1/I/L to avoid misreading).
     */
    const CHARS = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * Generate a new set, replacing the old one.
     *
     * @param int $user_id User ID.
     * @return string[] Plain codes, formatted "xxxx-xxxx", to show once.
     * @since 3.0.0
     */
    public static function generate($user_id)
    {
        $user_id = (int) $user_id;
        $plain = array();
        $hashes = array();
        $max = strlen(self::CHARS) - 1;

        for ($i = 0; $i < self::COUNT; $i++) {
            $code = '';
            for ($j = 0; $j < self::LENGTH; $j++) {
                $code .= self::CHARS[random_int(0, $max)];
            }
            $plain[] = substr($code, 0, 4) . '-' . substr($code, 4);
            $hashes[] = wp_hash_password($code);
        }

        update_user_meta($user_id, self::META, $hashes);
        update_user_meta($user_id, self::META_CREATED, time());

        return $plain;
    }

    /**
     * Codes left.
     *
     * @param int $user_id User ID.
     * @return int
     * @since 3.0.0
     */
    public static function remaining($user_id)
    {
        $hashes = get_user_meta((int) $user_id, self::META, true);

        return is_array($hashes) ? count($hashes) : 0;
    }

    /**
     * Whether the user has unused codes.
     *
     * @param int $user_id User ID.
     * @return bool
     * @since 3.0.0
     */
    public static function is_configured($user_id)
    {
        return self::remaining($user_id) > 0;
    }

    /**
     * Normalise what the user typed.
     *
     * @param string $code Code.
     * @return string
     */
    private static function normalize($code)
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $code));
    }

    /**
     * Use a code: when it matches, it is removed.
     *
     * @param int    $user_id User ID.
     * @param string $code    Code.
     * @return bool
     * @since 3.0.0
     */
    public static function use_code($user_id, $code)
    {
        $user_id = (int) $user_id;
        $code = self::normalize($code);

        if (self::LENGTH !== strlen($code)) {
            return false;
        }

        // Read the stored list straight from the database and remove the code
        // with a compare-and-set, so two parallel requests with the same code
        // cannot both succeed. A lost race re-reads and checks again.
        for ($try = 0; $try < 3; $try++) {
            $raw = self::raw($user_id);
            $hashes = null === $raw ? false : maybe_unserialize($raw);
            if (!is_array($hashes) || !$hashes) {
                return false;
            }

            $match = null;
            foreach ($hashes as $index => $hash) {
                if (wp_check_password($code, $hash)) {
                    $match = $index;
                    break;
                }
            }
            if (null === $match) {
                return false;
            }

            unset($hashes[$match]);
            $hashes = array_values($hashes);

            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic compare-and-set; the meta cache is cleared below.
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s AND meta_value = %s",
                maybe_serialize($hashes),
                $user_id,
                self::META,
                $raw
            ));
            wp_cache_delete($user_id, 'user_meta');

            if ($updated) {
                $user = get_userdata($user_id);
                Log::add('backup_code_used', array(
                    'user_id' => $user_id,
                    'username' => $user ? $user->user_login : '',
                    'context' => array('remaining' => count($hashes)),
                ));

                return true;
            }
        }

        return false;
    }

    /**
     * The stored (serialized) list, read from the database, not the cache.
     *
     * @param int $user_id User ID.
     * @return string|null
     */
    private static function raw($user_id)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $value = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC LIMIT 1", (int) $user_id, self::META));

        return null === $value ? null : (string) $value;
    }

    /**
     * Remove all codes.
     *
     * @param int $user_id User ID.
     * @since 3.0.0
     */
    public static function remove($user_id)
    {
        delete_user_meta((int) $user_id, self::META);
        delete_user_meta((int) $user_id, self::META_CREATED);
    }

    /**
     * Login-step fields.
     *
     * @param \WP_User $user User.
     * @since 3.0.0
     */
    public static function render($user)
    {
        $left = self::remaining($user->ID);
        ?>
        <p class="authlify-2fa__intro"><?php esc_html_e('Enter one of the backup codes you saved when you set up two-factor login. Each code works once.', 'modify-login'); ?></p>
        <p>
            <label for="authlify_code"><?php esc_html_e('Backup code', 'modify-login'); ?></label>
            <input type="text" name="authlify_code" id="authlify_code" class="input authlify-2fa__code" value="" size="20" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="12" required autofocus<?php echo LoginFlow::field_error_attrs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attributes. ?>>
        </p>
        <?php if ($left <= self::WARN_AT) : ?>
            <p class="description"><?php echo esc_html(sprintf(
                /* translators: %d: number of backup codes left */
                _n('You have %d backup code left. Make new ones from your profile after signing in.', 'You have %d backup codes left. Make new ones from your profile after signing in.', $left, 'modify-login'),
                $left
            )); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Login-step verification.
     *
     * @param \WP_User $user User.
     * @return bool
     * @since 3.0.0
     */
    public static function verify_request($user)
    {
        $code = isset($_POST['authlify_code']) ? sanitize_text_field(wp_unslash($_POST['authlify_code'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified by the login nonce.

        return self::use_code($user->ID, $code);
    }
}
