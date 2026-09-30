<?php
/**
 * Dashboard screen.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Log\Log;
use Authlify\Login\Router;
use Authlify\Security\Limiter;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Security status at a glance: what is protected, what to do next, and the
 * last seven days of login activity.
 */
final class Dashboard
{
    /**
     * Checklist items: key => array( done, title, text, url ).
     *
     * @return array
     */
    public static function checks()
    {
        $slug = Router::slug();

        $checks = array(
            'login_url' => array(
                '' !== $slug,
                __('Custom login URL', 'modify-login'),
                '' !== $slug ? sprintf(__('Your login page is at %s', 'modify-login'), Router::login_url()) : __('Bots are guessing passwords at wp-login.php. Move it.', 'modify-login'),
                Menu::url('login-url'),
            ),
            'hide' => array(
                Router::is_hiding(),
                __('Default login hidden', 'modify-login'),
                Router::is_hiding() ? ('403' === Settings::get('blocked_response') ? __('wp-login.php and wp-admin show "access denied" to visitors.', 'modify-login') : ('redirect' === Settings::get('blocked_response') ? __('wp-login.php and wp-admin redirect visitors elsewhere.', 'modify-login') : __('wp-login.php and wp-admin show "page not found" to visitors.', 'modify-login'))) : __('wp-login.php still works for everyone.', 'modify-login'),
                Menu::url('login-url'),
            ),
            'limits' => array(
                Limiter::enabled(),
                __('Brute-force protection', 'modify-login'),
                Limiter::enabled() ? sprintf(__('%1$d attempts per %2$d minutes, then a lockout.', 'modify-login'), (int) Settings::get('limit_attempts'), (int) Settings::get('limit_window')) : __('Nothing stops an attacker from trying thousands of passwords.', 'modify-login'),
                Menu::url('protection'),
            ),
            'xmlrpc' => array(
                'on' !== Settings::get('xmlrpc'),
                __('XML-RPC password guessing', 'modify-login'),
                'on' !== Settings::get('xmlrpc') ? __('Multi-password XML-RPC requests are blocked.', 'modify-login') : __('XML-RPC lets attackers try hundreds of passwords per request.', 'modify-login'),
                Menu::url('protection', array('tab' => 'hardening')),
            ),
        );

        // Another two-factor plugin took over: Authlify's second step is paused.
        if (class_exists('Authlify\\TwoFactor\\TwoFactor') && '' !== ($other = \Authlify\TwoFactor\TwoFactor::other_provider())) {
            $checks['two_factor_other'] = array(
                false,
                __('Two-factor login is paused', 'modify-login'),
                /* translators: %s: plugin name */
                sprintf(__('%s is active and handles two-factor login instead. People who set up two-factor login with Authlify now sign in with only a password.', 'modify-login'), $other),
                Menu::url('two-factor'),
            );
        }

        /**
         * Filters the dashboard checklist (CAPTCHA and two-factor add theirs).
         *
         * @param array $checks key => array( done, title, text, url ).
         * @since 3.0.0
         */
        return apply_filters('authlify_dashboard_checks', $checks);
    }

