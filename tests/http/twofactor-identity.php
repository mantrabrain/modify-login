<?php
/**
 * Two-factor and identity fixes over HTTP (audit fix phase, 08-fixes-identity):
 * the per-account second-step throttle and atomic attempt counter (F4), the
 * stateless passkey sign-in challenge and its single use (F5), sign-in checks
 * for passkeys and recovery links (F6), the recovery form running the whole
 * `authenticate` chain (F7), and the free-side block on temporary accounts
 * (ARCH-02).
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const ISLUG = 'id-door';

function id_logged_in($res)
{
    foreach (live_cookies($res) as $name => $v) {
        if (0 === strpos($name, 'wordpress_logged_in_')) {
            return true;
        }
    }

    return false;
}

function id_step($user, $ip)
{
    $res = login_post(login_url(ISLUG), $user['login'], $user['pass'], array(), array('ip' => $ip));
    ok(false !== strpos($res->body, 'authlify_2fa_form'), 'second step shown: ' . $res->code . ' ' . substr(strip_tags($res->body), 0, 200));
    preg_match('/name="authlify_nonce" value="([^"]+)"/', $res->body, $n);

    return array('authlify_nonce' => $n[1], 'authlify_uid' => $user['id']);
}

function id_meta($user_id, $key, $value)
{
    wp_eval('update_user_meta(' . (int) $user_id . ', "' . $key . '", ' . var_export($value, true) . ');');
}

function b64u($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64u_decode($data)
{
    $data = strtr($data, '-_', '+/');

    return base64_decode($data . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/**
 * A software passkey (EC P-256) stored for $user_id on the test site.
 */
function id_passkey($user_id)
{
    if (PHP_VERSION_ID < 80000) {
        skip('passkeys need PHP 8.0 (by design; the test site runs this PHP too)');
    }
    $key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
    if (!$key) {
        skip('this PHP build cannot create EC keys');
    }
    $pem = openssl_pkey_get_details($key)['key'];
    $id = b64u(random_bytes(16));
    $row = (int) wp_eval('global $wpdb; $wpdb->insert(\Authlify\TwoFactor\Passkeys::table(), array("user_id" => ' . (int) $user_id . ', "rp_id" => \Authlify\TwoFactor\Passkeys::rp_id(), "credential_hash" => hash("sha256", "' . $id . '"), "credential_id" => "' . $id . '", "public_key" => base64_decode("' . base64_encode($pem) . '"), "sign_count" => 0, "name" => "soft", "created_at" => gmdate("Y-m-d H:i:s"))); \Authlify\TwoFactor\Passkeys::flush(' . (int) $user_id . ', true); echo $wpdb->insert_id;');
    $handle = wp_eval('echo (string) get_user_meta(' . (int) $user_id . ', \Authlify\TwoFactor\Passkeys::META_HANDLE, true);');

    return array('id' => $id, 'key' => $key, 'row' => $row, 'user_id' => (int) $user_id);
}

/**
 * Sign a WebAuthn assertion for the challenge in a sign-in token.
 */
function id_assert(array $pk, $challenge_b64u, $counter = 1)
{
    $host = parse_url(BASE, PHP_URL_HOST);
    $client = json_encode(array('type' => 'webauthn.get', 'challenge' => $challenge_b64u, 'origin' => BASE, 'crossOrigin' => false), JSON_UNESCAPED_SLASHES);
    $auth = hash('sha256', $host, true) . chr(0x05) . pack('N', $counter);
    openssl_sign($auth . hash('sha256', $client, true), $signature, $pk['key'], OPENSSL_ALGO_SHA256);

    return array(
        'authlify_pk_id' => $pk['id'],
        'authlify_pk_clientDataJSON' => b64u($client),
        'authlify_pk_authenticatorData' => b64u($auth),
        'authlify_pk_signature' => b64u($signature),
        'authlify_pk_userHandle' => '',
    );
}

