<?php
/**
 * Plugin settings.
 *
 * @package Authlify
 */

namespace Authlify;

defined('ABSPATH') || exit;

/**
 * Settings store: one array option (a network option when network-activated).
 *
 * Every key is declared in schema() with a type and default. Modules and add-ons
 * add their keys through the `authlify_settings_schema` filter, so unknown keys
 * are never stored and every value is sanitized by type.
 */
final class Settings
{
    const OPTION = 'authlify_settings';

    /**
     * Cache.
     *
     * @var array|null
     */
    private static $cache = null;

    /**
     * Setting definitions: key => array( type, default [, choices] ).
     *
     * Types: bool, int, string, slug, url, text (multi-line), choice, list (array of strings), map (array).
     *
     * @return array
     */
    public static function schema()
    {
        $schema = array(
            // Login URL.
            'login_slug' => array('slug', ''),
            'block_wp_login' => array('bool', true),
            'blocked_response' => array('choice', '404', array('404', '403', 'redirect')),
            'blocked_redirect_url' => array('url', ''),
            'pending_slug' => array('slug', ''),
            'pending_token' => array('string', ''),
            'pending_expires' => array('int', 0),

            // Brute-force protection.
            'limit_enabled' => array('bool', true),
            'limit_attempts' => array('int', 5),
            'limit_window' => array('int', 15),
            'lockout_minutes' => array('int', 15),
            'lockout_escalate' => array('bool', true),
            'limit_network' => array('bool', false),
            'user_attempts' => array('int', 10),
            'limit_user_lock' => array('bool', true),
            'ip_source' => array('choice', 'remote_addr', array('remote_addr', 'cloudflare', 'proxy')),
            'trusted_proxies' => array('text', ''),
            'ip_allowlist' => array('text', ''),
            'ip_denylist' => array('text', ''),
            // Lockouts of one address within 24 hours that add it to the block list (0 = off).
            'auto_block_lockouts' => array('int', 0),

            // CAPTCHA (inc/Captcha).
            'captcha_provider' => array('choice', 'none', array('none', 'turnstile', 'hcaptcha', 'recaptcha_v2', 'recaptcha_v3', 'altcha')),
            'captcha_site_key' => array('string', ''),
            'captcha_secret_key' => array('string', ''),
            'captcha_v3_threshold' => array('string', '0.5'),
            'captcha_forms' => array('list', array('login', 'register', 'lostpassword')),
            'captcha_mode' => array('choice', 'always', array('always', 'after_failures')),
            'captcha_after' => array('int', 2),
            'captcha_test_mode' => array('bool', false),
            'captcha_fail' => array('choice', 'open', array('open', 'closed')),
            'honeypot' => array('bool', false),

            // Two-factor and passkeys (inc/TwoFactor).
            'twofa_enabled' => array('bool', true),
            'twofa_methods' => array('list', array('totp', 'backup', 'passkey')),
            'passkey_login_button' => array('bool', true),

            // Passwords.
            'hibp_enabled' => array('bool', false),
            'hibp_roles' => array('list', array()),

            // Hardening.
            'xmlrpc' => array('choice', 'on', array('on', 'no_multicall', 'off')),
            'block_user_enumeration' => array('bool', false),
            'app_passwords' => array('choice', 'on', array('on', 'admins', 'off')),
            'generic_errors' => array('bool', false),
            'force_login' => array('bool', false),
            'force_login_exclude' => array('text', ''),

            // Redirects.
            'login_redirect_url' => array('url', ''),
            'logout_redirect_url' => array('url', ''),
            'role_redirects' => array('map', array()),

            // Activity log.
            'log_enabled' => array('bool', true),
            'log_retention_days' => array('int', 90),
            'log_anonymize_ip' => array('bool', false),
            'geo_source' => array('choice', 'headers', array('off', 'headers', 'dbip')),
            'alert_admin_lockout' => array('bool', false),
            // Email users when they sign in from a new device or address (off by default).
            'signin_notice' => array('bool', false),
            'signin_notice_roles' => array('list', array('administrator')),

            // Leak Check (inc/Diagnostics).
            'leak_check_schedule' => array('bool', true),

            // General.
            'delete_data' => array('bool', false),
            'onboarding_done' => array('bool', false),
        );

        /**
         * Filters the settings schema. Modules and add-ons register their keys here.
         *
         * @param array $schema key => array( type, default [, choices] ).
         * @since 3.0.0
         */
        return apply_filters('authlify_settings_schema', $schema);
    }

    /**
     * Default values.
     *
     * @return array
     */
    public static function defaults()
    {
        $defaults = array();
        foreach (self::schema() as $key => $def) {
            $defaults[$key] = $def[1];
        }

        return $defaults;
    }

    /**
     * Whether settings are stored network-wide.
     *
     * @return bool
     */
    public static function is_network()
    {
        if (!is_multisite()) {
            return false;
        }

        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(AUTHLIFY_BASENAME);
    }

    /**
     * Stored values (raw, without defaults).
     *
     * @return array
     */
    public static function stored()
    {
        if (self::is_network()) {
            $stored = get_site_option(self::OPTION, false);
            // First network activation: start from the main site's settings instead of defaults.
            if (false === $stored) {
                $stored = get_blog_option(get_main_site_id(), self::OPTION, array());
                if (is_array($stored) && $stored) {
                    update_site_option(self::OPTION, $stored);
                }
            }
        } else {
            $stored = get_option(self::OPTION, array());
        }

        return is_array($stored) ? $stored : array();
    }

