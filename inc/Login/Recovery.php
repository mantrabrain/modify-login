<?php
/**
 * Never-locked-out safety nets.
 *
 * @package Authlify
 */

namespace Authlify\Login;

use Authlify\Log\Log;
use Authlify\Net\Ip;
use Authlify\Security\Limiter;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Ways back in that never need FTP:
 *
 * - wp-config.php constants: AUTHLIFY_DISABLE_HIDE restores wp-login.php,
 *   AUTHLIFY_SLUG forces a login slug (both read by Settings::all());
 * - WP-CLI: `wp authlify url`, `wp authlify unlock` (see Cli);
 * - an email with the new login URL whenever it changes;
 * - "confirm before it applies": a new slug works alongside the old one until
 *   the admin opens it once, so a typo cannot lock anyone out;
 * - an unlock-by-email link on the lockout screen.
 */
final class Recovery
{
    const UNLOCK_ACTION = 'authlify_unlock';

    /**
     * Unlock emails one account can receive an hour, whoever asks.
     */
    const UNLOCK_PER_ACCOUNT = 3;

    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('authlify_settings_updated', array(__CLASS__, 'on_settings_updated'), 10, 2);
        add_action('login_init', array(__CLASS__, 'maybe_confirm_pending'), 5);
        add_action('login_form_' . self::UNLOCK_ACTION, array(__CLASS__, 'unlock_screen'));
        add_filter('authlify_lockout_message', array(__CLASS__, 'lockout_message_link'), 10, 2);
        add_filter('login_message', array(__CLASS__, 'unlocked_message'));
        // WooCommerce My Account: ask for an unlock link without the login URL (FQA-09).
        add_action('woocommerce_before_customer_login_form', array(__CLASS__, 'woo_unlock_form'), 5);
    }

    /**
     * Start a slug change: the new slug works next to the old one until confirmed.
     *
     * @param string $slug New slug.
     * @return string Confirmation URL.
     */
    public static function start_pending($slug)
    {
        $token = wp_generate_password(20, false);

        Settings::update(array(
            'pending_slug' => $slug,
            'pending_token' => wp_hash($token),
            'pending_expires' => time() + 30 * MINUTE_IN_SECONDS,
        ));

        return add_query_arg('authlify_confirm', $token, Router::login_url(null, $slug));
    }

    /**
     * Cancel a pending slug change.
     */
    public static function cancel_pending()
    {
        Settings::update(array('pending_slug' => '', 'pending_token' => '', 'pending_expires' => 0));
    }

    /**
     * Opening the confirmation link on the new URL makes it the login URL.
     */
    public static function maybe_confirm_pending()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        if (empty($_GET['authlify_confirm'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['authlify_confirm']));
        // phpcs:enable

        $pending = Router::pending_slug();
        $stored = (string) Settings::get('pending_token', '');

        if ('' === $pending || '' === $stored || !hash_equals($stored, wp_hash($token)) || Router::request_path() !== $pending && !array_key_exists($pending, $_GET)) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        if (!current_user_can(Settings::is_network() ? 'manage_network_options' : 'manage_options')) {
            // Not logged in on this browser: log in first, then come back to this confirmation link.
            if (!is_user_logged_in() && empty($_REQUEST['redirect_to'])) { // phpcs:ignore WordPress.Security.NonceVerification
                $back = add_query_arg('authlify_confirm', rawurlencode($token), Router::login_url(null, $pending));
                wp_safe_redirect(add_query_arg('redirect_to', rawurlencode($back), Router::login_url(null, $pending)));
                exit;
            }

            return;
        }

        Settings::update(array(
            'login_slug' => $pending,
            'pending_slug' => '',
            'pending_token' => '',
            'pending_expires' => 0,
        ));

        wp_safe_redirect(add_query_arg('authlify_notice', 'slug_confirmed', \Authlify\Admin\Menu::url('login-url')));
        exit;
    }

    /**
     * Email the admins and log when the login URL changes.
     *
     * @param array $new New settings.
     * @param array $old Old settings.
     */
    public static function on_settings_updated($new, $old)
    {
        if ($new['login_slug'] === $old['login_slug']) {
            return;
        }

        Log::add('slug_changed', array(
            'user_id' => get_current_user_id(),
            'context' => array('from' => $old['login_slug'], 'to' => $new['login_slug']),
        ));

        self::email_login_url($new['login_slug']);
    }

    /**
     * Send the login URL to the site admin email and the current user.
     *
     * @param string $slug Slug ('' = back to wp-login.php).
     */
    public static function email_login_url($slug, $reminder = false)
    {
        $url = '' === $slug ? site_url('wp-login.php', 'login') : Router::login_url(null, $slug);
        $recipients = array_unique(array_filter(array(
            get_option('admin_email'),
            wp_get_current_user()->user_email,
        )));

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        /* translators: %s: site name */
        $subject = sprintf(__('[%s] Your login address', 'modify-login'), $site);

        $recover = __("If you ever lose it, add this line to wp-config.php to restore the standard wp-login.php page:\n\ndefine( 'AUTHLIFY_DISABLE_HIDE', true );\n\nor run: wp authlify url reset", 'modify-login');

        if ($reminder) {
            /* translators: 1: site name, 2: login URL */
            $body = sprintf(__("As requested, here is the login address for %1\$s:\n\n%2\$s\n\nBookmark it.", 'modify-login'), $site, $url) . "\n\n" . $recover;
        } elseif ('' === $slug) {
            /* translators: 1: site name, 2: login URL */
            $body = sprintf(__("The custom login address for %1\$s was turned off. Log in at the standard address:\n\n%2\$s", 'modify-login'), $site, $url);
        } else {
            $body = sprintf(
                /* translators: 1: site name, 2: login URL */
                __("The login address for %1\$s is now:\n\n%2\$s\n\nBookmark it.", 'modify-login'),
                $site,
                $url
            ) . (Router::is_hiding() ? ' ' . __('The old address no longer shows the login page.', 'modify-login') : '') . "\n\n" . $recover;
        }

        /**
         * Filters the login-URL email.
         *
         * @param array  $email { to, subject, message }.
         * @param string $url   New login URL.
         * @since 3.0.0
         */
        $email = apply_filters('authlify_login_url_email', array('to' => $recipients, 'subject' => $subject, 'message' => $body), $url);

        if (!empty($email['to'])) {
            wp_mail($email['to'], $email['subject'], $email['message']);
        }
    }

    /**
     * Confirmation after an unlock link was used.
     *
     * @param string $message Message.
     * @return string
     */
    public static function unlocked_message($message)
    {
        if (!empty($_GET['authlify_unlocked'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $message .= '<p class="message">' . esc_html__('Unlock link accepted. You can now log in to that account from this device.', 'modify-login') . '</p>';
        }

        return $message;
    }

    /**
     * Add an unlock link to the lockout message.
     *
     * @param string $message Message.
     * @param int    $until   Lockout end.
     * @return string
     */
    public static function lockout_message_link($message, $until)
    {
        if (!apply_filters('authlify_unlock_by_email', true)) {
            return $message;
        }

        // The link carries the login URL. Front-end forms (WooCommerce, blocks)
        // show this message to anonymous visitors, so only the login page itself
        // may print it; elsewhere it would reveal the hidden address.
        if ('' !== Router::slug() && !Router::is_login_request()) {
            // A WooCommerce My Account login gets a link to the same page,
            // where the request form is shown (FQA-09).
            $woo = self::woo_unlock_url();
            if ('' !== $woo && isset($_POST['login'], $_POST['username'])) { // phpcs:ignore WordPress.Security.NonceVerification
                return $message . '<br><a href="' . esc_url($woo) . '">' . esc_html__('Is this your account? Email me an unlock link.', 'modify-login') . '</a>';
            }

            return $message;
        }

        $url = add_query_arg('action', self::UNLOCK_ACTION, wp_login_url());

        return $message . '<br><a href="' . esc_url($url) . '">' . esc_html__('Is this your account? Email me an unlock link.', 'modify-login') . '</a>';
    }

    /**
     * The My Account address that shows the unlock request form, or '' without WooCommerce.
     *
     * @return string
     * @since 3.0.2
     */
    public static function woo_unlock_url()
    {
        if (!function_exists('wc_get_page_permalink')) {
            return '';
        }
        $page = (string) wc_get_page_permalink('myaccount');

        return '' !== $page ? add_query_arg('authlify_unlock', '1', $page) : '';
    }

    /**
     * WooCommerce My Account (logged out, ?authlify_unlock=1): the same unlock
     * request as the login page, so customers never need the login URL. The
     * emailed link goes to the account's own address only.
     *
     * @since 3.0.2
     */
    public static function woo_unlock_form()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        if (is_user_logged_in() || empty($_GET['authlify_unlock']) || !apply_filters('authlify_unlock_by_email', true)) {
            return;
        }

        $sent = false;
        if (isset($_POST['authlify_unlock_login'], $_POST['authlify_unlock_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['authlify_unlock_nonce'])), self::UNLOCK_ACTION)) {
            self::send_unlock_email(sanitize_text_field(wp_unslash($_POST['authlify_unlock_login'])), Ip::client());
            $sent = true;
        }
        // phpcs:enable

        $text = $sent
            ? __('If that account exists, an unlock link is on its way to its email address. The link works from this device for 30 minutes.', 'modify-login')
            : __('Locked out after too many attempts? Enter your username or email address and we will email you a link that lets you log in to your account from this device.', 'modify-login');
        ?>
        <div class="authlify-woo-unlock" style="margin-bottom:2em">
            <div class="woocommerce-info" role="status"><?php echo esc_html($text); ?></div>
            <?php if (!$sent) : ?>
                <form method="post" class="woocommerce-form authlify-woo-unlock__form" action="<?php echo esc_url(self::woo_unlock_url()); ?>">
                    <p class="woocommerce-form-row form-row">
                        <label for="authlify_unlock_login"><?php esc_html_e('Username or Email Address', 'modify-login'); ?></label>
                        <input type="text" class="woocommerce-Input input-text" name="authlify_unlock_login" id="authlify_unlock_login" autocomplete="username" required>
                    </p>
                    <input type="hidden" name="authlify_unlock_nonce" value="<?php echo esc_attr(wp_create_nonce(self::UNLOCK_ACTION)); ?>">
                    <p><button type="submit" class="woocommerce-button button<?php echo function_exists('wc_wp_theme_get_element_class_name') ? ' ' . esc_attr(wc_wp_theme_get_element_class_name('button')) : ''; ?>"><?php esc_html_e('Email unlock link', 'modify-login'); ?></button></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * wp-login.php?action=authlify_unlock: request and use unlock links.
     */
    public static function unlock_screen()
    {
        $ip = Ip::client();
        $messages = '';
        $errors = new \WP_Error();

        // phpcs:disable WordPress.Security.NonceVerification
        if (!empty($_GET['unlock_key']) && !empty($_GET['u'])) {
            $user_id = (int) $_GET['u'];
            $key = sanitize_text_field(wp_unslash($_GET['unlock_key']));
            $stored = self::unlock_keys($user_id);
            $hash = wp_hash($key . '|' . $user_id);
            $match = null;
            foreach ($stored as $i => $item) {
                if (hash_equals($item['hash'], $hash) && $item['expires'] > time()) {
                    $match = $i;
                }
            }

            if (null !== $match) {
                // Single use: only this link is spent; other valid links stay valid.
                unset($stored[$match]);
                self::save_unlock_keys($user_id, $stored);
                // Only this account may log in from the locked address; the lockout
                // itself stays, so the link cannot be used to reset an attack.
                Limiter::grant_pass($ip, $user_id);
                wp_safe_redirect(add_query_arg('authlify_unlocked', '1', wp_login_url()));
                exit;
            }

            $errors->add('invalid', __('This unlock link is invalid or has expired. Request a new one below.', 'modify-login'));
        } elseif ('POST' === (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') && isset($_POST['user_login'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            check_admin_referer(self::UNLOCK_ACTION);
            $login = sanitize_text_field(wp_unslash($_POST['user_login']));
            self::send_unlock_email($login, $ip);
            $messages = __('If that account exists, an unlock link is on its way to its email address. The link works from this device for 30 minutes.', 'modify-login');
        }
        // phpcs:enable

        // Like core's lost-password screen: the explanation sits in the message box above the form.
        if ('' === $messages && !$errors->has_errors()) {
            $messages = __('Locked out after too many attempts? Enter your username or email address and we will email you a link that lets you log in to your account from this device.', 'modify-login');
        }

        login_header(__('Unlock your account', 'modify-login'), '' !== $messages ? '<p class="message">' . esc_html($messages) . '</p>' : '', $errors);
        ?>
        <form name="authlify-unlock" id="lostpasswordform" action="<?php echo esc_url(add_query_arg('action', self::UNLOCK_ACTION, wp_login_url())); ?>" method="post">
            <p>
                <label for="user_login"><?php esc_html_e('Username or Email Address', 'modify-login'); ?></label>
                <input type="text" name="user_login" id="user_login" class="input" value="" size="20" autocapitalize="off" autocomplete="username" required="required" />
            </p>
            <?php wp_nonce_field(self::UNLOCK_ACTION); ?>
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Email unlock link', 'modify-login'); ?>" />
            </p>
        </form>
        <p id="nav"><a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to log in', 'modify-login'); ?></a></p>
        <?php
        login_footer('user_login');
        exit;
    }

    /**
     * Valid unlock keys for a user.
     *
     * @param int $user_id User ID.
     * @return array[] Each: hash, expires.
     */
    private static function unlock_keys($user_id)
    {
        $stored = get_transient('authlify_unlock_' . (int) $user_id);
        if (!is_array($stored)) {
            return array();
        }
        // 3.0 betas stored one key: array( 'hash' => … ).
        if (isset($stored['hash'])) {
            $stored = array(array('hash' => $stored['hash'], 'expires' => time() + 30 * MINUTE_IN_SECONDS));
        }

        $keys = array();
        foreach ($stored as $item) {
            if (is_array($item) && isset($item['hash'], $item['expires']) && (int) $item['expires'] > time()) {
                $keys[] = array('hash' => (string) $item['hash'], 'expires' => (int) $item['expires']);
            }
        }

        return $keys;
    }

    /**
     * Store a user's unlock keys.
     *
     * @param int     $user_id User ID.
     * @param array[] $keys    Keys.
     */
    private static function save_unlock_keys($user_id, array $keys)
    {
        $keys = array_values($keys);
        if (!$keys) {
            delete_transient('authlify_unlock_' . (int) $user_id);

            return;
        }

        set_transient('authlify_unlock_' . (int) $user_id, $keys, 30 * MINUTE_IN_SECONDS);
    }

    /**
     * Email an unlock link (rate-limited, silent when the user does not exist).
     *
     * @param string $login Username or email.
     * @param string $ip    Requesting IP.
     */
    private static function send_unlock_email($login, $ip)
    {
        // Nothing to unlock: no email (and nothing counted), so a neighbour on
        // a shared address cannot use up the quota while nobody is locked out.
        if (Limiter::locked_until($ip) <= time() && Limiter::account_paused_until($login) <= time()) {
            return;
        }

        $user = get_user_by(is_email($login) ? 'email' : 'login', $login);

        // Quotas, per address counted the way lockouts are (an IPv6 /64 is one
        // address): 3 an hour per account and address, 10 an hour per address
        // overall (so one address cannot flood every account's inbox), and 3
        // an hour per account from anywhere (so rotating addresses cannot either).
        $ip_id = Limiter::ip_key($ip);
        $ip_key = 'authlify_unlock_rate_' . md5($ip_id);
        $pair_key = 'authlify_unlock_rate_' . md5($ip_id . '|' . ($user ? $user->ID : strtolower($login)));
        $user_key = $user ? 'authlify_unlock_rate_u' . $user->ID : '';
        $ip_sent = (int) get_transient($ip_key);
        $pair_sent = (int) get_transient($pair_key);
        $user_sent = '' !== $user_key ? (int) get_transient($user_key) : 0;
        if ($ip_sent >= 10 || $pair_sent >= 3 || $user_sent >= self::UNLOCK_PER_ACCOUNT) {
            return;
        }
        set_transient($ip_key, $ip_sent + 1, HOUR_IN_SECONDS);
        set_transient($pair_key, $pair_sent + 1, HOUR_IN_SECONDS);

        if (!$user) {
            return;
        }
        set_transient($user_key, $user_sent + 1, HOUR_IN_SECONDS);

        // Earlier links stay valid: expired keys are dropped, and the
        // per-account cap (3 an hour, links last 30 minutes) keeps the number
        // of live keys under the 5 kept, so a new request never pushes out a
        // link the owner was emailed.
        $key = wp_generate_password(32, false);
        $keys = array_values(array_filter(self::unlock_keys($user->ID), function ($item) {
            return isset($item['expires']) && $item['expires'] > time();
        }));
        $keys[] = array('hash' => wp_hash($key . '|' . $user->ID), 'expires' => time() + 30 * MINUTE_IN_SECONDS);
        self::save_unlock_keys($user->ID, array_slice($keys, -5));

        $url = add_query_arg(array(
            'action' => self::UNLOCK_ACTION,
            'u' => $user->ID,
            'unlock_key' => $key,
        ), wp_login_url());

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        wp_mail(
            $user->user_email,
            /* translators: %s: site name */
            sprintf(__('[%s] Unlock your login', 'modify-login'), $site),
            sprintf(
                /* translators: 1: site name, 2: unlock URL */
                __("Someone (hopefully you) was locked out of %1\$s after too many failed login attempts.\n\nOpen this link on the same device, then log in to your account as usual (valid for 30 minutes; other accounts stay locked out from that address):\n\n%2\$s\n\nIf this was not you, ignore this email. Your password was not changed.", 'modify-login'),
                $site,
                $url
            )
        );
    }
}