function id_pk_options($ip)
{
    if (PHP_VERSION_ID < 80000) {
        skip('passkeys need PHP 8.0 (by design; the test site runs this PHP too)');
    }
    $res = post(login_url(ISLUG) . '?action=authlify_passkey_options', array('authlify' => '1'), array('ip' => $ip));
    $json = json_decode($res->body, true);
    ok(!empty($json['success']), 'passkey options: ' . $res->code . ' ' . substr($res->body, 0, 200));

    return $json['data'];
}

function id_pk_rows()
{
    return (int) wp_eval('global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \'%authlify\\\\_pk%\'");');
}

before_all(function () {
    settings(array('login_slug' => ISLUG, 'block_wp_login' => true, 'twofa_enabled' => true, 'twofa_methods' => array('totp', 'backup', 'passkey'), 'passkey_login_button' => true, 'captcha_provider' => 'none', 'captcha_forms' => array('login'), 'honeypot' => false, 'limit_enabled' => true, 'limit_attempts' => 20));
    $u = http_user('editor');
    $GLOBALS['secret'] = wp_eval('$s = \Authlify\TwoFactor\Totp::new_secret(); update_user_meta(' . $u['id'] . ', \Authlify\TwoFactor\Totp::META_SECRET, \Authlify\TwoFactor\Crypto::encrypt($s, "totp|' . $u['id'] . '")); \Authlify\TwoFactor\TwoFactor::sync_flag(' . $u['id'] . '); echo $s;');
    $GLOBALS['u'] = $u;
});

after_all(function () {
    wp_eval('global $wpdb; $wpdb->query("DELETE FROM " . \Authlify\TwoFactor\Passkeys::table() . " WHERE name = \'soft\'"); delete_site_option(\Authlify\TwoFactor\Passkeys::ANY_FLAG);');
});

// ---------------------------------------------------------------- F4: attempts and the per-account throttle.

t('F4: ten wrong codes in an hour pause the second step for the account, from any address', function () {
    $u = $GLOBALS['u'];
    id_meta($u['id'], 'authlify_2fa_fails', 10);
    id_meta($u['id'], 'authlify_2fa_fails_since', time());
    try {
        $f = id_step($u, '198.51.100.41');
        $res = post(login_url(ISLUG) . '?action=authlify_2fa', array_merge($f, array('authlify_method' => 'totp', 'authlify_code' => totp_now($GLOBALS['secret']))), array('ip' => '198.51.100.41'));
        no(id_logged_in($res), 'the right code is refused while the account is paused');
        contains('Too many wrong codes were entered for this account', strip_tags($res->body));
    } finally {
        wp_eval('delete_user_meta(' . $u['id'] . ', "authlify_2fa_fails"); delete_user_meta(' . $u['id'] . ', "authlify_2fa_fails_since");');
    }

    // A wrong code from a new address counts against the account.
    $f = id_step($u, '198.51.100.42');
    post(login_url(ISLUG) . '?action=authlify_2fa', array_merge($f, array('authlify_method' => 'totp', 'authlify_code' => '000000' === totp_now($GLOBALS['secret']) ? '111111' : '000000')), array('ip' => '198.51.100.42'));
    eq('1', wp_eval('echo (int) get_user_meta(' . $u['id'] . ', "authlify_2fa_fails", true);'), 'account counter');
});

t('F4: attempts are counted before the code is checked (a sixth try is refused even with the right code)', function () {
    $u = $GLOBALS['u'];
    $f = id_step($u, '198.51.100.43');
    id_meta($u['id'], 'authlify_2fa_login_attempts', 5);
    $res = post(login_url(ISLUG) . '?action=authlify_2fa', array_merge($f, array('authlify_method' => 'totp', 'authlify_code' => totp_now($GLOBALS['secret']))), array('ip' => '198.51.100.43'));
    no(id_logged_in($res));
    contains('authlify_2fa=too_many', $res->location);
    eq('', wp_eval('echo get_user_meta(' . $u['id'] . ', "authlify_2fa_login", true) ? "left" : "";'), 'the pending login is void');
});

