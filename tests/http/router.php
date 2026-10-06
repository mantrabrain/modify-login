<?php
/**
 * Router over HTTP: the full leak/bypass matrix (tests/leak-suite.sh) with
 * pretty and plain permalinks, postpass smuggling, blocked-response modes, and
 * the confirm-before-it-applies slug change.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const SLUG = 'test-door';

function leak_suite(array $args)
{
    $cmd = escapeshellarg(dirname(__DIR__) . '/leak-suite.sh') . ' ' . escapeshellarg(BASE) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $out, $status);

    return array($status, $out);
}

function assert_suite_green(array $args)
{
    list($status, $out) = leak_suite($args);
    $bad = array_values(array_filter($out, function ($l) {
        return (bool) preg_match('/\s(LEAK|FAIL)\s/', $l);
    }));
    eq(0, $status, 'leak-suite exit status; failing rows: ' . implode(' | ', array_map('trim', $bad)) . ' | ' . end($out));
    ok((bool) preg_match('/(\d+) passed/', end($out), $m) && (int) $m[1] >= 35, 'the suite ran its probes: ' . end($out));
}

before_all(function () {
    $GLOBALS['admin'] = http_user('administrator');
    settings(array('login_slug' => SLUG, 'block_wp_login' => true, 'blocked_response' => '404', 'pending_slug' => '', 'pending_token' => '', 'pending_expires' => 0, 'captcha_provider' => 'none', 'honeypot' => false, 'force_login' => false));
});

t('leak suite: pretty permalinks, with a real login and logout through the slug', function () {
    $a = $GLOBALS['admin'];
    assert_suite_green(array(SLUG, '--user', $a['login'], '--pass', $a['pass']));
});

t('leak suite: plain permalinks (login URL is /?slug)', function () {
    wp_cli("rewrite structure '' --quiet");
    try {
        $a = $GLOBALS['admin'];
        eq(200, get(BASE . '/?' . SLUG)->code, 'the /?slug URL works');
        assert_suite_green(array(SLUG, '--plain', '--user', $a['login'], '--pass', $a['pass']));
    } finally {
        wp_cli("rewrite structure '/%postname%/' --quiet");
    }
});

t('custom URL: login form, no-store, noindex', function () {
    $res = get(login_url(SLUG));
    eq(200, $res->code);
    contains('id="loginform"', $res->body);
    contains('no-store', implode(',', $res->headers['cache-control']));
    contains('noindex', strtolower(implode(',', isset($res->headers['x-robots-tag']) ? $res->headers['x-robots-tag'] : array())));
    contains('action="' . login_url(SLUG), str_replace('&#038;', '&', $res->body), 'the form posts to the custom URL');
});

t('slug matching is exact: case and encoding variants reach the form, prefixes do not', function () {
    eq(200, get(BASE . '/TEST-DOOR/')->code, 'upper case');
    eq(200, get(BASE . '/test%2Ddoor/')->code, 'encoded hyphen');
    $res = get(BASE . '/test-doorx/');
    eq(404, $res->code, 'longer path');
    not_contains('loginform', $res->body);
    $res = get(BASE . '/test-door/extra/');
    not_contains('id="loginform"', $res->body, 'sub-path');
});

t('postpass: a real password-protected post form still works while hidden', function () {
    $post = (int) wp_eval('echo wp_insert_post(array("post_title" => "Pw test", "post_status" => "publish", "post_password" => "letmein", "post_content" => "secret body"));');
    try {
        $permalink = wp_eval('echo get_permalink(' . $post . ');');
        $res = post(BASE . '/wp-login.php?action=postpass', array('post_password' => 'letmein'), array('headers' => array('Referer: ' . $permalink)));
        eq(302, $res->code, 'core answers with a redirect');
        eq($permalink, $res->location, 'back to the post');
        ok(isset($res->cookies['wp-postpass_' . wp_eval('echo COOKIEHASH;')]), 'post password cookie set');
        not_contains(SLUG, $res->location . $res->body);
        $page = get($permalink, array('cookies' => live_cookies($res)));
        contains('secret body', $page->body, 'the cookie opens the post');
    } finally {
        wp_eval('wp_delete_post(' . $post . ', true);');
    }
});

t('postpass smuggling: extra query args, GET, missing password, array password are all blocked', function () {
    $cases = array(
        array('POST', '/wp-login.php?action=postpass&checkemail=confirm', array('post_password' => 'x')),
        array('POST', '/wp-login.php?action=postpass&key=x&login=admin', array('post_password' => 'x')),
        array('POST', '/wp-login.php?action=postpass&action2=rp', array('post_password' => 'x')),
        array('POST', '/wp-login.php?checkemail=confirm', array('action' => 'postpass', 'post_password' => 'x')),
        array('POST', '/wp-login.php?action=rp', array('action' => 'postpass', 'post_password' => 'x')),
        array('POST', '/wp-login.php?action=postpass', array()),
        array('POST', '/wp-login.php?action=postpass', 'post_password[]=x'),
        array('GET', '/wp-login.php?action=postpass&post_password=x', null),
        array('POST', '/wp-login.php?action=register', array('action' => 'postpass', 'post_password' => 'x')),
    );
    foreach ($cases as $c) {
        $res = request($c[0], BASE . $c[1], null === $c[2] ? array() : array('body' => $c[2]));
        $where = $c[0] . ' ' . $c[1] . ' ' . (is_array($c[2]) ? http_build_query($c[2]) : (string) $c[2]);
        not_contains('id="loginform"', $res->body, $where . ': form served');
        not_contains(SLUG, $res->location . $res->body, $where . ': slug leaked');
        ok(404 === $res->code, $where . ': expected the 404 page, got ' . $res->code);
    }
});

t('blocked response 403 and redirect modes', function () {
    settings(array('blocked_response' => '403'));
    $res = get(BASE . '/wp-login.php');
    eq(403, $res->code);
    not_contains(SLUG, $res->body);

    settings(array('blocked_response' => 'redirect', 'blocked_redirect_url' => BASE . '/landing/'));
    $res = get(BASE . '/wp-login.php?action=register');
    eq(302, $res->code);
    eq(BASE . '/landing/', $res->location);
    $res = get(BASE . '/wp-admin/');
    eq(BASE . '/landing/', $res->location, 'wp-admin too');

    settings(array('blocked_response' => '404', 'blocked_redirect_url' => ''));
});

t('hiding off (block_wp_login = false): wp-login.php works alongside the slug', function () {
    settings(array('block_wp_login' => false));
    contains('id="loginform"', get(BASE . '/wp-login.php')->body);
    contains('id="loginform"', get(login_url(SLUG))->body);
    settings(array('block_wp_login' => true));
});

t('a signed-in user opening the slug goes to the dashboard (no form)', function () {
    $s = session_for($GLOBALS['admin']['id']);
    $res = get(login_url(SLUG), array('cookies' => $s['cookies']));
    eq(302, $res->code);
    eq(BASE . '/wp-admin/', $res->location);
    $res = get(login_url(SLUG) . '?redirect_to=' . rawurlencode('https://evil.example/'), array('cookies' => $s['cookies']));
    not_contains('evil.example', $res->location, 'no open redirect');
});

t('slug change: the new slug works next to the old one until confirmed', function () {
    $url = wp_eval('wp_set_current_user(' . $GLOBALS['admin']['id'] . '); echo \Authlify\Login\Recovery::start_pending("new-door");');
    ok((bool) preg_match('#/new-door/\?authlify_confirm=([A-Za-z0-9]+)#', $url, $m), 'confirmation URL on the new slug: ' . $url);
    contains('id="loginform"', get(login_url(SLUG))->body, 'old slug still works');
    contains('id="loginform"', get(BASE . '/new-door/')->body, 'new slug already works');
    eq(SLUG, wp_eval('echo \Authlify\Settings::get("login_slug");'), 'not applied yet');
    $GLOBALS['confirm'] = array($url, $m[1]);
});

t('slug change: a logged-out visitor opening the link is sent to log in first, nothing changes', function () {
    list($url) = $GLOBALS['confirm'];
    $res = get($url);
    eq(302, $res->code);
    contains('/new-door/', $res->location);
    contains('redirect_to=', $res->location);
    eq(SLUG, wp_eval('echo \Authlify\Settings::get("login_slug");'));
});

t('slug change: a wrong token or a non-admin does not confirm', function () {
    list($url) = $GLOBALS['confirm'];
    $admin = session_for($GLOBALS['admin']['id']);
    get(BASE . '/new-door/?authlify_confirm=wrongtoken', array('cookies' => $admin['cookies']));
    eq(SLUG, wp_eval('echo \Authlify\Settings::get("login_slug");'), 'wrong token');

    $editor = http_user('editor');
    $s = session_for($editor['id']);
    get($url, array('cookies' => $s['cookies']));
    eq(SLUG, wp_eval('echo \Authlify\Settings::get("login_slug");'), 'editor');

    get(login_url(SLUG) . '?authlify_confirm=' . $GLOBALS['confirm'][1], array('cookies' => $admin['cookies']));
    eq(SLUG, wp_eval('echo \Authlify\Settings::get("login_slug");'), 'right token on the old slug');
});

t('slug change: the admin opening the link applies it; the old slug stops working', function () {
    list($url) = $GLOBALS['confirm'];
    $s = session_for($GLOBALS['admin']['id']);
    $res = get($url, array('cookies' => $s['cookies']));
    eq(302, $res->code);
    contains('slug_confirmed', $res->location);
    eq('new-door', wp_eval('echo \Authlify\Settings::get("login_slug");'));
    eq('', wp_eval('echo \Authlify\Settings::get("pending_slug");'));
    eq(404, get(login_url(SLUG))->code, 'old slug is now a 404');
    contains('id="loginform"', get(BASE . '/new-door/')->body);
    // Reusing the link does nothing more.
    $res = get($url, array('cookies' => $s['cookies']));
    eq('new-door', wp_eval('echo \Authlify\Settings::get("login_slug");'));
    settings(array('login_slug' => SLUG));
});

t('slug change: an expired pending slug stops working and cannot be confirmed', function () {
    $url = wp_eval('echo \Authlify\Login\Recovery::start_pending("late-door");');
    settings(array('pending_expires' => time() - 5));
    eq(404, get(BASE . '/late-door/')->code);
    $s = session_for($GLOBALS['admin']['id']);
    get($url, array('cookies' => $s['cookies']));
    eq(SLUG, wp_eval('echo \Authlify\Settings::get("login_slug");'));
    settings(array('pending_slug' => '', 'pending_token' => '', 'pending_expires' => 0));
});

t('login URL helpers point at the slug; wp-login.php links are rewritten', function () {
    eq(BASE . '/' . SLUG . '/', wp_eval('echo wp_login_url();'));
    eq(BASE . '/' . SLUG . '/?action=lostpassword', wp_eval('echo wp_lostpassword_url();'));
    contains('/' . SLUG . '/?action=logout', wp_eval('wp_set_current_user(' . $GLOBALS['admin']['id'] . '); echo wp_logout_url();'));
});

t('SEC2-01: visitors are never redirected to the slug (comment redirect_to, /index.php/wp-login.php)', function () {
    $post = (int) wp_eval('echo wp_insert_post(array("post_title" => "Comment leak", "post_status" => "publish", "comment_status" => "open", "post_content" => "x"));');
    try {
        $res = post(BASE . '/wp-comments-post.php', array('comment_post_ID' => $post, 'author' => 'probe', 'email' => 'probe@example.test', 'comment' => 'leak ' . uniqid(), 'redirect_to' => BASE . '/wp-login.php'), array('ip' => '198.51.100.71'));
        eq(302, $res->code, 'the comment was accepted');
        not_contains(SLUG, $res->location, 'comment redirect');
        contains('/wp-login.php', $res->location, 'the redirect stays on the hidden wp-login.php');
        $res = post(BASE . '/wp-comments-post.php', array('comment_post_ID' => $post, 'author' => 'probe', 'email' => 'probe2@example.test', 'comment' => 'leak ' . uniqid(), 'redirect_to' => BASE . '/wp-login.php?action=lostpassword'), array('ip' => '198.51.100.72'));
        not_contains(SLUG, $res->location, 'comment redirect with an action');
    } finally {
        wp_eval('wp_delete_post(' . $post . ', true);');
    }
    foreach (array('/index.php/wp-login.php', '/index.php//wp-login.php', '/index.php/wp-login.php/', '/index.php/wp-login.php?action=lostpassword') as $path) {
        $res = get(BASE . $path);
        not_contains(SLUG, $res->location . $res->body, $path);
        not_contains('id="loginform"', $res->body, $path);
    }
});

t('SEC2-01: login-page flows still redirect within the slug (lost password, logout, logged-in redirects)', function () {
    $u = http_user('subscriber');
    $res = post(login_url(SLUG) . '?action=lostpassword', array('user_login' => $u['login'], 'wp-submit' => 'Get New Password'), array('ip' => '198.51.100.73'));
    eq(302, $res->code, 'lost password answered with a redirect');
    contains('/' . SLUG . '/?checkemail=confirm', $res->location, 'lost password goes to the check-email screen on the slug');
    $s = session_for($u['id'], array('log-out'));
    $res = get(login_url(SLUG) . '?action=logout&_wpnonce=' . $s['nonces']['log-out'], array('cookies' => $s['cookies']));
    eq(302, $res->code);
    contains('/' . SLUG . '/?loggedout=true', $res->location, 'logout lands on the slug');
    // A logged-in user is still sent to the slug (they can see it anyway).
    eq(login_url(SLUG), wp_eval('wp_set_current_user(' . $u['id'] . '); echo \Authlify\Login\Router::filter_redirect(get_option("siteurl") . "/wp-login.php");'));
});

after_all(function () {
    // tests/leak-suite.sh posts one comment per run.
    wp_eval('foreach (get_comments(array("author_email" => "leak-suite@authlify.invalid", "status" => "all", "fields" => "ids")) as $id) { wp_delete_comment($id, true); }');
});
