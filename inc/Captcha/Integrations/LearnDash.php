<?php
/**
 * LearnDash registration form.
 *
 * @package Authlify
 */

namespace Authlify\Captcha\Integrations;

use Authlify\Captcha\Captcha;
use Authlify\Captcha\Integrations;

defined('ABSPATH') || exit;

/**
 * LearnDash's registration form, through its documented hooks:
 * `learndash_registration_form` (render), core's `registration_errors` (it
 * marks its post with the `learndash-registration-form` field) and
 * `learndash_registration_errors` (error code => message).
 *
 * LearnDash's login form is wp_login_form() posting to the login page, so
 * Authlify's core check already covers it. Its reset-password shortcode sends
 * its own email and has no documented hook to refuse the request, so it gets
 * no widget: a box that nothing verifies would only look safe.
 *
 * LearnDash is a paid plugin: this integration is written against its
 * developer documentation and a test stand-in, and was not run against
 * LearnDash itself.
 *
 * @since 3.2.0
 */
final class LearnDash
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('learndash_registration_form', array(__CLASS__, 'render'));
        add_filter('registration_errors', array(__CLASS__, 'check'), 20, 3);
        add_filter('learndash_registration_errors', array(__CLASS__, 'messages'));
    }

    /**
     * Widget on the registration form.
     */
    public static function render()
    {
        echo Captcha::render('register'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
    }

    /**
     * Verify the form, wherever it was posted (a repeat check reuses the result).
     *
     * @param \WP_Error $errors Errors.
     * @param string    $login  Login.
     * @param string    $email  Email.
     * @return \WP_Error
     */
    public static function check($errors, $login = '', $email = '')
    {
        if (!Integrations::is_post() || !isset($_POST['learndash-registration-form'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- LearnDash checks its own nonce.
            return $errors;
        }

        $error = Captcha::check('register', (string) $login);
        if ($error && $errors instanceof \WP_Error && !$errors->get_error_message($error->get_error_code())) {
            $errors->add($error->get_error_code(), $error->get_error_message());
        }

        return $errors;
    }

    /**
     * LearnDash shows registration errors by code.
     *
     * @param array $messages Code => message.
     * @return array
     */
    public static function messages($messages)
    {
        $messages = (array) $messages;
        $messages['authlify_captcha'] = __('The security check (CAPTCHA) failed. Please try again.', 'modify-login');

        return $messages;
    }
}
