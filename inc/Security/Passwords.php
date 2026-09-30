<?php
/**
 * Breached-password check.
 *
 * @package Authlify
 */

namespace Authlify\Security;

use Authlify\Admin\UI;
use Authlify\Log\Log;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Refuses passwords that appear in known data breaches, using the Have I Been
 * Pwned range API with k-anonymity: only the first 5 characters of the
 * password's SHA-1 hash are sent, and the answer (every breached hash that
 * starts with them, padded with decoys) is compared on this server.
 *
 * Opt-in (hibp_enabled), limited to chosen roles (hibp_roles, empty = all).
 * Range answers are cached for a day. On any error the check is skipped
 * (fail open) so a slow API never stops someone setting a password.
 *
 * @since 3.0.0
 */
final class Passwords
{
    /**
     * Range API.
     */
    const API = 'https://api.pwnedpasswords.com/range/';

    /**
     * Option holding recent breach-check results (not autoloaded).
     */
    const CACHE_OPTION = 'authlify_hibp_cache';

    /**
     * Most results kept.
     */
    const CACHE_MAX = 500;

    /**
     * Wire up.
     */
    public static function init()
    {
        add_filter('authlify_log_events', array(__CLASS__, 'log_events'));

        if (is_admin()) {
            add_filter('authlify_protection_tabs', array(__CLASS__, 'tabs'), 20);
            add_filter('authlify_validate_settings', array(__CLASS__, 'validate'), 10, 3);
        }

        if (!Settings::get('hibp_enabled', false)) {
            return;
        }

        add_action('user_profile_update_errors', array(__CLASS__, 'check_profile'), 10, 3);
        add_action('validate_password_reset', array(__CLASS__, 'check_reset'), 10, 2);
        add_filter('registration_errors', array(__CLASS__, 'check_register'), 30, 3);
        add_filter('woocommerce_process_registration_errors', array(__CLASS__, 'check_woo_register'), 20, 4);
        add_action('woocommerce_save_account_details_errors', array(__CLASS__, 'check_woo_account'), 10, 2);
        add_action('woocommerce_after_checkout_validation', array(__CLASS__, 'check_woo_checkout'), 20, 2);
    }

    /**
     * Profile screens: own profile, editing another user, adding a user.
     *
     * @param \WP_Error $errors Errors.
     * @param bool      $update Updating an existing user.
     * @param \stdClass $user   User data being saved.
     */
    public static function check_profile($errors, $update, $user)
    {
        if (!isset($user->user_pass) || '' === (string) $user->user_pass || $errors->has_errors()) {
            return;
        }

        $roles = array();
        if ($update && !empty($user->ID)) {
            $existing = get_userdata((int) $user->ID);
            $roles = $existing ? (array) $existing->roles : array();
        }
        if (!empty($user->role)) {
            $roles[] = (string) $user->role;
        }
        if (!$roles) {
            $roles[] = (string) get_option('default_role', 'subscriber');
        }

        self::apply($errors, wp_unslash((string) $user->user_pass), $roles, isset($user->ID) ? (int) $user->ID : 0, 'pass1');
    }

    /**
     * Password reset (core wp-login.php and WooCommerce's reset form).
     *
     * @param \WP_Error         $errors Errors.
     * @param \WP_User|\WP_Error $user   User.
     */
    public static function check_reset($errors, $user)
    {
        if (!$user instanceof \WP_User || $errors->has_errors()) {
            return;
        }

        $password = self::posted(array('pass1', 'password_1'));
        if ('' !== $password) {
            self::apply($errors, $password, (array) $user->roles, $user->ID, 'password_reset_mismatch');
        }
    }

    /**
     * Core registration, when a plugin adds a password field.
     *
     * @param \WP_Error $errors Errors.
     * @param string    $login  Login.
     * @param string    $email  Email.
     * @return \WP_Error
     */
    public static function check_register($errors, $login = '', $email = '')
    {
        $password = self::posted(array('user_pass', 'pass1', 'password'));
        if ('' !== $password && $errors instanceof \WP_Error) {
            self::apply($errors, $password, array((string) get_option('default_role', 'subscriber')), 0, 'pass1');
        }

        return $errors;
    }

    /**
     * WooCommerce registration (only when customers choose their own password).
     *
     * @param \WP_Error $errors   Errors.
     * @param string    $username Username.
     * @param string    $password Password.
     * @param string    $email    Email.
     * @return \WP_Error
     */
    public static function check_woo_register($errors, $username = '', $password = '', $email = '')
    {
        if ('' !== (string) $password && $errors instanceof \WP_Error) {
            self::apply($errors, (string) $password, array('customer'), 0, 'password');
        }

        return $errors;
    }

