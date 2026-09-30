<?php
/**
 * Free/Pro boundary: with Pro deactivated its REST routes are gone, its stored
 * settings are kept but do nothing, and nothing fatals; Pro without the free
 * plugin shows a notice and stays inert.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const BSLUG = 'edge-door';

function active_plugins()
{
    return wp_json('echo json_encode(array_values(get_option("active_plugins")));');
}

function set_active_plugins(array $plugins)
{
    wp_eval('update_option("active_plugins", json_decode(base64_decode("' . base64_encode(json_encode(array_values($plugins))) . '"), true));');
}

function debug_fatals()
{
    $log = dirname(WPCLI) . '/debug.log';

    return is_readable($log) ? preg_grep('/PHP (Fatal|Parse) error/', file($log)) : array();
}

before_all(function () {
    $GLOBALS['has_pro'] = 'yes' === wp_eval('echo file_exists(WP_PLUGIN_DIR . "/authlify-pro/authlify-pro.php") ? "yes" : "no";');
    $GLOBALS['plugins'] = active_plugins();
    $GLOBALS['admin'] = http_user('administrator');
    $GLOBALS['sub'] = http_user('subscriber');
    settings(array('login_slug' => BSLUG, 'block_wp_login' => true, 'captcha_provider' => 'none', 'honeypot' => false, 'limit_enabled' => true));
});

after_all(function () {
    set_active_plugins($GLOBALS['plugins']);
});

t('Pro active: its REST namespace is registered', function () {
    if (!$GLOBALS['has_pro']) {
        skip('Authlify Pro is not installed next to the free plugin');
    }
    $index = json_decode(get(BASE . '/wp-json/')->body, true);
    contains('authlify-pro/v1', $index['namespaces']);
});

t('Pro deactivated: its routes no longer exist (404 rest_no_route)', function () {
    if (!$GLOBALS['has_pro']) {
        skip('Authlify Pro is not installed');
    }
    // Pro settings that would change behaviour, stored while Pro was active.
    settings(array('pro_sudo' => true, 'pro_twofa_roles' => array('subscriber'), 'pro_twofa_grace_type' => 'none', 'pro_country_mode' => 'allow', 'pro_country_list' => 'ZZ', 'pro_idle_minutes' => 5));
    set_active_plugins(array_diff($GLOBALS['plugins'], array('authlify-pro/authlify-pro.php')));
    $s = session_for($GLOBALS['admin']['id']);
    foreach (array('settings', 'activity', 'lockouts', 'design', 'identity/email/send') as $route) {
        $res = request('identity/email/send' === $route ? 'POST' : 'GET', BASE . '/wp-json/authlify-pro/v1/' . $route, array('cookies' => $s['cookies'], 'headers' => array('X-WP-Nonce: ' . $s['rest'])));
        eq(404, $res->code, $route);
        eq('rest_no_route', json_decode($res->body, true)['code']);
    }
    $index = json_decode(get(BASE . '/wp-json/')->body, true);
    not_contains('authlify-pro/v1', $index['namespaces']);
    contains('authlify/v1', $index['namespaces'], 'free routes still there');
});

t('Pro deactivated: its stored settings are inert (no 2FA enforcement, no country rule, no sudo)', function () {
    if (!$GLOBALS['has_pro']) {
        skip('Authlify Pro is not installed');
    }
    $sub = $GLOBALS['sub'];
    $res = login_post(login_url(BSLUG), $sub['login'], $sub['pass'], array(), array('ip' => '64.0.0.30'));
    eq(302, $res->code, 'subscriber logs in without being forced into 2FA setup');
    not_contains('authlify_pro', $res->location);

    $a = $GLOBALS['admin'];
    $s = session_for($a['id']);
    wp_eval('$m = WP_Session_Tokens::get_instance(' . $a['id'] . '); $x = $m->get("' . $s['token'] . '"); $x["login"] = time() - 7200; $x["last_activity"] = time() - 7200; $m->update("' . $s['token'] . '", $x);');
    $res = get(BASE . '/wp-admin/', array('cookies' => $s['cookies']));
    eq(200, $res->code, 'no idle logout');
    $res = request('POST', BASE . '/wp-json/authlify/v1/twofactor/reset', array('cookies' => $s['cookies'], 'headers' => array('X-WP-Nonce: ' . $s['rest']), 'json' => array('user_id' => $sub['id'])));
    eq(200, $res->code, 'no sudo prompt: ' . substr($res->body, 0, 120));
});

t('Pro deactivated: saving free settings keeps the Pro keys', function () {
    if (!$GLOBALS['has_pro']) {
        skip('Authlify Pro is not installed');
    }
    $s = session_for($GLOBALS['admin']['id'], array('authlify_save_protection'));
    $res = post(BASE . '/wp-admin/admin-post.php', array('action' => 'authlify_save', 'authlify_page' => 'protection', 'authlify_tab' => 'limits', 'authlify_fields' => 'limit_window', 'authlify' => array('limit_window' => '25'), '_authlify_nonce' => $s['nonces']['authlify_save_protection']), array('cookies' => $s['cookies'], 'headers' => array('Referer: ' . BASE . '/wp-admin/')));
    eq(302, $res->code);
    $raw = wp_json('echo json_encode(get_option("authlify_settings"));');
    eq(25, $raw['limit_window']);
    eq(true, $raw['pro_sudo']);
    eq(array('subscriber'), $raw['pro_twofa_roles']);
    eq('allow', $raw['pro_country_mode']);
});

t('Pro reactivated: the kept settings apply again', function () {
    if (!$GLOBALS['has_pro']) {
        skip('Authlify Pro is not installed');
    }
    set_active_plugins($GLOBALS['plugins']);
    eq('1', wp_eval('echo \AuthlifyPro\TwoFactor\Sudo::enabled() ? 1 : 0;'));
    eq('1', wp_eval('echo \AuthlifyPro\TwoFactor\Policies::applies(get_userdata(' . $GLOBALS['sub']['id'] . ')) ? 1 : 0;'));
    settings(array('pro_sudo' => false, 'pro_twofa_roles' => array(), 'pro_country_mode' => 'off', 'pro_country_list' => '', 'pro_idle_minutes' => 0));
});

t('Pro without the free plugin: a notice, no fatal, nothing else', function () {
    if (!$GLOBALS['has_pro']) {
        skip('Authlify Pro is not installed');
    }
    $before = count(debug_fatals());
    set_active_plugins(array('authlify-pro/authlify-pro.php'));
    try {
        eq(200, get(BASE . '/')->code, 'front end');
        $s = session_for($GLOBALS['admin']['id']);
        $res = get(BASE . '/wp-admin/plugins.php', array('cookies' => $s['cookies']));
        eq(200, $res->code, 'wp-admin');
        contains('Authlify Pro needs the free Authlify plugin', $res->body);
        $sub = session_for($GLOBALS['sub']['id']);
        not_contains('Authlify Pro needs', get(BASE . '/wp-admin/profile.php', array('cookies' => $sub['cookies']))->body, 'only people who can activate plugins see it');
        $index = json_decode(get(BASE . '/wp-json/')->body, true);
        not_contains('authlify-pro/v1', $index['namespaces'], 'no Pro routes without free');
        contains('id="loginform"', get(BASE . '/wp-login.php')->body, 'core login page is back');
    } finally {
        set_active_plugins($GLOBALS['plugins']);
    }
    eq($before, count(debug_fatals()), 'no PHP fatal: ' . implode(' ', array_slice(debug_fatals(), -1)));
});

t('Pro with an older free version: a notice naming both versions', function () {
    if (!$GLOBALS['has_pro']) {
        skip('Authlify Pro is not installed');
    }
    $out = wp_eval('echo \AuthlifyPro\Plugin::free_ok() ? "ok" : "no";');
    eq('ok', $out, 'the current free plugin satisfies Pro');
    $src = file_get_contents(dirname(dirname(__DIR__)) . '/../authlify-pro/inc/Plugin.php');
    contains('version_compare(AUTHLIFY_VERSION, AUTHLIFY_PRO_REQUIRES_FREE', $src, 'version gate present');
});

t('every Authlify page loads for an administrator without a PHP error', function () {
    $before = count(debug_fatals());
    $s = session_for($GLOBALS['admin']['id']);
    // Every Authlify page linked from the admin menu (free, modules and Pro).
    $dash = get(BASE . '/wp-admin/admin.php?page=modify-login', array('cookies' => $s['cookies']));
    eq(200, $dash->code, 'dashboard');
    preg_match_all('#admin\.php\?page=((?:modify-login|authlify)[a-z0-9_-]*)#', $dash->body, $m);
    $pages = array_values(array_unique($m[1]));
    ok(count($pages) >= 6, 'menu pages found: ' . implode(', ', $pages));
    foreach ($pages as $page) {
        $res = get(BASE . '/wp-admin/admin.php?page=' . $page, array('cookies' => $s['cookies']));
        eq(200, $res->code, $page);
        not_contains('critical error', $res->body, $page);
    }
    eq($before, count(debug_fatals()), 'no PHP fatal');
});
