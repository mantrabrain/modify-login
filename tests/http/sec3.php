<?php
/**
 * Round 3 security review: the public lost-password screen and the two-factor import.
 *
 * Abuse first: one account's mailbox, one address, a blocked address. Then the
 * people these limits must not hurt: someone else on a shared address, and a
 * request refused by the CAPTCHA, which must not use up a quota.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const S3SLUG = 's3-door-55';

function s3_nonce($ip)
{
    $form = get('/?authlify_lost_public=1', array('ip' => $ip));
    if (!preg_match('/name="authlify_public_nonce" value="([^"]+)"/', $form->body, $m)) {
        fail('no form: ' . $form->code);
    }

    return $m[1];
}

function s3_ask($login, $nonce, $ip, array $extra = array())
{
    return post('/?authlify_lost_public=1', array_merge(array('authlify_public_login' => $login, 'authlify_public_nonce' => $nonce), $extra), array('ip' => $ip));
}

function s3_reset()
{
    wp_eval('global $wpdb; $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE \'%authlify_lost_rate_%\'");');
}

before_all(function () {
    settings(array('login_slug' => S3SLUG, 'block_wp_login' => true, 'captcha_provider' => 'none', 'ip_denylist' => '', 'ip_allowlist' => ''));
});

before_each('s3_reset');
after_all('s3_reset');

t('one account gets at most 3 reset emails an hour from the public screen, with one answer', function () {
    $u = http_user('subscriber');
    $n = s3_nonce('203.0.113.120');
    $c = mail_count();
    $bodies = array();
    for ($i = 0; $i < 8; $i++) {
        $res = s3_ask($u['login'], $n, '203.0.113.120');
        eq(200, $res->code);
        $bodies[] = wp_strip_all_tags_test($res->body);
    }
    eq(3, mail_count() - $c, 'emails sent to one account');
    eq(1, count(array_unique($bodies)), 'the answer must not change at the limit');
});

function wp_strip_all_tags_test($html)
{
    return trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#s', '', $html))));
}

t('rotating addresses and switching to the email address do not get around the account limit', function () {
    $u = http_user('subscriber');
    $n = s3_nonce('203.0.113.121');
    $c = mail_count();
    for ($i = 0; $i < 3; $i++) {
        s3_ask($u['login'], $n, '203.0.113.' . (130 + $i));
    }
    s3_ask($u['email'], $n, '203.0.113.140');
    s3_ask($u['login'], $n, '203.0.113.141');
    eq(3, mail_count() - $c);
});

t('an unknown account gets the same answer as a known one, at and past the limit', function () {
    $u = http_user('subscriber');
    $n = s3_nonce('203.0.113.122');
    for ($i = 0; $i < 4; $i++) {
        s3_ask($u['login'], $n, '203.0.113.122');
    }
    eq(wp_strip_all_tags_test(s3_ask($u['login'], $n, '203.0.113.122')->body), wp_strip_all_tags_test(s3_ask('nobody-here', $n, '203.0.113.122')->body));
});

t('one address is stopped past its limit; it is told so, and others on other addresses still work', function () {
    wp_eval('update_option("authlify_s3_low", 1);');
    file_put_contents(SITE_DIR . '/wp-content/mu-plugins/authlify-test-s3.php', "<?php\nadd_filter('authlify_lost_public_limits', function (\$l) { \$l['address'] = 5; return \$l; });\n");
    try {
        $n = s3_nonce('203.0.113.123');
        $c = mail_count();
        $users = array();
        for ($i = 0; $i < 7; $i++) {
            $users[$i] = http_user('subscriber');
            $last = s3_ask($users[$i]['login'], $n, '203.0.113.123');
        }
        eq(5, mail_count() - $c, 'emails from one address');
        contains('Too many password-reset requests', $last->body);

        $c = mail_count();
        $other = s3_ask($users[6]['login'], $n, '203.0.113.124');
        eq(1, mail_count() - $c, 'another address still works');
        contains('If that account exists', $other->body);

        // A logged line, once.
        eq('1', wp_eval('global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM " . \Authlify\Log\Log::table() . " WHERE event = \'lost_limited\'");'));
    } finally {
        @unlink(SITE_DIR . '/wp-content/mu-plugins/authlify-test-s3.php');
        wp_eval('delete_option("authlify_s3_low"); global $wpdb; $wpdb->query("DELETE FROM " . \Authlify\Log\Log::table() . " WHERE event = \'lost_limited\'");');
    }
});

t('thirty people behind one address can all ask (the shared-address default)', function () {
    $ok = wp_json('echo json_encode(array("address" => \Authlify\Login\Recovery::LOST_PER_ADDRESS, "account" => \Authlify\Login\Recovery::LOST_PER_ACCOUNT));');
    ok($ok['address'] >= 30, 'address limit ' . $ok['address']);
    ok($ok['account'] >= 3, 'account limit ' . $ok['account']);
});

t('a blocked address sends nothing and sees the ordinary answer', function () {
    $u = http_user('subscriber');
    $n = s3_nonce('203.0.113.125');
    settings(array('ip_denylist' => '203.0.113.126'));
    $c = mail_count();
    $res = s3_ask($u['login'], $n, '203.0.113.126');
    settings(array('ip_denylist' => ''));
    eq(0, mail_count() - $c);
    contains('If that account exists', $res->body);
});

t('a request the CAPTCHA refuses uses up no quota', function () {
    settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'x', 'captcha_secret_key' => 'y', 'captcha_forms' => array('lostpassword'), 'captcha_mode' => 'always'));
    wp_eval('update_option("authlify_test_http", array(array("match" => "siteverify", "siteverify" => true)));');
    $u = http_user('subscriber');
    $n = s3_nonce('203.0.113.127');
    $c = mail_count();
    for ($i = 0; $i < 6; $i++) {
        s3_ask($u['login'], $n, '203.0.113.127');
    }
    eq(0, mail_count() - $c, 'no token, no email');
    s3_ask($u['login'], $n, '203.0.113.127', array('cf-turnstile-response' => 'good'));
    eq(1, mail_count() - $c, 'the real owner still gets through');
    settings(array('captcha_provider' => 'none', 'captcha_forms' => array()));
});

t('the public lost-password screen with a CAPTCHA prints its widget and adds no deprecation notice', function () {
    settings(array('captcha_provider' => 'altcha', 'captcha_forms' => array('lostpassword')));
    $log = dirname(SITE_DIR) . '/debug.log';
    clearstatcache();
    $size = is_file($log) ? filesize($log) : 0;
    $res = get('/?authlify_lost_public=1', array('ip' => '203.0.113.129'));
    settings(array('captcha_provider' => 'none', 'captcha_forms' => array()));
    eq(200, $res->code);
    contains('altcha', strtolower($res->body));
    clearstatcache();
    $added = is_file($log) ? (string) file_get_contents($log, false, null, $size) : '';
    not_contains('print_emoji_styles', $added);
});

t('the Host header does not reach the reset link', function () {
    if ('1' === wp_eval('echo is_multisite() ? 1 : 0;')) {
        skip('a network redirects an unknown Host before WordPress loads');
    }
    $u = http_user('subscriber');
    $n = s3_nonce('203.0.113.128');
    $c = mail_count();
    post('/?authlify_lost_public=1', array('authlify_public_login' => $u['login'], 'authlify_public_nonce' => $n), array('ip' => '203.0.113.128', 'headers' => array('Host: evil.example', 'X-Forwarded-Host: evil.example')));
    $m = mails($c);
    eq(1, count($m));
    not_contains('evil.example', $m[0]['message']);
});

t('multisite: a site admin who is not a super admin cannot run the two-factor import', function () {
    if ('1' !== wp_eval('echo is_multisite() ? 1 : 0;')) {
        skip('needs a multisite site');
    }
    $a = http_user('administrator');
    $s = session_for($a['id'], array('authlify_2fa_import'));
    $res = post('/wp-admin/admin-post.php', array('action' => 'authlify_2fa_import', 'source' => 'wordfence', 'run' => '1', '_wpnonce' => $s['nonces']['authlify_2fa_import']), array('cookies' => $s['cookies']));
    eq(403, $res->code);
});