    /**
     * WooCommerce "Account details" password change.
     *
     * @param \WP_Error $errors Errors.
     * @param \stdClass $user   User data.
     */
    public static function check_woo_account($errors, $user)
    {
        $password = self::posted(array('password_1'));
        if ('' === $password || !$errors instanceof \WP_Error) {
            return;
        }

        $existing = get_userdata(isset($user->ID) ? (int) $user->ID : get_current_user_id());
        self::apply($errors, $password, $existing ? (array) $existing->roles : array('customer'), $existing ? $existing->ID : 0, 'password_1');
    }

    /**
     * WooCommerce checkout, when a new account is created with a chosen password.
     *
     * @param array     $data   Posted data.
     * @param \WP_Error $errors Errors.
     */
    public static function check_woo_checkout($data, $errors)
    {
        if (is_user_logged_in() || empty($data['createaccount']) || empty($data['account_password']) || !$errors instanceof \WP_Error) {
            return;
        }

        self::apply($errors, (string) $data['account_password'], array('customer'), 0, 'account_password');
    }

    /**
     * Add an error when the password is breached and the roles are covered.
     *
     * @param \WP_Error $errors   Errors.
     * @param string    $password Password.
     * @param string[]  $roles    Roles of the account.
     * @param int       $user_id  User ID (0 for new accounts).
     * @param string    $field    Field the error belongs to.
     */
    private static function apply($errors, $password, array $roles, $user_id, $field)
    {
        if (!self::covers($roles)) {
            return;
        }

        $count = self::breach_count($password);
        if ($count < 1) {
            return;
        }

        $errors->add('authlify_breached_password', self::message($count, did_action('login_init') || is_admin()), array('form-field' => $field));

        $user = $user_id ? get_userdata($user_id) : false;
        Log::add('password_breached', array(
            'user_id' => (int) $user_id,
            'username' => $user ? $user->user_login : '',
            'context' => array('seen' => $count),
        ));
    }

    /**
     * Whether the check applies to an account with these roles.
     *
     * @param string[] $roles Roles.
     * @return bool
     */
    public static function covers(array $roles)
    {
        $chosen = (array) Settings::get('hibp_roles', array());

        return !$chosen || (bool) array_intersect($roles, $chosen);
    }

    /**
     * Refusal message.
     *
     * @param int $count Times seen in breaches.
     * @return string
     */
    public static function message($count, $prefix = true)
    {
        $message = sprintf(
            /* translators: %s: number of times */
            _n(
                '<strong>Error:</strong> This password has appeared %s time in known data breaches, so attackers try it early. Please choose a different password.',
                '<strong>Error:</strong> This password has appeared %s times in known data breaches, so attackers try it early. Please choose a different password.',
                $count,
                'modify-login'
            ),
            number_format_i18n($count)
        );

        // WooCommerce adds its own "Error:" prefix.
        return $prefix ? $message : preg_replace('#^<strong>[^<]*</strong>\s*#', '', $message);
    }

    /**
     * How often a password appears in breaches (0 when not found or on error).
     *
     * @param string $password Password.
     * @return int
     */
    public static function breach_count($password)
    {
        if ('' === $password) {
            return 0;
        }

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $cached = self::cache_get($hash);
        if (null !== $cached) {
            return $cached;
        }

        $range = self::range($prefix);
        if (null === $range) {
            return 0;
        }

        $count = preg_match('/^' . $suffix . ':(\d+)/m', $range, $match) ? (int) $match[1] : 0;
        self::cache_set($hash, $count);

        return $count;
    }

    /**
     * Cached result for a password hash, or null.
     *
     * Results (not whole ranges) are kept for a day in one small option that
     * is not autoloaded, capped at CACHE_MAX entries. Keys are an HMAC of the
     * hash, so the cache never holds a crackable password hash.
     *
     * @param string $hash SHA-1 (uppercase hex).
     * @return int|null
     */
    private static function cache_get($hash)
    {
        $cache = get_option(self::CACHE_OPTION, array());
        $key = self::cache_key($hash);

        return is_array($cache) && isset($cache[$key][0], $cache[$key][1]) && (int) $cache[$key][1] > time() ? (int) $cache[$key][0] : null;
    }

    /**
     * Remember a result.
     *
     * @param string $hash  SHA-1 (uppercase hex).
     * @param int    $count Breach count.
     */
    private static function cache_set($hash, $count)
    {
        $cache = get_option(self::CACHE_OPTION, array());
        $cache = is_array($cache) ? $cache : array();
        $now = time();

        foreach ($cache as $key => $item) {
            if (!is_array($item) || !isset($item[1]) || (int) $item[1] <= $now) {
                unset($cache[$key]);
            }
        }

        $cache[self::cache_key($hash)] = array((int) $count, $now + DAY_IN_SECONDS);
        if (count($cache) > self::CACHE_MAX) {
            $cache = array_slice($cache, -self::CACHE_MAX, null, true);
        }

        update_option(self::CACHE_OPTION, $cache, false);
    }

