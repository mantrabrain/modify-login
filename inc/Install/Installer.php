<?php
/**
 * Activation, tables and cron.
 *
 * @package Authlify
 */

namespace Authlify\Install;

defined('ABSPATH') || exit;

/**
 * Creates tables and schedules. Never deletes data (see uninstall.php).
 */
final class Installer
{
    const DB_VERSION = 1;

    /**
     * Activation.
     *
     * @param bool $network_wide Network activation.
     */
    public static function activate($network_wide = false)
    {
        self::create_tables();
        self::schedule();
        Upgrader::maybe_upgrade();

        // Old 2.x rewrite rule is gone; flush once so it stops matching.
        delete_option('rewrite_rules');
    }

    /**
     * Deactivation: stop cron. Nothing else, so reactivating restores everything.
     */
    public static function deactivate()
    {
        wp_clear_scheduled_hook('authlify_daily');
    }

    /**
     * Schedule the daily housekeeping event.
     */
    public static function schedule()
    {
        if (!wp_next_scheduled('authlify_daily')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'authlify_daily');
        }
    }

    /**
     * Create or update tables (dbDelta).
     */
    public static function create_tables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->base_prefix;

        $sql = array();

        $sql[] = "CREATE TABLE {$prefix}authlify_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            event varchar(32) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            username varchar(191) NOT NULL DEFAULT '',
            ip varchar(45) NOT NULL DEFAULT '',
            country char(2) NOT NULL DEFAULT '',
            user_agent varchar(255) NOT NULL DEFAULT '',
            context text NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY event_created (event, created_at),
            KEY user_id (user_id),
            KEY ip (ip)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}authlify_limits (
            scope varchar(8) NOT NULL,
            subject varchar(191) NOT NULL,
            failures int(10) unsigned NOT NULL DEFAULT 0,
            last_failure int(10) unsigned NOT NULL DEFAULT 0,
            locked_until int(10) unsigned NOT NULL DEFAULT 0,
            lockouts int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (scope, subject),
            KEY locked_until (locked_until)
        ) {$charset};";

        /**
         * Filters the CREATE TABLE statements (modules add their own tables).
         *
         * @param string[] $sql     Statements.
         * @param string   $prefix  Base table prefix.
         * @param string   $charset Charset/collation clause.
         * @since 3.0.0
         */
        $sql = apply_filters('authlify_install_tables', $sql, $prefix, $charset);

        dbDelta($sql);

        // Tables are shared by the whole network, so this is network-wide; on a
        // single site it is autoloaded (it is read on every request).
        if (is_multisite()) {
            update_site_option('authlify_db_version', self::DB_VERSION);
        } else {
            update_option('authlify_db_version', self::DB_VERSION, true);
            if (function_exists('wp_set_option_autoload')) {
                wp_set_option_autoload('authlify_db_version', true);
            }
        }
    }

    /**
     * Whether the tables need creating or updating.
     *
     * @return bool
     */
    public static function needs_tables()
    {
        return (int) get_site_option('authlify_db_version', 0) < self::DB_VERSION;
    }
}
