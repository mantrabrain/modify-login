<?php
/**
 * Two-factor login and passkeys.
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

use Authlify\Admin\Menu;
use Authlify\Install\Installer;
use Authlify\Log\Log;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Module entry point: the method registry, per-user state and the wiring of
 * the login flow, profile screen, REST routes and admin page.
 *
 * Users opt in from their profile. Built-in methods are an authenticator app
 * (TOTP), one-time backup codes and passkeys (PHP 8.0+). Authlify Pro adds
 * more methods, role enforcement and trusted devices through these hooks:
 *
 * - `authlify_twofactor_methods` (filter): register login methods.
 * - `authlify_twofactor_required` (filter): whether a user must use 2FA.
 * - `authlify_twofactor_trusted_device` (filter): skip the second step.
 * - `authlify_twofactor_verified` (action): the second step succeeded.
 *
 * Emergency switch: define('AUTHLIFY_DISABLE_2FA', true) in wp-config.php
 * turns off the second step for everyone without deleting anything.
 *
 * Methods that cannot run right now never switch two-factor off silently:
 * a user whose method is Authlify Pro's email code (Pro inactive) or a passkey
 * (server without passkey support) keeps the second step, which then offers
 * backup codes and an emailed one-time sign-in link, and Site Health warns.
 *
 * @since 3.0.0
 */
final class TwoFactor
{
    /**
     * User meta flag set while a user has at least one primary method (for fast counting).
     */
    const META_FLAG = 'authlify_2fa';

    /**
     * Add-on methods free knows by their stored flag, so that users keep
     * two-factor login when the add-on is inactive: key => user meta.
     */
    const ADDON_METHODS = array('email' => 'authlify_pro_email_2fa');

    /**
     * Coverage cache (transient).
     */
    const COVERAGE_CACHE = 'authlify_2fa_coverage';

    /**
     * Wire up.
     *
     * @since 3.0.0
     */
    public static function init()
    {
        add_filter('authlify_log_events', array(__CLASS__, 'log_events'));
        add_filter('authlify_install_tables', array(Passkeys::class, 'install_table'), 10, 3);
        add_action('authlify_upgraded', array(__CLASS__, 'maybe_create_table'));
        add_action('authlify_upgraded', array(__CLASS__, 'note_plain_secrets'));
        add_action('set_user_role', array(__CLASS__, 'flush_coverage'));
        add_action('deleted_user', array(__CLASS__, 'flush_coverage'));
        add_action('user_register', array(__CLASS__, 'flush_coverage'));
        add_action('activated_plugin', array(__CLASS__, 'flush_coverage'));
        add_action('deactivated_plugin', array(__CLASS__, 'flush_coverage'));
        add_action('authlify_reset_two_factor', array(__CLASS__, 'reset'));
        add_action('deleted_user', array(Passkeys::class, 'remove'));
        add_action('wpmu_delete_user', array(Passkeys::class, 'remove'));

        LoginFlow::init();
        Recovery::init();
        add_action('rest_api_init', array(Rest::class, 'register'));

        if (is_admin()) {
            Profile::init();
            AdminPage::init();
            add_filter('authlify_dashboard_checks', array(__CLASS__, 'dashboard_check'));
            add_filter('site_status_tests', array(__CLASS__, 'site_health_tests'));
        }
    }

    /**
     * Log event labels.
     *
     * @param array $events Events.
     * @return array
     */
    public static function log_events($events)
    {
        return array_merge($events, array(
            'twofa_enabled' => __('Two-factor turned on', 'modify-login'),
            'twofa_disabled' => __('Two-factor turned off', 'modify-login'),
            'twofa_failed' => __('Wrong two-factor code', 'modify-login'),
            'twofa_verified' => __('Two-factor verified', 'modify-login'),
            'passkey_added' => __('Passkey added', 'modify-login'),
            'passkey_removed' => __('Passkey removed', 'modify-login'),
            'passkey_login' => __('Signed in with a passkey', 'modify-login'),
            'backup_code_used' => __('Backup code used', 'modify-login'),
            'passkey_failed' => __('Passkey could not be added', 'modify-login'),
        ));
    }

    /**
     * Existing installs get the passkeys table on upgrade.
     */
    public static function maybe_create_table()
    {
        Passkeys::forget_table();
        if (!Passkeys::table_exists()) {
            Installer::create_tables();
            Passkeys::forget_table();
        }
    }

