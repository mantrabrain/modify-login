<?php
/**
 * First-run guidance.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Plugin;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Fresh installs hide nothing until the admin picks a login URL, so nobody
 * is locked out by activating the plugin. This shows one notice on the
 * Plugins screen and the dashboard until setup is done. Sites upgraded from
 * Modify Login get a one-time "what's new" notice instead.
 */
final class Onboarding
{
    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('network_admin_notices', array(__CLASS__, 'notice'));
        add_action('admin_post_authlify_dismiss_notice', array(__CLASS__, 'dismiss'));
        add_action('authlify_settings_updated', array(__CLASS__, 'mark_done'), 10, 2);
    }

    /**
     * Setup is done once a login URL has been chosen.
     *
     * @param array $new New settings.
     */
    public static function mark_done($new)
    {
        if (empty($new['onboarding_done']) && ('' !== $new['login_slug'] || '' !== $new['pending_slug'])) {
            remove_action('authlify_settings_updated', array(__CLASS__, 'mark_done'), 10);
            Settings::update(array('onboarding_done' => true));
        }
    }

    /**
     * Show the notice.
     */
    public static function notice()
    {
        if (!current_user_can(Plugin::cap())) {
            return;
        }

        $screen = get_current_screen();
        $where = $screen ? $screen->id : '';
        $on_plugins = in_array($where, array('plugins', 'plugins-network', 'dashboard', 'dashboard-network'), true);

        if (!Settings::get('onboarding_done') && ($on_plugins || 'toplevel_page_modify-login' === $where)) {
            ?>
            <div class="notice notice-info authlify-notice">
                <p><strong><?php esc_html_e('Authlify is active. Nothing is hidden yet.', 'modify-login'); ?></strong>
                <?php esc_html_e('Choose a private login address to stop bots hammering wp-login.php. It takes a minute, and you confirm the new address before it applies.', 'modify-login'); ?></p>
                <p class="authlify-notice__actions"><a class="button button-primary" href="<?php echo esc_url(Menu::url('login-url')); ?>"><?php esc_html_e('Choose my login URL', 'modify-login'); ?></a></p>
            </div>
            <?php
            return;
        }

        $migrated = \Authlify\Install\Upgrader::get('authlify_migrated_from', '');
        if ('' !== $migrated && !get_user_meta(get_current_user_id(), 'authlify_seen_welcome', true) && ($on_plugins || 'toplevel_page_modify-login' === $where)) {
            $changed = \Authlify\Install\Upgrader::get('authlify_slug_changed_on_upgrade', array());
            ?>
            <div class="notice notice-success authlify-notice">
                <p><strong><?php esc_html_e('Modify Login is now Authlify.', 'modify-login'); ?></strong>
                <?php esc_html_e('Your login URL, redirects and settings carried over unchanged. New protection (brute-force lockouts, CAPTCHA, two-factor login and passkeys) stays off until you switch it on.', 'modify-login'); ?></p>
                <?php if ($changed) : ?>
                    <p><?php printf(esc_html__('Your old login address "%1$s" contained characters that are no longer allowed, so it is now "%2$s".', 'modify-login'), esc_html($changed['from']), esc_html($changed['to'])); ?></p>
                <?php endif; ?>
                <p class="authlify-notice__actions"><a class="button" href="<?php echo esc_url(Menu::url('protection')); ?>"><?php esc_html_e('Review protection', 'modify-login'); ?></a>
                <a class="button-link" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=authlify_dismiss_notice'), 'authlify_dismiss_notice')); ?>"><?php esc_html_e('Dismiss', 'modify-login'); ?></a></p>
            </div>
            <?php
        }
    }

    /**
     * Dismiss the welcome notice.
     */
    public static function dismiss()
    {
        check_admin_referer('authlify_dismiss_notice');
        update_user_meta(get_current_user_id(), 'authlify_seen_welcome', 1);
        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
        exit;
    }
}
