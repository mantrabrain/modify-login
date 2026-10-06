<?php
/**
 * CAPTCHA: honeypot (replay, expiry, too fast), ALTCHA (sign, verify, replay),
 * hostname check, outages, after-failures mode, test mode, key validation on
 * save (siteverify mocked with pre_http_request).
 *
 * The HTTP-level bypass tests (Basic auth, forged WooCommerce field) are in
 * http/captcha.php.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Captcha\Admin as CaptchaAdmin;
use Authlify\Captcha\Altcha;
use Authlify\Captcha\Captcha;
use Authlify\Captcha\Honeypot;
use Authlify\Security\Limiter;

function captcha_reset()
{
    $p = new ReflectionProperty(Captcha::class, 'checked');
    if (PHP_VERSION_ID < 80100) { $p->setAccessible(true); } // No-op since PHP 8.1, deprecated in 8.5.
    $p->setValue(null, array());
}

function honeypot_stamp($time, $nonce = null)
{
    $m = new ReflectionMethod(Honeypot::class, 'sign');
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); } // No-op since PHP 8.1, deprecated in 8.5.
    $nonce = null === $nonce ? bin2hex(random_bytes(8)) : $nonce;

    return $time . '.' . $nonce . '.' . $m->invoke(null, $time, $nonce);
}

function honeypot_forget($stamp)
{
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_authlify\\_hp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_authlify\\_hp\\_%'");
    wp_cache_flush();
}

function altcha_solve(array $c, $number = null)
{
    if (null === $number) {
        for ($n = 0; $n <= $c['maxnumber']; $n++) {
            if (hash('sha256', $c['salt'] . $n) === $c['challenge']) {
                $number = $n;
                break;
            }
        }
    }

    return base64_encode(wp_json_encode(array('algorithm' => $c['algorithm'], 'challenge' => $c['challenge'], 'number' => $number, 'salt' => $c['salt'], 'signature' => $c['signature'])));
}

function altcha_key()
{
    $m = new ReflectionMethod(Altcha::class, 'key');
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); } // No-op since PHP 8.1, deprecated in 8.5.

    return $m->invoke(null);
}

/**
 * siteverify mock: token "good" passes, anything else fails; records calls.
 */
function mock_siteverify(array &$calls, array $success = array())
{
    mock_http(function ($url, $args) use (&$calls, $success) {
        $calls[] = array('url' => $url, 'body' => $args['body']);
        if ('good' === $args['body']['response']) {
            return http_response(200, array_merge(array('success' => true, 'hostname' => 'localhost'), $success));
        }
        if ('bad-secret' === $args['body']['secret']) {
            return http_response(200, array('success' => false, 'error-codes' => array('invalid-input-secret')));
        }

        return http_response(200, array('success' => false, 'error-codes' => array('invalid-input-response')));
    });
}

before_each(function () {
    captcha_reset();
    set_settings(array(
        'captcha_provider' => 'none', 'captcha_site_key' => '', 'captcha_secret_key' => '', 'captcha_forms' => array('login', 'register', 'lostpassword'),
        'captcha_mode' => 'always', 'captcha_after' => 2, 'captcha_test_mode' => false, 'captcha_fail' => 'open', 'honeypot' => false,
        'ip_allowlist' => '', 'limit_enabled' => true,
    ));
    as_ip('203.0.113.90');
    $_POST = array();
});

// ---------------------------------------------------------------- Honeypot.

t('honeypot: filled trap field is refused', function () {
    $_POST = array(Honeypot::FIELD => 'http://spam.example', Honeypot::STAMP => honeypot_stamp(time() - 30));
    eq('honeypot', Honeypot::check());
});

t('honeypot: missing, unsigned or forged stamp is refused', function () {
    $_POST = array();
    eq('honeypot_missing', Honeypot::check(), 'no stamp');
    $_POST = array(Honeypot::STAMP => (time() - 30) . '.deadbeefdeadbeefdead');
    eq('honeypot_missing', Honeypot::check(), 'bad signature');
    $_POST = array(Honeypot::STAMP => 'abc.' . substr(honeypot_stamp(time()), 11));
    eq('honeypot_missing', Honeypot::check(), 'non-numeric time');
    $good = honeypot_stamp(time() - 60);
    list($t, $nonce, $sig) = explode('.', $good);
    $_POST = array(Honeypot::STAMP => ($t - 1000) . '.' . $nonce . '.' . $sig);
    eq('honeypot_missing', Honeypot::check(), 'signature from another time');
    $_POST = array(Honeypot::STAMP => $t . '.' . str_repeat('0', 16) . '.' . $sig);
    eq('honeypot_missing', Honeypot::check(), 'signature from another nonce');
    $_POST = array(Honeypot::STAMP => $t . '.' . $sig);
    eq('honeypot_missing', Honeypot::check(), 'old two-part format');
});

