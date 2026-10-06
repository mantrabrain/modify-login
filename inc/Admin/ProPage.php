<?php
/**
 * Free vs Pro screen (shown only while Pro is not installed).
 *
 * @package Authlify
 */

namespace Authlify\Admin;

defined('ABSPATH') || exit;

/**
 * A plain comparison of Free and Pro, with the plans. No nags elsewhere:
 * besides this page and the docs, Pro is only mentioned in one quiet row on
 * the few screens it extends (see Upsell).
 */
final class ProPage
{
    /**
     * Authlify's product page (features, Free vs Pro, pricing, FAQ).
     */
    const URL = 'https://matrixaddons.com/plugins/authlify/';

    /**
     * Store checkout and the Authlify Pro download ID there.
     */
    const CHECKOUT = 'https://store.mantrabrain.com/checkout/';
    const DOWNLOAD_ID = 39065;

    /**
     * Plans. Price IDs match the store's variable prices.
     *
     * @return array[] name, sites, yearly (price, price_id), lifetime (price, price_id), featured.
     */
    public static function plans()
    {
        /**
         * Filters the plans shown on the Free vs Pro screen.
         *
         * @param array[] $plans Plans.
         * @since 3.0.0
         */
        return (array) apply_filters('authlify_pro_plans', array(
            array(
                'name' => __('Personal', 'modify-login'),
                'sites' => 1,
                'yearly' => array('49', 1),
                'lifetime' => array('129', 4),
                'featured' => false,
            ),
            array(
                'name' => __('Plus', 'modify-login'),
                'sites' => 5,
                'yearly' => array('99', 2),
                'lifetime' => array('249', 5),
                'featured' => true,
            ),
            array(
                'name' => __('Agency', 'modify-login'),
                'sites' => 25,
                'yearly' => array('149', 3),
                'lifetime' => array('399', 6),
                'featured' => false,
            ),
        ));
    }

    /**
     * Direct checkout link for one price.
     *
     * @param int $price_id Store price ID.
     * @return string
     */
    public static function checkout_url($price_id)
    {
        return add_query_arg(array(
            'edd_action' => 'add_to_cart',
            'download_id' => self::DOWNLOAD_ID,
            'edd_options[price_id]' => (int) $price_id,
        ), self::CHECKOUT);
    }

