<?php
/**
 * BuddyPress and BuddyBoss registration.
 *
 * @package Authlify
 */

namespace Authlify\Captcha\Integrations;

use Authlify\Captcha\Captcha;
use Authlify\Captcha\Integrations;

defined('ABSPATH') || exit;

/**
 * The BuddyPress (and BuddyBoss, which keeps the same hooks) sign-up page.
 * It follows the "Registration" switch. BuddyPress logins use the WordPress
 * login form, which is already covered.
 *
 * @since 3.1.0
 */
final class BuddyPress
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('bp_before_registration_submit_buttons', array(__CLASS__, 'render'));
        add_action('bp_signup_validate', array(__CLASS__, 'validate'));
    }

    /**
     * Any error, then the widget, above the "Complete Sign Up" button.
     */
    public static function render()
    {
        // BuddyPress prints a sign-up error on the action "bp_{field key}_errors".
        do_action('bp_authlify_captcha_errors');
        echo Captcha::render('register'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * Sign-up validation: BuddyPress shows each error next to its field key.
     */
    public static function validate()
    {
        if (!Integrations::is_post() || !function_exists('buddypress')) {
            return;
        }

        $error = Captcha::check('register', Integrations::posted('signup_username'));
        if (!$error) {
            return;
        }

        $bp = buddypress();
        if (isset($bp->signup) && is_object($bp->signup)) {
            if (!isset($bp->signup->errors) || !is_array($bp->signup->errors)) {
                $bp->signup->errors = array();
            }
            $bp->signup->errors['authlify_captcha'] = esc_html(Integrations::plain($error));
        }
    }
}
