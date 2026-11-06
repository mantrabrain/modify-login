<?php
/**
 * Inline cards on Authlify's own screens.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Login\Router;
use Authlify\Plugin;

defined('ABSPATH') || exit;

/**
 * A card says one true thing about this site, what Pro does about it, one
 * link, and "Don't show again". The review request uses the same engine.
 *
 * The rules, within WordPress.org guideline 11:
 * - only on Authlify's own screens, never as an admin notice, popup or modal;
 * - only for people with the plugin's capability;
 * - nothing for the first 7 days after install;
 * - at most one per screen;
 * - dismissed per user, in user meta, and not shown to that user again;
 * - nothing at all while Pro is active or `authlify_show_pro_pointers` is false;
 * - a card only shows while its fact is true and non-zero.
 *
 * Never contacts anything: links are ordinary links the user chooses to click.
 *
 * @since 3.2.0
 */
final class Nudges
{
    /**
     * User meta: card id => true (never again) or a time to ask again.
     */
    const META = 'authlify_nudges';

    /**
     * Option holding the time Authlify was first seen on this site.
     */
    const FIRST_SEEN = 'authlify_first_seen';

    /**
     * Days after install with no cards.
     */
    const QUIET_DAYS = 7;

    /**
     * Days installed before the review request may show.
     */
    const REVIEW_DAYS = 14;

    /**
     * Where the review request sends people.
     */
    const REVIEW_URL = 'https://wordpress.org/support/plugin/modify-login/reviews/#new-post';

    /**
     * Thresholds for the failed-login card (starting values, not measured).
     */
    const MIN_LOCKOUTS = 3;
    const MIN_FAILED = 20;

    /**
     * Whether a card already claimed this screen.
     *
     * @var bool
     */
    private static $shown = false;

