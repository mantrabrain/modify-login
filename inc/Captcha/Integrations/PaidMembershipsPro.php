<?php
/**
 * Paid Memberships Pro forms.
 *
 * @package Authlify
 */

namespace Authlify\Captcha\Integrations;

use Authlify\Captcha\Captcha;
use Authlify\Captcha\Integrations;

defined('ABSPATH') || exit;

/**
 * Paid Memberships Pro's checkout (when it creates an account) and lost-password
 * form. Its login form is wp_login_form() posting to the login page and its
 * lost-password form posts there too, so core's checks already verify them;
 * what they lack is the widget on the lost-password form and any message:
 * PMPro sends a failed login back to its own page with the error code and
 * prints nothing for a code it does not know.
 *
 * Checked with Paid Memberships Pro 3.2.2.
 *
 * @since 3.2.0
 */
final class PaidMembershipsPro
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('pmpro_checkout_before_submit_button', array(__CLASS__, 'render_checkout'));
        add_filter('pmpro_registration_checks', array(__CLASS__, 'check_checkout'));
        add_action('pmpro_lost_password_before_submit_button', array(__CLASS__, 'render_lostpassword'));
        add_filter('do_shortcode_tag', array(__CLASS__, 'print_error'), 10, 2);
    }

    /**
     * Widget at checkout, for people who are not logged in (the checkout makes them an account).
     */
    public static function render_checkout()
    {
        if (!is_user_logged_in()) {
            echo Captcha::render('register'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
        }
    }

    /**
     * Stop a checkout that would create an account when the check fails.
     *
     * @param bool $continue Whether the checkout goes on.
     * @return bool
     */
    public static function check_checkout($continue)
    {
        if (!$continue || is_user_logged_in()) {
            return $continue;
        }

        $error = Captcha::check('register', sanitize_user(Integrations::posted('username')));
        if (!$error) {
            return $continue;
        }

        if (function_exists('pmpro_setMessage')) {
            pmpro_setMessage(Integrations::plain($error), 'pmpro_error');
        }

        return false;
    }

    /**
     * Widget on the lost-password form.
     */
    public static function render_lostpassword()
    {
        echo Captcha::render('lostpassword'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * Say why the login or reset was refused, on PMPro's own page.
     *
     * @param string $output Shortcode output.
     * @param string $tag    Shortcode.
     * @return string
     */
    public static function print_error($output, $tag = '')
    {
        if ('pmpro_login' !== $tag || !is_string($output)) {
            return $output;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- compared with a fixed list, only picks a fixed message.
        $codes = array();
        foreach (array('action', 'errors') as $field) {
            if (isset($_GET[$field]) && is_string($_GET[$field])) {
                $codes[] = sanitize_key(wp_unslash($_GET[$field]));
            }
        }
        // phpcs:enable

        $messages = array(
            'authlify_locked' => __('Too many failed login attempts. Please try again later.', 'modify-login'),
            'authlify_denied' => __('Access from your network is blocked.', 'modify-login'),
            'authlify_captcha' => __('The security check (CAPTCHA) failed. Please try again.', 'modify-login'),
        );
        foreach ($codes as $code) {
            if (isset($messages[$code])) {
                return '<div class="pmpro_message pmpro_error" role="alert">' . esc_html($messages[$code]) . '</div>' . $output;
            }
        }

        return $output;
    }
}
