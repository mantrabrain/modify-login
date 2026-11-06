<?php
/**
 * 3.2.0 free features over HTTP:
 * - C3-05: the login URL is not handed to low-privilege members (logout link,
 *   session-expired popup, /login, redirects), and logout keeps working;
 * - the username denylist on real registration and login forms;
 * - C3-01: Wordfence import, ending in a real two-factor login with a code
 *   worked out from the original bytes, not from anything Authlify stored;
 * - Limit Login Attempts import; Paid Memberships Pro and LearnDash forms.
 *
 * Real plugins run when their folder is in the test site's plugins directory
 * (otherwise those tests skip): wordfence-login-security, wordfence,
 * limit-login-attempts, paid-memberships-pro. LearnDash is paid, so its test
 * uses a stand-in written from LearnDash's developer documentation: it proves
 * our side of the hooks, not LearnDash itself.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const RSLUG = 'r3-door-77';

/**
 * wp_eval() that ignores notices old plugins print while WP-CLI loads them
 * (Limit Login Attempts on PHP 8, Paid Memberships Pro's early translations).
 */
function r3_e($php)
{
    $mark = '@@R3@@';
    $out = shell_exec(escapeshellarg(WPCLI) . ' eval ' . escapeshellarg('echo "' . $mark . '"; ' . $php . '; echo "' . $mark . '";') . ' 2>&1');

    return preg_match('/' . $mark . '(.*)' . $mark . '/s', (string) $out, $m) ? trim($m[1]) : trim((string) $out);
}

function r3_json($php)
{
    $data = json_decode(r3_e($php), true);
    if (null === $data) {
        fail('no JSON from: ' . substr($php, 0, 100));
    }

    return $data;
}

function r3_logged_in($res)
{
    foreach (live_cookies($res) as $name => $v) {
        if (0 === strpos($name, 'wordpress_logged_in_')) {
            return true;
        }
    }

    return false;
}

function r3_has($plugin)
{
    return is_dir(SITE_DIR . '/wp-content/plugins/' . $plugin);
}

function r3_text($html)
{
    return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
}

function r3_field($html, $name)
{
    return preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $m) || preg_match('/value="([^"]*)"[^>]*name="' . preg_quote($name, '/') . '"/', $html, $m) ? html_entity_decode($m[1]) : '';
}

/** RFC 6238 from the raw bytes given as hex, independent of Authlify's code. */
function r3_totp_hex($hex, $step = null)
{
    $step = null === $step ? (int) floor(time() / 30) : (int) $step;
    $hash = hash_hmac('sha1', pack('N*', 0, $step), hex2bin($hex), true);
    $o = ord($hash[19]) & 0xf;
    $n = unpack('N', substr($hash, $o, 4));

    return str_pad((string) (($n[1] & 0x7fffffff) % 1000000), 6, '0', STR_PAD_LEFT);
}

function add_redirect($link, $to)
{
    return $link . '&redirect_to=' . rawurlencode($to);
}

function r3_logout_link($html)
{
    return preg_match('/href=[\'"]([^\'"]*(?:action=logout|authlify_logout)[^\'"]*)[\'"]/', $html, $m) ? html_entity_decode(str_replace('&#038;', '&', $m[1])) : '';
}

before_all(function () {
    settings(array(
        'login_slug' => RSLUG, 'block_wp_login' => true, 'limit_enabled' => true, 'limit_attempts' => 5, 'lockout_minutes' => 15, 'lockout_escalate' => true,
        'captcha_provider' => 'none', 'honeypot' => false, 'ip_allowlist' => '', 'ip_denylist' => '', 'auto_block_lockouts' => 0, 'twofa_enabled' => true,
        'twofa_methods' => array('totp', 'backup', 'passkey'), 'log_enabled' => true, 'username_denylist' => '', 'username_deny_login' => false, 'username_deny_lockout' => false,
    ));
    r3_e('update_option("users_can_register", 1);');
    $GLOBALS['member'] = http_user('subscriber');
    $GLOBALS['post_id'] = (int) r3_e('echo wp_insert_post(array("post_title" => "r3 comments", "post_status" => "publish", "comment_status" => "open"));');
});

after_all(function () {
    r3_e('update_option("users_can_register", 0); wp_delete_post(' . (int) $GLOBALS['post_id'] . ', true);');
    settings(array('login_slug' => '', 'username_denylist' => '', 'username_deny_login' => false, 'username_deny_lockout' => false));
    @unlink(SITE_DIR . '/wp-content/mu-plugins/authlify-test-ld-stub.php');
    foreach (array('wordfence-login-security', 'wordfence', 'limit-login-attempts', 'paid-memberships-pro', 'woocommerce') as $p) {
        if (r3_has($p)) {
            wp_cli('plugin deactivate ' . $p);
        }
    }
    r3_e('global $wpdb; $wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}wfls_2fa_secrets"); foreach (array("limit_login_options", "limit_login_allowed_retries", "limit_login_lockout_duration", "limit_login_valid_duration") as $o) { delete_option($o); }');
});

/* ---------------------------------------------------------- C3-05 */

t('C3-05: a subscriber sees no login URL on the front end or in wp-admin', function () {
    $s = session_for($GLOBALS['member']['id']);
    foreach (array('/', '/wp-admin/profile.php', '/wp-admin/') as $path) {
        $res = get(BASE . $path, array('cookies' => $s['cookies'], 'ip' => '203.0.113.60'));
        eq(200, $res->code, $path);
        not_contains(RSLUG, $res->body, $path . ' (logout link, session-expired popup)');
    }
    contains('authlify_logout', get(BASE . '/wp-admin/profile.php', array('cookies' => $s['cookies'], 'ip' => '203.0.113.60'))->body);
});

t('C3-05: /login and a comment redirect_to do not give a subscriber the login URL; /admin still works', function () {
    $s = session_for($GLOBALS['member']['id']);
    $o = array('cookies' => $s['cookies'], 'ip' => '203.0.113.61', 'follow' => false);
    $res = get(BASE . '/login', $o);
    not_contains(RSLUG, json_encode($res->headers), '/login headers');
    not_contains(RSLUG, $res->body, '/login body');
    ok(in_array($res->code, array(404, 301), true), '/login is a 404, or core\'s guess at a similar page, never the login page: ' . $res->code);
    not_contains('id="loginform"', $res->body);

    $res = get(BASE . '/admin', $o);
    eq(302, $res->code);
    contains('/wp-admin', (string) $res->location, '/admin still goes to the dashboard');

    $res = post(BASE . '/wp-comments-post.php', array('comment' => 'r3 ' . uniqid(), 'comment_post_ID' => $GLOBALS['post_id'], 'redirect_to' => BASE . '/wp-login.php'), $o);
    not_contains(RSLUG, (string) $res->location, 'comment redirect: ' . $res->location);
});

