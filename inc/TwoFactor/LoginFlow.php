<?php
/**
 * The second login step.
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

use Authlify\Log\Log;
use Authlify\Security\Limiter;

defined('ABSPATH') || exit;

/**
 * Login interception, following the core-team Two Factor plugin:
 *
 * 1. The password succeeds and core fires `wp_login`. At priority 1 we
 *    destroy the session core just created, drop its cookies, store a
 *    short-lived hashed login nonce in user meta and render the second step
 *    with login_header()/login_footer(), so it inherits the login design.
 * 2. The step posts to the login URL with action=authlify_2fa. A valid code
 *    sets the auth cookie, fires `wp_login` again (so logging, the limiter and
 *    other plugins see one successful login) and redirects the way core does.
 * 3. A wrong code is logged and passed to `wp_login_failed`, so the limiter
 *    counts it. Five wrong codes void the login nonce. Attempts are counted
 *    atomically before the code is checked, and each account also takes at
 *    most ten wrong codes an hour whatever address they come from.
 *
 * Passkeys also sign people in on their own ("Sign in with a passkey"). That
 * counts as a full login and never as a failed attempt. The challenge is not
 * stored: it travels in a signed, short-lived token, and only a challenge that
 * signed someone in is remembered (so it cannot be used twice).
 *
 * Every sign-in that does not pass through `authenticate` (passkey, recovery
 * link) is checked with sign_in_error(): accounts whose sign-in is blocked
 * (META_BLOCKED) are refused, and add-ons can refuse through filters.
 *
 * XML-RPC and REST logins with the account password are refused for users
 * with 2FA; application passwords keep working.
 *
 * @since 3.0.0
 */
final class LoginFlow
{
    const ACTION = 'authlify_2fa';
    const PASSKEY_OPTIONS = 'authlify_passkey_options';
    const PASSKEY_LOGIN = 'authlify_passkey_login';
    const META = 'authlify_2fa_login';
    const META_ATTEMPTS = 'authlify_2fa_login_attempts';
    const META_FAILS = 'authlify_2fa_fails';
    const META_FAILS_SINCE = 'authlify_2fa_fails_since';
    const TTL = 600;
    const MAX_ATTEMPTS = 5;
    const ACCOUNT_LIMIT = 10;
    const ACCOUNT_WINDOW = 3600;
    const PASSKEY_TTL = 300;

    /**
     * User meta that blocks every sign-in to an account (password, application
     * passwords, passkeys, recovery links, password reset). Value: array(
     * reason, by, since, message ). Authlify Pro sets it on temporary access
     * accounts when it is deactivated, so they cannot be used without it.
     */
    const META_BLOCKED = 'authlify_sign_in_blocked';

    /**
     * User meta on temporary accounts (Authlify Pro temporary access links):
     * when the access ends (Unix time). The free plugin honours it on its own,
     * so it holds while Pro is inactive, cannot boot or was uninstalled:
     * such accounts never sign in with a password, never reset it and never
     * get application passwords, and once the time has passed they cannot
     * sign in at all and their sessions end.
     */
    const META_TEMP_EXPIRES = 'authlify_temp_expires';

    /**
     * Whether the step being rendered offers an email recovery link.
     *
     * @var bool
     */
    private static $recovery_offered = false;

    /**
     * Whether the step being rendered shows an error.
     *
     * @var bool
     */
    private static $step_error = false;

    /**
     * True while we complete a login ourselves (our own wp_login must pass).
     *
     * @var bool
     */
    private static $completing = false;

    /**
     * Session tokens created during this request, by user.
     *
     * @var array
     */
    private static $tokens = array();

    /**
     * True while the second step renders (for login_assets()).
     *
     * @var bool
     */
    private static $step = false;

