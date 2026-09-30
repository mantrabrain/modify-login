<?php
/**
 * Admin menu and settings saving.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Plugin;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Registers the Authlify menu. The 2.x page slugs (modify-login,
 * modify-login-logs, modify-login-builder) are kept for the dashboard,
 * activity log and designer so old bookmarks keep working.
 */
final class Menu
{
    /**
     * Pages: key => array( slug, menu title, callable, position ).
     *
     * @return array
     */
    public static function pages()
    {
        $pages = array(
            'dashboard' => array('modify-login', __('Dashboard', 'modify-login'), array(Dashboard::class, 'render'), 0),
            'login-url' => array('authlify-login-url', __('Login URL', 'modify-login'), array(LoginUrlPage::class, 'render'), 10),
            'protection' => array('authlify-protection', __('Security', 'modify-login'), array(ProtectionPage::class, 'render'), 20),
            'redirects' => array('authlify-redirects', __('Redirects', 'modify-login'), array(RedirectsPage::class, 'render'), 50),
            'activity' => array('modify-login-logs', __('Activity', 'modify-login'), array(ActivityPage::class, 'render'), 60),
            'tools' => array('authlify-tools', __('Settings', 'modify-login'), array(ToolsPage::class, 'render'), 80),
        );

        if (!Plugin::has_pro()) {
            $pages['pro'] = array('authlify-pro', __('Free vs Pro', 'modify-login'), array(ProPage::class, 'render'), 94);
        }

        /**
         * Filters the admin pages. Modules add theirs (two-factor at 30,
         * designer at 40), Authlify Pro adds its own.
         *
         * @param array $pages key => array( slug, menu title, callable, position ).
         * @since 3.0.0
         */
        $pages = apply_filters('authlify_admin_pages', $pages);

        uasort($pages, function ($a, $b) {
            return $a[3] <=> $b[3];
        });

        return $pages;
    }

    /**
     * Pages shown as tabs of another page instead of menu items: child slug => parent slug.
     *
     * @return array
     */
    public static function parents()
    {
        /**
         * Filters which admin pages are tabs of another page.
         *
         * @param array $parents child slug => parent slug.
         * @since 3.0.0
         */
        return apply_filters('authlify_admin_page_parents', array(
            'authlify-redirects' => 'authlify-login-url',
            'authlify-alerts' => 'modify-login-logs',
            'authlify-agency' => 'authlify-tools',
            'authlify-license' => 'authlify-tools',
            'authlify-pro-two-factor' => 'authlify-two-factor',
            'authlify-pro-passwordless' => 'authlify-social',
            'authlify-pro-design' => 'modify-login-builder',
        ));
    }

    /**
     * Short labels for pages when shown as tabs: slug => label.
     *
     * @return array
     */
    public static function tab_labels()
    {
        return apply_filters('authlify_admin_tab_labels', array(
            'authlify-login-url' => __('Login URL', 'modify-login'),
            'authlify-redirects' => __('Redirects', 'modify-login'),
            'authlify-two-factor' => __('Settings', 'modify-login'),
            'authlify-pro-two-factor' => __('Rules & report', 'modify-login'),
            'authlify-social' => __('Social & SSO', 'modify-login'),
            'authlify-pro-passwordless' => __('Passwordless', 'modify-login'),
            'modify-login-builder' => __('Designer', 'modify-login'),
            'authlify-pro-design' => __('Emails & extras', 'modify-login'),
            'authlify-tools' => __('Settings', 'modify-login'),
            'authlify-agency' => __('Agency', 'modify-login'),
            'authlify-license' => __('License', 'modify-login'),
            'authlify-alerts' => __('Alerts', 'modify-login'),
        ));
    }

    /**
     * Menu titles that differ from the page's own title: slug => title.
     *
     * @return array
     */
    private static function menu_titles()
    {
        return array(
            'authlify-social' => __('Sign-in methods', 'modify-login'),
            'modify-login-builder' => __('Designer', 'modify-login'),
        );
    }

    /**
     * A page's title in the menu and the header navigation.
     *
     * @param string $slug Slug.
     * @return string
     */
    public static function menu_title($slug)
    {
        $titles = self::menu_titles();
        if (isset($titles[$slug])) {
            return $titles[$slug];
        }
        foreach (self::pages() as $page) {
            if ($page[0] === $slug) {
                return $page[1];
            }
        }

        return $slug;
    }

    /**
     * The current page slug.
     *
     * @return string
     */
    public static function current_slug()
    {
        return isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    }

