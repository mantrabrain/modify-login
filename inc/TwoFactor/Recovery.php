<?php
/**
 * Two-factor recovery by email link.
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

use Authlify\Log\Log;
use Authlify\Net\Ip;
use Authlify\Security\Limiter;

defined('ABSPATH') || exit;

/**
 * A way back in for people who lost their phone, passkey and backup codes.
 *
 * - Asking: on the second step ("Can't use your methods? Email me a recovery
 *   link"), which is reached only with the right password. Accounts whose
 *   password is refused (Authlify Pro passkey-only roles) use the request form
 *   at action=authlify_2fa_recover, which also needs the password and answers
 *   the same way whatever was typed, so it never reveals whether an account
 *   exists. The password is checked with wp_authenticate(), so the CAPTCHA,
 *   the brute-force limits and add-on access rules apply as on the login form.
 * - The link holds a random single-use token (only its HMAC is stored), works
 *   for 15 minutes and replaces any earlier link. Each account gets at most 3
 *   links an hour, and each address 5 requests an hour.
 * - Opening the link shows a confirm button (mail scanners that follow links
 *   do not use it up). Using it signs the person in, keeps their two-factor
 *   methods (nothing is switched off) and sends them to their security
 *   settings to set up a new method and remove the lost one.
 * - Every request and use is logged, and using a link emails the site admin.
 * - Using a link goes through LoginFlow::sign_in_error() and the
 *   `authlify_recovery_login_user` filter (Authlify Pro's access rules), and a
 *   pending link stops working when the password changes or 2FA is reset.
 *
 * Turn it off with the `authlify_twofactor_recovery_enabled` filter.
 *
 * @since 3.0.0
 */
final class Recovery
{
    const ACTION = 'authlify_2fa_recover';
    const META = 'authlify_2fa_recovery';
    const META_RATE = 'authlify_2fa_recovery_rate';
    const TTL = 900;
    const USER_LIMIT = 3;
    const IP_LIMIT = 5;