t('F4: a right code signs in and clears the account counter', function () {
    $u = $GLOBALS['u'];
    wp_eval('delete_user_meta(' . $u['id'] . ', \Authlify\TwoFactor\Totp::META_LAST_STEP);');
    id_meta($u['id'], 'authlify_2fa_fails', 3);
    id_meta($u['id'], 'authlify_2fa_fails_since', time());
    $f = id_step($u, '198.51.100.44');
    $res = post(login_url(ISLUG) . '?action=authlify_2fa', array_merge($f, array('authlify_method' => 'totp', 'authlify_code' => totp_now($GLOBALS['secret'], 1))), array('ip' => '198.51.100.44'));
    ok(id_logged_in($res), 'signed in: ' . $res->code . ' ' . substr(strip_tags($res->body), 0, 200));
    eq('', wp_eval('echo get_user_meta(' . $u['id'] . ', "authlify_2fa_fails", true);'));
});

// ---------------------------------------------------------------- F5: passkey sign-in challenge.

t('F5: anonymous passkey options store nothing; the token is signed and expires', function () {
    $pk = id_passkey($GLOBALS['u']['id']);
    $GLOBALS['pk'] = $pk;
    $before = id_pk_rows();
    for ($i = 0; $i < 5; $i++) {
        $data = id_pk_options('198.51.100.50');
    }
    eq($before, id_pk_rows(), 'no option rows for anonymous option requests');
    eq(3, count(explode('.', $data['token'])), 'challenge.expires.signature');

    // A forged token (challenge swapped) and a tampered expiry are refused.
    list($challenge, $expires, $sig) = explode('.', $data['token']);
    $forged = b64u(random_bytes(32)) . '.' . $expires . '.' . $sig;
    $res = post(login_url(ISLUG) . '?action=authlify_passkey_login', array_merge(array('authlify_pk_token' => $forged), id_assert($pk, explode('.', $forged)[0])), array('ip' => '198.51.100.50'));
    no(id_logged_in($res), 'forged challenge');
    contains('authlify_passkey=failed', $res->location);
    $later = $challenge . '.' . ($expires + 3600) . '.' . $sig;
    $res = post(login_url(ISLUG) . '?action=authlify_passkey_login', array_merge(array('authlify_pk_token' => $later), id_assert($pk, $challenge)), array('ip' => '198.51.100.50'));
    no(id_logged_in($res), 'tampered expiry');
});

t('F5: a signed challenge signs in once; replaying the same assertion is refused', function () {
    if (empty($GLOBALS['pk']['user_id'])) {
        skip('no test passkey (passkeys need PHP 8.0 and EC keys)');
    }
    $pk = $GLOBALS['pk'];
    $data = id_pk_options('198.51.100.51');
    $challenge = explode('.', $data['token'])[0];
    $body = array_merge(array('authlify_pk_token' => $data['token']), id_assert($pk, $challenge, 5));
    $res = post(login_url(ISLUG) . '?action=authlify_passkey_login', $body, array('ip' => '198.51.100.51'));
    ok(id_logged_in($res), 'passkey sign-in: ' . $res->code . ' ' . $res->location);

    // Same challenge, a higher counter (as a cloned authenticator or a replay with a fresh signature would send).
    $res = post(login_url(ISLUG) . '?action=authlify_passkey_login', array_merge(array('authlify_pk_token' => $data['token']), id_assert($pk, $challenge, 6)), array('ip' => '198.51.100.51'));
    no(id_logged_in($res), 'the challenge works once');
});

// ---------------------------------------------------------------- F6 / ARCH-02: sign-in blocks.

