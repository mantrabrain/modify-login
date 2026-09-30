<?php
/**
 * Brute-force protection.
 *
 * @package Authlify
 */

namespace Authlify\Security;

use Authlify\Log\Log;
use Authlify\Net\Ip;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Counts failed logins and locks out attackers.
 *
 * Three counters:
 * - per address: N failures inside the window lock that address out, and each
 *   repeat lockout lasts longer (15 min → 1 h → 4 h → 24 h) when escalation is
 *   on. An IPv6 "address" is its /64, because one connection usually owns a
 *   whole /64 and could otherwise rotate through billions of addresses;
 * - per /24 (IPv4) or /48 (IPv6) network: 3×N failures lock the whole network,
 *   which stops attackers rotating addresses within one provider block;
 * - per username: at the targeted-account threshold the username is flagged
 *   and other modules add friction (a CAPTCHA). At twice that, the account is
 *   paused for addresses it has never logged in from, so a botnet cannot
 *   guess forever. Its owner still gets in from a known address, an
 *   allowlisted one, or with the emailed unlock link.
 *
 * A successful login clears the account's counter. Allowlisted IPs are never
 * counted; denylisted IPs are refused before the password is checked.
 */
final class Limiter
{
    /**
     * Escalation steps as multiples of the base lockout.
     */
    const ESCALATION = array(1, 4, 16, 96);

    /**
     * Table name.
     *
     * @return string
     */
    public static function table()
    {
        global $wpdb;

        return $wpdb->base_prefix . 'authlify_limits';
    }

    /**
     * Wire up.
     */
    public static function init()
    {
        add_filter('authenticate', array(__CLASS__, 'check_before'), 1, 3);
        add_filter('authenticate', array(__CLASS__, 'check_after'), 99999, 3);
        add_action('wp_login_failed', array(__CLASS__, 'record_failure'), 10, 2);
        add_action('wp_login', array(__CLASS__, 'record_success'), 10, 2);
        add_filter('shake_error_codes', array(__CLASS__, 'shake_codes'));
        add_filter('wp_login_errors', array(__CLASS__, 'attempts_left_hint'));
        add_action('authlify_daily', array(__CLASS__, 'prune'));

        // Application-password logins (REST, XML-RPC) skip the authenticate
        // chain, so they get the same gate and failure counting here.
        add_action('wp_authenticate_application_password_errors', array(__CLASS__, 'app_password_gate'), 10, 4);
        add_action('application_password_failed_authentication', array(__CLASS__, 'app_password_failed'));
    }

    /**
     * Whether brute-force limits are on.
     *
     * @return bool
     */
    public static function enabled()
    {
        return (bool) Settings::get('limit_enabled', true);
    }

    /**
     * Refuse denylisted and locked-out IPs before any password is checked.
     *
     * @param \WP_User|\WP_Error|null $user     User.
     * @param string                  $username Username.
     * @param string                  $password Password.
     * @return \WP_User|\WP_Error|null
     */
    public static function check_before($user, $username = '', $password = '')
    {
        $error = self::gate_error((string) $username);

        return $error ? $error : $user;
    }

    /**
     * Enforce the gate again after every other authenticate filter, because
     * core's password check ignores errors returned earlier in the chain.
     *
     * @param \WP_User|\WP_Error|null $user     User.
     * @param string                  $username Username.
     * @param string                  $password Password.
     * @return \WP_User|\WP_Error|null
     */
    public static function check_after($user, $username = '', $password = '')
    {
        $error = self::gate_error((string) $username);

        return $error ? $error : $user;
    }

