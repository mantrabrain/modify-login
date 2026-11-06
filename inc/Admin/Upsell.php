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
 * Quiet pointers to Authlify Pro, only while Pro is not active: a link on the
 * Plugins screen and small rows on the few screens Pro extends. Rows follow
 * the rules in Nudges (own screens only, at most one per screen, nothing in
 * the first 7 days, dismissed per user) and give way to a card about this
 * site's own data. Never a banner, popup or notice, and never in place of a
 * free feature. Pro features do not exist in the free plugin; these only link
 * to their documentation and to the product page.
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

        Nudges::init();
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
         * (the Plugins-screen link, the small Pro rows, the inline cards, the Dashboard card and the review request).
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
        $id = 'row-' . $spot;
        // Whoever said no to the card on this screen does not get a second pitch below it.
        $cards = array('security' => 'moment-lockouts', 'two-factor' => 'moment-2fa-coverage', 'alerts' => 'moment-insights');
        if (isset($cards[$spot]) && Nudges::is_dismissed($cards[$spot])) {
            return;
        }
        if (!isset($spots[$spot]) || !Nudges::claim($id)) {
            return;
        }
        list($title, $text, $doc, $items) = $spots[$spot];
        ?>
        <div class="authlify-upsell" data-authlify-upsell="<?php echo esc_attr($spot); ?>" data-authlify-nudge="<?php echo esc_attr($id); ?>">
            <span class="authlify-pro-tag">Pro</span>
            <p class="authlify-upsell__text">
                <strong><?php echo esc_html($title); ?></strong>
                <?php echo esc_html($text); ?>
                <?php if ($items) : ?>
                    <?php $i = 0; ?>
                    <?php foreach ($items as $label => $article) : ?>
                        <?php echo $i > 0 ? ' · ' : ''; ?><a href="<?php echo esc_url(Docs::url($article)); ?>"><?php echo esc_html($label); ?></a><?php ++$i; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </p>
            <span class="authlify-upsell__links">
                <?php if (!$items) : ?>
                    <a href="<?php echo esc_url(Docs::feature_url($spot, $id)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Learn more', 'modify-login'); ?><span class="screen-reader-text"> <?php echo esc_html(sprintf(/* translators: %s: feature */ __('about %s (opens in a new tab)', 'modify-login'), $title)); ?></span></a>
                <?php endif; ?>
                <a href="<?php echo esc_url(Docs::upgrade_url($id)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Upgrade', 'modify-login'); ?><span class="screen-reader-text"> <?php esc_html_e('to Authlify Pro (opens in a new tab)', 'modify-login'); ?></span></a>
                <button type="button" class="button-link authlify-upsell__hide" data-authlify-nudge-done><?php esc_html_e('Don\'t show again', 'modify-login'); ?></button>
            </span>
        </div>
        <?php
    }

}
