<?php
/**
 * WordPress Abilities API (read-only).
 *
 * @package Authlify
 */

namespace Authlify;

use Authlify\Diagnostics\LeakCheck;
use Authlify\Log\Log;
use Authlify\Login\Router;
use Authlify\Security\Limiter;

defined('ABSPATH') || exit;

/**
 * Three read-only abilities for tools and AI agents on WordPress 6.9 and later:
 * the protection status, a count of recent lockouts and the last Leak Check
 * result. They need the capability that manages Authlify. Their output holds
 * no login address, IP address, username or key, and they are not offered over
 * the REST API or to AI clients until a site says so with the
 * `authlify_abilities_public` filter.
 *
 * On older WordPress nothing here runs: core never fires these actions.
 *
 * @since 3.2.0
 */
final class Abilities
{
    const CATEGORY = 'authlify';

    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('wp_abilities_api_categories_init', array(__CLASS__, 'register_category'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'register'));
    }

    /**
     * Who may run the abilities.
     *
     * @return bool
     */
    public static function can_run()
    {
        return current_user_can(Plugin::cap());
    }

    /**
     * Register the category.
     */
    public static function register_category()
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category(self::CATEGORY, array(
            'label' => __('Login security', 'modify-login'),
            'description' => __('Status of the login protection on this site.', 'modify-login'),
        ));
    }

    /**
     * The abilities: name => arguments for wp_register_ability().
     *
     * @return array
     */
    public static function definitions()
    {
        $public = (bool) apply_filters('authlify_abilities_public', false);
        $meta = array(
            'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            'public' => $public,
            'show_in_rest' => $public,
        );
        $common = array(
            'category' => self::CATEGORY,
            'permission_callback' => array(__CLASS__, 'can_run'),
            'meta' => $meta,
        );

        return array(
            'authlify/get-status' => array_merge($common, array(
                'label' => __('Get login protection status', 'modify-login'),
                'description' => __('Which login protections are switched on. Never includes the login address or any key.', 'modify-login'),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'version' => array('type' => 'string'),
                        'pro_active' => array('type' => 'boolean'),
                        'custom_login_url' => array('type' => 'boolean'),
                        'wp_login_hidden' => array('type' => 'boolean'),
                        'lockouts_enabled' => array('type' => 'boolean'),
                        'captcha_provider' => array('type' => 'string'),
                        'two_factor_enabled' => array('type' => 'boolean'),
                        'activity_log_enabled' => array('type' => 'boolean'),
                    ),
                ),
                'execute_callback' => array(__CLASS__, 'status'),
            )),
            'authlify/get-recent-lockouts' => array_merge($common, array(
                'label' => __('Count recent lockouts', 'modify-login'),
                'description' => __('How many lockouts were logged in the last hours and how many are active now. Counts only, no addresses or usernames.', 'modify-login'),
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'hours' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 720, 'default' => 24),
                    ),
                    'additionalProperties' => false,
                    'default' => array(),
                ),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'hours' => array('type' => 'integer'),
                        'logged' => array('type' => 'integer'),
                        'active' => array('type' => 'integer'),
                        'activity_log_enabled' => array('type' => 'boolean'),
                    ),
                ),
                'execute_callback' => array(__CLASS__, 'recent_lockouts'),
            )),
            'authlify/get-leak-check' => array_merge($common, array(
                'label' => __('Get the Leak Check result', 'modify-login'),
                'description' => __('The last Leak Check: when it ran, its overall state and how many probes passed or failed. Never includes the probes, which can name the login address.', 'modify-login'),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'has_run' => array('type' => 'boolean'),
                        'time' => array('type' => 'integer'),
                        'state' => array('type' => 'string'),
                        'counts' => array('type' => 'object'),
                    ),
                ),
                'execute_callback' => array(__CLASS__, 'leak_check'),
            )),
        );
    }

    /**
     * Register the abilities, then let add-ons register theirs.
     */
    public static function register()
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        /**
         * Filters Authlify's abilities before they are registered: change or
         * remove one. Keep the capability check.
         *
         * @param array $abilities Ability name => arguments for wp_register_ability().
         * @since 3.2.0
         */
        foreach ((array) apply_filters('authlify_abilities', self::definitions()) as $name => $args) {
            wp_register_ability((string) $name, (array) $args);
        }

        /**
         * Fires after Authlify's read-only abilities are registered, inside
         * `wp_abilities_api_init`. Authlify Pro registers its write abilities
         * here. Use the category Abilities::CATEGORY, check a capability and
         * write a log entry.
         *
         * @since 3.2.0
         */
        do_action('authlify_register_abilities');
    }

    /**
     * The protection status.
     *
     * @return array
     */
    public static function status()
    {
        return self::output('authlify/get-status', array(
            'version' => (string) AUTHLIFY_VERSION,
            'pro_active' => Plugin::has_pro(),
            'custom_login_url' => '' !== Router::slug(),
            'wp_login_hidden' => Router::is_hiding(),
            'lockouts_enabled' => Limiter::enabled(),
            'captcha_provider' => (string) Settings::get('captcha_provider', 'none'),
            'two_factor_enabled' => (bool) Settings::get('twofa_enabled', true),
            'activity_log_enabled' => (bool) Settings::get('log_enabled', true),
        ));
    }

    /**
     * Lockout counts.
     *
     * @param array|null $input hours.
     * @return array
     */
    public static function recent_lockouts($input = null)
    {
        global $wpdb;

        $hours = is_array($input) && isset($input['hours']) ? (int) $input['hours'] : 24;
        $hours = min(720, max(1, $hours));
        $table = Log::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $logged = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event = 'lockout' AND created_at >= %s", gmdate('Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS)));

        return self::output('authlify/get-recent-lockouts', array(
            'hours' => $hours,
            'logged' => $logged,
            'active' => count(Limiter::active_lockouts(true)),
            'activity_log_enabled' => (bool) Settings::get('log_enabled', true),
        ));
    }

    /**
     * The last Leak Check, without its probes.
     *
     * @return array
     */
    public static function leak_check()
    {
        $last = LeakCheck::last();

        return self::output('authlify/get-leak-check', array(
            'has_run' => null !== $last,
            'time' => $last ? (int) $last['time'] : 0,
            'state' => $last ? (string) $last['state'] : 'none',
            'counts' => $last && isset($last['counts']) ? (object) (array) $last['counts'] : new \stdClass(),
        ));
    }

    /**
     * Let a site change what an ability returns.
     *
     * @param string $name   Ability name.
     * @param array  $output Output.
     * @return array
     */
    private static function output($name, array $output)
    {
        /**
         * Filters what a read-only ability returns. Whoever runs it has the
         * capability that manages Authlify, but the output can travel to other
         * tools, so think before adding the login address.
         *
         * @param array  $output Output.
         * @param string $name   Ability name.
         * @since 3.2.0
         */
        return (array) apply_filters('authlify_ability_output', $output, $name);
    }
}