t('honeypot: two forms rendered in the same second get different stamps', function () {
    preg_match('/name="' . Honeypot::STAMP . '" value="([^"]+)"/', Honeypot::html(), $a);
    preg_match('/name="' . Honeypot::STAMP . '" value="([^"]+)"/', Honeypot::html(), $b);
    ok($a[1] !== $b[1], 'unique per render');
});

t('honeypot: too fast (under 2 seconds) is refused', function () {
    $_POST = array(Honeypot::STAMP => honeypot_stamp(time()));
    eq('too_fast', Honeypot::check());
});

t('honeypot: a cached stamp works for many visitors, but each address only a few times an hour (ARCH-05)', function () {
    $stamp = honeypot_stamp(time() - 3600);
    $_POST = array(Honeypot::STAMP => $stamp, Honeypot::FIELD => '');
    for ($i = 1; $i <= 12; $i++) {
        as_ip('198.51.100.' . $i);
        eq('', Honeypot::check(), 'visitor ' . $i . ' with the cached stamp');
    }
    as_ip('198.51.100.200');
    $limits = Honeypot::limits();
    for ($i = 1; $i <= $limits['ip']; $i++) {
        eq('', Honeypot::check(), 'use ' . $i . ' from one address');
    }
    eq('honeypot_replayed', Honeypot::check(), 'one address over its per-stamp limit');
    as_ip('198.51.100.201');
    eq('', Honeypot::check(), 'another address is not affected');
    honeypot_forget($stamp);
});

t('honeypot: one stamp has an overall hourly cap across addresses', function () {
    add_test_filter('authlify_honeypot_stamp_limits', function () {
        return array('ip' => 10, 'total' => 3);
    });
    $stamp = honeypot_stamp(time() - 60);
    $_POST = array(Honeypot::STAMP => $stamp, Honeypot::FIELD => '');
    foreach (array(1, 2, 3) as $i) {
        as_ip('198.51.100.' . (50 + $i));
        eq('', Honeypot::check(), 'use ' . $i);
    }
    as_ip('198.51.100.60');
    eq('honeypot_replayed', Honeypot::check(), 'over the overall cap');
    $_POST = array(Honeypot::STAMP => honeypot_stamp(time() - 60), Honeypot::FIELD => '');
    eq('', Honeypot::check(), 'a burnt stamp never affects another form\'s stamp (A-25)');
    honeypot_forget($stamp);
});

t('honeypot: a stamp older than its lifetime (7 days) is refused; a day-old cached page still works', function () {
    $_POST = array(Honeypot::STAMP => honeypot_stamp(time() - 7 * DAY_IN_SECONDS - 5));
    eq('honeypot_missing', Honeypot::check(), 'expired');
    $old = honeypot_stamp(time() - DAY_IN_SECONDS - 5);
    $_POST = array(Honeypot::STAMP => $old, Honeypot::FIELD => '');
    eq('', Honeypot::check(), 'a page cached for a day');
    honeypot_forget($old);
});

t('honeypot: html() carries a signed stamp that check() accepts after the minimum time', function () {
    $html = Honeypot::html();
    ok(preg_match('/name="' . Honeypot::STAMP . '" value="([^"]+)"/', $html, $m), 'stamp field present');
    contains('tabindex="-1"', $html);
    contains('aria-hidden="true"', $html);
    add_test_filter('authlify_honeypot_min_seconds', '__return_zero');
    $_POST = array(Honeypot::STAMP => $m[1], Honeypot::FIELD => '');
    eq('', Honeypot::check());
    honeypot_forget($m[1]);
});

