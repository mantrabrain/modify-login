<?php
/**
 * Migration from Modify Login 2.x and 1.x (fixtures in tests/fixtures), and a
 * fresh install. Each test puts the site back into its "never ran Authlify 3"
 * state, runs the real upgrade routine and compares the settings it produced.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Install\Upgrader;
use Authlify\Settings;

const LEGACY_OPTIONS = array('modify_login_settings', 'modify_login_version', 'modify_login_login_endpoint', 'mb_login_endpoint', 'mb_redirect_url', 'authlify_slug_changed_on_upgrade');

function fixture($name)
{
    return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/' . $name), true);
}

/**
 * Pretend Authlify 3 never ran, set up the legacy data, run the upgrade.
 */
function run_first_upgrade(array $options, array $log_rows = array())
{
    global $wpdb;
    delete_option(Settings::OPTION);
    delete_site_option('authlify_version');
    delete_site_option('authlify_migrated_from');
    delete_site_option('authlify_fresh_install');
    delete_site_transient('authlify_upgrading');
    foreach ($options as $name => $value) {
        update_option($name, $value);
    }
    if ($log_rows) {
        $table = $wpdb->prefix . 'modify_login_logs';
        $wpdb->query("CREATE TABLE {$table} (id bigint(20) NOT NULL AUTO_INCREMENT, user_id bigint(20) NOT NULL, ip_address varchar(45) NOT NULL, user_agent text NOT NULL, status varchar(20) NOT NULL, attempted_username varchar(255) DEFAULT NULL, country varchar(100) DEFAULT NULL, city varchar(100) DEFAULT NULL, created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))");
        foreach ($log_rows as $row) {
            $wpdb->insert($table, $row);
        }
    }
    Settings::flush();
    Upgrader::maybe_upgrade();
    Settings::flush();

    return get_option(Settings::OPTION);
}

after_each(function () {
    global $wpdb;
    foreach (LEGACY_OPTIONS as $name) {
        delete_option($name);
    }
    delete_site_option('authlify_slug_changed_on_upgrade');
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'modify_login_logs');
    $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->base_prefix . 'authlify_log WHERE username LIKE %s', 'legacy-%'));
});

t('2.x: login URL, protection state, redirects, logging and reCAPTCHA carry over; new protection stays off', function () {
    $fx = fixture('modify-login-2x.json');
    $s = run_first_upgrade(array('modify_login_settings' => $fx, 'modify_login_version' => '2.1.0'));
    ok(is_array($s), 'settings written');
    eq('members-door', $s['login_slug']);
    eq(true, $s['block_wp_login']);
    eq('redirect', $s['blocked_response'], '2.x redirected blocked visitors');
    eq('https://example.com/go-away', $s['blocked_redirect_url']);
    eq('https://example.com/welcome/{username}', $s['login_redirect_url']);
    eq('https://example.com/bye', $s['logout_redirect_url']);
    eq(true, $s['log_enabled']);
    eq('headers', $s['geo_source']);
    eq('recaptcha_v2', $s['captcha_provider']);
    eq('6Lc-site', $s['captcha_site_key']);
    eq('6Lc-secret', $s['captcha_secret_key']);
    eq(array('login'), $s['captcha_forms']);
    eq(false, $s['limit_enabled'], 'brute-force limits ship off on upgraded sites');
    eq(false, $s['honeypot']);
    eq(true, $s['onboarding_done']);
    eq('2.x', get_site_option('authlify_migrated_from'));
    eq(AUTHLIFY_VERSION, get_site_option('authlify_version'));
    eq('members-door', \Authlify\Login\Router::slug(), 'the router serves the same URL');
    eq('https://example.com/go-away', get_option('modify_login_settings')['redirect_url'], 'old options are kept');
});

t('2.x: protection off, tracking off, reCAPTCHA off are kept off', function () {
    $fx = array_merge(fixture('modify-login-2x.json'), array('enable_redirect' => false, 'enable_tracking' => 'no', 'enable_recaptcha' => 'no'));
    $s = run_first_upgrade(array('modify_login_settings' => $fx));
    eq(false, $s['block_wp_login'], 'wp-login.php stays reachable, as in 2.x');
    eq(false, $s['log_enabled']);
    eq('none', $s['captcha_provider']);
    eq('', $s['captcha_secret_key']);
});

t('2.x: reCAPTCHA on but keys missing does not turn a CAPTCHA on', function () {
    $fx = array_merge(fixture('modify-login-2x.json'), array('recaptcha_secret_key' => ''));
    $s = run_first_upgrade(array('modify_login_settings' => $fx));
    eq('none', $s['captcha_provider']);
});

