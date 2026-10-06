<?php
/**
 * Other login-security plugins that overlap with Authlify.
 *
 * @package Authlify
 */

namespace Authlify\Diagnostics;

use Authlify\Admin\Menu;
use Authlify\Plugin;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Warns when another plugin also limits login attempts or renames the login
 * page (CMPT-05). Two limiters lock people out twice, and Authlify's unlock
 * tools cannot clear the other plugin's lockout; two login renamers fight over
 * the login URL. Shown as a dismissible admin notice (per plugin, per user) and
 * as a Site Health test.
 */
final class Conflicts
{
    /**
     * admin-post action for dismissing one plugin's notice.
     */
    const DISMISS = 'authlify_dismiss_conflict';

    /**
     * User meta with the dismissed plugin keys.
     */
    const META = 'authlify_conflicts_dismissed';

    /**
     * Wire up.
     */
    public static function init()
    {
        add_filter('site_status_tests', array(__CLASS__, 'site_health_tests'));

        if (is_admin()) {
            add_action('admin_notices', array(__CLASS__, 'notice'));
            add_action('network_admin_notices', array(__CLASS__, 'notice'));
            add_action('admin_post_' . self::DISMISS, array(__CLASS__, 'dismiss'));
        }
    }

    /**
     * Active plugins that overlap with Authlify.
     *
     * @return array[] key => array( name, limits (bool), renames (bool) ).
     * @since 3.0.2
     */
    public static function detect()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $found = array();
        $add = function ($key, $name, $limits, $renames) use (&$found) {
            if ($limits || $renames) {
                $found[$key] = array('name' => $name, 'limits' => (bool) $limits, 'renames' => (bool) $renames);
            }
        };

        if (is_plugin_active('limit-login-attempts-reloaded/limit-login-attempts-reloaded.php')) {
            $add('llar', 'Limit Login Attempts Reloaded', true, false);
        }

        if (is_plugin_active('loginizer/loginizer.php')) {
            $add('loginizer', 'Loginizer', true, false);
        }

        if (is_plugin_active('all-in-one-wp-security-and-firewall/wp-security.php')) {
            $aios = get_option('aio_wp_security_configs');
            $aios = is_array($aios) ? $aios : array();
            $add(
                'aios',
                'All-In-One Security (AIOS)',
                !empty($aios['aiowps_enable_login_lockdown']),
                !empty($aios['aiowps_enable_rename_login_page'])
            );
        }

        if (is_plugin_active('better-wp-security/better-wp-security.php') && class_exists('ITSEC_Modules', false)) {
            $limits = false;
            $renames = false;
            try {
                $limits = (bool) \ITSEC_Modules::is_active('brute-force');
                $renames = (bool) \ITSEC_Modules::is_active('hide-backend') && (bool) \ITSEC_Modules::get_setting('hide-backend', 'enabled');
            } catch (\Throwable $e) {
                $limits = false;
            }
            $add('solid', 'Solid Security', $limits, $renames);
        }

        if (is_plugin_active('wordfence/wordfence.php')) {
            $limits = true;
            if (class_exists('wfConfig', false) && is_callable(array('wfConfig', 'get'))) {
                try {
                    $limits = (bool) \wfConfig::get('loginSecurityEnabled', true);
                } catch (\Throwable $e) {
                    $limits = true;
                }
            }
            $add('wordfence', 'Wordfence', $limits, false);
        }

        if (is_plugin_active('wps-hide-login/wps-hide-login.php')) {
            $add('wps-hide-login', 'WPS Hide Login', false, true);
        }