    /**
     * All settings, with defaults and constant overrides applied.
     *
     * @return array
     */
    public static function all()
    {
        if (null === self::$cache) {
            $all = wp_parse_args(self::stored(), self::defaults());

            // Recovery overrides from wp-config.php.
            if (defined('AUTHLIFY_SLUG') && is_string(AUTHLIFY_SLUG)) {
                $forced = self::clean_slug(AUTHLIFY_SLUG);
                // A reserved or too-short value would break wp-admin or hide a real page; ignore it.
                if (preg_match('/^[a-z0-9_-]{3,64}$/', $forced) && !in_array($forced, \Authlify\Login\Router::reserved_slugs(), true)) {
                    $all['login_slug'] = $forced;
                }
            }
            if (defined('AUTHLIFY_DISABLE_HIDE') && AUTHLIFY_DISABLE_HIDE) {
                $all['login_slug'] = '';
            }

            self::$cache = $all;
        }

        return self::$cache;
    }

    /**
     * One setting.
     *
     * @param string $key     Key.
     * @param mixed  $default Fallback when the key is unknown.
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Save settings (merged with stored values, sanitized).
     *
     * @param array $values Values to change.
     * @return array The saved settings.
     */
    public static function update(array $values)
    {
        $stored = self::stored();
        $old = wp_parse_args($stored, self::defaults());
        $new = self::sanitize(array_merge($old, $values));

        // Keep keys that no loaded module declares (e.g. Authlify Pro settings
        // while Pro is deactivated), so saving never erases another module's data.
        $new = array_merge(array_diff_key($stored, self::schema()), $new);

        if (self::is_network()) {
            update_site_option(self::OPTION, $new);
        } else {
            update_option(self::OPTION, $new);
        }

        self::flush();

        /**
         * Fires after settings are saved.
         *
         * @param array $new New settings.
         * @param array $old Previous settings.
         * @since 3.0.0
         */
        do_action('authlify_settings_updated', $new, $old);

        return $new;
    }

    /**
     * Clear the in-memory cache.
     */
    public static function flush()
    {
        self::$cache = null;
    }

    /**
     * Sanitize a full settings array by schema.
     *
     * @param array $values Values.
     * @return array
     */
    public static function sanitize(array $values)
    {
        $clean = array();

        foreach (self::schema() as $key => $def) {
            $value = array_key_exists($key, $values) ? $values[$key] : $def[1];
            $clean[$key] = self::sanitize_value($value, $def);
        }

        return $clean;
    }

    /**
     * Sanitize one value by its definition.
     *
     * @param mixed $value Value.
     * @param array $def   Definition.
     * @return mixed
     */
    public static function sanitize_value($value, array $def)
    {
        switch ($def[0]) {
            case 'bool':
                return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'int':
                return max(0, (int) $value);

            case 'slug':
                return self::clean_slug($value);

            case 'url':
                return self::clean_url($value);

            case 'text':
                return sanitize_textarea_field((string) $value);

            case 'choice':
                $choices = isset($def[2]) ? $def[2] : array();

                return in_array($value, $choices, true) ? $value : $def[1];

            case 'list':
                return array_values(array_filter(array_map('sanitize_key', (array) $value)));

            case 'map':
                return is_array($value) ? self::clean_map($value) : array();

            default:
                return sanitize_text_field((string) $value);
        }
    }

    /**
     * esc_url_raw() that keeps the {username} and {user_id} redirect placeholders.
     *
     * @param mixed $value URL.
     * @return string
     */
    public static function clean_url($value)
    {
        $tokens = array('{username}' => 'authlifyusernametoken', '{user_id}' => 'authlifyuseridtoken');
        $url = esc_url_raw(str_replace(array_keys($tokens), array_values($tokens), trim((string) $value)));

        return str_replace(array_values($tokens), array_keys($tokens), $url);
    }

    /**
     * Lowercase letters, numbers, hyphens and underscores only.
     *
     * @param mixed $value Raw slug.
     * @return string
     */
    public static function clean_slug($value)
    {
        $slug = strtolower(trim((string) $value, " \t\n\r\0\x0B/"));

        return preg_replace('/[^a-z0-9_-]/', '', $slug);
    }

    /**
     * Recursively sanitize a nested array of strings.
     *
     * @param array $value Value.
     * @return array
     */
    private static function clean_map(array $value)
    {
        $clean = array();
        foreach ($value as $key => $item) {
            $key = sanitize_key($key);
            if (is_array($item)) {
                $clean[$key] = self::clean_map($item);
            } elseif (is_bool($item) || is_int($item)) {
                $clean[$key] = $item;
            } else {
                $item = (string) $item;
                $clean[$key] = preg_match('#^https?://#i', $item) || 0 === strpos($item, '/') ? self::clean_url($item) : sanitize_text_field($item);
            }
        }

        return $clean;
    }

    /**
     * Split a textarea setting into trimmed, non-empty lines.
     *
     * @param string $key Setting key.
     * @return string[]
     */
    public static function lines($key)
    {
        $lines = preg_split('/[\r\n,]+/', (string) self::get($key, ''));

        return array_values(array_filter(array_map('trim', $lines), 'strlen'));
    }
}
