<?php
/**
 * Applies the design on wp-login.php.
 *
 * @package Authlify
 */

namespace Authlify\Designer;

use Authlify\Plugin;

defined('ABSPATH') || exit;

/**
 * Login-screen side of the designer: stylesheet, body classes, the image or
 * sidebar panel element, logo link and text, custom texts, hidden links and
 * the styled logout confirmation. No JavaScript is added to the login page
 * (the live preview adds a few lines for the admin who is previewing only).
 *
 * The preview (`?authlify_preview={nonce}`) shows the admin's draft instead of
 * the saved design, and only for that logged-in admin: the nonce is tied to
 * the user's session, so the parameter does nothing for anyone else.
 *
 * @since 3.0.0
 */
final class Frontend
{
    const PREVIEW = 'authlify_preview';
    const NONCE = 'authlify_designer_preview';

    /**
     * The design used for this request.
     *
     * @var array|null
     */
    private static $design = null;

    /**
     * Whether this request is a preview.
     *
     * @var bool
     */
    private static $preview = false;

    /**
     * Whether the login screen is showing an error (not just a notice).
     *
     * @var bool
     */
    private static $has_error = false;

    /**
     * Wire up.
     *
     * @since 3.0.0
     */
    public static function init()
    {
        add_action('login_init', array(__CLASS__, 'boot'), 1);
    }

    /**
     * Decide which design applies and hook the login screen.
     *
     * @since 3.0.0
     */
    public static function boot()
    {
        self::$preview = self::is_preview();
        if (self::$preview) {
            // wp-login.php signs a visitor in again from the auth cookie
            // (wp_signon() with no credentials). Only a network installed in
            // sub-directories sends that cookie to the login page, and there
            // each preview load started a new session: the designer's REST
            // nonce went stale and every save was first refused with a 403
            // (CMPT-07). A preview only shows the form, so it never signs
            // anyone in.
            add_filter('authenticate', array(__CLASS__, 'preview_no_signon'), 31, 3);
            $draft = Design::draft(get_current_user_id());
            self::$design = $draft ? $draft : Design::saved();
            self::$design['enabled'] = true;
            nocache_headers();
        } else {
            self::$design = Design::saved();
        }

        if (!self::$design['enabled']) {
            if (get_site_option('authlify_design_legacy_header')) {
                // Modify Login 2.x linked the logo to the site and titled it with the site name.
                add_filter('login_headerurl', array(__CLASS__, 'home_url'));
                add_filter('login_headertext', array(__CLASS__, 'site_name'));
            }

            return;
        }

        $d = self::$design;

        add_action('login_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_filter('login_body_class', array(__CLASS__, 'body_class'), 20);
        add_action('login_header', array(__CLASS__, 'panel'));
        add_filter('login_headerurl', array(__CLASS__, 'logo_url'), 20);
        add_filter('login_headertext', array(__CLASS__, 'logo_text'), 20);
        add_filter('login_message', array(__CLASS__, 'intro'), 20);
        add_filter('wp_login_errors', array(__CLASS__, 'note_errors'), PHP_INT_MAX);
        add_action('login_footer', array(__CLASS__, 'footer'), 5);

        if ('' !== $d['text']['button_label']) {
            add_filter('gettext', array(__CLASS__, 'button_label'), 20, 3);
        }
        if ($d['links']['hide_language']) {
            add_filter('login_display_language_dropdown', '__return_false', 20);
        }
        if ($d['links']['hide_privacy']) {
            add_filter('the_privacy_policy_link', '__return_empty_string', 20);
        }
        if ($d['links']['hide_backtoblog']) {
            add_filter('login_site_html_link', '__return_empty_string', 20);
        }

        // wp-login.php?action=logout without a nonce ends in wp_die(); show it in the design.
        if ('logout' === self::action()) {
            add_filter('wp_die_handler', array(__CLASS__, 'die_handler_name'), 20);
        }

        if (self::$preview) {
            // The preview reloads while the admin types; it must never take keyboard focus.
            add_filter('enable_login_autofocus', '__return_false');
            add_action('login_head', array(__CLASS__, 'preview_focus_guard'), 1);
            add_action('login_footer', array(__CLASS__, 'preview_script'), 99);
            add_action('login_init', array(__CLASS__, 'preview_screen'), 50);
        }
    }

    /**
     * Preview only: refuse the cookie sign-in that wp-login.php attempts
     * with no username and password. The two "empty" codes are the ones core
     * clears on a GET, so the form shows no error.
     *
     * @param \WP_User|\WP_Error|null $user     Result so far.
     * @param string                  $username Username.
     * @param string                  $password Password.
     * @return \WP_User|\WP_Error|null
     * @since 3.0.2
     */
    public static function preview_no_signon($user, $username = '', $password = '')
    {
        if (!$user instanceof \WP_User || '' !== (string) $username || '' !== (string) $password || !empty($_POST)) { // phpcs:ignore WordPress.Security.NonceVerification
            return $user;
        }

        $error = new \WP_Error('empty_username', '');
        $error->add('empty_password', '');

        return $error;
    }

    /**
     * Whether this request is a valid preview for the current user.
     *
     * @return bool
     * @since 3.0.0
     */
    public static function is_preview()
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- the value is the nonce.
        if (empty($_GET[self::PREVIEW]) || !is_user_logged_in() || !current_user_can(Plugin::cap())) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        return false !== wp_verify_nonce(sanitize_text_field(wp_unslash($_GET[self::PREVIEW])), self::NONCE);
    }

