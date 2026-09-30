<?php
/**
 * Passkeys (WebAuthn).
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

use Authlify\Log\Log;

defined('ABSPATH') || exit;

/**
 * Passkeys and security keys through the vendored lbuchs/WebAuthn library
 * (MIT, lib/WebAuthn), which needs PHP 8.0 or newer. On older PHP the method
 * is hidden and nothing from lib/ is loaded.
 *
 * Credentials live in {base_prefix}authlify_passkeys. Users are network-wide,
 * so rows are not tied to a blog; they are tied to the relying-party ID (the
 * site host), because a passkey only works on the host it was created for.
 *
 * Passkeys work as a second factor after the password, and on their own for
 * "Sign in with a passkey" (discoverable credentials).
 *
 * @since 3.0.0
 */
final class Passkeys
{
    const META_HANDLE = 'authlify_passkey_handle';
    const META_REGISTER = 'authlify_passkey_register';

    /**
     * Site option: the table was seen (saves a SHOW TABLES per request).
     */
    const TABLE_FLAG = 'authlify_passkeys_table';

    /**
     * Site option: rp_id => array( any, checked ): whether anyone has a
     * passkey for that host (the login button shows only then).
     */
    const ANY_FLAG = 'authlify_passkeys_any';

    /**
     * Object cache group.
     */
    const CACHE = 'authlify_passkeys';

    /**
     * Passkey counts for all users (request cache for counts()).
     *
     * @var array|null
     */
    private static $counts = null;

    /**
     * Passkey counts per user (request cache).
     *
     * @var array
     */
    private static $user_counts = array();

    /**
     * Whether the table exists (request cache).
     *
     * @var bool|null
     */
    private static $exists = null;

    /**
     * Cached server.
     *
     * @var object|null
     */
    private static $server = null;

    /**
     * Whether passkeys can run here (PHP 8.0+ with OpenSSL).
     *
     * @return bool
     * @since 3.0.0
     */
    public static function supported()
    {
        return PHP_VERSION_ID >= 80000 && function_exists('openssl_verify') && function_exists('openssl_get_md_methods');
    }

    /**
     * Table name.
     *
     * @return string
     * @since 3.0.0
     */
    public static function table()
    {
        global $wpdb;

        return $wpdb->base_prefix . 'authlify_passkeys';
    }

    /**
     * CREATE TABLE statement (authlify_install_tables).
     *
     * @param string[] $sql     Statements.
     * @param string   $prefix  Base prefix.
     * @param string   $charset Charset clause.
     * @return string[]
     * @since 3.0.0
     */
    public static function install_table($sql, $prefix = '', $charset = '')
    {
        $sql[] = "CREATE TABLE {$prefix}authlify_passkeys (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            rp_id varchar(191) NOT NULL DEFAULT '',
            credential_hash char(64) NOT NULL,
            credential_id text NOT NULL,
            public_key text NOT NULL,
            sign_count int(10) unsigned NOT NULL DEFAULT 0,
            name varchar(100) NOT NULL DEFAULT '',
            transports varchar(191) NOT NULL DEFAULT '',
            aaguid char(36) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            last_used_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY credential_hash (credential_hash),
            KEY user_rp (user_id, rp_id)
        ) {$charset};";