    /**
     * The page family of a slug: its parent followed by the parent's children (slug => label).
     *
     * @param string $slug Page slug (default: current).
     * @return array Empty when the page has no tab siblings.
     */
    public static function family($slug = '')
    {
        $slug = '' !== $slug ? $slug : self::current_slug();
        $parents = self::parents();
        $parent = isset($parents[$slug]) ? $parents[$slug] : $slug;
        $registered = wp_list_pluck(self::pages(), 0);

        $family = array();
        foreach (array_keys(array_merge(array($parent => $parent), array_filter($parents, function ($p) use ($parent) {
            return $p === $parent;
        }))) as $member) {
            if (in_array($member, $registered, true)) {
                $family[$member] = self::tab_label($member);
            }
        }

        return count($family) > 1 ? $family : array();
    }

    /**
     * A page's tab label.
     *
     * @param string $slug Slug.
     * @return string
     */
    public static function tab_label($slug)
    {
        $labels = self::tab_labels();
        if (isset($labels[$slug])) {
            return $labels[$slug];
        }
        foreach (self::pages() as $page) {
            if ($page[0] === $slug) {
                return $page[1];
            }
        }

        return $slug;
    }

    /**
     * Admin URL of a page slug.
     *
     * @param string $slug Slug.
     * @param array  $args Query args.
     * @return string
     */
    public static function slug_url($slug, array $args = array())
    {
        $base = Settings::is_network() ? network_admin_url('admin.php') : admin_url('admin.php');

        return add_query_arg(array_merge(array('page' => $slug), $args), $base);
    }

    /**
     * Wire up.
     */
    public static function init()
    {
        add_action(Settings::is_network() ? 'network_admin_menu' : 'admin_menu', array(__CLASS__, 'register'));

        LoginUrlPage::boot();
        ProtectionPage::boot();
        ActivityPage::boot();
        ToolsPage::boot();
        Docs::init();
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_head', array(__CLASS__, 'hide_tab_pages'));
        add_filter('submenu_file', array(__CLASS__, 'highlight'));
        add_action('in_admin_header', array(__CLASS__, 'topbar'));
        add_filter('admin_body_class', array(__CLASS__, 'body_class'));
        add_filter('admin_footer_text', array(UI::class, 'footer_text'), 20);
        add_filter('update_footer', array(UI::class, 'footer_version'), 20);
        add_action('admin_post_authlify_save', array(__CLASS__, 'save'));
        add_filter('plugin_action_links_' . AUTHLIFY_BASENAME, array(__CLASS__, 'action_links'));
        add_filter('network_admin_plugin_action_links_' . AUTHLIFY_BASENAME, array(__CLASS__, 'action_links'));
    }

    /**
     * URL of a page.
     *
     * @param string $key  Page key.
     * @param array  $args Query args.
     * @return string
     */
    public static function url($key = 'dashboard', array $args = array())
    {
        $pages = self::pages();
        $slug = isset($pages[$key]) ? $pages[$key][0] : 'modify-login';
        $base = Settings::is_network() ? network_admin_url('admin.php') : admin_url('admin.php');

        return add_query_arg(array_merge(array('page' => $slug), $args), $base);
    }

    /**
     * Register menu pages.
     */
    public static function register()
    {
        $cap = Plugin::cap();
        $pages = self::pages();

        /**
         * Filters the admin menu label (Authlify Pro white-label uses this).
         *
         * @param string $label Label.
         * @since 3.0.0
         */
        $label = (string) apply_filters('authlify_menu_label', 'Authlify');

        add_menu_page(
            $label,
            $label,
            $cap,
            'modify-login',
            $pages['dashboard'][2],
            'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.5 16.5 4v5.2c0 4-2.8 7.1-6.5 8.3C6.3 16.3 3.5 13.2 3.5 9.2V4Zm0 5a1.9 1.9 0 0 0-.9 3.6v3.2h1.8v-3.2A1.9 1.9 0 0 0 10 6.5Z"/></svg>'),
            72
        );