t('honeypot on its own protects the form and blocks with authlify_captcha', function () {
    set_settings(array('honeypot' => true));
    ok(Captcha::form_enabled('login'));
    $_POST = array('log' => 'x');
    is_error_code('authlify_captcha', Captcha::check('login', 'x'));
});

// ---------------------------------------------------------------- ALTCHA.

t('altcha: challenge is signed, solvable and accepted once', function () {
    $c = Altcha::challenge();
    eq('SHA-256', $c['algorithm']);
    eq(hash_hmac('sha256', $c['challenge'], altcha_key()), $c['signature']);
    ok(preg_match('/\?expires=(\d+)$/', $c['salt'], $m) && (int) $m[1] > time() + 1000, 'salt carries a future expiry');

    $payload = altcha_solve($c);
    eq('', Altcha::verify_payload($payload), 'valid solution');
    eq('replayed', Altcha::verify_payload($payload), 'replay');
});

t('altcha: verify without consuming leaves it usable', function () {
    $payload = altcha_solve(Altcha::challenge());
    eq('', Altcha::verify_payload($payload, false));
    eq('', Altcha::verify_payload($payload));
});

t('altcha: wrong number, forged signature, other algorithm, junk', function () {
    $c = Altcha::challenge();
    $right = json_decode(base64_decode(altcha_solve($c)), true)['number'];
    eq('wrong-solution', Altcha::verify_payload(altcha_solve($c, $right === 0 ? 1 : $right - 1)));

    // Attacker picks their own challenge and signs it with a guessed key.
    $salt = 'aaaa?expires=' . (time() + 600);
    $forged = array('algorithm' => 'SHA-256', 'challenge' => hash('sha256', $salt . '5'), 'maxnumber' => 100000, 'salt' => $salt, 'signature' => hash_hmac('sha256', hash('sha256', $salt . '5'), 'guess'));
    eq('bad-signature', Altcha::verify_payload(altcha_solve($forged, 5)));

    $bad = $c;
    $bad['algorithm'] = 'SHA-1';
    eq('bad-algorithm', Altcha::verify_payload(altcha_solve($bad, 1)));

    eq('missing-input-response', Altcha::verify_payload(''));
    eq('malformed', Altcha::verify_payload('!!!not base64'));
    eq('malformed', Altcha::verify_payload(base64_encode('{"algorithm":"SHA-256"}')));
    eq('malformed', Altcha::verify_payload(altcha_solve($c, 10000001)), 'number above the maximum');
    eq('malformed', Altcha::verify_payload(altcha_solve($c, '-1')), 'negative number');
});

t('altcha: an expired or undated challenge is refused even when correctly signed', function () {
    foreach (array('abcd?expires=' . (time() - 5), 'abcd', 'abcd?expires=0') as $salt) {
        $number = 7;
        $challenge = hash('sha256', $salt . $number);
        $c = array('algorithm' => 'SHA-256', 'challenge' => $challenge, 'maxnumber' => 100000, 'salt' => $salt, 'signature' => hash_hmac('sha256', $challenge, altcha_key()));
        eq('expired', Altcha::verify_payload(altcha_solve($c, $number)), 'salt ' . $salt);
    }
});

t('altcha provider through Captcha::check (no keys needed)', function () {
    set_settings(array('captcha_provider' => 'altcha'));
    ok(null !== Captcha::active_provider());
    $_POST = array('altcha' => altcha_solve(Altcha::challenge()));
    eq(null, Captcha::check('login', 'x'));
    captcha_reset();
    is_error_code('authlify_captcha', Captcha::check('login', 'x'), 'same payload again');
});

// ---------------------------------------------------------------- Remote providers.

t('turnstile: no token, wrong token, good token', function () {
    $calls = array();
    mock_siteverify($calls);
    set_settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret'));

    $_POST = array();
    is_error_code('authlify_captcha', Captcha::check('login'));
    eq(0, count($calls), 'no request without a token');

    captcha_reset();
    $_POST = array('cf-turnstile-response' => 'nope');
    is_error_code('authlify_captcha', Captcha::check('login'));

    captcha_reset();
    $_POST = array('cf-turnstile-response' => 'good');
    eq(null, Captcha::check('login'));
    contains('challenges.cloudflare.com', $calls[count($calls) - 1]['url']);
    eq('secret', $calls[count($calls) - 1]['body']['secret']);
    eq('203.0.113.90', $calls[count($calls) - 1]['body']['remoteip']);
});