t('C3-05: logout through the member link ends the session and lands on the home page, or on redirect_to', function () {
    $u = http_user('subscriber');
    $s = session_for($u['id']);
    $link = r3_logout_link(get(BASE . '/wp-admin/profile.php', array('cookies' => $s['cookies'], 'ip' => '203.0.113.62'))->body);
    contains('authlify_logout', $link);
    not_contains(RSLUG, $link);

    $res = get($link, array('cookies' => $s['cookies'], 'ip' => '203.0.113.62', 'follow' => false));
    eq(302, $res->code);
    eq(rtrim(BASE, '/') . '/', (string) $res->location);
    eq(0, (int) r3_e('echo count(WP_Session_Tokens::get_instance(' . $u['id'] . ')->get_all());'), 'session destroyed');
    $res = get(BASE . '/wp-admin/profile.php', array('cookies' => $s['cookies'], 'ip' => '203.0.113.62', 'follow' => false));
    ok(200 !== $res->code, 'wp-admin closed afterwards: ' . $res->code);

    // redirect_to, on-site only: a real link made for this session.
    foreach (array(array(BASE . '/sample-page/', BASE . '/sample-page/'), array('https://evil.example/', rtrim(BASE, '/') . '/')) as $case) {
        $s = session_for($u['id']);
        $link = r3_logout_link(get(BASE . '/wp-admin/profile.php', array('cookies' => $s['cookies'], 'ip' => '203.0.113.62'))->body);
        $res = get(add_redirect($link, $case[0]), array('cookies' => $s['cookies'], 'ip' => '203.0.113.62', 'follow' => false));
        eq($case[1], (string) $res->location, 'redirect_to ' . $case[0]);
    }
});

t('C3-05: a logout link without a valid nonce does nothing', function () {
    $u = http_user('subscriber');
    $s = session_for($u['id']);
    $res = get(BASE . '/?authlify_logout=1&_wpnonce=bad', array('cookies' => $s['cookies'], 'ip' => '203.0.113.63', 'follow' => false));
    eq(403, $res->code);
    not_contains(RSLUG, $res->body, 'the "are you sure" page');
    ok((int) r3_e('echo count(WP_Session_Tokens::get_instance(' . $u['id'] . ')->get_all());') > 0, 'still logged in');
    eq(302, get(BASE . '/?authlify_logout=1', array('ip' => '203.0.113.63', 'follow' => false))->code, 'logged-out visitor goes home');
});

t('C3-05: staff keep the normal logout link and session-expired popup', function () {
    foreach (array('administrator', 'editor') as $role) {
        $u = http_user($role);
        $s = session_for($u['id']);
        $res = get(BASE . '/wp-admin/profile.php', array('cookies' => $s['cookies'], 'ip' => '203.0.113.64'));
        contains(RSLUG, $res->body, $role);
        not_contains('authlify_logout', $res->body, $role);
        $link = r3_logout_link($res->body);
        $res = get($link, array('cookies' => $s['cookies'], 'ip' => '203.0.113.64', 'follow' => false));
        eq(302, $res->code, $role . ' logs out');
        contains('loggedout=true', (string) $res->location, $role);
    }
});

t('C3-05: real WooCommerce: a customer\'s My Account logout works and never shows the login URL', function () {
    if (!r3_has('woocommerce')) {
        skip('woocommerce is not installed on the test site');
    }
    $u = http_user('subscriber');
    r3_e('$u = get_userdata(' . $u['id'] . '); $u->set_role("subscriber");');
    wp_cli('plugin activate woocommerce');
    get(BASE . '/');
    try {
        r3_e('if (!get_option("woocommerce_myaccount_page_id") || !get_post(get_option("woocommerce_myaccount_page_id"))) { $id = wp_insert_post(array("post_title" => "My account", "post_name" => "my-account", "post_type" => "page", "post_status" => "publish", "post_content" => "[woocommerce_my_account]")); update_option("woocommerce_myaccount_page_id", $id); } update_option("woocommerce_logout_endpoint", "customer-logout"); flush_rewrite_rules();');
        $url = r3_e('echo get_permalink(get_option("woocommerce_myaccount_page_id"));');
        $s = session_for($u['id']);
        $o = array('cookies' => $s['cookies'], 'ip' => '203.0.113.65', 'follow' => false);
        $page = get($url, $o);
        eq(200, $page->code, 'My Account');
        not_contains(RSLUG, $page->body, 'My Account page');
        ok(preg_match('/href="([^"]*customer-logout[^"]*)"/', $page->body, $m) === 1, 'Woo logout link');
        $res = get(html_entity_decode($m[1]), $o);
        // Woo ends the session itself on its endpoint and goes back to My Account.
        eq(302, $res->code);
        not_contains(RSLUG, (string) $res->location, 'Woo redirect: ' . $res->location);
        contains('my-account', (string) $res->location, 'back to My Account');
        eq(0, (int) r3_e('echo count(WP_Session_Tokens::get_instance(' . $u['id'] . ')->get_all());'), 'session ended');
    } finally {
        wp_cli('plugin deactivate woocommerce');
    }
});

t('F3-08, F3-10: real WooCommerce: the attempt that locks says so, and the unlock email from My Account does not name the login address', function () {
    if (!r3_has('woocommerce')) {
        skip('woocommerce is not installed on the test site');
    }
    $u = http_user('subscriber');
    wp_cli('plugin activate woocommerce');
    get(BASE . '/');
    settings(array('limit_attempts' => 2));
    try {
        r3_e('if (!get_option("woocommerce_myaccount_page_id") || !get_post(get_option("woocommerce_myaccount_page_id"))) { $id = wp_insert_post(array("post_title" => "My account", "post_name" => "my-account", "post_type" => "page", "post_status" => "publish", "post_content" => "[woocommerce_my_account]")); update_option("woocommerce_myaccount_page_id", $id); } update_option("woocommerce_coming_soon", "no");');
        $url = r3_e('echo get_permalink(get_option("woocommerce_myaccount_page_id"));');
        $ip = '203.0.113.66';
        $res = null;
        for ($i = 0; $i < 2; $i++) {
            $page = get($url, array('ip' => $ip));
            $res = post($url, array('username' => $u['login'], 'password' => 'wrong', 'login' => 'Log in', 'woocommerce-login-nonce' => r3_field($page->body, 'woocommerce-login-nonce'), '_wp_http_referer' => '/my-account/'), array('ip' => $ip, 'cookies' => live_cookies($page), 'follow' => true));
        }
        contains('Too many failed login attempts', r3_text($res->body), 'said at once');
        contains('authlify_unlock=1', str_replace('&#038;', '&', $res->body), 'link to My Account');
        $page = get($url . '?authlify_unlock=1', array('ip' => $ip));
        $before = mail_count();
        post($url . '?authlify_unlock=1', array('authlify_unlock_login' => $u['login'], 'authlify_unlock_nonce' => r3_field($page->body, 'authlify_unlock_nonce')), array('ip' => $ip, 'cookies' => live_cookies($page)));
        eq($before + 1, mail_count(), 'emailed');
        $mails = mails($before);
        not_contains(RSLUG, (string) $mails[0]['message'], 'no login address in the email');
        contains('authlify_unlock_public', (string) $mails[0]['message']);
    } finally {
        settings(array('limit_attempts' => 5));
        r3_e('\Authlify\Security\Limiter::unlock("203.0.113.66");');
        wp_cli('plugin deactivate woocommerce');
    }
});

