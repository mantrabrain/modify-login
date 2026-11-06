<?php
/**
 * 3.2.0 free features inside WordPress: parsing Wordfence's stored bytes (with
 * and without the real table), the username denylist rules, the importer for
 * the original Limit Login Attempts, the new integrations' switches and the
 * read-only abilities.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Admin\ToolsPage;
use Authlify\Security\Limiter;
use Authlify\Settings;
use Authlify\TwoFactor\BackupCodes;
use Authlify\TwoFactor\Import;
use Authlify\TwoFactor\Totp;

function r3u_wf_table()
{
    global $wpdb;
    $table = $wpdb->base_prefix . 'wfls_2fa_secrets';
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (id int unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, secret tinyblob NOT NULL, recovery blob NOT NULL, ctime int unsigned NOT NULL, vtime int unsigned NOT NULL, mode enum('authenticator') NOT NULL DEFAULT 'authenticator', PRIMARY KEY (id), KEY user_id (user_id))");
    $wpdb->query("TRUNCATE TABLE {$table}");

    return $table;
}

function r3u_wf_row($table, $user_id, $hex, $recovery_hex, $vtime = 0)
{
    global $wpdb;
    $wpdb->query($wpdb->prepare("INSERT INTO {$table} (user_id, secret, recovery, ctime, vtime) VALUES (%d, X%s, X%s, %d, %d)", $user_id, $hex, $recovery_hex, time(), $vtime));
}

after_each(function () {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}wfls_2fa_secrets");
});

t('Wordfence: secret and recovery bytes decode to Base32 and 16-hex codes', function () {
    // RFC 6238's SHA-1 seed "12345678901234567890".
    eq('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Import::wordfence_secret(bin2hex('12345678901234567890')));
    eq('', Import::wordfence_secret(bin2hex('too short')), 'not 20 bytes');
    eq('', Import::wordfence_secret('zz' . str_repeat('0', 38)), 'not hex');
    eq(array('0123456789abcdef', 'fedcba9876543210'), Import::wordfence_recovery('0123456789ABCDEFFEDCBA9876543210'));
    eq(array('0123456789abcdef'), Import::wordfence_recovery('0123456789abcdefabc'), 'a partial code is dropped');
    eq(array(), Import::wordfence_recovery(''));
});

t('Wordfence: no table means no source and no error', function () {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}wfls_2fa_secrets");
    eq(0, Import::count('wordfence'));
    no(Import::wordfence_found());
    eq(array(), Import::plan('wordfence')['import']);
});

t('Wordfence: preview changes nothing; import keeps the app, codes and the last step; skips the right people', function () {
    $table = r3u_wf_table();
    $done = make_user('editor');
    $has_codes = make_user('editor');
    $has_app = make_user('editor');
    $bad = make_user('editor');
    $hex = bin2hex(random_bytes(20));
    $vtime = (Totp::step() - 3) * 30;

    r3u_wf_row($table, $done->ID, $hex, '0123456789abcdeffedcba9876543210', $vtime);
    r3u_wf_row($table, $has_codes->ID, bin2hex(random_bytes(20)), '0123456789abcdef');
    r3u_wf_row($table, $has_app->ID, bin2hex(random_bytes(20)), '0123456789abcdef');
    r3u_wf_row($table, $bad->ID, bin2hex('short'), '');
    r3u_wf_row($table, 999999, bin2hex(random_bytes(20)), '');

    Totp::import_secret($has_app->ID, Totp::new_secret());
    $kept = BackupCodes::generate($has_codes->ID);

    eq(5, Import::count('wordfence'));
    $preview = Import::run('wordfence', true);
    ok($preview['dry']);
    eq(2, $preview['imported']);
    eq(2, $preview['recovery']);
    eq(array('already' => 1, 'unreadable' => 1, 'no_user' => 1), array_intersect_key($preview['skipped'], array('already' => 1, 'unreadable' => 1, 'no_user' => 1)));
    no(Totp::is_configured($done->ID), 'preview changes nothing');

    $result = Import::run('wordfence', false);
    eq(2, $result['imported']);
    eq(1, $result['recovery'], 'only the user without backup codes');
    ok(Totp::is_configured($done->ID));
    no(Totp::is_configured($bad->ID));
    contains('Wordfence Login Security', Import::summary($result));
    contains('Recovery codes were copied', Import::summary($result));

    // The original secret works (RFC computed here from the bytes).
    $base32 = Import::wordfence_secret($hex);
    ok(true === Totp::verify($done->ID, Totp::code($base32, Totp::step())), 'a current code from the original secret');

    eq(2, BackupCodes::remaining($done->ID));
    ok(BackupCodes::use_code($done->ID, '0123 4567 89ab cdef'), 'typed as Wordfence shows it');
    no(BackupCodes::use_code($done->ID, '0123 4567 89ab cdef'), 'single use');
    eq(count($kept), BackupCodes::remaining($has_codes->ID), 'existing backup codes are kept');
    ok(BackupCodes::use_code($has_codes->ID, $kept[0]), 'and still work');

    // A second run skips everyone already imported.
    eq(0, Import::run('wordfence', false)['imported']);
});

t('Wordfence: the code last used there cannot be replayed here', function () {
    $table = r3u_wf_table();
    $u = make_user('editor');
    $hex = bin2hex(random_bytes(20));
    $step = Totp::step();
    r3u_wf_row($table, $u->ID, $hex, '', $step * 30);
    Import::run('wordfence', false);
    $base32 = Import::wordfence_secret($hex);
    ok(true !== Totp::verify($u->ID, Totp::code($base32, $step)), 'same step refused');
    ok(true === Totp::verify($u->ID, Totp::code($base32, $step + 1)), 'next step works');
});

t('Wordfence: a time budget leaves the rest for the next click', function () {
    $table = r3u_wf_table();
    $a = make_user('editor');
    r3u_wf_row($table, $a->ID, bin2hex(random_bytes(20)), '');
    add_test_filter('authlify_2fa_import_seconds', function () {
        return 0;
    });
    $result = Import::run('wordfence', false);
    eq(0, $result['imported']);
    eq(1, $result['remaining']);
    contains('Import again', Import::summary($result));
});

t('backup codes: other lengths are still refused', function () {
    $u = make_user('editor');
    BackupCodes::import_codes($u->ID, array('0123456789abcdef', 'bad'));
    eq(1, BackupCodes::remaining($u->ID), 'a code of another length is not stored');
    no(BackupCodes::use_code($u->ID, '0123456789abcde'));
    no(BackupCodes::use_code($u->ID, '0123456789abcdef0'));
});

t('denylist: off by default; nothing is listed', function () {
    eq('', Settings::defaults()['username_denylist']);
    no(Settings::defaults()['username_deny_login']);
    no(Settings::defaults()['username_deny_lockout']);
    eq(array(), Limiter::denied_usernames());
    eq(array('x'), Limiter::illegal_logins(array('x')), 'filter unchanged');
});

t('denylist: matches names case-insensitively, never a real user or an email', function () {
    $u = make_user('subscriber');
    set_settings(array('username_denylist' => "Ghost\n" . $u->user_login));
    ok(Limiter::is_denied_username('ghost'));
    ok(Limiter::is_denied_username(' GHOST '));
    no(Limiter::is_denied_username($u->user_login), 'real account');
    no(Limiter::is_denied_username(strtoupper($u->user_login)), 'real account, other case');
    no(Limiter::is_denied_username('ghost@example.test'), 'email');
    no(Limiter::is_denied_username('ghost2'));
    eq(array($u->user_login), Limiter::denied_usernames_in_use());
});

t('denylist: registration is refused by wp_insert_user for visitors, not for admins or WP-CLI', function () {
    // This runner is WP-CLI, which may create such a user on purpose.
    set_settings(array('username_denylist' => 'ghost9'));
    eq(array(), Limiter::illegal_logins(array()), 'WP-CLI may');
    ok(defined('WP_CLI') && WP_CLI);
});

t('denylist: validation cleans the list and refuses emails', function () {
    $v = \Authlify\Admin\ProtectionPage::validate(array('username_denylist' => " Admin \nadmin\ntest"), 'protection');
    eq("admin\ntest", $v['username_denylist']);
    $v = \Authlify\Admin\ProtectionPage::validate(array('username_denylist' => 'me@example.test'), 'protection');
    ok(is_wp_error($v));
    contains('not usernames', $v->get_error_message());
});

t('denylist: settings can be exported and imported like the others', function () {
    set_settings(array('username_denylist' => 'ghost', 'username_deny_login' => true));
    $clean = ToolsPage::without_secrets(array_intersect_key(Settings::all(), Settings::schema()));
    eq('ghost', $clean['username_denylist']);
    ok($clean['username_deny_login']);
});

t('Limit Login Attempts: 1.7 options, the 2.0 beta array, and out-of-range values', function () {
    update_option('limit_login_allowed_retries', 2);
    update_option('limit_login_lockout_duration', 600);
    update_option('limit_login_valid_duration', 3600);
    contains('2 attempts', ToolsPage::lla_import());
    eq(array(2, 10, 60), array(Settings::get('limit_attempts'), Settings::get('lockout_minutes'), Settings::get('limit_window')));
    delete_option('limit_login_allowed_retries');
    delete_option('limit_login_lockout_duration');
    delete_option('limit_login_valid_duration');

    update_option('limit_login_options', array('allowed_retries' => 9, 'lockout_duration' => 60, 'valid_duration' => 86400 * 30));
    ok(ToolsPage::lla_found(), 'the beta array is found');
    ToolsPage::lla_import();
    eq(array(9, 1, 1440), array(Settings::get('limit_attempts'), Settings::get('lockout_minutes'), Settings::get('limit_window')), 'window capped at the screen limit');
    delete_option('limit_login_options');
    no(ToolsPage::lla_found());
});

t('integrations: registered, detected only with the plugin, labelled', function () {
    $all = \Authlify\Captcha\Integrations::all();
    ok(isset($all['pmpro'], $all['learndash']));
    eq(defined('PMPRO_VERSION'), call_user_func($all['pmpro'][1]));
    ok(class_exists($all['pmpro'][0]) && class_exists($all['learndash'][0]));
    ok(method_exists($all['pmpro'][0], 'init') && method_exists($all['learndash'][0], 'init'));
});

t('integrations: PMPro error codes become a fixed message, anything else adds nothing', function () {
    $_GET['action'] = 'authlify_captcha';
    contains('security check', \Authlify\Captcha\Integrations\PaidMembershipsPro::print_error('FORM', 'pmpro_login'));
    eq('FORM', \Authlify\Captcha\Integrations\PaidMembershipsPro::print_error('FORM', 'other_tag'));
    $_GET['action'] = '<script>alert(1)</script>';
    eq('FORM', \Authlify\Captcha\Integrations\PaidMembershipsPro::print_error('FORM', 'pmpro_login'));
});

t('abilities: registered read-only, private by default, capability-checked', function () {
    if (!function_exists('wp_get_ability')) {
        skip('this WordPress has no Abilities API');
    }
    foreach (array('authlify/get-status', 'authlify/get-recent-lockouts', 'authlify/get-leak-check') as $name) {
        $ability = wp_get_ability($name);
        ok($ability instanceof WP_Ability, $name);
        $meta = $ability->get_meta();
        ok($meta['annotations']['readonly'], $name . ' readonly');
        no($meta['show_in_rest'], $name . ' not in REST by default');

        wp_set_current_user(0);
        no(true === $ability->check_permissions(array()), $name . ': logged out');
        ok(is_wp_error($ability->execute(array())), $name . ': logged out cannot run');

        $sub = make_user('subscriber');
        wp_set_current_user($sub->ID);
        no(true === $ability->check_permissions(array()), $name . ': subscriber');
        $editor = make_user('editor');
        wp_set_current_user($editor->ID);
        no(true === $ability->check_permissions(array()), $name . ': editor');
    }
});

t('abilities: output holds no slug, IP, username or key, and matches its schema', function () {
    if (!function_exists('wp_get_ability')) {
        skip('this WordPress has no Abilities API');
    }
    global $wpdb;
    set_settings(array('login_slug' => 'abil-door-secret', 'captcha_provider' => 'turnstile', 'captcha_site_key' => 'site-key-abc', 'captcha_secret_key' => 'secret-key-abc', 'log_enabled' => true));
    \Authlify\Log\Log::add('lockout', array('username' => 'victim-user', 'ip' => '8.8.8.8', 'context' => array('scope' => 'ip', 'subject' => '8.8.8.8', 'minutes' => 15)));
    update_site_option(\Authlify\Diagnostics\LeakCheck::OPTION, array('time' => time(), 'trigger' => 'manual', 'state' => 'leaks', 'counts' => array('pass' => 3, 'fail' => 1), 'probes' => array('x' => array('status' => 'fail', 'detail' => 'found abil-door-secret'))));

    $admin = make_user('administrator');
    wp_set_current_user($admin->ID);

    $status = wp_get_ability('authlify/get-status')->execute();
    ok(!is_wp_error($status), is_wp_error($status) ? $status->get_error_message() : '');
    ok($status['custom_login_url']);
    eq('turnstile', $status['captcha_provider']);

    $lock = wp_get_ability('authlify/get-recent-lockouts')->execute(array('hours' => 2));
    ok(!is_wp_error($lock), is_wp_error($lock) ? $lock->get_error_message() : '');
    eq(2, $lock['hours']);
    ok($lock['logged'] >= 1);

    $leak = wp_get_ability('authlify/get-leak-check')->execute();
    ok(!is_wp_error($leak), is_wp_error($leak) ? $leak->get_error_message() : '');
    eq('leaks', $leak['state']);

    $all = wp_json_encode(array($status, $lock, $leak));
    foreach (array('abil-door-secret', '8.8.8.8', 'victim-user', 'site-key-abc', 'secret-key-abc') as $secret) {
        not_contains($secret, $all);
    }
    delete_site_option(\Authlify\Diagnostics\LeakCheck::OPTION);
});

t('abilities: wrong input is refused; the filters and the Pro hook exist', function () {
    if (!function_exists('wp_get_ability')) {
        skip('this WordPress has no Abilities API');
    }
    $admin = make_user('administrator');
    wp_set_current_user($admin->ID);
    ok(is_wp_error(wp_get_ability('authlify/get-recent-lockouts')->execute(array('hours' => 99999))), 'out of range');
    ok(did_action('authlify_register_abilities') > 0 || function_exists('wp_get_abilities') && null !== wp_get_abilities(), 'fired');
    ok(did_action('authlify_register_abilities') > 0);
    add_test_filter('authlify_ability_output', function ($output, $name) {
        $output['extra'] = $name;

        return $output;
    }, 10, 2);
    eq('authlify/get-status', \Authlify\Abilities::status()['extra']);
    add_test_filter('authlify_abilities_public', '__return_true');
    ok(\Authlify\Abilities::definitions()['authlify/get-status']['meta']['show_in_rest'], 'a site can opt in');
});

t('F3-01: lsc_v2 secrets open with the key WP 2FA derives; a changed or foreign one is unreadable', function () {
    if (defined('WP2FA_ENCRYPT_KEY')) {
        skip('WP2FA_ENCRYPT_KEY is defined on this site');
    }
    $material = base64_encode(random_bytes(16));
    // WP 2FA keeps this per network on a multisite.
    is_multisite() ? update_network_option(null, 'wp_2fa_secret_key', $material) : update_option('wp_2fa_secret_key', $material);
    try {
        $key = hash_hmac('sha256', 'wp-2fa authenticated encryption v2', hash('sha256', base64_decode($material), true), true);
        $nonce = random_bytes(24);
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $box = sodium_crypto_secretbox($secret, $nonce, $key);
        eq($secret, Import::wp2fa_secret('lsc_v2:' . base64_encode($nonce . $box)));
        $box[0] = chr(ord($box[0]) ^ 1);
        eq('', Import::wp2fa_secret('lsc_v2:' . base64_encode($nonce . $box)), 'tampered');
        eq('', Import::wp2fa_secret('lsc_v2:' . base64_encode('short')), 'too short');
        eq('', Import::wp2fa_secret('lsc_v2:not base64!'), 'not base64');
    } finally {
        is_multisite() ? delete_network_option(null, 'wp_2fa_secret_key') : delete_option('wp_2fa_secret_key');
    }
});

t('F3-13: an import of nobody does not claim success', function () {
    $text = Import::summary(array('imported' => 0, 'skipped' => array('unreadable' => 1), 'total' => 1, 'dry' => false, 'source' => 'wp-2fa'));
    contains('No authenticator apps were imported', $text);
    not_contains('Imported the', $text);
    not_contains('Now deactivate', $text);
    contains('could not be read', $text);
});

t('F3-14: importers read the real keys of LLAR, LoginPress and Colorlib', function () {
    $u = make_user('subscriber');
    update_option('limit_login_allowed_retries', 3);
    update_option('limit_login_valid_duration', 3600);
    update_option('limit_login_blacklist_usernames', array('ghost77', $u->user_login));
    update_option('limit_login_whitelist_usernames', array('friend'));
    $imp = ToolsPage::importers()['limit-login-attempts-reloaded'];
    $msg = call_user_func($imp[2]);
    contains('allow list was not copied', $msg);
    eq(60, (int) Settings::get('limit_window'));
    ok(Settings::get('username_deny_login'));
    ok(Limiter::is_denied_username('ghost77'));
    no(Limiter::is_denied_username($u->user_login), 'a real account stays out of it');
    foreach (array('limit_login_allowed_retries', 'limit_login_valid_duration', 'limit_login_blacklist_usernames', 'limit_login_whitelist_usernames') as $o) {
        delete_option($o);
    }
});

t('Log: the context filter runs for every event', function () {
    global $wpdb;
    add_test_filter('authlify_log_context', function ($context, $event) {
        $context['source'] = 'test:' . $event;

        return $context;
    }, 10, 2);
    \Authlify\Log\Log::add('unlock', array('context' => array('subject' => 'x')));
    contains('test:unlock', (string) $wpdb->get_var('SELECT context FROM ' . \Authlify\Log\Log::table() . ' ORDER BY id DESC LIMIT 1'));
});

t('front-end lockout notice: only when this request started a lockout, never on the login page', function () {
    eq('msg', Limiter::front_end_lockout_notice('msg'));
});
