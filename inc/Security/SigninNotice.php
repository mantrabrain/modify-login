<?php
/**
 * "New sign-in" emails.
 *
 * @package Authlify
 */

namespace Authlify\Security;

use Authlify\Log\Log;
use Authlify\Net\Ip;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Emails a user when their account signs in from a device or IP address it
 * has not used before. Off by default; the admin picks the roles.
 *
 * - "Device" is the browser and operating system (version numbers ignored),
 *   "address" is the IP (an IPv6 address counts as its /64, as for lockouts).
 * - The first sign-in after the feature is turned on is only remembered, so
 *   switching it on does not email everyone.
 * - At most one email per account every 15 minutes.
 * - Runs after two-factor login has finished.
 * - When Authlify Pro's new-device or new-country alerts cover the user, Pro
 *   sends its own email (with "This wasn't me"), and this one is skipped.
 *
 * @since 3.1.0
 */
final class SigninNotice
{
    /**
     * User meta: devices and addresses seen, each key => last-seen time.
     */
    const META = 'authlify_signin_seen';

    /**
     * Entries kept per list.
     */
    const KEEP = 20;

    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('wp_login', array(__CLASS__, 'on_login'), 40, 2);
    }

    /**
     * Whether Authlify Pro's device alerts are on (and, for a user, cover them).
     *
     * @param \WP_User|null $user User, or null for "on at all".
     * @return bool
     */
    public static function pro_handles($user = null)
    {
        if (!class_exists('AuthlifyPro\Alerts\Alerts') || (!Settings::get('pro_alert_new_device', false) && !Settings::get('pro_alert_new_country', false))) {
            return false;
        }

        if (!$user instanceof \WP_User) {
            return true;
        }

        $roles = (array) Settings::get('pro_alert_roles', array());

        return !$roles || (bool) array_intersect((array) $user->roles, $roles);
    }

    /**
     * Whether the notice covers a user (feature on, role chosen).
     *
     * @param \WP_User $user User.
     * @return bool
     */
    public static function covers($user)
    {
        if (!$user instanceof \WP_User || !Settings::get('signin_notice', false)) {
            return false;
        }

        return (bool) array_intersect((array) $user->roles, (array) Settings::get('signin_notice_roles', array()));
    }

    /**
     * After a completed sign-in.
     *
     * @param string   $login Login.
     * @param \WP_User $user  User.
     */
    public static function on_login($login, $user = null)
    {
        if (!self::covers($user)) {
            return;
        }

        $ip = Ip::client();
        $device = self::device();
        $result = self::remember($user->ID, $device['key'], Ip::valid($ip) ? Limiter::ip_key($ip) : '');
        if (!$result['new'] || self::pro_handles($user)) {
            return;
        }

        $info = array('ip' => $ip, 'device' => $device['label'], 'new_device' => $result['new_device'], 'new_ip' => $result['new_ip'], 'time' => time());

        /**
         * Filters whether the "new sign-in" email is sent.
         *
         * @param bool     $send Send.
         * @param \WP_User $user User.
         * @param array    $info ip, device, new_device, new_ip, time.
         * @since 3.1.0
         */
        if (!apply_filters('authlify_signin_notice_email', true, $user, $info)) {
            return;
        }

        $throttle = 'authlify_signin_notice_' . (int) $user->ID;
        if (get_transient($throttle)) {
            return;
        }
        set_transient($throttle, 1, 15 * MINUTE_IN_SECONDS);

        if (self::send($user, $info)) {
            Log::add('signin_notice', array(
                'user_id' => $user->ID,
                'username' => $user->user_login,
                'context' => array_filter(array('device' => $device['label'], 'new_device' => $result['new_device'], 'new_ip' => $result['new_ip'])),
            ));
        }
    }

    /**
     * Record a device and address; say whether either is new.
     *
     * @param int    $user_id User ID.
     * @param string $device  Device key.
     * @param string $address Address key.
     * @return array new, new_device, new_ip, first.
     */
    public static function remember($user_id, $device, $address)
    {
        $seen = get_user_meta((int) $user_id, self::META, true);
        $seen = is_array($seen) ? $seen : array();
        $devices = isset($seen['d']) && is_array($seen['d']) ? $seen['d'] : array();
        $addresses = isset($seen['i']) && is_array($seen['i']) ? $seen['i'] : array();
        $first = !$devices && !$addresses;

        $new_device = '' !== $device && !isset($devices[$device]);
        $new_ip = '' !== $address && !isset($addresses[$address]);

        $now = time();
        if ('' !== $device) {
            $devices[$device] = $now;
        }
        if ('' !== $address) {
            $addresses[$address] = $now;
        }
        arsort($devices);
        arsort($addresses);
        update_user_meta((int) $user_id, self::META, array(
            'd' => array_slice($devices, 0, self::KEEP, true),
            'i' => array_slice($addresses, 0, self::KEEP, true),
        ));

        return array(
            'new' => !$first && ($new_device || $new_ip),
            'new_device' => !$first && $new_device,
            'new_ip' => !$first && $new_ip,
            'first' => $first,
        );
    }

    /**
     * The visitor's browser and system, without version numbers.
     *
     * @param string|null $agent User agent (default: this request's).
     * @return array key, label.
     */
    public static function device($agent = null)
    {
        if (null === $agent) {
            $agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        }
        $agent = (string) $agent;

        $browsers = array('Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'FxiOS' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari');
        $systems = array('iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Macintosh' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux');

        $browser = '';
        foreach ($browsers as $needle => $name) {
            if (false !== strpos($agent, $needle)) {
                $browser = $name;
                break;
            }
        }
        $system = '';
        foreach ($systems as $needle => $name) {
            if (false !== strpos($agent, $needle)) {
                $system = $name;
                break;
            }
        }

        if ('' !== $browser && '' !== $system) {
            /* translators: 1: browser, 2: operating system, e.g. "Firefox on Windows" */
            $label = sprintf(__('%1$s on %2$s', 'modify-login'), $browser, $system);
        } elseif ('' !== $browser || '' !== $system) {
            $label = $browser . $system;
        } else {
            $label = __('Unknown device', 'modify-login');
        }

        // Versions change with every update: leave them out of the key.
        $key = '' === $agent ? '' : substr(md5(strtolower(preg_replace('/[0-9][0-9._]*/', '', $agent))), 0, 16);

        return array('key' => $key, 'label' => $label);
    }

    /**
     * Send the email.
     *
     * @param \WP_User $user User.
     * @param array    $info Details.
     * @return bool
     */
    private static function send($user, array $info)
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $when = wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $info['time']);

        /* translators: %s: site name */
        $subject = sprintf(__('[%s] New sign-in to your account', 'modify-login'), $site);

        $lines = array(
            /* translators: %s: user's display name */
            sprintf(__('Hi %s,', 'modify-login'), $user->display_name),
            '',
            /* translators: 1: username, 2: site name */
            sprintf(__('Your account "%1$s" on %2$s was just signed in to from a device or network it has not used before.', 'modify-login'), $user->user_login, $site),
            '',
            /* translators: %s: date and time */
            sprintf(__('When: %s', 'modify-login'), $when),
            /* translators: %s: IP address */
            sprintf(__('IP address: %s', 'modify-login'), $info['ip']),
            /* translators: %s: device, e.g. "Firefox on Windows" */
            sprintf(__('Device: %s', 'modify-login'), $info['device']),
            '',
            __('If this was you, there is nothing to do.', 'modify-login'),
            '',
            __('If it was not you, change your password now:', 'modify-login'),
            wp_lostpassword_url(),
            '',
            __('Then sign in and check your profile, and turn on two-factor login if it is not on yet.', 'modify-login'),
        );

        /**
         * Filters the "new sign-in" email.
         *
         * @param array    $mail to, subject, message.
         * @param \WP_User $user User.
         * @param array    $info ip, device, new_device, new_ip, time.
         * @since 3.1.0
         */
        $mail = (array) apply_filters('authlify_signin_notice_mail', array(
            'to' => $user->user_email,
            'subject' => $subject,
            'message' => implode("\n", $lines),
        ), $user, $info);

        if (empty($mail['to']) || !is_email($mail['to'])) {
            return false;
        }

        return (bool) wp_mail($mail['to'], (string) $mail['subject'], (string) $mail['message']);
    }
}
