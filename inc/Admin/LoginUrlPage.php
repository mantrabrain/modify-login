<?php
/**
 * Login URL screen.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Login\Recovery;
use Authlify\Login\Router;
use Authlify\Plugin;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Choose the login URL and what hidden URLs return.
 *
 * A new URL does not replace the old one straight away: both work until the
 * admin opens the new one from this screen, so a typo cannot lock them out.
 */
final class LoginUrlPage
{
    /**
     * Wire up (called from Menu via the validate filter).
     */
    public static function boot()
    {
        add_filter('authlify_validate_settings', array(__CLASS__, 'validate'), 10, 3);
        add_action('admin_post_authlify_cancel_pending', array(__CLASS__, 'cancel_pending'));
        add_action('admin_post_authlify_email_url', array(__CLASS__, 'email_url'));
    }

    /**
     * Render.
     */
    public static function render()
    {
        $slug = Router::slug();
        $pending = Router::pending_slug();
        $confirm = get_transient('authlify_confirm_url_' . get_current_user_id());
        $forced = defined('AUTHLIFY_SLUG') || defined('AUTHLIFY_DISABLE_HIDE');
        ?>
        <div class="wrap authlify-page">
            <?php UI::header(__('Login URL', 'modify-login'), __('Move the login page to an address only you know. Bots that hammer wp-login.php get a normal "page not found" instead.', 'modify-login'), array(), 'login-url'); ?>

            <?php if ('' !== $pending) : ?>
                <div class="notice notice-warning authlify-pending">
                    <p><strong><?php esc_html_e('One more step: confirm your new login URL.', 'modify-login'); ?></strong>
                    <?php esc_html_e('Open it now. Until you do, the previous login address keeps working too, so a typo cannot lock you out.', 'modify-login'); ?></p>
                    <p>
                        <?php if ($confirm) : ?>
                            <a class="button button-primary" href="<?php echo esc_url($confirm); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open and confirm the new URL', 'modify-login'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'modify-login'); ?></span></a>
                        <?php endif; ?>
                        <code><?php echo esc_html(Router::login_url(null, $pending)); ?></code>
                        <a class="button-link" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=authlify_cancel_pending'), 'authlify_cancel_pending')); ?>"><?php esc_html_e('Cancel the change', 'modify-login'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <?php $other = self::other_hide_plugin(); ?>
            <?php if ('' !== $other) : ?>
                <div class="notice notice-warning"><p><?php printf(esc_html__('%s is also changing the login address. Two plugins doing this can reveal your address or lock you out. Turn that feature off in %s, or deactivate it.', 'modify-login'), '<strong>' . esc_html($other) . '</strong>', esc_html($other)); ?></p></div>
            <?php endif; ?>

            <?php if ($forced) : ?>
                <div class="notice notice-info"><p><?php esc_html_e('The login URL is currently controlled by a constant in wp-config.php (AUTHLIFY_SLUG or AUTHLIFY_DISABLE_HIDE). Remove it to manage the URL here.', 'modify-login'); ?></p></div>
            <?php endif; ?>

            <?php
            /**
             * Fires above the Login URL settings (Leak Check adds its button here).
             *
             * @since 3.0.0
             */
            do_action('authlify_login_url_page');
            ?>

            <?php UI::form_start('login-url'); ?>

            <?php UI::panel_start(__('Your login address', 'modify-login'), __('Letters, numbers, hyphens and underscores. Pick something that is not a word people would guess.', 'modify-login')); ?>

                <?php UI::field_start(__('Current login URL', 'modify-login')); ?>
                    <div class="authlify-copyable">
                        <?php $current = '' === $slug ? site_url('wp-login.php', 'login') : Router::login_url(); ?>
                        <code id="authlify-current-url"><?php echo esc_html($current); ?></code>
                        <button type="button" class="button button-small" data-authlify-copy="#authlify-current-url"><?php esc_html_e('Copy', 'modify-login'); ?></button>
                        <?php echo '' === $slug ? UI::pill(__('WordPress default', 'modify-login'), 'warning') : UI::pill(__('Custom', 'modify-login'), 'ok'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php UI::field_end(); ?>

                <?php
                UI::own('login_slug');
                UI::field_start(__('New login address', 'modify-login'), __('Leave empty to use the standard wp-login.php page.', 'modify-login'), 'authlify-login_slug');
                ?>
                    <span class="authlify-input-group">
                        <code><?php echo esc_html(self::url_prefix()); ?></code>
                        <input type="text" id="authlify-login_slug" name="authlify[login_slug]" value="<?php echo esc_attr(self::field_value($slug, $pending)); ?>" class="regular-text" pattern="[A-Za-z0-9_\-]{3,64}" title="<?php esc_attr_e('3 to 64 letters, numbers, hyphens or underscores', 'modify-login'); ?>" placeholder="<?php echo esc_attr(self::suggestion()); ?>" autocomplete="off" spellcheck="false"<?php echo UI::describedby('authlify-login_slug-note'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in describedby(). ?> <?php disabled($forced); ?>>
                    </span>
                    <p class="description" id="authlify-login_slug-note"><?php printf(esc_html__('Need an idea? %s', 'modify-login'), '<code>' . esc_html(self::suggestion()) . '</code>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                <?php UI::field_end(); ?>

            <?php UI::panel_end(); ?>

            <?php UI::panel_start(__('Hide the default login', 'modify-login'), __('What logged-out visitors get when they open wp-login.php or anything under /wp-admin/.', 'modify-login')); ?>

                <?php UI::toggle_row('block_wp_login', __('Hide wp-login.php and wp-admin from logged-out visitors', 'modify-login'), __('Needs a custom login address. When this is off, wp-login.php keeps working and WordPress will point visitors of /wp-admin/ to your custom address.', 'modify-login'), __('Hide default URLs', 'modify-login')); ?>

                <?php
                UI::choice_row('blocked_response', __('Show visitors', 'modify-login'), array(
                    '404' => array(__('Page not found', 'modify-login'), __('Your theme\'s normal "page not found" page.', 'modify-login'), 'icon' => 'file', 'badge' => __('Recommended', 'modify-login')),
                    '403' => array(__('Access denied', 'modify-login'), __('A short "access denied" message.', 'modify-login'), 'icon' => 'ban'),
                    'redirect' => array(__('Redirect', 'modify-login'), __('Send visitors to another page, such as the homepage.', 'modify-login'), 'icon' => 'route'),
                ), __('A normal "page not found" gives bots nothing to work with.', 'modify-login') . ' ' . UI::learn_more('howto-hide-login', __('Which to choose', 'modify-login')), 'cards');

                UI::input_row('blocked_redirect_url', __('Redirect to', 'modify-login'), __('Used with "A redirect". Leave empty for the homepage.', 'modify-login'), array('type' => 'url', 'placeholder' => home_url('/'), 'show_if' => 'authlify[blocked_response]=redirect'));

                UI::toggle_row('leak_check_schedule', __('Re-check weekly and after every change that the address stays hidden', 'modify-login'), __('Leak Check requests your own site about 40 times, like a visitor would. Nothing leaves your server. You can always run it by hand.', 'modify-login') . ' ' . UI::learn_more('leak-check'), __('Automatic Leak Check', 'modify-login'));
                ?>

            <?php UI::panel_end(); ?>

            <?php UI::form_end(); ?>

            <?php self::recovery_panel(); ?>
        </div>
        <?php
    }

    /**
     * How to get back in.
     */
    private static function recovery_panel()
    {
        UI::panel_start(__('If you ever lose the login URL', 'modify-login'), __('You will not need FTP. Any one of these gets you back in.', 'modify-login'));
        ?>
            <ol class="authlify-steps-list">
                <li><?php esc_html_e('Check your email: every time the login URL changes, we send it to the site admin address.', 'modify-login'); ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=authlify_email_url'), 'authlify_email_url')); ?>"><?php esc_html_e('Email it to me now', 'modify-login'); ?></a></li>
                <li><?php esc_html_e('Add this line to wp-config.php to turn the custom URL off:', 'modify-login'); ?><br><code>define( 'AUTHLIFY_DISABLE_HIDE', true );</code></li>
                <li><?php esc_html_e('Or run this WP-CLI command:', 'modify-login'); ?> <code>wp authlify url get</code> / <code>wp authlify url reset</code></li>
            </ol>
            <p class="authlify-panel__note"><?php echo UI::learn_more('login-recovery', __('All recovery options, including lockouts', 'modify-login')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in learn_more(). ?></p>
        <?php
        UI::panel_end();
    }

    /**
     * home URL shown before the slug input.
     *
     * @return string
     */
    private static function url_prefix()
    {
        return '' === (string) get_option('permalink_structure') ? home_url('/') . '?' : home_url('/');
    }

    /**
     * A random, hard-to-guess slug suggestion.
     *
     * @return string
     */
    private static function suggestion()
    {
        $words = array('harbor', 'maple', 'orbit', 'lantern', 'cedar', 'summit', 'violet', 'pebble', 'falcon', 'quartz', 'meadow', 'comet');
        $seed = crc32(wp_salt('auth') . get_current_user_id());

        return $words[$seed % count($words)] . '-' . $words[($seed >> 8) % count($words)] . '-' . (($seed % 90) + 10);
    }

    /**
     * Validate a slug.
     *
     * @param string $slug Slug (already cleaned).
     * @return true|\WP_Error
     */
    public static function validate_slug($slug)
    {
        if (!preg_match('/^[a-z0-9_-]{3,64}$/', $slug) || !preg_match('/[a-z]/', $slug)) {
            return new \WP_Error('invalid', __('The login address must be 3 to 64 characters of letters, numbers, hyphens and underscores, with at least one letter.', 'modify-login'));
        }

        $reserved = array('wp-admin', 'wp-login', 'wp-login-php', 'login', 'admin', 'dashboard', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'xmlrpc', 'register', 'signup', 'wp-signup', 'wp-activate', 'wp-register', 'page', 'comments', 'search', 'author', 'category', 'tag', 'embed', 'sitemap', 'robots', 'favicon');
        global $wp;
        if ($wp instanceof \WP) {
            $reserved = array_merge($reserved, $wp->public_query_vars, $wp->private_query_vars);
        }
        if (in_array($slug, $reserved, true)) {
            return new \WP_Error('reserved', __('That address is used by WordPress itself. Please choose another one.', 'modify-login'));
        }

        if (get_page_by_path($slug, OBJECT, get_post_types(array('public' => true)))) {
            return new \WP_Error('exists', __('A page or post already uses that address. Please choose another one.', 'modify-login'));
        }

        return true;
    }

    /**
     * Turn a slug change into a pending change that must be confirmed.
     *
     * @param array|\WP_Error $values Values.
     * @param string          $page   Page.
     * @return array|\WP_Error
     */
    public static function validate($values, $page)
    {
        if (is_wp_error($values) || 'login-url' !== $page || !array_key_exists('login_slug', $values)) {
            return $values;
        }

        $new = Settings::clean_slug($values['login_slug']);
        $current = Router::slug();
        unset($values['login_slug']);

        $typed = strtolower(trim(wp_unslash((string) $_POST['authlify']['login_slug']), '/ ')); // phpcs:ignore
        if ('' !== $typed && $new !== $typed) {
            self::slug_error(__('The login address may only contain letters, numbers, hyphens and underscores.', 'modify-login'), $typed);

            return $values;
        }

        if ($new === $current) {
            // Unchanged (or re-submitting the pending one).
            return $values;
        }

        if ('' === $new) {
            // Going back to wp-login.php is always safe: apply at once.
            $values['login_slug'] = '';
            $values['pending_slug'] = '';
            $values['pending_expires'] = 0;

            return $values;
        }

        $valid = self::validate_slug($new);
        if (is_wp_error($valid)) {
            // Save the other settings; only the address is refused.
            self::slug_error($valid->get_error_message(), $new);

            return $values;
        }

        if ($new === Router::pending_slug()) {
            return $values;
        }

        // Save the other fields first, then open the pending change.
        add_action('authlify_admin_saved', function () use ($new) {
            $url = Recovery::start_pending($new);
            set_transient('authlify_confirm_url_' . get_current_user_id(), $url, 30 * MINUTE_IN_SECONDS);
        });

        return $values;
    }

    /**
     * Cancel a pending change.
     */
    /**
     * Name of another active plugin that also hides the login page, or ''.
     *
     * @return string
     */
    public static function other_hide_plugin()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = array(
            'wps-hide-login/wps-hide-login.php' => 'WPS Hide Login',
            'change-wp-admin-login/change-wp-admin-login.php' => 'All In One Login',
            'hide-my-wp/index.php' => 'WP Ghost',
            'easy-hide-login/wp-hide-login.php' => 'Easy Hide Login',
            'lws-hide-login/lws-hide-login.php' => 'LWS Hide Login',
            'rename-wp-admin-login/rename-wp-admin-login.php' => 'Rename wp-admin login',
            'custom-login-url/custom-login-url.php' => 'Custom Login URL',
        );
        foreach ($plugins as $file => $name) {
            if (is_plugin_active($file)) {
                return $name;
            }
        }

        $ase = get_option('admin_site_enhancements', array());
        if (is_plugin_active('admin-site-enhancements/admin-site-enhancements.php') && is_array($ase) && !empty($ase['change_login_url']) && !empty($ase['custom_login_slug'])) {
            return 'Admin and Site Enhancements';
        }

        return '';
    }

    /**
     * Value for the address field: what was just refused, the pending one, or the current one.
     *
     * @param string $slug    Current.
     * @param string $pending Pending.
     * @return string
     */
    private static function field_value($slug, $pending)
    {
        $typed = get_transient('authlify_typed_slug_' . get_current_user_id());
        if (false !== $typed) {
            delete_transient('authlify_typed_slug_' . get_current_user_id());

            return (string) $typed;
        }

        return '' !== $pending ? $pending : $slug;
    }

    /**
     * Remember a refused address so the form shows what was typed, with the reason.
     *
     * @param string $message Reason.
     * @param string $typed   What was typed.
     */
    private static function slug_error($message, $typed)
    {
        set_transient('authlify_error_' . get_current_user_id(), sprintf(__('The other settings were saved, but not the login address: %s', 'modify-login'), $message), MINUTE_IN_SECONDS);
        set_transient('authlify_typed_slug_' . get_current_user_id(), $typed, MINUTE_IN_SECONDS);
    }

    /**
     * Cancel a pending change.
     */
    public static function cancel_pending()
    {
        if (!current_user_can(Plugin::cap()) || !check_admin_referer('authlify_cancel_pending')) {
            wp_die(esc_html__('You are not allowed to do that.', 'modify-login'), 403);
        }

        Recovery::cancel_pending();
        delete_transient('authlify_confirm_url_' . get_current_user_id());
        wp_safe_redirect(Menu::url('login-url', array('authlify_notice' => 'pending_cancelled')));
        exit;
    }

    /**
     * Email the login URL.
     */
    public static function email_url()
    {
        if (!current_user_can(Plugin::cap()) || !check_admin_referer('authlify_email_url')) {
            wp_die(esc_html__('You are not allowed to do that.', 'modify-login'), 403);
        }

        Recovery::email_login_url(Router::slug(), true);
        wp_safe_redirect(add_query_arg('authlify_notice', 'emailed', Menu::url('login-url')));
        exit;
    }
}
