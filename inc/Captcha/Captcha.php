<?php
/**
 * CAPTCHA module.
 *
 * @package Authlify
 */

namespace Authlify\Captcha;

use Authlify\Log\Log;
use Authlify\Net\Ip;
use Authlify\Security\Limiter;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Adds a CAPTCHA (Turnstile, hCaptcha, reCAPTCHA v2/v3 or self-hosted
 * ALTCHA) and an invisible honeypot to the chosen forms, and verifies them
 * on the server for every submission, failed or not.
 *
 * - Login is checked in `authenticate`, only for the login form itself (the
 *   core form on wp-login.php or the custom URL, or WooCommerce's form).
 *   XML-RPC, REST and application-password logins cannot show a CAPTCHA and
 *   are left to the brute-force limits.
 * - "After failures" mode shows and requires the CAPTCHA only once the
 *   visitor's IP has failed N times or the username is being targeted,
 *   evaluated again at submit time so leaving the widget out does not help.
 * - Test mode verifies and logs but never blocks.
 * - On a provider outage the admin's choice applies: let people in, or not.
 *
 * Recovery: define( 'AUTHLIFY_DISABLE_CAPTCHA', true ) in wp-config.php.
 *
 * @since 3.0.0
 */
final class Captcha
{
    /**
     * Render hooks => form key.
     */
    const RENDER_HOOKS = array(
        'login_form' => 'login',
        'register_form' => 'register',
        'lostpassword_form' => 'lostpassword',
        'comment_form_after_fields' => 'comments',
        'woocommerce_login_form' => 'woo_login',
        'woocommerce_register_form' => 'woo_register',
        'woocommerce_lostpassword_form' => 'woo_lostpassword',
        'woocommerce_after_checkout_billing_form' => 'woo_checkout',
    );

    /**
     * Verification results for this request, per form (tokens are single-use).
     *
     * @var array
     */
    private static $checked = array();

    /**
     * Wire up.
     */
    public static function init()
    {
        add_filter('authlify_log_events', array(__CLASS__, 'log_events'));
        add_filter('shake_error_codes', array(__CLASS__, 'shake_codes'));
        add_action('wp_ajax_authlify_altcha', array(Altcha::class, 'ajax_challenge'));
        add_action('wp_ajax_nopriv_authlify_altcha', array(Altcha::class, 'ajax_challenge'));

        if (is_admin()) {
            Admin::init();
        }

        if (self::disabled()) {
            return;
        }

        foreach (array_keys(self::RENDER_HOOKS) as $hook) {
            add_action($hook, array(__CLASS__, 'render_hook'));
        }
        add_filter('login_form_middle', array(__CLASS__, 'login_form_middle'), 10, 2);
        add_action('login_enqueue_scripts', array(__CLASS__, 'login_styles'));

        add_filter('authenticate', array(__CLASS__, 'check_login'), 99990, 3);
        add_filter('registration_errors', array(__CLASS__, 'check_register'), 20, 3);
        add_action('lostpassword_post', array(__CLASS__, 'check_lostpassword'), 10, 2);
        add_filter('preprocess_comment', array(__CLASS__, 'check_comment'), 1);
        add_filter('woocommerce_process_registration_errors', array(__CLASS__, 'check_woo_register'), 10, 4);
        add_action('woocommerce_after_checkout_validation', array(__CLASS__, 'check_woo_checkout'), 10, 2);
    }

    /**
     * Form keys and labels.
     *
     * @return array
     */
    public static function forms()
    {
        return array(
            'login' => __('Login', 'modify-login'),
            'register' => __('Registration', 'modify-login'),
            'lostpassword' => __('Lost password', 'modify-login'),
            'comments' => __('Comments (visitors who are not logged in)', 'modify-login'),
            'woo_login' => __('WooCommerce login', 'modify-login'),
            'woo_register' => __('WooCommerce registration', 'modify-login'),
            'woo_lostpassword' => __('WooCommerce lost password', 'modify-login'),
            'woo_checkout' => __('WooCommerce checkout (guest orders, classic checkout)', 'modify-login'),
        );
    }

    /**
     * Provider instances by key.
     *
     * @return Provider[]
     */
    public static function providers()
    {
        static $providers = null;
        if (null === $providers) {
            $providers = array();
            foreach (array(new Turnstile(), new HCaptcha(), new RecaptchaV2(), new RecaptchaV3(), new Altcha()) as $provider) {
                $providers[$provider->id()] = $provider;
            }
        }

        return $providers;
    }

    /**
     * A provider by key (default: the configured one), or null.
     *
     * @param string|null $id Provider key.
     * @return Provider|null
     */
    public static function provider($id = null)
    {
        $id = null === $id ? (string) Settings::get('captcha_provider', 'none') : (string) $id;
        $providers = self::providers();

        return isset($providers[$id]) ? $providers[$id] : null;
    }

    /**
     * Whether the CAPTCHA is switched off by constant (lockout recovery).
     *
     * @return bool
     */
    public static function disabled()
    {
        return defined('AUTHLIFY_DISABLE_CAPTCHA') && AUTHLIFY_DISABLE_CAPTCHA;
    }

    /**
     * The configured provider, when it is usable (keys present if needed).
     *
     * @return Provider|null
     */
    public static function active_provider()
    {
        $provider = self::provider();
        if (!$provider) {
            return null;
        }

        if ($provider->needs_keys() && ('' === (string) Settings::get('captcha_site_key', '') || '' === (string) Settings::get('captcha_secret_key', ''))) {
            return null;
        }

        return $provider;
    }

    /**
     * Whether anything (CAPTCHA or honeypot) protects a form for this visitor.
     *
     * @param string $form Form key.
     * @return bool
     */
    public static function form_enabled($form)
    {
        if (self::disabled() || !in_array($form, (array) Settings::get('captcha_forms', array()), true)) {
            return false;
        }

        if (!self::active_provider() && !Settings::get('honeypot', false)) {
            return false;
        }

        // Addresses on the "never lock out" list never see a CAPTCHA.
        $enabled = !Limiter::is_allowlisted(Ip::client());

        /**
         * Filters whether CAPTCHA protection applies to a form for this request.
         *
         * @param bool   $enabled Enabled.
         * @param string $form    Form key.
         * @since 3.0.0
         */
        return (bool) apply_filters('authlify_captcha_form_enabled', $enabled, $form);
    }

    /**
     * Whether the CAPTCHA widget is shown and required on a form right now.
     *
     * In "after failures" mode this applies to the login forms: registration,
     * lost-password, comment and checkout forms keep it always, because the
     * bots that abuse them never fail a login first.
     *
     * @param string $form     Form key.
     * @param string $username Submitted username, if known.
     * @return bool
     */
    public static function widget_required($form, $username = '')
    {
        if (!self::active_provider()) {
            return false;
        }

        if ('after_failures' !== Settings::get('captcha_mode', 'always') || !in_array($form, array('login', 'woo_login'), true)) {
            return true;
        }

        $after = max(1, (int) Settings::get('captcha_after', 2));
        if (Limiter::ip_failures(Ip::client()) >= $after) {
            return true;
        }

        $username = trim((string) $username);

        return '' !== $username && Limiter::user_is_targeted($username);
    }

    /**
     * Render for the current action hook.
     */
    public static function render_hook()
    {
        $hook = current_action();
        if (isset(self::RENDER_HOOKS[$hook])) {
            echo self::render(self::RENDER_HOOKS[$hook]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
        }
    }

    /**
     * Add to wp_login_form() forms (front-end login forms that post to the login URL).
     *
     * @param string $html Existing HTML.
     * @param array  $args Form args.
     * @return string
     */
    public static function login_form_middle($html, $args = array())
    {
        return $html . self::render('login');
    }

    /**
     * Markup for a form.
     *
     * @param string $form Form key.
     * @return string HTML.
     */
    public static function render($form)
    {
        if (!self::form_enabled($form)) {
            return '';
        }

        if (in_array($form, array('comments', 'woo_checkout'), true) && is_user_logged_in()) {
            return '';
        }

        $inner = '';
        if (Settings::get('honeypot', false)) {
            $inner .= Honeypot::html();
        }

        $provider = self::active_provider();
        $widget = $provider && self::widget_required($form, self::submitted_username());
        if ($widget) {
            $inner .= $provider->widget_html($form);
            self::enqueue($provider);
        }

        if ('' === $inner) {
            return '';
        }

        return sprintf(
            '<div class="authlify-captcha%1$s" data-form="%2$s">%3$s</div>',
            $widget ? ' authlify-captcha--' . esc_attr($provider->id()) : ' authlify-captcha--hp-only',
            esc_attr($form),
            $inner
        );
    }

    /**
     * Username re-displayed on the form after a failed attempt.
     *
     * @return string
     */
    private static function submitted_username()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        foreach (array('log', 'username') as $field) {
            if (isset($_POST[$field]) && is_string($_POST[$field])) {
                return sanitize_user(wp_unslash($_POST[$field]));
            }
        }
        // phpcs:enable

        return '';
    }

    /**
     * Login screen stylesheet (in the head, so nothing jumps).
     */
    public static function login_styles()
    {
        foreach (array('login', 'register', 'lostpassword') as $form) {
            if (self::form_enabled($form)) {
                wp_enqueue_style('authlify-captcha', AUTHLIFY_URL . 'assets/captcha/captcha.css', array(), AUTHLIFY_VERSION);

                return;
            }
        }
    }

    /**
     * Enqueue the widget scripts (footer, only on pages that render a widget).
     *
     * @param Provider $provider Provider.
     */
    public static function enqueue(Provider $provider)
    {
        self::register_assets();
        wp_enqueue_style('authlify-captcha');
        wp_enqueue_script('authlify-captcha');

        if ('altcha' === $provider->id()) {
            wp_enqueue_script('authlify-altcha');
        }

        $url = $provider->script_url();
        if ('' !== $url) {
            wp_enqueue_script('authlify-captcha-api', $url, array('authlify-captcha'), null, array('in_footer' => true, 'strategy' => 'async')); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- provider URLs must not carry ?ver.
        }
    }

    /**
     * Register the shared scripts and styles.
     */
    public static function register_assets()
    {
        if (wp_script_is('authlify-captcha', 'registered')) {
            return;
        }

        $base = AUTHLIFY_URL . 'assets/captcha/';
        wp_register_style('authlify-captcha', $base . 'captcha.css', array(), AUTHLIFY_VERSION);
        wp_register_script('authlify-altcha', $base . 'altcha-solver.js', array(), AUTHLIFY_VERSION, array('in_footer' => true));
        wp_register_script('authlify-captcha', $base . 'captcha.js', array(), AUTHLIFY_VERSION, array('in_footer' => true));

        wp_localize_script('authlify-captcha', 'authlifyCaptchaConfig', array(
            'ajax' => admin_url('admin-ajax.php'),
            'worker' => $base . 'altcha-solver.js?ver=' . AUTHLIFY_VERSION,
            /**
             * Filters the widget theme: auto (follows prefers-color-scheme), light or dark.
             *
             * @param string $theme Theme.
             * @since 3.0.0
             */
            'theme' => (string) apply_filters('authlify_captcha_theme', 'auto'),
            'apis' => self::api_urls(),
            'i18n' => array(
                'verifying' => __('Checking that you are human…', 'modify-login'),
                'verified' => __('Verified. You are human.', 'modify-login'),
                'failed' => __('The check could not finish.', 'modify-login'),
                'retry' => __('Try again', 'modify-login'),
                'error' => __('The security check could not load. Please reload the page.', 'modify-login'),
            ),
        ));
    }

    /**
     * Script URLs per provider (used by the settings preview; v3 gets its key appended).
     *
     * @return array
     */
    private static function api_urls()
    {
        $urls = array();
        foreach (self::providers() as $id => $provider) {
            $urls[$id] = 'recaptcha_v3' === $id ? remove_query_arg('render', $provider->script_url()) : $provider->script_url();
        }

        return $urls;
    }

    /**
     * Verify a form submission.
     *
     * @param string $form     Form key.
     * @param string $username Username (login forms).
     * @return \WP_Error|null Error to show, or null to let it through.
     */
    public static function check($form, $username = '')
    {
        if (array_key_exists($form, self::$checked)) {
            return self::$checked[$form];
        }

        self::$checked[$form] = self::run_check($form, (string) $username);

        return self::$checked[$form];
    }

    /**
     * Verification.
     *
     * @param string $form     Form key.
     * @param string $username Username.
     * @return \WP_Error|null
     */
    private static function run_check($form, $username)
    {
        if (!self::form_enabled($form)) {
            return null;
        }

        if (Settings::get('honeypot', false)) {
            $reason = Honeypot::check();
            if ('' !== $reason) {
                return self::fail($form, $reason, $username, 'honeypot');
            }
        }

        if (!self::widget_required($form, $username)) {
            return null;
        }

        $provider = self::active_provider();
        $result = $provider->verify($form);

        if ($result['ok']) {
            return null;
        }

        if ($result['outage']) {
            return self::outage($form, $result['reason'], $username, $provider->id());
        }

        return self::fail($form, $result['reason'], $username, $provider->id());
    }

    /**
     * A failed check: log it, and block unless in test mode.
     *
     * @param string $form     Form key.
     * @param string $reason   Reason.
     * @param string $username Username.
     * @param string $provider Provider key or "honeypot".
     * @return \WP_Error|null
     */
    private static function fail($form, $reason, $username, $provider)
    {
        $test = (bool) Settings::get('captcha_test_mode', false);
        self::log($form, $username, array('reason' => $reason, 'provider' => $provider, 'test_mode' => $test));

        if ($test) {
            return null;
        }

        return new \WP_Error('authlify_captcha', self::message($form, $reason));
    }

    /**
     * The provider could not be reached.
     *
     * @param string $form     Form key.
     * @param string $reason   Reason.
     * @param string $username Username.
     * @param string $provider Provider key.
     * @return \WP_Error|null
     */
    private static function outage($form, $reason, $username, $provider)
    {
        $test = (bool) Settings::get('captcha_test_mode', false);
        $open = 'closed' !== Settings::get('captcha_fail', 'open');
        self::log($form, $username, array('reason' => $reason, 'provider' => $provider, 'outage' => true, 'test_mode' => $test, 'let_through' => $open || $test));

        if ($open || $test) {
            return null;
        }

        return new \WP_Error('authlify_captcha', self::message($form, 'outage'));
    }

    /**
     * Log a failure.
     *
     * @param string $form     Form key.
     * @param string $username Username.
     * @param array  $context  Context.
     */
    private static function log($form, $username, array $context)
    {
        $user = '' !== $username ? get_user_by(is_email($username) ? 'email' : 'login', $username) : false;

        Log::add('captcha_failed', array(
            'username' => $username,
            'user_id' => $user ? $user->ID : 0,
            'context' => array_filter(array_merge(array('form' => $form), $context)),
        ));
    }

    /**
     * Error message.
     *
     * @param string $form   Form key.
     * @param string $reason Reason.
     * @return string
     */
    public static function message($form, $reason)
    {
        if ('outage' === $reason) {
            $text = __('The security check could not be completed because the CAPTCHA service is not responding. Please try again in a few minutes.', 'modify-login');
        } elseif ('too_fast' === $reason) {
            $text = __('That was quicker than a person can type. Please wait a moment and submit again.', 'modify-login');
        } elseif ('missing-input-response' === $reason) {
            $text = __('Please complete the security check (CAPTCHA) and try again.', 'modify-login');
        } else {
            $text = __('The security check (CAPTCHA) failed. Please try again.', 'modify-login');
        }

        if (in_array($form, array('login', 'register', 'lostpassword'), true)) {
            $text = sprintf(
                /* translators: %s: error message */
                __('<strong>Error:</strong> %s', 'modify-login'),
                $text
            );
        }

        /**
         * Filters the CAPTCHA error message.
         *
         * @param string $text   Message (may contain <strong>).
         * @param string $form   Form key.
         * @param string $reason Reason code.
         * @since 3.0.0
         */
        return apply_filters('authlify_captcha_message', $text, $form, $reason);
    }

    /**
     * Login: core form (wp-login.php or the custom URL) and WooCommerce.
     *
     * Runs late so it applies to failed and successful password checks alike.
     *
     * @param \WP_User|\WP_Error|null $user     User.
     * @param string                  $username Username.
     * @param string                  $password Password.
     * @return \WP_User|\WP_Error|null
     */
    public static function check_login($user, $username = '', $password = '')
    {
        if ($user instanceof \WP_Error && in_array($user->get_error_code(), array('authlify_locked', 'authlify_denied'), true)) {
            return $user;
        }

        // No password, no password guessing (passkeys and other flows post without one).
        if ('' === (string) $password || self::is_api_request() || !self::is_post()) {
            return $user;
        }

        // Decide by where the request really came from: posted fields can be forged.
        if (did_action('login_init')) {
            $form = 'login';
        } elseif (self::valid_nonce('woocommerce-login-nonce', 'woocommerce-login')) {
            $form = 'woo_login';
        } else {
            return $user;
        }

        /**
         * Filters whether this login request must pass the CAPTCHA.
         *
         * @param bool   $check    Check it.
         * @param string $form     login or woo_login.
         * @param string $username Username.
         * @since 3.0.0
         */
        if (!apply_filters('authlify_captcha_check_login', true, $form, $username)) {
            return $user;
        }

        $error = self::check($form, $username);

        return $error ? $error : $user;
    }

    /**
     * Core registration (wp-login.php?action=register).
     *
     * @param \WP_Error $errors Errors.
     * @param string    $login  Login.
     * @param string    $email  Email.
     * @return \WP_Error
     */
    public static function check_register($errors, $login = '', $email = '')
    {
        if (!did_action('login_init') || !self::is_post()) {
            return $errors;
        }

        $error = self::check('register', $login);
        if ($error && $errors instanceof \WP_Error) {
            $errors->add($error->get_error_code(), $error->get_error_message());
        }

        return $errors;
    }

    /**
     * Lost password: core form and WooCommerce's.
     *
     * @param \WP_Error          $errors    Errors.
     * @param \WP_User|false $user_data User.
     */
    public static function check_lostpassword($errors, $user_data = false)
    {
        if (!self::is_post() || is_user_logged_in() && is_admin()) {
            return;
        }

        if (did_action('login_init')) {
            $form = 'lostpassword';
        } elseif (isset($_POST['wc_reset_password']) && self::valid_nonce('woocommerce-lost-password-nonce', 'lost_password')) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $form = 'woo_lostpassword';
        } else {
            return;
        }

        $error = self::check($form, $user_data instanceof \WP_User ? $user_data->user_login : '');
        if ($error && $errors instanceof \WP_Error) {
            $errors->add($error->get_error_code(), $error->get_error_message());
        }
    }

    /**
     * Comments from visitors who are not logged in.
     *
     * @param array $commentdata Comment.
     * @return array
     */
    public static function check_comment($commentdata)
    {
        if (is_user_logged_in() || is_admin() || self::is_api_request() || !self::is_post()) {
            return $commentdata;
        }

        $type = isset($commentdata['comment_type']) ? $commentdata['comment_type'] : '';
        if (in_array($type, array('pingback', 'trackback'), true)) {
            return $commentdata;
        }

        $error = self::check('comments', isset($commentdata['comment_author_email']) ? (string) $commentdata['comment_author_email'] : '');
        if ($error) {
            wp_die(
                wp_kses($error->get_error_message(), array('strong' => array())),
                esc_html__('Comment Submission Failure', 'modify-login'),
                array('response' => 403, 'back_link' => true)
            );
        }

        return $commentdata;
    }

    /**
     * WooCommerce registration form.
     *
     * @param \WP_Error $errors   Errors.
     * @param string    $username Username.
     * @param string    $password Password.
     * @param string    $email    Email.
     * @return \WP_Error
     */
    public static function check_woo_register($errors, $username = '', $password = '', $email = '')
    {
        if (!self::is_post()) {
            return $errors;
        }

        $error = self::check('woo_register', (string) $username);
        if ($error && $errors instanceof \WP_Error) {
            $errors->add($error->get_error_code(), $error->get_error_message());
        }

        return $errors;
    }

    /**
     * WooCommerce classic checkout, guest orders.
     *
     * @param array     $data   Posted data.
     * @param \WP_Error $errors Errors.
     */
    public static function check_woo_checkout($data, $errors)
    {
        if (is_user_logged_in() || self::is_api_request()) {
            return;
        }

        $error = self::check('woo_checkout', isset($data['billing_email']) ? (string) $data['billing_email'] : '');
        if ($error && $errors instanceof \WP_Error) {
            $errors->add($error->get_error_code(), $error->get_error_message());
        }
    }

    /**
     * XML-RPC, REST and HTTP-auth (application password) requests cannot show a CAPTCHA.
     *
     * @return bool
     */
    public static function is_api_request()
    {
        // A form post to the login page is never an API request, whatever headers it carries.
        if (did_action('login_init')) {
            return false;
        }

        // The same definition core uses to allow application passwords.
        return (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request());
    }

    /**
     * Whether a posted WooCommerce nonce is genuine.
     *
     * @param string $field  POST field.
     * @param string $action Nonce action.
     * @return bool
     */
    private static function valid_nonce($field, $action)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
        return !empty($_POST[$field]) && false !== wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$field])), $action);
    }

    /**
     * Whether this is a POST request.
     *
     * @return bool
     */
    private static function is_post()
    {
        return isset($_SERVER['REQUEST_METHOD']) && 'POST' === strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])));
    }

    /**
     * Shake the login form on CAPTCHA errors.
     *
     * @param array $codes Codes.
     * @return array
     */
    public static function shake_codes($codes)
    {
        $codes[] = 'authlify_captcha';

        return $codes;
    }

    /**
     * Log labels.
     *
     * @param array $events Events.
     * @return array
     */
    public static function log_events($events)
    {
        $events['captcha_failed'] = __('CAPTCHA failed', 'modify-login');

        return $events;
    }

    /**
     * Failures test mode logged (would have been blocked) since a time.
     *
     * @param int $since Unix time.
     * @return int
     */
    public static function test_mode_count($since)
    {
        global $wpdb;
        $table = Log::table();

        return (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM {$table} WHERE event = 'captcha_failed' AND created_at >= %s AND context LIKE %s AND context NOT LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            gmdate('Y-m-d H:i:s', $since),
            '%' . $wpdb->esc_like('"test_mode":true') . '%',
            '%' . $wpdb->esc_like('"outage":true') . '%'
        ));
    }
}
