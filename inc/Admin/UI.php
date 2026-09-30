<?php
/**
 * Admin design helpers.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Plugin;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * One visual language for every Authlify screen (free and Pro), shared with
 * MatrixMap: a dark header bar with the primary navigation, a page head, a
 * grouped section navigation on the left for pages with several sections, and
 * cards with setting rows, choice cards, stat cards and a save card. Styles
 * live in assets/admin/admin.css.
 *
 * Pages and sections come from the existing filters:
 * - authlify_admin_pages       top-level pages (header navigation) and pages
 *                              shown as sections of another page (Menu::parents());
 *                              optional 'icon', 'group' and 'sections' keys.
 * - authlify_protection_tabs   Security sections (optional 'icon', 'group', 'keywords').
 * - authlify_activity_tabs     Activity sections (same optional keys).
 *
 * Settings forms post to admin-post.php?action=authlify_save. Each form lists
 * the keys it owns in a hidden field, so an unticked checkbox is saved as off
 * and keys owned by other screens are never touched.
 */
final class UI
{
    /**
     * Keys rendered by the current form.
     *
     * @var string[]
     */
    private static $fields = array();

    /**
     * ID of the current row's help text ('' when the row has none).
     *
     * @var string
     */
    private static $help_id = '';

    /**
     * Row counter for rows without a control ID.
     *
     * @var int
     */
    private static $rows = 0;

    /**
     * Section navigation of the current page (null until computed).
     *
     * @var array|null
     */
    private static $sections = null;

    /**
     * Whether the section navigation was printed on this page.
     *
     * @var bool
     */
    private static $split = false;

    /**
     * Logo mark.
     *
     * @param int $size Pixel size.
     * @return string SVG.
     */
    public static function logo($size = 28)
    {
        return '<svg class="authlify-brand__mark" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 256 256" aria-hidden="true" focusable="false">'
            . '<defs><linearGradient id="authlify-mark-bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6366F1"/><stop offset="1" stop-color="#4338CA"/></linearGradient></defs>'
            . '<rect width="256" height="256" rx="58" fill="url(#authlify-mark-bg)"/>'
            . '<path d="M128 44 L196 70 V124 C196 166 166 198 128 212 C90 198 60 166 60 124 V70 Z" fill="#fff"/>'
            . '<circle cx="128" cy="118" r="20" fill="#4338CA"/>'
            . '<rect x="120" y="126" width="16" height="42" rx="7" fill="#4338CA"/>'
            . '</svg>';
    }

    /**
     * Branding for the header and footer (Authlify Pro white-label filters it).
     *
     * @return array name, version, logo, links (label => URL).
     */
    public static function brand()
    {
        $pro = Plugin::has_pro();

        /**
         * Filters the header branding (Authlify Pro white-label uses this).
         *
         * @param array $brand name, version, logo (SVG/HTML), links (label => URL).
         * @since 3.0.0
         */
        return (array) apply_filters('authlify_admin_brand', array(
            'name' => $pro ? 'Authlify Pro' : 'Authlify',
            'version' => $pro ? AUTHLIFY_PRO_VERSION : AUTHLIFY_VERSION,
            'logo' => self::logo(),
            'links' => array(
                __('Docs', 'modify-login') => Docs::url(),
                __('Support', 'modify-login') => 'https://wordpress.org/support/plugin/modify-login/',
            ),
        ));
    }

    /*
     * ---------------------------------------------------------------------
     * Header bar
     * ---------------------------------------------------------------------
     */

    /**
     * The top-level page a slug belongs to (its parent when it is shown as a section).
     *
     * @param string $slug Slug (default: current).
     * @return string
     */
    public static function family_parent($slug = '')
    {
        $slug = '' !== $slug ? $slug : Menu::current_slug();
        $parents = Menu::parents();

        return isset($parents[$slug]) ? $parents[$slug] : $slug;
    }

    /**
     * Header navigation: every top-level Authlify page except the Docs (reached through Help).
     *
     * @return array key => array( label, url, active ).
     */
    public static function nav_items()
    {
        $parents = Menu::parents();
        $current = self::family_parent();
        $items = array();

        foreach (Menu::pages() as $key => $page) {
            if (isset($parents[$page[0]]) || Docs::SLUG === $page[0]) {
                continue;
            }
            $items[$key] = array(
                'label' => Menu::menu_title($page[0]),
                'url' => Menu::slug_url($page[0]),
                'active' => $page[0] === $current,
            );
        }

        /**
         * Filters the header navigation.
         *
         * @param array $items key => array( label, url, active ).
         * @since 3.0.0
         */
        return (array) apply_filters('authlify_admin_nav', $items);
    }

    /**
     * The one primary action in the header for the current page, if any.
     *
     * @return array label, url, target (bool), icon. Empty for none.
     */
    public static function primary_action()
    {
        $action = array();
        if ('modify-login' === Menu::current_slug()) {
            $action = array('label' => __('Open login page', 'modify-login'), 'url' => wp_login_url(), 'target' => true, 'icon' => 'external');
        }

        /**
         * Filters the primary action in the header bar (at most one per page).
         *
         * @param array  $action label, url, target, icon. Empty for none.
         * @param string $slug   Current page slug.
         * @since 3.0.0
         */
        return (array) apply_filters('authlify_admin_primary_action', $action, Menu::current_slug());
    }

