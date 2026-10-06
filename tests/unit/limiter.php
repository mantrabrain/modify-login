<?php
/**
 * Limiter: counting, lockout, escalation, allow/deny lists, user_key, unlock
 * pass, application-password gate, ignored failure codes.
 *
 * Uses TEST-NET addresses (RFC 5737), never the address the suite runs from.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Security\Limiter;

function limiter_row($scope, $subject)
{
    global $wpdb;

    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Limiter::table() . ' WHERE scope = %s AND subject = %s', $scope, $subject));
}

function limiter_fail($times, $username = 'nobody-here', $error = null)
{
    for ($i = 0; $i < $times; $i++) {
        Limiter::record_failure($username, $error ? $error : new WP_Error('incorrect_password', 'x'));
    }
}

before_each(function () {
    set_settings(array(
        'limit_enabled' => true, 'limit_attempts' => 5, 'limit_window' => 15, 'lockout_minutes' => 15,
        'lockout_escalate' => true, 'limit_network' => false, 'user_attempts' => 10,
        'ip_source' => 'remote_addr', 'ip_allowlist' => '', 'ip_denylist' => '',
    ));
    $_POST = array('log' => 'x', 'pwd' => 'y');
});

t('counts failures per IP and locks on the Nth', function () {
    as_ip('203.0.113.10');
    limiter_fail(4);
    eq(4, Limiter::ip_failures('203.0.113.10'));
    eq(0, Limiter::locked_until('203.0.113.10'), 'not yet locked');
    eq(null, Limiter::gate_error('nobody-here'));

    limiter_fail(1);
    $until = Limiter::locked_until('203.0.113.10');
    ok($until > time() + 14 * 60 && $until <= time() + 15 * 60, 'locked for 15 minutes');
    is_error_code('authlify_locked', Limiter::gate_error('nobody-here'));
    eq(0, (int) limiter_row('ip', '203.0.113.10')->failures, 'counter reset on lock');

    as_ip('203.0.113.11');
    eq(null, Limiter::gate_error('nobody-here'), 'another IP is not affected');
});

t('authenticate filters refuse a locked IP before and after the password check', function () {
    as_ip('203.0.113.12');
    limiter_fail(5);
    $user = make_user('subscriber');
    is_error_code('authlify_locked', Limiter::check_before(null, $user->user_login, 'x'));
    is_error_code('authlify_locked', Limiter::check_after($user, $user->user_login, 'x'), 'a correct password does not get through');
    is_error_code('authlify_locked', wp_authenticate($user->user_login, 'wrong'), 'the real authenticate chain');
});

t('failures outside the window restart the count', function () {
    global $wpdb;
    as_ip('203.0.113.13');
    limiter_fail(4);
    $wpdb->update(Limiter::table(), array('last_failure' => time() - 16 * 60), array('scope' => 'ip', 'subject' => '203.0.113.13'));
    limiter_fail(1);
    eq(1, (int) limiter_row('ip', '203.0.113.13')->failures);
    eq(0, Limiter::locked_until('203.0.113.13'));
});

t('escalation: 15 min, then 1 h, 4 h, 24 h; forgiven after a day', function () {
    global $wpdb;
    as_ip('203.0.113.14');
    $expected = array(15, 60, 240, 1440, 1440);
    foreach ($expected as $n => $minutes) {
        limiter_fail(5);
        $row = limiter_row('ip', '203.0.113.14');
        $length = ((int) $row->locked_until - time()) / 60;
        ok(abs($length - $minutes) < 1.1, 'lockout ' . ($n + 1) . ' lasts ' . $minutes . ' min, got ' . round($length, 1));
        eq($n + 1, (int) $row->lockouts);
        // Lockout over (but recent): the next one escalates.
        $wpdb->update(Limiter::table(), array('locked_until' => time() - 5), array('scope' => 'ip', 'subject' => '203.0.113.14'));
    }

    $wpdb->update(Limiter::table(), array('locked_until' => time() - 2 * DAY_IN_SECONDS), array('scope' => 'ip', 'subject' => '203.0.113.14'));
    limiter_fail(5);
    $length = ((int) limiter_row('ip', '203.0.113.14')->locked_until - time()) / 60;
    ok(abs($length - 15) < 1.1, 'history older than a day is forgiven, got ' . round($length, 1));
});

t('escalation off: every lockout is the base length', function () {
    global $wpdb;
    set_settings(array('lockout_escalate' => false, 'lockout_minutes' => 20));
    as_ip('203.0.113.15');
    for ($i = 0; $i < 3; $i++) {
        limiter_fail(5);
        $length = ((int) limiter_row('ip', '203.0.113.15')->locked_until - time()) / 60;
        ok(abs($length - 20) < 1.1, 'lockout ' . ($i + 1) . ' = 20 min, got ' . round($length, 1));
        $wpdb->update(Limiter::table(), array('locked_until' => time() - 5), array('scope' => 'ip', 'subject' => '203.0.113.15'));
    }
});

t('protection off: failures are still counted but nothing is locked', function () {
    set_settings(array('limit_enabled' => false));
    as_ip('203.0.113.16');
    limiter_fail(8);
    eq(8, Limiter::ip_failures('203.0.113.16'));
    eq(0, Limiter::locked_until('203.0.113.16'));
    eq(null, Limiter::gate_error('x'));
});

t('allowlist: never counted, never refused (even with a lock on record)', function () {
    set_settings(array('ip_allowlist' => "198.51.100.0/24"));
    as_ip('198.51.100.20');
    limiter_fail(10);
    eq(0, Limiter::ip_failures('198.51.100.20'));
    global $wpdb;
    $wpdb->insert(Limiter::table(), array('scope' => 'ip', 'subject' => '198.51.100.20', 'failures' => 0, 'last_failure' => time(), 'locked_until' => time() + 3600, 'lockouts' => 1));
    eq(null, Limiter::gate_error('x'));
});

t('denylist: refused before the password check, whatever the password', function () {
    set_settings(array('ip_denylist' => "203.0.113.64/26\n2001:db8:dead::/48"));
    $user = make_user('subscriber');
    as_ip('203.0.113.70');
    is_error_code('authlify_denied', Limiter::gate_error($user->user_login));
    is_error_code('authlify_denied', wp_authenticate($user->user_login, 'anything'));
    as_ip('2001:db8:dead::9');
    is_error_code('authlify_denied', Limiter::gate_error('x'));
    as_ip('203.0.113.63');
    eq(null, Limiter::gate_error('x'), 'just outside the range');
});

t('allowlist beats denylist', function () {
    set_settings(array('ip_denylist' => '203.0.113.0/24', 'ip_allowlist' => '203.0.113.80'));
    as_ip('203.0.113.80');
    eq(null, Limiter::gate_error('x'));
});

t('gate applies to login submissions, API requests and any request naming an account', function () {
    as_ip('203.0.113.17');
    limiter_fail(5);
    $_POST = array();
    unset($_SERVER['PHP_AUTH_USER']);
    eq(null, Limiter::gate_error(''), 'a plain GET of the login page is not gated');
    is_error_code('authlify_locked', Limiter::gate_error('x'), 'a login with an empty $_POST (JSON body, other endpoint) is gated (A-06)');
    $_SERVER['PHP_AUTH_USER'] = 'someone';
    is_error_code('authlify_locked', Limiter::gate_error(''), 'HTTP auth is gated');
});

t('A-06: wp_authenticate() from a locked IP with an empty $_POST is refused, not a password oracle', function () {
    $user = make_user('subscriber');
    as_ip('203.0.113.30');
    limiter_fail(5);
    $_POST = array();
    is_error_code('authlify_locked', wp_authenticate($user->user_login, 'wrong-password'), 'wrong password');
    is_error_code('authlify_locked', wp_authenticate($user->user_login, 'also-wrong'), 'no incorrect_password leak');
});

t('user_key(): username and email of one account share a counter', function () {
    $user = make_user('subscriber');
    eq('id:' . $user->ID, Limiter::user_key($user->user_login));
    eq('id:' . $user->ID, Limiter::user_key($user->user_email));
    eq('id:' . $user->ID, Limiter::user_key(strtoupper($user->user_email)), 'email lookup is case-insensitive');
    eq('ghost-user', Limiter::user_key(' Ghost-User '), 'unknown names are normalised');
    eq('', Limiter::user_key(''));

    as_ip('203.0.113.18');
    Limiter::record_failure($user->user_login, new WP_Error('incorrect_password'));
    as_ip('203.0.113.19');
    Limiter::record_failure($user->user_email, new WP_Error('incorrect_password'));
    eq(2, Limiter::user_failures($user->user_login));
    eq(2, Limiter::user_failures($user->user_email));
});

t('a targeted username never locks the account, it is only flagged', function () {
    $user = make_user('subscriber');
    set_settings(array('user_attempts' => 6, 'limit_attempts' => 100));
    for ($i = 1; $i <= 6; $i++) {
        as_ip('198.51.100.' . (100 + $i));
        Limiter::record_failure($user->user_login, new WP_Error('incorrect_password'));
    }
    ok(Limiter::user_is_targeted($user->user_login));
    ok(Limiter::user_is_targeted($user->user_email));
    as_ip('198.51.100.200');
    eq(null, Limiter::gate_error($user->user_login), 'the owner can still log in from a clean address');
    ok(wp_authenticate($user->user_login, 'wrong') instanceof WP_Error);
});

t('network lockout: 3×N failures across a /24 lock the whole network', function () {
    set_settings(array('limit_network' => true, 'limit_attempts' => 3));
    for ($i = 1; $i <= 9; $i++) {
        as_ip('192.0.2.' . $i);
        // One failure each, so no single IP reaches its own limit.
        Limiter::record_failure('x', new WP_Error('incorrect_password'));
    }
    ok(Limiter::locked_until('192.0.2.200') > time(), 'another address in the /24 is locked');
    as_ip('192.0.3.1');
    eq(null, Limiter::gate_error('x'), 'the neighbouring /24 is not');
});

t('unlock pass: only the granted account may log in from the locked IP', function () {
    $alice = make_user('subscriber');
    $bob = make_user('subscriber');
    as_ip('203.0.113.21');
    limiter_fail(5);
    Limiter::grant_pass('203.0.113.21', $alice->ID);

    eq(null, Limiter::gate_error($alice->user_login), 'granted user by login');
    eq(null, Limiter::gate_error($alice->user_email), 'granted user by email');
    is_error_code('authlify_locked', Limiter::gate_error($bob->user_login), 'another user stays locked out');
    is_error_code('authlify_locked', Limiter::gate_error(''), 'no username stays locked out');
    is_error_code('authlify_locked', Limiter::gate_error('ghost'), 'unknown username stays locked out');

    as_ip('203.0.113.22');
    limiter_fail(5);
    is_error_code('authlify_locked', Limiter::gate_error($alice->user_login), 'the pass is bound to one IP');
    delete_transient('authlify_unlock_pass_' . md5('203.0.113.21'));
});

t('SEC2-04: an unlock pass is a bounded allowance: failures use it up, a success ends it, the account pause comes back', function () {
    set_settings(array('limit_enabled' => true, 'limit_attempts' => 3, 'limit_user_lock' => true, 'user_attempts' => 2));
    $alice = make_user('subscriber');
    as_ip('203.0.113.25');
    limiter_fail(3, 'someone');
    Limiter::grant_pass('203.0.113.25', $alice->ID);
    eq(null, Limiter::gate_error($alice->user_login), 'the pass lets the owner try');
    limiter_fail(2, $alice->user_login);
    eq(null, Limiter::gate_error($alice->user_login), 'one attempt left');
    limiter_fail(1, $alice->user_login);
    is_error_code('authlify_locked', Limiter::gate_error($alice->user_login), 'the allowance is used up: locked again');

    // A success ends the pass (single use).
    Limiter::grant_pass('203.0.113.25', $alice->ID);
    eq(null, Limiter::gate_error($alice->user_login));
    Limiter::record_success($alice->user_login, $alice);
    is_error_code('authlify_locked', Limiter::gate_error($alice->user_login), 'a second login needs a new link');

    // 3.0.x passes (a plain list of IDs) still work, with the same allowance.
    set_transient('authlify_unlock_pass_' . md5('203.0.113.25'), array($alice->ID), 600);
    eq(null, Limiter::gate_error($alice->user_login), 'old-format pass');
    limiter_fail(3, $alice->user_login);
    is_error_code('authlify_locked', Limiter::gate_error($alice->user_login), 'old-format pass is bounded too');
    delete_transient('authlify_unlock_pass_' . md5('203.0.113.25'));
});

t('ignored failure codes are never counted (CAPTCHA, lockout, deny, 2FA API, empty fields)', function () {
    as_ip('203.0.113.23');
    foreach (array('authlify_captcha', 'authlify_locked', 'authlify_denied', 'authlify_2fa_api', 'empty_username', 'empty_password') as $code) {
        limiter_fail(6, 'x', new WP_Error($code, 'x'));
    }
    eq(0, Limiter::ip_failures('203.0.113.23'));
    eq(0, Limiter::locked_until('203.0.113.23'));
});

t('a successful login clears the account counter but not the IP counter', function () {
    // Otherwise an attacker holding any account could log into it between
    // guesses and never reach the IP lockout.
    $user = make_user('subscriber');
    as_ip('203.0.113.24');
    limiter_fail(3, $user->user_login);
    Limiter::record_success($user->user_login, $user);
    eq(3, Limiter::ip_failures('203.0.113.24'), 'IP counter keeps running');
    eq(0, Limiter::user_failures($user->user_login), 'account counter cleared');
    limiter_fail(2, 'other-name');
    ok(Limiter::locked_until('203.0.113.24') > time(), 'guess, log in, guess still ends in a lockout');
});

t('unlock(ip) lifts the IP and its network, unlock() lifts everything', function () {
    set_settings(array('limit_network' => true, 'limit_attempts' => 1));
    as_ip('203.0.113.25');
    limiter_fail(3);
    ok(Limiter::locked_until('203.0.113.25') > time());
    Limiter::unlock('203.0.113.25');
    eq(0, Limiter::locked_until('203.0.113.25'));

    as_ip('203.0.113.26');
    limiter_fail(1);
    ok(Limiter::unlock() >= 1);
    eq(0, Limiter::locked_until('203.0.113.26'));
});

t('app-password gate: a locked IP gets authlify_locked added to the auth error', function () {
    $user = make_user('subscriber');
    as_ip('203.0.113.27');
    limiter_fail(5);
    $_POST = array();
    $_SERVER['PHP_AUTH_USER'] = $user->user_login;
    $errors = new WP_Error();
    Limiter::app_password_gate($errors, $user, array(), 'xxxx');
    contains('authlify_locked', $errors->get_error_codes());
    not_contains('<br', $errors->get_error_message('authlify_locked'), 'plain message for API clients');
});

t('app-password failure is counted like a login failure (once per request)', function () {
    $user = make_user('subscriber');
    as_ip('203.0.113.28');
    $_POST = array();
    $_SERVER['PHP_AUTH_USER'] = $user->user_login;
    Limiter::app_password_failed(new WP_Error('incorrect_password', 'x'));
    eq(1, Limiter::ip_failures('203.0.113.28'));
    eq(1, Limiter::user_failures($user->user_login));
});

t('lockout fires authlify_lockout and logs it', function () {
    $fired = null;
    add_test_filter('authlify_lockout', function ($scope, $subject, $until) use (&$fired) {
        $fired = array($scope, $subject);
    }, 10, 3);
    as_ip('203.0.113.29');
    limiter_fail(5);
    eq(array('ip', '203.0.113.29'), $fired);
});

t('attempts-left hint appears with 2 and 1 attempts left only', function () {
    as_ip('203.0.113.30');
    limiter_fail(2);
    $e = Limiter::attempts_left_hint(new WP_Error('incorrect_password', 'x'));
    no(in_array('authlify_attempts_left', $e->get_error_codes(), true), '3 left: no hint');
    limiter_fail(1);
    $e = Limiter::attempts_left_hint(new WP_Error('incorrect_password', 'x'));
    contains('authlify_attempts_left', $e->get_error_codes());
});

t('A-07: IPv6 addresses are counted per /64, so rotating inside one /64 still locks', function () {
    for ($i = 1; $i <= 5; $i++) {
        as_ip('2001:db8:77:1::' . dechex($i));
        Limiter::record_failure('x', new WP_Error('incorrect_password'));
    }
    ok(Limiter::locked_until('2001:db8:77:1::ffff') > time(), 'another address in the /64 is locked');
    as_ip('2001:db8:77:1::abcd');
    is_error_code('authlify_locked', Limiter::gate_error('x'));
    as_ip('2001:db8:77:2::1');
    eq(null, Limiter::gate_error('x'), 'the next /64 is not');
    eq('2001:db8:77:1::/64', Limiter::ip_key('2001:db8:77:1::5'));
    eq('203.0.113.9', Limiter::ip_key('203.0.113.9'), 'IPv4 keeps the address');
    eq(1, Limiter::unlock('2001:db8:77:1::5') > 0 ? 1 : 0, 'unlock by any address in the /64');
    eq(0, Limiter::locked_until('2001:db8:77:1::5'));
});

t('A-07: network lockouts use /48 for IPv6', function () {
    set_settings(array('limit_network' => true, 'limit_attempts' => 3));
    for ($i = 1; $i <= 9; $i++) {
        as_ip('2001:db8:5:' . dechex($i) . '::1');
        Limiter::record_failure('x', new WP_Error('incorrect_password'));
    }
    ok(Limiter::locked_until('2001:db8:5:ff::1') > time(), 'the /48 is locked');
    eq(0, Limiter::locked_until('2001:db8:6::1'), 'the neighbouring /48 is not');
});

t('A-07: a botnet guessing one account is paused at 2x the targeted threshold; the owner still gets in from a known address or with a pass', function () {
    $user = make_user('administrator');
    set_settings(array('user_attempts' => 5, 'limit_attempts' => 100));
    for ($i = 1; $i <= 10; $i++) {
        as_ip('198.51.100.' . (10 + $i));
        Limiter::record_failure($user->user_login, new WP_Error('incorrect_password'));
    }
    ok(Limiter::account_paused_until($user->user_login) > time(), 'paused');
    ok(Limiter::account_paused_until($user->user_email) > time(), 'by email too');
    as_ip('198.51.100.99');
    $_POST = array('log' => $user->user_login, 'pwd' => 'x');
    $err = Limiter::gate_error($user->user_login);
    is_error_code('authlify_locked', $err, 'a new address is refused');
    contains('paused', $err->get_error_message());

    // Known address: the owner logged in from here before.
    global $wpdb;
    $wpdb->insert(\Authlify\Log\Log::table(), array('blog_id' => get_current_blog_id(), 'created_at' => gmdate('Y-m-d H:i:s'), 'event' => 'login_success', 'user_id' => $user->ID, 'username' => $user->user_login, 'ip' => '192.0.2.77'));
    as_ip('192.0.2.77');
    eq(null, Limiter::gate_error($user->user_login), 'known address');
    $wpdb->delete(\Authlify\Log\Log::table(), array('ip' => '192.0.2.77'));

    // Emailed unlock pass.
    as_ip('198.51.100.98');
    Limiter::grant_pass('198.51.100.98', $user->ID);
    eq(null, Limiter::gate_error($user->user_login), 'unlock pass');

    // Other accounts are untouched.
    $other = make_user('subscriber');
    as_ip('198.51.100.97');
    eq(null, Limiter::gate_error($other->user_login));
    ok(count(Limiter::active_lockouts(true)) > count(Limiter::active_lockouts()), 'listed with accounts, not with addresses');
});

t('account pause is off with protection off or the setting off', function () {
    $user = make_user('subscriber');
    set_settings(array('user_attempts' => 2, 'limit_attempts' => 100, 'limit_user_lock' => false));
    for ($i = 1; $i <= 6; $i++) {
        as_ip('198.51.100.' . (30 + $i));
        Limiter::record_failure($user->user_login, new WP_Error('incorrect_password'));
    }
    eq(0, Limiter::account_paused_until($user->user_login));
    as_ip('198.51.100.60');
    eq(null, Limiter::gate_error($user->user_login));
});

t('unlock pass: two accounts behind one address can each use their own', function () {
    $alice = make_user('subscriber');
    $bob = make_user('subscriber');
    $carol = make_user('subscriber');
    as_ip('203.0.113.40');
    limiter_fail(5);
    Limiter::grant_pass('203.0.113.40', $alice->ID);
    Limiter::grant_pass('203.0.113.40', $bob->ID);
    eq(null, Limiter::gate_error($alice->user_login), 'alice');
    eq(null, Limiter::gate_error($bob->user_login), 'bob (does not replace alice)');
    is_error_code('authlify_locked', Limiter::gate_error($carol->user_login), 'carol still locked');
});

t('a lockout is started once: a concurrent failure that also crossed the limit does not escalate again', function () {
    as_ip('203.0.113.41');
    limiter_fail(5);
    $row = limiter_row('ip', '203.0.113.41');
    eq(1, (int) $row->lockouts);
    // A request that passed the gate before the lock landed.
    $m = new ReflectionMethod(Limiter::class, 'lock');
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); } // No-op since PHP 8.1, deprecated in 8.5.
    $m->invoke(null, 'ip', '203.0.113.41', 'x');
    eq(1, (int) limiter_row('ip', '203.0.113.41')->lockouts, 'no second escalation step');
});
