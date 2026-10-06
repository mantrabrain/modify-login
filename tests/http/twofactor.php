<?php
/**
 * Two-factor login over HTTP: the second step cannot be skipped, codes are
 * single use, wrong codes are limited and counted, XML-RPC refuses the account
 * password for 2FA users, and the email recovery link is single use and expires.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const TSLUG = 'twofa-door';

function tf_logged_in($res)
{
    foreach (live_cookies($res) as $name => $v) {
        if (0 === strpos($name, 'wordpress_logged_in_')) {
            return true;
        }
    }

    return false;
}

function tf_step_fields($res)
{
    ok(false !== strpos($res->body, 'authlify_2fa_form'), 'second step shown: ' . $res->code . ' ' . substr(strip_tags($res->body), 0, 200));
    preg_match('/name="authlify_nonce" value="([^"]+)"/', $res->body, $n);
    preg_match('/name="authlify_uid" value="([^"]+)"/', $res->body, $u);

    return array('authlify_nonce' => $n[1], 'authlify_uid' => $u[1]);
}

function tf_step_post(array $fields, array $extra, $ip)
{
    return post(login_url(TSLUG) . '?action=authlify_2fa', array_merge($fields, $extra), array('ip' => $ip));
}

/**
 * Start a login (password step) and return the second step's hidden fields.
 * One pending login per user: starting a new one voids the previous nonce.
 */
function tf_begin($ip)
{
    $u = $GLOBALS['u'];

    return tf_step_fields(login_post(login_url(TSLUG), $u['login'], $u['pass'], array(), array('ip' => $ip)));
}

function tf_sessions($id)
{
    return (int) wp_eval('echo count(WP_Session_Tokens::get_instance(' . (int) $id . ')->get_all());');
}

function xmlrpc_call($method, array $params, $ip)
{
    $xml = '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params>';
    foreach ($params as $p) {
        $xml .= '<param><value><string>' . htmlspecialchars($p) . '</string></value></param>';
    }
    $xml .= '</params></methodCall>';

    return post(BASE . '/xmlrpc.php', $xml, array('ip' => $ip, 'headers' => array('Content-Type: text/xml')));
}

before_all(function () {
    settings(array('login_slug' => TSLUG, 'block_wp_login' => true, 'twofa_enabled' => true, 'twofa_methods' => array('totp', 'backup', 'passkey'), 'captcha_provider' => 'none', 'honeypot' => false, 'limit_enabled' => true, 'limit_attempts' => 20, 'xmlrpc' => 'on', 'app_passwords' => 'on'));
    $u = http_user('editor');
    $GLOBALS['secret'] = wp_eval('$s = \Authlify\TwoFactor\Totp::new_secret(); update_user_meta(' . $u['id'] . ', \Authlify\TwoFactor\Totp::META_SECRET, \Authlify\TwoFactor\Crypto::encrypt($s)); \Authlify\TwoFactor\TwoFactor::sync_flag(' . $u['id'] . '); echo $s;');
    $GLOBALS['codes'] = wp_json('echo json_encode(\Authlify\TwoFactor\BackupCodes::generate(' . $u['id'] . '));');
    $GLOBALS['u'] = $u;
    $GLOBALS['plain'] = http_user('author');
});

t('password step: no session, no auth cookie, the second step is shown', function () {
    $u = $GLOBALS['u'];
    $res = login_post(login_url(TSLUG), $u['login'], $u['pass'], array('redirect_to' => BASE . '/wp-admin/'), array('ip' => '203.0.113.120'));
    eq(200, $res->code);
    no(tf_logged_in($res), 'no logged-in cookie survives the first step');
    foreach (live_cookies($res) as $name => $v) {
        no(0 === strpos($name, 'wordpress_') && false === strpos($name, 'test_cookie'), 'auth cookie ' . $name . ' was sent');
    }
    eq(0, tf_sessions($u['id']), 'the session core created was destroyed');
    $f = tf_step_fields($res);
    eq((string) $u['id'], $f['authlify_uid']);
    contains('no-store', implode(',', $res->headers['cache-control']));
});

t('skipping the step: wp-admin with the first response\'s cookies is still logged out', function () {
    $res = login_post(login_url(TSLUG), $GLOBALS['u']['login'], $GLOBALS['u']['pass'], array(), array('ip' => '203.0.113.121'));
    $admin = get(BASE . '/wp-admin/', array('cookies' => live_cookies($res)));
    not_contains('wp-admin-bar-my-account', $admin->body);
    ok(in_array($admin->code, array(302, 404), true), 'wp-admin is closed: ' . $admin->code);
});