    /**
     * Current wp-login.php action.
     *
     * @return string
     * @since 3.0.0
     */
    private static function action()
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        return isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login';
    }

    /**
     * Whether the interim (modal) login is showing.
     *
     * @return bool
     * @since 3.0.0
     */
    private static function interim()
    {
        global $interim_login;

        // phpcs:ignore WordPress.Security.NonceVerification
        return !empty($interim_login) || isset($_REQUEST['interim-login']);
    }

    /**
     * The design applied to this request.
     *
     * @return array|null
     * @since 3.0.0
     */
    public static function design()
    {
        return self::$design;
    }

    /**
     * Enqueue the stylesheet (cached file, or inline when uploads isn't writable or previewing).
     *
     * @since 3.0.0
     */
    public static function enqueue()
    {
        $css = Compiler::compile(self::$design);
        $url = self::$preview ? '' : Compiler::file_url($css);

        if ('' !== $url) {
            wp_enqueue_style('authlify-login-design', $url, array('login'), null); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the file name is a content hash.

            return;
        }

        wp_register_style('authlify-login-design', false, array('login'), AUTHLIFY_VERSION); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter
        wp_enqueue_style('authlify-login-design');
        wp_add_inline_style('authlify-login-design', $css);
    }

    /**
     * Body classes.
     *
     * @param string[] $classes Classes.
     * @return string[]
     * @since 3.0.0
     */
    public static function body_class($classes)
    {
        $d = self::$design;
        $classes[] = 'authlify-designed';
        $classes[] = 'authlify-layout-' . sanitize_html_class($d['layout']);
        $classes[] = 'authlify-card-' . sanitize_html_class($d['form']['card']);
        if ('' !== $d['template']) {
            $classes[] = 'authlify-template-' . sanitize_html_class($d['template']);
        }
        if (self::$preview) {
            $classes[] = 'authlify-preview';
        }

        return $classes;
    }

    /**
     * The image panel (split layouts) or side panel (sidebar layouts).
     *
     * @since 3.0.0
     */
    public static function panel()
    {
        if (self::interim()) {
            return;
        }

        if (0 === strpos(self::$design['layout'], 'split-') || 0 === strpos(self::$design['layout'], 'sidebar-')) {
            echo '<div class="authlify-art" aria-hidden="true"></div>' . "\n";
        }
    }

    /**
     * Logo link.
     *
     * @return string
     * @since 3.0.0
     */
    public static function logo_url()
    {
        $link = self::$design['logo']['link'];

        return '' !== $link ? $link : home_url('/');
    }

    /**
     * Logo text (the accessible name for image logos, the visible text for text logos).
     *
     * @return string Escaped text (core prints it as is).
     * @since 3.0.0
     */
    public static function logo_text()
    {
        $logo = self::$design['logo'];
        if ('text' === $logo['type'] && '' !== $logo['text']) {
            return esc_html($logo['text']);
        }

        return esc_html('' !== $logo['title'] ? $logo['title'] : get_bloginfo('name', 'display'));
    }

    /**
     * 2.x logo link.
     *
     * @return string
     * @since 3.0.0
     */
    public static function home_url()
    {
        return home_url();
    }

    /**
     * 2.x logo title.
     *
     * @return string
     * @since 3.0.0
     */
    public static function site_name()
    {
        return esc_html(get_bloginfo('name', 'display'));
    }

    /**
     * Custom message above the log-in form.
     *
     * @param string $message Core message.
     * @return string
     * @since 3.0.0
     */
    public static function intro($message)
    {
        $text = self::$design['text']['message'];
        if ('' === $text || 'login' !== self::action() || self::interim() || self::is_step()) {
            return $message;
        }

        return '<div class="authlify-intro"><p>' . wp_kses($text, Design::allowed_html()) . '</p></div>' . $message;
    }

    /**
     * Remember whether the log-in screen shows an error.
     *
     * @param \WP_Error $errors Errors and notices.
     * @return \WP_Error
     * @since 3.0.0
     */
    public static function note_errors($errors)
    {
        if (is_wp_error($errors)) {
            foreach ($errors->get_error_codes() as $code) {
                if (!in_array($errors->get_error_data($code), array('message', 'success'), true)) {
                    self::$has_error = true;
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Whether this is a later step of logging in (an error, the two-factor
     * step or a lockout) rather than the plain log-in screen: the welcome
     * message is only for the first screen.
     *
     * @return bool
     * @since 3.0.0
     */
    private static function is_step()
    {
        if (self::$has_error) {
            return true;
        }
        // A POST that renders a screen is a failed log-in or the two-factor step.
        if (isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD']) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        $screen = isset($_GET['authlify_screen']) ? sanitize_key(wp_unslash($_GET['authlify_screen'])) : '';

        return self::$preview && in_array($screen, array('2fa', 'lockout'), true);
    }

    /**
     * Preview only: scripts inside the preview frame (core focuses the first
     * field on several screens) may not move keyboard focus out of the
     * designer. Focus still works once the admin clicks into the preview.
     *
     * @since 3.0.0
     */
    public static function preview_focus_guard()
    {
        wp_print_inline_script_tag('(function(){if(window.parent===window){return;}var f=HTMLElement.prototype.focus;HTMLElement.prototype.focus=function(){if(document.hasFocus()){return f.apply(this,arguments);}};})();');
    }

    /**
     * Footer text under the form.
     *
     * @since 3.0.0
     */
    public static function footer()
    {
        $text = self::$design['text']['footer'];
        if ('' === $text || self::interim()) {
            return;
        }

        echo '<div class="authlify-footer-text">' . wp_kses($text, Design::allowed_html()) . '</div>' . "\n";
    }

    /**
     * Custom label on the log-in button.
     *
     * @param string $translation Translation.
     * @param string $text        Original.
     * @param string $domain      Domain.
     * @return string
     * @since 3.0.0
     */
    public static function button_label($translation, $text, $domain)
    {
        if ('default' === $domain && 'Log In' === $text && 'login' === self::action()) {
            return self::$design['text']['button_label'];
        }

        return $translation;
    }

    /**
     * Handler name for wp_die() on the logout confirmation.
     *
     * @return callable
     * @since 3.0.0
     */
    public static function die_handler_name()
    {
        return array(__CLASS__, 'die_handler');
    }

    /**
     * Render a wp_die() message inside the designed login screen.
     *
     * @param string|\WP_Error $message Message.
     * @param string           $title   Title.
     * @param array|int        $args    Arguments.
     * @since 3.0.0
     */
    public static function die_handler($message, $title = '', $args = array())
    {
        if (!function_exists('login_header') || !function_exists('_wp_die_process_input')) {
            _default_wp_die_handler($message, $title, $args);

            return;
        }

        list($message, $title, $parsed) = _wp_die_process_input($message, $title, $args);

        if (!headers_sent()) {
            status_header($parsed['response']);
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
        }

        login_header('' !== $title ? $title : __('Log out', 'modify-login'));
        echo '<div class="message authlify-die">' . wp_kses_post('<p>' . $message . '</p>') . '</div>';
        login_footer();

        if ($parsed['exit']) {
            die();
        }
    }

    /**
     * Preview helper: live CSS updates from the designer, and links and forms disabled.
     *
     * @since 3.0.0
     */
    public static function preview_script()
    {
        $origin = wp_parse_url(admin_url(), PHP_URL_SCHEME) . '://' . wp_parse_url(admin_url(), PHP_URL_HOST) . (wp_parse_url(admin_url(), PHP_URL_PORT) ? ':' . wp_parse_url(admin_url(), PHP_URL_PORT) : '');
        $script = '(function(){var o=' . wp_json_encode($origin) . ';'
            . 'window.addEventListener("message",function(e){if(e.origin!==o||!e.data||e.data.type!=="authlify-css")return;var s=document.getElementById("authlify-login-design-inline-css");if(s){s.textContent=String(e.data.css);}});'
            . 'document.addEventListener("submit",function(e){e.preventDefault();},true);'
            . 'document.addEventListener("click",function(e){var a=e.target&&e.target.closest?e.target.closest("a"):null;if(a){e.preventDefault();}},true);'
            . 'if(window.parent!==window){window.parent.postMessage({type:"authlify-preview-ready"},o);}})();';

        wp_print_inline_script_tag($script);
    }

    /**
     * Preview of screens that cannot be reached directly (reset password,
     * registration while closed, the two-factor step, a lockout).
     *
     * @since 3.0.0
     */
    public static function preview_screen()
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        $screen = isset($_GET['authlify_screen']) ? sanitize_key(wp_unslash($_GET['authlify_screen'])) : '';
        if ('' === $screen) {
            return;
        }

        /**
         * Lets a module render its own preview screen (e.g. the two-factor step).
         * Print the screen with login_header()/login_footer() and return true.
         *
         * @param bool   $handled Whether the screen was rendered.
         * @param string $screen  Screen key.
         * @since 3.0.0
         */
        if (apply_filters('authlify_designer_preview_screen', false, $screen)) {
            exit;
        }

        switch ($screen) {
            case 'lockout':
                add_filter('wp_login_errors', function ($errors) {
                    $errors = is_wp_error($errors) ? $errors : new \WP_Error();
                    $errors->add('authlify_locked', __('<strong>Error:</strong> Too many failed login attempts. Please try again in 15 minutes.', 'modify-login'));

                    return $errors;
                });
                break;

            case 'register':
                if (!get_option('users_can_register')) {
                    self::mock_register();
                }
                break;

            case 'resetpass':
                self::mock_resetpass();
                break;

            case '2fa':
                self::mock_twofactor();
                break;
        }
    }

    /**
     * Registration form preview (same markup as core).
     *
     * @since 3.0.0
     */
    private static function mock_register()
    {
        login_header(__('Registration Form'), wp_get_admin_notice(__('Register For This Site'), array('type' => 'info', 'additional_classes' => array('message', 'register')))); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core strings.
        ?>
        <form name="registerform" id="registerform" action="#" method="post" novalidate="novalidate">
            <p>
                <label for="user_login"><?php _e('Username'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.Security.EscapeOutput.UnsafePrintingFunction ?></label>
                <input type="text" name="user_login" id="user_login" class="input" value="" size="20" autocapitalize="off" autocomplete="username" required="required" />
            </p>
            <p>
                <label for="user_email"><?php _e('Email'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.Security.EscapeOutput.UnsafePrintingFunction ?></label>
                <input type="email" name="user_email" id="user_email" class="input" value="" size="25" autocomplete="email" required="required" />
            </p>
            <p id="reg_passmail"><?php _e('Registration confirmation will be emailed to you.'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.Security.EscapeOutput.UnsafePrintingFunction ?></p>
            <br class="clear" />
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Register'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain ?>" />
            </p>
        </form>
        <p id="nav">
            <a class="wp-login-log-in" href="#"><?php _e('Log in'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.Security.EscapeOutput.UnsafePrintingFunction ?></a> |
            <a class="wp-login-lost-password" href="#"><?php _e('Lost your password?'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.Security.EscapeOutput.UnsafePrintingFunction ?></a>
        </p>
        <?php
        login_footer('user_login');
        exit;
    }

    /**
     * Reset password preview (same markup as core).
     *
     * @since 3.0.0
     */
    private static function mock_resetpass()
    {
        login_header(__('Reset Password'), wp_get_admin_notice(__('Enter your new password below or generate one.'), array('type' => 'info', 'additional_classes' => array('message', 'reset-pass')))); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
        ?>
        <form name="resetpassform" id="resetpassform" action="#" method="post" autocomplete="off">
            <div class="user-pass1-wrap">
                <p>
                    <label for="pass1"><?php _e('New password'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.Security.EscapeOutput.UnsafePrintingFunction ?></label>
                </p>
                <div class="wp-pwd">
                    <input type="text" name="pass1" id="pass1" class="input password-input" size="24" value="Tr0ub4dor&amp;3-horse-battery" autocomplete="new-password" spellcheck="false" />
                    <button type="button" class="button button-secondary wp-hide-pw hide-if-no-js" aria-label="<?php esc_attr_e('Hide password'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain ?>">
                        <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                    </button>
                    <div id="pass-strength-result" class="hide-if-no-js strong" aria-live="polite"><?php _e('Strong'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.Security.EscapeOutput.UnsafePrintingFunction ?></div>
                </div>
            </div>
            <p class="description indicator-hint"><?php echo esc_html(wp_get_password_hint()); ?></p>
            <p class="reset-pass-submit">
                <button type="button" class="button wp-generate-pw hide-if-no-js"><?php _e('Generate Password'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.Security.EscapeOutput.UnsafePrintingFunction ?></button>
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Save Password'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain ?>" />
            </p>
        </form>
        <p id="nav"><a class="wp-login-log-in" href="#"><?php _e('Log in'); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.Security.EscapeOutput.UnsafePrintingFunction ?></a></p>
        <?php
        login_footer('pass1');
        exit;
    }

    /**
     * Two-factor step preview (used until the two-factor module provides its own).
     *
     * @since 3.0.0
     */
    private static function mock_twofactor()
    {
        login_header(__('Two-factor authentication', 'modify-login'), wp_get_admin_notice(esc_html__('Enter the 6-digit code from your authenticator app.', 'modify-login'), array('type' => 'info', 'additional_classes' => array('message'))));
        ?>
        <form name="authlify-2fa" id="loginform" action="#" method="post">
            <p>
                <label for="authlify_code"><?php esc_html_e('Authentication code', 'modify-login'); ?></label>
                <input type="text" name="authlify_code" id="authlify_code" class="input" value="" size="20" inputmode="numeric" autocomplete="one-time-code" placeholder="123 456" />
            </p>
            <p class="forgetmenot"><input name="authlify_trust" type="checkbox" id="authlify_trust" value="1" /> <label for="authlify_trust"><?php esc_html_e('Trust this device for 30 days', 'modify-login'); ?></label></p>
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Verify', 'modify-login'); ?>" />
            </p>
        </form>
        <p id="nav"><a href="#"><?php esc_html_e('Use a backup code', 'modify-login'); ?></a></p>
        <?php
        login_footer('authlify_code');
        exit;
    }
}
