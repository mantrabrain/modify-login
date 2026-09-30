<?php
/**
 * CAPTCHA over HTTP: the Basic-auth and forged-WooCommerce-field bypasses stay
 * closed, a good token logs in, CAPTCHA failures never lock anyone out,
 * after-failures mode, and the honeypot's time trap and replay protection on
 * the real login form. Turnstile's siteverify is answered by the test harness
 * (token "good" passes).
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const CSLUG = 'captcha-door';

function captcha_login($user, array $extra = array(), array $o = array())
{
    return login_post(login_url(CSLUG), $user['login'], $user['pass'], $extra, $o);
}

function logged_in($res)
{
    foreach (live_cookies($res) as $name => $v) {
        if (0 === strpos($name, 'wordpress_logged_in_')) {
            return true;
        }
    }

    return false;
}

before_all(function () {
    $GLOBALS['u'] = http_user('subscriber');
    wp_eval('update_option("authlify_test_http", array(array("match" => "challenges.cloudflare.com", "siteverify" => true)));');
    settings(array(
        'login_slug' => CSLUG, 'block_wp_login' => true, 'limit_enabled' => true, 'limit_attempts' => 5,
        'captcha_provider' => 'turnstile', 'captcha_site_key' => 'test-site-key', 'captcha_secret_key' => 'test-secret',
        'captcha_forms' => array('login', 'register', 'lostpassword'), 'captcha_mode' => 'always', 'captcha_test_mode' => false,
        'captcha_fail' => 'open', 'honeypot' => false, 'ip_allowlist' => '',
    ));
});

t('the widget is on the login form', function () {
    $res = get(login_url(CSLUG));
    contains('authlify-captcha--turnstile', $res->body);
    contains('data-sitekey="test-site-key"', $res->body);
});

t('right password without a CAPTCHA token: refused', function () {
    $res = captcha_login($GLOBALS['u'], array(), array('ip' => '203.0.113.101'));
    no(logged_in($res), 'no auth cookie');
    contains('security check', strtolower(strip_tags($res->body)));
});

t('Basic-auth bypass stays closed: an Authorization header does not skip the CAPTCHA', function () {
    $u = $GLOBALS['u'];
    $res = captcha_login($u, array(), array('ip' => '203.0.113.102', 'headers' => array('Authorization: Basic ' . base64_encode($u['login'] . ':' . $u['pass']))));
    no(logged_in($res), 'Basic header with the right password');
    $res = captcha_login($u, array(), array('ip' => '203.0.113.102', 'headers' => array('Authorization: Basic ' . base64_encode('someone:else'))));
    no(logged_in($res), 'Basic header with other credentials');
});

t('forged WooCommerce fields on the core form do not skip the CAPTCHA', function () {
    $u = $GLOBALS['u'];
    $forged = array('woocommerce-login-nonce' => 'abcdef1234', 'username' => $u['login'], 'password' => $u['pass'], 'login' => 'Log in', '_wp_http_referer' => '/my-account/');
    $res = captcha_login($u, $forged, array('ip' => '203.0.113.103'));
    no(logged_in($res));
    // Also with a genuine-looking but wrong nonce posted to the home page (Woo's handler path).
    $res = post(BASE . '/', array_merge($forged, array('log' => $u['login'], 'pwd' => $u['pass'])), array('ip' => '203.0.113.103'));
    no(logged_in($res), 'front-end post');
});

t('a verified token logs in', function () {
    $res = captcha_login($GLOBALS['u'], array('cf-turnstile-response' => 'good'), array('ip' => '203.0.113.104'));
    eq(302, $res->code);
    ok(logged_in($res), 'auth cookie set');
});

t('a token the provider rejects: refused', function () {
    $res = captcha_login($GLOBALS['u'], array('cf-turnstile-response' => 'forged'), array('ip' => '203.0.113.105'));
    no(logged_in($res));
});

t('CAPTCHA failures are not counted towards the lockout', function () {
    $u = $GLOBALS['u'];
    for ($i = 0; $i < 7; $i++) {
        captcha_login($u, array(), array('ip' => '203.0.113.106'));
    }
    eq('0', wp_eval('echo \Authlify\Security\Limiter::ip_failures("203.0.113.106");'));
    $res = captcha_login($u, array('cf-turnstile-response' => 'good'), array('ip' => '203.0.113.106'));
    ok(logged_in($res), 'the person can still log in after solving it');
});

t('wrong passwords with a good token are counted and lock out as usual', function () {
    $u = $GLOBALS['u'];
    for ($i = 0; $i < 5; $i++) {
        login_post(login_url(CSLUG), $u['login'], 'wrong-password', array('cf-turnstile-response' => 'good'), array('ip' => '203.0.113.107'));
    }
    ok((int) wp_eval('echo \Authlify\Security\Limiter::locked_until("203.0.113.107");') > time(), 'locked');
    $res = captcha_login($u, array('cf-turnstile-response' => 'good'), array('ip' => '203.0.113.107'));
    no(logged_in($res), 'right password refused while locked');
    contains('Too many failed login attempts', $res->body);
});

t('after_failures mode: no widget at first, required after N failures from the address', function () {
    settings(array('captcha_mode' => 'after_failures', 'captcha_after' => 2));
    $u = $GLOBALS['u'];
    $ip = '203.0.113.108';
    not_contains('authlify-captcha--turnstile', get(login_url(CSLUG), array('ip' => $ip))->body, 'fresh visitor sees no widget');
    $res = captcha_login($u, array(), array('ip' => $ip));
    ok(logged_in($res), 'honest login without a CAPTCHA');

    $ip = '203.0.113.109';
    login_post(login_url(CSLUG), $u['login'], 'wrong-1', array(), array('ip' => $ip));
    login_post(login_url(CSLUG), $u['login'], 'wrong-2', array(), array('ip' => $ip));
    contains('authlify-captcha--turnstile', get(login_url(CSLUG), array('ip' => $ip))->body, 'widget after 2 failures');
    $res = captcha_login($u, array(), array('ip' => $ip));
    no(logged_in($res), 'leaving the widget out does not help');
    $res = captcha_login($u, array('cf-turnstile-response' => 'good'), array('ip' => $ip));
    ok(logged_in($res), 'with the token');
    settings(array('captcha_mode' => 'always'));
});

t('test mode: logs but never blocks', function () {
    settings(array('captcha_test_mode' => true));
    $res = captcha_login($GLOBALS['u'], array(), array('ip' => '203.0.113.110'));
    ok(logged_in($res));
    settings(array('captcha_test_mode' => false));
});

t('application-password REST requests are not asked for a CAPTCHA (brute-force limits cover them)', function () {
    $u = $GLOBALS['u'];
    $ap = wp_json('$a = WP_Application_Passwords::create_new_application_password(' . $u['id'] . ', array("name" => "cap")); echo json_encode($a[0]);');
    $res = get(BASE . '/wp-json/wp/v2/users/me', array('ip' => '203.0.113.111', 'headers' => array('Authorization: Basic ' . base64_encode($u['login'] . ':' . $ap))));
    eq(200, $res->code);
});

t('honeypot: instant submit refused, a human pace passes, a cached stamp works again but not endlessly, the trap field refuses', function () {
    settings(array('captcha_provider' => 'none', 'honeypot' => true));
    $u = $GLOBALS['u'];
    $form = get(login_url(CSLUG), array('ip' => '203.0.113.112'));
    ok((bool) preg_match('/name="authlify_ts" value="([^"]+)"/', $form->body, $m), 'stamp on the form');
    $stamp = $m[1];

    $res = captcha_login($u, array('authlify_ts' => $stamp, 'authlify_website' => ''), array('ip' => '203.0.113.112'));
    no(logged_in($res), 'submitted within 2 seconds');
    contains('quicker than a person', strip_tags($res->body));

    sleep(3);
    $form = get(login_url(CSLUG), array('ip' => '203.0.113.112'));
    preg_match('/name="authlify_ts" value="([^"]+)"/', $form->body, $m2);
    sleep(3);
    $res = captcha_login($u, array('authlify_ts' => $m2[1], 'authlify_website' => ''), array('ip' => '203.0.113.112'));
    ok(logged_in($res), 'human pace');

    // Pages served from a cache hand one stamp to many visitors (ARCH-05).
    $res = captcha_login($u, array('authlify_ts' => $m2[1], 'authlify_website' => ''), array('ip' => '203.0.113.113'));
    ok(logged_in($res), 'same stamp from another visitor (cached page)');

    // One address cannot keep reusing it: 10 uses an hour per address.
    for ($i = 0; $i < 9; $i++) {
        captcha_login($u, array('authlify_ts' => $m2[1], 'authlify_website' => ''), array('ip' => '203.0.113.116'));
    }
    $res = captcha_login($u, array('authlify_ts' => $m2[1], 'authlify_website' => ''), array('ip' => '203.0.113.116'));
    ok(logged_in($res), '10th use from one address');
    $res = captcha_login($u, array('authlify_ts' => $m2[1], 'authlify_website' => ''), array('ip' => '203.0.113.116'));
    no(logged_in($res), '11th use from the same address is refused');

    $res = captcha_login($u, array('authlify_ts' => $stamp, 'authlify_website' => 'http://spam.example'), array('ip' => '203.0.113.114'));
    no(logged_in($res), 'trap field filled');

    $res = captcha_login($u, array(), array('ip' => '203.0.113.115'));
    no(logged_in($res), 'no stamp at all (script posting straight to the URL)');
});