        /**
         * Filters the other login-security plugins Authlify warns about.
         *
         * @param array[] $found key => array( name, limits, renames ).
         * @since 3.0.2
         */
        return (array) apply_filters('authlify_conflicting_plugins', $found);
    }

    /**
     * Whether Authlify's own limiter / login URL is on.
     *
     * @return array limits (bool), renames (bool).
     */
    private static function ours()
    {
        return array(
            'limits' => (bool) Settings::get('limit_enabled', true),
            'renames' => '' !== \Authlify\Login\Router::slug(),
        );
    }

    /**
     * Overlaps that matter right now, one sentence each.
     *
     * @return string[] key => sentence.
     * @since 3.0.2
     */
    public static function messages()
    {
        $ours = self::ours();
        $out = array();

        foreach (self::detect() as $key => $plugin) {
            $parts = array();
            if ($plugin['limits'] && $ours['limits']) {
                $parts[] = sprintf(
                    /* translators: %s: plugin name */
                    __('%s also limits failed logins. With two limiters, a person can be locked out by either one, and Authlify\'s Unlock button and "wp authlify unlock" cannot clear the other plugin\'s lockout. Keep one limiter: turn off Authlify\'s brute-force limits (Protection → Limits) or the other plugin\'s.', 'modify-login'),
                    $plugin['name']
                );
            }
            if ($plugin['renames'] && $ours['renames']) {
                $parts[] = sprintf(
                    /* translators: %s: plugin name */
                    __('%s also changes the login address. Two plugins that move the login page fight over it, and one of the addresses stops working. Keep one: turn off the custom login URL in Authlify (Login URL) or in the other plugin.', 'modify-login'),
                    $plugin['name']
                );
            }
            if ($parts) {
                $out[$key] = implode(' ', $parts);
            }
        }

        return $out;
    }

    /**
     * Admin notice on the Dashboard, Plugins and Authlify screens.
     */
    public static function notice()
    {
        global $pagenow;

        if (!current_user_can(Plugin::cap())) {
            return;
        }
        if (!in_array($pagenow, array('index.php', 'plugins.php'), true) && !Menu::is_screen()) {
            return;
        }
        if (is_multisite() && Settings::is_network() !== is_network_admin()) {
            return;
        }

        $dismissed = (array) get_user_meta(get_current_user_id(), self::META, true);
        foreach (self::messages() as $key => $message) {
            // The Login URL screen already warns about WPS Hide Login (and other renamers).
            if (in_array($key, $dismissed, true) || 'wps-hide-login' === $key) {
                continue;
            }
            $url = wp_nonce_url(add_query_arg(array('action' => self::DISMISS, 'plugin' => $key), admin_url('admin-post.php')), self::DISMISS . '_' . $key);
            ?>
            <div class="notice notice-warning authlify-conflict-notice">
                <p><strong><?php esc_html_e('Authlify: another login-security plugin overlaps', 'modify-login'); ?></strong></p>
                <p><?php echo esc_html($message); ?></p>
                <p><a href="<?php echo esc_url($url); ?>"><?php esc_html_e('Dismiss for this plugin', 'modify-login'); ?></a></p>
            </div>
            <?php
        }
    }

    /**
     * Dismiss the notice for one plugin.
     */
    public static function dismiss()
    {
        $key = isset($_GET['plugin']) ? sanitize_key(wp_unslash($_GET['plugin'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ('' === $key || !current_user_can(Plugin::cap()) || !check_admin_referer(self::DISMISS . '_' . $key)) {
            wp_die(esc_html__('You are not allowed to do this.', 'modify-login'), 403);
        }

        $dismissed = array_filter((array) get_user_meta(get_current_user_id(), self::META, true));
        $dismissed[] = $key;
        update_user_meta(get_current_user_id(), self::META, array_values(array_unique($dismissed)));

        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
        exit;
    }

    /**
     * Site Health test.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function site_health_tests($tests)
    {
        $tests['direct']['authlify_conflicts'] = array(
            'label' => __('Authlify and other login-security plugins', 'modify-login'),
            'test' => array(__CLASS__, 'site_health'),
        );

        return $tests;
    }

    /**
     * Site Health result.
     *
     * @return array
     */
    public static function site_health()
    {
        $messages = self::messages();
        $result = array(
            'label' => __('No other plugin limits logins or moves the login page', 'modify-login'),
            'status' => 'good',
            'badge' => array('label' => __('Security', 'modify-login'), 'color' => 'blue'),
            'description' => '<p>' . esc_html__('Authlify is the only active plugin that limits failed logins or changes the login address.', 'modify-login') . '</p>',
            'actions' => '',
            'test' => 'authlify_conflicts',
        );

        if (!$messages) {
            return $result;
        }

        $items = '';
        foreach ($messages as $message) {
            $items .= '<li>' . esc_html($message) . '</li>';
        }

        $result['label'] = __('Another plugin overlaps with Authlify\'s login protection', 'modify-login');
        $result['status'] = 'recommended';
        $result['badge']['color'] = 'orange';
        $result['description'] = '<ul>' . $items . '</ul>';
        $result['actions'] = '<p><a href="' . esc_url(Menu::url('protection')) . '">' . esc_html__('Open Authlify protection settings', 'modify-login') . '</a></p>';

        return $result;
    }
}