t('provider without keys is not active (forms stay usable)', function () {
    set_settings(array('captcha_provider' => 'hcaptcha', 'captcha_site_key' => '', 'captcha_secret_key' => ''));
    eq(null, Captcha::active_provider());
    no(Captcha::form_enabled('login'));
});

t('hostname_ok: a token solved on another site is refused', function () {
    $calls = array();
    mock_siteverify($calls, array('hostname' => 'evil.example'));
    set_settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret'));
    $_POST = array('cf-turnstile-response' => 'good');
    $result = Captcha::provider()->verify('login');
    eq(false, $result['ok']);
    eq('hostname-mismatch', $result['reason']);
});

t('hostname_ok: this site\'s host passes; the allowed list is filterable; test keys are exempt', function () {
    $calls = array();
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    mock_siteverify($calls, array('hostname' => strtoupper($host)));
    set_settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret'));
    $_POST = array('cf-turnstile-response' => 'good');
    eq(true, Captcha::provider()->verify('login')['ok'], 'own host (case-insensitive)');

    remove_all_filters('pre_http_request', 1);
    $calls = array();
    mock_siteverify($calls, array('hostname' => 'shop.example'));
    add_test_filter('authlify_captcha_hostnames', function ($h) {
        $h[] = 'shop.example';

        return $h;
    });
    eq(true, Captcha::provider()->verify('login')['ok'], 'filtered host');

    set_settings(array('captcha_site_key' => '1x00000000000000000000AA'));
    remove_all_filters('authlify_captcha_hostnames');
    eq(true, Captcha::provider()->verify('login')['ok'], 'Cloudflare test key');
});

t('outage: fail open lets people in, fail closed refuses; both are logged', function () {
    mock_http(function () {
        return new WP_Error('http_request_failed', 'timeout');
    });
    set_settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 's', 'captcha_secret_key' => 'k', 'captcha_fail' => 'open'));
    $_POST = array('cf-turnstile-response' => 'whatever');
    eq(null, Captcha::check('login'));

    captcha_reset();
    set_settings(array('captcha_fail' => 'closed'));
    $e = Captcha::check('login');
    is_error_code('authlify_captcha', $e);
    contains('not responding', $e->get_error_message());

    captcha_reset();
    mock_http(function () {
        return http_response(503, 'down');
    });
    remove_all_filters('pre_http_request', 1);
    mock_http(function () {
        return http_response(502, '<html>');
    });
    is_error_code('authlify_captcha', Captcha::check('login'), '5xx counts as an outage');
});

t('SEC2-08: a 4xx or non-JSON answer and an oversized token fail closed, even with "fail open"', function () {
    set_settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 's', 'captcha_secret_key' => 'k', 'captcha_fail' => 'open', 'captcha_forms' => array('login'), 'captcha_mode' => 'always'));
    $calls = 0;
    mock_http(function () use (&$calls) {
        $calls++;
        return http_response(413, '<html>Request Entity Too Large</html>');
    });
    $_POST = array('cf-turnstile-response' => 'junk');
    is_error_code('authlify_captcha', Captcha::check('login'), '413 HTML is a failed check, not an outage');

    captcha_reset();
    remove_all_filters('pre_http_request', 1);
    mock_http(function () use (&$calls) {
        $calls++;
        return http_response(200, 'not json');
    });
    is_error_code('authlify_captcha', Captcha::check('login'), '200 without a verdict is a failed check');

    captcha_reset();
    $calls = 0;
    $_POST = array('cf-turnstile-response' => str_repeat('A', 9000));
    is_error_code('authlify_captcha', Captcha::check('login'), 'oversized token refused');
    eq(0, $calls, 'an oversized token is never sent to the provider');

    $provider = Captcha::active_provider();
    eq('unreachable', $provider->check_secret('k'), 'the key check still reports a bad answer as "could not check"');
});

t('test mode: failures are logged but never block', function () {
    set_settings(array('captcha_provider' => 'altcha', 'captcha_test_mode' => true));
    $_POST = array();
    eq(null, Captcha::check('login', 'x'));
});

