<?php
/**
 * Protection screen: brute force, CAPTCHA, hardening.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Net\Ip;
use Authlify\Plugin;
use Authlify\Security\Limiter;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Tabs: Brute force (with live lockouts), CAPTCHA (rendered by the Captcha
 * module through `authlify_protection_tabs`), Hardening.
 */
final class ProtectionPage
{
    /**
     * Wire up.
     */
    public static function boot()
    {
        add_action('admin_post_authlify_unlock', array(__CLASS__, 'unlock'));
        add_filter('authlify_validate_settings', array(__CLASS__, 'validate'), 10, 3);
    }

    /**
     * Tabs: key => array( label, callable ).
     *
     * @return array
     */
    public static function tabs()
    {
        /**
         * Filters the Protection tabs.
         *
         * @param array $tabs key => array( label, callable ).
         * @since 3.0.0
         */
        return apply_filters('authlify_protection_tabs', array(
            'limits' => array(__('Brute force', 'modify-login'), array(__CLASS__, 'render_limits')),
            'hardening' => array(__('Hardening', 'modify-login'), array(__CLASS__, 'render_hardening')),
        ));
    }

    /**
     * Render.
     */
    public static function render()
    {
        $tabs = self::tabs();
        $labels = array_map(function ($t) {
            return $t[0];
        }, $tabs);
        $tab = UI::current_tab($labels);
        ?>
        <div class="wrap authlify-page">
            <?php
            $docs = array('limits' => 'brute-force', 'captcha' => 'captcha', 'hardening' => 'hardening', 'passwords' => 'breached-passwords');
            UI::header(__('Security', 'modify-login'), __('Stop password-guessing bots without getting in the way of real people.', 'modify-login'), array(), isset($docs[$tab]) ? $docs[$tab] : '');
            ?>
            <?php UI::tabs($labels, $tab, 'protection'); ?>
            <?php call_user_func($tabs[$tab][1]); ?>
            <?php Upsell::render('security'); ?>
        </div>
        <?php
    }