t('forged step posts: wrong nonce, other user, unknown method, GET with the nonce', function () {
    $f = tf_begin('203.0.113.122');
    $code = totp_now($GLOBALS['secret']);

    $res = tf_step_post(array('authlify_uid' => $f['authlify_uid'], 'authlify_nonce' => 'x' . substr($f['authlify_nonce'], 1)), array('authlify_method' => 'totp', 'authlify_code' => $code), '203.0.113.122');
    no(tf_logged_in($res), 'wrong nonce');
    contains('authlify_2fa=expired', $res->location);

    $other = $GLOBALS['plain'];
    $res = tf_step_post(array('authlify_uid' => (string) $other['id'], 'authlify_nonce' => $f['authlify_nonce']), array('authlify_method' => 'totp', 'authlify_code' => $code), '203.0.113.122');
    no(tf_logged_in($res), 'nonce of one user for another user');

    $res = tf_step_post($f, array('authlify_method' => 'nope', 'authlify_code' => $code), '203.0.113.122');
    no(tf_logged_in($res), 'unknown method');

    $res = get(login_url(TSLUG) . '?action=authlify_2fa&' . http_build_query(array_merge($f, array('authlify_method' => 'totp', 'authlify_code' => $code))), array('ip' => '203.0.113.122'));
    no(tf_logged_in($res), 'GET never verifies');
});

t('wrong code: refused, counted as a failed login', function () {
    $f = tf_begin('203.0.113.123');
    $res = tf_step_post($f, array('authlify_method' => 'totp', 'authlify_code' => '000000' === totp_now($GLOBALS['secret']) ? '111111' : '000000'), '203.0.113.123');
    no(tf_logged_in($res));
    contains('not valid', strip_tags($res->body));
    eq('1', wp_eval('echo \Authlify\Security\Limiter::ip_failures("203.0.113.123");'));
});

t('a new login voids the previous pending one', function () {
    $old = tf_begin('203.0.113.136');
    tf_begin('203.0.113.136');
    $res = tf_step_post($old, array('authlify_method' => 'backup', 'authlify_code' => $GLOBALS['codes'][8]), '203.0.113.136');
    no(tf_logged_in($res));
    contains('authlify_2fa=expired', $res->location);
});

t('right code: signed in, redirected; the login nonce is then void', function () {
    $f = tf_begin('203.0.113.124');
    $GLOBALS['used_step'] = (int) floor(time() / 30);
    $GLOBALS['used_code'] = totp_now($GLOBALS['secret']);
    $res = tf_step_post($f, array('authlify_method' => 'totp', 'authlify_code' => $GLOBALS['used_code'], 'redirect_to' => BASE . '/wp-admin/'), '203.0.113.124');
    eq(302, $res->code);
    ok(tf_logged_in($res), 'auth cookie');
    eq(BASE . '/wp-admin/', $res->location);
    $admin = get(BASE . '/wp-admin/', array('cookies' => live_cookies($res)));
    contains('wp-admin-bar-my-account', $admin->body, 'the cookie works');

    $again = tf_step_post($f, array('authlify_method' => 'backup', 'authlify_code' => $GLOBALS['codes'][7]), '203.0.113.124');
    no(tf_logged_in($again), 'the same login nonce cannot sign in twice');
});

t('the same TOTP code cannot be used for a second login', function () {
    $u = $GLOBALS['u'];
    $step = tf_step_fields(login_post(login_url(TSLUG), $u['login'], $u['pass'], array(), array('ip' => '203.0.113.125')));
    $res = tf_step_post($step, array('authlify_method' => 'totp', 'authlify_code' => $GLOBALS['used_code']), '203.0.113.125');
    no(tf_logged_in($res), 'replayed code');
    contains('just used', strip_tags($res->body));
    // The step after the used one (still inside the ±1 window).
    $res = tf_step_post($step, array('authlify_method' => 'totp', 'authlify_code' => totp_now($GLOBALS['secret'], $GLOBALS['used_step'] + 1 - (int) floor(time() / 30))), '203.0.113.125');
    ok(tf_logged_in($res), 'the next code works');
});