t('allowlisted addresses never get a CAPTCHA', function () {
    set_settings(array('captcha_provider' => 'altcha', 'ip_allowlist' => '203.0.113.90'));
    no(Captcha::form_enabled('login'));
    eq(null, Captcha::check('login', 'x'));
});

t('forms not ticked are not checked', function () {
    set_settings(array('captcha_provider' => 'altcha', 'captcha_forms' => array('register')));
    no(Captcha::form_enabled('login'));
    ok(Captcha::form_enabled('register'));
});

// ---------------------------------------------------------------- After-failures mode.

t('after_failures: login needs the widget only after N failures from this IP', function () {
    set_settings(array('captcha_provider' => 'altcha', 'captcha_mode' => 'after_failures', 'captcha_after' => 2));
    $_POST = array('log' => 'x');
    no(Captcha::widget_required('login'), '0 failures');
    eq(null, Captcha::check('login', 'x'), 'no widget, no check');
    Limiter::record_failure('x', new WP_Error('incorrect_password'));
    no(Captcha::widget_required('login'), '1 failure');
    Limiter::record_failure('x', new WP_Error('incorrect_password'));
    ok(Captcha::widget_required('login'), '2 failures');
    captcha_reset();
    is_error_code('authlify_captcha', Captcha::check('login', 'x'), 'omitting the widget does not help');
});

t('after_failures: a targeted username always needs it; other forms always do', function () {
    set_settings(array('captcha_provider' => 'altcha', 'captcha_mode' => 'after_failures', 'user_attempts' => 2, 'limit_attempts' => 50));
    $user = make_user('subscriber');
    as_ip('198.51.100.40');
    Limiter::record_failure($user->user_login, new WP_Error('incorrect_password'));
    as_ip('198.51.100.41');
    Limiter::record_failure($user->user_email, new WP_Error('incorrect_password'));
    as_ip('198.51.100.42');
    ok(Captcha::widget_required('login', $user->user_login), 'targeted by username');
    ok(Captcha::widget_required('login', $user->user_email), 'targeted by email');
    no(Captcha::widget_required('login', 'someone-else'));
    ok(Captcha::widget_required('register'), 'registration always');
    ok(Captcha::widget_required('lostpassword'), 'lost password always');
});

t('CAPTCHA failures are not counted as failed logins', function () {
    set_settings(array('captcha_provider' => 'altcha'));
    as_ip('203.0.113.91');
    for ($i = 0; $i < 7; $i++) {
        captcha_reset();
        $err = Captcha::check('login', 'x');
        do_action('wp_login_failed', 'x', $err);
    }
    eq(0, Limiter::ip_failures('203.0.113.91'));
    eq(0, Limiter::locked_until('203.0.113.91'));
});

t('check_login: API requests, password-less posts and non-login contexts are not gated', function () {
    set_settings(array('captcha_provider' => 'altcha'));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $user = make_user('subscriber');
    eq($user, Captcha::check_login($user, $user->user_login, ''), 'no password (passkey, magic link)');
    eq($user, Captcha::check_login($user, $user->user_login, 'pw'), 'not the login page, no valid Woo nonce');
    $_POST = array('woocommerce-login-nonce' => 'forged', 'username' => $user->user_login);
    eq($user, Captcha::check_login($user, $user->user_login, 'pw'), 'a forged Woo nonce does not select a form');
    $locked = new WP_Error('authlify_locked', 'x');
    eq($locked, Captcha::check_login($locked, 'x', 'pw'), 'lockout errors pass through untouched');
});

