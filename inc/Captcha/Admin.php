<?php
/**
 * CAPTCHA settings screen.
 *
 * @package Authlify
 */

namespace Authlify\Captcha;

use Authlify\Admin\Menu;
use Authlify\Admin\UI;
use Authlify\Plugin;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * The CAPTCHA tab on the Protection page, key validation on save, and the
 * dashboard check.
 *
 * @since 3.0.0
 */
final class Admin
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_filter('authlify_protection_tabs', array(__CLASS__, 'tabs'));
        add_filter('authlify_validate_settings', array(__CLASS__, 'validate'), 10, 3);
        add_filter('authlify_dashboard_checks', array(__CLASS__, 'dashboard_check'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    /**
     * Add the CAPTCHA tab after Brute force.
     *
     * @param array $tabs Tabs.
     * @return array
     */
    public static function tabs($tabs)
    {
        $tab = array(__('CAPTCHA', 'modify-login'), array(__CLASS__, 'render'));
        $out = array();

        foreach ((array) $tabs as $key => $value) {
            $out[$key] = $value;
            if ('limits' === $key) {
                $out['captcha'] = $tab;
            }
        }

        if (!isset($out['captcha'])) {
            $out['captcha'] = $tab;
        }

        return $out;
    }

    /**
     * Whether the current screen is the CAPTCHA tab.
     *
     * @return bool
     */
    private static function is_tab()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        // phpcs:enable

        return 'authlify-protection' === $page && 'captcha' === $tab;
    }

    /**
     * Settings-screen scripts: the widget runtime (for the preview) and the form helper.
     */
    public static function assets()
    {
        if (!self::is_tab() || !current_user_can(Plugin::cap())) {
            return;
        }

        Captcha::register_assets();
        wp_enqueue_style('authlify-captcha');
        wp_enqueue_script('authlify-captcha');
        wp_enqueue_script('authlify-altcha');
        wp_enqueue_script('authlify-captcha-admin', AUTHLIFY_URL . 'assets/captcha/admin.js', array('authlify-captcha'), AUTHLIFY_VERSION, true);
        wp_localize_script('authlify-captcha-admin', 'authlifyCaptchaAdmin', array(
            'needKey' => __('Enter a site key first.', 'modify-login'),
            'v3' => __('reCAPTCHA v3 is invisible: if the key works, its badge appears in the bottom corner of this page.', 'modify-login'),
            'none' => __('No CAPTCHA selected. Only the invisible honeypot (if on) protects the forms.', 'modify-login'),
            'loading' => __('Loading…', 'modify-login'),
        ));
    }

    /**
     * Render the tab.
     */
    public static function render()
    {
        $warning = get_transient('authlify_captcha_warning_' . get_current_user_id());
        if ($warning) {
            delete_transient('authlify_captcha_warning_' . get_current_user_id());
            echo '<div class="notice notice-warning"><p>' . esc_html($warning) . '</p></div>';
        }

        if (Captcha::active_provider() && Provider::is_test_key((string) Settings::get('captcha_site_key', ''))) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('These are the provider\'s public test keys. They accept every answer, so the CAPTCHA blocks nothing. Create a real widget in the provider\'s dashboard and paste its keys below.', 'modify-login') . '</p></div>';
        }

        if (Captcha::disabled()) {
            echo '<div class="notice notice-warning"><p>' . wp_kses(__('CAPTCHA is switched off by <code>AUTHLIFY_DISABLE_CAPTCHA</code> in wp-config.php. Remove that line to turn it back on.', 'modify-login'), UI::inline_html()) . '</p></div>';
        }

        UI::form_start('protection', 'captcha');

        self::provider_panel();
        self::forms_panel();
        self::safety_panel();

        UI::form_end();
    }

    /**
     * Provider, keys and preview.
     */
    private static function provider_panel()
    {
        UI::panel_start(__('CAPTCHA provider', 'modify-login'), __('A CAPTCHA stops scripts that guess passwords or send spam. You can switch any time.', 'modify-login'));

        UI::choice_row('captcha_provider', __('Provider', 'modify-login'), array(
            'turnstile' => array(__('Cloudflare Turnstile', 'modify-login'), __('Free and unlimited, usually no puzzle at all. No Cloudflare hosting needed.', 'modify-login'), 'icon' => 'cloud', 'badge' => __('Recommended', 'modify-login')),
            'altcha' => array(__('ALTCHA', 'modify-login'), __('Runs entirely on your site: no keys, no cookies, nothing sent elsewhere. Needs JavaScript.', 'modify-login'), 'icon' => 'server', 'badge' => __('No third party', 'modify-login')),
            'hcaptcha' => array(__('hCaptcha', 'modify-login'), __('Privacy-focused with a free plan. Shows image puzzles more often than Turnstile.', 'modify-login'), 'icon' => 'tiles'),
            'recaptcha_v2' => array(__('Google reCAPTCHA v2', 'modify-login'), __('The "I\'m not a robot" checkbox. Sends visitor data to Google; free-tier limits apply.', 'modify-login'), 'icon' => 'check-square'),
            'recaptcha_v3' => array(__('Google reCAPTCHA v3', 'modify-login'), __('An invisible score. It can quietly block real people, with no puzzle to prove otherwise.', 'modify-login'), 'icon' => 'eye-off'),
            'none' => array(__('None', 'modify-login'), __('Only the invisible honeypot below, if it is on.', 'modify-login'), 'icon' => 'ban'),
        ), __('Turnstile suits most sites. Choose ALTCHA if nothing may be sent to another company.', 'modify-login') . ' ' . UI::learn_more('captcha-providers', __('Compare providers', 'modify-login')), 'cards');

        echo '<div data-authlify-captcha-show="turnstile hcaptcha recaptcha_v2 recaptcha_v3">';
        UI::field_start(__('Get your keys', 'modify-login'), __('Create a site in the provider\'s dashboard, then paste both keys below.', 'modify-login'));
        echo '<p data-authlify-captcha-show="turnstile"><a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">' . esc_html__('Cloudflare dashboard → Turnstile → Add widget', 'modify-login') . '</a></p>';
        echo '<p data-authlify-captcha-show="hcaptcha"><a href="https://dashboard.hcaptcha.com/sites" target="_blank" rel="noopener">' . esc_html__('hCaptcha dashboard → Sites', 'modify-login') . '</a></p>';
        echo '<p data-authlify-captcha-show="recaptcha_v2 recaptcha_v3"><a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener">' . esc_html__('Google reCAPTCHA admin console', 'modify-login') . '</a></p>';
        echo '<p class="description" data-authlify-captcha-show="recaptcha_v2 recaptcha_v3">' . esc_html__('Pick the same version as above: v2 keys do not work with v3. Google gives 10,000 free checks a month, and new keys need a Google Cloud project with billing attached.', 'modify-login') . '</p>';
        echo '<p class="description" data-authlify-captcha-show="turnstile hcaptcha recaptcha_v2 recaptcha_v3">' . esc_html__('Complete the preview below before saving. It proves both keys work together, so a wrong key can never lock anyone out.', 'modify-login') . '</p>';
        UI::field_end();
        UI::input_row('captcha_site_key', __('Site key', 'modify-login'), __('The public key. It is proven when you complete the preview below.', 'modify-login'), array('autocomplete' => 'off', 'class' => 'regular-text code'));
        UI::input_row('captcha_secret_key', __('Secret key', 'modify-login'), __('Checked with the provider when you save. A wrong key is refused, so a typo cannot lock anyone out.', 'modify-login'), array(
            'type' => 'password',
            'autocomplete' => 'new-password',
            'class' => 'regular-text code',
            'secret' => true,
        ));
        echo '</div>';

        echo '<div data-authlify-captcha-show="recaptcha_v3">';
        UI::own('captcha_v3_threshold');
        UI::field_start(__('Minimum score', 'modify-login'), __('Visits scoring below this (0.0 = bot, 1.0 = human) are refused. Lower it if real people get blocked.', 'modify-login'), 'authlify-captcha_v3_threshold');
        printf(
            '<input type="number" id="authlify-captcha_v3_threshold" name="authlify[captcha_v3_threshold]" value="%s" min="0" max="1" step="0.1" class="small-text"> <span class="authlify-sublabel">%s</span>',
            esc_attr(number_format(RecaptchaV3::threshold(), 1, '.', '')),
            esc_html__('0.5 is Google\'s suggestion', 'modify-login')
        );
        UI::field_end();
        echo '</div>';

        echo '<div data-authlify-captcha-show="turnstile altcha hcaptcha recaptcha_v2 recaptcha_v3">';
        UI::field_start(__('Preview', 'modify-login'), __('Try the widget with the settings above before you save. The provider\'s script loads only when you click.', 'modify-login'));
        echo '<button type="button" class="button" id="authlify-captcha-preview-button">' . esc_html__('Show preview', 'modify-login') . '</button>';
        echo '<div id="authlify-captcha-preview" class="authlify-captcha-preview" aria-live="polite"></div>';
        UI::field_end();
        echo '</div>';

        UI::panel_end();
    }

    /**
     * Forms and mode.
     */
    private static function forms_panel()
    {
        UI::panel_start(__('Where and when', 'modify-login'), __('Choose the forms to protect, and whether honest visitors see the check at all.', 'modify-login'));

        $enabled = (array) Settings::get('captcha_forms', array());
        $woo = class_exists('WooCommerce');

        UI::own('captcha_forms');
        UI::field_start(__('Forms', 'modify-login'), __('XML-RPC, REST API and application-password logins cannot show a CAPTCHA; the brute-force limits cover those.', 'modify-login'));
        echo '<fieldset class="authlify-checklist"><legend class="screen-reader-text">' . esc_html__('Forms', 'modify-login') . '</legend>';
        $woo_forms = '';
        foreach (Captcha::forms() as $key => $label) {
            $row = sprintf(
                '<label><input type="checkbox" name="authlify[captcha_forms][]" value="%1$s" %2$s> <span>%3$s</span></label>',
                esc_attr($key),
                checked(in_array($key, $enabled, true), true, false),
                esc_html($label)
            );
            if (0 === strpos($key, 'woo_')) {
                $woo_forms .= $row;
            } else {
                echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            }
        }
        if ('' !== $woo_forms) {
            // Without WooCommerce its forms stay in the form (so saved choices are kept) but out of sight.
            echo '<div' . ($woo ? '' : ' hidden') . '>' . $woo_forms . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if (!$woo) {
                echo '<p class="description">' . esc_html__('WooCommerce forms appear here when WooCommerce is active.', 'modify-login') . '</p>';
            }
        }
        echo '</fieldset>';
        UI::field_end();

        UI::choice_row('captcha_mode', __('When to show it', 'modify-login'), array(
            'always' => array(__('Always', 'modify-login'), ''),
            'after_failures' => array(__('Only after failed logins', 'modify-login'), __('Login forms show it after failures from the visitor\'s address, or for a username under attack. Other forms always show it.', 'modify-login')),
        ), __('"Only after failed logins" keeps signing in friction-free for people who type their password right.', 'modify-login') . ' ' . UI::learn_more('captcha'), true);

        UI::input_row('captcha_after', __('Failed logins before it appears', 'modify-login'), __('A username targeted from many addresses always needs it.', 'modify-login'), array('type' => 'number', 'min' => 1, 'max' => 100, 'suffix' => __('attempts', 'modify-login'), 'show_if' => 'authlify[captcha_mode]=after_failures'));

        UI::panel_end();
    }

    /**
     * Test mode, outage behaviour, honeypot.
     */
    private static function safety_panel()
    {
        UI::panel_start(__('Safety', 'modify-login'), __('Try it without risk, and decide what happens if the provider goes down.', 'modify-login'));

        $count = Captcha::test_mode_count(time() - 7 * DAY_IN_SECONDS);
        $note = Settings::get('captcha_test_mode', false)
            /* translators: %s: number of submissions */
            ? sprintf(_n('In the last 7 days, %s submission would have been blocked.', 'In the last 7 days, %s submissions would have been blocked.', $count, 'modify-login'), '<strong>' . number_format_i18n($count) . '</strong>')
            : __('Shows the widget and checks every answer, but only logs failures. Nobody is blocked.', 'modify-login');
        UI::toggle_row('captcha_test_mode', __('Check and log, but never block', 'modify-login'), $note, __('Test mode', 'modify-login'));

        UI::choice_row('captcha_fail', __('If the provider is down', 'modify-login'), array(
            'open' => array(__('Let people through (recommended)', 'modify-login'), __('The brute-force limits still apply.', 'modify-login')),
            'closed' => array(__('Block the form until the provider is back', 'modify-login'), ''),
        ), __('Outages are logged either way. ALTCHA runs on your site and cannot go down.', 'modify-login') . ' ' . UI::learn_more('howto-captcha-safely', __('Choosing safely', 'modify-login')), true);

        UI::toggle_row('honeypot', __('Add an invisible bot trap to the same forms', 'modify-login'), __('A hidden field only bots fill in, plus a check that the form was not sent back too fast. Invisible to people and screen readers.', 'modify-login') . ' ' . UI::learn_more('honeypot'), __('Honeypot', 'modify-login'));

        echo '<p class="authlify-panel__note">' . wp_kses(__('After a change, test the login page in a private window. If a CAPTCHA ever stops you logging in, add <code>define( \'AUTHLIFY_DISABLE_CAPTCHA\', true );</code> to wp-config.php.', 'modify-login'), UI::inline_html()) . '</p>';

        UI::panel_end();
    }

    /**
     * Validate on save: clean values and verify the secret key with the provider.
     *
     * @param array|\WP_Error $values Values.
     * @param string          $page   Page.
     * @param string          $tab    Tab.
     * @return array|\WP_Error
     */
    public static function validate($values, $page, $tab = '')
    {
        if (is_wp_error($values) || 'protection' !== $page || 'captcha' !== $tab) {
            return $values;
        }

        if (isset($values['captcha_forms'])) {
            $values['captcha_forms'] = array_values(array_intersect(array_map('sanitize_key', (array) $values['captcha_forms']), array_keys(Captcha::forms())));
        }
        if (isset($values['captcha_after'])) {
            $values['captcha_after'] = min(100, max(1, (int) $values['captcha_after']));
        }
        if (isset($values['captcha_v3_threshold'])) {
            $threshold = max(0.0, min(1.0, (float) str_replace(',', '.', (string) $values['captcha_v3_threshold'])));
            $values['captcha_v3_threshold'] = number_format($threshold, 1, '.', '');
        }
        foreach (array('captcha_site_key', 'captcha_secret_key') as $key) {
            if (isset($values[$key])) {
                $values[$key] = trim(sanitize_text_field((string) $values[$key]));
            }
        }
        // The secret is never printed into the form; an empty field keeps the stored one.
        if (isset($values['captcha_secret_key']) && '' === $values['captcha_secret_key']) {
            unset($values['captcha_secret_key']);
        }

        $id = isset($values['captcha_provider']) ? sanitize_key((string) $values['captcha_provider']) : (string) Settings::get('captcha_provider', 'none');
        $provider = Captcha::provider($id);
        if (!$provider || !$provider->needs_keys()) {
            return $values;
        }

        $site = isset($values['captcha_site_key']) ? $values['captcha_site_key'] : (string) Settings::get('captcha_site_key', '');
        $secret = isset($values['captcha_secret_key']) ? $values['captcha_secret_key'] : (string) Settings::get('captcha_secret_key', '');

        if ('' === $site || '' === $secret) {
            /* translators: %s: provider name */
            return new \WP_Error('authlify_captcha_keys', sprintf(__('%s needs both a site key and a secret key. Nothing was saved.', 'modify-login'), $provider->label()));
        }

        // Only ask the provider when something relevant changed, or when test
        // mode is being switched off (keys saved in test mode were never proven).
        $leaving_test = (bool) Settings::get('captcha_test_mode', false) && isset($values['captcha_test_mode']) && !filter_var($values['captcha_test_mode'], FILTER_VALIDATE_BOOLEAN);
        if (!$leaving_test && $id === Settings::get('captcha_provider') && $secret === Settings::get('captcha_secret_key') && $site === Settings::get('captcha_site_key')) {
            return $values;
        }

        // The preview widget sits inside this form, so its answer (if any) is posted along.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the saver verified the nonce before this filter.
        $token = isset($_POST[$provider->field()]) && is_string($_POST[$provider->field()]) ? trim(sanitize_text_field(wp_unslash($_POST[$provider->field()]))) : '';
        $test_mode = isset($values['captcha_test_mode']) ? (bool) filter_var($values['captcha_test_mode'], FILTER_VALIDATE_BOOLEAN) : (bool) Settings::get('captcha_test_mode', false);

        $status = $provider->check_keys($secret, $token);

        if ('needs_token' === $status || 'bad_token' === $status) {
            $message = 'needs_token' === $status
                /* translators: %s: provider name */
                ? sprintf(__('To make sure your %s keys work before anyone depends on them, click "Show preview", complete the check, then save again.', 'modify-login'), $provider->label())
                : __('The preview answer had expired or was already used. Click "Show preview" again, complete the check, then save.', 'modify-login');

            if (!$test_mode) {
                return new \WP_Error('authlify_captcha_unverified', $message . ' ' . __('To save without this check, turn on test mode first.', 'modify-login'));
            }

            set_transient('authlify_captcha_warning_' . get_current_user_id(), __('Saved in test mode without checking the keys. Complete the preview and save again before turning test mode off.', 'modify-login'), 5 * MINUTE_IN_SECONDS);

            return $values;
        }

        if ('bad' === $status) {
            return new \WP_Error('authlify_captcha_secret', sprintf(
                /* translators: %s: provider name */
                __('%s rejected the secret key, so nothing was saved (this stops a wrong key locking everyone out). Copy the secret key again from the provider\'s dashboard, and make sure it belongs to the same widget as the site key.', 'modify-login'),
                $provider->label()
            ));
        }

        if ('unreachable' === $status) {
            set_transient('authlify_captcha_warning_' . get_current_user_id(), sprintf(
                /* translators: %s: provider name */
                __('Saved, but %s could not be reached to check the secret key. Try the login page in a private window, or turn on test mode until you have.', 'modify-login'),
                $provider->label()
            ), 5 * MINUTE_IN_SECONDS);
        }

        return $values;
    }

    /**
     * Dashboard checklist item.
     *
     * @param array $checks Checks.
     * @return array
     */
    public static function dashboard_check($checks)
    {
        $provider = Captcha::active_provider();
        $on_login = in_array('login', (array) Settings::get('captcha_forms', array()), true);
        $done = $provider && $on_login && !Captcha::disabled();

        if ($done && Provider::is_test_key((string) Settings::get('captcha_site_key', ''))) {
            $text = __('The saved keys are the provider\'s public test keys: every check passes, so nothing is blocked. Replace them with your own keys.', 'modify-login');
            $done = false;
        } elseif ($done && Settings::get('captcha_test_mode', false)) {
            $text = __('Test mode is on: failures are logged but nobody is blocked yet.', 'modify-login');
            $done = false;
        } elseif ($done) {
            /* translators: %s: provider name */
            $text = sprintf(__('%s protects your login form.', 'modify-login'), $provider->label());
        } else {
            $text = __('Stops password-guessing scripts before they reach the password check. Turnstile and ALTCHA are free.', 'modify-login');
        }

        $checks['captcha'] = array($done, __('CAPTCHA on login forms', 'modify-login'), $text, Menu::url('protection', array('tab' => 'captcha')));

        return $checks;
    }
}