    /**
     * Wire up (called from Upsell::init(), admin only).
     */
    public static function init()
    {
        add_action('admin_init', array(__CLASS__, 'maybe_first_seen'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'), 30);
        add_action('wp_ajax_authlify_dismiss_nudge', array(__CLASS__, 'ajax_dismiss'));
        // Clearing the log clears the numbers quoted from it.
        add_action('admin_post_authlify_clear_log', array(Facts::class, 'flush'), 5);
    }

    /**
     * Start the install clock, from an Authlify screen only.
     */
    public static function maybe_first_seen()
    {
        if (Menu::is_screen()) {
            self::first_seen();
        }
    }

    /**
     * When Authlify was first seen here. New installs count from the install
     * itself; sites updated from an older version count from the update.
     *
     * @return int Timestamp.
     */
    public static function first_seen()
    {
        $network = is_multisite() && \Authlify\Settings::is_network();
        $seen = (int) ($network ? get_site_option(self::FIRST_SEEN, 0) : get_option(self::FIRST_SEEN, 0));
        if ($seen > 0) {
            return $seen;
        }

        $seen = (int) \Authlify\Install\Upgrader::get('authlify_fresh_install', 0);
        $seen = $seen > 0 ? $seen : time();
        if ($network) {
            add_site_option(self::FIRST_SEEN, $seen);
        } else {
            add_option(self::FIRST_SEEN, $seen, '', false);
        }

        return $seen;
    }

    /**
     * Whole days since Authlify was first seen here.
     *
     * @return int
     */
    public static function days_installed()
    {
        return (int) floor((time() - self::first_seen()) / DAY_IN_SECONDS);
    }

    /**
     * Every card id that can be dismissed.
     *
     * @return string[]
     */
    public static function ids()
    {
        return array(
            'review',
            'moment-2fa-coverage',
            'moment-lockouts',
            'moment-woo',
            'moment-network',
            'moment-designer',
            'moment-insights',
            'row-two-factor',
            'row-security',
            'row-alerts',
            'dashboard-card',
        );
    }

    /**
     * Dismissed cards of the current user.
     *
     * @return array
     */
    private static function dismissed()
    {
        $list = get_user_meta(get_current_user_id(), self::META, true);

        return is_array($list) ? $list : array();
    }

    /**
     * Whether the current user dismissed a card, for good or for now.
     *
     * @param string $id Card id.
     * @return bool
     */
    public static function is_dismissed($id)
    {
        $list = self::dismissed();
        if (!isset($list[$id])) {
            return false;
        }

        return true === $list[$id] || (int) $list[$id] > time();
    }

    /**
     * Dismiss a card for the current user.
     *
     * @param string $id    Card id.
     * @param int    $later Days to ask again after (at most 90). 0: never again.
     */
    public static function dismiss($id, $later = 0)
    {
        $list = self::dismissed();
        // "Don't show again" is never downgraded to "later".
        if (isset($list[$id]) && true === $list[$id]) {
            return;
        }
        $later = (int) $later;
        $list[$id] = $later > 0 ? time() + min(90, $later) * DAY_IN_SECONDS : true;
        update_user_meta(get_current_user_id(), self::META, $list);
    }

    /**
     * AJAX: dismiss a card.
     */
    public static function ajax_dismiss()
    {
        if (!check_ajax_referer('authlify_nudge', 'nonce', false)) {
            wp_send_json_error(null, 403);
        }
        if (!current_user_can(Plugin::cap())) {
            wp_send_json_error(null, 403);
        }
        $id = isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : '';
        if (!in_array($id, self::ids(), true)) {
            wp_send_json_error(null, 400);
        }
        self::dismiss($id, isset($_POST['later']) ? absint($_POST['later']) : 0);
        wp_send_json_success();
    }

    /**
     * Whether any card may show on this request at all.
     *
     * @return bool
     */
    public static function enabled()
    {
        return Upsell::active() && current_user_can(Plugin::cap()) && Menu::is_screen();
    }

    /**
     * Whether a card may show to the current user now.
     *
     * @param string $id    Card id.
     * @param bool   $quiet Whether the quiet period applies (false only for the Dashboard's own card).
     * @return bool
     */
    public static function can_show($id, $quiet = true)
    {
        if (!self::enabled() || self::is_dismissed($id)) {
            return false;
        }

        return !$quiet || self::days_installed() >= self::QUIET_DAYS;
    }

    /**
     * Claim the screen's one place for a card.
     *
     * @param string $id Card id.
     * @return bool False when it may not show, or the screen already has a card.
     */
    public static function claim($id)
    {
        if (self::$shown || !self::can_show($id)) {
            return false;
        }
        self::$shown = true;

        return true;
    }

    /**
     * Whether this screen already shows a card.
     *
     * @return bool
     */
    public static function shown()
    {
        return self::$shown;
    }

    /**
     * Forget the claim (a new "screen": used by the tests).
     */
    public static function reset_screen()
    {
        self::$shown = false;
    }

    /**
     * Styles and the dismiss script, on Authlify screens.
     */
    public static function assets()
    {
        if (!Menu::is_screen()) {
            return;
        }

        wp_enqueue_style('authlify-nudges', AUTHLIFY_URL . 'assets/admin/nudges.css', array('authlify-admin'), AUTHLIFY_VERSION);

        if (!self::enabled()) {
            return;
        }

        // The Designer's save is noticed through its own API client, which must load first.
        $deps = 'modify-login-builder' === Menu::current_slug() ? array('wp-api-fetch') : array();
        wp_enqueue_script('authlify-nudges', AUTHLIFY_URL . 'assets/admin/nudges.js', $deps, AUTHLIFY_VERSION, true);
        wp_localize_script('authlify-nudges', 'authlifyNudges', array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('authlify_nudge'),
            'hidden' => __('Hidden. It will not show again.', 'modify-login'),
            'later' => __('Hidden for now.', 'modify-login'),
        ));
    }

    /**
     * Print the card for a place on a screen, if one applies.
     *
     * @param string $place dashboard, two-factor, security, activity or designer.
     * @param array  $data  Optional. `week`: live Log::counts() result, so a card agrees with the numbers beside it.
     */
    public static function render($place, array $data = array())
    {
        if (!self::enabled()) {
            return;
        }

        // A placement's cards, best first. The first whose fact is true gets the place.
        $places = array(
            'dashboard' => array('review', 'lockouts', 'network'),
            'two-factor' => array('coverage', 'woo'),
            'security' => array('lockouts'),
            'activity' => array('insights'),
            'designer' => array('designer'),
        );
        if (!isset($places[$place])) {
            return;
        }

        foreach ($places[$place] as $card) {
            $html = call_user_func(array(__CLASS__, 'card_' . $card), $data);
            if ('' !== $html) {
                echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by card().

                return;
            }
        }
    }

    /**
     * Admins without two-factor login.
     *
     * @return string HTML.
     */
    private static function card_coverage()
    {
        $id = 'moment-2fa-coverage';
        if (!self::can_show($id)) {
            return '';
        }
        $admins = self::coverage_of('administrator');
        $without = $admins['total'] - $admins['with'];
        if ($admins['total'] < 2 || $without < 1) {
            return '';
        }

        $fact = sprintf(
            /* translators: 1: administrators without two-factor login, 2: all administrators */
            _n('%1$s of %2$s administrators has no two-factor login.', '%1$s of %2$s administrators have no two-factor login.', $without, 'modify-login'),
            number_format_i18n($without),
            number_format_i18n($admins['total'])
        );

        return self::card(
            $id,
            '<strong>' . esc_html($fact) . '</strong> ' . esc_html__('Each one chooses to set it up on Free. Pro can require it for the role, with a grace period.', 'modify-login'),
            Docs::feature_url('two-factor', $id),
            __('See required two-factor', 'modify-login')
        );
    }

    /**
     * WooCommerce store with customers.
     *
     * @return string HTML.
     */
    private static function card_woo()
    {
        $id = 'moment-woo';
        if (!self::can_show($id) || !class_exists('WooCommerce')) {
            return '';
        }
        $customers = self::coverage_of('customer');
        if ($customers['total'] < 1) {
            return '';
        }

        return self::card(
            $id,
            '<strong>' . esc_html__('Your store\'s customers can\'t manage two-factor from My Account on Free.', 'modify-login') . '</strong> ' . esc_html__('Pro adds it there, with a Security tab for devices and passkeys.', 'modify-login'),
            Docs::feature_url('pro-woocommerce', $id),
            __('See WooCommerce', 'modify-login')
        );
    }

    /**
     * Failed logins and lockouts this week.
     *
     * @param array $data Optional live counts in `week`.
     * @return string HTML.
     */
    private static function card_lockouts(array $data)
    {
        $id = 'moment-lockouts';
        if (!self::can_show($id)) {
            return '';
        }
        $week = isset($data['week']) ? Facts::pair($data['week']) : Facts::get()['week'];
        if ($week['lockouts'] < self::MIN_LOCKOUTS && $week['failed'] < self::MIN_FAILED) {
            return '';
        }

        /* translators: %s: "37 failed logins and 4 lockouts" */
        $fact = sprintf(__('%s this week.', 'modify-login'), Facts::phrase($week));

        return self::card(
            $id,
            '<strong>' . esc_html($fact) . '</strong> ' . esc_html__('If your team signs in from a few countries or set hours, Pro can refuse everyone else before they guess a password.', 'modify-login'),
            Docs::feature_url('pro-access-rules', $id),
            __('See access rules', 'modify-login')
        );
    }

    /**
     * Repeated attempts, on the activity log.
     *
     * @return string HTML.
     */
    private static function card_insights()
    {
        $id = 'moment-insights';
        if (!self::can_show($id)) {
            return '';
        }
        $week = Facts::get()['week'];
        if ($week['lockouts'] < self::MIN_LOCKOUTS && $week['failed'] < self::MIN_FAILED) {
            return '';
        }

        /* translators: %s: "37 failed logins and 4 lockouts" */
        $fact = sprintf(__('%s this week.', 'modify-login'), Facts::phrase($week));

        return self::card(
            $id,
            '<strong>' . esc_html($fact) . '</strong> ' . esc_html__('Pro can add a country to every entry, list the top attacking addresses and send a weekly digest.', 'modify-login'),
            Docs::feature_url('pro-geo-db', $id),
            __('See insights', 'modify-login')
        );
    }

    /**
     * A multisite network.
     *
     * @return string HTML.
     */
    private static function card_network()
    {
        $id = 'moment-network';
        if (!is_multisite() || !is_super_admin() || !self::can_show($id)) {
            return '';
        }

        return self::card(
            $id,
            '<strong>' . esc_html__('Running a network?', 'modify-login') . '</strong> ' . esc_html__('Pro adds per-site overrides, locks and a shared design.', 'modify-login'),
            Docs::feature_url('pro-agency', $id),
            __('See network features', 'modify-login')
        );
    }

    /**
     * After a Designer save. Printed hidden: nudges.js shows it when a save
     * succeeds, and asks for it not to come back for 30 days.
     *
     * @return string HTML.
     */
    private static function card_designer()
    {
        $id = 'moment-designer';
        if (!self::can_show($id)) {
            return '';
        }

        return '<div aria-live="polite">' . self::card(
            $id,
            '<strong>' . esc_html__('22 more designs in Pro:', 'modify-login') . '</strong> ' . esc_html__('animated, video, seasonal and industry.', 'modify-login'),
            Docs::feature_url('pro-templates', $id),
            __('See designs', 'modify-login'),
            array('hidden' => true)
        ) . '</div>';
    }

    /**
     * The review request: after 14 days, and only once Authlify has done
     * something real here. The link is the same for everyone: no star filter,
     * no reward.
     *
     * @return string HTML.
     */
    private static function card_review()
    {
        $id = 'review';
        if (!self::can_show($id) || self::days_installed() < self::REVIEW_DAYS) {
            return '';
        }

        $month = Facts::get()['month'];
        if ($month['failed'] + $month['lockouts'] > 0) {
            /* translators: %s: "184 failed logins and 9 lockouts" */
            $fact = sprintf(__('Authlify has recorded %s on this site.', 'modify-login'), Facts::phrase($month));
        } elseif (Router::is_hiding()) {
            $fact = __('Authlify is keeping your login address hidden on this site.', 'modify-login');
        } else {
            return '';
        }
        if (!self::claim($id)) {
            return '';
        }

        return '<section class="authlify-nudge authlify-nudge--review" data-authlify-nudge="' . esc_attr($id) . '" aria-label="' . esc_attr__('Review request', 'modify-login') . '">'
            . '<p class="authlify-nudge__text"><strong>' . esc_html($fact) . '</strong> ' . esc_html__('If it\'s doing its job, a short, honest review helps other site owners find it.', 'modify-login') . '</p>'
            . '<p class="authlify-nudge__actions">'
            . '<a class="button button-small" data-authlify-nudge-done href="' . esc_url(self::REVIEW_URL) . '" target="_blank" rel="noopener">' . esc_html__('Leave a review', 'modify-login') . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'modify-login') . '</span></a>'
            . '<button type="button" class="button-link" data-authlify-nudge-later="30">' . esc_html__('Maybe later', 'modify-login') . '</button>'
            . '<button type="button" class="button-link" data-authlify-nudge-done>' . esc_html__('Don\'t ask again', 'modify-login') . '</button>'
            . '</p></section>';
    }

