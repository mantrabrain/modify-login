<?php
/**
 * Audit fix phase (free): activity log scope and paging, deleted sites, the
 * breach-check cache, settings export/import safety, the designer keeping
 * add-on sections, upgrade state and defaults, accessible form rows.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Admin\ToolsPage;
use Authlify\Admin\UI;
use Authlify\Install\Upgrader;
use Authlify\Log\Log;
use Authlify\Security\Passwords;
use Authlify\Settings;

function fixes_log_rows($blog, $n, $name)
{
    global $wpdb;
    for ($i = 0; $i < $n; $i++) {
        $wpdb->insert(Log::table(), array('blog_id' => $blog, 'created_at' => gmdate('Y-m-d H:i:s'), 'event' => 'login_failed', 'username' => $name . $i, 'ip' => '192.0.2.9'));
    }
}

function fixes_log_clean($name)
{
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Log::table() . ' WHERE username LIKE %s', $wpdb->esc_like($name) . '%'));
}

// ---------------------------------------------------------------- Activity log.

t('DOC-10/ARCH-13: the log can be limited to one site (per-site activation on a network)', function () {
    fixes_log_rows(1, 3, 'scopeA');
    fixes_log_rows(77, 2, 'scopeA');
    try {
        eq(0, Log::scope_blog(), 'single site: no filter');
        eq(5, Log::query(array('search' => 'scopeA'))['total']);
        add_test_filter('authlify_log_scope_blog', function () {
            return 77;
        });
        eq(2, Log::query(array('search' => 'scopeA'))['total'], 'only this site\'s rows');
        eq(5, Log::query(array('search' => 'scopeA', 'blog_id' => 0))['total'], 'blog_id 0 = every site (privacy export)');
    } finally {
        fixes_log_clean('scopeA');
    }
});

t('DOC-10: "Clear the log" on one site deletes only that site\'s rows', function () {
    fixes_log_rows(1, 2, 'clearA');
    fixes_log_rows(78, 2, 'clearA');
    add_test_filter('authlify_log_scope_blog', function () {
        return 78;
    });
    try {
        Log::clear();
        eq(0, Log::query(array('search' => 'clearA'))['total'], 'site 78 emptied');
        eq(2, Log::query(array('search' => 'clearA', 'blog_id' => 1))['total'], 'site 1 kept');
    } finally {
        fixes_log_clean('clearA');
        global $wpdb;
        $wpdb->query('DELETE FROM ' . Log::table() . " WHERE event = 'log_cleared' AND blog_id = " . get_current_blog_id() . ' AND created_at >= "' . gmdate('Y-m-d H:i:s', time() - 60) . '"');
    }
});

t('LC-08: deleting a network site removes its log rows', function () {
    fixes_log_rows(79, 3, 'delsite');
    fixes_log_rows(1, 1, 'delsite');
    try {
        Log::on_delete_site((object) array('blog_id' => 79));
        eq(0, Log::query(array('search' => 'delsite', 'blog_id' => 79))['total']);
        eq(1, Log::query(array('search' => 'delsite', 'blog_id' => 1))['total'], 'other sites untouched');
        ok(false !== has_action('wp_delete_site', array(Log::class, 'on_delete_site')), 'hooked');
    } finally {
        fixes_log_clean('delsite');
    }
});

t('PERF-06/09: keyset paging returns every row once; count can be skipped', function () {
    fixes_log_rows(1, 25, 'keyset');
    try {
        $seen = array();
        $before = 0;
        do {
            $r = Log::query(array('search' => 'keyset', 'per_page' => 10, 'before_id' => $before, 'count' => false));
            eq(-1, $r['total'], 'no COUNT query');
            foreach ($r['rows'] as $row) {
                $seen[] = (int) $row->id;
                $before = (int) $row->id;
            }
        } while (count($r['rows']) === 10);
        eq(25, count($seen));
        eq(25, count(array_unique($seen)));
        eq(25, Log::query(array('search' => 'keyset', 'before_id' => max($seen) + 1))['total'], 'the total ignores the keyset cursor');
    } finally {
        fixes_log_clean('keyset');
    }
});

t('PERF-09: a full IP address searches the ip column exactly; partial addresses match from the start', function () {
    global $wpdb;
    $wpdb->insert(Log::table(), array('blog_id' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'event' => 'login_failed', 'username' => 'ipsearch1', 'ip' => '198.18.77.23'));
    $wpdb->insert(Log::table(), array('blog_id' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'event' => 'login_failed', 'username' => 'ipsearch2', 'ip' => '198.18.77.230'));
    try {
        eq(1, Log::query(array('search' => '198.18.77.23'))['total'], 'exact');
        eq(2, Log::query(array('search' => '198.18.77.'))['total'], 'prefix');
        eq(2, Log::query(array('search' => 'psearch'))['total'], 'usernames match anywhere');
    } finally {
        fixes_log_clean('ipsearch');
    }
});

// ---------------------------------------------------------------- Breach check.

t('PERF-07: breach results are cached as a few bytes in one non-autoloaded option, not ~70 KB ranges', function () {
    global $wpdb;
    delete_option(Passwords::CACHE_OPTION);
    $calls = 0;
    mock_http(function ($url) use (&$calls) {
        $calls++;
        $hash = strtoupper(sha1('password123'));
        $lines = array(substr($hash, 5) . ':12345');
        for ($i = 0; $i < 800; $i++) {
            $lines[] = strtoupper(substr(sha1('pad' . $i), 5)) . ':' . ($i + 1);
        }

        return http_response(200, implode("\r\n", $lines));
    });
    eq(12345, Passwords::breach_count('password123'));
    eq(12345, Passwords::breach_count('password123'), 'second check');
    eq(1, $calls, 'served from the cache');
    eq(0, Passwords::breach_count('not-in-the-range'));
    eq(2, $calls);
    $row = $wpdb->get_row($wpdb->prepare("SELECT autoload, LENGTH(option_value) AS len FROM {$wpdb->options} WHERE option_name = %s", Passwords::CACHE_OPTION));
    ok($row && in_array($row->autoload, array('no', 'off'), true), 'not autoloaded: ' . ($row ? $row->autoload : 'missing'));
    ok($row && (int) $row->len < 500, 'small: ' . ($row ? $row->len : 0) . ' bytes');
    not_contains(strtoupper(sha1('password123')), (string) wp_json_encode(get_option(Passwords::CACHE_OPTION)), 'no password hash stored');
    eq(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_authlify\\_hibp\\_%'"), 'no range transients');
    delete_option(Passwords::CACHE_OPTION);
});

t('PERF-07: the cache is capped', function () {
    delete_option(Passwords::CACHE_OPTION);
    mock_http(function () {
        return http_response(200, '');
    });
    for ($i = 0; $i < Passwords::CACHE_MAX + 20; $i++) {
        Passwords::breach_count('pw-' . $i);
    }
    eq(Passwords::CACHE_MAX, count(get_option(Passwords::CACHE_OPTION)));
    delete_option(Passwords::CACHE_OPTION);
});

// ---------------------------------------------------------------- Export and import.

t('A-29/ARCH-14: export drops secrets and keys no loaded module declares', function () {
    $clean = ToolsPage::without_secrets(array(
        'limit_attempts' => 5,
        'captcha_secret_key' => 'abc',
        'pro_social_oidc_client_secret' => 's1:xyz',
        'pro_social_apple_private_key' => '-----BEGIN',
        'pro_webhook_token' => 't',
        'some_value' => 'p1:plain-encrypted',
        'nested' => array('url' => 'https://example.com', 'api_key' => 'k'),
    ));
    eq(array('limit_attempts' => 5, 'nested' => array('url' => 'https://example.com')), $clean);
});

t('A-27: import runs the screens\' validators (a file that blocks your own address is refused)', function () {
    as_ip('203.0.113.77');
    // Registered on admin screens (the import runs in admin-post.php).
    add_test_filter('authlify_validate_settings', array(\Authlify\Admin\ProtectionPage::class, 'validate'), 10, 3);
    $m = new ReflectionMethod(ToolsPage::class, 'validate_import');
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); } // No-op since PHP 8.1, deprecated in 8.5.
    $out = $m->invoke(null, array('ip_denylist' => '203.0.113.0/24', 'limit_attempts' => 5));
    is_error_code('self_block', $out);
    $out = $m->invoke(null, array('limit_attempts' => 5000, 'hibp_enabled' => true));
    ok(is_array($out), 'valid values pass');
    eq(100, $out['limit_attempts'], 'bounds applied');
});

// ---------------------------------------------------------------- Designer.

t('ARCH-15: saving a design keeps sections an inactive add-on stored', function () {
    $design = \Authlify\Designer\Design::saved();
    $stored = $design;
    $stored['acme_addon'] = array('effect' => 'snow');
    update_option(\Authlify\Designer\Design::OPTION, $stored, false);
    $saved = \Authlify\Designer\Design::save($design);
    eq('snow', $saved['acme_addon']['effect'], 'returned');
    $raw = get_option(\Authlify\Designer\Design::OPTION);
    eq('snow', $raw['acme_addon']['effect'], 'stored');
});

// ---------------------------------------------------------------- Upgrades.

t('ARCH-11: sites upgraded from 2.x start with the new features off', function () {
    $v = Upgrader::map_2x(array('login_endpoint' => 'old-door'));
    no($v['twofa_enabled'], 'two-factor');
    no($v['passkey_login_button'], 'passkey button');
    no($v['leak_check_schedule'], 'automatic Leak Check');
    no($v['limit_enabled'], 'lockouts');
    ok(Settings::get('leak_check_schedule'), 'fresh installs: automatic Leak Check on by default');
});

t('LC-13: 1.x logout still lands on the homepage', function () {
    update_option('mb_login_endpoint', 'old-door');
    try {
        $m = new ReflectionMethod(Upgrader::class, 'map_1x');
        if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); } // No-op since PHP 8.1, deprecated in 8.5.
        $v = $m->invoke(null);
        eq(home_url('/'), $v['logout_redirect_url']);
        no($v['twofa_enabled']);
    } finally {
        delete_option('mb_login_endpoint');
    }
});

t('PERF-11/LC-01: upgrade state is autoloaded on a single site, per site when not network-active', function () {
    global $wpdb;
    Upgrader::set('authlify_version', AUTHLIFY_VERSION);
    $autoload = $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name = 'authlify_version'");
    ok(in_array($autoload, array('yes', 'on', 'auto-on'), true), 'authlify_version autoloaded: ' . $autoload);
    eq(AUTHLIFY_VERSION, Upgrader::get('authlify_version'));
    no(Upgrader::per_site(), 'single site');
});

// ---------------------------------------------------------------- Form rows.

t('A11Y-04: help text and notes are linked to their control', function () {
    ob_start();
    UI::form_start('protection', 'limits');
    UI::toggle_row('limit_enabled', 'Lock out', 'Why it matters.', 'Protection');
    UI::input_row('limit_attempts', 'Attempts', 'Per address.', array('type' => 'number', 'note' => 'A note.'));
    UI::textarea_row('ip_allowlist', 'Never lock out', 'Your office.', '');
    UI::choice_row('ip_source', 'Source', array('remote_addr' => 'Direct', 'cloudflare' => 'CF'), 'How visitors connect.', true);
    UI::choice_row('xmlrpc', 'XML-RPC', array('on' => 'On', 'off' => 'Off'), 'Old API.');
    $html = ob_get_clean();
    contains('id="authlify-limit_enabled-help"', $html);
    contains('aria-describedby="authlify-limit_enabled-help"', $html, 'toggle');
    contains('aria-describedby="authlify-limit_attempts-help authlify-limit_attempts-note"', $html, 'input + note');
    contains('id="authlify-limit_attempts-note"', $html);
    contains('aria-describedby="authlify-ip_allowlist-help"', $html, 'textarea');
    ok((bool) preg_match('/<fieldset class="authlify-radios" aria-describedby="(authlify-row-\d+-help)"/', $html, $m), 'radio group');
    contains('id="' . $m[1] . '"', $html);
    contains('<select id="authlify-xmlrpc" name="authlify[xmlrpc]" aria-describedby="authlify-xmlrpc-help"', $html, 'select');
});

t('A11Y-06: status icons carry their state in words', function () {
    $html = \Authlify\Admin\Dashboard::status_icon(false, 'Not set up:');
    contains('<span class="screen-reader-text">Not set up: </span>', $html);
    contains('aria-hidden="true"', $html, 'the icon itself stays hidden');
    not_contains('screen-reader-text', \Authlify\Admin\Dashboard::status_icon(true), 'decorative when no label');
});