t('SEC2-03: a WooCommerce login or lost-password post is checked whichever nonce field it uses', function () {
    set_settings(array('captcha_provider' => 'altcha', 'captcha_forms' => array('woo_login', 'woo_lostpassword'), 'captcha_mode' => 'always', 'honeypot' => false));
    $_SERVER['REQUEST_METHOD'] = 'POST';
    wp_set_current_user(0);
    $user = make_user('subscriber');
    $login = wp_create_nonce('woocommerce-login');
    $lost = wp_create_nonce('lost_password');

    foreach (array('woocommerce-login-nonce', '_wpnonce') as $field) {
        captcha_reset();
        $_POST = array('login' => 'Log in', 'username' => $user->user_login, 'password' => 'pw', $field => $login);
        $_REQUEST = $_POST;
        ok(is_wp_error(Captcha::check_login($user, $user->user_login, 'pw')), 'Woo login with ' . $field);
    }
    // The nonce in the query string, the fields in the body (WooCommerce reads $_REQUEST).
    captcha_reset();
    $_POST = array('login' => 'Log in', 'username' => $user->user_login, 'password' => 'pw');
    $_REQUEST = array_merge($_POST, array('_wpnonce' => $login));
    ok(is_wp_error(Captcha::check_login($user, $user->user_login, 'pw')), 'Woo login, nonce in the query string');

    foreach (array('woocommerce-lost-password-nonce', '_wpnonce') as $field) {
        captcha_reset();
        $_POST = array('wc_reset_password' => 'true', 'user_login' => $user->user_login, $field => $lost);
        $_REQUEST = $_POST;
        $errors = new WP_Error();
        Captcha::check_lostpassword($errors, $user);
        ok($errors->has_errors(), 'Woo lost password with ' . $field);
    }

    // A wrong nonce is not WooCommerce's form (WooCommerce ignores it too).
    captcha_reset();
    $_POST = array('login' => 'Log in', 'username' => $user->user_login, '_wpnonce' => 'forged');
    $_REQUEST = $_POST;
    eq($user, Captcha::check_login($user, $user->user_login, 'pw'), 'forged nonce');
});

t('SEC2-03: a WooCommerce login form posted to xmlrpc.php is not an API request', function () {
    $out = subprocess('define("XMLRPC_REQUEST", true); $old = get_option("authlify_settings"); \Authlify\Settings::update(array("captcha_provider" => "altcha", "captcha_forms" => array("woo_login"), "captcha_mode" => "always", "honeypot" => false)); \Authlify\Settings::flush(); wp_set_current_user(0); $_SERVER["REQUEST_METHOD"] = "POST"; $u = get_userdata(1); $_POST = $_REQUEST = array("login" => "Log in", "username" => $u->user_login, "woocommerce-login-nonce" => wp_create_nonce("woocommerce-login")); $r = \Authlify\Captcha\Captcha::check_login($u, $u->user_login, "pw"); $_POST = $_REQUEST = array(); $plain = \Authlify\Captcha\Captcha::check_login($u, $u->user_login, "pw"); update_option("authlify_settings", $old); echo (is_wp_error($r) ? "woo-checked" : "woo-skipped"), " ", (is_wp_error($plain) ? "api-checked" : "api-skipped");');
    contains('woo-checked', $out, 'Woo form on xmlrpc.php');
    contains('api-skipped', $out, 'a real XML-RPC login is still not gated');
});

t('AUTHLIFY_DISABLE_CAPTCHA switches every form off', function () {
    set_settings(array('captcha_provider' => 'altcha', 'honeypot' => true));
    $out = subprocess('define("AUTHLIFY_DISABLE_CAPTCHA", true); echo \Authlify\Captcha\Captcha::form_enabled("login") ? "on" : "off", has_filter("authenticate", array("Authlify\\\\Captcha\\\\Captcha", "check_login")) ? "-hooked" : "-unhooked";');
    // The constant must be defined before the plugin boots to unhook; form_enabled() checks it at call time.
    contains('off', $out);
});

// ---------------------------------------------------------------- Key validation on save.

t('keys: both keys required for a remote provider', function () {
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'x', 'captcha_secret_key' => ''), 'protection', 'captcha');
    is_error_code('authlify_captcha_keys', $r);
});

t('keys: without a preview answer the save is refused (unless test mode)', function () {
    $calls = array();
    mock_siteverify($calls);
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret', 'captcha_test_mode' => '0'), 'protection', 'captcha');
    is_error_code('authlify_captcha_unverified', $r);

    $user = make_user('administrator');
    wp_set_current_user($user->ID);
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret', 'captcha_test_mode' => '1'), 'protection', 'captcha');
    ok(is_array($r), 'saved in test mode');
    ok((bool) get_transient('authlify_captcha_warning_' . $user->ID), 'with a warning');
    delete_transient('authlify_captcha_warning_' . $user->ID);
});