t('five wrong codes void the pending login', function () {
    $u = $GLOBALS['u'];
    $step = tf_step_fields(login_post(login_url(TSLUG), $u['login'], $u['pass'], array(), array('ip' => '203.0.113.126')));
    for ($i = 0; $i < 5; $i++) {
        $res = tf_step_post($step, array('authlify_method' => 'backup', 'authlify_code' => 'zzzz-zzz' . $i), '203.0.113.126');
    }
    contains('authlify_2fa=too_many', $res->location);
    $res = tf_step_post($step, array('authlify_method' => 'backup', 'authlify_code' => $GLOBALS['codes'][9]), '203.0.113.126');
    no(tf_logged_in($res), 'a right code after the limit');
});

t('backup code: works once', function () {
    $u = $GLOBALS['u'];
    $step = tf_step_fields(login_post(login_url(TSLUG), $u['login'], $u['pass'], array(), array('ip' => '203.0.113.127')));
    $res = tf_step_post($step, array('authlify_method' => 'backup', 'authlify_code' => $GLOBALS['codes'][0]), '203.0.113.127');
    ok(tf_logged_in($res), 'first use');
    $step = tf_step_fields(login_post(login_url(TSLUG), $u['login'], $u['pass'], array(), array('ip' => '203.0.113.127')));
    $res = tf_step_post($step, array('authlify_method' => 'backup', 'authlify_code' => $GLOBALS['codes'][0]), '203.0.113.127');
    no(tf_logged_in($res), 'second use');
});

t('a wrong password never reaches the second step', function () {
    $u = $GLOBALS['u'];
    $res = login_post(login_url(TSLUG), $u['login'], 'wrong', array(), array('ip' => '203.0.113.128'));
    not_contains('authlify_2fa_form', $res->body);
    no(tf_logged_in($res));
});

t('XML-RPC: the account password is refused for a 2FA user, with a clear reason', function () {
    $u = $GLOBALS['u'];
    $res = xmlrpc_call('wp.getUsersBlogs', array($u['login'], $u['pass']), '203.0.113.129');
    eq(200, $res->code);
    contains('<name>faultCode</name><value><int>403</int>', preg_replace('/\s+/', '', $res->body));
    contains('two-factor', strtolower($res->body));
    eq('0', wp_eval('echo \Authlify\Security\Limiter::ip_failures("203.0.113.129");'), 'not counted as a password guess');
});

t('XML-RPC: an application password still works for the 2FA user; a user without 2FA may use the password', function () {
    $u = $GLOBALS['u'];
    $ap = wp_json('$a = WP_Application_Passwords::create_new_application_password(' . $u['id'] . ', array("name" => "xmlrpc")); echo json_encode($a[0]);');
    $res = xmlrpc_call('wp.getUsersBlogs', array($u['login'], $ap), '203.0.113.130');
    contains('<name>blogName</name>', $res->body, 'app password: ' . substr(strip_tags($res->body), 0, 200));

    $p = $GLOBALS['plain'];
    $res = xmlrpc_call('wp.getUsersBlogs', array($p['login'], $p['pass']), '203.0.113.130');
    contains('<name>blogName</name>', $res->body, 'no 2FA: password works');
});

t('REST: application password works for the 2FA user, but may not change 2FA settings', function () {
    $u = $GLOBALS['u'];
    $ap = wp_json('$a = WP_Application_Passwords::create_new_application_password(' . $u['id'] . ', array("name" => "rest")); echo json_encode($a[0]);');
    $h = array('Authorization: Basic ' . base64_encode($u['login'] . ':' . $ap));
    eq(200, get(BASE . '/wp-json/wp/v2/users/me', array('headers' => $h, 'ip' => '203.0.113.131'))->code);
    $res = request('DELETE', BASE . '/wp-json/authlify/v1/twofactor/totp', array('headers' => $h, 'ip' => '203.0.113.131'));
    eq(403, $res->code, 'turning off TOTP with an API credential');
    eq('1', wp_eval('echo \Authlify\TwoFactor\Totp::is_configured(' . $u['id'] . ') ? 1 : 0;'), 'TOTP still on');
});