    /**
     * Wire up.
     *
     * @since 3.0.0
     */
    public static function init()
    {
        add_action('set_logged_in_cookie', array(__CLASS__, 'collect_token'), 10, 6);
        add_action('wp_login', array(__CLASS__, 'intercept'), 1, 2);
        add_action('login_form_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('login_form_' . self::PASSKEY_OPTIONS, array(__CLASS__, 'passkey_options'));
        add_action('login_form_' . self::PASSKEY_LOGIN, array(__CLASS__, 'passkey_login'));
        add_filter('authenticate', array(__CLASS__, 'block_api_password'), 9999, 3);
        add_filter('authenticate', array(__CLASS__, 'refuse_blocked'), 99998, 1);
        add_filter('allow_password_reset', array(__CLASS__, 'no_reset_when_blocked'), 20, 2);
        add_filter('wp_is_application_passwords_available_for_user', array(__CLASS__, 'no_app_passwords_when_blocked'), 20, 2);
        add_action('init', array(__CLASS__, 'end_blocked_session'), 1);
        add_filter('xmlrpc_login_error', array(__CLASS__, 'xmlrpc_error'), 10, 2);
        add_action('login_form', array(__CLASS__, 'passkey_button'));
        add_action('login_enqueue_scripts', array(__CLASS__, 'login_assets'));
        add_filter('wp_login_errors', array(__CLASS__, 'login_errors'));
        add_filter('shake_error_codes', array(__CLASS__, 'shake_codes'));
        add_filter('authlify_designer_preview_screen', array(__CLASS__, 'preview_screen'), 10, 2);
    }

    /**
     * The designer's live preview of the second step: the real markup, with a
     * form that goes nowhere.
     *
     * @param bool   $handled Whether a screen was rendered.
     * @param string $screen  Screen key.
     * @return bool
     * @since 3.0.0
     */
    public static function preview_screen($handled, $screen)
    {
        if ($handled || '2fa' !== $screen) {
            return $handled;
        }

        $all = TwoFactor::methods();
        $user = wp_get_current_user();

        self::$step = true;
        login_header(__('Two-factor login', 'modify-login'));
        ?>
        <form name="authlify_2fa_form" id="loginform" class="authlify-2fa authlify-2fa--totp" action="#" method="post" autocomplete="off" onsubmit="return false;">
            <?php self::head(); ?>
            <?php call_user_func($all['totp']['render'], $user); ?>
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Verify', 'modify-login'); ?>">
            </p>
            <div class="authlify-2fa__alt">
                <p class="authlify-2fa__alt-title"><?php esc_html_e('Having trouble?', 'modify-login'); ?></p>
                <ul>
                    <li><button type="button" class="button-link"><?php echo esc_html($all['backup']['switch']); ?></button></li>
                </ul>
            </div>
        </form>
        <p id="nav">
            <a href="#"><?php esc_html_e('Back to sign in', 'modify-login'); ?></a>
        </p>
        <?php
        login_footer('authlify_code');

        return true;
    }

    /**
     * Remember the session token core creates, so it can be destroyed.
     *
     * @param string $cookie     Cookie.
     * @param int    $expire     Expire.
     * @param int    $expiration Expiration.
     * @param int    $user_id    User ID.
     * @param string $scheme     Scheme.
     * @param string $token      Session token.
     */
    public static function collect_token($cookie, $expire, $expiration, $user_id, $scheme = 'logged_in', $token = '')
    {
        if ('' !== (string) $token) {
            self::$tokens[(int) $user_id][] = (string) $token;
        }
    }

    /**
     * After the password: start the second step when the user has 2FA.
     *
     * @param string   $user_login Login.
     * @param \WP_User $user       User.
     */
    public static function intercept($user_login, $user = null)
    {
        if (self::$completing || !$user instanceof \WP_User || !TwoFactor::runs() || !TwoFactor::is_active_for($user->ID)) {
            return;
        }

        /**
         * Filters whether this device may skip the second step (Authlify Pro: trusted devices).
         *
         * @param bool     $trusted Trusted.
         * @param \WP_User $user    User.
         * @since 3.0.0
         */
        if (apply_filters('authlify_twofactor_trusted_device', false, $user)) {
            return;
        }

        self::drop_session($user->ID);

        // phpcs:disable WordPress.Security.NonceVerification -- core's login form has no nonce.
        $args = array(
            'redirect_to' => isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '',
            'remember' => !empty($_POST['rememberme']),
            'interim' => isset($_REQUEST['interim-login']),
            // Email recovery is offered only after a password (wp_signon), never
            // after a magic link or social sign-in, so it cannot turn a single
            // factor into a full login.
            'recover' => did_action('wp_authenticate') > 0 && !did_action('application_password_did_authenticate'),
        );
        // phpcs:enable

        $nonce = self::create_nonce($user->ID, $args);

        if (function_exists('login_header') && did_action('login_init')) {
            self::render($user, $nonce, '', $args);
            exit;
        }

        // Logins from other forms (WooCommerce, themes) continue on the login page.
        $url = self::step_url($user->ID, $nonce, $args);

        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            wp_send_json(array(
                'success' => false,
                'data' => array(
                    'message' => __('Two-factor login is required. Continue on the login page.', 'modify-login'),
                    'redirect' => $url,
                ),
            ));
        }

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Destroy the session core just created and drop its cookies.
     *
     * @param int $user_id User ID.
     */
    public static function drop_session($user_id)
    {
        if (!empty(self::$tokens[$user_id])) {
            $manager = \WP_Session_Tokens::get_instance($user_id);
            foreach (self::$tokens[$user_id] as $token) {
                $manager->destroy($token);
            }
            self::$tokens[$user_id] = array();
        }

        // Take the fresh auth cookies back out of the response, then expire any old ones.
        if (!headers_sent()) {
            $names = array(AUTH_COOKIE, SECURE_AUTH_COOKIE, LOGGED_IN_COOKIE);
            $keep = array();
            foreach (headers_list() as $header) {
                if (0 !== stripos($header, 'Set-Cookie:')) {
                    continue;
                }
                $name = trim(strtok(substr($header, 11), '='));
                if (!in_array($name, $names, true)) {
                    $keep[] = $header;
                }
            }
            header_remove('Set-Cookie');
            foreach ($keep as $header) {
                header($header, false);
            }
        }

        wp_clear_auth_cookie();
        wp_set_current_user(0);
    }

    /**
     * Create the login nonce (stored hashed, single use).
     *
     * @param int   $user_id User ID.
     * @param array $args    redirect_to, remember, interim.
     * @return string Nonce.
     */
    public static function create_nonce($user_id, array $args)
    {
        $nonce = wp_generate_password(40, false);

        update_user_meta($user_id, self::META, array(
            'hash' => self::hash($nonce),
            'expires' => time() + self::TTL,
            'attempts' => 0,
            'remember' => !empty($args['remember']),
            'recover' => !empty($args['recover']),
            'state' => array(),
        ));
        update_user_meta($user_id, self::META_ATTEMPTS, 0);

        return $nonce;
    }

    /**
     * Hash a nonce.
     *
     * @param string $nonce Nonce.
     * @return string
     */
    private static function hash($nonce)
    {
        return hash_hmac('sha256', (string) $nonce, wp_salt('nonce'));
    }

    /**
     * The stored login record when the nonce is valid.
     *
     * @param int    $user_id User ID.
     * @param string $nonce   Nonce.
     * @return array|false
     */
    public static function record($user_id, $nonce)
    {
        $record = get_user_meta((int) $user_id, self::META, true);

        if (!is_array($record) || empty($record['hash']) || '' === (string) $nonce) {
            return false;
        }
        if ((int) $record['expires'] < time()) {
            self::clear($user_id);

            return false;
        }

        return hash_equals($record['hash'], self::hash($nonce)) ? $record : false;
    }

    /**
     * Remove a pending login.
     *
     * @param int $user_id User ID.
     * @since 3.0.0
     */
    public static function clear($user_id)
    {
        delete_user_meta((int) $user_id, self::META);
        delete_user_meta((int) $user_id, self::META_ATTEMPTS);
    }

    /**
     * Add one to a numeric user meta value atomically and return the new value.
     *
     * One UPDATE with LAST_INSERT_ID(), so parallel requests each get their
     * own number and none is lost (a read-then-write lets them overlap).
     *
     * @param int    $user_id User ID.
     * @param string $key     Meta key.
     * @return int
     * @since 3.0.0
     */
    public static function increment($user_id, $key)
    {
        global $wpdb;
        $user_id = (int) $user_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic increment; the meta cache is cleared below.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->usermeta} SET meta_value = LAST_INSERT_ID(CAST(meta_value AS UNSIGNED) + 1) WHERE user_id = %d AND meta_key = %s",
            $user_id,
            $key
        ));

        if ($updated) {
            $value = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            wp_cache_delete($user_id, 'user_meta');

            return $value;
        }

        wp_cache_delete($user_id, 'user_meta');

        // No row yet, or a database without LAST_INSERT_ID(expr): read and write.
        $value = (int) get_user_meta($user_id, $key, true) + 1;
        update_user_meta($user_id, $key, $value);

        return $value;
    }

    /**
     * Error while this account has had too many wrong second-step codes in
     * the last hour (from any address), or null.
     *
     * @param \WP_User $user User.
     * @return \WP_Error|null
     * @since 3.0.0
     */
    public static function account_throttle($user)
    {
        $since = (int) get_user_meta($user->ID, self::META_FAILS_SINCE, true);
        if (!$since || $since < time() - self::ACCOUNT_WINDOW) {
            return null;
        }

        $fails = (int) get_user_meta($user->ID, self::META_FAILS, true);
        if ($fails < self::account_limit($user)) {
            return null;
        }

        $minutes = (int) max(1, ceil(($since + self::ACCOUNT_WINDOW - time()) / 60));

        return new \WP_Error('authlify_2fa_throttled', sprintf(
            /* translators: %d: minutes */
            _n('<strong>Error:</strong> Too many wrong codes were entered for this account. Try again in %d minute, or use “Email me a recovery link”.', '<strong>Error:</strong> Too many wrong codes were entered for this account. Try again in %d minutes, or use “Email me a recovery link”.', $minutes, 'modify-login'),
            $minutes
        ));
    }

    /**
     * Wrong second-step codes an account may take per hour.
     *
     * @param \WP_User $user User.
     * @return int
     */
    private static function account_limit($user)
    {
        /**
         * Filters how many wrong second-step codes an account may take in an
         * hour, from any address, before the step pauses for that account.
         *
         * @param int      $limit Limit (default 10).
         * @param \WP_User $user  User.
         * @since 3.0.0
         */
        return max(self::MAX_ATTEMPTS, (int) apply_filters('authlify_twofactor_account_limit', self::ACCOUNT_LIMIT, $user));
    }

    /**
     * Count a wrong code against the account.
     *
     * @param int $user_id User ID.
     * @return int Wrong codes in the current window.
     */
    private static function count_account_fail($user_id)
    {
        $since = (int) get_user_meta($user_id, self::META_FAILS_SINCE, true);
        if (!$since || $since < time() - self::ACCOUNT_WINDOW) {
            update_user_meta($user_id, self::META_FAILS_SINCE, time());
            update_user_meta($user_id, self::META_FAILS, 0);
        }

        return self::increment($user_id, self::META_FAILS);
    }

    /**
     * Store a value for the pending login (methods use it for challenges).
     *
     * @param int    $user_id User ID.
     * @param string $key     Key.
     * @param mixed  $value   Value.
     * @since 3.0.0
     */
    public static function state_set($user_id, $key, $value)
    {
        $record = get_user_meta((int) $user_id, self::META, true);
        if (is_array($record)) {
            $record['state'][$key] = $value;
            update_user_meta((int) $user_id, self::META, $record);
        }
    }

    /**
     * Read a value stored for the pending login.
     *
     * @param int    $user_id User ID.
     * @param string $key     Key.
     * @return mixed|null
     * @since 3.0.0
     */
    public static function state_get($user_id, $key)
    {
        $record = get_user_meta((int) $user_id, self::META, true);

        return is_array($record) && isset($record['state'][$key]) ? $record['state'][$key] : null;
    }

    /**
     * URL of the second step (used when the password was entered on another form).
     *
     * @param int    $user_id User ID.
     * @param string $nonce   Nonce.
     * @param array  $args    Args.
     * @return string
     */
    public static function step_url($user_id, $nonce, array $args)
    {
        return add_query_arg(array_filter(array(
            'action' => self::ACTION,
            'authlify_uid' => (int) $user_id,
            'authlify_nonce' => $nonce,
            'redirect_to' => '' !== $args['redirect_to'] ? rawurlencode($args['redirect_to']) : '',
            'interim-login' => !empty($args['interim']) ? 1 : '',
        )), wp_login_url());
    }

    /**
     * The action=authlify_2fa request: show or verify the second step.
     */
    public static function handle()
    {
        // phpcs:disable WordPress.Security.NonceVerification -- the login nonce below is the check.
        $user_id = isset($_REQUEST['authlify_uid']) ? absint($_REQUEST['authlify_uid']) : 0;
        $nonce = isset($_REQUEST['authlify_nonce']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_REQUEST['authlify_nonce'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $user = $user_id ? get_userdata($user_id) : false;
        $record = $user ? self::record($user_id, $nonce) : false;

        if (!$user || !$record || !TwoFactor::runs()) {
            wp_safe_redirect(add_query_arg('authlify_2fa', 'expired', wp_login_url()));
            exit;
        }

        $args = array(
            'redirect_to' => isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '',
            'remember' => !empty($record['remember']),
            'interim' => isset($_REQUEST['interim-login']),
        );

        $methods = TwoFactor::user_methods($user_id);

        // 2FA was removed meanwhile (e.g. an admin reset): the password was already right.
        if (!$methods) {
            self::clear($user_id);
            self::complete($user, $args, '');
        }

        $requested = isset($_REQUEST['authlify_method']) ? sanitize_key(wp_unslash($_REQUEST['authlify_method'])) : '';
        $switch = isset($_POST['authlify_switch']) ? sanitize_key(wp_unslash($_POST['authlify_switch'])) : '';
        $recover = !empty($_POST['authlify_recover']);
        // phpcs:enable

        // "Can't use your methods? Email me a recovery link."
        if ($recover && 'POST' === strtoupper(isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET')) {
            $gate = Limiter::gate_error();
            if ($gate) {
                self::render($user, $nonce, $requested, $args, $gate);
                exit;
            }
            $notice = Recovery::from_step($user, $record, $args);
            self::render($user, $nonce, $requested, $args, null, $notice);
            exit;
        }

        if ('' !== $switch && in_array($switch, $methods, true)) {
            self::render($user, $nonce, $switch, $args);
            exit;
        }

        $method = in_array($requested, $methods, true) ? $requested : '';

        if ('POST' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET') || '' === $method) {
            self::render($user, $nonce, $method, $args);
            exit;
        }

        $gate = Limiter::gate_error();
        if (!$gate) {
            $gate = self::account_throttle($user);
            if ($gate) {
                Log::add('twofa_failed', array('user_id' => $user_id, 'username' => $user->user_login, 'context' => array('method' => $method, 'reason' => 'account_throttled')));
            }
        }
        if ($gate) {
            self::render($user, $nonce, $method, $args, $gate);
            exit;
        }

        // Count the attempt before checking the code, atomically, so parallel
        // requests on one pending login cannot get more than five tries.
        $attempts = self::increment($user_id, self::META_ATTEMPTS);
        if ($attempts > self::MAX_ATTEMPTS) {
            self::clear($user_id);
            wp_safe_redirect(add_query_arg('authlify_2fa', 'too_many', wp_login_url()));
            exit;
        }

        $all = TwoFactor::methods();
        $result = is_callable($all[$method]['verify']) ? call_user_func($all[$method]['verify'], $user) : false;

        if (true === $result) {
            self::clear($user_id);
            delete_user_meta($user_id, self::META_FAILS);
            delete_user_meta($user_id, self::META_FAILS_SINCE);
            self::complete($user, $args, $method);
        }

        // Wrong code.
        $account_fails = self::count_account_fail($user_id);

        Log::add('twofa_failed', array(
            'user_id' => $user_id,
            'username' => $user->user_login,
            'context' => array('method' => $method, 'attempt' => $attempts, 'account_hour' => $account_fails),
        ));

        /** This action is documented in wp-includes/user.php */
        do_action('wp_login_failed', $user->user_login, new \WP_Error('authlify_2fa_failed', __('Wrong two-factor code.', 'modify-login')));

        if ($attempts >= self::MAX_ATTEMPTS) {
            self::clear($user_id);
            wp_safe_redirect(add_query_arg('authlify_2fa', 'too_many', wp_login_url()));
            exit;
        }

        $record = get_user_meta($user_id, self::META, true);
        if (is_array($record)) {
            // Kept for add-ons that read the record; the counter above is the one that counts.
            $record['attempts'] = $attempts;
            update_user_meta($user_id, self::META, $record);
        }

        $error = is_wp_error($result) ? $result : new \WP_Error('authlify_2fa_failed', 'passkey' === $method
            ? __('<strong>Error:</strong> The passkey could not be verified. Please try again.', 'modify-login')
            : __('<strong>Error:</strong> That code is not valid. Please try again.', 'modify-login'));

        /**
         * Filters where a failed second step is shown again ('' = here, on the
         * login page). Authlify Pro keeps WooCommerce customers in My Account.
         * The error message is kept for the pending login (state 'error').
         *
         * @param string    $url    URL.
         * @param \WP_User  $user   User.
         * @param string    $nonce  Login nonce.
         * @param string    $method Method.
         * @param \WP_Error $error  Error.
         * @since 3.0.0
         */
        $retry = (string) apply_filters('authlify_twofactor_step_retry_url', '', $user, $nonce, $method, $error);
        if ('' !== $retry) {
            self::state_set($user_id, 'error', $error->get_error_message());
            wp_safe_redirect($retry);
            exit;
        }

        self::render($user, $nonce, $method, $args, $error);
        exit;
    }

    /**
     * Render the second step.
     *
     * @param \WP_User       $user   User.
     * @param string         $nonce  Login nonce.
     * @param string         $method Method key ('' for the default).
     * @param array          $args   redirect_to, remember, interim.
     * @param \WP_Error|null $error  Error to show.
     * @param string         $notice Message to show (plain text).
     */
    private static function render($user, $nonce, $method, array $args, $error = null, $notice = '')
    {
        global $interim_login;

        $methods = TwoFactor::user_methods($user->ID);
        $all = TwoFactor::methods();
        if (!in_array($method, $methods, true)) {
            $method = (string) reset($methods);
        }
        $def = $all[$method];

        if (!empty($args['interim'])) {
            $interim_login = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        }

        if (!empty($def['begin']) && is_callable($def['begin'])) {
            call_user_func($def['begin'], $user);
        }

        $record = self::record($user->ID, $nonce);
        self::$recovery_offered = Recovery::offered_for($user, is_array($record) ? $record : array()) && empty($args['interim']);
        self::$step_error = $error instanceof \WP_Error;

        self::$step = true;
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        // The login nonce may be in this page's URL (logins from other forms).
        header('Referrer-Policy: no-referrer', true);

        login_header(__('Two-factor login', 'modify-login'), '' !== $notice ? '<p class="message">' . esc_html($notice) . '</p>' : '', $error instanceof \WP_Error ? $error : null);
        ?>
        <form name="authlify_2fa_form" id="loginform" class="authlify-2fa authlify-2fa--<?php echo esc_attr($method); ?>" action="<?php echo esc_url(add_query_arg('action', self::ACTION, wp_login_url())); ?>" method="post" autocomplete="off">
            <input type="hidden" name="authlify_uid" value="<?php echo esc_attr((string) $user->ID); ?>">
            <input type="hidden" name="authlify_nonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" name="authlify_method" value="<?php echo esc_attr($method); ?>">
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($args['redirect_to']); ?>">
            <?php if (!empty($args['remember'])) : ?>
                <input type="hidden" name="rememberme" value="forever">
            <?php endif; ?>
            <?php if (!empty($args['interim'])) : ?>
                <input type="hidden" name="interim-login" value="1">
            <?php endif; ?>

            <?php self::head(); ?>

            <?php call_user_func($def['render'], $user); ?>

            <p class="submit"<?php echo empty($def['submit']) ? ' hidden' : ''; ?>>
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Verify', 'modify-login'); ?>">
            </p>

            <?php
            $others = array_diff($methods, array($method));
            $recover = self::$recovery_offered;
            if ($others || $recover) :
                ?>
                <div class="authlify-2fa__alt">
                    <p class="authlify-2fa__alt-title"><?php esc_html_e('Having trouble?', 'modify-login'); ?></p>
                    <ul>
                        <?php foreach ($others as $key) : ?>
                            <li><button type="submit" class="button-link" name="authlify_switch" value="<?php echo esc_attr($key); ?>" formnovalidate><?php echo esc_html($all[$key]['switch']); ?></button></li>
                        <?php endforeach; ?>
                        <?php if ($recover) : ?>
                            <li><button type="submit" class="button-link" name="authlify_recover" value="1" formnovalidate><?php esc_html_e('Can’t use your methods? Email me a recovery link', 'modify-login'); ?></button></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </form>
        <p id="nav">
            <a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to sign in', 'modify-login'); ?></a>
        </p>
        <?php
        login_footer('authlify_code');
    }

    /**
     * Attributes for a second-step code field while the step shows an error:
     * marks it invalid and points it at the message (core's #login_error).
     * Methods print it inside their <input>.
     *
     * @return string Escaped attributes, with a leading space, or ''.
     * @since 3.0.0
     */
    public static function field_error_attrs()
    {
        return self::$step_error ? ' aria-invalid="true" aria-describedby="login_error"' : '';
    }

    /**
     * Whether the step being rendered offers "Email me a recovery link" (for
     * methods that cannot run and point people to it).
     *
     * @return bool
     * @since 3.0.0
     */
    public static function recovery_offered()
    {
        return self::$recovery_offered;
    }

    /**
     * The step's heading: a shield and a short title, in the form's own colours.
     *
     * @since 3.0.0
     */
    private static function head()
    {
        ?>
        <div class="authlify-2fa__head">
            <span class="authlify-2fa__icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" focusable="false"><path fill="currentColor" d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm0 2.2 6 2.2V11c0 4-2.6 7.8-6 8.9-3.4-1.1-6-4.9-6-8.9V6.4l6-2.2Zm-1 4.8v2.1a2 2 0 1 0 2 0V9h-2Z"/></svg></span>
            <h2 class="authlify-2fa__title"><?php esc_html_e('Confirm it\'s you', 'modify-login'); ?></h2>
        </div>
        <?php
    }

    /**
     * Sign a user in after a proven second factor: auth cookie, log entry,
     * `authlify_twofactor_verified`, then `wp_login` (which this class lets
     * through). The caller redirects.
     *
     * @param \WP_User $user     User.
     * @param bool     $remember Remember me.
     * @param string   $method   Method used ('' when 2FA was reset mid-login).
     * @since 3.0.0
     */
    public static function sign_in($user, $remember, $method)
    {
        self::$completing = true;
        wp_set_auth_cookie($user->ID, (bool) $remember);
        wp_set_current_user($user->ID);

        if ('' !== $method) {
            Log::add('passkey_login' === $method ? 'passkey_login' : 'twofa_verified', array(
                'user_id' => $user->ID,
                'username' => $user->user_login,
                'context' => array('method' => 'passkey_login' === $method ? 'passkey' : $method),
            ));

            /**
             * Fires after the second step succeeded (or a passkey signed the user in),
             * before the login is completed.
             *
             * @param \WP_User $user   User.
             * @param string   $method Method key; 'passkey_login' for passwordless sign-in, 'recovery' for an email recovery link.
             * @since 3.0.0
             */
            do_action('authlify_twofactor_verified', $user, $method);
        }

        /** This action is documented in wp-includes/user.php */
        do_action('wp_login', $user->user_login, $user);
        self::$completing = false;
    }

    /**
     * Log the user in after the second factor (or a passkey) and redirect like core.
     *
     * @param \WP_User $user   User.
     * @param array    $args   redirect_to, remember, interim.
     * @param string   $method Method used ('' when 2FA was reset mid-login).
     */
    private static function complete($user, array $args, $method)
    {
        global $interim_login;

        self::sign_in($user, !empty($args['remember']), $method);

        if (!empty($args['interim'])) {
            $interim_login = 'success'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            login_header('', '<p class="message">' . esc_html__('You have logged in successfully.', 'modify-login') . '</p>');
            echo '</div>';
            /** This action is documented in wp-login.php */
            do_action('login_footer');
            echo '</body></html>';
            exit;
        }

        $requested = $args['redirect_to'];
        $redirect_to = '' !== $requested ? $requested : admin_url();

        /** This filter is documented in wp-login.php */
        $redirect_to = apply_filters('login_redirect', $redirect_to, $requested, $user);

        if (empty($redirect_to) || 'wp-admin/' === $redirect_to || admin_url() === $redirect_to) {
            // Same fallbacks as core for users without dashboard access.
            if (is_multisite() && !get_active_blog_for_user($user->ID) && !is_super_admin($user->ID)) {
                $redirect_to = user_admin_url();
            } elseif (is_multisite() && !$user->has_cap('read')) {
                $redirect_to = get_dashboard_url($user->ID);
            } elseif (!$user->has_cap('edit_posts')) {
                $redirect_to = $user->has_cap('read') ? admin_url('profile.php') : home_url();
            }

            wp_redirect($redirect_to); // phpcs:ignore WordPress.Security.SafeRedirect -- same as core.
            exit;
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    /**
     * Passkey sign-in, step 1: options for navigator.credentials.get() (JSON).
     */
    public static function passkey_options()
    {
        nocache_headers();

        if (!TwoFactor::passkey_login_enabled() || 'POST' !== (isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '')) {
            wp_send_json_error(array('message' => __('Passkey sign-in is not available.', 'modify-login')), 404);
        }

        $gate = Limiter::gate_error();
        if ($gate) {
            wp_send_json_error(array('message' => wp_strip_all_tags($gate->get_error_message())), 403);
        }

        $options = Passkeys::assertion_options(0);
        if (is_wp_error($options)) {
            wp_send_json_error(array('message' => $options->get_error_message()), 500);
        }

        // Nothing is stored for an anonymous request: the challenge travels in
        // a signed token that expires in five minutes.
        wp_send_json_success(array('options' => $options['options'], 'token' => self::passkey_token($options['challenge'])));
    }

    /**
     * A signed token that carries a passkey sign-in challenge.
     *
     * @param string   $challenge Challenge (base64url).
     * @param int|null $expires   Expiry (Unix time).
     * @return string challenge.expires.signature
     * @since 3.0.0
     */
    public static function passkey_token($challenge, $expires = null)
    {
        $payload = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $challenge) . '.' . (null === $expires ? time() + self::PASSKEY_TTL : (int) $expires);

        return $payload . '.' . self::passkey_signature($payload);
    }

    /**
     * Signature of a token payload.
     *
     * @param string $payload Payload.
     * @return string
     */
    private static function passkey_signature($payload)
    {
        return hash_hmac('sha256', 'authlify_passkey_login|' . $payload, wp_salt('auth'));
    }

    /**
     * The challenge in a signed token, or '' when the token is forged or expired.
     *
     * @param string $token Token.
     * @return string
     * @since 3.0.0
     */
    public static function passkey_challenge($token)
    {
        $parts = explode('.', (string) $token);
        if (3 !== count($parts) || '' === $parts[0] || !ctype_digit($parts[1])) {
            return '';
        }
        if (!hash_equals(self::passkey_signature($parts[0] . '.' . $parts[1]), $parts[2])) {
            return '';
        }
        if ((int) $parts[1] < time() || (int) $parts[1] > time() + self::PASSKEY_TTL) {
            return '';
        }

        return $parts[0];
    }

    /**
     * Claim a one-time marker. Returns true only for the first caller (an
     * INSERT IGNORE, so two parallel requests cannot both win). Stored as a
     * transient row so WordPress deletes it when it expires.
     *
     * @param string $key Key (at most 150 characters).
     * @param int    $ttl Seconds.
     * @return bool
     * @since 3.0.0
     */
    public static function claim_once($key, $ttl)
    {
        global $wpdb;
        $key = substr(sanitize_key($key), 0, 150);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- an atomic insert; a transient cannot say whether it already existed.
        $won = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", '_transient_' . $key, '1'));
        if ($won) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", '_transient_timeout_' . $key, (string) (time() + (int) $ttl)));
        }
        // phpcs:enable

        return (bool) $won;
    }

    /**
     * Passkey sign-in, step 2: verify the assertion and log in.
     */
    public static function passkey_login()
    {
        nocache_headers();

        // phpcs:disable WordPress.Security.NonceVerification -- bound to the single-use WebAuthn challenge.
        $args = array(
            'redirect_to' => isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '',
            'remember' => !empty($_POST['rememberme']),
            'interim' => isset($_POST['interim-login']),
        );
        $token = isset($_POST['authlify_pk_token']) ? preg_replace('/[^A-Za-z0-9_.-]/', '', (string) wp_unslash($_POST['authlify_pk_token'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        // phpcs:enable

        $back = add_query_arg(array_filter(array(
            'redirect_to' => '' !== $args['redirect_to'] ? rawurlencode($args['redirect_to']) : '',
            'interim-login' => $args['interim'] ? 1 : '',
        )), wp_login_url());

        if (!TwoFactor::passkey_login_enabled() || '' === $token) {
            wp_safe_redirect($back);
            exit;
        }

        if (Limiter::gate_error()) {
            wp_safe_redirect(add_query_arg('authlify_passkey', 'locked', $back));
            exit;
        }

        $challenge = self::passkey_challenge($token);

        $user = '' !== $challenge ? Passkeys::verify_assertion(Passkeys::posted_assertion(), $challenge, 0) : new \WP_Error('authlify_passkey_expired');

        // Single use: only a challenge that signed someone in is remembered
        // (until it would expire anyway), so anonymous requests store nothing.
        if ($user instanceof \WP_User && !self::claim_once('authlify_pku_' . substr(hash('sha256', $challenge), 0, 40), self::PASSKEY_TTL + 60)) {
            $user = new \WP_Error('authlify_passkey_replayed');
        }

        if ($user instanceof \WP_User && is_multisite() && is_user_spammy($user)) {
            $user = new \WP_Error('spammer_account');
        }

        if ($user instanceof \WP_User) {
            $refused = self::sign_in_error($user, 'passkey_login');
            if ($refused) {
                $user = $refused;
            }
        }

        /**
         * Filters the user a passkey signs in (return a WP_Error to refuse).
         * Authlify Pro applies its access rules (countries, login hours,
         * honeypot bans) here.
         *
         * @param \WP_User|\WP_Error $user User.
         * @since 3.0.0
         */
        $user = apply_filters('authlify_passkey_login_user', $user);

        if (!$user instanceof \WP_User) {
            // A failed or cancelled passkey is not a password guess: logged, never counted.
            $code = is_wp_error($user) ? $user->get_error_code() : '';
            Log::add('twofa_failed', array('context' => array('method' => 'passkey_login', 'reason' => $code)));

            // A rule that refused a verified passkey says why (like the password form does).
            if (is_wp_error($user) && self::is_rule_error($code)) {
                wp_safe_redirect(add_query_arg('authlify_passkey', 'refused', $back));
                exit;
            }

            wp_safe_redirect(add_query_arg('authlify_passkey', 'failed', $back));
            exit;
        }

        self::complete($user, $args, 'passkey_login');
    }

    /**
     * Refuse the account password over XML-RPC and REST for users with 2FA.
     *
     * Application passwords are separate secrets and keep working.
     *
     * @param \WP_User|\WP_Error|null $user     User.
     * @param string                  $username Username.
     * @param string                  $password Password.
     * @return \WP_User|\WP_Error|null
     */
    public static function block_api_password($user, $username = '', $password = '')
    {
        if (!$user instanceof \WP_User || !TwoFactor::runs()) {
            return $user;
        }

        $api = (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST);
        if (!$api || !TwoFactor::is_active_for($user->ID)) {
            return $user;
        }

        // Application passwords stay allowed. Decide per user and per call whether
        // the secret given is this account's real password: a request-wide
        // "an app password authenticated" flag could be borrowed from another
        // login in the same XML-RPC multicall.
        if ('' === (string) $password || !wp_check_password((string) $password, $user->user_pass, $user->ID)) {
            return $user;
        }

        /**
         * Filters whether a user with 2FA may use the account password over XML-RPC or REST.
         *
         * @param bool     $allowed Allowed (default false).
         * @param \WP_User $user    User.
         * @since 3.0.0
         */
        if (apply_filters('authlify_twofactor_api_password_allowed', false, $user)) {
            return $user;
        }

        return new \WP_Error('authlify_2fa_api', __('<strong>Error:</strong> This account uses two-factor login, so its password does not work in apps. Create an application password in your profile instead.', 'modify-login'));
    }

    /**
     * Whether an account's sign-in is blocked (META_BLOCKED), for example an
     * Authlify Pro temporary access account while Pro is not active.
     *
     * @param int $user_id User ID.
     * @return array|false The block (reason, by, since, message), or false.
     * @since 3.0.0
     */
    public static function blocked($user_id)
    {
        $block = get_user_meta((int) $user_id, self::META_BLOCKED, true);
        if (is_array($block) && !empty($block['reason'])) {
            return $block;
        }

        $expires = (string) get_user_meta((int) $user_id, self::META_TEMP_EXPIRES, true);
        if ('' !== $expires && (int) $expires <= time()) {
            return array(
                'reason' => 'temporary_expired',
                'by' => 'authlify',
                'since' => (int) $expires,
                'message' => __('This temporary access has ended. Ask the person who gave you access for a new link.', 'modify-login'),
            );
        }

        return false;
    }

    /**
     * Whether an account is a temporary one (META_TEMP_EXPIRES is set).
     *
     * @param int $user_id User ID.
     * @return bool
     * @since 3.0.0
     */
    public static function is_temporary($user_id)
    {
        return '' !== (string) get_user_meta((int) $user_id, self::META_TEMP_EXPIRES, true);
    }

    /**
     * Error for a blocked account.
     *
     * @param array $block Block.
     * @return \WP_Error
     */
    private static function blocked_error(array $block)
    {
        $message = !empty($block['message']) ? (string) $block['message'] : __('This account cannot sign in right now. Please contact the site admin.', 'modify-login');

        return new \WP_Error('authlify_sign_in_blocked', '<strong>' . esc_html__('Error:', 'modify-login') . '</strong> ' . esc_html($message));
    }

    /**
     * Checks for sign-ins that do not run `authenticate` (passkey sign-in,
     * recovery links): the account may not be blocked, and add-ons may refuse.
     *
     * @param \WP_User $user   User.
     * @param string   $method passkey_login or recovery.
     * @return \WP_Error|null
     * @since 3.0.0
     */
    public static function sign_in_error($user, $method)
    {
        $block = self::blocked($user->ID);
        if ($block) {
            return self::blocked_error($block);
        }

        /**
         * Filters whether a user may sign in without a password (passkey,
         * two-factor recovery link, or an add-on's own method). Return a
         * WP_Error to refuse. The password form uses `authenticate` instead.
         *
         * @param true|\WP_Error $ok     True to allow.
         * @param \WP_User       $user   User.
         * @param string         $method passkey_login, recovery, or an add-on's key.
         * @since 3.0.0
         */
        $ok = apply_filters('authlify_can_sign_in', true, $user, (string) $method);

        return is_wp_error($ok) ? $ok : null;
    }

    /**
     * Whether an error code comes from an access rule (shown to the person,
     * unlike a failed passkey, which is never explained further).
     *
     * @param string $code Code.
     * @return bool
     */
    private static function is_rule_error($code)
    {
        return 'authlify_sign_in_blocked' === $code || 0 === strpos((string) $code, 'authlify_pro_') || 'authlify_locked' === $code || 'authlify_denied' === $code;
    }

    /**
     * Whether this POST was sent by a page on another site (login CSRF: a
     * form elsewhere that signs the visitor in with someone's link).
     *
     * Uses Sec-Fetch-Site, which pages cannot change, then the Origin
     * header. Requests without either (older browsers) are let through.
     *
     * @return bool
     * @since 3.0.0
     */
    public static function cross_site_post()
    {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- compared, never output.
        $fetch = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_SEC_FETCH_SITE']))) : '';
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? strtolower(trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'])))) : '';
        // phpcs:enable

        if ('cross-site' === $fetch) {
            return true;
        }
        if ('' === $origin || 'null' === $origin) {
            return false;
        }

        $host = (string) wp_parse_url($origin, PHP_URL_HOST);
        foreach (array(home_url(), site_url(), wp_login_url()) as $url) {
            if ('' !== $host && strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === $host) {
                return false;
            }
        }

        return true;
    }

    /**
     * `authenticate`: refuse blocked accounts (last, after the password check).
     *
     * @param \WP_User|\WP_Error|null $user User.
     * @return \WP_User|\WP_Error|null
     */
    public static function refuse_blocked($user)
    {
        if ($user instanceof \WP_User) {
            $block = self::blocked($user->ID);
            if ($block) {
                return self::blocked_error($block);
            }
            // Temporary accounts sign in with their link, never a password.
            if (self::is_temporary($user->ID)) {
                return self::blocked_error(array('message' => __('This is a temporary account. Use the access link you were given.', 'modify-login')));
            }
        }

        return $user;
    }

    /**
     * No password reset for blocked accounts.
     *
     * @param bool $allow   Allow.
     * @param int  $user_id User ID.
     * @return bool|\WP_Error
     */
    public static function no_reset_when_blocked($allow, $user_id = 0)
    {
        return $user_id && (self::blocked($user_id) || self::is_temporary($user_id)) ? false : $allow;
    }

    /**
     * No application passwords for blocked accounts.
     *
     * @param bool     $available Available.
     * @param \WP_User $user      User.
     * @return bool
     */
    public static function no_app_passwords_when_blocked($available, $user = null)
    {
        return $user instanceof \WP_User && (self::blocked($user->ID) || self::is_temporary($user->ID)) ? false : $available;
    }

    /**
     * A blocked account that still has a session (made before the block) is
     * signed out.
     */
    public static function end_blocked_session()
    {
        $user_id = get_current_user_id();
        if (!$user_id || !self::blocked($user_id)) {
            return;
        }

        \WP_Session_Tokens::get_instance($user_id)->destroy_all();
        wp_clear_auth_cookie();
        wp_set_current_user(0);
    }

    /**
     * XML-RPC replies "Incorrect username or password" for every refusal.
     * For Authlify's own refusals, send the real reason (so app users learn
     * to create an application password).
     *
     * @param \IXR_Error $error XML-RPC error.
     * @param \WP_Error  $user  Authentication result.
     * @return \IXR_Error
     */
    public static function xmlrpc_error($error, $user = null)
    {
        if ($user instanceof \WP_Error && 0 === strpos((string) $user->get_error_code(), 'authlify_') && class_exists('IXR_Error')) {
            $text = trim(wp_strip_all_tags(str_replace('<strong>Error:</strong>', '', $user->get_error_message())));

            return new \IXR_Error(403, '' !== $text ? $text : $error->message);
        }

        return $error;
    }

    /**
     * "Sign in with a passkey" on the login form.
     */
    public static function passkey_button()
    {
        if (!TwoFactor::passkey_login_enabled()) {
            return;
        }
        ?>
        <div class="authlify-passkey-login" hidden>
            <p class="authlify-passkey-login__or"><span><?php esc_html_e('or', 'modify-login'); ?></span></p>
            <button type="button" class="button button-large authlify-passkey-signin">
                <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-7 1.6-7 4v2h11.1a6 6 0 0 1-.1-1c0-1.9.9-3.6 2.2-4.7-1.8-.2-4-.3-6.2-.3Zm12.6 3.3a3.5 3.5 0 1 0-4.1 3.4V22l1.5 1 1.5-1-1-1 1-1-1-.7v-.6a3.5 3.5 0 0 0 2.1-3.4Zm-3.5-.8a.9.9 0 1 1 0-1.8.9.9 0 0 1 0 1.8Z"/></svg>
                <span><?php esc_html_e('Sign in with a passkey', 'modify-login'); ?></span>
            </button>
            <p class="authlify-passkey-status" role="status" aria-live="polite"></p>
        </div>
        <?php
    }

    /**
     * Scripts and styles on the login page.
     */
    public static function login_assets()
    {
        $button = TwoFactor::passkey_login_enabled();
        if (!self::$step && !$button) {
            return;
        }

        wp_enqueue_style('authlify-twofactor-login', AUTHLIFY_URL . 'assets/twofactor/login.css', array(), AUTHLIFY_VERSION);

        if (!Passkeys::supported()) {
            return;
        }

        wp_enqueue_script('authlify-passkey', AUTHLIFY_URL . 'assets/twofactor/passkey.js', array(), AUTHLIFY_VERSION, true);
        wp_enqueue_script('authlify-twofactor-login', AUTHLIFY_URL . 'assets/twofactor/login.js', array('authlify-passkey'), AUTHLIFY_VERSION, true);
        wp_localize_script('authlify-twofactor-login', 'authlifyLogin', array(
            'optionsUrl' => add_query_arg('action', self::PASSKEY_OPTIONS, wp_login_url()),
            'loginUrl' => add_query_arg('action', self::PASSKEY_LOGIN, wp_login_url()),
            'button' => $button,
            'i18n' => array(
                'waiting' => __('Follow the prompt from your browser or device.', 'modify-login'),
                'cancelled' => __('The passkey was not used: the prompt was closed, or it could not verify you (a PIN or fingerprint may be needed). Try again, or sign in with your password.', 'modify-login'),
                'failed' => __('Passkey sign-in did not work. Try again, or sign in with your password.', 'modify-login'),
                'insecure' => __('Passkeys need a secure (https) connection.', 'modify-login'),
                'signing' => __('Signing you in…', 'modify-login'),
            ),
        ));
    }

    /**
     * Messages on the login form after a redirect from the second step.
     *
     * @param \WP_Error $errors Errors.
     * @return \WP_Error
     */
    public static function login_errors($errors)
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $two = isset($_GET['authlify_2fa']) ? sanitize_key(wp_unslash($_GET['authlify_2fa'])) : '';
        $passkey = isset($_GET['authlify_passkey']) ? sanitize_key(wp_unslash($_GET['authlify_passkey'])) : '';
        // phpcs:enable

        if (!$errors instanceof \WP_Error) {
            $errors = new \WP_Error();
        }

        if ('expired' === $two) {
            $errors->add('authlify_2fa_expired', __('Your sign-in session expired. Please sign in again.', 'modify-login'), 'message');
        } elseif ('too_many' === $two) {
            // The wrong codes also count towards the address lockout: say so
            // instead of inviting a sign-in that would be refused.
            $gate = Limiter::gate_error();
            $errors->add('authlify_2fa_failed', $gate ? $gate->get_error_message() : __('<strong>Error:</strong> Too many wrong codes. Please sign in again.', 'modify-login'));
        }

        if ('failed' === $passkey) {
            $errors->add('authlify_passkey_failed', __('<strong>Error:</strong> That passkey could not be verified. Try again, or sign in with your password.', 'modify-login'));
        } elseif ('locked' === $passkey) {
            $gate = Limiter::gate_error();
            $errors->add('authlify_passkey_failed', $gate ? $gate->get_error_message() : __('<strong>Error:</strong> Please try again later.', 'modify-login'));
        } elseif ('refused' === $passkey) {
            $errors->add('authlify_passkey_failed', __('<strong>Error:</strong> Your passkey was recognized, but this account cannot sign in from here or at this time. Please contact the site admin.', 'modify-login'));
        }

        return $errors;
    }

    /**
     * Shake the form on our errors.
     *
     * @param array $codes Codes.
     * @return array
     */
    public static function shake_codes($codes)
    {
        return array_merge((array) $codes, array('authlify_2fa_failed', 'authlify_2fa_api', 'authlify_passkey_failed', 'authlify_2fa_throttled', 'authlify_sign_in_blocked'));
    }
}