t('Pro request: real EDD: the "Lost Password?" link on [edd_login] names no login address', function () {
    if (!r3_has('easy-digital-downloads')) {
        skip('easy-digital-downloads is not installed on the test site');
    }
    wp_cli('plugin activate easy-digital-downloads');
    get(BASE . '/');
    try {
        r3_e('wp_insert_post(array("post_title" => "r3 edd", "post_name" => "r3-edd", "post_type" => "page", "post_status" => "publish", "post_content" => "[edd_login]"));');
        $res = get(BASE . '/r3-edd/', array('ip' => '203.0.113.67'));
        eq(200, $res->code);
        ok(preg_match('/<a[^>]*href="([^"]*)"[^>]*>\s*Lost Password/i', $res->body, $m) === 1, 'link found');
        not_contains(RSLUG, $m[1], 'the link');
    } finally {
        r3_e('$p = get_page_by_path("r3-edd"); if ($p) { wp_delete_post($p->ID, true); }');
        wp_cli('plugin deactivate easy-digital-downloads');
    }
});

/* ---------------------------------------------------------- Username denylist */

t('denylist: nothing changes while the list is empty', function () {
    $name = 'nobody' . wp_rand_free();
    $res = post(login_url(RSLUG) . '?action=register', array('user_login' => $name, 'user_email' => $name . '@example.test'), array('ip' => '203.0.113.70', 'follow' => false));
    eq(302, $res->code, 'registered: ' . substr(r3_text($res->body), 0, 150));
    r3_e('require_once ABSPATH . "wp-admin/includes/user.php"; $u = get_user_by("login", "' . $name . '"); if ($u) { wp_delete_user($u->ID); }');
});

function wp_rand_free()
{
    return substr(md5(uniqid('', true)), 0, 8);
}

t('denylist: on a network, wp-signup.php refuses a listed name and takes an unlisted one', function () {
    if ('1' !== r3_e('echo is_multisite() ? 1 : 0;')) {
        skip('needs a multisite site');
    }
    r3_e('update_site_option("registration", "user");');
    settings(array('username_denylist' => "adminx\nMixedCase"));
    $signup = function ($name) {
        $form = get(BASE . '/wp-signup.php', array('ip' => '203.0.113.72'));
        preg_match('/name=[\'"]signup_form_id[\'"] value=[\'"]([^\'"]+)/', $form->body, $id);
        preg_match('/name=[\'"]_signup_form[\'"] value=[\'"]([^\'"]+)/', $form->body, $nonce);
        return post(BASE . '/wp-signup.php', array('stage' => 'validate-user-signup', 'signup_for' => 'user', 'user_name' => $name, 'user_email' => $name . wp_rand_free() . '@example.test', 'signup_form_id' => $id[1], '_signup_form' => $nonce[1]), array('ip' => '203.0.113.72'));
    };
    try {
        foreach (array('adminx', 'mixedcase') as $name) {
            $res = r3_text($signup($name)->body);
            contains('not allowed', $res, $name);
            not_contains('is your new username', $res, $name);
        }
        contains('is your new username', r3_text($signup('okname' . substr(wp_rand_free(), 0, 5) . 'x')->body));
    } finally {
        r3_e('update_site_option("registration", "none");');
        settings(array('username_denylist' => ''));
    }
});

t('denylist: a listed name cannot register on the real form, an unlisted one can, and the refusal is logged', function () {
    if ('1' === r3_e('echo is_multisite() ? 1 : 0;')) {
        skip('a network registers through wp-signup.php, tested above');
    }
    settings(array('username_denylist' => "adminx\nTestX"));
    foreach (array('adminx', 'testx', 'TESTX') as $name) {
        $res = post(login_url(RSLUG) . '?action=register', array('user_login' => $name, 'user_email' => strtolower($name) . wp_rand_free() . '@example.test'), array('ip' => '203.0.113.71', 'follow' => false));
        eq(200, $res->code, $name);
        contains('not allowed', r3_text($res->body), $name);
        eq('', r3_e('echo username_exists("' . $name . '") ? "yes" : "";'), $name . ' not created');
    }
    ok((int) r3_e('global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM " . \Authlify\Log\Log::table() . " WHERE event = \'username_refused\' AND username = \'adminx\'");') >= 1, 'logged');
    $name = 'okname' . wp_rand_free();
    $res = post(login_url(RSLUG) . '?action=register', array('user_login' => $name, 'user_email' => $name . '@example.test'), array('ip' => '203.0.113.71', 'follow' => false));
    eq(302, $res->code);
    r3_e('require_once ABSPATH . "wp-admin/includes/user.php"; $u = get_user_by("login", "' . $name . '"); if ($u) { wp_delete_user($u->ID); }');
    settings(array('username_denylist' => ''));
});

t('denylist: a real account on the list logs in, is not locked by a wrong password, and can edit its profile', function () {
    $u = http_user('subscriber');
    settings(array('username_denylist' => $u['login'] . "\nghostname", 'username_deny_login' => true, 'username_deny_lockout' => true));
    $ip = '8.8.4.70';
    $res = login_post(login_url(RSLUG), $u['login'], 'wrong-password', array(), array('ip' => $ip));
    no(r3_logged_in($res));
    eq(0, (int) r3_e('echo \Authlify\Security\Limiter::locked_until("' . $ip . '");'), 'one wrong password does not lock');
    ok(r3_logged_in(login_post(login_url(RSLUG), $u['login'], $u['pass'], array(), array('ip' => $ip))), 'real account signs in');
    eq('1', r3_e('$r = wp_update_user(array("ID" => ' . $u['id'] . ', "display_name" => "Renamed")); echo is_wp_error($r) ? "0" : "1";'), 'update is not refused');
    r3_e('\Authlify\Security\Limiter::unlock("' . $ip . '");');
    settings(array('username_denylist' => '', 'username_deny_login' => false, 'username_deny_lockout' => false));
});

t('denylist: login attempts: normal when off; refused and logged when on; the address locks only when chosen', function () {
    settings(array('username_denylist' => 'ghostname', 'username_deny_login' => false, 'username_deny_lockout' => false));
    $count = function () {
        return (int) r3_e('global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM " . \Authlify\Log\Log::table() . " WHERE event = \'username_refused\' AND username = \'ghostname\'");');
    };
    $before = $count();
    login_post(login_url(RSLUG), 'ghostname', 'x', array(), array('ip' => '8.8.4.71'));
    eq($before, $count(), 'off: not treated specially');

    settings(array('username_deny_login' => true));
    $res = login_post(login_url(RSLUG), 'GhostName', 'x', array(), array('ip' => '8.8.4.72'));
    no(r3_logged_in($res));
    contains('Unknown username', r3_text($res->body));
    not_contains('ghostname', strtolower(r3_text($res->body)), 'the list is not revealed');
    eq($before + 1, $count(), 'logged once for the whole request');
    eq(0, (int) r3_e('echo \Authlify\Security\Limiter::locked_until("8.8.4.72");'), 'no lockout unless chosen');

    settings(array('username_deny_lockout' => true));
    $res = login_post(login_url(RSLUG), 'ghostname', 'x', array(), array('ip' => '8.8.4.73'));
    ok((int) r3_e('echo \Authlify\Security\Limiter::locked_until("8.8.4.73");') > time(), 'locked on the first attempt');
    contains('Too many failed login attempts', r3_text($res->body), 'told at once');
    $u = http_user('subscriber');
    no(r3_logged_in(login_post(login_url(RSLUG), $u['login'], $u['pass'], array(), array('ip' => '8.8.4.73'))), 'that address cannot log in');
    ok(r3_logged_in(login_post(login_url(RSLUG), $u['login'], $u['pass'], array(), array('ip' => '8.8.4.74'))), 'others can');
    r3_e('\Authlify\Security\Limiter::unlock("8.8.4.73");');
});