    /**
     * Render.
     */
    public static function render()
    {
        $checks = self::checks();
        $done = count(array_filter(wp_list_pluck($checks, 0)));
        $total = max(1, count($checks));
        $counts = Log::counts(time() - 7 * DAY_IN_SECONDS);
        // Where each checklist item is explained.
        $docs = array(
            'login_url' => 'login-url',
            'hide' => 'howto-hide-login',
            'limits' => 'brute-force',
            'xmlrpc' => 'hardening',
            'captcha' => 'captcha',
            'two_factor' => 'two-factor',
            'two_factor_other' => 'trouble-conflicts',
            'leak_check' => 'leak-check',
        );
        $user = wp_get_current_user();
        $name = '' !== (string) $user->first_name ? $user->first_name : $user->display_name;
        ?>
        <div class="wrap authlify-page authlify-dash">
            <?php
            UI::page_head(
                /* translators: %s: user's first name or display name */
                sprintf(__('Welcome, %s', 'modify-login'), $name),
                esc_html__('How well your login is protected, and what happened this week.', 'modify-login') . ' ' . UI::learn_more('recommended-setup')
            );

            do_action('authlify_dashboard_top');
            ?>

            <div class="authlify-stats">
                <?php
                $failed = isset($counts['login_failed']) ? (int) $counts['login_failed'] : 0;
                $locked_now = count(Limiter::active_lockouts());
                UI::stat(__('Logins, 7 days', 'modify-login'), isset($counts['login_success']) ? (int) $counts['login_success'] : 0, 'log-in', Menu::url('activity', array('event' => 'login_success')));
                UI::stat(__('Failed, 7 days', 'modify-login'), $failed, 'alert', Menu::url('activity', array('event' => 'login_failed')));
                UI::stat(__('Lockouts, 7 days', 'modify-login'), isset($counts['lockout']) ? (int) $counts['lockout'] : 0, 'lock', Menu::url('activity', array('event' => 'lockout')));
                UI::stat(__('Locked out now', 'modify-login'), $locked_now, 'ban', Menu::url('protection'), '', $locked_now ? 'warning' : '');
                $coverage = self::twofa_coverage();
                if ($coverage) {
                    UI::stat(__('Admins with 2FA', 'modify-login'), sprintf(/* translators: 1: admins with 2FA, 2: all admins */ __('%1$d of %2$d', 'modify-login'), $coverage['with'], $coverage['total']), 'smartphone', Menu::url('two-factor'), '', $coverage['with'] >= $coverage['total'] ? 'ok' : '');
                }
                ?>
            </div>

            <div class="authlify-dash__grid">
                <div class="authlify-dash__main">
                    <?php
                    $aside = '<span class="authlify-badge authlify-badge--' . ($done >= count($checks) ? 'ok' : 'accent') . '">' . esc_html(sprintf(/* translators: 1: done, 2: total */ __('%1$d of %2$d done', 'modify-login'), $done, count($checks))) . '</span>';
                    UI::panel_start(
                        $done >= count($checks) ? __('Your login is well protected', 'modify-login') : __('Protect your login', 'modify-login'),
                        __('The protections that matter most, in the order to set them up.', 'modify-login'),
                        'authlify-checklist',
                        '',
                        $aside
                    );
                    ?>
                    <div class="authlify-progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo (int) count($checks); ?>" aria-valuenow="<?php echo (int) $done; ?>" aria-label="<?php esc_attr_e('Protection progress', 'modify-login'); ?>"><span style="width: <?php echo (int) round($done / $total * 100); ?>%"></span></div>
                    <ol class="authlify-steps">
                        <?php $i = 0; ?>
                        <?php foreach ($checks as $key => $check) : ?>
                            <?php ++$i; ?>
                            <li class="authlify-step<?php echo $check[0] ? ' is-done' : ''; ?>">
                                <span class="authlify-step__mark" aria-hidden="true"><?php echo $check[0] ? UI::icon('check', 14) : (int) $i; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG / int. ?></span>
                                <span class="authlify-step__text">
                                    <strong><span class="screen-reader-text"><?php echo $check[0] ? esc_html__('Done:', 'modify-login') : esc_html__('Not set up:', 'modify-login'); ?> </span><?php echo esc_html($check[1]); ?></strong>
                                    <span><?php echo esc_html($check[2]); ?><?php echo isset($docs[$key]) ? ' ' . UI::learn_more($docs[$key]) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in learn_more(). ?></span>
                                </span>
                                <a class="button button-small<?php echo $check[0] ? ' authlify-button-ghost' : ''; ?>" href="<?php echo esc_url($check[3]); ?>"><?php echo $check[0] ? esc_html__('Review', 'modify-login') : esc_html__('Set up', 'modify-login'); ?><span class="screen-reader-text"> <?php echo esc_html($check[1]); ?></span></a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <?php UI::panel_end(); ?>

                    <?php
                    /**
                     * Adds cards to the Dashboard's main column (Authlify Pro: features, insights).
                     *
                     * @since 3.0.0
                     */
                    do_action('authlify_dashboard');
                    ?>

                    <?php self::recent(); ?>
                </div>

                <aside class="authlify-dash__aside" aria-label="<?php esc_attr_e('Status and help', 'modify-login'); ?>">
                    <?php
                    self::health();

                    /**
                     * Adds cards to the Dashboard's side column (Authlify Pro: the licence).
                     *
                     * @since 3.0.0
                     */
                    do_action('authlify_dashboard_side');

                    self::pro_card();
                    self::resources();
                    ?>
                </aside>
            </div>
        </div>
        <?php
    }

    /**
     * Administrators with two-factor login (when the module runs here).
     *
     * @return array total, with. Empty when not available.
     */
    private static function twofa_coverage()
    {
        if (!class_exists('Authlify\\TwoFactor\\TwoFactor') || '' !== \Authlify\TwoFactor\TwoFactor::other_provider()) {
            return array();
        }
        $coverage = \Authlify\TwoFactor\TwoFactor::coverage();
        if (empty($coverage['administrator']['total'])) {
            return array();
        }

        return array('total' => (int) $coverage['administrator']['total'], 'with' => (int) $coverage['administrator']['with']);
    }

    /**
     * Health card: the Leak Check in one line, details folded away.
     */
    private static function health()
    {
        UI::panel_start(__('Health', 'modify-login'), '', 'authlify-leak-check');
        if (class_exists('Authlify\\Diagnostics\\LeakCheck')) {
            \Authlify\Diagnostics\LeakCheck::render_health();
        } else {
            echo '<p class="authlify-health__line is-ok">' . UI::icon('check', 18) . '<span class="authlify-health__text"><strong>' . esc_html__('Everything looks good.', 'modify-login') . '</strong></span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
        }
        UI::panel_end();
    }