    /**
     * Wire up.
     *
     * @since 3.0.0
     */
    public static function init()
    {
        add_filter('authlify_log_events', array(__CLASS__, 'log_events'));
        add_action('login_form_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('after_password_reset', array(__CLASS__, 'forget'));
        add_action('profile_update', array(__CLASS__, 'password_changed'), 10, 2);
    }

    /**
     * Void a pending recovery link.
     *
     * @param \WP_User|int $user User or ID.
     * @since 3.0.0
     */
    public static function forget($user)
    {
        $user_id = $user instanceof \WP_User ? $user->ID : (int) $user;
        if ($user_id) {
            delete_user_meta($user_id, self::META);
        }
    }

    /**
     * A new password voids a pending recovery link (it was asked for with the old one).
     *
     * @param int           $user_id  User ID.
     * @param \WP_User|null $old_data User before the update.
     */
    public static function password_changed($user_id, $old_data = null)
    {
        $user = get_userdata((int) $user_id);
        if ($user && $old_data instanceof \WP_User && $old_data->user_pass !== $user->user_pass) {
            self::forget($user_id);
        }
    }

    /**
     * Log labels.
     *
     * @param array $events Events.
     * @return array
     */
    public static function log_events($events)
    {
        return array_merge($events, array(
            'twofa_recovery_sent' => __('Two-factor recovery link sent', 'modify-login'),
            'twofa_recovery_used' => __('Signed in with a two-factor recovery link', 'modify-login'),
            'twofa_recovery_refused' => __('Two-factor recovery refused', 'modify-login'),
        ));
    }

    /**
     * Whether email recovery is available.
     *
     * @return bool
     * @since 3.0.0
     */
    public static function enabled()
    {
        /**
         * Filters whether people can get a two-factor recovery link by email.
         *
         * @param bool $enabled Enabled (default true).
         * @since 3.0.0
         */
        return TwoFactor::runs() && (bool) apply_filters('authlify_twofactor_recovery_enabled', true);
    }

    /**
     * Whether the second step offers a recovery link (after a password only).
     *
     * @param \WP_User $user   User.
     * @param array    $record Pending login record.
     * @return bool
     * @since 3.0.0
     */
    public static function offered_for($user, array $record)
    {
        return self::enabled() && !empty($record['recover']) && is_email($user->user_email);
    }

    /**
     * URL of the request form.
     *
     * @return string
     * @since 3.0.0
     */
    public static function request_url()
    {
        return add_query_arg('action', self::ACTION, wp_login_url());
    }

    /**
     * "Email me a recovery link" on the second step.
     *
     * @param \WP_User $user   User.
     * @param array    $record Pending login record.
     * @param array    $args   redirect_to, remember, interim.
     * @return string Message for the step.
     * @since 3.0.0
     */
    public static function from_step($user, array $record, array $args)
    {
        if (!self::offered_for($user, $record)) {
            return __('A recovery link is not available for this sign-in. Sign in with your password to ask for one.', 'modify-login');
        }

        $sent = self::send($user, 'step');
        if ('limited' === $sent) {
            return __('We already sent several recovery links in the last hour. Use the newest email, or try again later.', 'modify-login');
        }
        if (true !== $sent) {
            return __('The email could not be sent. Please contact the site admin.', 'modify-login');
        }

        return sprintf(
            /* translators: %s: masked email address */
            __('We emailed a recovery link to %s. It works once, for 15 minutes.', 'modify-login'),
            self::mask($user->user_email)
        );
    }

    /**
     * j•••@example.com.
     *
     * @param string $email Email.
     * @return string
     */
    private static function mask($email)
    {
        $at = strrpos((string) $email, '@');

        return false === $at || $at < 1 ? '•••' : substr((string) $email, 0, 1) . '•••' . substr((string) $email, $at);
    }

    /**
     * HMAC of a token.
     *
     * @param int    $user_id User ID.
     * @param string $token   Token.
     * @return string
     */
    private static function hash($user_id, $token)
    {
        return hash_hmac('sha256', 'recover|' . (int) $user_id . '|' . $token, wp_salt('auth'));
    }

    /**
     * Count a hit on a per-hour budget.
     *
     * @param string $key   Transient key.
     * @param int    $limit Limit.
     * @return bool Whether this hit is allowed.
     */
    private static function budget($key, $limit)
    {
        $hits = get_transient($key);
        if (!is_array($hits) || (int) $hits['start'] < time() - HOUR_IN_SECONDS) {
            $hits = array('start' => time(), 'count' => 0);
        }
        $hits['count']++;
        set_transient($key, $hits, HOUR_IN_SECONDS);

        return $hits['count'] <= $limit;
    }

    /**
     * Create and email a recovery link.
     *
     * @param \WP_User $user User.
     * @param string   $via  step or form.
     * @return true|string|false True when sent, 'limited', or false when mail failed.
     * @since 3.0.0
     */
    public static function send($user, $via)
    {
        if (!self::budget('authlify_2fa_rec_u_' . (int) $user->ID, self::USER_LIMIT)) {
            Log::add('twofa_recovery_refused', array('user_id' => $user->ID, 'username' => $user->user_login, 'context' => array('reason' => 'rate_limited', 'via' => $via)));

            return 'limited';
        }

        $token = wp_generate_password(43, false);
        update_user_meta($user->ID, self::META, array(
            'hash' => self::hash($user->ID, $token),
            'expires' => time() + self::TTL,
            'ip' => Ip::client(),
        ));

        $url = add_query_arg(array(
            'action' => self::ACTION,
            'uid' => (int) $user->ID,
            'rkey' => $token,
        ), wp_login_url());

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $ok = self::mail($user->user_email, 'twofa_recovery', array(
            'subject' => __('Your two-factor recovery link', 'modify-login'),
            'heading' => __('Sign in without your second step', 'modify-login'),
            'lines' => array(
                /* translators: %s: site name */
                sprintf(__('Someone (hopefully you) signed in to %s with your password and asked for a recovery link, because they cannot use their two-factor method.', 'modify-login'), $site),
                __('The link works once, for 15 minutes. It signs you in and opens your security settings, so you can set up a new method and remove the one you lost.', 'modify-login'),
            ),
            'button' => array(__('Sign in and set up again', 'modify-login'), $url),
            'after' => array(
                __('If this was not you, someone knows your password. Change it now, and do not open the link.', 'modify-login'),
            ),
            /* translators: %s: IP address */
            'footer' => sprintf(__('Requested from %s.', 'modify-login'), Ip::client()),
        ));

        if (!$ok) {
            delete_user_meta($user->ID, self::META);
            Log::add('twofa_recovery_refused', array('user_id' => $user->ID, 'username' => $user->user_login, 'context' => array('reason' => 'mail_failed', 'via' => $via)));

            return false;
        }

        Log::add('twofa_recovery_sent', array('user_id' => $user->ID, 'username' => $user->user_login, 'context' => array('via' => $via)));

        return true;
    }

    /**
     * Send an email (plain text; Authlify Pro sends its branded version).
     *
     * @param string $to      Address.
     * @param string $type    Type key.
     * @param array  $message subject, heading, lines, button (label, url), after, footer.
     * @return bool
     */
    private static function mail($to, $type, array $message)
    {
        /**
         * Short-circuits a two-factor recovery email (Authlify Pro sends it with
         * its branded template). Return true or false to say whether it was sent.
         *
         * @param bool|null $sent    Null to let Authlify send it.
         * @param string    $to      Address.
         * @param string    $type    twofa_recovery or twofa_recovery_admin.
         * @param array     $message subject, heading, lines, button, after, footer.
         * @since 3.0.0
         */
        $sent = apply_filters('authlify_twofactor_recovery_mail', null, $to, $type, $message);
        if (null !== $sent) {
            return (bool) $sent;
        }

        $body = implode("\n\n", (array) $message['lines']);
        if (!empty($message['button'])) {
            $body .= "\n\n" . $message['button'][1];
        }
        if (!empty($message['after'])) {
            $body .= "\n\n" . implode("\n\n", (array) $message['after']);
        }
        if (!empty($message['footer'])) {
            $body .= "\n\n-- \n" . $message['footer'];
        }

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        /* translators: 1: site name, 2: email subject */
        return (bool) wp_mail($to, sprintf(__('[%1$s] %2$s', 'modify-login'), $site, $message['subject']), $body);
    }

    /**
     * The action=authlify_2fa_recover request: the request form, the confirm
     * page for a link, and using the link.
     */
    public static function handle()
    {
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);

        if (!self::enabled()) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        // phpcs:disable WordPress.Security.NonceVerification -- the emailed token is the check for links; the form has its own nonce.
        $uid = isset($_REQUEST['uid']) ? absint($_REQUEST['uid']) : 0;
        $key = isset($_REQUEST['rkey']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_REQUEST['rkey'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        // phpcs:enable

        if ($uid && '' !== $key) {
            self::link($uid, $key);
        }

        self::form();
    }

    /**
     * Whether a link is valid (without using it).
     *
     * @param int    $uid User ID.
     * @param string $key Token.
     * @return \WP_User|false
     */
    private static function check_link($uid, $key)
    {
        $user = get_userdata((int) $uid);
        $record = $user ? get_user_meta($user->ID, self::META, true) : false;

        if (!$user || !is_array($record) || empty($record['hash']) || (int) $record['expires'] < time()) {
            return false;
        }

        return hash_equals((string) $record['hash'], self::hash($user->ID, $key)) ? $user : false;
    }

    /**
     * Confirm page (GET) and use (POST) of a link.
     *
     * @param int    $uid User ID.
     * @param string $key Token.
     */
    private static function link($uid, $key)
    {
        $post = 'POST' === (isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET');
        $user = self::check_link($uid, $key);

        if (!$user) {
            Log::add('twofa_recovery_refused', array('user_id' => (int) $uid, 'context' => array('reason' => 'invalid_link')));
            self::screen(new \WP_Error('authlify_2fa_recovery', __('<strong>Error:</strong> This recovery link is invalid, used or expired. Links work once, for 15 minutes. Ask for a new one.', 'modify-login')));
        }

        $gate = Limiter::gate_error();
        if ($gate) {
            self::screen($gate);
        }

        if (!$post) {
            wp_enqueue_style('authlify-twofactor-login', AUTHLIFY_URL . 'assets/twofactor/login.css', array(), AUTHLIFY_VERSION);
            login_header(__('Account recovery', 'modify-login'));
            ?>
            <form name="authlify_2fa_recover" id="loginform" class="authlify-2fa authlify-2fa-recover" action="<?php echo esc_url(add_query_arg('action', self::ACTION, wp_login_url())); ?>" method="post">
                <input type="hidden" name="uid" value="<?php echo esc_attr((string) $user->ID); ?>">
                <input type="hidden" name="rkey" value="<?php echo esc_attr($key); ?>">
                <h2 class="authlify-2fa__title"><?php esc_html_e('Sign in without your second step', 'modify-login'); ?></h2>
                <p><?php echo esc_html(sprintf(
                    /* translators: %s: username */
                    __('This signs you in as %s once. Your two-factor methods stay on: you go straight to your security settings to set up a new one and remove the one you lost.', 'modify-login'),
                    $user->user_login
                )); ?></p>
                <p class="submit"><input type="submit" class="button button-primary button-large" value="<?php esc_attr_e('Sign in and set up again', 'modify-login'); ?>"></p>
            </form>
            <p id="nav"><a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to sign in', 'modify-login'); ?></a></p>
            <?php
            login_footer();
            exit;
        }

        // Only the confirm page on this site may use the link (no login CSRF).
        if (LoginFlow::cross_site_post()) {
            self::screen(new \WP_Error('authlify_2fa_recovery', __('<strong>Error:</strong> Open the link from your email again and press the button on that page.', 'modify-login')));
        }

        if (is_multisite() && is_user_spammy($user)) {
            delete_user_meta($user->ID, self::META);
            self::screen(new \WP_Error('spammer_account', __('<strong>Error:</strong> Your account has been marked as a spammer.', 'modify-login')));
        }

        // The same checks as other sign-ins without a password: blocked
        // accounts, and add-on rules such as countries and login hours. The
        // link stays valid, so it can be used once the rule allows it.
        $allowed = LoginFlow::sign_in_error($user, 'recovery');

        /**
         * Filters the user a two-factor recovery link signs in (return a
         * WP_Error to refuse). Authlify Pro applies its access rules here.
         *
         * @param \WP_User|\WP_Error $user User.
         * @since 3.0.0
         */
        $allowed = apply_filters('authlify_recovery_login_user', $allowed ? $allowed : $user);
        if (!$allowed instanceof \WP_User) {
            $error = is_wp_error($allowed) ? $allowed : new \WP_Error('authlify_2fa_recovery', __('<strong>Error:</strong> This account cannot sign in right now.', 'modify-login'));
            Log::add('twofa_recovery_refused', array('user_id' => $user->ID, 'username' => $user->user_login, 'context' => array('reason' => $error->get_error_code())));
            self::screen($error);
        }

        // Single use, whatever happens next.
        delete_user_meta($user->ID, self::META);

        Log::add('twofa_recovery_used', array('user_id' => $user->ID, 'username' => $user->user_login, 'context' => array('ip' => Ip::client())));
        self::notify_admin($user);

        LoginFlow::sign_in($user, false, 'recovery');

        $url = add_query_arg('authlify_recovered', 1, TwoFactor::settings_url($user));
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Tell the site admin a recovery link was used.
     *
     * @param \WP_User $user User.
     */
    private static function notify_admin($user)
    {
        $admin = (string) get_option('admin_email');
        if (!is_email($admin)) {
            return;
        }

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        self::mail($admin, 'twofa_recovery_admin', array(
            /* translators: %s: username */
            'subject' => sprintf(__('%s signed in with a two-factor recovery link', 'modify-login'), $user->user_login),
            'heading' => __('Two-factor recovery link used', 'modify-login'),
            'lines' => array(
                /* translators: 1: username, 2: email address, 3: IP address */
                sprintf(__('%1$s (%2$s) signed in with an emailed recovery link instead of their two-factor method, from %3$s.', 'modify-login'), $user->user_login, $user->user_email, Ip::client()),
                __('Their two-factor methods were not removed. If this looks wrong, reset their password and sessions from the Users screen.', 'modify-login'),
            ),
            'button' => array(__('View the user', 'modify-login'), add_query_arg('user_id', $user->ID, admin_url('user-edit.php'))),
            /* translators: %s: site name */
            'footer' => sprintf(__('Sent by Authlify on %s.', 'modify-login'), $site),
        ));
    }

    /**
     * A message screen with a way back.
     *
     * @param \WP_Error $error Error.
     */
    private static function screen($error)
    {
        login_header(__('Account recovery', 'modify-login'), '', $error);
        ?>
        <p id="nav">
            <a href="<?php echo esc_url(self::request_url()); ?>"><?php esc_html_e('Ask for a new link', 'modify-login'); ?></a> |
            <a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to sign in', 'modify-login'); ?></a>
        </p>
        <?php
        login_footer();
        exit;
    }

    /**
     * A password hash that matches nothing, so unknown accounts take as long
     * to check as real ones.
     *
     * @return string
     */
    private static function dummy_hash()
    {
        $hash = get_site_transient('authlify_2fa_rec_dummy');
        if (!is_string($hash) || '' === $hash) {
            $hash = wp_hash_password(wp_generate_password(32, true, true));
            set_site_transient('authlify_2fa_rec_dummy', $hash, WEEK_IN_SECONDS);
        }

        return $hash;
    }

    /**
     * The request form: username or email plus password. The answer is the
     * same whatever was typed.
     */
    private static function form()
    {
        $message = '';
        $error = null;
        $login = '';

        if ('POST' === (isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET')) {
            check_admin_referer(self::ACTION);
            $login = isset($_POST['log']) ? sanitize_text_field(wp_unslash($_POST['log'])) : '';
            $password = isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passwords are not sanitized.

            $gate = Limiter::gate_error();
            if ($gate) {
                $error = $gate;
            } elseif ('' === $login || '' === $password) {
                $error = new \WP_Error('authlify_2fa_recovery', __('<strong>Error:</strong> Enter your username or email address and your password.', 'modify-login'));
            } elseif (!self::budget('authlify_2fa_rec_ip_' . md5(Ip::client()), self::IP_LIMIT)) {
                $error = new \WP_Error('authlify_2fa_recovery', __('<strong>Error:</strong> Too many requests. Please try again later.', 'modify-login'));
            } else {
                // The whole `authenticate` chain, as on the login form: the
                // CAPTCHA, brute-force limits, blocked accounts and add-on
                // access rules apply, and a wrong password fires
                // wp_login_failed (the limiter counts it).
                $result = wp_authenticate($login, $password);

                if ($result instanceof \WP_User) {
                    if (TwoFactor::is_active_for($result->ID) && is_email($result->user_email)) {
                        self::send($result, 'form');
                    }
                } else {
                    $code = is_wp_error($result) ? (string) $result->get_error_code() : '';
                    if (in_array($code, array('invalid_username', 'invalid_email'), true)) {
                        // Unknown accounts take as long to check as real ones.
                        wp_check_password($password, self::dummy_hash());
                    } elseif (0 === strpos($code, 'authlify_') && 'authlify_2fa_recovery' !== $code) {
                        // CAPTCHA, lockout and access rules say why, as on the login form.
                        $error = $result;
                    }
                }

                if (!$error) {
                    $message = __('If those details are right and the account uses two-factor login, we emailed a recovery link to its address. It works once, for 15 minutes.', 'modify-login');
                }
            }
        }

        if ('' === $message && !$error) {
            $message = __('Lost your phone, passkey and backup codes? Enter your username or email address and your password, and we will email you a link that signs you in once so you can set up again.', 'modify-login');
        }

        login_header(__('Account recovery', 'modify-login'), '<p class="message">' . esc_html($message) . '</p>', $error);
        ?>
        <form name="authlify_2fa_recover" id="loginform" action="<?php echo esc_url(self::request_url()); ?>" method="post">
            <p>
                <label for="user_login"><?php esc_html_e('Username or Email Address', 'modify-login'); ?></label>
                <input type="text" name="log" id="user_login" class="input" value="<?php echo esc_attr($login); ?>" size="20" autocapitalize="off" autocomplete="username" required>
            </p>
            <div class="user-pass-wrap">
                <label for="user_pass"><?php esc_html_e('Password', 'modify-login'); ?></label>
                <input type="password" name="pwd" id="user_pass" class="input password-input" value="" size="20" autocomplete="current-password" required>
            </div>
            <?php
            // The login form's CAPTCHA and honeypot, checked by wp_authenticate() above.
            if (class_exists('\Authlify\Captcha\Captcha') && method_exists('\Authlify\Captcha\Captcha', 'render')) {
                echo \Authlify\Captcha\Captcha::render('login'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
            }
            ?>
            <?php wp_nonce_field(self::ACTION); ?>
            <p class="submit"><input type="submit" class="button button-primary button-large" value="<?php esc_attr_e('Email me a recovery link', 'modify-login'); ?>"></p>
        </form>
        <p id="nav">
            <a href="<?php echo esc_url(wp_lostpassword_url()); ?>"><?php esc_html_e('Lost your password?', 'modify-login'); ?></a> |
            <a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to sign in', 'modify-login'); ?></a>
        </p>
        <?php
        login_footer('user_login');
        exit;
    }
}