    /**
     * Brute-force tab.
     */
    public static function render_limits()
    {
        $detected = Ip::detect();
        $source = Settings::get('ip_source');

        UI::form_start('protection', 'limits');

        UI::panel_start(__('Limit login attempts', 'modify-login'), __('After too many wrong passwords from one address, that address has to wait. Other people keep logging in as normal.', 'modify-login'));
        UI::toggle_row('limit_enabled', __('Lock out addresses that keep guessing passwords', 'modify-login'), '', __('Brute-force protection', 'modify-login'));
        UI::input_row('limit_attempts', __('Failed attempts allowed', 'modify-login'), __('Per IP address, within the time window below.', 'modify-login'), array('type' => 'number', 'min' => 1, 'max' => 100));
        UI::input_row('limit_window', __('Time window', 'modify-login'), '', array('type' => 'number', 'min' => 1, 'max' => 1440, 'suffix' => __('minutes', 'modify-login')));
        UI::input_row('lockout_minutes', __('Lockout length', 'modify-login'), '', array('type' => 'number', 'min' => 1, 'max' => 10080, 'suffix' => __('minutes', 'modify-login')));
        UI::toggle_row('lockout_escalate', sprintf(__('Make repeat lockouts longer: %s', 'modify-login'), implode(' → ', array_map(function ($step) {
            return human_time_diff(0, max(1, (int) Settings::get('lockout_minutes', 15)) * MINUTE_IN_SECONDS * $step);
        }, \Authlify\Security\Limiter::ESCALATION))), '', __('Escalating lockouts', 'modify-login'));
        UI::toggle_row('limit_network', __('Also lock whole networks (/24, or /48 for IPv6) when many of their addresses fail', 'modify-login'), __('Stops attackers who rotate addresses within one provider. It can also lock out other people on the same network, such as an office, so add your own address to "Never lock out" first.', 'modify-login') . ' ' . UI::learn_more('brute-force'), __('Network lockouts', 'modify-login'));
        UI::input_row('user_attempts', __('Targeted-account threshold', 'modify-login'), __('When one username collects this many failures from different addresses, a CAPTCHA is required for it (if CAPTCHA is set up).', 'modify-login') . ' ' . UI::learn_more('brute-force'), array('type' => 'number', 'min' => 1, 'max' => 1000));
        UI::toggle_row('limit_user_lock', __('Pause a targeted account for new addresses at twice that number', 'modify-login'), __('Stops botnets that spread guesses over thousands of addresses. The owner still logs in from any address they used before, and the lockout message offers an unlock email.', 'modify-login'), __('Account pause', 'modify-login'));
        UI::panel_end();

        UI::panel_start(__('Visitor IP address', 'modify-login'), __('Lockouts only work if the plugin sees each visitor\'s real address. Forwarding headers can be faked, so they are only trusted from proxies you name.', 'modify-login'));
        UI::field_start(__('Detected setup', 'modify-login'));
        echo '<p>' . esc_html($detected['reason']) . ' ';
        if ($detected['source'] !== $source) {
            echo UI::pill(__('Recommended: change the setting below', 'modify-login'), 'warning'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            echo UI::pill(__('Matches your setting', 'modify-login'), 'ok'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '</p><p class="description">' . sprintf(esc_html__('Your IP address as seen now: %s', 'modify-login'), '<code>' . esc_html(Ip::client()) . '</code>') . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        UI::field_end();
        $recommended = __('Recommended', 'modify-login');
        UI::choice_row('ip_source', __('Where visitors connect from', 'modify-login'), array(
            'remote_addr' => array(__('Directly', 'modify-login'), __('No proxy or CDN in front of the site.', 'modify-login'), 'icon' => 'server', 'badge' => 'remote_addr' === $detected['source'] ? $recommended : ''),
            'cloudflare' => array(__('Through Cloudflare', 'modify-login'), __('Uses the visitor address Cloudflare passes on.', 'modify-login'), 'icon' => 'cloud', 'badge' => 'cloudflare' === $detected['source'] ? $recommended : ''),
            'proxy' => array(__('Through my own proxy', 'modify-login'), __('A load balancer or reverse proxy. List it under Trusted proxies.', 'modify-login'), 'icon' => 'layers', 'badge' => 'proxy' === $detected['source'] ? $recommended : ''),
        ), __('Choose how requests reach this site.', 'modify-login') . ' ' . UI::learn_more('ip-detection', __('How to choose', 'modify-login')), 'cards');
        UI::textarea_row('trusted_proxies', __('Trusted proxies', 'modify-login'), __('One IP or CIDR range per line. Only used with "my own proxy".', 'modify-login'), "10.0.0.0/8\n192.168.1.10");
        UI::panel_end();

        UI::panel_start(__('Allow and block lists', 'modify-login'), __('Check the ranges carefully: blocking your own address locks you out.', 'modify-login') . ' ' . UI::learn_more('allow-block-lists'));
        UI::textarea_row('ip_allowlist', __('Never lock out', 'modify-login'), __('Your office or home IP. One IP or CIDR range per line.', 'modify-login'), '203.0.113.7');
        UI::textarea_row('ip_denylist', __('Always block', 'modify-login'), __('These addresses can never log in. One IP or CIDR range per line.', 'modify-login'));
        UI::panel_end();

        UI::form_end();

        self::lockouts_panel();
    }

    /**
     * Current lockouts.
     */
    private static function lockouts_panel()
    {
        $rows = Limiter::active_lockouts(true);

        UI::panel_start(__('Locked out right now', 'modify-login'));
        if (!$rows) {
            echo '<p class="authlify-empty">' . esc_html__('Nobody is locked out.', 'modify-login') . ' ' . UI::learn_more('trouble-locked-out', __('If a real person is locked out', 'modify-login')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            ?>
            <table class="widefat striped authlify-table">
                <thead><tr><th><?php esc_html_e('Address', 'modify-login'); ?></th><th><?php esc_html_e('Until', 'modify-login'); ?></th><th><?php esc_html_e('Lockouts in a row', 'modify-login'); ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php if ('user' === $row->scope) : ?>
                            <?php $paused = 0 === strpos($row->subject, 'id:') ? get_userdata((int) substr($row->subject, 3)) : false; ?>
                            <?php echo esc_html($paused ? $paused->user_login : $row->subject); ?> <?php echo UI::pill(__('account paused for new addresses', 'modify-login'), 'warning'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php else : ?>
                            <code><?php echo esc_html($row->subject); ?></code><?php echo 'net' === $row->scope ? ' ' . UI::pill(__('network', 'modify-login'), 'info') : ''; // phpcs:ignore ?>
                        <?php endif; ?></td>
                        <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $row->locked_until)); ?></td>
                        <td><?php echo (int) $row->lockouts; ?></td>
                        <td><a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=authlify_unlock&subject=' . rawurlencode($row->subject)), 'authlify_unlock')); ?>"><?php esc_html_e('Unlock', 'modify-login'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=authlify_unlock&subject=all'), 'authlify_unlock')); ?>"><?php esc_html_e('Unlock everyone', 'modify-login'); ?></a></p>
            <?php
        }
        UI::panel_end();
    }