    /**
     * Cache key for a hash.
     *
     * @param string $hash SHA-1.
     * @return string
     */
    private static function cache_key($hash)
    {
        return substr(hash_hmac('sha256', 'authlify-hibp|' . $hash, wp_salt('auth')), 0, 16);
    }

    /**
     * Breached hash suffixes for a prefix ("SUFFIX:COUNT" lines). Not cached:
     * a range is ~70 KB, and breach_count() caches the one result it needs.
     *
     * @param string $prefix First 5 hex characters of the SHA-1 hash.
     * @return string|null Null when the API could not be reached.
     */
    private static function range($prefix)
    {
        $response = wp_remote_get(self::API . $prefix, array(
            'timeout' => 3,
            'headers' => array(
                'Add-Padding' => 'true',
                'User-Agent' => 'Authlify/' . AUTHLIFY_VERSION . ' (WordPress)',
            ),
        ));

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        // Drop the padding decoys (count 0) before caching.
        $lines = array();
        foreach (preg_split('/\r?\n/', (string) wp_remote_retrieve_body($response)) as $line) {
            if (preg_match('/^([0-9A-F]{35}):(\d+)$/', trim($line), $m) && (int) $m[2] > 0) {
                $lines[] = $m[1] . ':' . $m[2];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * First non-empty posted value among fields (unslashed, not sanitized: it is a password).
     *
     * @param string[] $fields Field names.
     * @return string
     */
    private static function posted(array $fields)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- read-only check inside forms that verify their own nonces; passwords are not sanitized.
        foreach ($fields as $field) {
            if (isset($_POST[$field]) && is_string($_POST[$field]) && '' !== $_POST[$field]) {
                return (string) wp_unslash($_POST[$field]);
            }
        }
        // phpcs:enable

        return '';
    }

    /**
     * Log label.
     *
     * @param array $events Events.
     * @return array
     */
    public static function log_events($events)
    {
        $events['password_breached'] = __('Breached password refused', 'modify-login');

        return $events;
    }

    /**
     * Add the Passwords tab to the Protection page.
     *
     * @param array $tabs Tabs.
     * @return array
     */
    public static function tabs($tabs)
    {
        $tabs['passwords'] = array(__('Passwords', 'modify-login'), array(__CLASS__, 'render'));

        return $tabs;
    }

    /**
     * Render the Passwords tab.
     */
    public static function render()
    {
        UI::form_start('protection', 'passwords');

        UI::panel_start(__('Breached passwords', 'modify-login'), __('Stop people choosing a password that is already in attackers\' word lists.', 'modify-login'));

        UI::toggle_row(
            'hibp_enabled',
            __('Refuse passwords found in known data breaches', 'modify-login'),
            __('Checked whenever a password is set: profiles, resets, registration and WooCommerce accounts.', 'modify-login'),
            __('Breached-password check', 'modify-login')
        );

        $chosen = (array) Settings::get('hibp_roles', array());
        UI::own('hibp_roles');
        UI::field_start(__('Roles', 'modify-login'), __('Leave all unticked to check everyone.', 'modify-login'), '', 'authlify[hibp_enabled]=1');
        echo '<fieldset class="authlify-checklist authlify-checklist--columns"><legend class="screen-reader-text">' . esc_html__('Roles', 'modify-login') . '</legend>';
        foreach (wp_roles()->get_names() as $role => $name) {
            printf(
                '<label><input type="checkbox" name="authlify[hibp_roles][]" value="%1$s" %2$s> <span>%3$s</span></label>',
                esc_attr($role),
                checked(in_array($role, $chosen, true), true, false),
                esc_html(translate_user_role($name))
            );
        }
        echo '</fieldset>';
        UI::field_end();

        ?>
        <details class="authlify-details">
            <summary><?php esc_html_e('How the check keeps passwords private', 'modify-login'); ?></summary>
            <div>
                <p><?php echo wp_kses(__('Authlify uses the free Have I Been Pwned service. Only the first 5 characters of the password\'s SHA-1 hash are sent to <code>api.pwnedpasswords.com</code> (for example <code>5BAA6</code>); the password and the rest of its hash never leave your server.', 'modify-login'), UI::inline_html()); ?></p>
                <p><?php esc_html_e('Answers are cached for a day. If the service does not answer within 3 seconds, the password is accepted.', 'modify-login'); ?></p>
            </div>
        </details>
        <?php

        UI::panel_end();

        UI::form_end();
    }

    /**
     * Keep only real roles.
     *
     * @param array|\WP_Error $values Values.
     * @param string          $page   Page.
     * @param string          $tab    Tab.
     * @return array|\WP_Error
     */
    public static function validate($values, $page, $tab = '')
    {
        if (is_wp_error($values) || 'protection' !== $page || 'passwords' !== $tab || !isset($values['hibp_roles'])) {
            return $values;
        }

        $values['hibp_roles'] = array_values(array_intersect(array_map('sanitize_key', (array) $values['hibp_roles']), array_keys(wp_roles()->get_names())));

        return $values;
    }
}