t('F6: a blocked account cannot sign in with a passkey, and is told why', function () {
    if (empty($GLOBALS['pk']['user_id'])) {
        skip('no test passkey (passkeys need PHP 8.0 and EC keys)');
    }
    $pk = $GLOBALS['pk'];
    id_meta($pk['user_id'], 'authlify_sign_in_blocked', array('reason' => 'test', 'by' => 'test', 'message' => 'Paused for the test.'));
    try {
        $data = id_pk_options('198.51.100.52');
        $res = post(login_url(ISLUG) . '?action=authlify_passkey_login', array_merge(array('authlify_pk_token' => $data['token']), id_assert($pk, explode('.', $data['token'])[0], 20)), array('ip' => '198.51.100.52'));
        no(id_logged_in($res), 'refused');
        contains('authlify_passkey=refused', $res->location);
        $page = get($res->location, array('ip' => '198.51.100.52'));
        contains('cannot sign in from here or at this time', strip_tags($page->body));
    } finally {
        wp_eval('delete_user_meta(' . $pk['user_id'] . ', "authlify_sign_in_blocked");');
    }
});

t('F6: a recovery link does not sign in a blocked account (and stays valid)', function () {
    $u = $GLOBALS['u'];
    $token = wp_eval('$t = "RecoveryTokenForTheTestSuite0123456789abcd"; update_user_meta(' . $u['id'] . ', \Authlify\TwoFactor\Recovery::META, array("hash" => hash_hmac("sha256", "recover|' . $u['id'] . '|" . $t, wp_salt("auth")), "expires" => time() + 600, "ip" => "")); echo $t;');
    id_meta($u['id'], 'authlify_sign_in_blocked', array('reason' => 'test', 'by' => 'test', 'message' => 'Paused for the test.'));
    try {
        $res = post(login_url(ISLUG) . '?action=authlify_2fa_recover', array('uid' => $u['id'], 'rkey' => $token), array('ip' => '198.51.100.53'));
        no(id_logged_in($res), 'refused');
        contains('Paused for the test.', strip_tags($res->body));
        neq('', wp_eval('echo get_user_meta(' . $u['id'] . ', \Authlify\TwoFactor\Recovery::META, true) ? "kept" : "";'), 'the link is kept for later');
    } finally {
        wp_eval('delete_user_meta(' . $u['id'] . ', "authlify_sign_in_blocked");');
    }

    // Unblocked, and posted from another site: refused (login CSRF).
    $res = post(login_url(ISLUG) . '?action=authlify_2fa_recover', array('uid' => $u['id'], 'rkey' => $token), array('ip' => '198.51.100.53', 'headers' => array('Origin: https://evil.example', 'Sec-Fetch-Site: cross-site')));
    no(id_logged_in($res), 'cross-site POST');

    $res = post(login_url(ISLUG) . '?action=authlify_2fa_recover', array('uid' => $u['id'], 'rkey' => $token), array('ip' => '198.51.100.53', 'headers' => array('Origin: ' . BASE, 'Sec-Fetch-Site: same-origin')));
    ok(id_logged_in($res), 'same-site POST signs in: ' . $res->code . ' ' . substr(strip_tags($res->body), 0, 200));
});

