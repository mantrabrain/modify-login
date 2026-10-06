<?php
/**
 * Main plugin class.
 *
 * @package Authlify
 */

namespace Authlify;

defined('ABSPATH') || exit;

/**
 * Boots every module.
 */
final class Plugin
{
    /**
     * Instance.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * The instance.
     *
     * @return Plugin
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Wire up the modules.
     */
    private function __construct()
    {
        /**
         * Fires before Authlify loads (2.x action name).
         *
         * @since 2.0.0
         */
        do_action('before_modify_login_init');

        $modules = array(
            Install\Upgrader::class,
            Login\Router::class,
            Login\Recovery::class,
            Login\Redirects::class,
            Security\Limiter::class,
            Security\Hardening::class,
            Log\Log::class,
            Compat\Legacy::class,
            Compat\Caching::class,
            Captcha\Captcha::class,
            TwoFactor\TwoFactor::class,
            Security\Passwords::class,
            Security\SigninNotice::class,
            Designer\Designer::class,
            Diagnostics\LeakCheck::class,
            Diagnostics\SiteHealth::class,
            Diagnostics\Conflicts::class,
        );

        if (is_admin()) {
            $modules[] = Admin\Menu::class;
            $modules[] = Admin\Onboarding::class;
        }

        if (defined('WP_CLI') && WP_CLI) {
            $modules[] = Cli::class;
        }

        /**
         * Filters the modules Authlify boots. Each class needs a static init().
         *
         * @param string[] $modules Class names.
         * @since 3.0.0
         */
        foreach (apply_filters('authlify_modules', $modules) as $module) {
            if (class_exists($module) && method_exists($module, 'init')) {
                $module::init();
            }
        }

        add_action('init', array($this, 'load_textdomain'), 0);

        // Add-ons register settings keys while plugins load; drop any settings
        // cached before then so their keys get defaults.
        add_action('plugins_loaded', array(Settings::class, 'flush'), 6);
        add_action('plugins_loaded', array(Settings::class, 'flush'), 21);

        /**
         * Fires once Authlify has loaded. Add-ons (Authlify Pro) hook in here.
         *
         * @since 3.0.0
         */
        add_action('plugins_loaded', function () {
            do_action('authlify_loaded');

            /**
             * Fires once the plugin has loaded (2.x action name).
             *
             * @since 2.0.0
             */
            do_action('modify_login_init');
        }, 20);
    }

    /**
     * Load translations.
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('modify-login', false, dirname(AUTHLIFY_BASENAME) . '/languages');
    }

    /**
     * Whether Authlify Pro is active.
     *
     * @return bool
     */
    public static function has_pro()
    {
        return defined('AUTHLIFY_PRO_VERSION');
    }

    /**
     * Capability required to manage Authlify.
     *
     * @return string
     */
    public static function cap()
    {
        return Settings::is_network() ? 'manage_network_options' : 'manage_options';
    }
}