t('2.x: an invalid 2.x slug is cleaned and the change recorded; empty slug becomes "setup"', function () {
    $s = run_first_upgrade(array('modify_login_settings' => array_merge(fixture('modify-login-2x.json'), array('login_endpoint' => 'My Door!'))));
    eq('mydoor', $s['login_slug']);
    eq(array('from' => 'My Door!', 'to' => 'mydoor'), get_site_option('authlify_slug_changed_on_upgrade'));

    $s = run_first_upgrade(array('modify_login_settings' => array_merge(fixture('modify-login-2x.json'), array('login_endpoint' => ''))));
    eq('setup', $s['login_slug'], '2.x default endpoint');

    $s = run_first_upgrade(array('modify_login_settings' => array_merge(fixture('modify-login-2x.json'), array('login_endpoint' => '')), 'modify_login_login_endpoint' => 'older-door'));
    eq('older-door', $s['login_slug'], 'the 2.0 standalone option');
});

t('2.x: the login log is copied (newest first, site time to UTC, success/failure events)', function () {
    global $wpdb;
    update_option('gmt_offset', 2);
    try {
        $rows = array(
            array('user_id' => 0, 'ip_address' => '203.0.113.5', 'user_agent' => 'UA-1', 'status' => 'failed', 'attempted_username' => 'legacy-guess', 'country' => 'Germany', 'created_at' => '2025-01-02 12:00:00'),
            array('user_id' => 1, 'ip_address' => '203.0.113.6', 'user_agent' => 'UA-2', 'status' => 'success', 'attempted_username' => 'legacy-admin', 'country' => '', 'created_at' => '2025-01-03 08:30:00'),
        );
        run_first_upgrade(array('modify_login_settings' => fixture('modify-login-2x.json')), $rows);
        $log = $wpdb->get_results($wpdb->prepare('SELECT event, username, ip, created_at, context FROM ' . $wpdb->base_prefix . 'authlify_log WHERE username LIKE %s ORDER BY created_at', 'legacy-%'), ARRAY_A);
        eq(2, count($log));
        eq('login_failed', $log[0]['event']);
        eq('2025-01-02 10:00:00', $log[0]['created_at'], 'site time (UTC+2) converted to UTC');
        eq('203.0.113.5', $log[0]['ip']);
        contains('Germany', (string) $log[0]['context']);
        eq('login_success', $log[1]['event']);
        ok($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->prefix . "modify_login_logs'"), 'the old table is kept');
    } finally {
        update_option('gmt_offset', 0);
    }
});

t('1.x: slug and redirect carry over, wp-login.php stays blocked, new protection off', function () {
    $fx = fixture('modify-login-1x.json');
    $s = run_first_upgrade($fx);
    eq('old-door', $s['login_slug']);
    eq(true, $s['block_wp_login']);
    eq('redirect', $s['blocked_response']);
    eq('https://example.com/nothing-here', $s['blocked_redirect_url']);
    eq(false, $s['limit_enabled']);
    eq(true, $s['onboarding_done']);
    eq('1.x', get_site_option('authlify_migrated_from'));
});

t('fresh install: nothing is hidden until the admin picks a URL', function () {
    $s = run_first_upgrade(array());
    eq('', Settings::get('login_slug'));
    eq(false, $s['onboarding_done']);
    ok((int) get_site_option('authlify_fresh_install') > 0);
    eq(false, get_site_option('authlify_migrated_from'));
    eq(true, Settings::get('limit_enabled'), 'protection is on for new sites');
});

t('existing 3.x settings are never overwritten by a legacy migration', function () {
    global $wpdb;
    Settings::update(array('login_slug' => 'already-three'));
    delete_site_option('authlify_version');
    delete_site_transient('authlify_upgrading');
    update_option('modify_login_settings', fixture('modify-login-2x.json'));
    Settings::flush();
    Upgrader::maybe_upgrade();
    Settings::flush();
    eq('already-three', Settings::get('login_slug'));
    eq(AUTHLIFY_VERSION, get_site_option('authlify_version'));
});

t('the upgrade runs once (version stored, concurrent runs locked out)', function () {
    $count = 0;
    add_test_filter('authlify_upgraded', function () use (&$count) {
        $count++;
    });
    delete_site_option('authlify_version');
    set_site_transient('authlify_upgrading', 1, 60);
    Upgrader::maybe_upgrade();
    eq(0, $count, 'another request is upgrading');
    delete_site_transient('authlify_upgrading');
    Upgrader::maybe_upgrade();
    Upgrader::maybe_upgrade();
    eq(1, $count);
});