    /**
     * The one Authlify Pro card (only while Pro is not active).
     */
    private static function pro_card()
    {
        if (!Upsell::active()) {
            return;
        }
        ?>
        <section class="authlify-panel authlify-upsell-card" data-authlify-upsell="dashboard">
            <div class="authlify-panel__body">
                <span class="authlify-badge authlify-badge--pro"><?php esc_html_e('Authlify Pro', 'modify-login'); ?></span>
                <h2 class="authlify-upsell-card__title"><?php esc_html_e('Protect every account on the site', 'modify-login'); ?></h2>
                <ul class="authlify-checks-list">
                    <?php
                    foreach (array(
                        __('Two-factor login required by role', 'modify-login'),
                        __('Alerts for new devices and countries', 'modify-login'),
                        __('Social sign-in and login links', 'modify-login'),
                        __('Sessions, password policy and access rules', 'modify-login'),
                        __('White-label and agency tools', 'modify-login'),
                    ) as $point) {
                        echo '<li>' . UI::icon('check', 16) . esc_html($point) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
                    }
                    ?>
                </ul>
                <a class="button authlify-button-pro" href="<?php echo esc_url(Menu::slug_url('authlify-pro')); ?>"><?php esc_html_e('Compare Free and Pro', 'modify-login'); ?></a>
            </div>
        </section>
        <?php
    }

    /**
     * Help & resources.
     */
    private static function resources()
    {
        $brand = UI::brand();
        if (empty($brand['links'])) {
            return;
        }
        UI::panel_start(__('Help & resources', 'modify-login'));
        echo '<ul class="authlify-links">';
        echo '<li><a href="' . esc_url(Docs::url()) . '">' . UI::icon('book', 18) . '<span>' . esc_html__('Documentation', 'modify-login') . '</span></a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
        foreach (array(
            array('https://wordpress.org/support/plugin/modify-login/', __('Support forum', 'modify-login'), 'help'),
            array('https://wordpress.org/support/plugin/modify-login/reviews/#new-post', __('Leave a review', 'modify-login'), 'spark'),
        ) as $link) {
            echo '<li><a href="' . esc_url($link[0]) . '" target="_blank" rel="noopener">' . UI::icon($link[2], 18) . '<span>' . esc_html($link[1]) . '</span>' . UI::icon('external', 14) . '<span class="screen-reader-text">' . esc_html__('(opens in a new tab)', 'modify-login') . '</span></a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
        }
        echo '</ul>';
        UI::panel_end();
    }

    /**
     * Check / attention icon.
     *
     * The icon itself is hidden from screen readers; pass $label to announce
     * the state in words (colour and shape are never the only signal).
     *
     * @param bool        $ok    Done.
     * @param string|null $label Screen-reader text for the state, e.g. "Done:". Null for a decorative icon.
     * @return string Markup.
     */
    public static function status_icon($ok, $label = null)
    {
        $text = null !== $label && '' !== (string) $label ? '<span class="screen-reader-text">' . esc_html($label) . ' </span>' : '';

        if ($ok) {
            return $text . '<span class="authlify-status-icon authlify-status-icon--ok" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none"><path d="M3.5 8.5l3 3 6-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
        }

        return $text . '<span class="authlify-status-icon" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none"><path d="M8 4.5v4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="8" cy="11.8" r="1.1" fill="currentColor"/></svg></span>';
    }

    /**
     * Latest events.
     */
    private static function recent()
    {
        $result = Log::query(array('per_page' => 8, 'count' => false));
        $events = Log::events();

        UI::panel_start(__('Latest activity', 'modify-login'), '', '', '', $result['rows'] ? '<a class="button button-small authlify-button-ghost" href="' . esc_url(Menu::url('activity')) . '">' . esc_html__('See all activity', 'modify-login') . '</a>' : '');
        if (!$result['rows']) {
            echo '<p class="authlify-empty">' . esc_html__('No activity yet. Logins and failed attempts will appear here.', 'modify-login') . '</p>';
        } else {
            echo '<ul class="authlify-feed">';
            foreach ($result['rows'] as $row) {
                printf(
                    '<li><span class="authlify-feed__event">%1$s</span><span class="authlify-feed__who">%2$s</span><code class="authlify-feed__ip">%3$s</code><time class="authlify-feed__when" datetime="%4$s">%5$s</time></li>',
                    UI::pill(isset($events[$row->event]) ? $events[$row->event] : $row->event, ActivityPage::tone($row->event)), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    esc_html('' !== $row->username ? $row->username : '—'),
                    esc_html($row->ip),
                    esc_attr(mysql2date('c', $row->created_at . ' UTC')),
                    /* translators: %s: time, e.g. "5 minutes" */
                    esc_html(sprintf(__('%s ago', 'modify-login'), human_time_diff(strtotime($row->created_at . ' UTC'))))
                );
            }
            echo '</ul>';
        }
        UI::panel_end();
    }
}