    /**
     * The error to return when this request may not log in, or null.
     *
     * @return \WP_Error|null
     */
    public static function gate_error($username = '')
    {
        $username = (string) $username;

        // Any request that names an account is gated, however it sends the
        // credentials (form fields, a JSON body, another plugin's endpoint).
        if ('' === $username && empty($_POST) && !self::is_api_request()) { // phpcs:ignore WordPress.Security.NonceVerification
            return null;
        }

        $ip = Ip::client();

        if (self::is_allowlisted($ip)) {
            return null;
        }

        // The block list applies even when lockouts are switched off.
        if (self::is_denylisted($ip)) {
            if ('' !== $username && !did_action('authlify_denied_logged')) {
                do_action('authlify_denied_logged');
                Log::add('denied', array('username' => $username));
            }

            return new \WP_Error('authlify_denied', __('<strong>Error:</strong> Access from your network is blocked.', 'modify-login'));
        }

        if (!self::enabled()) {
            return null;
        }

        $until = self::locked_until($ip);
        if ($until > time()) {
            // An emailed unlock link lets that one account back in from this address.
            if ('' !== $username && self::has_pass($ip, $username)) {
                return null;
            }

            return new \WP_Error('authlify_locked', self::lockout_message($until));
        }

        // A paused account: refused from addresses it has never logged in from.
        if ('' !== $username) {
            $until = self::account_paused_until($username);
            if ($until > time() && !self::has_pass($ip, $username) && !self::is_known_address($username, $ip)) {
                return new \WP_Error('authlify_locked', self::lockout_message($until, 'account'));
            }
        }

        return null;
    }

    /**
     * Counter key for an address: the address itself for IPv4, its /64 for IPv6.
     *
     * @param string $ip IP.
     * @return string
     */
    public static function ip_key($ip)
    {
        $ip = Ip::normalize((string) $ip);

        return Ip::valid($ip) && false !== strpos($ip, ':') ? Ip::subnet($ip) : $ip;
    }

    /**
     * Whether per-account pausing is on.
     *
     * @return bool
     */
    public static function account_pause_enabled()
    {
        return self::enabled() && (bool) Settings::get('limit_user_lock', true);
    }

    /**
     * Failures on one account (from any address) that pause it.
     *
     * @return int
     */
    public static function account_pause_threshold()
    {
        /**
         * Filters how many failures on one username pause it for unknown addresses.
         *
         * @param int $threshold Default: twice the targeted-account threshold.
         * @since 3.0.0
         */
        return max(2, (int) apply_filters('authlify_account_pause_threshold', 2 * max(1, (int) Settings::get('user_attempts', 10))));
    }

    /**
     * When a username's pause ends, or 0.
     *
     * @param string $username Username or email.
     * @return int
     */
    public static function account_paused_until($username)
    {
        global $wpdb;

        if (!self::account_pause_enabled() || '' === trim((string) $username)) {
            return 0;
        }

        $table = self::table();

        return (int) $wpdb->get_var($wpdb->prepare("SELECT locked_until FROM {$table} WHERE scope = 'user' AND subject = %s", self::user_key($username))); // phpcs:ignore
    }