t('denylist: the immediate lockout never hits a private address, an admin\'s address or an allowlisted one', function () {
    settings(array('username_denylist' => 'ghostname', 'username_deny_login' => true, 'username_deny_lockout' => true, 'ip_allowlist' => '8.8.4.76'));
    login_post(login_url(RSLUG), 'ghostname', 'x', array(), array('ip' => '10.20.30.40'));
    eq(0, (int) r3_e('echo \Authlify\Security\Limiter::locked_until("10.20.30.40");'), 'private');

    $admin = http_user('administrator');
    ok(r3_logged_in(login_post(login_url(RSLUG), $admin['login'], $admin['pass'], array(), array('ip' => '8.8.4.75'))), 'admin signs in from it');
    login_post(login_url(RSLUG), 'ghostname', 'x', array(), array('ip' => '8.8.4.75'));
    eq(0, (int) r3_e('echo \Authlify\Security\Limiter::locked_until("8.8.4.75");'), 'an address an admin used');

    login_post(login_url(RSLUG), 'ghostname', 'x', array(), array('ip' => '8.8.4.76'));
    eq(0, (int) r3_e('echo \Authlify\Security\Limiter::locked_until("8.8.4.76");'), 'allowlisted');

    // An email address is never a listed username.
    login_post(login_url(RSLUG), 'ghostname@example.test', 'x', array(), array('ip' => '8.8.4.77'));
    eq(0, (int) r3_e('echo \Authlify\Security\Limiter::locked_until("8.8.4.77");'), 'email');
    settings(array('username_denylist' => '', 'username_deny_login' => false, 'username_deny_lockout' => false, 'ip_allowlist' => ''));
});