        $titles = self::menu_titles();
        foreach ($pages as $key => $page) {
            $menu_title = isset($titles[$page[0]]) ? $titles[$page[0]] : $page[1];
            add_submenu_page('modify-login', $page[1] . ' ‹ ' . $label, $menu_title, $cap, $page[0], $page[2]);
        }
    }

    /**
     * Tab pages stay registered (so their URLs work) but leave the menu, and
     * their parent stays highlighted.
     */
    public static function hide_tab_pages()
    {
        global $submenu;

        if (empty($submenu['modify-login'])) {
            return;
        }

        $parents = self::parents();
        foreach ($submenu['modify-login'] as $i => $item) {
            if (isset($parents[$item[2]])) {
                unset($submenu['modify-login'][$i]);
            }
        }
    }

    /**
     * Highlight the parent menu item on a tab page.
     *
     * @param string|null $submenu_file Submenu file.
     * @return string|null
     */
    public static function highlight($submenu_file)
    {
        $parents = self::parents();
        $slug = self::current_slug();

        return isset($parents[$slug]) ? $parents[$slug] : $submenu_file;
    }

    /**
     * Whether the current screen is an Authlify page.
     *
     * @return bool
     */
    public static function is_screen()
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        foreach (self::pages() as $p) {
            if ($p[0] === $page) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enqueue shared admin CSS.
     */
    public static function assets()
    {
        if (!self::is_screen()) {
            return;
        }

        wp_enqueue_style('authlify-admin', AUTHLIFY_URL . 'assets/admin/admin.css', array(), AUTHLIFY_VERSION);
        wp_enqueue_script('authlify-admin', AUTHLIFY_URL . 'assets/admin/admin.js', array(), AUTHLIFY_VERSION, true);
        if ('authlify-pro' === self::current_slug()) {
            wp_enqueue_style('authlify-compare', AUTHLIFY_URL . 'assets/admin/compare.css', array('authlify-admin'), AUTHLIFY_VERSION);
        }
        wp_localize_script('authlify-admin', 'authlifyAdmin', array(
            'copied' => __('Copied', 'modify-login'),
            /* translators: 1: matching settings on this page, 2: other matching sections */
            'searchCount' => __('%1$d matching settings here, %2$d other matching sections', 'modify-login'),
        ));
    }

    /**
     * Top bar on Authlify screens.
     */
    public static function topbar()
    {
        if (self::is_screen()) {
            UI::topbar();
        }
    }

    /**
     * Body class.
     *
     * @param string $classes Classes.
     * @return string
     */
    public static function body_class($classes)
    {
        if (!self::is_screen()) {
            return $classes;
        }

        return $classes . ' authlify-admin' . (UI::sections() ? ' authlify-admin--split' : '');
    }

    /**
     * Plugins-screen links.
     *
     * @param array $links Links.
     * @return array
     */
    public static function action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(self::url('dashboard')) . '">' . esc_html__('Settings', 'modify-login') . '</a>');

        return $links;
    }

    /**
     * Save a settings form.
     */
    public static function save()
    {
        $page = isset($_POST['authlify_page']) ? sanitize_key(wp_unslash($_POST['authlify_page'])) : '';
        $tab = isset($_POST['authlify_tab']) ? sanitize_key(wp_unslash($_POST['authlify_tab'])) : '';

        if (!current_user_can(Plugin::cap()) || !check_admin_referer('authlify_save_' . $page, '_authlify_nonce')) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'modify-login'), 403);
        }

        $schema = Settings::schema();
        $owned = isset($_POST['authlify_fields']) ? array_filter(array_map('sanitize_key', explode(',', sanitize_text_field(wp_unslash($_POST['authlify_fields']))))) : array();
        $input = isset($_POST['authlify']) && is_array($_POST['authlify']) ? wp_unslash($_POST['authlify']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per key by Settings::sanitize_value().

        /**
         * Filters which settings only one screen may write: key => page key.
         * Keys mapped to '' are internal and never written by a form (the
         * pending login URL, for example, is only set by the confirm flow).
         * The client lists the keys it posts, so without this any Authlify
         * form nonce could set, say, the login URL and skip its confirm step.
         *
         * @param array $owners key => page.
         * @since 3.0.0
         */
        $owners = (array) apply_filters('authlify_settings_owner_pages', array(
            'login_slug' => 'login-url',
            'pending_slug' => '',
            'pending_token' => '',
            'pending_expires' => '',
            'delete_data' => 'tools',
            'onboarding_done' => '',
        ));

        $values = array();
        foreach ($owned as $key) {
            if (!isset($schema[$key]) || (array_key_exists($key, $owners) && $owners[$key] !== $page)) {
                continue;
            }
            if (array_key_exists($key, $input)) {
                $values[$key] = $input[$key];
            } elseif ('bool' === $schema[$key][0]) {
                $values[$key] = false;
            } elseif ('list' === $schema[$key][0] || 'map' === $schema[$key][0]) {
                $values[$key] = array();
            }
        }

        /**
         * Validates submitted values before saving. Return a WP_Error to refuse.
         *
         * @param array  $values Values (unsanitized).
         * @param string $page   Page key.
         * @param string $tab    Tab key.
         * @since 3.0.0
         */
        $values = apply_filters('authlify_validate_settings', $values, $page, $tab);

        $back = wp_get_referer() ? wp_get_referer() : self::url($page);
        $back = remove_query_arg(array('authlify_saved', 'authlify_error', 'authlify_notice'), $back);

        if (is_wp_error($values)) {
            set_transient('authlify_error_' . get_current_user_id(), $values->get_error_message(), MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg('authlify_error', 1, $back));
            exit;
        }

        Settings::update($values);

        /**
         * Fires after a settings form was saved.
         *
         * @param string $page   Page key.
         * @param string $tab    Tab.
         * @param array  $values Saved values.
         * @since 3.0.0
         */
        do_action('authlify_admin_saved', $page, $tab, $values);

        wp_safe_redirect(add_query_arg('authlify_saved', 1, $back));
        exit;
    }
}