    /**
     * Hardening tab.
     */
    public static function render_hardening()
    {
        UI::form_start('protection', 'hardening');

        UI::panel_start(__('Other ways in', 'modify-login'), __('A hidden login page does not cover these. Each can be used to guess passwords.', 'modify-login') . ' ' . UI::learn_more('trouble-conflicts', __('What might break', 'modify-login')));
        UI::choice_row('xmlrpc', __('XML-RPC', 'modify-login'), array(
            'on' => array(__('On', 'modify-login'), __('The WordPress default. Some apps and Jetpack need it.', 'modify-login'), 'icon' => 'globe'),
            'no_multicall' => array(__('Block multi-password requests', 'modify-login'), __('Stops system.multicall, which tries hundreds of passwords in one request.', 'modify-login'), 'icon' => 'shield-check', 'badge' => __('Recommended', 'modify-login')),
            'off' => array(__('Off', 'modify-login'), __('For sites that do not use the mobile app, Jetpack or remote publishing.', 'modify-login'), 'icon' => 'ban'),
        ), __('An old remote-publishing API that attackers use to guess passwords in bulk.', 'modify-login'), 'cards');
        UI::choice_row('app_passwords', __('Application passwords', 'modify-login'), array(
            'on' => array(__('All users', 'modify-login'), __('The WordPress default.', 'modify-login')),
            'admins' => array(__('Administrators only', 'modify-login'), ''),
            'off' => array(__('Off', 'modify-login'), ''),
        ), __('They let apps log in through the REST API without the login page, CAPTCHA or two-factor.', 'modify-login'), true);
        UI::panel_end();

        UI::panel_start(__('Give attackers less to work with', 'modify-login'), __('Most attacks start by collecting usernames. Keep them to yourself.', 'modify-login'));
        UI::toggle_row('block_user_enumeration', __('Hide usernames from the REST API, ?author= links, sitemaps and oEmbed', 'modify-login'), __('Author archives keep working for logged-in users.', 'modify-login'), __('Username discovery', 'modify-login'));
        UI::toggle_row('generic_errors', __('Say "username or password is incorrect" instead of which one was wrong', 'modify-login'), __('So a failed login does not confirm that a username exists.', 'modify-login'), __('Login error messages', 'modify-login'));
        UI::panel_end();

        UI::panel_start(__('Private site', 'modify-login'), __('Only logged-in users can see the site. Everyone else is sent to the login page.', 'modify-login'));
        UI::toggle_row('force_login', __('Require login to view the site', 'modify-login'), __('Also blocks anonymous REST API requests.', 'modify-login') . ' ' . UI::learn_more('force-login'), __('Force login', 'modify-login'));
        UI::textarea_row('force_login_exclude', __('Public pages', 'modify-login'), __('Paths that stay public, one per line. A path includes its sub-pages.', 'modify-login'), "/\n/contact", array(
            'show_if' => 'authlify[force_login]=1',
            'note' => __('For example <code>/contact</code> or <code>/shop/</code>. Use <code>/</code> for the home page only.', 'modify-login'),
        ));
        UI::panel_end();

        UI::form_end();
    }

    /**
     * Keep numbers sensible.
     *
     * @param array|\WP_Error $values Values.
     * @param string          $page   Page.
     * @return array|\WP_Error
     */
    public static function validate($values, $page)
    {
        if (is_wp_error($values) || 'protection' !== $page) {
            return $values;
        }

        $bounds = array('limit_attempts' => array(1, 100), 'limit_window' => array(1, 1440), 'lockout_minutes' => array(1, 10080), 'user_attempts' => array(1, 1000));
        foreach ($bounds as $key => $range) {
            if (isset($values[$key])) {
                $values[$key] = min($range[1], max($range[0], (int) $values[$key]));
            }
        }

        foreach (array('trusted_proxies', 'ip_allowlist', 'ip_denylist') as $key) {
            if (!isset($values[$key])) {
                continue;
            }
            $bad = array();
            foreach (preg_split('/[\r\n,]+/', (string) $values[$key]) as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                $ip = false !== strpos($line, '/') ? strstr($line, '/', true) : $line;
                $bits = false !== strpos($line, '/') ? substr(strrchr($line, '/'), 1) : '';
                $max = false !== strpos((string) $ip, ':') ? 128 : 32;
                if (!Ip::valid($ip) || ('' !== $bits && (!ctype_digit($bits) || (int) $bits > $max))) {
                    $bad[] = $line;
                }
            }
            if ($bad) {
                return new \WP_Error('bad_ip', sprintf(__('These are not valid IP addresses or ranges: %s', 'modify-login'), esc_html(implode(', ', $bad))));
            }
        }

        // Never let the admin deny their own address.
        if (!empty($values['ip_denylist']) && Ip::in_ranges(Ip::client(), preg_split('/[\r\n,]+/', (string) $values['ip_denylist']))) {
            return new \WP_Error('self_block', __('Your own IP address is on the block list. Remove it, or you would be blocked from logging in.', 'modify-login'));
        }

        return $values;
    }

    /**
     * Unlock action.
     */
    public static function unlock()
    {
        if (!current_user_can(Plugin::cap()) || !check_admin_referer('authlify_unlock')) {
            wp_die(esc_html__('You are not allowed to do that.', 'modify-login'), 403);
        }

        $subject = isset($_GET['subject']) ? sanitize_text_field(wp_unslash($_GET['subject'])) : '';
        Limiter::unlock('all' === $subject ? '' : $subject);

        wp_safe_redirect(add_query_arg('authlify_notice', 'unlocked', Menu::url('protection')));
        exit;
    }
}
