<?php
/**
 * Version upgrades and the move from Modify Login 1.x/2.x.
 *
 * @package Authlify
 */

namespace Authlify\Install;

use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Runs once per version. Migrations copy data and never delete the old
 * options or table (uninstall.php removes them only when the admin asks).
 */
final class Upgrader
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 1);
    }

    /**
     * Upgrade when the stored version differs.
     */
    public static function maybe_upgrade()
    {
        if (Installer::needs_tables()) {
            Installer::create_tables();
        }

        $stored = (string) self::get('authlify_version', '');
        if (AUTHLIFY_VERSION === $stored) {
            return;
        }

        // Only one request runs the upgrade (per site when each site runs Authlify itself).
        $lock = self::per_site() ? 'authlify_upgrading_' . get_current_blog_id() : 'authlify_upgrading';
        if (get_site_transient($lock)) {
            return;
        }
        set_site_transient($lock, 1, MINUTE_IN_SECONDS);

        Installer::schedule();

        if ('' === $stored) {
            self::first_run();
        }

        /**
         * Fires after an upgrade (modules run their own migrations here).
         *
         * @param string $from Previous Authlify version ('' on first run).
         * @param string $to   New version.
         * @since 3.0.0
         */
        do_action('authlify_upgraded', $stored, AUTHLIFY_VERSION);

        self::set('authlify_version', AUTHLIFY_VERSION);
        delete_site_transient($lock);

        // This request was routed before the migration wrote the login URL;
        // route it again so the old login address works right away.
        if ('' === $stored && did_action('plugins_loaded') && class_exists('Authlify\\Login\\Router')) {
            \Authlify\Login\Router::reroute();
        }
    }

    /**
     * Whether upgrade state is kept per site: on a network where Authlify is
     * activated site by site, each site has its own settings, old Modify
     * Login options and log to migrate.
     *
     * @return bool
     */
    public static function per_site()
    {
        return is_multisite() && !Settings::is_network();
    }

    /**
     * Read an upgrade-state option (authlify_version, authlify_migrated_from…).
     *
     * @param string $name    Option.
     * @param mixed  $default Default.
     * @return mixed
     */
    public static function get($name, $default = false)
    {
        return is_multisite() && !self::per_site() ? get_site_option($name, $default) : get_option($name, $default);
    }

    /**
     * Write an upgrade-state option. On a single site it is autoloaded (it is
     * read on every request and only a few bytes long).
     *
     * @param string $name  Option.
     * @param mixed  $value Value.
     */
    public static function set($name, $value)
    {
        if (is_multisite() && !self::per_site()) {
            update_site_option($name, $value);
        } else {
            update_option($name, $value, true);
            // update_option() leaves autoload alone when the value is unchanged.
            if (function_exists('wp_set_option_autoload')) {
                wp_set_option_autoload($name, true);
            }
        }
    }

    /**
     * First run on this site: migrate Modify Login settings, or set up a fresh install.
     */
    private static function first_run()
    {
        if (Settings::stored()) {
            return;
        }

        $legacy = get_option('modify_login_settings', null);
        $was_2x = is_array($legacy) || false !== get_option('modify_login_version', false) || false !== get_option('modify_login_login_endpoint', false);
        $was_1x = !$was_2x && false !== get_option('mb_login_endpoint', false);

        if ($was_2x) {
            Settings::update(self::map_2x(is_array($legacy) ? $legacy : array()));
            self::copy_2x_logs();
            self::set('authlify_migrated_from', '2.x');
        } elseif ($was_1x) {
            Settings::update(self::map_1x());
            self::set('authlify_migrated_from', '1.x');
        } else {
            // Fresh install: nothing is hidden until the admin chooses a URL in setup.
            Settings::update(array('onboarding_done' => false));
            self::set('authlify_fresh_install', time());
        }
    }

    /**
     * Map Modify Login 2.x settings. Behaviour is preserved: the same login
     * URL, the same wp-login.php protection state and target, the same redirects.
     *
     * @param array $old modify_login_settings.
     * @return array
     */
    public static function map_2x(array $old)
    {
        // The slug the site actually served. 2.x always activated the endpoint,
        // defaulting to "setup" (2.0.0's front end fell back to "login").
        $slug = isset($old['login_endpoint']) ? (string) $old['login_endpoint'] : '';
        if ('' === $slug) {
            $slug = (string) get_option('modify_login_login_endpoint', '');
        }
        if ('' === $slug) {
            $slug = 'setup';
        }

        $clean = Settings::clean_slug($slug);
        if ($clean !== $slug) {
            self::set('authlify_slug_changed_on_upgrade', array('from' => $slug, 'to' => $clean));
        }
        if ('' === $clean) {
            $clean = 'setup';
        }

        $block = !empty($old['enable_redirect']);
        $redirect = isset($old['redirect_url']) ? (string) $old['redirect_url'] : '';

        $values = array(
            'login_slug' => $clean,
            'block_wp_login' => $block,
            // 2.x redirected blocked visitors to its redirect URL or the homepage.
            'blocked_response' => 'redirect',
            'blocked_redirect_url' => $redirect,
            'login_redirect_url' => isset($old['login_redirect_url']) ? (string) $old['login_redirect_url'] : '',
            'logout_redirect_url' => isset($old['logout_redirect_url']) ? (string) $old['logout_redirect_url'] : '',
            'log_enabled' => !empty($old['enable_tracking']) && 'no' !== $old['enable_tracking'],
            'geo_source' => 'headers',
            // New protection ships off on upgraded sites; the dashboard recommends it.
            'limit_enabled' => false,
            'honeypot' => false,
            'twofa_enabled' => false,
            'passkey_login_button' => false,
            'leak_check_schedule' => false,
            'onboarding_done' => true,
        );

        if (isset($old['enable_recaptcha']) && 'yes' === $old['enable_recaptcha'] && !empty($old['recaptcha_site_key']) && !empty($old['recaptcha_secret_key'])) {
            $values['captcha_provider'] = 'recaptcha_v2';
            $values['captcha_site_key'] = (string) $old['recaptcha_site_key'];
            $values['captcha_secret_key'] = (string) $old['recaptcha_secret_key'];
            $values['captcha_forms'] = array('login');
        }

        return $values;
    }

    /**
     * Map Modify Login 1.x settings (sites that never ran 2.x).
     *
     * @return array
     */
    private static function map_1x()
    {
        $slug = Settings::clean_slug((string) get_option('mb_login_endpoint', 'setup'));

        return array(
            'login_slug' => '' !== $slug ? $slug : 'setup',
            // 1.x always blocked wp-login.php.
            'block_wp_login' => true,
            'blocked_response' => 'redirect',
            'blocked_redirect_url' => (string) get_option('mb_redirect_url', ''),
            // 1.x always sent people to the homepage after logging out.
            'logout_redirect_url' => home_url('/'),
            'limit_enabled' => false,
            'twofa_enabled' => false,
            'passkey_login_button' => false,
            'leak_check_schedule' => false,
            'onboarding_done' => true,
        );
    }

    /**
     * Copy the 2.x login log into the new table (the old table is kept).
     */
    private static function copy_2x_logs()
    {
        global $wpdb;

        $old = $wpdb->prefix . 'modify_login_logs';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old)) !== $old) { // phpcs:ignore
            return;
        }

        $new = $wpdb->base_prefix . 'authlify_log';
        $offset = (int) round((float) get_option('gmt_offset') * HOUR_IN_SECONDS);
        $blog_id = get_current_blog_id();

        // 2.x stored site-local times and full country names; keep the newest 50,000 rows.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$new} (blog_id, created_at, event, user_id, username, ip, country, user_agent, context)
             SELECT %d, DATE_SUB(created_at, INTERVAL %d SECOND),
                    IF(status = 'success', 'login_success', 'login_failed'),
                    user_id, LEFT(IFNULL(attempted_username, ''), 191), LEFT(ip_address, 45), '',
                    LEFT(user_agent, 255),
                    IF(IFNULL(country, '') = '', NULL, CONCAT('{\"country_name\":', JSON_QUOTE(country), ',\"imported\":true}'))
             FROM {$old} ORDER BY id DESC LIMIT 50000",
            $blog_id,
            $offset
        ));
        // phpcs:enable

        if ($wpdb->last_error) {
            // JSON_QUOTE needs MySQL 5.7+/MariaDB 10.2+; retry without the country name.
            $wpdb->query($wpdb->prepare( // phpcs:ignore
                "INSERT INTO {$new} (blog_id, created_at, event, user_id, username, ip, country, user_agent)
                 SELECT %d, DATE_SUB(created_at, INTERVAL %d SECOND), IF(status = 'success', 'login_success', 'login_failed'),
                        user_id, LEFT(IFNULL(attempted_username, ''), 191), LEFT(ip_address, 45), '', LEFT(user_agent, 255)
                 FROM {$old} ORDER BY id DESC LIMIT 50000",
                $blog_id,
                $offset
            ));
        }
    }
}