    /**
     * Comparison rows, grouped. A cell is true (included), false (not
     * included) or a short string.
     *
     * @return array[] icon, title, rows (label, free, pro).
     */
    public static function groups()
    {
        return array(
            array('link', __('Login URL', 'modify-login'), array(
                array(__('Custom login address; wp-login.php and wp-admin hidden', 'modify-login'), true, true),
                array(__('Leak Check: scans for anything that reveals the address', 'modify-login'), true, true),
                array(__('Recovery by email, wp-config.php or WP-CLI', 'modify-login'), true, true),
                array(__('Login and logout redirects, per role', 'modify-login'), true, true),
                array(__('Trap address that bans bots that try it', 'modify-login'), false, true),
            )),
            array('shield', __('Security', 'modify-login'), array(
                array(__('Brute-force lockouts with escalation, allow and deny lists', 'modify-login'), true, true),
                array(__('Block an IP from the log, and after repeated lockouts', 'modify-login'), true, true),
                array(__('Real visitor IP behind Cloudflare and proxies', 'modify-login'), true, true),
                array(__('Hardening: XML-RPC, application passwords, user enumeration, force login', 'modify-login'), true, true),
                array(__('CAPTCHA: Turnstile, hCaptcha, reCAPTCHA, ALTCHA and honeypot', 'modify-login'), true, true),
                array(__('CAPTCHA on WooCommerce (incl. block checkout), EDD, Ultimate Member, MemberPress and BuddyPress forms', 'modify-login'), true, true),
                array(__('Breached-password check when a password is set', 'modify-login'), true, true),
                array(__('Breached-password check at login, password policy and expiry', 'modify-login'), false, true),
                array(__('Session length, idle logout and concurrent-login limits', 'modify-login'), false, true),
                array(__('Login rules by country and by time of day', 'modify-login'), false, true),
            )),
            array('smartphone', __('Two-factor login', 'modify-login'), array(
                array(__('Authenticator apps, backup codes and passkeys', 'modify-login'), true, true),
                array(__('Sign in with a passkey, no password', 'modify-login'), true, true),
                array(__('Import authenticator apps from Two Factor and WP 2FA', 'modify-login'), true, true),
                array(__('Codes by email', 'modify-login'), false, true),
                array(__('Require two-factor by role, with a grace period and setup wizard', 'modify-login'), false, true),
                array(__('Trusted devices', 'modify-login'), false, true),
                array(__('Confirm identity before sensitive changes', 'modify-login'), false, true),
                array(__('Coverage report with CSV export', 'modify-login'), false, true),
                array(__('Two-factor in WooCommerce My Account', 'modify-login'), false, true),
            )),
            array('log-in', __('Sign-in methods', 'modify-login'), array(
                array(__('Magic links and email sign-in codes', 'modify-login'), false, true),
                array(__('Temporary access links for support staff and clients', 'modify-login'), false, true),
                array(__('Google, Microsoft, Apple, GitHub and OpenID Connect', 'modify-login'), false, true),
            )),
            array('activity', __('Activity and alerts', 'modify-login'), array(
                array(__('Activity log with filters, CSV export and privacy tools', 'modify-login'), true, true),
                array(__('Visitor country', 'modify-login'), __('From Cloudflare', 'modify-login'), __('Cloudflare or local database', 'modify-login')),
                array(__('Email users about sign-ins from a new device or IP', 'modify-login'), true, true),
                array(__('New-device and new-country alerts with “This wasn’t me”', 'modify-login'), false, true),
                array(__('Slack, Discord, Telegram, Teams and signed webhooks', 'modify-login'), false, true),
                array(__('Audit events: new admins, role and plugin changes', 'modify-login'), false, true),
                array(__('Insights and scheduled reports', 'modify-login'), false, true),
            )),
            array('palette', __('Login page design', 'modify-login'), array(
                array(__('Visual designer with live preview and custom CSS', 'modify-login'), true, true),
                array(__('Templates', 'modify-login'), '12', '34'),
                array(__('Animated, seasonal and video backgrounds', 'modify-login'), false, true),
                array(__('Branded emails', 'modify-login'), false, true),
                array(__('Login and account blocks, popup login, WooCommerce skin', 'modify-login'), false, true),
            )),
            array('building', __('Sites and teams', 'modify-login'), array(
                array(__('Import from other login plugins; settings export', 'modify-login'), true, true),
                array(__('WP-CLI', 'modify-login'), __('Essentials', 'modify-login'), __('Full, incl. bulk and multisite', 'modify-login')),
                array(__('REST API', 'modify-login'), false, true),
                array(__('Multisite', 'modify-login'), __('Network-wide settings', 'modify-login'), __('Per-site overrides and locks', 'modify-login')),
                array(__('White-label and client handoff', 'modify-login'), false, true),
                array(__('Share login designs between sites', 'modify-login'), false, true),
            )),
            array('help', __('Support and updates', 'modify-login'), array(
                array(__('Support', 'modify-login'), __('Community forum', 'modify-login'), __('Priority email', 'modify-login')),
                array(__('Updates', 'modify-login'), __('WordPress.org', 'modify-login'), __('One click, with a license', 'modify-login')),
            )),
        );
    }

    /**
     * One table cell.
     *
     * @param bool|string $value Value.
     * @return string HTML.
     */
    private static function cell($value)
    {
        if (true === $value) {
            return '<span class="authlify-compare__yes">' . UI::icon('check', 16) . '<span class="screen-reader-text">' . esc_html__('Included', 'modify-login') . '</span></span>';
        }
        if (false === $value) {
            return '<span class="authlify-compare__no" aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('Not included', 'modify-login') . '</span>';
        }

        return '<span class="authlify-compare__text">' . esc_html($value) . '</span>';
    }

