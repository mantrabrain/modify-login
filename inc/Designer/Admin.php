<?php
/**
 * Designer admin page.
 *
 * @package Authlify
 */

namespace Authlify\Designer;

use Authlify\Admin\UI;
use Authlify\Plugin;

defined('ABSPATH') || exit;

/**
 * The "Login designer" page (slug modify-login-builder, kept from 2.x): a
 * React app built from assets/designer/src into assets/designer/build, using
 * WordPress's own wp-element, wp-components, wp-api-fetch and wp-i18n.
 *
 * @since 3.0.0
 */
final class Admin
{
    const SLUG = 'modify-login-builder';

    /**
     * Wire up.
     *
     * @since 3.0.0
     */
    public static function init()
    {
        add_filter('authlify_admin_pages', array(__CLASS__, 'page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    /**
     * Register the page.
     *
     * @param array $pages Pages.
     * @return array
     * @since 3.0.0
     */
    public static function page($pages)
    {
        $pages['designer'] = array(self::SLUG, __('Designer', 'modify-login'), array(__CLASS__, 'render'), 40);

        return $pages;
    }

    /**
     * Whether the designer page is showing.
     *
     * @return bool
     * @since 3.0.0
     */
    private static function is_page()
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        return isset($_GET['page']) && self::SLUG === sanitize_key(wp_unslash($_GET['page']));
    }

    /**
     * Enqueue the app.
     *
     * @since 3.0.0
     */
    public static function enqueue()
    {
        if (!self::is_page() || !current_user_can(Plugin::cap())) {
            return;
        }

        $asset_file = AUTHLIFY_DIR . 'assets/designer/build/index.asset.php';
        if (!is_readable($asset_file)) {
            return;
        }
        $asset = require $asset_file;

        // WordPress 6.4 and 6.5 have no react-jsx-runtime handle; provide it from React.
        if (!wp_script_is('react-jsx-runtime', 'registered')) {
            wp_register_script('react-jsx-runtime', false, array('react'), AUTHLIFY_VERSION, true); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NoExplicitVersion
            wp_add_inline_script('react-jsx-runtime', 'window.ReactJSXRuntime=window.ReactJSXRuntime||(function(R){function j(t,p,k){var q=Object.assign({},p);if(k!==undefined){q.key=k;}return R.createElement(t,q);}return{jsx:j,jsxs:j,Fragment:R.Fragment};})(window.React);');
        }

        wp_enqueue_media();
        wp_enqueue_script('authlify-designer', AUTHLIFY_URL . 'assets/designer/build/index.js', $asset['dependencies'], $asset['version'], true);
        wp_set_script_translations('authlify-designer', 'modify-login', AUTHLIFY_DIR . 'languages');
        wp_enqueue_style('wp-components');
        if (is_readable(AUTHLIFY_DIR . 'assets/designer/build/style-index.css')) {
            wp_enqueue_style('authlify-designer', AUTHLIFY_URL . 'assets/designer/build/style-index.css', array('wp-components'), $asset['version']);
            wp_style_add_data('authlify-designer', 'rtl', 'replace');
        }

        wp_add_inline_script('authlify-designer', 'window.authlifyDesigner = ' . wp_json_encode(self::data()) . ';', 'before');
    }

    /**
     * Data for the app.
     *
     * @return array
     * @since 3.0.0
     */
    private static function data()
    {
        $fonts = array();
        foreach (Design::fonts() as $key => $font) {
            $fonts[] = array('value' => $key, 'label' => $font[0]);
        }

        $draft = Design::draft(get_current_user_id());
        $saved = Design::saved();

        return array(
            'design' => $saved,
            'draft' => $draft && wp_json_encode($draft) !== wp_json_encode($saved) ? $draft : null,
            'defaults' => Design::defaults(),
            'templates' => Templates::all(),
            'fonts' => $fonts,
            'fontsBase' => AUTHLIFY_URL . 'assets/designer/fonts/',
            'imagesBase' => AUTHLIFY_URL . 'assets/designer/images/',
            'previewUrl' => wp_login_url(),
            'previewToken' => wp_create_nonce(Frontend::NONCE),
            'canRegister' => (bool) get_option('users_can_register'),
            'siteName' => get_bloginfo('name'),
            'siteIcon' => (string) get_site_icon_url(128),
            'homeUrl' => home_url('/'),
            'restPath' => '/' . Rest::NS . '/designer',
            'legacy' => 'legacy-2x' === $saved['template'],
        );
    }

    /**
     * Page.
     *
     * @since 3.0.0
     */
    public static function render()
    {
        ?>
        <div class="wrap authlify-page authlify-designer-page">
            <h1 class="screen-reader-text"><?php esc_html_e('Designer', 'modify-login'); ?></h1>
            <?php UI::notices(); ?>
            <?php UI::family_tabs(); ?>
            <div id="authlify-designer-root" class="authlify-designer-root">
                <?php if (!is_readable(AUTHLIFY_DIR . 'assets/designer/build/index.js')) : ?>
                    <div class="notice notice-error"><p><?php esc_html_e('The designer app is missing. Run "npm run build:designer" in the plugin folder.', 'modify-login'); ?></p></div>
                <?php else : ?>
                    <p class="authlify-designer-loading"><?php esc_html_e('Loading the designer…', 'modify-login'); ?></p>
                    <noscript><div class="notice notice-error"><p><?php esc_html_e('The login designer needs JavaScript.', 'modify-login'); ?></p></div></noscript>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
