<?php
/**
 * Brute-force limits over HTTP: the login form, application passwords (REST),
 * XML-RPC, the denylist, and the emailed unlock link scoped to one account.
 * Each test uses its own TEST-NET address (harness header), never the suite's.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const LSLUG = 'limit-door';

function l_logged_in($res)
{
    foreach (live_cookies($res) as $name => $v) {
        if (0 === strpos($name, 'wordpress_logged_in_')) {
            return true;
        }
    }

    return false;
}

function basic($login, $pass)
{
    return 'Authorization: Basic ' . base64_encode($login . ':' . $pass);
}

before_all(function () {
    settings(array('login_slug' => LSLUG, 'block_wp_login' => true, 'limit_enabled' => true, 'limit_attempts' => 3, 'limit_window' => 15, 'lockout_minutes' => 15, 'lockout_escalate' => true, 'ip_allowlist' => '', 'ip_denylist' => '', 'captcha_provider' => 'none', 'honeypot' => false, 'xmlrpc' => 'on', 'app_passwords' => 'on', 'twofa_enabled' => true));
    $GLOBALS['alice'] = http_user('subscriber');
    $GLOBALS['bob'] = http_user('subscriber');
    $GLOBALS['ap'] = wp_json('$a = WP_Application_Passwords::create_new_application_password(' . $GLOBALS['alice']['id'] . ', array("name" => "limiter")); echo json_encode($a[0]);');
});

t('login form: N wrong passwords lock the address; the right password is then refused', function () {
    $a = $GLOBALS['alice'];
    $ip = '203.0.113.140';
    $res = login_post(login_url(LSLUG), $a['login'], 'wrong-1', array(), array('ip' => $ip));
    contains('attempts left', strip_tags($res->body), 'hint before the lockout');
    login_post(login_url(LSLUG), $a['login'], 'wrong-2', array(), array('ip' => $ip));
    login_post(login_url(LSLUG), $a['login'], 'wrong-3', array(), array('ip' => $ip));
    $res = login_post(login_url(LSLUG), $a['login'], $a['pass'], array(), array('ip' => $ip));
    no(l_logged_in($res));
    contains('Too many failed login attempts', $res->body);
    contains('action=authlify_unlock', $res->body, 'unlock-by-email link offered');
    not_contains('wp-login.php', $res->body, 'the unlock link uses the custom URL');

    $res = login_post(login_url(LSLUG), $a['login'], $a['pass'], array(), array('ip' => '203.0.113.141'));
    ok(l_logged_in($res), 'another address is not affected');
});

t('application passwords: wrong ones are counted and lock the address (REST)', function () {
    $a = $GLOBALS['alice'];
    $ip = '203.0.113.142';
    for ($i = 0; $i < 3; $i++) {
        $res = get(BASE . '/wp-json/wp/v2/users/me', array('ip' => $ip, 'headers' => array(basic($a['login'], 'abcd efgh ijkl mnop qrst uvw' . $i))));
        eq(401, $res->code, 'wrong app password ' . $i);
    }
    ok((int) wp_eval('echo \Authlify\Security\Limiter::locked_until("' . $ip . '");') > time(), 'address locked');
    $res = get(BASE . '/wp-json/wp/v2/users/me', array('ip' => $ip, 'headers' => array(basic($a['login'], $GLOBALS['ap']))));
    ok(in_array($res->code, array(401, 403), true), 'the right app password is refused while locked: ' . $res->code);
    not_contains('"id":' . $a['id'], $res->body, 'no user data');

    $res = get(BASE . '/wp-json/wp/v2/users/me', array('ip' => '203.0.113.143', 'headers' => array(basic($a['login'], $GLOBALS['ap']))));
    eq(200, $res->code, 'another address works');
    $GLOBALS['locked_ap_ip'] = $ip;
});

t('application passwords: a locked-out API client is told why (lockout message, plain text)', function () {
    $a = $GLOBALS['alice'];
    // Core only reports application-password errors when the current user is
    // determined before rest_authentication_errors runs; on some stacks it is
    // not, and core answers every failure with rest_not_logged_in.
    $probe = json_decode(get(BASE . '/wp-json/wp/v2/users/me', array('ip' => '203.0.113.149', 'headers' => array(basic($a['login'], 'zzzz zzzz zzzz zzzz zzzz zzzz'))))->body, true);
    wp_eval('\Authlify\Security\Limiter::unlock("203.0.113.149");');
    if (isset($probe['code']) && 'rest_not_logged_in' === $probe['code']) {
        skip('core does not surface application-password errors on this server (a plain wrong app password also gets rest_not_logged_in)');
    }
    $res = get(BASE . '/wp-json/wp/v2/users/me', array('ip' => $GLOBALS['locked_ap_ip'], 'headers' => array(basic($a['login'], $GLOBALS['ap']))));
    $json = json_decode($res->body, true);
    eq('authlify_locked', isset($json['code']) ? $json['code'] : '', 'error code (body: ' . substr($res->body, 0, 160) . ')');
    contains('Too many failed login attempts', $res->body);
    not_contains('<br', $res->body, 'plain message for API clients');
});

t('application password: one wrong request counts once', function () {
    $a = $GLOBALS['alice'];
    get(BASE . '/wp-json/wp/v2/users/me', array('ip' => '203.0.113.144', 'headers' => array(basic($a['login'], 'nope nope nope nope nope nope'))));
    eq('1', wp_eval('echo \Authlify\Security\Limiter::ip_failures("203.0.113.144");'));
});

t('XML-RPC: wrong passwords are counted; a locked address is refused', function () {
    $b = $GLOBALS['bob'];
    $ip = '203.0.113.145';
    $call = function ($pass) use ($b, $ip) {
        $xml = '<?xml version="1.0"?><methodCall><methodName>wp.getUsersBlogs</methodName><params><param><value><string>' . $b['login'] . '</string></value></param><param><value><string>' . $pass . '</string></value></param></params></methodCall>';

        return post(BASE . '/xmlrpc.php', $xml, array('ip' => $ip, 'headers' => array('Content-Type: text/xml')));
    };
    for ($i = 0; $i < 3; $i++) {
        $call('wrong' . $i);
    }
    ok((int) wp_eval('echo \Authlify\Security\Limiter::locked_until("' . $ip . '");') > time(), 'locked');
    $res = $call($b['pass']);
    contains('faultCode', $res->body);
    not_contains('blogName', $res->body, 'right password refused while locked');
});

t('denylist: refused with the right password; allowlist: never locked', function () {
    settings(array('ip_denylist' => '198.51.100.0/24', 'ip_allowlist' => '192.0.2.50'));
    $a = $GLOBALS['alice'];
    $res = login_post(login_url(LSLUG), $a['login'], $a['pass'], array(), array('ip' => '198.51.100.9'));
    no(l_logged_in($res));
    contains('blocked', strtolower(strip_tags($res->body)));
    $res = get(BASE . '/wp-json/wp/v2/users/me', array('ip' => '198.51.100.9', 'headers' => array(basic($a['login'], $GLOBALS['ap']))));
    ok(in_array($res->code, array(401, 403), true), 'app password from a denied network');

    for ($i = 0; $i < 6; $i++) {
        login_post(login_url(LSLUG), $a['login'], 'wrong' . $i, array(), array('ip' => '192.0.2.50'));
    }
    ok(l_logged_in(login_post(login_url(LSLUG), $a['login'], $a['pass'], array(), array('ip' => '192.0.2.50'))), 'allowlisted address never locked');
    settings(array('ip_denylist' => '', 'ip_allowlist' => ''));
});

t('unlock link: lets only that account in from the locked address, for one login (SEC2-04)', function () {
    $a = $GLOBALS['alice'];
    $b = $GLOBALS['bob'];
    $ip = '203.0.113.146';
    for ($i = 0; $i < 3; $i++) {
        login_post(login_url(LSLUG), $b['login'], 'wrong' . $i, array(), array('ip' => $ip));
    }
    $form = get(login_url(LSLUG) . '?action=authlify_unlock', array('ip' => $ip));
    ok((bool) preg_match('/name="_wpnonce" value="([^"]+)"/', $form->body, $n), 'unlock form');
    $before = mail_count();
    $res = post(login_url(LSLUG) . '?action=authlify_unlock', array('user_login' => $a['email'], '_wpnonce' => $n[1], '_wp_http_referer' => '/'), array('ip' => $ip));
    contains('If that account exists', strip_tags($res->body));
    $sent = mails($before);
    ok(count($sent) === 1, 'one mail');
    ok((bool) preg_match('#(http\S+action=authlify_unlock\S+)#', $sent[0]['message'], $m), 'unlock link');
    $link = $m[1];
    contains('/' . LSLUG . '/', $link);

    $res = get($link, array('ip' => $ip));
    contains('authlify_unlocked=1', $res->location);

    $res = login_post(login_url(LSLUG), $b['login'], $b['pass'], array(), array('ip' => $ip));
    no(l_logged_in($res), 'another account stays locked out');
    // The link gives the normal allowance (3 here), not unlimited guesses.
    login_post(login_url(LSLUG), $a['login'], 'wrong-a', array(), array('ip' => $ip));
    login_post(login_url(LSLUG), $a['login'], 'wrong-b', array(), array('ip' => $ip));
    $res = login_post(login_url(LSLUG), $a['login'], $a['pass'], array(), array('ip' => $ip));
    ok(l_logged_in($res), 'the unlocked account gets in');
    $res = login_post(login_url(LSLUG), $a['login'], $a['pass'], array(), array('ip' => $ip));
    no(l_logged_in($res), 'the pass ends with that login: the next one needs a new link');

    $res = get($link, array('ip' => '203.0.113.147'));
    contains('invalid or has expired', strip_tags($res->body), 'the link works once');
    ok((int) wp_eval('echo \Authlify\Security\Limiter::locked_until("' . $ip . '");') > time(), 'the lockout itself stays');

    // A new link, then the allowance used up by wrong passwords: locked again,
    // and the right password is refused (SEC2-04 PoC: 30 guesses then success).
    wp_eval('\Authlify\Security\Limiter::grant_pass("' . $ip . '", ' . $a['id'] . ');');
    for ($i = 0; $i < 3; $i++) {
        login_post(login_url(LSLUG), $a['login'], 'guess' . $i, array(), array('ip' => $ip));
    }
    $res = login_post(login_url(LSLUG), $a['login'], $a['pass'], array(), array('ip' => $ip));
    no(l_logged_in($res), 'after the allowance, even the right password is refused');
    contains('Too many failed login attempts', $res->body);
    wp_eval('delete_transient("authlify_unlock_pass_" . md5("' . $ip . '")); delete_transient("authlify_unlock_rate_" . md5("' . $ip . '"));');
});

t('unlock link requests: unknown accounts get the same answer and no mail; 3 per hour per address', function () {
    $ip = '203.0.113.148';
    $form = get(login_url(LSLUG) . '?action=authlify_unlock', array('ip' => $ip));
    preg_match('/name="_wpnonce" value="([^"]+)"/', $form->body, $n);
    $before = mail_count();
    $res = post(login_url(LSLUG) . '?action=authlify_unlock', array('user_login' => 'nobody-at-all', '_wpnonce' => $n[1]), array('ip' => $ip));
    contains('If that account exists', strip_tags($res->body));
    eq($before, mail_count(), 'no mail for an unknown account');
    for ($i = 0; $i < 3; $i++) {
        post(login_url(LSLUG) . '?action=authlify_unlock', array('user_login' => $GLOBALS['alice']['login'], '_wpnonce' => $n[1]), array('ip' => $ip));
    }
    ok(mail_count() - $before <= 2, 'rate limited to 3 requests an hour (1 was for an unknown account)');
    wp_eval('delete_transient("authlify_unlock_rate_" . md5("' . $ip . '"));');
});