    /**
     * Whether the account has logged in successfully from this address before
     * (the real owner is not paused at their usual place).
     *
     * @param string $username Username or email.
     * @param string $ip       IP.
     * @return bool
     */
    private static function is_known_address($username, $ip)
    {
        global $wpdb;

        $user = get_user_by(is_email($username) ? 'email' : 'login', $username);
        if (!$user || !Ip::valid($ip)) {
            return false;
        }

        $stored = Settings::get('log_anonymize_ip', false) ? Ip::anonymize($ip) : $ip;
        $table = Log::table();

        /**
         * Filters whether an address counts as known for a paused account.
         *
         * @param bool     $known Known.
         * @param \WP_User $user  User.
         * @param string   $ip    IP.
         * @since 3.0.0
         */
        return (bool) apply_filters(
            'authlify_known_address',
            (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE ip = %s AND event = 'login_success' AND user_id = %d LIMIT 1", $stored, $user->ID)), // phpcs:ignore
            $user,
            $ip
        );
    }

    /**
     * Whether an emailed unlock link let this account in from this address.
     *
     * @param string $ip       IP.
     * @param string $username Username or email.
     * @return bool
     */
    private static function has_pass($ip, $username)
    {
        $pass = get_transient('authlify_unlock_pass_' . md5(self::ip_key($ip)));
        if (!$pass) {
            return false;
        }

        foreach ((array) $pass as $user_id) {
            if (self::same_user($user_id, $username)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Warn on the login form when one or two attempts are left before a lockout.
     *
     * @param \WP_Error $errors Errors.
     * @return \WP_Error
     */
    public static function attempts_left_hint($errors)
    {
        if (!self::enabled() || !$errors instanceof \WP_Error || !array_intersect($errors->get_error_codes(), array('incorrect_password', 'invalid_username', 'invalid_email'))) {
            return $errors;
        }

        $left = max(1, (int) Settings::get('limit_attempts', 5)) - self::ip_failures(Ip::client());
        if ($left > 0 && $left <= 2) {
            /* translators: %d: attempts left */
            $errors->add('authlify_attempts_left', sprintf(_n('%d attempt left before a short lockout.', '%d attempts left before a short lockout.', $left, 'modify-login'), $left));
        }

        return $errors;
    }

    /**
     * Counter key for a login name: the account's ID when it exists, so the
     * username and the email of one account share a counter.
     *
     * @param string $login Username or email.
     * @return string
     */
    public static function user_key($login)
    {
        $login = trim((string) $login);
        $user = '' !== $login ? get_user_by(is_email($login) ? 'email' : 'login', $login) : false;

        return $user ? 'id:' . $user->ID : strtolower(sanitize_user($login));
    }

    /**
     * Allow one account to log in from a locked address (after an emailed unlock link).
     *
     * @param string $ip      IP.
     * @param int    $user_id User.
     */
    public static function grant_pass($ip, $user_id)
    {
        $key = 'authlify_unlock_pass_' . md5(self::ip_key($ip));
        $users = array_filter(array_map('intval', (array) get_transient($key)));
        $users[] = (int) $user_id;

        // Several people behind one address can each unlock their own account.
        set_transient($key, array_slice(array_values(array_unique($users)), -10), 30 * MINUTE_IN_SECONDS);
    }

    /**
     * Whether a username or email belongs to a user ID.
     *
     * @param int    $user_id User ID.
     * @param string $login   Username or email.
     * @return bool
     */
    private static function same_user($user_id, $login)
    {
        $user = get_user_by(is_email($login) ? 'email' : 'login', $login);

        return $user && (int) $user->ID === (int) $user_id;
    }

    /**
     * Gate application-password logins.
     *
     * @param \WP_Error $error    Errors.
     * @param \WP_User  $user     User.
     * @param array     $item     App password.
     * @param string    $password Password.
     */
    public static function app_password_gate($error, $user = null, $item = array(), $password = '')
    {
        static $running = false;
        if ($running) {
            return;
        }
        $running = true;

        $gate = self::gate_error($user instanceof \WP_User ? $user->user_login : '');
        if ($gate && $error instanceof \WP_Error) {
            $message = explode('<br', $gate->get_error_message());
            $error->add($gate->get_error_code(), trim(wp_strip_all_tags($message[0])));
        }

        $running = false;
    }

    /**
     * Count a failed application-password login like any other failed login.
     *
     * @param \WP_Error $error Error.
     */
    public static function app_password_failed($error)
    {
        // Logging may ask for the current user, which re-runs application-password
        // authentication and would land here again; count each request once.
        static $running = false;
        if ($running) {
            return;
        }
        $running = true;

        $username = isset($_SERVER['PHP_AUTH_USER']) ? sanitize_user(wp_unslash($_SERVER['PHP_AUTH_USER'])) : '';

        /** This action is documented in wp-includes/user.php */
        do_action('wp_login_failed', $username, $error);

        $running = false;
    }

    /**
     * Human lockout message.
     *
     * @param int $until Unix time the lockout ends.
     * @return string
     */
    public static function lockout_message($until, $scope = 'ip')
    {
        $minutes = max(1, (int) ceil(($until - time()) / MINUTE_IN_SECONDS));
        $left = $minutes >= 120 ? sprintf(_n('%d hour', '%d hours', (int) round($minutes / 60), 'modify-login'), (int) round($minutes / 60)) : sprintf(_n('%d minute', '%d minutes', $minutes, 'modify-login'), $minutes);

        $message = 'account' === $scope
            ? sprintf(
                /* translators: %s: time left, e.g. "15 minutes" */
                __('<strong>This account is paused after too many failed login attempts.</strong> Please try again in %s.', 'modify-login'),
                $left
            )
            : sprintf(
                /* translators: %s: time left, e.g. "15 minutes" */
                __('<strong>Too many failed login attempts.</strong> Please try again in %s.', 'modify-login'),
                $left
            );

        /**
         * Filters the lockout message (the Recovery module appends an unlock-by-email link).
         *
         * @param string $message Message HTML.
         * @param int    $until   Unix time the lockout ends.
         * @since 3.0.0
         */
        return apply_filters('authlify_lockout_message', $message, $until);
    }

    /**
     * Count a failed login.
     *
     * @param string         $username Username.
     * @param \WP_Error|null $error    Error.
     */
    public static function record_failure($username, $error = null)
    {
        // Failures are always counted (the CAPTCHA's "after failures" mode reads
        // them); lockouts only happen when protection is on.
        $code = $error instanceof \WP_Error ? $error->get_error_code() : '';
        if (in_array($code, (array) apply_filters('authlify_ignored_failure_codes', array('authlify_locked', 'authlify_denied', 'authlify_2fa_api', 'authlify_captcha', 'empty_username', 'empty_password')), true)) {
            return;
        }

        $ip = Ip::client();
        if (self::is_allowlisted($ip)) {
            return;
        }

        $window = max(1, (int) Settings::get('limit_window', 15)) * MINUTE_IN_SECONDS;
        $attempts = max(1, (int) Settings::get('limit_attempts', 5));

        $ip_count = self::bump('ip', self::ip_key($ip), $window);
        $net_count = Settings::get('limit_network', false) ? self::bump('net', Ip::network($ip), $window) : 0;
        $user_count = self::bump('user', self::user_key($username), $window * 4);

        if (!self::enabled()) {
            return;
        }

        if ($ip_count >= $attempts) {
            self::lock('ip', self::ip_key($ip), $username);
        }
        if ($net_count && $net_count >= $attempts * 3) {
            self::lock('net', Ip::network($ip), $username);
        }
        if ($user_count && self::account_pause_enabled() && $user_count >= self::account_pause_threshold()) {
            self::pause_account($username);
        }
    }

    /**
     * Pause a username for unknown addresses (base lockout length, no escalation,
     * so an attacker cannot keep the real owner out for long).
     *
     * @param string $username Username.
     */
    private static function pause_account($username)
    {
        global $wpdb;

        $until = time() + max(1, (int) Settings::get('lockout_minutes', 15)) * MINUTE_IN_SECONDS;
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET locked_until = %d, lockouts = lockouts + 1, failures = 0 WHERE scope = 'user' AND subject = %s", $until, self::user_key($username))); // phpcs:ignore

        $user = get_user_by(is_email($username) ? 'email' : 'login', $username);
        Log::add('lockout', array(
            'username' => $username,
            'user_id' => $user ? $user->ID : 0,
            'context' => array('scope' => 'user', 'until' => $until, 'minutes' => (int) round(($until - time()) / 60)),
        ));

        /**
         * Fires when a username is paused for unknown addresses.
         *
         * @param string $username Username.
         * @param int    $until    Unix time the pause ends.
         * @since 3.0.0
         */
        do_action('authlify_account_paused', $username, $until);
    }

    /**
     * Clear the IP counter after a successful login.
     *
     * @param string   $user_login Login.
     * @param \WP_User $user       User.
     */
    public static function record_success($user_login, $user)
    {
        global $wpdb;
        $ip = Ip::client();

        // Only the account's own counter is cleared. The IP counter keeps running
        // until its window ends: otherwise an attacker with any account could
        // log into it between guesses and never be locked out.
        unset($ip);
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET failures = 0 WHERE scope = %s AND subject = %s', 'user', self::user_key($user_login))); // phpcs:ignore
    }

    /**
     * Increment a counter inside its window and return the new count.
     *
     * @param string $scope   ip, net or user.
     * @param string $subject Value.
     * @param int    $window  Window in seconds.
     * @return int
     */
    private static function bump($scope, $subject, $window)
    {
        global $wpdb;

        if ('' === $subject) {
            return 0;
        }

        $now = time();
        $table = self::table();

        // Atomic upsert: restart the count when the previous failure is outside the window.
        $wpdb->query($wpdb->prepare( // phpcs:ignore
            "INSERT INTO {$table} (scope, subject, failures, last_failure, locked_until, lockouts)
             VALUES (%s, %s, 1, %d, 0, 0)
             ON DUPLICATE KEY UPDATE
                failures = IF(last_failure < %d, 1, failures + 1),
                last_failure = %d",
            $scope,
            $subject,
            $now,
            $now - $window,
            $now
        ));

        return (int) $wpdb->get_var($wpdb->prepare("SELECT failures FROM {$table} WHERE scope = %s AND subject = %s", $scope, $subject)); // phpcs:ignore
    }

    /**
     * Lock a subject out.
     *
     * @param string $scope    ip or net.
     * @param string $subject  Value.
     * @param string $username Username that triggered it.
     */
    private static function lock($scope, $subject, $username)
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare("SELECT lockouts, locked_until FROM {$table} WHERE scope = %s AND subject = %s", $scope, $subject)); // phpcs:ignore
        $lockouts = $row ? (int) $row->lockouts : 0;

        // Lockout history older than a day is forgiven.
        if ($row && (int) $row->locked_until < time() - DAY_IN_SECONDS) {
            $lockouts = 0;
        }

        $base = max(1, (int) Settings::get('lockout_minutes', 15)) * MINUTE_IN_SECONDS;
        $step = Settings::get('lockout_escalate', true) ? self::ESCALATION[min($lockouts, count(self::ESCALATION) - 1)] : 1;
        $until = time() + $base * $step;

        // Only the request that actually starts the lockout records it: a
        // concurrent failure that also crossed the limit updates nothing, so
        // one burst never skips an escalation step or logs twice.
        $changed = $wpdb->query($wpdb->prepare("UPDATE {$table} SET locked_until = %d, lockouts = %d, failures = 0 WHERE scope = %s AND subject = %s AND locked_until <= %d", $until, $lockouts + 1, $scope, $subject, time())); // phpcs:ignore
        if (!$changed) {
            return;
        }

        Log::add('lockout', array(
            'username' => $username,
            'user_id' => ($u = get_user_by('login', $username)) ? $u->ID : 0,
            'context' => array('scope' => $scope, 'subject' => $subject, 'until' => $until, 'minutes' => (int) round(($until - time()) / 60)),
        ));

        /**
         * Fires when an IP or network is locked out.
         *
         * @param string $scope    ip or net.
         * @param string $subject  IP or CIDR.
         * @param int    $until    Unix time the lockout ends.
         * @param string $username Username that triggered it.
         * @since 3.0.0
         */
        do_action('authlify_lockout', $scope, $subject, $until, $username);
    }

    /**
     * When the IP (or its network) is locked until, or 0.
     *
     * @param string $ip IP.
     * @return int
     */
    public static function locked_until($ip)
    {
        global $wpdb;
        $table = self::table();

        $ip = Ip::normalize((string) $ip);
        $until = $wpdb->get_var($wpdb->prepare( // phpcs:ignore
            "SELECT MAX(locked_until) FROM {$table} WHERE (scope = 'ip' AND subject IN (%s, %s)) OR (scope = 'net' AND subject IN (%s, %s))",
            $ip,
            self::ip_key($ip),
            Ip::network($ip),
            Ip::subnet($ip)
        ));

        return (int) $until;
    }

    /**
     * Failures recorded for a username inside its window.
     *
     * @param string $username Username.
     * @return int
     */
    public static function user_failures($username)
    {
        global $wpdb;
        $table = self::table();
        $window = max(1, (int) Settings::get('limit_window', 15)) * MINUTE_IN_SECONDS * 4;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT failures FROM {$table} WHERE scope = 'user' AND subject = %s AND last_failure >= %d", self::user_key($username), time() - $window)); // phpcs:ignore
    }

    /**
     * Failures from an IP inside the window.
     *
     * @param string $ip IP.
     * @return int
     */
    public static function ip_failures($ip)
    {
        global $wpdb;
        $table = self::table();
        $window = max(1, (int) Settings::get('limit_window', 15)) * MINUTE_IN_SECONDS;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT failures FROM {$table} WHERE scope = 'ip' AND subject = %s AND last_failure >= %d", self::ip_key($ip), time() - $window)); // phpcs:ignore
    }

    /**
     * Whether a username has been targeted (used to require a CAPTCHA for it).
     *
     * @param string $username Username.
     * @return bool
     */
    public static function user_is_targeted($username)
    {
        return self::user_failures($username) >= max(1, (int) Settings::get('user_attempts', 10));
    }

    /**
     * Current lockouts of addresses and networks.
     *
     * @param bool $accounts Also include paused accounts (scope "user").
     * @return object[]
     */
    public static function active_lockouts($accounts = false)
    {
        global $wpdb;
        $table = self::table();
        $scopes = $accounts ? "'ip', 'net', 'user'" : "'ip', 'net'";

        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE locked_until > %d AND scope IN ({$scopes}) ORDER BY locked_until DESC LIMIT 200", time())); // phpcs:ignore
    }

    /**
     * Lift a lockout for an IP (and its network), or for everything.
     *
     * @param string $ip IP, CIDR, or '' for all.
     * @return int Rows unlocked.
     */
    public static function unlock($ip = '')
    {
        global $wpdb;
        $table = self::table();

        if ('' === $ip) {
            $n = (int) $wpdb->query("UPDATE {$table} SET locked_until = 0, failures = 0, lockouts = 0 WHERE locked_until > 0 OR failures > 0"); // phpcs:ignore
        } else {
            $valid = Ip::valid(Ip::normalize($ip));
            $subjects = $valid ? array($ip, self::ip_key($ip), Ip::subnet($ip), Ip::network($ip)) : array($ip, self::ip_key($ip));
            $n = (int) $wpdb->query($wpdb->prepare("UPDATE {$table} SET locked_until = 0, failures = 0, lockouts = 0 WHERE subject IN (%s, %s, %s, %s)", array_pad($subjects, 4, $ip))); // phpcs:ignore
        }

        if ($n) {
            Log::add('unlock', array('context' => array('subject' => '' === $ip ? 'all' : $ip)));
        }

        return $n;
    }

    /**
     * Delete stale counters.
     */
    public static function prune()
    {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE locked_until < %d AND last_failure < %d", time() - DAY_IN_SECONDS, time() - DAY_IN_SECONDS)); // phpcs:ignore
    }

    /**
     * Whether an IP is on the allowlist.
     *
     * @param string $ip IP.
     * @return bool
     */
    public static function is_allowlisted($ip)
    {
        return Ip::in_ranges($ip, Settings::lines('ip_allowlist'));
    }

    /**
     * Whether an IP is on the denylist.
     *
     * @param string $ip IP.
     * @return bool
     */
    public static function is_denylisted($ip)
    {
        return Ip::in_ranges($ip, Settings::lines('ip_denylist'));
    }

    /**
     * Shake the login form on our errors too.
     *
     * @param array $codes Codes.
     * @return array
     */
    public static function shake_codes($codes)
    {
        $codes[] = 'authlify_locked';
        $codes[] = 'authlify_denied';

        return $codes;
    }

    /**
     * XML-RPC and REST logins count too.
     *
     * @return bool
     */
    private static function is_api_request()
    {
        return (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST) || isset($_SERVER['PHP_AUTH_USER']);
    }
}
