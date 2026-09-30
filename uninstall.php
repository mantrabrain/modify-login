<?php
/**
 * Uninstall: removes data only when the admin turned on "Delete all data".
 *
 * Single site: everything goes when the site's setting is on.
 *
 * Multisite: the setting is read where it was made. When Authlify ran
 * network-wide (the network option), the whole network is cleaned. When it
 * was activated site by site, each site whose own setting is on is cleaned:
 * its options, transients, compiled design files, cron events and its rows
 * in the shared log. The shared tables and user meta go only when no site
 * keeps its data.
 *
 * @package Authlify
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

/**
 * Whether a settings array asks for deletion.
 *
 * @param mixed $settings authlify_settings value.
 * @return bool
 */
function authlify_uninstall_wants_delete($settings)
{
    return is_array($settings) && !empty($settings['delete_data']);
}

/**
 * Remove the current site's options, transients, capability, files and cron.
 */
function authlify_uninstall_site()
{
    global $wpdb;

    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'modify_login_logs'); // phpcs:ignore
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'authlify\\_%' OR option_name LIKE 'modify\\_login\\_%' OR option_name IN ('mb_login_endpoint', 'mb_redirect_url')"); // phpcs:ignore
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_authlify\\_%' OR option_name LIKE '\\_transient\\_timeout\\_authlify\\_%' OR option_name LIKE '\\_site\\_transient\\_authlify\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_authlify\\_%'"); // phpcs:ignore
    wp_cache_delete('alloptions', 'options');

    $admin = get_role('administrator');
    if ($admin) {
        $admin->remove_cap('manage_modify_login');
    }

    // Compiled login-page CSS and anything else in this site's uploads/authlify/ (recursively).
    $uploads = wp_upload_dir(null, false);
    authlify_uninstall_rmdir(trailingslashit($uploads['basedir']) . 'authlify');
    // Modify Login 2.x kept its builder files here.
    authlify_uninstall_rmdir(trailingslashit($uploads['basedir']) . 'modify-login');

    // Every Authlify and Authlify Pro scheduled event on this site, whatever its
    // arguments (Pro's own uninstall may run after this one and can no longer
    // read the "delete data" setting).
    $crons = _get_cron_array();
    foreach ((array) $crons as $hooks) {
        foreach ((array) $hooks as $hook => $events) {
            if (0 === strpos((string) $hook, 'authlify_')) {
                wp_unschedule_hook($hook);
            }
        }
    }
}

/**
 * Delete a folder and everything in it.
 *
 * @param string $dir Folder.
 */
function authlify_uninstall_rmdir($dir)
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }

    foreach ((array) scandir($dir) as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            authlify_uninstall_rmdir($path);
        } else {
            wp_delete_file($path);
        }
    }
    @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
}

/**
 * Drop the shared tables and everything network-wide.
 */
function authlify_uninstall_shared()
{
    global $wpdb;

    // Authlify 3.x tables, and any table an add-on created under the same prefix.
    $like = $wpdb->esc_like($wpdb->base_prefix . 'authlify_') . '%';
    $tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like)); // phpcs:ignore
    foreach (array_unique(array_merge($tables, array($wpdb->base_prefix . 'authlify_log', $wpdb->base_prefix . 'authlify_limits', $wpdb->base_prefix . 'authlify_passkeys'))) as $table) {
        $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`'); // phpcs:ignore
    }

    // Temporary or blocked accounts must not become ordinary accounts once the
    // markers that restrict them are deleted: strip their roles and sessions first.
    $authlify_restricted = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('authlify_temp_expires', 'authlify_sign_in_blocked')"); // phpcs:ignore
    foreach ((array) $authlify_restricted as $authlify_uid) {
        $authlify_user = get_userdata((int) $authlify_uid);
        if ($authlify_user) {
            $authlify_user->set_role('');
            if (is_multisite()) {
                foreach (get_blogs_of_user($authlify_user->ID) as $authlify_blog) {
                    remove_user_from_blog($authlify_user->ID, $authlify_blog->userblog_id);
                }
            }
            WP_Session_Tokens::get_instance($authlify_user->ID)->destroy_all();
        }
    }

    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'authlify\\_%'"); // phpcs:ignore

    if (is_multisite()) {
        $wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'authlify\\_%' OR meta_key LIKE '\\_site\\_transient\\_authlify\\_%' OR meta_key LIKE '\\_site\\_transient\\_timeout\\_authlify\\_%'"); // phpcs:ignore
    }
}

if (!is_multisite()) {
    if (!authlify_uninstall_wants_delete(get_option('authlify_settings', array()))) {
        return;
    }

    authlify_uninstall_site();
    authlify_uninstall_shared();

    return;
}

// Multisite.
$authlify_network_settings = get_site_option('authlify_settings', null);
$authlify_network = authlify_uninstall_wants_delete($authlify_network_settings);
$authlify_sites = get_sites(array('fields' => 'ids', 'number' => 0));
// Network settings that say "keep" keep the shared data.
$authlify_kept = is_array($authlify_network_settings) && $authlify_network_settings && !$authlify_network;
$authlify_cleaned = array();

foreach ($authlify_sites as $authlify_site) {
    switch_to_blog($authlify_site);

    $authlify_own = get_option('authlify_settings', null);
    // A site with its own settings follows its own choice; sites without follow the network.
    $authlify_delete = null === $authlify_own || false === $authlify_own ? $authlify_network : authlify_uninstall_wants_delete($authlify_own);

    if ($authlify_delete) {
        authlify_uninstall_site();
        $authlify_cleaned[] = (int) $authlify_site;
    } elseif (is_array($authlify_own)) {
        $authlify_kept = true;
    }

    restore_current_blog();
}

if (!$authlify_kept && ($authlify_network || $authlify_cleaned)) {
    // Nobody keeps data: drop the shared tables, user meta and network options.
    authlify_uninstall_shared();
} elseif ($authlify_cleaned) {
    // Shared tables stay for the sites that keep their data; remove the
    // cleaned sites' rows from the shared log.
    $authlify_log = $wpdb->base_prefix . 'authlify_log';
    if ($authlify_log === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $authlify_log))) { // phpcs:ignore
        foreach ($authlify_cleaned as $authlify_blog) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $authlify_log . ' WHERE blog_id = %d', $authlify_blog)); // phpcs:ignore
        }
    }
}