    /**
     * One card: a fact, what Pro does, one link, "Don't show again".
     *
     * @param string $id    Card id (also its utm_content).
     * @param string $text  Escaped HTML.
     * @param string $url   The one link.
     * @param string $label Link text.
     * @param array  $args  hidden: print hidden, for a script to reveal.
     * @return string HTML. Empty when it may not show or the screen has a card.
     */
    public static function card($id, $text, $url, $label, array $args = array())
    {
        if (!self::claim($id)) {
            return '';
        }

        return '<section class="authlify-nudge" data-authlify-nudge="' . esc_attr($id) . '"' . (!empty($args['hidden']) ? ' hidden' : '') . ' aria-label="' . esc_attr__('Authlify Pro suggestion', 'modify-login') . '">'
            . '<span class="authlify-pro-tag">' . esc_html__('Pro', 'modify-login') . '</span>'
            . '<p class="authlify-nudge__text">' . wp_kses($text, array('strong' => array())) . '</p>'
            . '<p class="authlify-nudge__actions">'
            . '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'modify-login') . '</span></a>'
            . '<button type="button" class="button-link" data-authlify-nudge-done>' . esc_html__('Don\'t show again', 'modify-login') . '</button>'
            . '</p></section>';
    }

    /**
     * Total and two-factor counts for a role.
     *
     * @param string $role Role.
     * @return array total, with.
     */
    private static function coverage_of($role)
    {
        $none = array('total' => 0, 'with' => 0);
        if (!class_exists('Authlify\\TwoFactor\\TwoFactor') || '' !== \Authlify\TwoFactor\TwoFactor::other_provider()) {
            return $none;
        }
        $coverage = \Authlify\TwoFactor\TwoFactor::coverage();

        return isset($coverage[$role]) ? array('total' => (int) $coverage[$role]['total'], 'with' => (int) $coverage[$role]['with']) : $none;
    }
}