t('ARCH-02: temporary accounts (authlify_temp_expires) never use a password or reset it; expired ones lose their session', function () {
    $t = http_user('administrator');
    id_meta($t['id'], 'authlify_temp_expires', (string) (time() + 3600));
    $res = login_post(login_url(ISLUG), $t['login'], $t['pass'], array(), array('ip' => '198.51.100.60'));
    no(id_logged_in($res), 'password refused while the access is valid');
    contains('temporary account', strip_tags($res->body));

    $mails = mail_count();
    $res = post(login_url(ISLUG) . '?action=lostpassword', array('user_login' => $t['login'], 'wp-submit' => 'Get New Password'), array('ip' => '198.51.100.60'));
    eq($mails, mail_count(), 'no reset email');
    eq('no', wp_eval('echo wp_is_application_passwords_available_for_user(' . $t['id'] . ') ? "yes" : "no";'), 'no application passwords');

    // A session made while valid ends once the access has passed.
    $s = session_for($t['id']);
    $res = get('/wp-admin/profile.php', array('cookies' => $s['cookies'], 'ip' => '198.51.100.60'));
    eq(200, $res->code, 'valid access: wp-admin opens');
    id_meta($t['id'], 'authlify_temp_expires', (string) (time() - 5));
    $res = get('/wp-admin/profile.php', array('cookies' => $s['cookies'], 'ip' => '198.51.100.60'));
    neq(200, $res->code, 'expired access: signed out');
    eq('0', wp_eval('echo count(WP_Session_Tokens::get_instance(' . $t['id'] . ')->get_all());'), 'sessions destroyed');
});

// ---------------------------------------------------------------- F7: the recovery form runs `authenticate`.

t('F7: the recovery request form applies the login CAPTCHA/honeypot and counts wrong passwords once', function () {
    $u = $GLOBALS['u'];
    // The recovery form allows a few requests per address and hour; earlier runs in the same hour must not count.
    wp_eval('foreach (array("198.51.100.70", "198.51.100.71", "198.51.100.72") as $ip) { delete_transient("authlify_2fa_rec_ip_" . md5($ip)); }');
    settings(array('honeypot' => true, 'captcha_forms' => array('login')));
    $form = get(login_url(ISLUG) . '?action=authlify_2fa_recover', array('ip' => '198.51.100.70'));
    contains('authlify-captcha', $form->body, 'the form carries the login honeypot');
    preg_match('/name="_wpnonce" value="([^"]+)"/', $form->body, $n);

    $mails = mail_count();
    $res = post(login_url(ISLUG) . '?action=authlify_2fa_recover', array('log' => $u['login'], 'pwd' => $u['pass'], '_wpnonce' => $n[1], '_wp_http_referer' => '/'), array('ip' => '198.51.100.70', 'cookies' => array('wordpress_test_cookie' => 'WP%20Cookie%20check')));
    eq($mails, mail_count(), 'a bot without the honeypot fields gets no email');
    not_contains('we emailed a recovery link', strtolower(strip_tags($res->body)));
    settings(array('honeypot' => false));

    // A wrong password: counted by the limiter once, logged once.
    $logs = (int) wp_eval('global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM " . \Authlify\Log\Log::table() . " WHERE ip = \'198.51.100.71\'");');
    $form = get(login_url(ISLUG) . '?action=authlify_2fa_recover', array('ip' => '198.51.100.71'));
    preg_match('/name="_wpnonce" value="([^"]+)"/', $form->body, $n);
    post(login_url(ISLUG) . '?action=authlify_2fa_recover', array('log' => $u['login'], 'pwd' => 'wrong-password', '_wpnonce' => $n[1]), array('ip' => '198.51.100.71'));
    eq('1', wp_eval('echo \Authlify\Security\Limiter::ip_failures("198.51.100.71");'), 'counted once');
    eq($logs + 1, (int) wp_eval('global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM " . \Authlify\Log\Log::table() . " WHERE ip = \'198.51.100.71\'");'), 'one log row');

    // The right password still sends the link.
    $mails = mail_count();
    $form = get(login_url(ISLUG) . '?action=authlify_2fa_recover', array('ip' => '198.51.100.72'));
    preg_match('/name="_wpnonce" value="([^"]+)"/', $form->body, $n);
    $res = post(login_url(ISLUG) . '?action=authlify_2fa_recover', array('log' => $u['login'], 'pwd' => $u['pass'], '_wpnonce' => $n[1]), array('ip' => '198.51.100.72'));
    contains('we emailed a recovery link', strtolower(strip_tags($res->body)));
    eq($mails + 1, mail_count(), 'one recovery email');
});