    /**
     * Header bar shown on every Authlify screen: brand, primary navigation,
     * Help and one primary action. Replaces the older white top bar (the
     * class names it had are kept for white-label fallbacks).
     */
    public static function topbar()
    {
        $brand = self::brand();
        $name = (string) $brand['name'];
        $pro_pill = Plugin::has_pro() && 'Authlify Pro' === $name;
        if ($pro_pill) {
            $name = 'Authlify';
        }

        // Help goes to the documentation; hidden when white-label hides vendor links.
        $help = '';
        foreach ((array) $brand['links'] as $url) {
            if (Docs::url() === $url) {
                $help = $url;
            }
        }
        $action = self::primary_action();
        $on_docs = Docs::SLUG === Menu::current_slug();
        ?>
        <header class="authlify-topbar authlify-top">
            <a class="authlify-brand authlify-top__brand" href="<?php echo esc_url(Menu::url('dashboard')); ?>">
                <span class="authlify-top__logo" aria-hidden="true">
                    <?php if ('' !== (string) $brand['logo']) : ?>
                        <?php echo wp_kses($brand['logo'], self::svg_html()); ?>
                    <?php else : ?>
                        <span class="authlify-top__initial"><?php echo esc_html(function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1)); ?></span>
                    <?php endif; ?>
                </span>
                <span class="authlify-brand__name"><?php echo esc_html($name); ?></span>
                <?php if ($pro_pill) : ?>
                    <span class="authlify-badge authlify-badge--pro"><?php esc_html_e('Pro', 'modify-login'); ?></span>
                <?php endif; ?>
            </a>
            <nav class="authlify-top__nav" aria-label="<?php echo esc_attr($name); ?>">
                <?php foreach (self::nav_items() as $item) : ?>
                    <a class="authlify-top__link<?php echo !empty($item['active']) ? ' is-active' : ''; ?>" href="<?php echo esc_url($item['url']); ?>"<?php echo !empty($item['active']) ? ' aria-current="page"' : ''; ?>><?php echo esc_html($item['label']); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="authlify-top__end authlify-topbar__links">
                <?php if ('' !== $help) : ?>
                    <a class="authlify-top__icon<?php echo $on_docs ? ' is-active' : ''; ?>" href="<?php echo esc_url($help); ?>"<?php echo $on_docs ? ' aria-current="page"' : ''; ?>>
                        <?php echo self::icon('help', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
                        <span><?php esc_html_e('Help', 'modify-login'); ?></span>
                    </a>
                <?php endif; ?>
                <?php if (!empty($action['label']) && !empty($action['url'])) : ?>
                    <a class="button button-primary authlify-top__action" href="<?php echo esc_url($action['url']); ?>"<?php echo !empty($action['target']) ? ' target="_blank" rel="noopener"' : ''; ?>>
                        <?php echo !empty($action['icon']) ? self::icon($action['icon'], 16) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
                        <span><?php echo esc_html($action['label']); ?></span>
                        <?php if (!empty($action['target'])) : ?>
                            <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'modify-login'); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            </div>
        </header>
        <?php
    }

    /*
     * ---------------------------------------------------------------------
     * Page head and section navigation
     * ---------------------------------------------------------------------
     */

    /**
     * Page head: large title, one-line lead, actions on the right.
     *
     * @param string $title   Title.
     * @param string $lead    Lead (inline HTML allowed, see inline_html()).
     * @param string $actions Actions HTML (escaped by the caller).
     */
    public static function page_head($title, $lead = '', $actions = '')
    {
        ?>
        <div class="authlify-page-head">
            <div class="authlify-page-head__text">
                <h1><?php echo esc_html($title); ?></h1>
                <?php if ('' !== $lead) : ?>
                    <p><?php echo wp_kses($lead, self::inline_html()); ?></p>
                <?php endif; ?>
            </div>
            <?php if ('' !== $actions) : ?>
                <div class="authlify-page-head__actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?></div>
            <?php endif; ?>
        </div>
        <hr class="wp-header-end">
        <?php
        self::notices();
    }

    /**
     * Buttons for the page head.
     *
     * @param array $actions array( label, url, primary, target ).
     * @return string HTML.
     */
    private static function actions_html(array $actions)
    {
        $html = '';
        foreach ($actions as $action) {
            $html .= '<a class="button' . (!empty($action['primary']) ? ' button-primary' : '') . '" href="' . esc_url($action['url']) . '"' . (!empty($action['target']) ? ' target="_blank" rel="noopener"' : '') . '>'
                . esc_html($action['label'])
                . (!empty($action['target']) ? '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'modify-login') . '</span>' : '')
                . '</a>';
        }

        return $html;
    }

    /**
     * Page header. On pages with several sections this prints the page head of
     * the whole page family, the section navigation, the settings search and
     * the current section's title; otherwise a page head and, for page
     * families without a section navigation (Designer), a segmented control.
     *
     * @param string $title       Title.
     * @param string $description Description.
     * @param array  $actions     Buttons: array( label, url, primary, target ).
     * @param string $doc         Optional documentation article ID for a "Learn more" link.
     */
    public static function header($title, $description = '', $actions = array(), $doc = '')
    {
        $sections = self::sections();

        if (!$sections) {
            $lead = '' !== $description ? esc_html($description) . ('' !== $doc ? ' ' . self::learn_more($doc) : '') : '';
            self::page_head($title, $lead, self::actions_html($actions));
            self::family_tabs();

            return;
        }

        $parent = self::family_parent();
        $family_lead = self::family_lead($parent);
        $lead = '' !== $family_lead ? $family_lead : $description;
        self::page_head(Menu::menu_title($parent), esc_html($lead));

        self::section_nav($sections);

        $current = array('label' => $title, 'search' => true);
        foreach ($sections as $item) {
            if ($item['active']) {
                $current = $item;
            }
        }
        if ($current['search']) {
            self::search_box();
        }
        // The page's own actions belong to this section.
        $buttons = self::actions_html($actions);
        echo '<div class="authlify-section-head"><div class="authlify-section-head__text">';
        echo '<h2 class="authlify-section__title">' . esc_html($current['label']) . ('' !== $doc ? ' ' . self::learn_more($doc) : '') . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in learn_more().
        if ('' !== $description && $description !== $lead) {
            echo '<p class="authlify-section__lead">' . esc_html($description) . '</p>';
        }
        echo '</div>';
        if ('' !== $buttons) {
            echo '<div class="authlify-section-head__actions">' . $buttons . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in actions_html().
        }
        echo '</div>';
    }

    /**
     * One-line lead for a page family (shared by all of its sections).
     *
     * @param string $parent Parent slug.
     * @return string
     */
    public static function family_lead($parent)
    {
        $leads = array(
            'authlify-login-url' => __('Move the login page to an address only you know, and choose where people land after they log in or out.', 'modify-login'),
            'authlify-protection' => __('Stop password-guessing bots without getting in the way of real people.', 'modify-login'),
            'authlify-two-factor' => __('Let people protect their account with an authenticator app, backup codes or a passkey.', 'modify-login'),
            'authlify-social' => __('Let people sign in with a social account, an emailed link or a one-time code.', 'modify-login'),
            'modify-login-logs' => __('Every login, failed attempt and lockout on this site. Stored only here, never sent anywhere.', 'modify-login'),
            'authlify-tools' => __('Move settings between sites, switch from another login plugin, and manage stored data.', 'modify-login'),
        );

        /**
         * Filters the one-line lead shown under a page family's title.
         *
         * @param string $lead   Lead ('' for the page's own description).
         * @param string $parent Parent page slug.
         * @since 3.0.0
         */
        return (string) apply_filters('authlify_admin_family_lead', isset($leads[$parent]) ? $leads[$parent] : '', $parent);
    }

    /**
     * Pages that keep their own layout (no section navigation).
     *
     * @return string[]
     */
    private static function own_layout()
    {
        return array('modify-login-builder', 'authlify-docs', 'modify-login');
    }

    /**
     * Registered pages by slug.
     *
     * @return array slug => page definition (with 'key').
     */
    private static function pages_by_slug()
    {
        $out = array();
        foreach (Menu::pages() as $key => $page) {
            $page['key'] = $key;
            $out[$page[0]] = $page;
        }

        return $out;
    }

    /**
     * The key of the current section among $sections (from ?tab=, else the first).
     *
     * @param array $sections key => anything.
     * @return string
     */
    private static function current_key(array $sections)
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        return isset($sections[$tab]) ? $tab : (string) key($sections);
    }

    /**
     * Default icon, group and search words of the built-in sections and pages.
     *
     * @param string $key Section key or page slug.
     * @return array icon, group, keywords.
     */
    private static function section_defaults($key)
    {
        $protect = __('Login protection', 'modify-login');
        $site = __('Site', 'modify-login');
        $accounts = __('Accounts', 'modify-login');
        $defaults = array(
            // Security.
            'limits' => array('shield', $protect, __('lockout attempts failed guessing ip address proxy cloudflare allow block list network account pause', 'modify-login')),
            'captcha' => array('bot', $protect, __('turnstile hcaptcha recaptcha altcha honeypot forms keys', 'modify-login')),
            'access' => array('globe', $protect, __('country countries hours schedule honeypot login url ban', 'modify-login')),
            'hardening' => array('lock', $site, __('xml-rpc xmlrpc application passwords usernames enumeration error messages force login private site', 'modify-login')),
            'passwords' => array('key', $accounts, __('breached pwned have i been pwned password check roles', 'modify-login')),
            'password-policy' => array('sliders', $accounts, __('password length strength expiry reuse history policy', 'modify-login')),
            'sessions' => array('clock', $accounts, __('sessions idle logout concurrent remember me length', 'modify-login')),
            // Activity.
            'log' => array('list', __('Activity', 'modify-login'), __('log events export csv filter logins failed', 'modify-login')),
            'settings' => array('settings', __('Activity', 'modify-login'), __('retention keep anonymize ip country lockout email clear log', 'modify-login')),
            // Settings (tools).
            'switch' => array('swap', __('Tools', 'modify-login'), __('switch migrate import wps hide login limit login attempts loginpress admin site enhancements', 'modify-login')),
            'import' => array('download', __('Tools', 'modify-login'), __('export import json file move site backup', 'modify-login')),
            'data' => array('database', __('Tools', 'modify-login'), __('uninstall delete data remove', 'modify-login')),
            // Pages shown as sections.
            'authlify-login-url' => array('link', __('Login', 'modify-login'), __('login url address slug hide wp-login wp-admin 404 403 redirect leak check recovery', 'modify-login')),
            'authlify-redirects' => array('route', __('Login', 'modify-login'), __('redirect after login logout role', 'modify-login')),
            'authlify-two-factor' => array('smartphone', __('Two-factor login', 'modify-login'), __('methods authenticator app backup codes passkeys reset coverage', 'modify-login')),
            'authlify-pro-two-factor' => array('users', __('Two-factor login', 'modify-login'), __('require roles grace period email codes trusted devices report', 'modify-login')),
            'authlify-social' => array('user-check', __('Social', 'modify-login'), __('google microsoft apple github openid connect sso providers', 'modify-login')),
            'authlify-pro-passwordless' => array('mail', __('Passwordless', 'modify-login'), __('magic link email code temporary access', 'modify-login')),
            'authlify-alerts' => array('bell', __('Alerts', 'modify-login'), __('alerts email slack discord telegram webhook digest', 'modify-login')),
            'authlify-agency' => array('building', __('Agency', 'modify-login'), __('white label client handoff network design sync', 'modify-login')),
            'authlify-license' => array('badge', __('Account', 'modify-login'), __('licence license key updates support', 'modify-login')),
        );

        return isset($defaults[$key]) ? $defaults[$key] : array('dot', '', '');
    }

    /**
     * Build one navigation item.
     *
     * @param string $key      Section key or page slug (for defaults).
     * @param array  $def      Definition: label or 0, optional icon, group, keywords, pro.
     * @param string $url      URL.
     * @param bool   $active   Current.
     * @param string $group    Group used when the definition has none and there is no default.
     * @return array
     */
    private static function section_item($key, array $def, $url, $active, $group = '')
    {
        $defaults = self::section_defaults($key);
        $label = isset($def['label']) ? $def['label'] : (isset($def[0]) ? $def[0] : $key);

        return array(
            'label' => (string) $label,
            'url' => $url,
            'icon' => !empty($def['icon']) ? (string) $def['icon'] : $defaults[0],
            'group' => isset($def['group']) ? (string) $def['group'] : ('' !== $defaults[1] ? $defaults[1] : $group),
            'keywords' => isset($def['keywords']) ? (string) $def['keywords'] : $defaults[2],
            'pro' => !empty($def['pro']),
            'search' => isset($def['search']) ? (bool) $def['search'] : 'log' !== $key,
            'active' => (bool) $active,
        );
    }

    /**
     * Sections a top-level page renders itself: key => definition.
     *
     * @param string $slug Page slug.
     * @return array
     */
    public static function internal_sections($slug)
    {
        if ('authlify-protection' === $slug) {
            return ProtectionPage::tabs();
        }
        if ('modify-login-logs' === $slug) {
            return ActivityPage::sections();
        }
        if ('authlify-tools' === $slug) {
            return ToolsPage::sections();
        }

        return array();
    }

    /**
     * The section navigation of the current page: the page family's own
     * sections plus the pages shown as its sections (with their sub-sections).
     *
     * @return array id => array( label, url, icon, group, keywords, pro, search, active ). Empty when the page has no sections.
     */
    public static function sections()
    {
        if (null !== self::$sections) {
            return self::$sections;
        }
        self::$sections = array();

        $slug = Menu::current_slug();
        $parent = self::family_parent($slug);
        $pages = self::pages_by_slug();
        if ('' === $slug || !isset($pages[$parent]) || !isset($pages[$slug]) || in_array($parent, self::own_layout(), true)) {
            return self::$sections;
        }

        $items = array();
        $page_sections = function ($member) use ($pages) {
            $def = $pages[$member];
            if (!empty($def['sections']) && is_array($def['sections'])) {
                return $def['sections'];
            }

            return self::internal_sections($member);
        };

        foreach (array_merge(array($parent => ''), Menu::family($parent)) as $member => $unused) {
            if (!isset($pages[$member])) {
                continue;
            }
            $def = $pages[$member];
            $defaults = self::section_defaults($member);
            $group = isset($def['group']) ? (string) $def['group'] : $defaults[1];
            $subs = $page_sections($member);

            if ($subs) {
                $current = $slug === $member ? self::current_key($subs) : '';
                foreach ($subs as $key => $sub) {
                    $sub = is_array($sub) ? $sub : array((string) $sub);
                    $items[$member . ':' . $key] = self::section_item($key, $sub, Menu::slug_url($member, array('tab' => $key)), $key === $current, $group);
                }
            } else {
                $items[$member] = self::section_item($member, array_merge(array('label' => Menu::tab_label($member)), array_intersect_key($def, array_flip(array('icon', 'group', 'keywords')))), Menu::slug_url($member), $slug === $member, $group);
            }
        }

        /**
         * Filters the section navigation of the current page.
         *
         * @param array  $items  id => array( label, url, icon, group, keywords, pro, active ).
         * @param string $parent Top-level page slug.
         * @since 3.0.0
         */
        $items = (array) apply_filters('authlify_admin_sections', $items, $parent);

        self::$sections = count($items) > 1 ? $items : array();

        return self::$sections;
    }

    /**
     * Print the grouped section navigation.
     *
     * @param array $sections From sections().
     */
    public static function section_nav(array $sections)
    {
        $groups = array();
        foreach ($sections as $id => $item) {
            $groups[$item['group']][$id] = $item;
        }
        self::$split = true;
        $n = 0;
        ?>
        <nav class="authlify-side" aria-label="<?php esc_attr_e('Sections', 'modify-login'); ?>">
            <?php foreach ($groups as $group => $items) : ?>
                <?php ++$n; ?>
                <?php if ('' !== (string) $group) : ?>
                    <p class="authlify-side__group" id="authlify-side-group-<?php echo (int) $n; ?>"><?php echo esc_html($group); ?></p>
                <?php endif; ?>
                <ul class="authlify-side__list"<?php echo '' !== (string) $group ? ' aria-labelledby="authlify-side-group-' . (int) $n . '"' : ''; ?>>
                    <?php foreach ($items as $item) : ?>
                        <li>
                            <a class="authlify-side__link<?php echo $item['active'] ? ' is-active' : ''; ?>" href="<?php echo esc_url($item['url']); ?>"<?php echo $item['active'] ? ' aria-current="page"' : ''; ?> data-keywords="<?php echo esc_attr($item['keywords']); ?>">
                                <?php echo self::icon($item['icon'], 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
                                <span class="authlify-side__label"><?php echo esc_html($item['label']); ?></span>
                                <?php if ($item['pro']) : ?>
                                    <span class="authlify-badge authlify-badge--pro authlify-badge--xs"><?php esc_html_e('Pro', 'modify-login'); ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * "Search settings…" box above the section content. Filters the rows and
     * cards of the current section and lists matching sections (admin.js).
     */
    public static function search_box()
    {
        ?>
        <div class="authlify-setsearch" role="search">
            <span class="authlify-setsearch__icon" aria-hidden="true"><?php echo self::icon('search', 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
            <label class="screen-reader-text" for="authlify-settings-search"><?php esc_html_e('Search settings', 'modify-login'); ?></label>
            <input type="search" id="authlify-settings-search" placeholder="<?php esc_attr_e('Search settings…', 'modify-login'); ?>" autocomplete="off" spellcheck="false" aria-describedby="authlify-settings-status">
        </div>
        <p class="screen-reader-text" id="authlify-settings-status" aria-live="polite" aria-atomic="true"></p>
        <div class="authlify-setsearch__empty" id="authlify-settings-empty" hidden>
            <p data-authlify-search-none><?php esc_html_e('Nothing on this page matches your search.', 'modify-login'); ?></p>
            <div data-authlify-search-other hidden>
                <p><?php esc_html_e('Matching sections:', 'modify-login'); ?></p>
                <ul></ul>
            </div>
        </div>
        <?php
    }

    /**
     * The segmented page switcher for a page family without a section
     * navigation (the Designer and its Pro extras).
     */
    public static function family_tabs()
    {
        if (self::$split) {
            return;
        }
        $slug = Menu::current_slug();
        $family = Menu::family($slug);
        if (!$family) {
            return;
        }
        $items = array();
        foreach ($family as $member => $label) {
            $items[] = array($label, Menu::slug_url($member), $member === $slug);
        }
        self::tab_row($items);
    }

    /**
     * Pages that render their own tabs (kept for compatibility).
     *
     * @return string[]
     */
    public static function self_tabbed()
    {
        return array('authlify-protection', 'modify-login-logs');
    }

    /**
     * A page's own tabs (key => label), kept for compatibility.
     *
     * @param string $slug Page slug.
     * @return array
     */
    public static function internal_tabs($slug)
    {
        return array_map(function ($t) {
            return is_array($t) ? (isset($t['label']) ? $t['label'] : $t[0]) : $t;
        }, self::internal_sections($slug));
    }

    /**
     * Print a segmented control.
     *
     * @param array $items array( label, url, active ).
     */
    private static function tab_row(array $items)
    {
        if (count($items) < 2) {
            return;
        }
        echo '<nav class="authlify-segment authlify-tabs" aria-label="' . esc_attr__('Sections', 'modify-login') . '">';
        foreach ($items as $item) {
            printf(
                '<a href="%1$s" class="authlify-segment__btn%2$s"%3$s>%4$s</a>',
                esc_url($item[1]),
                $item[2] ? ' is-active' : '',
                $item[2] ? ' aria-current="page"' : '',
                esc_html($item[0])
            );
        }
        echo '</nav>';
    }

    /**
     * Tabs. Pages with a section navigation already show them there; other
     * pages get a segmented control.
     *
     * @param array  $tabs    key => label.
     * @param string $current Current key.
     * @param string $page    Menu key for URLs.
     */
    public static function tabs(array $tabs, $current, $page)
    {
        if (self::$split) {
            return;
        }
        $items = array();
        foreach ($tabs as $key => $label) {
            $items[] = array($label, add_query_arg('tab', $key, Menu::url($page)), $key === $current);
        }
        self::tab_row($items);
    }

    /**
     * Current tab from the URL, validated.
     *
     * @param array $tabs Tabs.
     * @return string
     */
    public static function current_tab(array $tabs)
    {
        return self::current_key($tabs);
    }

    /*
     * ---------------------------------------------------------------------
     * Forms, cards and rows
     * ---------------------------------------------------------------------
     */

    /**
     * Open a settings form.
     *
     * @param string $page Menu key (used for the redirect back and nonce).
     * @param string $tab  Current tab.
     */
    public static function form_start($page, $tab = '')
    {
        self::$fields = array();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="authlify-form" data-authlify-form>
            <input type="hidden" name="action" value="authlify_save">
            <input type="hidden" name="authlify_page" value="<?php echo esc_attr($page); ?>">
            <input type="hidden" name="authlify_tab" value="<?php echo esc_attr($tab); ?>">
            <?php wp_nonce_field('authlify_save_' . $page, '_authlify_nonce'); ?>
        <?php
    }

    /**
     * Close a settings form with the save card.
     *
     * @param string $label Button label.
     */
    public static function form_end($label = '')
    {
        ?>
            <input type="hidden" name="authlify_fields" value="<?php echo esc_attr(implode(',', array_unique(self::$fields))); ?>">
            <?php self::save_bar($label); ?>
        </form>
        <?php
    }

    /**
     * The save card at the end of a form (sticks to the bottom while the form is on screen).
     *
     * @param string $label Button label.
     */
    public static function save_bar($label = '')
    {
        ?>
        <div class="authlify-savebar">
            <?php submit_button('' !== $label ? $label : __('Save changes', 'modify-login'), 'primary', 'submit', false); ?>
            <span class="authlify-savebar__hint" data-authlify-dirty hidden><?php esc_html_e('Unsaved changes', 'modify-login'); ?></span>
        </div>
        <?php
    }

    /**
     * Register a key as owned by the current form (for custom controls).
     *
     * @param string $key Key.
     */
    public static function own($key)
    {
        self::$fields[] = $key;
    }

    /**
     * Open a card.
     *
     * @param string $title       Title.
     * @param string $description Description.
     * @param string $id          Anchor.
     * @param string $badge       Optional pill (e.g. "Pro").
     * @param string $aside       Optional HTML at the top right (escaped by the caller).
     */
    public static function panel_start($title, $description = '', $id = '', $badge = '', $aside = '')
    {
        ?>
        <section class="authlify-panel"<?php echo '' !== $id ? ' id="' . esc_attr($id) . '"' : ''; ?>>
            <?php if ('' !== $title || '' !== $aside) : ?>
            <header class="authlify-panel__head">
                <div>
                    <?php if ('' !== $title) : ?>
                        <h2><?php echo esc_html($title); ?><?php echo '' !== $badge ? ' <span class="authlify-pro-tag">' . esc_html($badge) . '</span>' : ''; ?></h2>
                    <?php endif; ?>
                    <?php if ('' !== $description) : ?>
                        <p><?php echo wp_kses($description, self::inline_html()); ?></p>
                    <?php endif; ?>
                </div>
                <?php echo $aside; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?>
            </header>
            <?php endif; ?>
            <div class="authlify-panel__body">
        <?php
    }

    /**
     * Close a card.
     */
    public static function panel_end()
    {
        echo '</div></section>';
    }

    /**
     * Open a setting row.
     *
     * The help text gets an ID ("{$for}-help") so the control can point at it
     * with aria-describedby (see describedby()).
     *
     * @param string $label   Label.
     * @param string $help    Help text.
     * @param string $for     Input ID.
     * @param string $show_if Condition, e.g. 'authlify[key]=value'.
     * @param string $class   Extra row class.
     */
    public static function field_start($label, $help = '', $for = '', $show_if = '', $class = '')
    {
        ++self::$rows;
        self::$help_id = '' !== $help ? ('' !== $for ? $for : 'authlify-row-' . self::$rows) . '-help' : '';
        ?>
        <div class="authlify-field<?php echo '' !== $class ? ' ' . esc_attr($class) : ''; ?>"<?php echo '' !== $show_if ? ' data-authlify-show-if="' . esc_attr($show_if) . '"' : ''; ?>>
            <div class="authlify-field__label">
                <?php if ('' !== $for) : ?>
                    <label for="<?php echo esc_attr($for); ?>"><?php echo esc_html($label); ?></label>
                <?php else : ?>
                    <span><?php echo esc_html($label); ?></span>
                <?php endif; ?>
                <?php if ('' !== $help) : ?>
                    <p class="authlify-field__help" id="<?php echo esc_attr(self::$help_id); ?>"><?php echo wp_kses($help, self::inline_html()); ?></p>
                <?php endif; ?>
            </div>
            <div class="authlify-field__control">
        <?php
    }

    /**
     * An aria-describedby attribute that links the current row's control to
     * its help text and, optionally, to a note under the control.
     *
     * @param string $note_id ID of a note under the control, if any.
     * @return string Attribute (with a leading space), or ''.
     */
    public static function describedby($note_id = '')
    {
        $ids = array_filter(array(self::$help_id, (string) $note_id));

        return $ids ? ' aria-describedby="' . esc_attr(implode(' ', $ids)) . '"' : '';
    }

    /**
     * Close a setting row.
     */
    public static function field_end()
    {
        self::$help_id = '';
        echo '</div></div>';
    }

    /**
     * Toggle bound to a boolean setting.
     *
     * @param string $key   Setting key.
     * @param string $label Label beside the switch.
     * @param string $help  Help text under the label column.
     * @param string $title Row title (defaults to the label).
     */
    public static function toggle_row($key, $label, $help = '', $title = '')
    {
        self::own($key);
        $id = 'authlify-' . $key;
        self::field_start('' !== $title ? $title : $label, $help, $id);
        self::toggle('authlify[' . $key . ']', (bool) Settings::get($key), '' !== $title ? $label : '', $id, self::$help_id);
        self::field_end();
    }

    /**
     * Toggle switch.
     *
     * @param string $name    Input name.
     * @param bool   $checked Checked.
     * @param string $label   Label.
     * @param string $id      ID.
     * @param string $help_id ID of the text that describes it (aria-describedby).
     */
    public static function toggle($name, $checked, $label = '', $id = '', $help_id = '')
    {
        ?>
        <label class="authlify-toggle"<?php echo '' !== $id ? ' for="' . esc_attr($id) . '"' : ''; ?>>
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($checked); ?><?php echo '' !== $id ? ' id="' . esc_attr($id) . '"' : ''; ?><?php echo '' !== $help_id ? ' aria-describedby="' . esc_attr($help_id) . '"' : ''; ?>>
            <span class="authlify-toggle__track" aria-hidden="true"></span>
            <?php if ('' !== $label) : ?>
                <span class="authlify-toggle__label"><?php echo esc_html($label); ?></span>
            <?php endif; ?>
        </label>
        <?php
    }

    /**
     * Text/number/url input row.
     *
     * @param string $key   Setting key.
     * @param string $label Label.
     * @param string $help  Help.
     * @param array  $attrs type, placeholder, class, min, max, step, prefix, suffix, note,
     *                      show_if, autocomplete, pattern, title, secret (never print the stored value).
     */
    public static function input_row($key, $label, $help = '', array $attrs = array())
    {
        self::own($key);
        $id = 'authlify-' . $key;
        $type = isset($attrs['type']) ? $attrs['type'] : 'text';
        self::field_start($label, $help, $id, isset($attrs['show_if']) ? $attrs['show_if'] : '');

        $grouped = !empty($attrs['prefix']) || !empty($attrs['suffix']);
        if ($grouped) {
            echo '<span class="authlify-inline">';
        }
        if (!empty($attrs['prefix'])) {
            echo '<code>' . esc_html($attrs['prefix']) . '</code>';
        }
        $note_id = !empty($attrs['note']) ? $id . '-note' : '';
        $value = (string) Settings::get($key);
        // Secrets are never printed back into the page: an empty field keeps the stored value.
        if (!empty($attrs['secret']) && '' !== $value) {
            $value = '';
            $attrs['placeholder'] = __('Saved. Leave empty to keep it.', 'modify-login');
        }
        printf(
            '<input type="%1$s" id="%2$s" name="authlify[%3$s]" value="%4$s" class="%5$s"%6$s%7$s%8$s%9$s%10$s%11$s%12$s>',
            esc_attr($type),
            esc_attr($id),
            esc_attr($key),
            esc_attr($value),
            esc_attr(isset($attrs['class']) ? $attrs['class'] : ('number' === $type ? 'small-text' : 'regular-text')),
            isset($attrs['placeholder']) ? ' placeholder="' . esc_attr($attrs['placeholder']) . '"' : '',
            isset($attrs['min']) ? ' min="' . esc_attr($attrs['min']) . '"' : '',
            isset($attrs['max']) ? ' max="' . esc_attr($attrs['max']) . '"' : '',
            !empty($attrs['autocomplete']) ? ' autocomplete="' . esc_attr($attrs['autocomplete']) . '"' : '',
            self::describedby($note_id),
            !empty($attrs['pattern']) ? ' pattern="' . esc_attr($attrs['pattern']) . '"' . (!empty($attrs['title']) ? ' title="' . esc_attr($attrs['title']) . '"' : '') : '',
            isset($attrs['step']) ? ' step="' . esc_attr($attrs['step']) . '"' : ''
        );
        if (!empty($attrs['suffix'])) {
            echo '<span class="authlify-sublabel">' . esc_html($attrs['suffix']) . '</span>';
        }
        if ($grouped) {
            echo '</span>';
        }
        if (!empty($attrs['note'])) {
            echo '<p class="description" id="' . esc_attr($note_id) . '">' . wp_kses($attrs['note'], self::inline_html()) . '</p>';
        }

        self::field_end();
    }

    /**
     * Textarea row.
     *
     * @param string $key   Setting key.
     * @param string $label Label.
     * @param string $help  Help.
     * @param string $placeholder Placeholder.
     * @param array  $attrs       Optional: show_if ('authlify[key]=value'), rows, note.
     */
    public static function textarea_row($key, $label, $help = '', $placeholder = '', array $attrs = array())
    {
        self::own($key);
        $id = 'authlify-' . $key;
        self::field_start($label, $help, $id, isset($attrs['show_if']) ? $attrs['show_if'] : '');
        $note_id = !empty($attrs['note']) ? $id . '-note' : '';
        printf(
            '<textarea id="%1$s" name="authlify[%2$s]" rows="%5$d" class="large-text code" placeholder="%3$s"%6$s>%4$s</textarea>',
            esc_attr($id),
            esc_attr($key),
            esc_attr($placeholder),
            esc_textarea((string) Settings::get($key)),
            isset($attrs['rows']) ? (int) $attrs['rows'] : 4,
            self::describedby($note_id)
        );
        if (!empty($attrs['note'])) {
            echo '<p class="description" id="' . esc_attr($note_id) . '">' . wp_kses($attrs['note'], self::inline_html()) . '</p>';
        }
        self::field_end();
    }

    /**
     * Select, radio or choice-card row.
     *
     * @param string      $key     Setting key.
     * @param string      $label   Label.
     * @param array       $choices value => label, or value => array( title, description ) for
     *                             radios with a one-line explanation under each option. For
     *                             cards, each may also carry 'icon' and 'badge'.
     * @param string      $help    Help.
     * @param bool|string $radios  true for radio buttons, 'cards' for choice cards.
     */
    public static function choice_row($key, $label, array $choices, $help = '', $radios = false)
    {
        if ('cards' === $radios) {
            self::choice_cards_row($key, $label, $choices, $help);

            return;
        }

        self::own($key);
        $id = 'authlify-' . $key;
        $current = (string) Settings::get($key);
        self::field_start($label, $help, $radios ? '' : $id);

        if ($radios) {
            echo '<fieldset class="authlify-radios"' . self::describedby() . '><legend class="screen-reader-text">' . esc_html($label) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in describedby().
            foreach ($choices as $value => $text) {
                if (is_array($text)) {
                    $text = '<span class="authlify-option"><span class="authlify-option__title">' . wp_kses($text[0], self::inline_html()) . '</span>'
                        . (!empty($text[1]) ? '<span class="authlify-option__desc">' . wp_kses($text[1], self::inline_html()) . '</span>' : '')
                        . '</span>';
                } else {
                    $text = '<span>' . wp_kses($text, self::inline_html()) . '</span>';
                }
                printf(
                    '<label><input type="radio" name="authlify[%1$s]" value="%2$s" %3$s> %4$s</label><br>',
                    esc_attr($key),
                    esc_attr($value),
                    checked($current, (string) $value, false),
                    $text // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
                );
            }
            echo '</fieldset>';
        } else {
            printf('<select id="%1$s" name="authlify[%2$s]"%3$s>', esc_attr($id), esc_attr($key), self::describedby()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in describedby().
            foreach ($choices as $value => $text) {
                printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($value), selected($current, (string) $value, false), esc_html(is_array($text) ? $text[0] : $text));
            }
            echo '</select>';
        }

        self::field_end();
    }

    /**
     * A row of choice cards bound to a setting: the label and help on top,
     * then one card per option (icon, title, optional tag, one line).
     *
     * @param string $key     Setting key.
     * @param string $label   Label (the group's accessible name).
     * @param array  $choices value => array( title, description, 'icon' => name, 'badge' => text ).
     * @param string $help    Help.
     */
    public static function choice_cards_row($key, $label, array $choices, $help = '')
    {
        self::own($key);
        self::field_start($label, $help, '', '', 'authlify-field--stack');
        echo self::choices('authlify[' . $key . ']', (string) Settings::get($key), $choices, $label, self::$help_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in choices().
        self::field_end();
    }

    /**
     * Choice cards: a radio group that looks like cards.
     *
     * @param string $name     Input name.
     * @param string $current  Current value.
     * @param array  $options  value => array( title, description, 'icon', 'badge' ) or 'title'/'text' keys.
     * @param string $legend   Accessible name.
     * @param string $help_id  ID of the describing text.
     * @return string HTML.
     */
    public static function choices($name, $current, array $options, $legend, $help_id = '')
    {
        $html = '<fieldset class="authlify-choices"' . ('' !== $help_id ? ' aria-describedby="' . esc_attr($help_id) . '"' : '') . '><legend class="screen-reader-text">' . esc_html($legend) . '</legend>';
        foreach ($options as $value => $o) {
            $o = is_array($o) ? $o : array((string) $o);
            $title = isset($o['title']) ? $o['title'] : (isset($o[0]) ? $o[0] : (string) $value);
            $text = isset($o['text']) ? $o['text'] : (isset($o[1]) ? $o[1] : '');
            $id = 'authlify-choice-' . sanitize_html_class(trim(str_replace(array('[', ']'), '-', $name), '-') . '-' . $value);
            $html .= '<label class="authlify-choice" for="' . esc_attr($id) . '">'
                . '<input type="radio" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . checked((string) $current, (string) $value, false) . '>'
                . '<span class="authlify-choice__icon" aria-hidden="true">' . self::icon(!empty($o['icon']) ? $o['icon'] : 'dot', 20) . '</span>'
                . '<span class="authlify-choice__title">' . wp_kses($title, self::inline_html()) . (!empty($o['badge']) ? ' <span class="authlify-badge authlify-badge--accent">' . esc_html($o['badge']) . '</span>' : '') . '</span>'
                . ('' !== $text ? '<span class="authlify-choice__text">' . wp_kses($text, self::inline_html()) . '</span>' : '')
                . '</label>';
        }

        return $html . '</fieldset>';
    }

    /**
     * Stat card with an icon tile.
     *
     * @param string     $label Label.
     * @param int|string $value Number (formatted) or short text.
     * @param string     $icon  Icon name.
     * @param string     $url   Optional link.
     * @param string     $meta  Optional muted line.
     * @param string     $tone  '' or ok|warning|error (icon tile colour).
     */
    public static function stat($label, $value, $icon = 'dot', $url = '', $meta = '', $tone = '')
    {
        $tag = '' !== $url ? 'a' : 'div';
        echo '<' . $tag . ' class="authlify-stat' . ('' !== $tone ? ' authlify-stat--' . esc_attr($tone) : '') . '"' . ('a' === $tag ? ' href="' . esc_url($url) . '"' : '') . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag.
        echo '<span class="authlify-stat__icon" aria-hidden="true">' . self::icon($icon, 20) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
        echo '<span class="authlify-stat__value">' . esc_html(is_numeric($value) ? number_format_i18n((float) $value) : (string) $value) . '</span>';
        echo '<span class="authlify-stat__label">' . esc_html($label) . ('' !== $meta ? '<span class="authlify-stat__meta"> · ' . esc_html($meta) . '</span>' : '') . '</span>';
        echo '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag.
    }

    /**
     * Status pill.
     *
     * @param string $text Text.
     * @param string $type ok, warning, error, info, or neutral (for "Off" and other non-states).
     * @return string HTML.
     */
    public static function pill($text, $type = 'info')
    {
        return '<span class="authlify-pill authlify-pill--' . esc_attr($type) . '">' . esc_html($text) . '</span>';
    }

    /**
     * Admin notices passed through the redirect after saving.
     */
    public static function notices()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        if (!empty($_GET['authlify_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'modify-login') . '</p></div>';
        }

        // Errors are stored per user for one request, with or without the URL flag.
        $message = get_transient('authlify_error_' . get_current_user_id());
        if ($message) {
            delete_transient('authlify_error_' . get_current_user_id());
            echo '<div class="notice notice-error"><p>' . wp_kses($message, self::inline_html()) . '</p></div>';
        }

        if (!empty($_GET['authlify_notice'])) {
            $messages = apply_filters('authlify_admin_notice_messages', array(
                'slug_confirmed' => __('Your new login URL is confirmed and active. We emailed it to you as well.', 'modify-login'),
                'unlocked' => __('Lockouts cleared.', 'modify-login'),
                'log_cleared' => __('Activity log cleared.', 'modify-login'),
                'imported' => __('Settings imported.', 'modify-login'),
                'emailed' => __('We emailed the login URL to you and the site admin address.', 'modify-login'),
                'pending_cancelled' => __('Change cancelled. Your login URL stays as it was.', 'modify-login'),
            ));
            $key = sanitize_key(wp_unslash($_GET['authlify_notice']));
            if (isset($messages[$key])) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$key]) . '</p></div>';
            }
        }
        // phpcs:enable
    }

    /**
     * A small, quiet "Learn more" link to an article in the plugin's documentation.
     *
     * The visible text stays short; screen readers hear the article title too,
     * so the link makes sense out of context.
     *
     * @param string $article_id Article ID (see Docs::articles()).
     * @param string $label      Visible text. Default "Learn more".
     * @return string HTML (escaped). Empty when the article does not exist.
     * @since 3.0.0
     */
    public static function learn_more($article_id, $label = '')
    {
        if (!class_exists(Docs::class)) {
            return '';
        }
        $title = Docs::title($article_id);
        if ('' === $title) {
            return '';
        }

        return sprintf(
            '<a class="authlify-learn-more" href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
            esc_url(Docs::url($article_id)),
            esc_html('' !== $label ? $label : __('Learn more', 'modify-login')),
            /* translators: %s: documentation article title */
            esc_html(sprintf(__('(documentation: %s)', 'modify-login'), $title))
        );
    }

    /*
     * ---------------------------------------------------------------------
     * Footer
     * ---------------------------------------------------------------------
     */

    /**
     * Whether the header shows the vendor's own name (not white-labelled).
     *
     * @return bool
     */
    private static function own_brand()
    {
        $brand = self::brand();

        return in_array((string) $brand['name'], array('Authlify', 'Authlify Pro'), true) && !empty($brand['links']);
    }

    /**
     * Footer text on Authlify screens: a review request.
     *
     * @param string $text Text.
     * @return string
     */
    public static function footer_text($text)
    {
        if (!Menu::is_screen()) {
            return $text;
        }
        if (!self::own_brand()) {
            return '';
        }

        return '<span class="authlify-foot">' . sprintf(
            /* translators: %s: link to reviews */
            esc_html__('Enjoying Authlify? %s helps a lot.', 'modify-login'),
            '<a href="https://wordpress.org/support/plugin/modify-login/reviews/#new-post" target="_blank" rel="noopener">' . esc_html__('A review', 'modify-login') . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'modify-login') . '</span></a>'
        ) . '</span>';
    }

    /**
     * No version text in the footer on Authlify screens.
     *
     * @param string $text Text.
     * @return string
     */
    public static function footer_version($text)
    {
        return Menu::is_screen() ? '' : $text;
    }

    /*
     * ---------------------------------------------------------------------
     * Icons and allowed HTML
     * ---------------------------------------------------------------------
     */

    /**
     * Inline icons (Lucide-style strokes on a 24px grid, the set MatrixMap uses).
     *
     * @param string $name Name.
     * @param int    $size Pixel size.
     * @return string SVG.
     */
    public static function icon($name, $size = 18)
    {
        $paths = array(
            'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'alert' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
            'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'badge' => '<circle cx="12" cy="9" r="6"/><path d="M8.5 14 7 22l5-3 5 3-1.5-8"/>',
            'ban' => '<circle cx="12" cy="12" r="9"/><path d="m5.7 5.7 12.6 12.6"/>',
            'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
            'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
            'bot' => '<path d="M12 8V4H8"/><rect x="4" y="8" width="16" height="12" rx="2"/><path d="M2 14h2M20 14h2M15 13v2M9 13v2"/>',
            'building' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
            'chart' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'check-square' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="m9 12 2 2 4-4"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'cloud' => '<path d="M17.5 19H9a7 7 0 1 1 6.7-9h1.8a4.5 4.5 0 1 1 0 9z"/>',
            'code' => '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
            'copy' => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
            'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'dot' => '<circle cx="12" cy="12" r="3"/>',
            'download' => '<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
            'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5"/>',
            'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
            'eye-off' => '<path d="M9.9 4.2A10 10 0 0 1 12 4c6.4 0 10 8 10 8a17 17 0 0 1-2.2 3.2M6.6 6.6A17 17 0 0 0 2 12s3.6 8 10 8a9.7 9.7 0 0 0 5.4-1.6"/><path d="m2 2 20 20M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
            'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
            'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
            'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 0 1 4.9.8c0 1.7-2.4 2.2-2.4 3.7"/><path d="M12 17h.01"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
            'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m11 12 9-9M17 6l3 3M15 8l2 2"/>',
            'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
            'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
            'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
            'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
            'log-in' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5M15 12H3"/>',
            'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'palette' => '<circle cx="13.5" cy="6.5" r="1"/><circle cx="17.5" cy="10.5" r="1"/><circle cx="8.5" cy="7.5" r="1"/><circle cx="6.5" cy="12.5" r="1"/><path d="M12 2a10 10 0 0 0 0 20c1 0 1.7-.8 1.7-1.7 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.8-1.7 1.7-1.7H17a5 5 0 0 0 5-5C22 6 17.5 2 12 2z"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'route' => '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
            'server' => '<rect x="3" y="3" width="18" height="8" rx="2"/><rect x="3" y="13" width="18" height="8" rx="2"/><path d="M7 7h.01M7 17h.01"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 2.9-1.2V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'sliders' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
            'smartphone' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
            'spark' => '<path d="M12 3l1.8 4.9L19 9.7l-4.3 3 1.3 5.3L12 15.2 8 18l1.3-5.3L5 9.7l5.2-1.8z"/>',
            'swap' => '<path d="M7 7h13l-4-4M17 17H4l4 4"/>',
            'sync' => '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/>',
            'tag' => '<path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
            'tiles' => '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/>',
            'trash' => '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/>',
            'upload' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'user-check' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
            'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.8-.7-.7-2.8z"/>',
            'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        );
        $path = isset($paths[$name]) ? $paths[$name] : $paths['dot'];

        return '<svg class="authlify-icon" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
    }

    /**
     * Tags allowed in the brand logo.
     *
     * @return array
     */
    public static function svg_html()
    {
        $attrs = array('class' => true, 'width' => true, 'height' => true, 'viewbox' => true, 'aria-hidden' => true, 'focusable' => true, 'id' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'offset' => true, 'stop-color' => true, 'rx' => true, 'x' => true, 'y' => true, 'fill' => true, 'd' => true, 'cx' => true, 'cy' => true, 'r' => true, 'xmlns' => true, 'src' => true, 'alt' => true, 'style' => true);

        return array_fill_keys(array('svg', 'defs', 'lineargradient', 'stop', 'rect', 'path', 'circle', 'g', 'img', 'span'), $attrs);
    }

    /**
     * Inline HTML allowed in help text.
     *
     * @return array
     */
    public static function inline_html()
    {
        return array(
            'a' => array('href' => true, 'target' => true, 'rel' => true, 'class' => true),
            'span' => array('class' => true),
            'code' => array(),
            'strong' => array(),
            'em' => array(),
            'br' => array(),
        );
    }
}