        return $sql;
    }

    /**
     * Whether the table exists (it may be missing until the upgrade runs).
     *
     * @return bool
     */
    public static function table_exists()
    {
        if (null === self::$exists) {
            // Once seen, remembered in a site option (cleared on upgrade), so
            // normal requests do not run SHOW TABLES.
            if (get_site_option(self::TABLE_FLAG)) {
                self::$exists = true;
            } else {
                global $wpdb;
                self::$exists = self::table() === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::table()))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if (self::$exists) {
                    update_site_option(self::TABLE_FLAG, 1);
                }
            }
        }

        return self::$exists;
    }

    /**
     * Forget what is known about the table (after an upgrade creates it).
     *
     * @since 3.0.0
     */
    public static function forget_table()
    {
        self::$exists = null;
        delete_site_option(self::TABLE_FLAG);
    }

    /**
     * Relying-party ID: the site host.
     *
     * @return string
     * @since 3.0.0
     */
    public static function rp_id()
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        /**
         * Filters the WebAuthn relying-party ID (default: the site host).
         *
         * Changing it makes existing passkeys stop working.
         *
         * @param string $host Host.
         * @since 3.0.0
         */
        return (string) apply_filters('authlify_passkey_rp_id', $host);
    }

    /**
     * Origins a ceremony may come from (home and site URL, scheme + host + port).
     *
     * @return string[]
     */
    private static function origins()
    {
        $origins = array();
        foreach (array(home_url(), site_url(), wp_login_url()) as $url) {
            $parts = wp_parse_url($url);
            if (!empty($parts['host'])) {
                $origins[] = strtolower((isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
            }
        }

        /**
         * Filters the origins passkey ceremonies are accepted from.
         *
         * @param string[] $origins Origins like https://example.com.
         * @since 3.0.0
         */
        return array_unique((array) apply_filters('authlify_passkey_origins', $origins));
    }

    /**
     * The WebAuthn server.
     *
     * @return \lbuchs\WebAuthn\WebAuthn
     * @throws \Exception When the library cannot load.
     */
    private static function server()
    {
        if (null === self::$server) {
            if (!self::supported()) {
                throw new \Exception('Passkeys need PHP 8.0 or newer.');
            }
            if (!class_exists('\lbuchs\WebAuthn\WebAuthn', false)) {
                require_once AUTHLIFY_DIR . 'lib/WebAuthn/WebAuthn.php';
            }

            $name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
            self::$server = new \lbuchs\WebAuthn\WebAuthn('' !== $name ? $name : self::rp_id(), self::rp_id(), null, true);
        }

        // The library keeps this as a static flag; other plugins may change it.
        \lbuchs\WebAuthn\Binary\ByteBuffer::$useBase64UrlEncoding = true;

        return self::$server;
    }

    /**
     * Base64url encode.
     *
     * @param string $data Binary.
     * @return string
     */
    public static function b64url_encode($data)
    {
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '='); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Base64url decode.
     *
     * @param string $data Base64url.
     * @return string|false
     */
    public static function b64url_decode($data)
    {
        $data = strtr((string) $data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($data, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
    }

    /**
     * The user's WebAuthn user handle (random, never the numeric ID).
     *
     * @param int $user_id User ID.
     * @return string Binary.
     */
    private static function user_handle($user_id)
    {
        $stored = (string) get_user_meta((int) $user_id, self::META_HANDLE, true);
        if ('' === $stored) {
            $stored = self::b64url_encode(random_bytes(32));
            update_user_meta((int) $user_id, self::META_HANDLE, $stored);
        }

        return (string) self::b64url_decode($stored);
    }

    /**
     * The user's passkeys on this site.
     *
     * @param int $user_id User ID.
     * @return object[]
     * @since 3.0.0
     */
    public static function for_user($user_id)
    {
        if (!self::table_exists()) {
            return array();
        }

        global $wpdb;
        $table = self::table();

        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d AND rp_id = %s ORDER BY id ASC", (int) $user_id, self::rp_id())); // phpcs:ignore
    }

    /**
     * Number of passkeys a user has on this site.
     *
     * @param int $user_id User ID.
     * @return int
     * @since 3.0.0
     */
    public static function count_for_user($user_id)
    {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return 0;
        }
        if (null !== self::$counts) {
            return isset(self::$counts[$user_id]) ? self::$counts[$user_id] : 0;
        }
        if (isset(self::$user_counts[$user_id])) {
            return self::$user_counts[$user_id];
        }

        $key = 'u' . $user_id . ':' . md5(self::rp_id());
        $count = wp_cache_get($key, self::CACHE);

        if (false === $count) {
            $count = 0;
            if (self::table_exists()) {
                global $wpdb;
                $table = self::table();
                // One indexed lookup (user_rp), not a scan of the whole table.
                $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND rp_id = %s", $user_id, self::rp_id())); // phpcs:ignore
            }
            wp_cache_set($key, $count, self::CACHE, HOUR_IN_SECONDS);
        }

        self::$user_counts[$user_id] = (int) $count;

        return self::$user_counts[$user_id];
    }

    /**
     * Passkey counts for every user on this host (one grouped query).
     *
     * This reads the whole table: use it for reports, never on every request.
     * Single users go through count_for_user().
     *
     * @return array user_id => count
     * @since 3.0.0
     */
    public static function counts()
    {
        if (null === self::$counts) {
            self::$counts = array();
            if (self::table_exists()) {
                global $wpdb;
                $table = self::table();
                $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, COUNT(*) AS n FROM {$table} WHERE rp_id = %s GROUP BY user_id", self::rp_id())); // phpcs:ignore
                foreach ((array) $rows as $row) {
                    self::$counts[(int) $row->user_id] = (int) $row->n;
                }
            }
        }

        return self::$counts;
    }

    /**
     * Whether anyone has a passkey on this site (the login button shows only then).
     *
     * @return bool
     * @since 3.0.0
     */
    public static function any()
    {
        if (!self::supported()) {
            return false;
        }

        $rp = self::rp_id();
        $flags = get_site_option(self::ANY_FLAG, array());
        $flags = is_array($flags) ? $flags : array();

        // Kept in a site option that adding or removing a passkey updates, and
        // checked again once a day (rows may be added outside this class).
        if (isset($flags[$rp]['any'], $flags[$rp]['checked']) && (int) $flags[$rp]['checked'] > time() - DAY_IN_SECONDS) {
            return (bool) $flags[$rp]['any'];
        }

        $any = false;
        if (self::table_exists()) {
            global $wpdb;
            $table = self::table();
            $any = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE rp_id = %s LIMIT 1", $rp)); // phpcs:ignore
        }

        self::remember_any($rp, $any);

        return $any;
    }

    /**
     * Store the "anyone has a passkey" flag for a host.
     *
     * @param string $rp  RP ID.
     * @param bool   $any Any.
     */
    private static function remember_any($rp, $any)
    {
        $flags = get_site_option(self::ANY_FLAG, array());
        $flags = is_array($flags) ? $flags : array();
        // Only a handful of hosts ever: keep the newest few.
        $flags = array_slice($flags, -9, null, true);
        $flags[(string) $rp] = array('any' => (bool) $any, 'checked' => time());
        update_site_option(self::ANY_FLAG, $flags);
    }

    /**
     * Drop cached counts after a user's passkeys changed.
     *
     * @param int  $user_id User ID.
     * @param bool $added   A passkey was added (the site now has at least one).
     * @since 3.0.0
     */
    public static function flush($user_id, $added = false)
    {
        $user_id = (int) $user_id;
        self::$counts = null;
        unset(self::$user_counts[$user_id]);
        wp_cache_delete('u' . $user_id . ':' . md5(self::rp_id()), self::CACHE);

        if ($added) {
            self::remember_any(self::rp_id(), true);

            return;
        }

        // Count again on the next login page view.
        $flags = get_site_option(self::ANY_FLAG, array());
        if (is_array($flags) && isset($flags[self::rp_id()])) {
            unset($flags[self::rp_id()]);
            update_site_option(self::ANY_FLAG, $flags);
        }
    }

    /**
     * Passkeys that exist but cannot be used for signing in here, for the
     * Site Health warning: this server cannot run passkeys, or they were made
     * for another host (the site address changed).
     *
     * @return array unsupported (users with passkeys while passkeys cannot run), other_host (users whose passkeys are all for other hosts), hosts (string[]).
     * @since 3.0.0
     */
    public static function stranded()
    {
        $out = array('unsupported' => 0, 'other_host' => 0, 'hosts' => array());
        if (!self::table_exists()) {
            return $out;
        }

        global $wpdb;
        $table = self::table();
        $rp = self::rp_id();

        if (!self::supported()) {
            $out['unsupported'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$table}"); // phpcs:ignore
        }

        $out['other_host'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE rp_id <> %s AND user_id NOT IN (SELECT user_id FROM {$table} WHERE rp_id = %s)", $rp, $rp)); // phpcs:ignore
        if ($out['other_host']) {
            $out['hosts'] = array_map('strval', (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT rp_id FROM {$table} WHERE rp_id <> %s LIMIT 5", $rp))); // phpcs:ignore
        }

        return $out;
    }

    /**
     * Whether the user has passkeys here that this server cannot check
     * (PHP older than 8.0 or no OpenSSL). Two-factor login then stays on for
     * them and they sign in with another method or an email recovery link.
     *
     * @param int $user_id User ID.
     * @return bool
     * @since 3.0.0
     */
    public static function is_unavailable_for($user_id)
    {
        return !self::supported() && self::count_for_user($user_id) > 0;
    }

    /**
     * Whether the user has a passkey here.
     *
     * @param int $user_id User ID.
     * @return bool
     * @since 3.0.0
     */
    public static function is_configured($user_id)
    {
        return self::supported() && self::count_for_user($user_id) > 0;
    }

    /**
     * Options for navigator.credentials.create(); stores the challenge.
     *
     * @param \WP_User $user User.
     * @return array|\WP_Error
     * @since 3.0.0
     */
    public static function registration_options($user)
    {
        try {
            $exclude = array();
            foreach (self::for_user($user->ID) as $row) {
                $exclude[] = self::b64url_decode($row->credential_id);
            }

            $args = self::server()->getCreateArgs(
                self::user_handle($user->ID),
                $user->user_login,
                '' !== $user->display_name ? $user->display_name : $user->user_login,
                120,
                'preferred',
                'preferred',
                null,
                $exclude
            );
            unset($args->publicKey->extensions);

            update_user_meta($user->ID, self::META_REGISTER, array(
                'challenge' => self::b64url_encode(self::server()->getChallenge()->getBinaryString()),
                'expires' => time() + 5 * MINUTE_IN_SECONDS,
            ));

            return json_decode(wp_json_encode($args), true);
        } catch (\Throwable $e) {
            return new \WP_Error('authlify_passkey_options', __('Passkeys are not available on this server.', 'modify-login'));
        }
    }

    /**
     * Save a new passkey from the browser's attestation response.
     *
     * @param \WP_User $user User.
     * @param array    $data clientDataJSON, attestationObject (base64url), transports (array), name.
     * @return int|\WP_Error Row ID.
     * @since 3.0.0
     */
    public static function register($user, array $data)
    {
        $pending = get_user_meta($user->ID, self::META_REGISTER, true);
        delete_user_meta($user->ID, self::META_REGISTER);

        if (!is_array($pending) || empty($pending['challenge']) || (int) $pending['expires'] < time()) {
            return new \WP_Error('authlify_passkey_expired', __('The request expired. Please try again.', 'modify-login'));
        }

        $client = self::b64url_decode(isset($data['clientDataJSON']) ? $data['clientDataJSON'] : '');
        $attestation = self::b64url_decode(isset($data['attestationObject']) ? $data['attestationObject'] : '');

        if (!$client || !$attestation || !self::origin_ok($client)) {
            return new \WP_Error('authlify_passkey_invalid', __('The passkey response was not valid.', 'modify-login'));
        }

        try {
            $result = self::server()->processCreate($client, $attestation, self::b64url_decode($pending['challenge']), false, true, false, false);
        } catch (\Throwable $e) {
            // The library's reason goes to the log; the browser gets a plain message.
            Log::add('passkey_failed', array('user_id' => $user->ID, 'username' => $user->user_login, 'context' => array('reason' => substr(sanitize_text_field($e->getMessage()), 0, 200))));

            return new \WP_Error('authlify_passkey_invalid', __('The passkey could not be verified. Try again, or use another passkey or security key.', 'modify-login'));
        }

        $credential_id = self::b64url_encode($result->credentialId);
        $transports = isset($data['transports']) && is_array($data['transports']) ? array_values(array_intersect(array_map('sanitize_key', $data['transports']), array('usb', 'nfc', 'ble', 'hybrid', 'internal', 'smart-card'))) : array();
        $name = isset($data['name']) ? sanitize_text_field((string) $data['name']) : '';
        if ('' === $name) {
            $name = __('Passkey', 'modify-login');
        }

        global $wpdb;
        $ok = $wpdb->insert(self::table(), array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'user_id' => (int) $user->ID,
            'rp_id' => self::rp_id(),
            'credential_hash' => hash('sha256', $credential_id),
            'credential_id' => $credential_id,
            'public_key' => (string) $result->credentialPublicKey,
            'sign_count' => (int) $result->signatureCounter,
            'name' => function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100),
            'transports' => implode(',', $transports),
            'aaguid' => self::format_aaguid($result->AAGUID),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ), array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'));

        if (!$ok) {
            return new \WP_Error('authlify_passkey_duplicate', __('This passkey is already registered.', 'modify-login'));
        }

        $id = (int) $wpdb->insert_id;
        self::flush($user->ID, true);
        do_action('authlify_passkeys_changed', $user->ID);

        Log::add('passkey_added', array('user_id' => $user->ID, 'username' => $user->user_login, 'context' => array('name' => $name)));
        TwoFactor::sync_flag($user->ID);

        return $id;
    }

    /**
     * AAGUID as a UUID string.
     *
     * @param mixed $aaguid Binary or ByteBuffer.
     * @return string
     */
    private static function format_aaguid($aaguid)
    {
        if (is_object($aaguid) && method_exists($aaguid, 'getBinaryString')) {
            $aaguid = $aaguid->getBinaryString();
        }
        $hex = bin2hex((string) $aaguid);
        if (32 !== strlen($hex)) {
            return '';
        }

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /**
     * Rename a passkey.
     *
     * @param int    $user_id User ID.
     * @param int    $id      Row ID.
     * @param string $name    New name.
     * @return bool
     * @since 3.0.0
     */
    public static function rename($user_id, $id, $name)
    {
        global $wpdb;
        $name = sanitize_text_field((string) $name);
        if ('' === $name || !self::table_exists()) {
            return false;
        }

        // Only the owner's own passkey. Check first: an unchanged name updates
        // no rows, so the update's result cannot tell ownership.
        $table = self::table();
        $owned = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d AND user_id = %d", (int) $id, (int) $user_id)); // phpcs:ignore
        if (!$owned) {
            return false;
        }

        $name = function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100);

        return false !== $wpdb->update($table, array('name' => $name), array('id' => (int) $id, 'user_id' => (int) $user_id), array('%s'), array('%d', '%d')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Delete one passkey.
     *
     * @param int $user_id User ID.
     * @param int $id      Row ID.
     * @return bool
     * @since 3.0.0
     */
    public static function delete($user_id, $id)
    {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, name FROM {$table} WHERE id = %d AND user_id = %d", (int) $id, (int) $user_id)); // phpcs:ignore
        if (!$row) {
            return false;
        }

        $wpdb->delete($table, array('id' => (int) $id), array('%d')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        self::flush($user_id);
        do_action('authlify_passkeys_changed', $user_id);

        $user = get_userdata($user_id);
        Log::add('passkey_removed', array('user_id' => (int) $user_id, 'username' => $user ? $user->user_login : '', 'context' => array('name' => $row->name)));
        TwoFactor::sync_flag($user_id);

        return true;
    }

    /**
     * Delete every passkey of a user (all hosts).
     *
     * @param int $user_id User ID.
     * @since 3.0.0
     */
    public static function remove($user_id)
    {
        if (self::table_exists()) {
            global $wpdb;
            $wpdb->delete(self::table(), array('user_id' => (int) $user_id), array('%d')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            self::flush($user_id);
            do_action('authlify_passkeys_changed', $user_id);
        }
        delete_user_meta((int) $user_id, self::META_REGISTER);
        delete_user_meta((int) $user_id, self::META_HANDLE);
    }

    /**
     * Options for navigator.credentials.get().
     *
     * @param int $user_id User whose passkeys are allowed, or 0 for any discoverable passkey.
     * @return array|\WP_Error array( 'options' => array, 'challenge' => base64url ).
     * @since 3.0.0
     */
    public static function assertion_options($user_id = 0)
    {
        try {
            $ids = array();
            if ($user_id) {
                foreach (self::for_user($user_id) as $row) {
                    $ids[] = self::b64url_decode($row->credential_id);
                }
            }

            // Passwordless sign-in (no user yet) is the only factor, so it must
            // verify the person (PIN or biometric), not just a touch.
            $args = self::server()->getGetArgs($ids, 120, true, true, true, true, true, $user_id ? 'preferred' : 'required');

            return array(
                'options' => json_decode(wp_json_encode($args), true),
                'challenge' => self::b64url_encode(self::server()->getChallenge()->getBinaryString()),
            );
        } catch (\Throwable $e) {
            return new \WP_Error('authlify_passkey_options', __('Passkeys are not available on this server.', 'modify-login'));
        }
    }

    /**
     * Verify an assertion from the browser.
     *
     * @param array  $data      id, clientDataJSON, authenticatorData, signature, userHandle (base64url).
     * @param string $challenge Challenge (base64url) that was issued.
     * @param int    $user_id   Expected user, or 0 to accept the passkey's owner.
     * @return \WP_User|\WP_Error
     * @since 3.0.0
     */
    public static function verify_assertion(array $data, $challenge, $user_id = 0)
    {
        $fail = new \WP_Error('authlify_passkey_failed', __('<strong>Error:</strong> That passkey could not be verified. Please try again.', 'modify-login'));

        $credential_id = isset($data['id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $data['id']) : '';
        $client = self::b64url_decode(isset($data['clientDataJSON']) ? $data['clientDataJSON'] : '');
        $auth = self::b64url_decode(isset($data['authenticatorData']) ? $data['authenticatorData'] : '');
        $signature = self::b64url_decode(isset($data['signature']) ? $data['signature'] : '');

        if ('' === $credential_id || !$client || !$auth || !$signature || '' === (string) $challenge || !self::table_exists() || !self::origin_ok($client)) {
            return $fail;
        }

        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE credential_hash = %s AND rp_id = %s", hash('sha256', $credential_id), self::rp_id())); // phpcs:ignore

        if (!$row || ($user_id && (int) $row->user_id !== (int) $user_id)) {
            return $fail;
        }

        // A discoverable passkey returns its user handle, which must be this user's.
        if (!empty($data['userHandle'])) {
            $handle = self::b64url_decode($data['userHandle']);
            if (false === $handle || !hash_equals(self::user_handle($row->user_id), $handle)) {
                return $fail;
            }
        }

        try {
            $server = self::server();
            $server->processGet($client, $auth, $signature, $row->public_key, self::b64url_decode($challenge), (int) $row->sign_count > 0 ? (int) $row->sign_count : null, !$user_id, true);
            $count = $server->getSignatureCounter();
        } catch (\Throwable $e) {
            return $fail;
        }

        $wpdb->update($table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'sign_count' => null === $count ? (int) $row->sign_count : (int) $count,
            'last_used_at' => gmdate('Y-m-d H:i:s'),
        ), array('id' => (int) $row->id), array('%d', '%s'), array('%d'));

        $user = get_userdata((int) $row->user_id);

        return $user ? $user : $fail;
    }

    /**
     * The clientDataJSON origin must be this site.
     *
     * The library only checks that the host ends with the RP ID; this is exact.
     *
     * @param string $client clientDataJSON.
     * @return bool
     */
    private static function origin_ok($client)
    {
        $decoded = json_decode((string) $client, true);
        if (!is_array($decoded) || empty($decoded['origin'])) {
            return false;
        }

        return in_array(strtolower(rtrim((string) $decoded['origin'], '/')), self::origins(), true);
    }

    /**
     * Assertion fields posted by assets/twofactor/passkey.js.
     *
     * @return array
     */
    public static function posted_assertion()
    {
        // phpcs:disable WordPress.Security.NonceVerification -- bound to the WebAuthn challenge.
        $out = array();
        foreach (array('id', 'clientDataJSON', 'authenticatorData', 'signature', 'userHandle') as $key) {
            $field = 'authlify_pk_' . $key;
            $out[$key] = isset($_POST[$field]) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) wp_unslash($_POST[$field])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }
        // phpcs:enable

        return $out;
    }

    /**
     * Hidden fields the passkey script fills in.
     */
    public static function assertion_fields()
    {
        foreach (array('id', 'clientDataJSON', 'authenticatorData', 'signature', 'userHandle') as $key) {
            echo '<input type="hidden" name="authlify_pk_' . esc_attr($key) . '" value="">';
        }
    }

    /**
     * Login-step fields (passkey as the second factor).
     *
     * @param \WP_User $user User.
     * @since 3.0.0
     */
    public static function render($user)
    {
        $options = self::assertion_options($user->ID);
        if (is_wp_error($options)) {
            echo '<p class="authlify-2fa__intro">' . esc_html($options->get_error_message()) . '</p>';

            return;
        }

        LoginFlow::state_set($user->ID, 'passkey_challenge', $options['challenge']);
        ?>
        <p class="authlify-2fa__intro"><?php esc_html_e('Use the passkey or security key you added to your account.', 'modify-login'); ?></p>
        <?php self::assertion_fields(); ?>
        <p class="authlify-2fa__passkey">
            <button type="button" class="button button-primary button-large authlify-passkey-verify" data-options="<?php echo esc_attr(wp_json_encode($options['options'])); ?>"><?php esc_html_e('Use my passkey', 'modify-login'); ?></button>
        </p>
        <p class="authlify-passkey-status" role="status" aria-live="polite"></p>
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
        $challenge = (string) LoginFlow::state_get($user->ID, 'passkey_challenge');
        LoginFlow::state_set($user->ID, 'passkey_challenge', '');

        $result = self::verify_assertion(self::posted_assertion(), $challenge, $user->ID);

        return $result instanceof \WP_User && (int) $result->ID === (int) $user->ID;
    }
}