    /**
     * Render.
     */
    public static function render()
    {
        $new_tab = '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'modify-login') . '</span>';
        ?>
        <div class="wrap authlify-page authlify-compare">
            <?php UI::header(__('Free vs Pro', 'modify-login'), __('Everything in Authlify stays free. Pro adds tools for teams, stores and agencies.', 'modify-login'), array(
                array('label' => __('Full comparison', 'modify-login'), 'url' => Docs::upgrade_url('free-vs-pro', 'compare'), 'target' => true),
            )); ?>

            <div class="authlify-plans">
                <?php foreach (self::plans() as $plan) : ?>
                    <div class="authlify-plan<?php echo !empty($plan['featured']) ? ' is-featured' : ''; ?>">
                        <div class="authlify-plan__head">
                            <h2><?php echo esc_html($plan['name']); ?></h2>
                            <?php if (!empty($plan['featured'])) : ?>
                                <span class="authlify-plan__tag"><?php esc_html_e('Most popular', 'modify-login'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="authlify-plan__sites">
                            <?php
                            /* translators: %d: number of sites */
                            echo esc_html(sprintf(_n('%d site', '%d sites', (int) $plan['sites'], 'modify-login'), (int) $plan['sites']));
                            ?>
                        </p>
                        <p class="authlify-plan__price">
                            <strong>$<?php echo esc_html($plan['yearly'][0]); ?></strong>
                            <span><?php esc_html_e('/ year', 'modify-login'); ?></span>
                        </p>
                        <a class="button<?php echo !empty($plan['featured']) ? ' button-primary' : ''; ?> authlify-plan__buy" href="<?php echo esc_url(self::checkout_url($plan['yearly'][1])); ?>" target="_blank" rel="noopener">
                            <?php
                            /* translators: %s: plan name */
                            echo esc_html(sprintf(__('Get %s', 'modify-login'), $plan['name']));
                            echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </a>
                        <a class="authlify-plan__alt" href="<?php echo esc_url(self::checkout_url($plan['lifetime'][1])); ?>" target="_blank" rel="noopener">
                            <?php
                            /* translators: %s: price */
                            echo esc_html(sprintf(__('or $%s once, for life', 'modify-login'), $plan['lifetime'][0]));
                            echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="authlify-strip">
                <?php echo Dashboard::status_icon(true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <p class="authlify-strip__text"><strong><?php esc_html_e('Our promise:', 'modify-login'); ?></strong> <?php esc_html_e('no free feature will ever move into Pro, and Pro keeps working if a license lapses; the license only brings updates and support. 14-day money-back guarantee.', 'modify-login'); ?></p>
            </div>

            <?php UI::panel_start(__('Compare features', 'modify-login')); ?>
                <div class="authlify-compare__scroll">
                    <table class="authlify-table authlify-compare__table">
                        <caption class="screen-reader-text"><?php esc_html_e('Features in Authlify and Authlify Pro', 'modify-login'); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Feature', 'modify-login'); ?></th>
                                <th scope="col" class="authlify-compare__col"><?php esc_html_e('Free', 'modify-login'); ?></th>
                                <th scope="col" class="authlify-compare__col authlify-compare__col--pro"><?php esc_html_e('Pro', 'modify-login'); ?></th>
                            </tr>
                        </thead>
                        <?php foreach (self::groups() as $group) : ?>
                            <tbody>
                                <tr class="authlify-compare__group">
                                    <th scope="colgroup" colspan="3"><?php echo UI::icon($group[0], 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html($group[1]); ?></th>
                                </tr>
                                <?php foreach ($group[2] as $row) : ?>
                                    <tr>
                                        <th scope="row"><?php echo esc_html($row[0]); ?></th>
                                        <td class="authlify-compare__col"><?php echo self::cell($row[1]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                        <td class="authlify-compare__col authlify-compare__col--pro"><?php echo self::cell($row[2]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php UI::panel_end(); ?>
        </div>
        <?php
    }
}
