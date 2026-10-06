<?php
/**
 * Compatibility fixes over HTTP (round-2 CMPT audit): wp-register.php and
 * friends in any folder, a logged-out toolbar "Log In" link (BuddyPress), the
 * Leak Check's front-end login pages, and the Designer preview that must not
 * start a new session (network installs send the auth cookie to the login page).
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const CSLUG = 'cmpt-door';

function cmpt_mu($name)
{
    return SITE_DIR . '/wp-content/mu-plugins/' . $name;
}

before_all(function () {
    settings(array('login_slug' => CSLUG, 'block_wp_login' => true, 'blocked_response' => '404', 'captcha_provider' => 'none', 'honeypot' => false, 'limit_attempts' => 20));
    // A logged-out toolbar with a "Log In" node, as BuddyPress adds it, and a
    // [cmpt_login] shortcode that prints wp_login_form() on a public page.
    file_put_contents(cmpt_mu('cmpt-fixture.php'), '<?php
add_filter("show_admin_bar", function ($show) { return isset($_GET["cmpt_bar"]) ? true : $show; }, PHP_INT_MAX);
add_action("admin_bar_menu", function ($bar) { if (!is_user_logged_in()) { $bar->add_node(array("id" => "bp-login", "title" => "Log In", "href" => wp_login_url(home_url("/")))); } }, 90);
add_shortcode("cmpt_login", function () { return wp_login_form(array("echo" => false)); });
');
    $GLOBALS['cmpt_page'] = (int) wp_eval('echo wp_insert_post(array("post_type" => "page", "post_status" => "publish", "post_title" => "Member login", "post_name" => "cmpt-member-login", "post_content" => "[cmpt_login]"));');
    // Registered as a membership plugin's login page (PMPro), which the Leak Check visits.
    wp_eval('update_option("pmpro_login_page_id", ' . (int) $GLOBALS['cmpt_page'] . ');');
});

after_all(function () {
    @unlink(cmpt_mu('cmpt-fixture.php'));
    if (!empty($GLOBALS['cmpt_page'])) {
        wp_eval('wp_delete_post(' . (int) $GLOBALS['cmpt_page'] . ', true); delete_option("pmpro_login_page_id");');
    }
    wp_eval('delete_site_option("authlify_leak_check");');
});

foreach (array('/x/wp-register.php', '/wp-content/wp-register.php', '/a/b/wp-register.php?x=1', '/blah/wp-login.php', '/blah/wp-signup.php') as $path) {
    t('CMPT-02: GET ' . $path . ' does not reveal the login URL', function () use ($path) {
        $res = get($path);
        not_contains(CSLUG, $res->location, 'redirect: ' . $res->code . ' ' . $res->location);
        not_contains('/' . CSLUG, $res->body);
        eq(404, $res->code);
    });
}

t('CMPT-02: the registration URL itself still works on the login page', function () {
    $res = get(login_url(CSLUG) . '?action=register');
    ok(in_array($res->code, array(200, 302), true), 'answered ' . $res->code);
});

t('CMPT-04: a logged-out toolbar does not link to the login page', function () {
    $res = get('/?cmpt_bar=1');
    eq(200, $res->code);
    contains('wpadminbar', $res->body, 'the toolbar is shown to visitors (fixture)');
    not_contains('wp-admin-bar-bp-login', $res->body, 'the Log In node is removed');
    not_contains('/' . CSLUG, $res->body);
});

t('CMPT-04: with the login URL not hidden, the toolbar link stays', function () {
    settings(array('block_wp_login' => false));
    try {
        $res = get('/?cmpt_bar=1');
        contains('wp-admin-bar-bp-login', $res->body);
    } finally {
        settings(array('block_wp_login' => true));
    }
});

t('CMPT-02/03: the Leak Check probes wp-register.php in a folder and warns about a public login form', function () {
    $r = wp_json('echo json_encode(\Authlify\Diagnostics\LeakCheck::run("cli"));');
    eq('pass', $r['probes']['wp_register_dir']['status'], json_encode($r['probes']['wp_register_dir']));
    eq('pass', $r['probes']['wp_register_content']['status']);
    eq('warn', $r['probes']['login_page_pmpro_login']['status'], json_encode($r['probes']['login_page_pmpro_login']));
    eq('warning', $r['state'], 'a front-end login form is a warning, not a pass');
    contains('public login page', \strtolower(wp_eval('echo \Authlify\Diagnostics\LeakCheck::summary(\Authlify\Diagnostics\LeakCheck::last());')));
    eq(0, (int) $r['counts']['failed'], 'nothing failed');
});

t('CMPT-07: the Designer preview never signs the admin in again (no new session)', function () {
    $admin = http_user('administrator');
    $s = session_for($admin['id'], array('authlify_designer_preview'));
    $before = (int) wp_eval('echo count(WP_Session_Tokens::get_instance(' . (int) $admin['id'] . ')->get_all());');
    // Send the auth cookie too, as a sub-directory network does (its admin
    // cookie path is "/").
    $res = get(login_url(CSLUG) . '?authlify_preview=' . $s['nonces']['authlify_designer_preview'] . '&action=login', array('cookies' => $s['cookies']));
    eq(200, $res->code, 'the preview shows the form, not a redirect: ' . $res->code . ' ' . $res->location);
    contains('loginform', $res->body);
    $set = array_filter(array_keys(live_cookies($res)), function ($n) {
        return 0 === strpos($n, 'wordpress_logged_in_');
    });
    eq(array(), array_values($set), 'no new logged-in cookie');
    eq($before, (int) wp_eval('echo count(WP_Session_Tokens::get_instance(' . (int) $admin['id'] . ')->get_all());'), 'no new session');
});

t('Pro [authlify_account_security] on a public page does not show signed-out visitors the login URL', function () {
    if ('yes' !== wp_eval('echo class_exists("\AuthlifyPro\Plugin") ? "yes" : "no";')) {
        skip('Authlify Pro is not active');
    }
    $id = (int) wp_eval('echo wp_insert_post(array("post_type" => "page", "post_status" => "publish", "post_title" => "Account security", "post_name" => "cmpt-account-security", "post_content" => "[authlify_account_security]"));');
    try {
        $res = get('/?page_id=' . $id);
        $res = $res->location ? get($res->location) : $res;
        eq(200, $res->code);
        contains('authlify-account-security__signin', $res->body);
        not_contains('/' . CSLUG, $res->body);
        $r = wp_json('echo json_encode(\Authlify\Diagnostics\LeakCheck::run("cli"));');
        eq('pass', $r['probes']['login_page_form_' . $id]['status'], 'the Leak Check visits pages with Authlify shortcodes: ' . json_encode($r['probes']['login_page_form_' . $id]));
    } finally {
        wp_eval('wp_delete_post(' . $id . ', true);');
    }
});
