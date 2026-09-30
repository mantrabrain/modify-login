<?php
/**
 * Hardening and recovery over HTTP (audit fix phase): the private-site
 * exclusion list, the encoded system.multicall, lost-password enumeration, the
 * lockout message on front-end forms, the unlock-email quotas, the activity
 * CSV export and the owner-page guard of the settings saver.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const HSLUG = 'hard-door';

before_all(function () {
    $GLOBALS['admin'] = http_user('administrator');
    $GLOBALS['alice'] = http_user('subscriber');
    settings(array('login_slug' => HSLUG, 'block_wp_login' => true, 'blocked_response' => '404', 'limit_enabled' => true, 'limit_attempts' => 3, 'lockout_minutes' => 15, 'captcha_provider' => 'none', 'honeypot' => false, 'force_login' => false, 'generic_errors' => false, 'xmlrpc' => 'on', 'ip_allowlist' => '', 'ip_denylist' => ''));
});

t('A-04: private site: an excluded path never serves another post through query vars (GET or POST)', function () {
    $id = (int) wp_eval('echo wp_insert_post(array("post_title" => "Members only A04", "post_content" => "SECRET-A04", "post_status" => "publish"));');
    $page = (int) wp_eval('echo wp_insert_post(array("post_title" => "Open page A04", "post_name" => "open-a04", "post_content" => "PUBLIC-A04", "post_status" => "publish", "post_type" => "page"));');
    settings(array('force_login' => true, 'force_login_exclude' => "/\n/open-a04"));
    try {
        $res = post(BASE . '/', array('p' => $id));
        not_contains('SECRET-A04', $res->body, 'POST / with p=');
        eq(302, $res->code, 'POST / p= goes to the login page');
        $res = get(BASE . '/open-a04/?p=' . $id);
        not_contains('SECRET-A04', $res->body, '/open-a04/?p=');
        $res = get(BASE . '/?pagename=open-a04&p=' . $id);
        not_contains('SECRET-A04', $res->body);
        $res = get(BASE . '/open-a04/?utm_source=news');
        eq(200, $res->code, 'the excluded page itself (with tracking parameters) stays public');
        contains('PUBLIC-A04', $res->body);
        eq(200, get(BASE . '/')->code, 'the excluded home page stays public');
        eq(302, get(BASE . '/?s=secret')->code, 'search on the home page is not the home page');
    } finally {
        settings(array('force_login' => false, 'force_login_exclude' => ''));
        wp_eval('wp_delete_post(' . $id . ', true); wp_delete_post(' . $page . ', true);');
    }
});

t('A-14: "block multicall" also refuses an entity-encoded system.multicall', function () {
    settings(array('xmlrpc' => 'no_multicall'));
    try {
        $call = '<?xml version="1.0"?><methodCall><methodName>%s</methodName><params><param><value><array><data><value><struct><member><name>methodName</name><value><string>wp.getUsersBlogs</string></value></member><member><name>params</name><value><array><data><value><string>x</string></value><value><string>y</string></value></data></array></value></member></struct></value></data></array></value></param></params></methodCall>';
        foreach (array('system&#46;multicall', 'system&#x2e;multicall', '<![CDATA[system.multicall]]>', 'system.<!-- x -->multicall', ' System.MultiCall ') as $name) {
            $res = post(BASE . '/xmlrpc.php', sprintf($call, $name), array('ip' => '203.0.113.160', 'headers' => array('Content-Type: text/xml')));
            eq(403, $res->code, 'refused: ' . $name);
            contains('multicall is disabled', $res->body);
        }
        $res = post(BASE . '/xmlrpc.php', '<?xml version="1.0"?><methodCall><methodName>demo.sayHello</methodName></methodCall>', array('headers' => array('Content-Type: text/xml')));
        contains('Hello!', $res->body, 'other methods still work');
    } finally {
        settings(array('xmlrpc' => 'on'));
        wp_eval('\Authlify\Security\Limiter::unlock("203.0.113.160");');
    }
});

t('A-17: with generic errors on, lost password answers the same for unknown and real accounts', function () {
    settings(array('generic_errors' => true));
    try {
        $unknown = post(login_url(HSLUG) . '?action=lostpassword', array('user_login' => 'nobody_zz_' . mt_rand(1000, 9999)), array('ip' => '203.0.113.161'));
        $real = post(login_url(HSLUG) . '?action=lostpassword', array('user_login' => $GLOBALS['alice']['login']), array('ip' => '203.0.113.161'));
        eq(302, $unknown->code, 'unknown account redirects');
        eq($real->location, $unknown->location, 'same place as a real account');
        contains('checkemail=confirm', $unknown->location);
        not_contains('wp-login.php', $unknown->location, 'stays on the custom login URL');
        not_contains('wp-login.php', $real->location, 'a real account too (core redirects to a relative wp-login.php)');
        $empty = post(login_url(HSLUG) . '?action=lostpassword', array('user_login' => ''), array('ip' => '203.0.113.161'));
        eq(200, $empty->code, 'an empty field still shows its error');
    } finally {
        settings(array('generic_errors' => false));
    }
});

t('A-05: the unlock link (with the login URL) is on the login page, never in messages shown elsewhere', function () {
    $out = wp_eval('echo \Authlify\Security\Limiter::lockout_message(time() + 900);');
    contains('Too many failed login attempts', $out);
    not_contains(HSLUG, $out, 'no slug in the message outside the login page (front-end forms print it)');
    $ip = '203.0.113.162';
    for ($i = 0; $i < 3; $i++) {
        login_post(login_url(HSLUG), $GLOBALS['alice']['login'], 'wrong' . $i, array(), array('ip' => $ip));
    }
    $res = login_post(login_url(HSLUG), $GLOBALS['alice']['login'], 'wrong', array(), array('ip' => $ip));
    contains('action=authlify_unlock', $res->body, 'the login page still offers the unlock email');
    wp_eval('\Authlify\Security\Limiter::unlock("' . $ip . '");');
});

t('A-12: unlock emails: none while nothing is locked; 3 per account and address; a new request keeps the older link valid', function () {
    $a = $GLOBALS['alice'];
    $ip = '203.0.113.163';
    $form = get(login_url(HSLUG) . '?action=authlify_unlock', array('ip' => $ip));
    preg_match('/name="_wpnonce" value="([^"]+)"/', $form->body, $n);
    $send = function () use ($a, $ip, $n) {
        return post(login_url(HSLUG) . '?action=authlify_unlock', array('user_login' => $a['login'], '_wpnonce' => $n[1]), array('ip' => $ip));
    };

    $before = mail_count();
    $send();
    eq($before, mail_count(), 'address not locked: no email, nothing counted');

    for ($i = 0; $i < 3; $i++) {
        login_post(login_url(HSLUG), 'someone-else', 'wrong' . $i, array(), array('ip' => $ip));
    }
    $send();
    $send();
    $sent = mails($before);
    eq(2, count($sent), 'two requests, two emails');
    preg_match('#(http\S+action=authlify_unlock\S+)#', $sent[0]['message'], $first);
    $send();
    $send();
    eq(3, count(mails($before)), 'at most 3 per account and address an hour');

    $res = get($first[1], array('ip' => $ip));
    contains('authlify_unlocked=1', $res->location, 'the first link still works after newer ones were sent');
    wp_eval('\Authlify\Security\Limiter::unlock("' . $ip . '"); global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%authlify\\\\_unlock%\'"); wp_cache_flush();');
});

t('PERF-06: the activity CSV export streams every row with keyset paging (no 100k cap)', function () {
    $a = $GLOBALS['admin'];
    wp_eval('global $wpdb; $t = \Authlify\Log\Log::table(); for ($i = 0; $i < 2105; $i++) { $wpdb->insert($t, array("blog_id" => get_current_blog_id(), "created_at" => gmdate("Y-m-d H:i:s"), "event" => "login_failed", "username" => "csvrow" . $i, "ip" => "192.0.2.1")); }');
    try {
        $s = session_for($a['id'], array('authlify_export_log'));
        $res = get(BASE . '/wp-admin/admin-post.php?action=authlify_export_log&s=csvrow&_wpnonce=' . $s['nonces']['authlify_export_log'], array('cookies' => $s['cookies']));
        eq(200, $res->code);
        $lines = array_values(array_filter(explode("\n", $res->body)));
        eq(2106, count($lines), 'header + every matching row across three batches');
        eq(2105, count(array_unique($lines)) - 1, 'no row twice');
    } finally {
        wp_eval('global $wpdb; $wpdb->query("DELETE FROM " . \Authlify\Log\Log::table() . " WHERE username LIKE \'csvrow%\'");');
    }
});

t('ARCH-22: a form of another screen cannot set the login URL, pending slug or delete-on-uninstall', function () {
    $a = $GLOBALS['admin'];
    $s = session_for($a['id'], array('authlify_save_activity'));
    $res = post(BASE . '/wp-admin/admin-post.php', array(
        'action' => 'authlify_save', 'authlify_page' => 'activity', 'authlify_tab' => 'settings',
        'authlify_fields' => 'log_retention_days,login_slug,pending_slug,delete_data',
        'authlify' => array('log_retention_days' => '45', 'login_slug' => 'sneaky-door', 'pending_slug' => 'sneaky2', 'delete_data' => '1'),
        '_authlify_nonce' => $s['nonces']['authlify_save_activity'],
    ), array('cookies' => $s['cookies'], 'headers' => array('Referer: ' . BASE . '/wp-admin/admin.php?page=modify-login-logs&tab=settings')));
    eq(302, $res->code);
    $now = wp_json('echo json_encode(get_option("authlify_settings"));');
    eq(45, (int) $now['log_retention_days'], 'the screen\'s own key is saved');
    eq(HSLUG, $now['login_slug'], 'login URL untouched');
    eq('', $now['pending_slug'], 'pending slug untouched');
    no(!empty($now['delete_data']), 'delete-on-uninstall untouched');
    settings(array('log_retention_days' => 90));
});

t('lost password on the custom URL lands on its "check your email" screen, not a 404', function () {
    $res = post(login_url(HSLUG) . '?action=lostpassword', array('user_login' => $GLOBALS['alice']['login']), array('ip' => '203.0.113.164'));
    eq(302, $res->code);
    eq(login_url(HSLUG) . '?checkemail=confirm', $res->location, 'core\'s relative wp-login.php redirect is rewritten');
    $page = get($res->location);
    eq(200, $page->code);
    contains('Check your email', $page->body);
    eq(404, get(BASE . '/' . HSLUG . '/wp-login.php?checkemail=confirm')->code, 'the old broken target');
    eq(404, get(BASE . '/blah/wp-login.php')->code, 'T-1 stays fixed: other /x/wp-login.php paths are not rewritten');
});

t('LC-04: on sites upgraded from 1.x, the saved wp-login.php?{slug} link goes to the login page', function () {
    $before = wp_eval('echo (string) get_option("authlify_migrated_from");');
    eq(404, get(BASE . '/wp-login.php?' . HSLUG)->code, 'not a 1.x site: hidden as usual');
    wp_eval('update_option("authlify_migrated_from", "1.x");');
    try {
        $res = get(BASE . '/wp-login.php?' . HSLUG . '&redirect=false');
        eq(302, $res->code);
        eq(login_url(HSLUG), $res->location);
        eq(404, get(BASE . '/wp-login.php?other-door')->code, 'any other wp-login.php request stays hidden');
    } finally {
        wp_eval('' === $before ? 'delete_option("authlify_migrated_from");' : 'update_option("authlify_migrated_from", "' . $before . '");');
    }
});
