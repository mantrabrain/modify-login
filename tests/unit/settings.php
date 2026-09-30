<?php
/**
 * Settings: schema sanitization, unknown keys, clean_slug, clean_url, AUTHLIFY_SLUG.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Settings;

t('bool: truthy and falsy strings, ints and junk', function () {
    $def = array('bool', false);
    foreach (array('1', 1, true, 'true', 'on', 'yes') as $v) {
        eq(true, Settings::sanitize_value($v, $def), 'value ' . var_export($v, true));
    }
    foreach (array('0', 0, false, 'false', 'off', 'no', '', 'banana', array()) as $v) {
        eq(false, Settings::sanitize_value($v, $def), 'value ' . var_export($v, true));
    }
});

t('int: casts, and negatives clamp to 0', function () {
    $def = array('int', 5);
    eq(12, Settings::sanitize_value('12', $def));
    eq(0, Settings::sanitize_value('-7', $def));
    eq(3, Settings::sanitize_value('3.9', $def));
    eq(0, Settings::sanitize_value('abc', $def));
});

t('slug: lowercased, stripped to [a-z0-9_-], slashes trimmed', function () {
    $def = array('slug', '');
    eq('secret-door', Settings::sanitize_value(' /Secret-Door/ ', $def));
    eq('abcscriptalert1script', Settings::sanitize_value('ab"c<script>alert(1)</script>', $def));
    eq('my_door', Settings::sanitize_value('my_door', $def));
    eq('ogin', Settings::sanitize_value('ł%ogin', $def));
});

t('url: esc_url_raw, javascript: refused', function () {
    $def = array('url', '');
    eq('https://example.com/x?a=1', Settings::sanitize_value(' https://example.com/x?a=1 ', $def));
    eq('', Settings::sanitize_value('javascript:alert(1)', $def));
});

t('text: tags stripped, newlines kept', function () {
    $out = Settings::sanitize_value("10.0.0.1\n<b>10.0.0.2</b>\n", array('text', ''));
    eq("10.0.0.1\n10.0.0.2", $out);
});

t('choice: only listed values, else the default', function () {
    $def = array('choice', '404', array('404', '403', 'redirect'));
    eq('403', Settings::sanitize_value('403', $def));
    eq('404', Settings::sanitize_value('500', $def));
    eq('404', Settings::sanitize_value(403, $def), 'strict comparison: int 403 is not "403"');
});

t('list: sanitize_key per item, empties dropped, reindexed', function () {
    $out = Settings::sanitize_value(array('Login', '', 'bad key!', 'woo_login'), array('list', array()));
    eq(array('login', 'badkey', 'woo_login'), $out);
    eq(array('single'), Settings::sanitize_value('single', array('list', array())));
});

t('map: nested arrays, keys sanitized, URLs kept with placeholders, text sanitized', function () {
    $out = Settings::sanitize_value(array(
        'Editor' => '/wp-admin/edit.php',
        'author' => 'https://example.com/u/{username}',
        'nested' => array('x' => '<i>t</i>', 'n' => 5, 'b' => true),
        'js' => 'javascript:alert(1)',
    ), array('map', array()));
    eq('/wp-admin/edit.php', $out['editor']);
    eq('https://example.com/u/{username}', $out['author']);
    eq(array('x' => 't', 'n' => 5, 'b' => true), $out['nested']);
    eq('javascript:alert(1)', $out['js'], 'plain text (not a URL) is only text-sanitized');
    eq(array(), Settings::sanitize_value('nope', array('map', array())));
});

t('string (default type): sanitize_text_field', function () {
    eq('abc', Settings::sanitize_value("<b>abc</b>\n", array('string', '')));
});

t('update(): every declared key sanitized by its type', function () {
    $saved = Settings::update(array(
        'limit_attempts' => '-3',
        'blocked_response' => 'teapot',
        'block_wp_login' => 'off',
        'login_slug' => 'My Door!',
        'captcha_forms' => array('LOGIN', '<x>'),
    ));
    eq(0, $saved['limit_attempts']);
    eq('404', $saved['blocked_response']);
    eq(false, $saved['block_wp_login']);
    eq('mydoor', $saved['login_slug']);
    eq(array('login', 'x'), $saved['captcha_forms']);
});

t('unknown keys: kept in storage (Pro keys while Pro is off) but inert', function () {
    global $wpdb;
    // Plant a key no module declares, as if Pro had saved it and was then deactivated.
    $stored = get_option(Settings::OPTION, array());
    $stored['pro_future_key'] = 'kept';
    update_option(Settings::OPTION, $stored);
    Settings::flush();

    Settings::update(array('limit_attempts' => 7, 'not_declared' => 'dropped'));

    $raw = get_option(Settings::OPTION);
    eq('kept', $raw['pro_future_key'], 'a stored unknown key survives a save');
    no(array_key_exists('not_declared', $raw), 'a new unknown key is never stored');
    eq(7, $raw['limit_attempts']);
    // Inert: not part of the sanitized schema output.
    no(array_key_exists('pro_future_key', Settings::sanitize($raw)), 'unknown keys are not in the schema');
});

t('update() fires authlify_settings_updated with new and old values', function () {
    $seen = null;
    add_test_filter('authlify_settings_updated', function ($new, $old) use (&$seen) {
        $seen = array($new['limit_attempts'], $old['limit_attempts']);
    }, 10, 2);
    Settings::update(array('limit_attempts' => 4));
    Settings::update(array('limit_attempts' => 9));
    eq(array(9, 4), $seen);
});

t('clean_slug(): trims slashes and whitespace, lowercases, strips everything else', function () {
    eq('door', Settings::clean_slug("\t/Door/\n"));
    eq('a-b_c9', Settings::clean_slug('A-B_C9'));
    eq('', Settings::clean_slug('../'));
    eq('etcpasswd', Settings::clean_slug('/etc/passwd'));
    eq('', Settings::clean_slug(null));
});

t('clean_url(): keeps {username} and {user_id} placeholders', function () {
    eq('https://example.com/members/{username}/?id={user_id}', Settings::clean_url('https://example.com/members/{username}/?id={user_id}'));
    eq('/account/{user_id}', Settings::clean_url('/account/{user_id}'));
    eq('', Settings::clean_url('javascript:alert("{username}")'));
    eq('https://example.com/other', Settings::clean_url('https://example.com/{other}'), 'other braces are stripped by esc_url_raw');
});

t('lines(): textarea to trimmed non-empty lines (newlines and commas)', function () {
    set_settings(array('ip_allowlist' => "10.0.0.1\n\n 10.0.0.2 ,10.0.0.3\r\n"));
    eq(array('10.0.0.1', '10.0.0.2', '10.0.0.3'), Settings::lines('ip_allowlist'));
});

t('get(): unknown key returns the fallback', function () {
    eq('fb', Settings::get('no_such_key', 'fb'));
    eq(true, Settings::get('block_wp_login'));
});

t('AUTHLIFY_SLUG: a valid value overrides the stored slug', function () {
    $out = subprocess('define("AUTHLIFY_SLUG", "/Forced-Door/"); \Authlify\Settings::flush(); echo \Authlify\Settings::get("login_slug");');
    eq('forced-door', trim($out));
});

t('AUTHLIFY_SLUG: reserved words are ignored', function () {
    set_settings(array('login_slug' => 'stored-door'));
    foreach (array('wp-admin', 'login', 'feed', 'wp-json') as $reserved) {
        $out = subprocess('define("AUTHLIFY_SLUG", "' . $reserved . '"); \Authlify\Settings::flush(); echo \Authlify\Settings::get("login_slug");');
        eq('stored-door', trim($out), 'AUTHLIFY_SLUG=' . $reserved);
    }
});

t('AUTHLIFY_SLUG: too short, too long or non-string values are ignored', function () {
    set_settings(array('login_slug' => 'stored-door'));
    foreach (array('"ab"', '"' . str_repeat('a', 65) . '"', 'true', '12345') as $value) {
        $out = subprocess('define("AUTHLIFY_SLUG", ' . $value . '); \Authlify\Settings::flush(); echo \Authlify\Settings::get("login_slug");');
        eq('stored-door', trim($out), 'AUTHLIFY_SLUG=' . $value);
    }
});

t('AUTHLIFY_DISABLE_HIDE wins over AUTHLIFY_SLUG and the stored slug', function () {
    set_settings(array('login_slug' => 'stored-door'));
    $out = subprocess('define("AUTHLIFY_SLUG", "forced-door"); define("AUTHLIFY_DISABLE_HIDE", true); \Authlify\Settings::flush(); echo "[" . \Authlify\Settings::get("login_slug") . "]";');
    eq('[]', trim($out));
});