t('recovery link: emailed from the second step, confirm page first, single use', function () {
    $u = $GLOBALS['u'];
    $before = mail_count();
    $step = tf_step_fields(login_post(login_url(TSLUG), $u['login'], $u['pass'], array(), array('ip' => '203.0.113.132')));
    $res = tf_step_post($step, array('authlify_recover' => '1'), '203.0.113.132');
    contains('We emailed a recovery link', strip_tags($res->body));
    $sent = mails($before);
    ok(count($sent) >= 1, 'a mail was sent');
    $mail = end($sent);
    eq($u['email'], is_array($mail['to']) ? $mail['to'][0] : $mail['to']);
    ok((bool) preg_match('#(https?://\S+action=authlify_2fa_recover\S+)#', $mail['message'], $m), 'link in the mail');
    $link = html_entity_decode($m[1]);
    contains('/' . TSLUG . '/', $link, 'link uses the login URL');

    $page = get($link, array('ip' => '203.0.113.132'));
    no(tf_logged_in($page), 'opening the link (GET, e.g. a mail scanner) does not sign in');
    contains('Sign in and set up again', $page->body);

    parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
    $use = post(login_url(TSLUG) . '?action=authlify_2fa_recover', array('uid' => $q['uid'], 'rkey' => $q['rkey']), array('ip' => '203.0.113.132'));
    ok(tf_logged_in($use), 'confirming signs in');
    contains('authlify_recovered=1', $use->location);
    eq('1', wp_eval('echo \Authlify\TwoFactor\Totp::is_configured(' . $u['id'] . ') ? 1 : 0;'), '2FA methods kept');

    $again = post(login_url(TSLUG) . '?action=authlify_2fa_recover', array('uid' => $q['uid'], 'rkey' => $q['rkey']), array('ip' => '203.0.113.133'));
    no(tf_logged_in($again), 'second use');
    contains('invalid, used or expired', strip_tags($again->body));

    $admin_mail = array_filter(mails($before), function ($m) {
        return false !== strpos($m['subject'], 'recovery link');
    });
    ok(count($admin_mail) >= 2, 'the admin was told the link was used');
});

t('recovery link: expires after 15 minutes; a newer link replaces an older one', function () {
    $u = $GLOBALS['u'];
    wp_eval('delete_transient("authlify_2fa_rec_u_' . $u['id'] . '");');
    $links = array();
    for ($i = 0; $i < 2; $i++) {
        $before = mail_count();
        $step = tf_step_fields(login_post(login_url(TSLUG), $u['login'], $u['pass'], array(), array('ip' => '203.0.113.134')));
        tf_step_post($step, array('authlify_recover' => '1'), '203.0.113.134');
        $sent = mails($before);
        preg_match('#action=authlify_2fa_recover&(?:amp;)?uid=(\d+)&(?:amp;)?rkey=([A-Za-z0-9]+)#', end($sent)['message'], $m);
        $links[] = array('uid' => $m[1], 'rkey' => $m[2]);
    }
    $res = post(login_url(TSLUG) . '?action=authlify_2fa_recover', $links[0], array('ip' => '203.0.113.134'));
    no(tf_logged_in($res), 'the older link is void');

    wp_eval('$r = get_user_meta(' . $u['id'] . ', "authlify_2fa_recovery", true); $r["expires"] = time() - 1; update_user_meta(' . $u['id'] . ', "authlify_2fa_recovery", $r);');
    $res = post(login_url(TSLUG) . '?action=authlify_2fa_recover', $links[1], array('ip' => '203.0.113.134'));
    no(tf_logged_in($res), 'expired');
});

t('recovery link: at most 3 per account per hour', function () {
    $u = $GLOBALS['u'];
    wp_eval('delete_transient("authlify_2fa_rec_u_' . $u['id'] . '");');
    $messages = array();
    for ($i = 0; $i < 4; $i++) {
        $step = tf_step_fields(login_post(login_url(TSLUG), $u['login'], $u['pass'], array(), array('ip' => '203.0.113.135')));
        $messages[] = strip_tags(tf_step_post($step, array('authlify_recover' => '1'), '203.0.113.135')->body);
    }
    contains('We emailed', $messages[2]);
    contains('already sent several', $messages[3]);
    wp_eval('delete_transient("authlify_2fa_rec_u_' . $u['id'] . '");');
});

t('SEC2-16: made-up 2FA recovery links add at most one log row per address a minute', function () {
    $count = function () {
        return (int) wp_eval('global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM " . \Authlify\Log\Log::table() . " WHERE event = \'twofa_recovery_refused\'");');
    };
    $before = $count();
    for ($i = 0; $i < 6; $i++) {
        $res = get(login_url(TSLUG) . '?action=authlify_2fa_recover&uid=1&rkey=made-up-' . $i, array('ip' => '198.51.100.161'));
        contains('invalid, used or expired', strip_tags($res->body));
    }
    eq($before + 1, $count(), 'six requests, one row');
    wp_eval('delete_transient("authlify_2fa_rec_log_" . md5("198.51.100.161"));');
});
