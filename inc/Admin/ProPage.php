<?php
/**
 * Free vs Pro screen (shown only while Pro is not installed).
 *
 * @package Authlify
 */

namespace Authlify\Admin;

defined('ABSPATH') || exit;

/**
 * Why someone would want Pro, the plans, what happens after paying and after
 * stopping, and the full comparison a click away. Every claim here has to be
 * true of the shipped code. Buying and installing happen outside the plugin:
 * these are links and written steps only (WordPress.org guideline 8).
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
     * The buyer's account on the store, and the refund policy.
     */
    const ACCOUNT = 'https://store.mantrabrain.com/account/';
    const REFUNDS = 'https://mantrabrain.com/refund-policy/';

    /**
     * The Authlify Pro video.
     */
    const VIDEO = 'https://www.youtube.com/watch?v=wNZVy6q90B8';

    /**
     * Plans. Price IDs match the store's variable prices.
     *
     * @return array[] name, slug, sites, line, yearly (price, price_id), lifetime (price, price_id), featured.
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
                'slug' => 'personal',
                'sites' => 1,
                'line' => __('For one business site with staff or members.', 'modify-login'),
                'yearly' => array('49', 1),
                'lifetime' => array('129', 4),
                'featured' => false,
            ),
            array(
                'name' => __('Plus', 'modify-login'),
                'slug' => 'plus',
                'sites' => 5,
                'line' => __('For a business or store with a few sites.', 'modify-login'),
                'yearly' => array('99', 2),
                'lifetime' => array('249', 5),
                'featured' => true,
            ),
            array(
                'name' => __('Agency', 'modify-login'),
                'slug' => 'agency',
                'sites' => 25,
                'line' => __('For client work: white-label, handoff, and priority support.', 'modify-login'),
                'yearly' => array('149', 3),
                'lifetime' => array('399', 6),
                'featured' => false,
            ),
        ));
    }

    /**
     * Direct checkout link for one price, attributed to the place it sits.
     *
     * @param int    $price_id Store price ID.
     * @param string $content  Placement (utm_content), e.g. freevspro-plus-yearly.
     * @return string
     */
    public static function checkout_url($price_id, $content = 'freevspro')
    {
        return add_query_arg(array(
            'edd_action' => 'add_to_cart',
            'download_id' => self::DOWNLOAD_ID,
            'edd_options[price_id]' => (int) $price_id,
            'utm_source' => 'authlify-free',
            'utm_medium' => 'plugin',
            'utm_campaign' => 'upgrade',
            'utm_content' => sanitize_key($content),
        ), self::CHECKOUT);
    }

    /**
     * Comparison rows, grouped. A label is plain text, or array( benefit, detail ).
     * A cell is true (included), false (not included) or a short string.
     *
     * Rows that differ between Free and Pro make up the "What Pro adds" table,
     * and every row is in the full comparison. Say why someone would want it,
     * and only what the shipped code does.
     *
     * New Pro features go in the matching group below, as a benefit row. Name a
     * third-party integration as tested only once it has run against the real plugin.
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
                array(array(__('Catch bots looking for your login page.', 'modify-login'), __('A trap address bans the ones that try it.', 'modify-login')), false, true),
            )),
            array('shield', __('Security', 'modify-login'), array(
                array(__('Brute-force lockouts with escalation, allow and deny lists', 'modify-login'), true, true),
                array(__('Block an IP from the log, and after repeated lockouts', 'modify-login'), true, true),
                array(__('Real visitor IP behind Cloudflare and proxies', 'modify-login'), true, true),
                array(__('Hardening: XML-RPC, application passwords, user enumeration, force login', 'modify-login'), true, true),
                array(__('CAPTCHA: Turnstile, hCaptcha, reCAPTCHA, ALTCHA and honeypot', 'modify-login'), true, true),
                array(__('CAPTCHA on WooCommerce (incl. block checkout), EDD, Ultimate Member, Paid Memberships Pro and BuddyPress forms; MemberPress and LearnDash in beta', 'modify-login'), true, true),
                array(__('Breached-password check when a password is set', 'modify-login'), true, true),
                array(array(__('Stop weak and reused passwords.', 'modify-login'), __('Password rules, expiry and a breached-password check at login.', 'modify-login')), false, true),
                array(array(__('Sign people out of forgotten browsers.', 'modify-login'), __('Session length, idle logout and limits on concurrent logins.', 'modify-login')), false, true),
                array(array(__('Refuse logins from countries you don\'t work in.', 'modify-login'), __('Rules by country and by time of day. Countries come from your CDN\'s header or a local database you turn on.', 'modify-login')), false, true),
                array(array(__('Country rules that tell you when they can\'t work.', 'modify-login'), __('A warning, and one click to the local country database.', 'modify-login')), false, true),
                array(array(__('Deal with accounts nobody uses.', 'modify-login'), __('Flag them, warn their owners, then ask for a new password or block sign-in, with a preview first.', 'modify-login')), false, true),
            )),
            array('smartphone', __('Two-factor login', 'modify-login'), array(
                array(__('Authenticator apps, backup codes and passkeys', 'modify-login'), true, true),
                array(__('Sign in with a passkey, no password', 'modify-login'), true, true),
                array(__('Import authenticator apps from Wordfence, Two Factor and WP 2FA', 'modify-login'), true, true),
                array(array(__('Stop relying on staff to turn on 2FA.', 'modify-login'), __('Require it by role, with a grace period and a setup wizard.', 'modify-login')), false, true),
                array(array(__('See who still has no 2FA.', 'modify-login'), __('A coverage report for each role, with CSV export.', 'modify-login')), false, true),
                array(array(__('2FA for people without an authenticator app.', 'modify-login'), __('Codes by email.', 'modify-login')), false, true),
                array(array(__('Fewer codes for your team.', 'modify-login'), __('Skip the second step on devices they trust.', 'modify-login')), false, true),
                array(array(__('Protect sensitive changes on a borrowed or left-open browser.', 'modify-login'), __('People confirm it is them first.', 'modify-login')), false, true),
                array(array(__('2FA your store\'s customers can manage themselves.', 'modify-login'), __('The second step and a Security tab inside WooCommerce My Account.', 'modify-login')), false, true),
            )),
            array('log-in', __('Sign-in methods', 'modify-login'), array(
                array(array(__('Let staff sign in with the accounts they already use.', 'modify-login'), __('Google, Microsoft, Apple, GitHub and OpenID Connect.', 'modify-login')), false, true),
                array(array(__('More accounts people already have.', 'modify-login'), __('Facebook, LinkedIn and Discord sign-in, with your domain and role rules still in charge.', 'modify-login')), false, true),
                array(array(__('Keep your login URL out of sign-in redirects.', 'modify-login'), __('The callback address for Facebook, LinkedIn and Discord never contains your hidden login URL. Other providers can use it too.', 'modify-login')), false, true),
                array(array(__('Sign-in options on store and membership forms.', 'modify-login'), __('Social buttons, login links and the second step on Easy Digital Downloads and Ultimate Member, with MemberPress and LearnDash support in beta.', 'modify-login')), false, true),
                array(array(__('Give support a login that expires by itself.', 'modify-login'), __('Temporary access links for support staff and clients.', 'modify-login')), false, true),
                array(array(__('Sign in without a password.', 'modify-login'), __('Magic links and email sign-in codes.', 'modify-login')), false, true),
            )),
            array('activity', __('Activity and alerts', 'modify-login'), array(
                array(__('Activity log with filters, CSV export and privacy tools', 'modify-login'), true, true),
                array(__('Email users about sign-ins from a new device or IP', 'modify-login'), true, true),
                array(array(__('See where attempts come from, without Cloudflare.', 'modify-login'), __('Visitor country.', 'modify-login')), __('From Cloudflare', 'modify-login'), __('Cloudflare or local database', 'modify-login')),
                array(array(__('Know when someone signs in from somewhere new.', 'modify-login'), __('New-device and new-country alerts, with a “This wasn’t me” link.', 'modify-login')), false, true),
                array(array(__('Get alerts where your team already works.', 'modify-login'), __('Slack, Discord, Telegram, Teams and signed webhooks.', 'modify-login')), false, true),
                array(array(__('Hear about changes that often mean a takeover.', 'modify-login'), __('New admins, role and plugin changes.', 'modify-login')), false, true),
                array(array(__('See who is attacking your login.', 'modify-login'), __('Insights and scheduled reports.', 'modify-login')), false, true),
                array(array(__('See who changed which security setting.', 'modify-login'), __('A settings change log that says how it was changed, and never writes down a secret.', 'modify-login')), false, true),
                array(array(__('Show who has access.', 'modify-login'), __('An access review report, printable and as CSV: who has privileged access, how they sign in and when they last did. It helps with evidence for your own reviews.', 'modify-login')), false, true),
                array(array(__('See what your AI tools do.', 'modify-login'), __('The log names the source when an application password or agent acts.', 'modify-login')), false, true),
            )),
            array('palette', __('Login page design', 'modify-login'), array(
                array(__('Visual designer with live preview and custom CSS', 'modify-login'), true, true),
                array(array(__('22 more login designs.', 'modify-login'), __('Templates in total:', 'modify-login')), '12', '34'),
                array(array(__('A login page people remember.', 'modify-login'), __('Animated, seasonal and video backgrounds.', 'modify-login')), false, true),
                array(array(__('Emails that match your brand.', 'modify-login'), __('Branded emails with your logo and colors.', 'modify-login')), false, true),
                array(array(__('Put login where your visitors are.', 'modify-login'), __('Login and account blocks, popup login and a WooCommerce skin.', 'modify-login')), false, true),
            )),
            array('building', __('Sites and teams', 'modify-login'), array(
                array(__('Import from other login plugins; settings export', 'modify-login'), true, true),
                array(array(__('Your brand, and clients can\'t change your settings.', 'modify-login'), __('White-label and client handoff.', 'modify-login')), false, true),
                array(array(__('Run a network your way.', 'modify-login'), __('Multisite.', 'modify-login')), __('Network-wide settings', 'modify-login'), __('Per-site overrides and locks', 'modify-login')),
                array(array(__('Reuse one design on every client site.', 'modify-login'), __('Share login designs between sites.', 'modify-login')), false, true),
                array(array(__('Connect Authlify to your own tools.', 'modify-login'), __('REST API.', 'modify-login')), false, true),
                array(array(__('Manage sites from the command line.', 'modify-login'), __('WP-CLI.', 'modify-login')), __('Essentials', 'modify-login'), __('Full, incl. bulk and multisite', 'modify-login')),
                array(array(__('Let an approved tool or AI agent act for you.', 'modify-login'), __('Abilities that lift a lockout, block an address or end sessions, with permission checks. WordPress 6.9 or later. Private by default; a developer enables them with a one-line filter.', 'modify-login')), __('Read-only status', 'modify-login'), __('Read and act', 'modify-login')),
            )),
            array('help', __('Support and updates', 'modify-login'), array(
                array(__('Support', 'modify-login'), __('Community forum', 'modify-login'), __('Email (priority on Agency)', 'modify-login')),
                array(__('Updates', 'modify-login'), __('WordPress.org', 'modify-login'), __('One click, with a license', 'modify-login')),
                array(array(__('Find your way around Pro.', 'modify-login'), __('A getting-started card shows what Pro added and where it lives, right after you activate.', 'modify-login')), false, true),
            )),
        );
    }

    /**
     * Questions and answers. "What happens when I stop paying" describes
     * AuthlifyPro\License: the license only gates the update package and support.
     *
     * @return array question => answer.
     */
    public static function faq()
    {
        return array(
            __('What happens when I stop paying?', 'modify-login') => __('Pro keeps working. No feature, setting or login rule depends on the license, so two-factor rules, alerts and everything else stay on. Two things stop: one-click updates and support. The Plugins screen still tells you when a new version exists, and you can renew at any time to update again.', 'modify-login'),
            __('Can I get a refund?', 'modify-login') => __('Yes. There is a 14-day money-back guarantee. If Pro isn\'t for you, ask within 14 days of buying.', 'modify-login'),
            __('How many sites can I use it on?', 'modify-login') => __('Personal covers 1 site, Plus 5 and Agency 25. Every plan includes every Pro feature. Each site where you activate the license uses one place, and deactivating the license on a site frees it. A network-activated multisite uses one place, for the whole network.', 'modify-login'),
            __('Yearly or lifetime?', 'modify-login') => __('Yearly plans renew each year until you cancel, and you can cancel any time. Lifetime plans are paid once, with updates and support for life and nothing to renew. Lifetime pays for itself in under 3 years.', 'modify-login'),
            __('Will my free setup keep working?', 'modify-login') => __('Yes. Pro is an add-on and needs the free plugin, so keep both active. Your login URL, settings and log stay as they are, and rules such as required two-factor or login by country stay off until you turn them on. No free feature will ever move into Pro.', 'modify-login'),
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
     * One comparison table.
     *
     * @param bool   $pro_only Only the rows where Pro adds something.
     * @param string $caption  Caption for screen readers.
     */
    private static function table($pro_only, $caption)
    {
        ?>
        <div class="authlify-compare__scroll">
            <table class="authlify-table authlify-compare__table">
                <caption class="screen-reader-text"><?php echo esc_html($caption); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Feature', 'modify-login'); ?></th>
                        <th scope="col" class="authlify-compare__col"><?php esc_html_e('Free', 'modify-login'); ?></th>
                        <th scope="col" class="authlify-compare__col authlify-compare__col--pro"><?php esc_html_e('Pro', 'modify-login'); ?></th>
                    </tr>
                </thead>
                <?php foreach (self::groups() as $group) : ?>
                    <?php
                    $rows = array_filter($group[2], function ($row) use ($pro_only) {
                        return !$pro_only || $row[1] !== $row[2];
                    });
                    if (!$rows) {
                        continue;
                    }
                    ?>
                    <tbody>
                        <tr class="authlify-compare__group">
                            <th scope="colgroup" colspan="3"><?php echo UI::icon($group[0], 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html($group[1]); ?></th>
                        </tr>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <th scope="row">
                                    <?php if (is_array($row[0])) : ?>
                                        <strong><?php echo esc_html($row[0][0]); ?></strong> <?php echo esc_html($row[0][1]); ?>
                                    <?php else : ?>
                                        <?php echo esc_html($row[0]); ?>
                                    <?php endif; ?>
                                </th>
                                <td class="authlify-compare__col"><?php echo self::cell($row[1]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                <td class="authlify-compare__col authlify-compare__col--pro"><?php echo self::cell($row[2]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }

    /**
     * Render.
     */
    public static function render()
    {
        $new_tab = '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'modify-login') . '</span>';
        $plans = self::plans();
        $shots = array(
            'two-factor-rules' => array(__('Require two-factor by role', 'modify-login'), __('Pick the roles and a grace period. People get a short setup wizard right after their password.', 'modify-login'), 960, 497),
            'login-alerts' => array(__('Login alerts', 'modify-login'), __('Tell people about a login from somewhere new, and yourself about changes that matter.', 'modify-login'), 960, 484),
            'designs' => array(__('22 more designs', 'modify-login'), __('Animated, video, seasonal and industry templates, right in the Designer.', 'modify-login'), 960, 667),
        );
        ?>
        <div class="wrap authlify-page authlify-compare">
            <?php UI::header(__('Free vs Pro', 'modify-login'), __('What is free in Authlify stays free. Pro is an add-on for sites with staff, customers or clients.', 'modify-login'), array(
                array('label' => __('Compare on our website', 'modify-login'), 'url' => Docs::upgrade_url('freevspro-header', 'compare'), 'target' => true),
            )); ?>

            <section class="authlify-panel authlify-compare-hero">
                <div class="authlify-panel__body">
                    <h2><?php esc_html_e('Free protects your login. Pro makes it a rule for everyone who signs in.', 'modify-login'); ?></h2>
                    <p><?php esc_html_e('Require two-factor by role, sign in with Google or a magic link, and hear about it when something looks wrong.', 'modify-login'); ?></p>
                    <a class="authlify-compare-hero__video" href="<?php echo esc_url(self::VIDEO); ?>" target="_blank" rel="noopener"><?php echo UI::icon('external', 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?> <?php esc_html_e('Watch the Authlify Pro video on YouTube', 'modify-login'); ?><?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
                </div>
            </section>

            <div class="authlify-plans" id="authlify-plans">
                <?php foreach ($plans as $plan) : ?>
                    <?php $slug = isset($plan['slug']) ? (string) $plan['slug'] : sanitize_title($plan['name']); ?>
                    <div class="authlify-plan<?php echo !empty($plan['featured']) ? ' is-featured' : ''; ?>">
                        <div class="authlify-plan__head">
                            <h2><?php echo esc_html($plan['name']); ?></h2>
                        </div>
                        <p class="authlify-plan__sites">
                            <?php
                            /* translators: %d: number of sites */
                            echo esc_html(sprintf(_n('%d site', '%d sites', (int) $plan['sites'], 'modify-login'), (int) $plan['sites']));
                            ?>
                        </p>
                        <?php if (!empty($plan['line'])) : ?>
                            <p class="authlify-plan__line"><?php echo esc_html($plan['line']); ?></p>
                        <?php endif; ?>
                        <p class="authlify-plan__price">
                            <strong>$<?php echo esc_html($plan['yearly'][0]); ?></strong>
                            <span><?php esc_html_e('/ year', 'modify-login'); ?></span>
                        </p>
                        <p class="authlify-plan__renews"><?php esc_html_e('Renews yearly. Cancel any time.', 'modify-login'); ?></p>
                        <a class="button<?php echo !empty($plan['featured']) ? ' button-primary' : ''; ?> authlify-plan__buy" href="<?php echo esc_url(self::checkout_url($plan['yearly'][1], 'freevspro-' . $slug . '-yearly')); ?>" target="_blank" rel="noopener">
                            <?php
                            /* translators: %s: plan name */
                            echo esc_html(sprintf(__('Get %s', 'modify-login'), $plan['name']));
                            echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </a>
                        <a class="authlify-plan__alt" href="<?php echo esc_url(self::checkout_url($plan['lifetime'][1], 'freevspro-' . $slug . '-lifetime')); ?>" target="_blank" rel="noopener">
                            <?php
                            /* translators: %s: price */
                            echo esc_html(sprintf(__('or $%s once, with updates for life', 'modify-login'), $plan['lifetime'][0]));
                            echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="authlify-plans-note"><?php esc_html_e('Every plan includes every Pro feature. Lifetime pays for itself in under 3 years. Sold by MantraBrain, our store.', 'modify-login'); ?></p>

            <div class="authlify-strip">
                <?php echo Dashboard::status_icon(true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <p class="authlify-strip__text"><strong><?php esc_html_e('Try Pro for 14 days. If it isn\'t for you, get your money back.', 'modify-login'); ?></strong> <?php esc_html_e('Pro keeps working if a license lapses; the license only brings updates and support. No free feature will ever move into Pro.', 'modify-login'); ?></p>
            </div>

            <div class="authlify-shots">
                <?php foreach ($shots as $file => $shot) : ?>
                    <figure class="authlify-shot">
                        <a href="<?php echo esc_url(AUTHLIFY_URL . 'assets/admin/pro/' . $file . '.webp'); ?>" target="_blank" rel="noopener">
                            <?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <img src="<?php echo esc_url(AUTHLIFY_URL . 'assets/admin/pro/' . $file . '.webp'); ?>" alt="<?php echo esc_attr(sprintf(/* translators: %s: screen */ __('Authlify Pro screen: %s', 'modify-login'), $shot[0])); ?>" width="<?php echo (int) $shot[2]; ?>" height="<?php echo (int) $shot[3]; ?>" loading="lazy">
                        </a>
                        <figcaption><strong><?php echo esc_html($shot[0]); ?></strong> <?php echo esc_html($shot[1]); ?></figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>

            <?php UI::panel_start(__('What Pro adds', 'modify-login')); ?>
                <?php self::table(true, __('What Authlify Pro adds to Authlify', 'modify-login')); ?>
                <details class="authlify-details authlify-compare__all">
                    <summary><?php esc_html_e('Show everything in Free too', 'modify-login'); ?></summary>
                    <div><?php self::table(false, __('Features in Authlify and Authlify Pro', 'modify-login')); ?></div>
                </details>
            <?php UI::panel_end(); ?>

            <?php UI::panel_start(__('Already bought Pro?', 'modify-login'), __('Pro installs like any other plugin, next to the free one. Your settings stay as they are.', 'modify-login')); ?>
                <ol class="authlify-compare__steps">
                    <li><?php esc_html_e('Download the Authlify Pro zip file from your account.', 'modify-login'); ?></li>
                    <li><?php esc_html_e('Upload it under Plugins → Add New → Upload Plugin, then activate it. Keep the free plugin active.', 'modify-login'); ?></li>
                    <li><?php esc_html_e('Enter your license key under Authlify → Settings → License to get updates and support.', 'modify-login'); ?></li>
                </ol>
                <p class="authlify-compare__actions">
                    <a class="button" href="<?php echo esc_url(self::ACCOUNT); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open my account', 'modify-login'); ?><?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
                    <?php if (current_user_can('install_plugins')) : ?>
                        <a class="button" href="<?php echo esc_url(self_admin_url('plugin-install.php?tab=upload')); ?>"><?php esc_html_e('Upload the plugin', 'modify-login'); ?></a>
                    <?php endif; ?>
                </p>
            <?php UI::panel_end(); ?>

            <?php UI::panel_start(__('Questions', 'modify-login')); ?>
                <div class="authlify-compare__faq">
                    <?php $first = true; ?>
                    <?php foreach (self::faq() as $question => $answer) : ?>
                        <details class="authlify-details"<?php echo $first ? ' open' : ''; ?>>
                            <summary><?php echo esc_html($question); ?></summary>
                            <div><p><?php echo esc_html($answer); ?></p></div>
                        </details>
                        <?php $first = false; ?>
                    <?php endforeach; ?>
                </div>
                <p class="authlify-compare__refunds"><a href="<?php echo esc_url(self::REFUNDS); ?>" target="_blank" rel="noopener"><?php esc_html_e('Read the refund policy', 'modify-login'); ?><?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></p>
            <?php UI::panel_end(); ?>

            <section class="authlify-panel authlify-compare-close">
                <div class="authlify-panel__body">
                    <?php if (isset($plans[0]['yearly'][0], $plans[0]['lifetime'][0])) : ?>
                        <p>
                            <?php
                            /* translators: 1: yearly price, 2: one-time price */
                            echo esc_html(sprintf(__('From $%1$s a year, or $%2$s once. 14-day refund.', 'modify-login'), $plans[0]['yearly'][0], $plans[0]['lifetime'][0]));
                            ?>
                        </p>
                    <?php endif; ?>
                    <a class="button" href="#authlify-plans"><?php esc_html_e('Choose your plan', 'modify-login'); ?></a>
                </div>
            </section>
        </div>
        <?php
    }
}
