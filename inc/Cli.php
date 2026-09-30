<?php
/**
 * WP-CLI commands.
 *
 * @package Authlify
 */

namespace Authlify;

use Authlify\Login\Router;
use Authlify\Security\Limiter;

defined('ABSPATH') || exit;

/**
 * Manage Authlify from the command line. Works when you are locked out.
 *
 * ## EXAMPLES
 *
 *     wp authlify url get
 *     wp authlify url set my-door
 *     wp authlify url reset
 *     wp authlify unlock 203.0.113.7
 *     wp authlify unlock --all
 *     wp authlify lockouts
 */
final class Cli
{
    /**
     * Register the command.
     */
    public static function init()
    {
        \WP_CLI::add_command('authlify', __CLASS__);
    }

    /**
     * Show, change or reset the login URL.
     *
     * ## OPTIONS
     *
     * <action>
     * : get, set or reset.
     * ---
     * options:
     *   - get
     *   - set
     *   - reset
     * ---
     *
     * [<slug>]
     * : New slug (for set).
     *
     * @param array $args Args.
     */
    public function url($args)
    {
        $action = $args[0];

        if ('get' === $action) {
            $slug = Router::slug();
            \WP_CLI::line('' === $slug ? site_url('wp-login.php', 'login') . ' (custom login URL is off)' : Router::login_url());

            return;
        }

        if ('reset' === $action && defined('AUTHLIFY_SLUG')) {
            \WP_CLI::error('The login URL is forced by AUTHLIFY_SLUG in wp-config.php. Remove that line to reset it.');
        }

        if ('reset' === $action) {
            Settings::update(array('login_slug' => '', 'pending_slug' => '', 'pending_token' => '', 'pending_expires' => 0));
            \WP_CLI::success('Custom login URL turned off. Log in at ' . site_url('wp-login.php', 'login'));

            return;
        }

        if (defined('AUTHLIFY_SLUG') || defined('AUTHLIFY_DISABLE_HIDE')) {
            \WP_CLI::error('The login URL is set by a constant in wp-config.php (AUTHLIFY_SLUG or AUTHLIFY_DISABLE_HIDE). Remove it first.');
        }
        if (!isset($args[1]) || '' === trim($args[1])) {
            \WP_CLI::error('Give the new slug, for example: wp authlify url set my-door');
        }

        $slug = strtolower(trim($args[1], '/ '));
        if (Settings::clean_slug($slug) !== $slug) {
            \WP_CLI::error('The slug may only contain letters, numbers, hyphens and underscores.');
        }
        $valid = Admin\LoginUrlPage::validate_slug($slug);
        if (is_wp_error($valid)) {
            \WP_CLI::error($valid->get_error_message());
        }

        Settings::update(array('login_slug' => $slug, 'pending_slug' => '', 'pending_token' => '', 'pending_expires' => 0));
        \WP_CLI::success('Login URL is now ' . Router::login_url());
    }

    /**
     * Lift lockouts.
     *
     * ## OPTIONS
     *
     * [<ip>]
     * : IP address or CIDR network to unlock.
     *
     * [--all]
     * : Unlock everything.
     *
     * @param array $args  Args.
     * @param array $assoc Options.
     */
    public function unlock($args, $assoc)
    {
        if (!empty($assoc['all'])) {
            $n = Limiter::unlock('');
        } elseif (!empty($args[0])) {
            $n = Limiter::unlock($args[0]);
        } else {
            \WP_CLI::error('Give an IP address, or --all.');

            return;
        }

        \WP_CLI::success(sprintf('%d lockout record(s) cleared.', $n));
    }

    /**
     * List active lockouts.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param array $args  Args.
     * @param array $assoc Options.
     */
    public function lockouts($args, $assoc)
    {
        $rows = array();
        foreach (Limiter::active_lockouts() as $row) {
            $rows[] = array(
                'scope' => $row->scope,
                'subject' => $row->subject,
                'until' => gmdate('Y-m-d H:i:s', (int) $row->locked_until) . ' UTC',
                'lockouts' => $row->lockouts,
            );
        }

        \WP_CLI\Utils\format_items($assoc['format'], $rows, array('scope', 'subject', 'until', 'lockouts'));
    }

    /**
     * Reset two-factor login for a user (removes their authenticator, backup codes and passkeys).
     *
     * <user>
     * : User ID, login or email.
     *
     * @param array $args Args.
     */
    public function reset_2fa($args)
    {
        $user = is_numeric($args[0]) ? get_user_by('id', (int) $args[0]) : get_user_by(is_email($args[0]) ? 'email' : 'login', $args[0]);
        if (!$user) {
            \WP_CLI::error('User not found.');
        }

        /**
         * Resets every two-factor method for a user.
         *
         * @param int $user_id User ID.
         * @since 3.0.0
         */
        do_action('authlify_reset_two_factor', $user->ID);
        \WP_CLI::success(sprintf('Two-factor login reset for %s.', $user->user_login));
    }
}
