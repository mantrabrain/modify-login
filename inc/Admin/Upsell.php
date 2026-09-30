<?php
/**
 * Contextual Authlify Pro mentions.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Plugin;

defined('ABSPATH') || exit;

/**
 * Quiet, contextual pointers to Authlify Pro, shown only while Pro is not
 * active and only where a Pro feature extends what is on screen: at most one
 * per screen, never a banner, popup or notice, and never in place of a free
 * feature. Pro features do not exist in the free plugin; these only link to
 * their documentation and to the Pro page.
 *
 * @since 3.0.0
 */
final class Upsell
{
    /**
     * Wire up (called from Docs::init()).
     *
     * @since 3.0.0
     */
    public static function init()
    {
        add_filter('plugin_action_links_' . AUTHLIFY_BASENAME, array(__CLASS__, 'action_links'), 20);
        add_filter('network_admin_plugin_action_links_' . AUTHLIFY_BASENAME, array(__CLASS__, 'action_links'), 20);
        add_filter('plugin_row_meta', array(__CLASS__, 'row_meta'), 10, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'styles'), 20);

        // The Dashboard shows its own Authlify Pro card (Dashboard::pro_card()).
    }

    /**
     * Whether to show Pro pointers at all.
     *
     * @return bool
     * @since 3.0.0
     */
    public static function active()
    {
        /**
         * Filters whether Authlify shows its contextual Pro pointers
         * (the Plugins-screen link, and the small Pro rows on a few screens).
         *
         * @param bool $show Default true while Authlify Pro is not active.
         * @since 3.0.0
         */
        return (bool) apply_filters('authlify_show_pro_pointers', !Plugin::has_pro());
    }

    /**
     * "Upgrade to Pro" on the Plugins screen, after the Settings link.
     *
     * @param array $links Links.
     * @return array
     * @since 3.0.0
     */
    public static function action_links($links)
    {
        if (!self::active()) {
            return $links;
        }

        $links['authlify-pro'] = sprintf(
            '<a href="%1$s" target="_blank" rel="noopener" style="color:#4f46e5;font-weight:500">%2$s<span class="screen-reader-text"> %3$s</span></a>',
            esc_url(Docs::upgrade_url('plugins-screen')),
            esc_html__('Upgrade to Pro', 'modify-login'),
            esc_html__('(opens in a new tab)', 'modify-login')
        );

        return $links;
    }

    /**
     * "Docs" under the plugin description.
     *
     * @param array  $meta Links.
     * @param string $file Plugin file.
     * @return array
     * @since 3.0.0
     */
    public static function row_meta($meta, $file)
    {
        if (AUTHLIFY_BASENAME !== $file || !current_user_can(Plugin::cap())) {
            return $meta;
        }

        $meta[] = '<a href="' . esc_url(Docs::url()) . '">' . esc_html__('Docs', 'modify-login') . '</a>';

        return $meta;
    }

    /**
     * Styles for the "Learn more" links and the Pro rows, on Authlify screens.
     *
     * @since 3.0.0
     */
    public static function styles()
    {
        if (!Menu::is_screen()) {
            return;
        }

        // The styles now live in assets/admin/admin.css (.authlify-upsell, .authlify-learn-more).
    }

    /**
     * Where each pointer appears, and what it says.
     *
     * @return array key => array( title, text, doc, items (label => doc) ).
     * @since 3.0.0
     */
    public static function spots()
    {
        return array(
            'two-factor' => array(
                __('Two-factor for your whole team', 'modify-login'),
                __('Authlify Pro can require it by role, with a grace period to set up.', 'modify-login'),
                'pro-2fa-policies',
                array(
                    __('Required two-factor for roles', 'modify-login') => 'pro-2fa-policies',
                    __('Trusted devices', 'modify-login') => 'pro-trusted-devices',
                    __('Email codes', 'modify-login') => 'pro-email-codes',
                ),
            ),
            'security' => array(
                __('More security controls', 'modify-login'),
                __('Authlify Pro adds:', 'modify-login'),
                'pro-password-policy',
                array(
                    __('Password policy', 'modify-login') => 'pro-password-policy',
                    __('Sessions', 'modify-login') => 'pro-sessions',
                    __('Access rules', 'modify-login') => 'pro-access-rules',
                ),
            ),
            'alerts' => array(
                __('Alerts to email, Slack or webhooks', 'modify-login'),
                __('Authlify Pro can tell you about new-device and new-country logins, lockouts and settings changes as they happen.', 'modify-login'),
                'pro-alerts',
                array(),
            ),
            'dashboard' => array(
                __('Need more?', 'modify-login'),
                __('Authlify Pro adds two-factor rules for roles, login alerts, social sign-in and agency tools.', 'modify-login'),
                '',
                array(),
            ),
        );
    }

    /**
     * Print one pointer.
     *
     * @param string $spot Key from spots().
     * @since 3.0.0
     */
    public static function render($spot)
    {
        $spots = self::spots();
        if (!self::active() || !isset($spots[$spot])) {
            return;
        }
        list($title, $text, $doc, $items) = $spots[$spot];
        ?>
        <div class="authlify-upsell" data-authlify-upsell="<?php echo esc_attr($spot); ?>">
            <span class="authlify-pro-tag">Pro</span>
            <p class="authlify-upsell__text">
                <strong><?php echo esc_html($title); ?></strong>
                <?php echo esc_html($text); ?>
                <?php if ($items) : ?>
                    <?php $last = count($items) - 1; $i = 0; ?>
                    <?php foreach ($items as $label => $article) : ?>
                        <?php echo $i > 0 ? ' · ' : ''; ?><a href="<?php echo esc_url(Docs::url($article)); ?>"><?php echo esc_html($label); ?></a><?php ++$i; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </p>
            <span class="authlify-upsell__links">
                <?php if ('' !== $doc && !$items) : ?>
                    <a href="<?php echo esc_url(Docs::url($doc)); ?>"><?php esc_html_e('Learn more', 'modify-login'); ?><span class="screen-reader-text"> <?php echo esc_html(sprintf(/* translators: %s: feature */ __('about %s', 'modify-login'), $title)); ?></span></a>
                <?php elseif ('' === $doc) : ?>
                    <a href="<?php echo esc_url(Menu::slug_url('authlify-pro')); ?>"><?php esc_html_e('Compare Free and Pro', 'modify-login'); ?></a>
                <?php endif; ?>
                <a href="<?php echo esc_url(Docs::upgrade_url($spot)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Upgrade', 'modify-login'); ?><span class="screen-reader-text"> <?php esc_html_e('to Authlify Pro (opens in a new tab)', 'modify-login'); ?></span></a>
            </span>
        </div>
        <?php
    }

    /**
     * Dashboard: one compact line.
     *
     * @since 3.0.0
     */
    public static function dashboard()
    {
        self::render('dashboard');
    }
}