t('denylist: the settings screen warns about real accounts on the list', function () {
    $admin = http_user('administrator');
    $u = http_user('subscriber');
    settings(array('username_denylist' => $u['login'] . "\nghostname"));
    $s = session_for($admin['id']);
    $body = get(BASE . '/wp-admin/admin.php?page=authlify-protection&tab=limits', array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'))->body;
    contains('Refused usernames', $body);
    contains('belong to real accounts', $body);
    contains($u['login'], r3_text(substr($body, strpos($body, 'belong to real accounts'), 300)));
    settings(array('username_denylist' => ''));
});

/* ---------------------------------------------------------- C3-01 */

/** Enrol with the real plugin, import through the screen, deactivate, sign in. */
function r3_wordfence_flow($plugin, $ip)
{
    if (!r3_has($plugin)) {
        skip($plugin . ' is not installed on the test site');
    }
    $admin = http_user('administrator');
    $u = http_user('administrator');
    $s = session_for($admin['id'], array('authlify_2fa_import'));
    wp_cli('plugin activate ' . $plugin);
    get(BASE . '/');

    try {
        $hex = bin2hex(random_bytes(20));
        $codes = array(bin2hex(random_bytes(8)), bin2hex(random_bytes(8)), bin2hex(random_bytes(8)));
        r3_e('\WordfenceLS\Controller_TOTP::shared()->activate_2fa(get_userdata(' . $u['id'] . '), "' . $hex . '", ' . var_export($codes, true) . ', time() - 300);');
        $used_step = (int) floor(time() / 30);
        $used_code = r3_totp_hex($hex, $used_step);
        eq('1', r3_e('echo \WordfenceLS\Controller_TOTP::shared()->validate_2fa(get_userdata(' . $u['id'] . '), "' . $used_code . '") ? "1" : "0";'), 'the plugin itself accepts our independent code');
        eq(strtolower($hex), strtolower(r3_e('global $wpdb; echo $wpdb->get_var("SELECT HEX(secret) FROM {$wpdb->base_prefix}wfls_2fa_secrets WHERE user_id = ' . $u['id'] . '");')), 'stored as raw bytes');

        $tools = get(BASE . '/wp-admin/admin.php?page=authlify-tools', array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'))->body;
        contains('value="wordfence"', $tools);
        not_contains('Not supported', $tools);
        not_contains('cannot be copied', $tools);

        $send = function ($run) use ($s) {
            return post(BASE . '/wp-admin/admin-post.php', array_merge(array('action' => 'authlify_2fa_import', 'source' => 'wordfence', '_wpnonce' => $s['nonces']['authlify_2fa_import']), $run ? array('run' => '1') : array('preview' => '1')), array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'));
        };
        $send(false);
        $notice = r3_text(get(BASE . '/wp-admin/admin.php?page=authlify-tools', array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'))->body);
        contains('Preview:', $notice);
        contains('Recovery codes would be copied', $notice);
        eq('', r3_e('echo \Authlify\TwoFactor\Totp::is_configured(' . $u['id'] . ') ? "yes" : "";'), 'preview changed nothing');

        $send(true);
        $notice = r3_text(get(BASE . '/wp-admin/admin.php?page=authlify-tools', array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'))->body);
        contains('Recovery codes were copied', $notice);
        eq('yes', r3_e('echo \Authlify\TwoFactor\Totp::is_configured(' . $u['id'] . ') ? "yes" : "";'), 'imported');
        eq(strtolower($hex), strtolower(r3_e('global $wpdb; echo $wpdb->get_var("SELECT HEX(secret) FROM {$wpdb->base_prefix}wfls_2fa_secrets WHERE user_id = ' . $u['id'] . '");')), 'Wordfence\'s data is left as it was');

        wp_cli('plugin deactivate ' . $plugin);
        // The full Wordfence plugin can take PHP's built-in test server down
        // ("Maximum execution time of 0+2 seconds"); start it again if so.
        shell_exec(escapeshellarg(getenv('AUTHLIFY_TESTS_DIR') . '/bin/serve.sh') . ' 2>&1');

        // Real sign-in. The code the old plugin just accepted cannot be replayed.
        $begin = function () use ($u, $ip) {
            $res = login_post(login_url(RSLUG), $u['login'], $u['pass'], array(), array('ip' => $ip));
            no(r3_logged_in($res), 'password alone is not enough');
            contains('authlify_2fa_form', $res->body);

            return array('authlify_nonce' => r3_field($res->body, 'authlify_nonce'), 'authlify_uid' => r3_field($res->body, 'authlify_uid'));
        };
        $res = post(login_url(RSLUG) . '?action=authlify_2fa', array_merge($begin(), array('authlify_method' => 'totp', 'authlify_code' => $used_code)), array('ip' => $ip));
        no(r3_logged_in($res), 'replay of the code last used in Wordfence is refused');

        $res = post(login_url(RSLUG) . '?action=authlify_2fa', array_merge($begin(), array('authlify_method' => 'totp', 'authlify_code' => r3_totp_hex($hex, max($used_step + 1, (int) floor(time() / 30))))), array('ip' => $ip));
        ok(r3_logged_in($res), 'signed in with the next code from the original secret: ' . $res->code . ' ' . substr(r3_text($res->body), 0, 200));

        // Recovery code, typed the way Wordfence shows it (groups of four).
        $typed = implode(' ', str_split($codes[0], 4));
        $res = post(login_url(RSLUG) . '?action=authlify_2fa', array_merge($begin(), array('authlify_method' => 'backup', 'authlify_code' => $typed)), array('ip' => $ip));
        ok(r3_logged_in($res), 'signed in with a Wordfence recovery code: ' . substr(r3_text($res->body), 0, 200));
        $res = post(login_url(RSLUG) . '?action=authlify_2fa', array_merge($begin(), array('authlify_method' => 'backup', 'authlify_code' => $typed)), array('ip' => $ip));
        no(r3_logged_in($res), 'single use');
        eq(2, (int) r3_e('echo \Authlify\TwoFactor\BackupCodes::remaining(' . $u['id'] . ');'));
    } finally {
        wp_cli('plugin deactivate ' . $plugin);
        r3_e('global $wpdb; $wpdb->query("TRUNCATE TABLE {$wpdb->base_prefix}wfls_2fa_secrets");');
    }
}

t('C3-01: real Wordfence Login Security: import, deactivate, 2FA login with the original secret and a recovery code', function () {
    r3_wordfence_flow('wordfence-login-security', '203.0.113.80');
});

t('C3-01: real Wordfence (full plugin, which bundles login security): the same import and login', function () {
    r3_wordfence_flow('wordfence', '203.0.113.82');
});

t('C3-01: the 2FA code input takes a recovery code in Wordfence\'s format', function () {
    $u = http_user('editor');
    r3_e('\Authlify\TwoFactor\Totp::import_secret(' . $u['id'] . ', \Authlify\TwoFactor\Totp::new_secret()); \Authlify\TwoFactor\BackupCodes::import_codes(' . $u['id'] . ', array("0123456789abcdef")); \Authlify\TwoFactor\TwoFactor::sync_flag(' . $u['id'] . ');');
    $res = login_post(login_url(RSLUG), $u['login'], $u['pass'], array(), array('ip' => '203.0.113.81'));
    contains('authlify_2fa_form', $res->body);
    $backup = post(login_url(RSLUG) . '?action=authlify_2fa', array('authlify_nonce' => r3_field($res->body, 'authlify_nonce'), 'authlify_uid' => r3_field($res->body, 'authlify_uid'), 'authlify_switch' => 'backup', 'authlify_method' => 'backup'), array('ip' => '203.0.113.81'));
    ok(preg_match('/name="authlify_code"[^>]*maxlength="24"/', $res->body . $backup->body) === 1, 'field allows 16 characters and separators');
});

/* ---------------------------------------------------------- C3-13 */

t('C3-13: real Limit Login Attempts: found while active, saved settings copied, not mistaken for Reloaded', function () {
    if (!r3_has('limit-login-attempts')) {
        skip('limit-login-attempts is not installed on the test site');
    }
    $admin = http_user('administrator');
    $s = session_for($admin['id']);
    wp_cli('plugin activate limit-login-attempts');
    try {
        $tools = get(BASE . '/wp-admin/admin.php?page=authlify-tools', array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'))->body;
        contains('importer=limit-login-attempts&', str_replace('&#038;', '&', $tools), 'row for the original');
        not_contains('importer=limit-login-attempts-reloaded', $tools, 'not offered as Reloaded');

        // Defaults when its screen was never saved: 4 tries, 20 minutes, 12 hours.
        eq('4|20|720', r3_e('\Authlify\Admin\ToolsPage::lla_import(); echo \Authlify\Settings::get("limit_attempts") . "|" . \Authlify\Settings::get("lockout_minutes") . "|" . \Authlify\Settings::get("limit_window");'));

        // Saved with its own option names.
        r3_e('update_option("limit_login_allowed_retries", 7); update_option("limit_login_lockout_duration", 3600); update_option("limit_login_valid_duration", 7200);');
        eq('7|60|120', r3_e('\Authlify\Admin\ToolsPage::lla_import(); echo \Authlify\Settings::get("limit_attempts") . "|" . \Authlify\Settings::get("lockout_minutes") . "|" . \Authlify\Settings::get("limit_window");'));
        contains('Limit Login Attempts', r3_e('$c = \Authlify\Diagnostics\Conflicts::detect(); echo json_encode($c);'), 'conflict notice names it');
    } finally {
        wp_cli('plugin deactivate limit-login-attempts');
        r3_e('foreach (array("limit_login_allowed_retries", "limit_login_lockout_duration", "limit_login_valid_duration") as $o) { delete_option($o); }');
        settings(array('limit_attempts' => 5, 'lockout_minutes' => 15, 'limit_window' => 15));
    }
});

/* ---------------------------------------------------------- C3-06 */

t('C3-06: real Paid Memberships Pro: checkout and lost password carry the CAPTCHA and it is enforced; login message shown; no new slug leak', function () {
    if (!r3_has('paid-memberships-pro')) {
        skip('paid-memberships-pro is not installed on the test site');
    }
    $u = http_user('subscriber');
    $s = session_for($u['id']);
    wp_cli('plugin activate paid-memberships-pro');
    get(BASE . '/');
    r3_e('update_option("authlify_test_http", array(array("match" => "challenges.cloudflare.com", "siteverify" => true)));');
    settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'test-site-key', 'captcha_secret_key' => 'test-secret', 'captcha_forms' => array('login', 'register', 'lostpassword'), 'captcha_mode' => 'always'));
    try {
        $ids = r3_json('global $wpdb;
            $checkout = wp_insert_post(array("post_title" => "r3 checkout", "post_name" => "r3-checkout", "post_type" => "page", "post_status" => "publish", "post_content" => "[pmpro_checkout]"));
            $login = wp_insert_post(array("post_title" => "r3 pm login", "post_name" => "r3-pm-login", "post_type" => "page", "post_status" => "publish", "post_content" => "[pmpro_login]"));
            $conf = wp_insert_post(array("post_title" => "r3 conf", "post_name" => "r3-conf", "post_type" => "page", "post_status" => "publish", "post_content" => "[pmpro_confirmation]"));
            $acct = wp_insert_post(array("post_title" => "r3 acct", "post_name" => "r3-acct", "post_type" => "page", "post_status" => "publish", "post_content" => "[pmpro_account]"));
            $lvls = wp_insert_post(array("post_title" => "r3 levels", "post_name" => "r3-levels", "post_type" => "page", "post_status" => "publish", "post_content" => "[pmpro_levels]"));
            update_option("pmpro_checkout_page_id", $checkout); update_option("pmpro_login_page_id", $login); update_option("pmpro_confirmation_page_id", $conf); update_option("pmpro_account_page_id", $acct); update_option("pmpro_levels_page_id", $lvls);
            update_option("pmpro_gateway", ""); update_option("pmpro_recaptcha", 0);
            $wpdb->insert($wpdb->pmpro_membership_levels, array("name" => "R3 Free", "description" => "", "confirmation" => "", "initial_payment" => 0, "billing_amount" => 0, "cycle_number" => 0, "cycle_period" => "", "billing_limit" => 0, "trial_amount" => 0, "trial_limit" => 0, "allow_signups" => 1, "expiration_number" => 0, "expiration_period" => ""));
            echo json_encode(array("pages" => array($checkout, $login, $conf, $acct, $lvls), "level" => $wpdb->insert_id));');
        $level = (int) $ids['level'];
        $ip = '203.0.113.90';
        get(BASE . '/', array('ip' => $ip));

        // Checkout.
        $url = BASE . '/r3-checkout/?level=' . $level;
        $page = get($url, array('ip' => $ip));
        eq(200, $page->code, 'checkout page');
        contains('authlify-captcha--turnstile', $page->body, 'widget on the checkout form');

        $name = 'pm' . wp_rand_free();
        $fields = array('level' => $level, 'username' => $name, 'password' => 'Pm-Pass-123456!', 'password2' => 'Pm-Pass-123456!', 'bemail' => $name . '@example.test', 'bconfirmemail' => $name . '@example.test', 'fullname' => '', 'submit-checkout' => '1', 'javascriptok' => '1', 'pmpro_checkout_nonce' => r3_field($page->body, 'pmpro_checkout_nonce'), 'checkjavascript' => '1');
        $res = post($url, $fields, array('ip' => $ip));
        contains('security check', strtolower(r3_text($res->body)), 'no token: refused with our message: ' . substr(r3_text($res->body), 0, 200));
        eq('', r3_e('echo username_exists("' . $name . '") ? "yes" : "";'), 'no account made');

        $res = post($url, array_merge($fields, array('cf-turnstile-response' => 'good')), array('ip' => $ip, 'follow' => false));
        eq('yes', r3_e('echo username_exists("' . $name . '") ? "yes" : "";'), 'valid token: account made: ' . $res->code . ' ' . substr(r3_text($res->body), 0, 200));

        // Denylisted name at checkout.
        settings(array('username_denylist' => 'pmghost'));
        $res = post($url, array_merge($fields, array('username' => 'pmghost', 'bemail' => 'pmghost@example.test', 'bconfirmemail' => 'pmghost@example.test', 'cf-turnstile-response' => 'good')), array('ip' => $ip));
        eq('', r3_e('echo username_exists("pmghost") ? "yes" : "";'), 'a listed name cannot check out: ' . substr(r3_text($res->body), 0, 200));
        settings(array('username_denylist' => ''));

        // Login page: wp_login_form() with the widget; failures come back with a message.
        $page = get(BASE . '/r3-pm-login/', array('ip' => $ip));
        contains('pmpro_login_form_used', $page->body);
        contains('authlify-captcha--turnstile', $page->body, 'widget on the login form');
        $res = post(login_url(RSLUG), array('log' => $u['login'], 'pwd' => $u['pass'], 'pmpro_login_form_used' => '1', 'wp-submit' => 'Log In'), array('ip' => $ip, 'follow' => false, 'headers' => array('Referer: ' . BASE . '/r3-pm-login/')));
        no(r3_logged_in($res), 'no token, no login');
        contains('action=authlify_captcha', (string) $res->location, 'back to PMPro with our code');
        contains('security check', strtolower(r3_text(get($res->location, array('ip' => $ip))->body)), 'the page says why');

        // Lost password.
        $page = get(BASE . '/r3-pm-login/?action=reset_pass', array('ip' => $ip));
        contains('lostpasswordform', $page->body);
        contains('authlify-captcha--turnstile', $page->body, 'widget on the lost-password form');
        $before = mail_count();
        $res = post(login_url(RSLUG) . '?action=lostpassword', array('user_login' => $u['login'], 'pmpro_login_form_used' => '1'), array('ip' => $ip, 'follow' => false));
        contains('errors=authlify_captcha', (string) $res->location, 'refused: ' . $res->location);
        eq($before, mail_count(), 'no reset email');
        contains('security check', strtolower(r3_text(get($res->location, array('ip' => $ip))->body)), 'the page says why');
        $res = post(login_url(RSLUG) . '?action=lostpassword', array('user_login' => $u['login'], 'pmpro_login_form_used' => '1', 'cf-turnstile-response' => 'good'), array('ip' => $ip, 'follow' => false));
        contains('checkemail=confirm', (string) $res->location, 'valid token goes through');

        // Logged-in member: the PMPro account page and logout carry no slug.
        $acct = get(BASE . '/r3-acct/', array('cookies' => $s['cookies'], 'ip' => $ip));
        not_contains(RSLUG, $acct->body, 'member account page');

        // The Leak Check knows PMPro's login page.
        contains('r3-pm-login', r3_e('echo json_encode(array_values(\Authlify\Diagnostics\LeakCheck::login_pages()));'));
    } finally {
        settings(array('captcha_provider' => 'none', 'captcha_site_key' => '', 'captcha_secret_key' => ''));
        r3_e('global $wpdb; foreach (array("r3-checkout", "r3-pm-login", "r3-conf", "r3-acct", "r3-levels") as $s) { $p = get_page_by_path($s); if ($p) { wp_delete_post($p->ID, true); } } delete_option("authlify_test_http");');
        wp_cli('plugin deactivate paid-memberships-pro');
    }
});

t('C3-06: LearnDash (a stand-in built from its documented hooks, not LearnDash itself): the registration form gets the CAPTCHA and it is enforced', function () {
    if ('1' === r3_e('echo is_multisite() ? 1 : 0;')) {
        skip('the stand-in posts to wp-login.php, which a network sends to wp-signup.php');
    }
    $stub = <<<'PHP'
<?php
// Test stand-in for LearnDash's documented registration hooks.
define('LEARNDASH_VERSION', 'stub');
add_shortcode('ld_stub_register', function () {
    ob_start();
    echo '<form id="learndash_registerform" method="post" action="' . esc_url(wp_registration_url()) . '"><input name="user_login"><input name="user_email"><input type="hidden" name="learndash-registration-form" value="1">';
    do_action('learndash_registration_form');
    echo '<input type="submit"></form>';
    return ob_get_clean();
});
PHP;
    file_put_contents(SITE_DIR . '/wp-content/mu-plugins/authlify-test-ld-stub.php', $stub);
    r3_e('update_option("authlify_test_http", array(array("match" => "challenges.cloudflare.com", "siteverify" => true)));');
    settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'test-site-key', 'captcha_secret_key' => 'test-secret', 'captcha_forms' => array('login', 'register', 'lostpassword'), 'captcha_mode' => 'always'));
    try {
        r3_e('wp_insert_post(array("post_title" => "r3 ld", "post_name" => "r3-ld", "post_type" => "page", "post_status" => "publish", "post_content" => "[ld_stub_register]"));');
        $page = get(BASE . '/r3-ld/', array('ip' => '203.0.113.91'));
        contains('authlify-captcha--turnstile', $page->body, 'widget');
        $name = 'ld' . wp_rand_free();
        $fields = array('user_login' => $name, 'user_email' => $name . '@example.test', 'learndash-registration-form' => '1');
        post(login_url(RSLUG) . '?action=register', $fields, array('ip' => '203.0.113.91'));
        eq('', r3_e('echo username_exists("' . $name . '") ? "yes" : "";'), 'no token, no account');
        post(login_url(RSLUG) . '?action=register', array_merge($fields, array('cf-turnstile-response' => 'good')), array('ip' => '203.0.113.91'));
        eq('yes', r3_e('echo username_exists("' . $name . '") ? "yes" : "";'), 'valid token');
        contains('CAPTCHA', r3_e('echo json_encode(apply_filters("learndash_registration_errors", array()));'), 'message for LearnDash');
    } finally {
        unlink(SITE_DIR . '/wp-content/mu-plugins/authlify-test-ld-stub.php');
        settings(array('captcha_provider' => 'none', 'captcha_site_key' => '', 'captcha_secret_key' => ''));
        r3_e('$p = get_page_by_path("r3-ld"); if ($p) { wp_delete_post($p->ID, true); } delete_option("authlify_test_http");');
    }
});

/* ---------------------------------------------------------- F3 fixes */

t('F3-01: real WP 2FA 4.3.0: a secret it sealed through its own code imports, and the user logs in with a code from the original secret', function () {
    if (!r3_has('wp-2fa')) {
        skip('wp-2fa is not installed on the test site');
    }
    $admin = http_user('administrator');
    $u = http_user('editor');
    $s = session_for($admin['id'], array('authlify_2fa_import'));
    wp_cli('plugin activate wp-2fa');
    get(BASE . '/');
    try {
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $stored = r3_e('$s = \WP2FA\Authenticator\Open_SSL::SECRET_KEY_PREFIX . \WP2FA\Authenticator\Open_SSL::encrypt("' . $secret . '"); update_user_meta(' . $u['id'] . ', "wp_2fa_totp_key", $s); update_user_meta(' . $u['id'] . ', "wp_2fa_enabled_methods", "totp"); echo $s;');
        contains('lsc_v2:', $stored, 'the current format');

        $send = function ($run) use ($s) {
            return post(BASE . '/wp-admin/admin-post.php', array_merge(array('action' => 'authlify_2fa_import', 'source' => 'wp-2fa', '_wpnonce' => $s['nonces']['authlify_2fa_import']), $run ? array('run' => '1') : array('preview' => '1')), array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'));
        };
        $send(false);
        $notice = r3_text(get(BASE . '/wp-admin/admin.php?page=authlify-tools', array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'))->body);
        contains('1 user would be imported', $notice);
        not_contains('secret could not be read', $notice);
        $send(true);
        eq('yes', r3_e('echo \Authlify\TwoFactor\Totp::is_configured(' . $u['id'] . ') ? "yes" : "";'), 'imported');
    } finally {
        wp_cli('plugin deactivate wp-2fa');
    }
    $ip = '203.0.113.83';
    $res = login_post(login_url(RSLUG), $u['login'], $u['pass'], array(), array('ip' => $ip));
    contains('authlify_2fa_form', $res->body);
    $res = post(login_url(RSLUG) . '?action=authlify_2fa', array('authlify_nonce' => r3_field($res->body, 'authlify_nonce'), 'authlify_uid' => r3_field($res->body, 'authlify_uid'), 'authlify_method' => 'totp', 'authlify_code' => totp_now($secret)), array('ip' => $ip));
    ok(r3_logged_in($res), 'signed in: ' . substr(r3_text($res->body), 0, 160));
});

t('F3-02: a failed login-URL email is reported on the screen, with the address', function () {
    $admin = http_user('administrator');
    $s = session_for($admin['id'], array('authlify_email_url'));
    file_put_contents(SITE_DIR . '/wp-content/mu-plugins/authlify-test-nomail.php', "<?php add_filter('pre_wp_mail', '__return_false', 9999);");
    try {
        $res = get(BASE . '/wp-admin/admin-post.php?action=authlify_email_url&_wpnonce=' . $s['nonces']['authlify_email_url'], array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'));
        contains('authlify_notice=email_failed', (string) $res->location);
        $page = get($res->location, array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'))->body;
        contains('could not be sent', $page);
        contains(RSLUG, substr($page, strpos($page, 'could not be sent'), 400));
        contains('AUTHLIFY_DISABLE_HIDE', $page);
    } finally {
        unlink(SITE_DIR . '/wp-content/mu-plugins/authlify-test-nomail.php');
    }
    $res = get(BASE . '/wp-admin/admin-post.php?action=authlify_email_url&_wpnonce=' . $s['nonces']['authlify_email_url'], array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'));
    contains('authlify_notice=emailed', (string) $res->location, 'with working mail');
});

t('F3-03, F3-08: the public unlock screen and its email never name the login address; the link lets that account in', function () {
    $u = http_user('subscriber');
    $ip = '203.0.113.84';
    settings(array('limit_attempts' => 2));
    login_post(login_url(RSLUG), $u['login'], 'bad-1', array(), array('ip' => $ip));
    login_post(login_url(RSLUG), $u['login'], 'bad-2', array(), array('ip' => $ip));
    settings(array('limit_attempts' => 5));
    ok((int) r3_e('echo \Authlify\Security\Limiter::locked_until("' . $ip . '");') > time(), 'locked');

    $page = get(BASE . '/?authlify_unlock_public=1', array('ip' => $ip));
    eq(200, $page->code);
    not_contains(RSLUG, $page->body, 'form page');
    contains('noindex', json_encode($page->headers));
    $before = mail_count();
    $res = post(BASE . '/?authlify_unlock_public=1', array('authlify_public_login' => $u['login'], 'authlify_public_nonce' => r3_field($page->body, 'authlify_public_nonce')), array('ip' => $ip));
    not_contains(RSLUG, $res->body, 'sent page');
    eq($before + 1, mail_count(), 'one email');
    $mails = mails($before);
    $mail = end($mails);
    $body = (string) (isset($mail['message']) ? $mail['message'] : json_encode($mail));
    not_contains(RSLUG, $body, 'the email');
    ok(preg_match('#https?://\S+authlify_unlock_public\S+#', $body, $m) === 1, 'link in the mail: ' . $body);
    $link = html_entity_decode($m[0]);

    $res = get($link, array('ip' => $ip));
    contains('Unlock link accepted', r3_text($res->body));
    not_contains(RSLUG, $res->body, 'accepted page');
    ok(r3_logged_in(login_post(login_url(RSLUG), $u['login'], $u['pass'], array(), array('ip' => $ip))), 'that account can log in from the locked address');

    $res = get($link, array('ip' => $ip));
    contains('invalid or has expired', r3_text($res->body), 'single use');
    r3_e('\Authlify\Security\Limiter::unlock("' . $ip . '");');
});

t('Pro request: lost-password links on public pages use a screen that names no login address; the CAPTCHA and core\'s email still work', function () {
    eq(BASE . '/?authlify_lost_public=1', r3_e('echo wp_lostpassword_url();'), 'WP-CLI has no user and no login page');
    $u = http_user('subscriber');
    $ip = '203.0.113.85';
    $page = get(BASE . '/?authlify_lost_public=1', array('ip' => $ip));
    eq(200, $page->code);
    not_contains(RSLUG, $page->body);
    $before = mail_count();
    $res = post(BASE . '/?authlify_lost_public=1', array('authlify_public_login' => 'nobody-here', 'authlify_public_nonce' => r3_field($page->body, 'authlify_public_nonce')), array('ip' => $ip));
    contains('If that account exists', r3_text($res->body));
    eq($before, mail_count(), 'unknown name: same page, no email');
    $res = post(BASE . '/?authlify_lost_public=1', array('authlify_public_login' => $u['login'], 'authlify_public_nonce' => r3_field($page->body, 'authlify_public_nonce')), array('ip' => $ip));
    contains('If that account exists', r3_text($res->body));
    eq($before + 1, mail_count(), 'known name: reset email from core');
    eq(403, post(BASE . '/?authlify_lost_public=1', array('authlify_public_login' => $u['login'], 'authlify_public_nonce' => 'bad'), array('ip' => $ip))->code === 200 ? 403 : 0, 'bad nonce shows the form again, no mail');
    eq($before + 1, mail_count(), 'bad nonce sends nothing');
    // The login page keeps its normal link.
    contains('action=lostpassword', login_post(login_url(RSLUG), 'x', 'y', array(), array('ip' => '203.0.113.86'))->body);

    r3_e('update_option("authlify_test_http", array(array("match" => "challenges.cloudflare.com", "siteverify" => true)));');
    settings(array('captcha_provider' => 'turnstile', 'captcha_site_key' => 'test-site-key', 'captcha_secret_key' => 'test-secret', 'captcha_forms' => array('lostpassword'), 'captcha_mode' => 'always'));
    try {
        $page = get(BASE . '/?authlify_lost_public=1', array('ip' => $ip));
        contains('authlify-captcha--turnstile', $page->body, 'widget');
        $before = mail_count();
        $res = post(BASE . '/?authlify_lost_public=1', array('authlify_public_login' => $u['login'], 'authlify_public_nonce' => r3_field($page->body, 'authlify_public_nonce')), array('ip' => $ip));
        contains('security check', strtolower(r3_text($res->body)));
        eq($before, mail_count(), 'no token, no email');
    } finally {
        settings(array('captcha_provider' => 'none', 'captcha_site_key' => '', 'captcha_secret_key' => ''));
        r3_e('delete_option("authlify_test_http");');
    }
});

t('F3-06: unlocking a CIDR range clears the addresses inside it, and nothing outside', function () {
    settings(array('limit_attempts' => 1));
    foreach (array('198.51.100.81', '198.51.100.82', '198.51.101.83') as $ip) {
        login_post(login_url(RSLUG), 'someone', 'x', array(), array('ip' => $ip));
    }
    settings(array('limit_attempts' => 5));
    $out = shell_exec(escapeshellarg(WPCLI) . ' authlify unlock 198.51.100.0/24 2>&1');
    contains('2 lockout record', (string) $out, $out);
    eq(0, (int) r3_e('echo \Authlify\Security\Limiter::locked_until("198.51.100.81");'));
    ok((int) r3_e('echo \Authlify\Security\Limiter::locked_until("198.51.101.83");') > time(), 'outside the range stays');
    $out = shell_exec(escapeshellarg(WPCLI) . ' authlify unlock 192.0.2.0/24 2>&1');
    contains('Warning', (string) $out, 'nothing matched');
    r3_e('\Authlify\Security\Limiter::unlock("198.51.101.83");');
});

t('F3-04: ALTCHA past the soft limit gets a harder puzzle, not a refusal; far past it is refused', function () {
    $ip = '203.0.113.87';
    $key = r3_e('echo "authlify_altcha_rate_" . md5("' . $ip . '|" . (int) floor(time() / 600));');
    $res = get(BASE . '/wp-admin/admin-ajax.php?action=authlify_altcha', array('ip' => $ip));
    eq(1000000, json_decode($res->body, true)['maxnumber'], 'normal');
    r3_e('set_transient("' . $key . '", 130, 600);');
    $res = get(BASE . '/wp-admin/admin-ajax.php?action=authlify_altcha', array('ip' => $ip));
    eq(200, $res->code, 'past the old limit and the new one: still served');
    eq(5000000, json_decode($res->body, true)['maxnumber'], 'harder');
    r3_e('set_transient("' . $key . '", 1200, 600);');
    eq(429, get(BASE . '/wp-admin/admin-ajax.php?action=authlify_altcha', array('ip' => $ip))->code, 'far past it');
    r3_e('delete_transient("' . $key . '");');
});

t('F3-03, F3-10: real EDD and Ultimate Member: the attempt that locks says so, with an unlock link printed as a link', function () {
    foreach (array('easy-digital-downloads' => '[edd_login]', 'ultimate-member' => null) as $plugin => $shortcode) {
        if (!r3_has($plugin)) {
            continue;
        }
        wp_cli('plugin activate ' . $plugin);
        get(BASE . '/');
        try {
            $u = http_user('subscriber');
            settings(array('limit_attempts' => 2));
            if ('ultimate-member' === $plugin) {
                $id = r3_e('$f = get_posts(array("post_type" => "um_form", "numberposts" => -1, "meta_key" => "_um_mode", "meta_value" => "login", "fields" => "ids")); if (!$f) { $i = wp_insert_post(array("post_type" => "um_form", "post_status" => "publish", "post_title" => "Login")); update_post_meta($i, "_um_mode", "login"); $f = array($i); } echo (int) $f[0];');
                $shortcode = '[ultimatemember form_id="' . (int) $id . '"]';
            }
            r3_e('wp_insert_post(array("post_title" => "r3 locked", "post_name" => "r3-locked", "post_type" => "page", "post_status" => "publish", "post_content" => ' . var_export($shortcode, true) . '));');
            $ip = 'easy-digital-downloads' === $plugin ? '203.0.113.88' : '203.0.113.89';
            $page = get(BASE . '/r3-locked/', array('ip' => $ip));
            eq(200, $page->code, $plugin);
            $jar = live_cookies($page);
            $res = null;
            for ($i = 0; $i < 2; $i++) {
                if ('easy-digital-downloads' === $plugin) {
                    $fields = array('edd_user_login' => $u['login'], 'edd_user_pass' => 'wrong', 'edd_login_nonce' => r3_field($page->body, 'edd_login_nonce'), 'edd_action' => 'user_login', 'edd_redirect' => BASE . '/r3-locked/');
                } else {
                    $form = (int) $id;
                    $fields = array('username-' . $form => $u['login'], 'user_password-' . $form => 'wrong', 'form_id' => $form, 'um_request' => '', '_wpnonce' => r3_field($page->body, '_wpnonce'), '_wp_http_referer' => '/r3-locked/', 'timestamp' => (string) (time() - 60));
                }
                $res = post(BASE . '/r3-locked/', $fields, array('ip' => $ip, 'cookies' => $jar, 'follow' => true));
                $jar = array_merge($jar, live_cookies($res));
            }
            ok((int) r3_e('echo \Authlify\Security\Limiter::locked_until("' . $ip . '");') > time(), $plugin . ': locked: ' . substr(r3_text($res->body), 0, 200));
            contains('Too many failed login attempts', r3_text($res->body), $plugin . ': said at once');
            ok(preg_match('/<a [^>]*href="[^"]*authlify_unlock_public=1[^"]*"/', $res->body) === 1, $plugin . ': a working link, not escaped markup');
            not_contains(RSLUG, substr($res->body, strpos($res->body, 'Too many failed'), 400), $plugin . ': no login address next to it');
        } finally {
            settings(array('limit_attempts' => 5));
            r3_e('$p = get_page_by_path("r3-locked"); if ($p) { wp_delete_post($p->ID, true); } global $wpdb; $wpdb->query("UPDATE " . \Authlify\Security\Limiter::table() . " SET locked_until = 0, failures = 0, lockouts = 0");');
            wp_cli('plugin deactivate ' . $plugin);
        }
    }
    if (!r3_has('easy-digital-downloads') && !r3_has('ultimate-member')) {
        skip('neither plugin is installed on the test site');
    }
});