    /**
     * Secrets stored without encryption before this version ("p1:") keep
     * working: record that this site wrote them (see Crypto::decrypt()).
     *
     * @since 3.0.0
     */
    public static function note_plain_secrets()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off check on upgrade.
        $plain = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s) AND meta_value LIKE %s", Totp::META_SECRET, Totp::META_PENDING, '%p1:%'));
        if (!$plain) {
            $settings = get_option('authlify_settings');
            $plain = is_array($settings) && false !== strpos((string) wp_json_encode($settings), '"p1:');
        }

        if ($plain) {
            update_site_option(Crypto::PLAIN_FLAG, 1);
        }
    }

    /**
     * Whether the admin (or wp-config.php) turned two-factor off.
     *
     * @return bool
     * @since 3.0.0
     */
    public static function disabled()
    {
        return (defined('AUTHLIFY_DISABLE_2FA') && AUTHLIFY_DISABLE_2FA) || !Settings::get('twofa_enabled', true);
    }

    /**
     * Another active plugin that handles two-factor login, or ''.
     *
     * @return string Plugin name.
     * @since 3.0.0
     */
    public static function other_provider()
    {
        $name = '';
        if (class_exists('Two_Factor_Core')) {
            $name = 'Two Factor';
        } elseif (class_exists('WP2FA\WP2FA')) {
            $name = 'WP 2FA';
        } elseif (defined('WORDFENCE_LS_VERSION') || class_exists('WordfenceLS\Controller_WordfenceLS')) {
            $name = 'Wordfence Login Security';
        }

        /**
         * Filters which other plugin handles two-factor login ('' for none).
         * While one does, Authlify does not intercept logins or show the passkey button.
         *
         * @param string $name Plugin name.
         * @since 3.0.0
         */
        return (string) apply_filters('authlify_twofactor_other_provider', $name);
    }

    /**
     * Whether our login flow runs.
     *
     * @return bool
     * @since 3.0.0
     */
    public static function runs()
    {
        return !self::disabled() && '' === self::other_provider();
    }

    /**
     * Built-in methods the admin offers (and this server supports).
     *
     * @return string[]
     * @since 3.0.0
     */
    public static function offered()
    {
        $offered = array_intersect(array('totp', 'backup', 'passkey'), (array) Settings::get('twofa_methods', array()));
        if (!Passkeys::supported()) {
            $offered = array_diff($offered, array('passkey'));
        }

        return array_values($offered);
    }

    /**
     * Whether the "Sign in with a passkey" button shows on the login form.
     *
     * @return bool
     * @since 3.0.0
     */
    public static function passkey_login_enabled()
    {
        // Nothing changes on the login form until someone has added a passkey.
        return self::runs() && in_array('passkey', self::offered(), true) && (bool) Settings::get('passkey_login_button', true) && Passkeys::any();
    }

    /**
     * Method registry.
     *
     * @return array key => array( label, switch, primary, configured, render, verify, reset, submit, order ).
     * @since 3.0.0
     */
    public static function methods()
    {
        $methods = array(
            'totp' => array(
                'label' => __('Authenticator app', 'modify-login'),
                'switch' => __('Use your authenticator app instead', 'modify-login'),
                'primary' => true,
                'configured' => array(Totp::class, 'is_configured'),
                'render' => array(Totp::class, 'render'),
                'verify' => array(Totp::class, 'verify_request'),
                'reset' => array(Totp::class, 'remove'),
                'submit' => true,
                'order' => 10,
            ),
            'backup' => array(
                'label' => __('Backup codes', 'modify-login'),
                'switch' => __('Use a backup code instead', 'modify-login'),
                'primary' => false,
                'configured' => array(BackupCodes::class, 'is_configured'),
                'render' => array(BackupCodes::class, 'render'),
                'verify' => array(BackupCodes::class, 'verify_request'),
                'reset' => array(BackupCodes::class, 'remove'),
                'submit' => true,
                'order' => 90,
            ),
        );

        if (Passkeys::supported()) {
            $methods['passkey'] = array(
                'label' => __('Passkeys', 'modify-login'),
                'switch' => __('Use a passkey instead', 'modify-login'),
                'primary' => true,
                'configured' => array(Passkeys::class, 'is_configured'),
                'render' => array(Passkeys::class, 'render'),
                'verify' => array(Passkeys::class, 'verify_request'),
                'reset' => array(Passkeys::class, 'remove'),
                'submit' => false,
                'order' => 20,
            );
        }

        /**
         * Filters the two-factor methods. Authlify Pro registers email codes here.
         *
         * Each method is key => array(
         *     'label'      => string   Name shown in lists.
         *     'switch'     => string   Link text on the login step, e.g. "Use a backup code instead".
         *     'primary'    => bool     Whether having it means the user has 2FA (backup codes do not).
         *     'configured' => callable( int $user_id ): bool.
         *     'render'     => callable( \WP_User $user ): void  Prints the fields inside the step form.
         *     'verify'     => callable( \WP_User $user ): bool|\WP_Error  Reads $_POST.
         *     'begin'      => callable( \WP_User $user ): void  Optional. Runs when the step is shown (send an email code).
         *     'reset'      => callable( int $user_id ): void  Removes the method.
         *     'submit'     => bool     Whether the step shows a Verify button (false for passkeys).
         *     'order'      => int      Lower is offered first.
         * ).
         *
         * Methods can keep per-login state with LoginFlow::state_set() and state_get().
         * Call TwoFactor::sync_flag( $user_id ) after a user configures or removes a method.
         *
         * @param array $methods Methods.
         * @since 3.0.0
         */
        $methods = (array) apply_filters('authlify_twofactor_methods', $methods);

        // A method a user set up must not vanish because it cannot run right
        // now (that would sign them in with the password alone).
        if (!isset($methods['passkey'])) {
            $methods['passkey'] = self::unavailable_method(
                __('Passkeys', 'modify-login'),
                array(Passkeys::class, 'is_unavailable_for'),
                array(Passkeys::class, 'remove'),
                __('Passkeys cannot be checked on this server right now.', 'modify-login')
            );
        }
        foreach (self::ADDON_METHODS as $key => $meta) {
            if (!isset($methods[$key])) {
                $methods[$key] = self::unavailable_method(
                    __('Email code', 'modify-login'),
                    function ($user_id) use ($meta) {
                        return (bool) get_user_meta((int) $user_id, $meta, true);
                    },
                    function ($user_id) use ($meta) {
                        delete_user_meta((int) $user_id, $meta);
                    },
                    __('Email codes are not available on this site right now.', 'modify-login')
                );
            }
        }

        uasort($methods, function ($a, $b) {
            return (isset($a['order']) ? (int) $a['order'] : 50) <=> (isset($b['order']) ? (int) $b['order'] : 50);
        });

        return $methods;
    }

    /**
     * A stand-in for a method a user set up that cannot run right now. It
     * keeps two-factor login on, and its step offers the emailed one-time
     * sign-in link (only after the right password) instead of a code.
     *
     * @param string   $label      Label.
     * @param callable $configured configured( int $user_id ): bool.
     * @param callable $reset      reset( int $user_id ).
     * @param string   $why        Why it cannot run (one sentence).
     * @return array Method definition.
     */
    private static function unavailable_method($label, $configured, $reset, $why)
    {
        return array(
            'label' => $label,
            'switch' => __('Email me a sign-in link instead', 'modify-login'),
            'primary' => true,
            'unavailable' => true,
            'configured' => $configured,
            'render' => function ($user) use ($why) {
                self::render_unavailable($why);
            },
            'verify' => function ($user) {
                return new \WP_Error('authlify_2fa_failed', __('<strong>Error:</strong> This method is not available right now. Use another method, or email yourself a sign-in link.', 'modify-login'));
            },
            'reset' => $reset,
            'submit' => false,
            'order' => 95,
        );
    }

    /**
     * Step fields for a method that cannot run.
     *
     * @param string $why Why.
     */
    private static function render_unavailable($why)
    {
        ?>
        <p class="authlify-2fa__intro"><?php echo esc_html($why); ?>
            <?php if (LoginFlow::recovery_offered()) : ?>
                <?php esc_html_e('We can email you a link that signs you in once, so you can check your security settings.', 'modify-login'); ?>
            <?php else : ?>
                <?php esc_html_e('Use another method, or ask the site admin to reset your two-factor login.', 'modify-login'); ?>
            <?php endif; ?>
        </p>
        <?php if (LoginFlow::recovery_offered()) : ?>
            <p class="authlify-2fa__passkey">
                <button type="submit" class="button button-primary button-large" name="authlify_recover" value="1" formnovalidate><?php esc_html_e('Email me a sign-in link', 'modify-login'); ?></button>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Labels of the user's methods that cannot run right now.
     *
     * @param int $user_id User ID.
     * @return string[]
     * @since 3.0.0
     */
    public static function unavailable_for($user_id)
    {
        $all = self::methods();
        $out = array();
        foreach (self::user_methods($user_id) as $key) {
            if (!empty($all[$key]['unavailable'])) {
                $out[] = (string) $all[$key]['label'];
            }
        }

        return $out;
    }

    /**
     * Methods a user has configured, in login order.
     *
     * Methods configured before the admin stopped offering them keep working,
     * so turning a method off never silently weakens an account.
     *
     * @param int $user_id User ID.
     * @return string[]
     * @since 3.0.0
     */
    public static function user_methods($user_id)
    {
        $out = array();
        foreach (self::methods() as $key => $method) {
            if (!empty($method['configured']) && is_callable($method['configured']) && call_user_func($method['configured'], (int) $user_id)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Whether the user has two-factor login (at least one primary method).
     *
     * @param int $user_id User ID.
     * @return bool
     * @since 3.0.0
     */
    public static function is_active_for($user_id)
    {
        $methods = self::methods();
        foreach (self::user_methods($user_id) as $key) {
            if (!empty($methods[$key]['primary'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user must use two-factor login (free: never; Pro enforces by role).
     *
     * @param \WP_User $user User.
     * @return bool
     * @since 3.0.0
     */
    public static function is_required($user)
    {
        /**
         * Filters whether a user is required to use two-factor login.
         *
         * When true, the user cannot remove their last method from the profile.
         * Authlify Pro uses it for role enforcement and its setup prompt.
         *
         * @param bool     $required Required.
         * @param \WP_User $user     User.
         * @since 3.0.0
         */
        return (bool) apply_filters('authlify_twofactor_required', false, $user);
    }

    /**
     * Where a user manages their own two-factor methods.
     *
     * @param \WP_User $user User.
     * @return string
     * @since 3.0.0
     */
    public static function settings_url($user)
    {
        /**
         * Filters where a user manages their two-factor methods (Authlify Pro
         * points customers to the Security page in My Account).
         *
         * @param string   $url  URL (default: the profile screen).
         * @param \WP_User $user User.
         * @since 3.0.0
         */
        return (string) apply_filters('authlify_twofactor_settings_url', admin_url('profile.php') . '#authlify-two-factor', $user);
    }

    /**
     * Keep the user-meta flag in step with the user's methods.
     *
     * @param int $user_id User ID.
     * @since 3.0.0
     */
    public static function sync_flag($user_id)
    {
        $had = (bool) get_user_meta((int) $user_id, self::META_FLAG, true);
        $has = self::is_active_for($user_id);

        if ($has) {
            update_user_meta((int) $user_id, self::META_FLAG, 1);
        } else {
            delete_user_meta((int) $user_id, self::META_FLAG);
        }

        if ($had !== $has) {
            self::flush_coverage();
        }
    }

    /**
     * Forget the cached coverage counts.
     *
     * @since 3.0.0
     */
    public static function flush_coverage()
    {
        delete_transient(self::COVERAGE_CACHE);
        delete_transient('authlify_2fa_problems');
    }

    /**
     * Log a 2FA change.
     *
     * @param int    $user_id User ID.
     * @param string $event   Event.
     * @param array  $context Context.
     * @since 3.0.0
     */
    public static function log_change($user_id, $event, array $context = array())
    {
        $user = get_userdata($user_id);
        if (get_current_user_id() && get_current_user_id() !== (int) $user_id) {
            $context['by'] = wp_get_current_user()->user_login;
        }

        Log::add($event, array('user_id' => (int) $user_id, 'username' => $user ? $user->user_login : '', 'context' => $context));
    }

    /**
     * Remove every method for a user (profile reset, admin reset, `wp authlify reset_2fa`).
     *
     * @param int $user_id User ID.
     * @since 3.0.0
     */
    public static function reset($user_id)
    {
        $user_id = (int) $user_id;
        $had = self::is_active_for($user_id);

        foreach (self::methods() as $method) {
            if (!empty($method['reset']) && is_callable($method['reset'])) {
                call_user_func($method['reset'], $user_id);
            }
        }

        // Built-ins are removed even when a filter dropped them from the registry.
        Totp::remove($user_id);
        BackupCodes::remove($user_id);
        Passkeys::remove($user_id);
        LoginFlow::clear($user_id);
        Recovery::forget($user_id);
        foreach (self::ADDON_METHODS as $meta) {
            delete_user_meta($user_id, $meta);
        }
        delete_user_meta($user_id, self::META_FLAG);
        self::flush_coverage();

        if ($had) {
            self::log_change($user_id, 'twofa_disabled', array('reset' => true));
        }
    }

    /**
     * Number of users with two-factor login, per role and in total.
     *
     * @return array role => array( total, with ), plus '_all'.
     * @since 3.0.0
     */
    public static function coverage()
    {
        // Counting runs one query per role: cached for ten minutes, and
        // dropped whenever someone turns two-factor on or off or changes role.
        $cached = get_transient(self::COVERAGE_CACHE);
        if (is_array($cached)) {
            return $cached;
        }

        $counts = count_users();
        $roles = wp_roles()->get_names();
        $out = array();

        foreach ($counts['avail_roles'] as $role => $total) {
            if ('none' === $role || !$total) {
                continue;
            }

            $query = new \WP_User_Query(array(
                'role' => $role,
                'meta_key' => self::META_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery
                'meta_compare' => 'EXISTS',
                'fields' => 'ID',
                'number' => 1,
                'count_total' => true,
            ));

            $out[$role] = array(
                'label' => isset($roles[$role]) ? translate_user_role($roles[$role]) : $role,
                'total' => (int) $total,
                'with' => (int) $query->get_total(),
            );
        }

        set_transient(self::COVERAGE_CACHE, $out, 10 * MINUTE_IN_SECONDS);

        return $out;
    }

    /**
     * Dashboard checklist item.
     *
     * @param array $checks Checks.
     * @return array
     */
    public static function dashboard_check($checks)
    {
        if ('' !== self::other_provider()) {
            return $checks;
        }

        $coverage = self::coverage();
        $admins = isset($coverage['administrator']) ? $coverage['administrator'] : array('total' => 0, 'with' => 0);
        $done = !self::disabled() && $admins['total'] > 0 && $admins['with'] >= $admins['total'];

        $problems = self::cached_problems();
        if ($problems) {
            $checks['two_factor_methods'] = array(
                false,
                __('Two-factor methods that cannot be used', 'modify-login'),
                (string) reset($problems),
                Menu::url('two-factor'),
            );
        }

        $checks['two_factor'] = array(
            $done,
            __('Two-factor login for administrators', 'modify-login'),
            $done
                ? __('Every administrator signs in with a second step.', 'modify-login')
                : sprintf(
                    /* translators: 1: admins with 2FA, 2: all admins */
                    __('%1$d of %2$d administrators use two-factor login. Each sets it up from their profile.', 'modify-login'),
                    $admins['with'],
                    $admins['total']
                ),
            Menu::url('two-factor'),
        );

        return $checks;
    }

    /**
     * Site Health: warn when secrets cannot be encrypted.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function site_health_tests($tests)
    {
        $tests['direct']['authlify_2fa_crypto'] = array(
            'label' => __('Two-factor secrets are encrypted', 'modify-login'),
            'test' => array(__CLASS__, 'site_health_crypto'),
        );
        $tests['direct']['authlify_2fa_methods'] = array(
            'label' => __('Every two-factor method can be used', 'modify-login'),
            'test' => array(__CLASS__, 'site_health_methods'),
        );

        return $tests;
    }

    /**
     * Problems that weaken or disturb two-factor login, one sentence each.
     *
     * @return string[]
     * @since 3.0.0
     */
    public static function method_problems()
    {
        $problems = array();

        $stranded = Passkeys::stranded();
        if ($stranded['unsupported']) {
            $problems[] = sprintf(
                /* translators: %d: number of users */
                _n('%d user has a passkey, but this server cannot check passkeys (it needs PHP 8.0 or newer with OpenSSL). Two-factor login stays on for them: they sign in with a backup code or an emailed sign-in link.', '%d users have passkeys, but this server cannot check passkeys (it needs PHP 8.0 or newer with OpenSSL). Two-factor login stays on for them: they sign in with a backup code or an emailed sign-in link.', $stranded['unsupported'], 'modify-login'),
                $stranded['unsupported']
            );
        }
        if ($stranded['other_host']) {
            $problems[] = sprintf(
                /* translators: 1: number of users, 2: host names */
                _n('%1$d user has passkeys only for another address (%2$s). Passkeys work only on the address they were made for: unless this user has another method, they sign in without a second step until they add a passkey here.', '%1$d users have passkeys only for another address (%2$s). Passkeys work only on the address they were made for: unless they have another method, these users sign in without a second step until they add a passkey here.', $stranded['other_host'], 'modify-login'),
                $stranded['other_host'],
                implode(', ', $stranded['hosts'])
            );
        }

        global $wpdb;
        foreach (self::ADDON_METHODS as $key => $meta) {
            $all = self::methods();
            if (empty($all[$key]['unavailable'])) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Site Health only.
            $users = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", $meta));
            if ($users) {
                $problems[] = sprintf(
                    /* translators: %d: number of users */
                    _n('%d user signs in with email codes, which need Authlify Pro. Pro is not active, so this user now signs in with a backup code or an emailed one-time link (the site admin is emailed each time). Activate Authlify Pro, or ask them to set up an authenticator app or a passkey.', '%d users sign in with email codes, which need Authlify Pro. Pro is not active, so they now sign in with a backup code or an emailed one-time link (the site admin is emailed each time). Activate Authlify Pro, or ask them to set up an authenticator app or a passkey.', $users, 'modify-login'),
                    $users
                );
            }
        }

        $settings = get_option('authlify_settings');
        if (!has_filter('authlify_twofactor_required') && is_array($settings) && (!empty($settings['pro_twofa_roles']) || !empty($settings['pro_passkey_only_roles']))) {
            $problems[] = __('Two-factor login is set as required for some roles in Authlify Pro, but Pro is not active. Until it is, those people can sign in with just their password, and passkey-only roles can use their password again.', 'modify-login');
        }

        /**
         * Filters the two-factor problems listed in Site Health.
         *
         * @param string[] $problems One sentence each.
         * @since 3.0.0
         */
        return (array) apply_filters('authlify_twofactor_problems', $problems);
    }

    /**
     * method_problems(), cached for an hour (for the dashboard).
     *
     * @return string[]
     */
    private static function cached_problems()
    {
        $cached = get_transient('authlify_2fa_problems');
        if (!is_array($cached)) {
            $cached = self::method_problems();
            set_transient('authlify_2fa_problems', $cached, HOUR_IN_SECONDS);
        }

        return $cached;
    }

    /**
     * Site Health test result: methods that cannot run.
     *
     * @return array
     */
    public static function site_health_methods()
    {
        $problems = self::method_problems();
        $ok = !$problems;
        $list = '';
        foreach ($problems as $problem) {
            $list .= '<li>' . esc_html($problem) . '</li>';
        }

        return array(
            'label' => $ok ? __('Every two-factor method can be used', 'modify-login') : __('Some two-factor methods cannot be used right now', 'modify-login'),
            'status' => $ok ? 'good' : 'critical',
            'badge' => array('label' => __('Security', 'modify-login'), 'color' => $ok ? 'blue' : 'red'),
            'description' => $ok
                ? '<p>' . esc_html__('Everyone who set up two-factor login can use their methods on this server.', 'modify-login') . '</p>'
                : '<ul>' . $list . '</ul>',
            'actions' => $ok ? '' : '<p><a href="' . esc_url(Menu::url('two-factor')) . '">' . esc_html__('Open the two-factor settings', 'modify-login') . '</a></p>',
            'test' => 'authlify_2fa_methods',
        );
    }

    /**
     * Site Health test result.
     *
     * @return array
     */
    public static function site_health_crypto()
    {
        $ok = Crypto::available();

        return array(
            'label' => $ok ? __('Two-factor secrets are encrypted', 'modify-login') : __('Two-factor secrets are stored without encryption', 'modify-login'),
            'status' => $ok ? 'good' : 'recommended',
            'badge' => array('label' => __('Security', 'modify-login'), 'color' => $ok ? 'blue' : 'orange'),
            'description' => '<p>' . esc_html($ok
                ? __('Authenticator app keys are encrypted with sodium before they are saved.', 'modify-login')
                : __('The sodium PHP extension is missing, so Authlify stores authenticator app keys without encryption. Ask your host to enable sodium.', 'modify-login')) . '</p>',
            'test' => 'authlify_2fa_crypto',
        );
    }
}