t('keys: a preview token proves the keys; a rejected secret is refused', function () {
    $calls = array();
    mock_siteverify($calls);
    $_POST = array('cf-turnstile-response' => 'good');
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret'), 'protection', 'captcha');
    ok(is_array($r), 'good token: saved');
    eq('secret', $calls[0]['body']['secret'], 'checked with the submitted secret');

    $_POST = array('cf-turnstile-response' => 'expired-token');
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'bad-secret'), 'protection', 'captcha');
    is_error_code('authlify_captcha_secret', $r);

    $_POST = array('cf-turnstile-response' => 'expired-token');
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret'), 'protection', 'captcha');
    is_error_code('authlify_captcha_unverified', $r, 'stale preview answer');
});

t('keys: provider unreachable saves with a warning', function () {
    mock_http(function () {
        return new WP_Error('http_request_failed', 'down');
    });
    $user = make_user('administrator');
    wp_set_current_user($user->ID);
    $_POST = array('h-captcha-response' => 'tok');
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'hcaptcha', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret'), 'protection', 'captcha');
    ok(is_array($r));
    contains('could not be reached', (string) get_transient('authlify_captcha_warning_' . $user->ID));
    delete_transient('authlify_captcha_warning_' . $user->ID);
});

t('keys: unchanged keys are not re-checked; ALTCHA never calls out', function () {
    $calls = array();
    mock_siteverify($calls);
    set_settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret'));
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret'), 'protection', 'captcha');
    ok(is_array($r));
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'altcha'), 'protection', 'captcha');
    ok(is_array($r));
    eq(0, count($calls));
});

t('keys: leaving test mode re-checks keys that were never proven', function () {
    $calls = array();
    mock_siteverify($calls);
    set_settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret', 'captcha_test_mode' => true));
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'secret', 'captcha_test_mode' => '0'), 'protection', 'captcha');
    is_error_code('authlify_captcha_unverified', $r);
});

t('keys: values cleaned (forms whitelisted, captcha_after 1..100, threshold 0..1)', function () {
    $r = CaptchaAdmin::validate(array('captcha_provider' => 'none', 'captcha_forms' => array('login', 'evil', 'woo_login'), 'captcha_after' => 500, 'captcha_v3_threshold' => '1,7'), 'protection', 'captcha');
    eq(array('login', 'woo_login'), $r['captcha_forms']);
    eq(100, $r['captcha_after']);
    eq('1.0', $r['captcha_v3_threshold']);
});

t('altcha: difficulty is 1,000,000 by default and filterable (A-26)', function () {
    eq(1000000, Altcha::challenge()['maxnumber']);
    add_test_filter('authlify_altcha_max_number', function () {
        return 50000;
    });
    $c = Altcha::challenge();
    eq(50000, $c['maxnumber']);
    eq('', Altcha::verify_payload(altcha_solve($c)), 'a solved challenge verifies');
});

t('captcha: the providers\' public test keys are recognised (A-30)', function () {
    ok(\Authlify\Captcha\Provider::is_test_key('1x00000000000000000000AA'));
    ok(\Authlify\Captcha\Provider::is_test_key('6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI'));
    ok(\Authlify\Captcha\Provider::is_test_key('10000000-ffff-ffff-ffff-000000000001'));
    no(\Authlify\Captcha\Provider::is_test_key('0x4AAAAAAAreal-site-key'));
    set_settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => '1x00000000000000000000AA', 'captcha_secret_key' => '1x0000000000000000000000000000000AA', 'captcha_forms' => array('login')));
    $checks = CaptchaAdmin::dashboard_check(array());
    no($checks['captcha'][0], 'the dashboard does not count test keys as protected');
    contains('test keys', $checks['captcha'][2]);
});

t('captcha: an empty secret field keeps the stored secret, which is never printed (ARCH-31)', function () {
    set_settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site-A', 'captcha_secret_key' => 'secret-A'));
    $out = CaptchaAdmin::validate(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'site-A', 'captcha_secret_key' => ''), 'protection', 'captcha');
    ok(is_array($out), 'no error');
    no(array_key_exists('captcha_secret_key', $out), 'the stored secret is kept');
    ob_start();
    \Authlify\Admin\UI::form_start('protection', 'captcha');
    \Authlify\Admin\UI::input_row('captcha_secret_key', 'Secret key', '', array('type' => 'password', 'secret' => true));
    $html = ob_get_clean();
    not_contains('secret-A', $html, 'not in the page');
    contains('Leave empty to keep it', $html);
});
