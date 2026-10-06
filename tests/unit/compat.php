<?php
/**
 * Compatibility fixes (round-2 CMPT audit): other two-factor plugins are
 * detected without autoloading look-alikes (Solid Security), hidden login
 * scripts in any folder, canonical redirects and toolbar links never hand out
 * the login URL, the Leak Check visits front-end login pages, conflict and
 * WP-Cron warnings, and the Users list passkey query.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Diagnostics\Conflicts;
use Authlify\Diagnostics\LeakCheck;
use Authlify\Diagnostics\SiteHealth;
use Authlify\Login\Router;
use Authlify\TwoFactor\Passkeys;

// ---------------------------------------------------------------- CMPT-01.

t('CMPT-01: a Solid-style Two_Factor_Core stand-in (no add_hooks) is not "Two Factor"', function () {
    $out = subprocess('
        if (!class_exists("Two_Factor_Core", false)) { eval("class Two_Factor_Core { public static function is_user_using_two_factor(\$u = null) { return false; } }"); }
        echo "[" . \Authlify\TwoFactor\TwoFactor::other_provider() . "]" . (\Authlify\TwoFactor\TwoFactor::runs() ? "runs" : "paused");
    ');
    contains('[]runs', $out, 'Authlify keeps two-factor login on: ' . $out);
});

t('CMPT-01: the real Two Factor plugin (class with add_hooks) still pauses Authlify', function () {
    $out = subprocess('
        eval("class Two_Factor_Core { public static function add_hooks() {} }");
        echo "[" . \Authlify\TwoFactor\TwoFactor::other_provider() . "]";
    ');
    contains('[Two Factor]', $out);
});

t('CMPT-01: detection never autoloads Two_Factor_Core', function () {
    $out = subprocess('
        spl_autoload_register(function ($c) { if ("Two_Factor_Core" === $c) { echo "AUTOLOADED"; eval("class Two_Factor_Core {}"); } });
        echo "[" . \Authlify\TwoFactor\TwoFactor::other_provider() . "]";
    ');
    not_contains('AUTOLOADED', $out);
    contains('[]', $out);
});

t('CMPT-01: Solid Security pauses Authlify only while its two-factor module is on', function () {
    $code = '
        eval("class ITSEC_Modules { public static \$on = false; public static function is_active(\$m) { return \"two-factor\" === \$m && self::\$on; } }");
        $a = \Authlify\TwoFactor\TwoFactor::other_provider();
        ITSEC_Modules::$on = true;
        $b = \Authlify\TwoFactor\TwoFactor::other_provider();
        echo "off=[" . $a . "] on=[" . $b . "]";
    ';
    $out = subprocess($code);
    contains('off=[] on=[Solid Security]', $out, $out);
});

// ---------------------------------------------------------------- CMPT-02 / 04.

t('CMPT-02: a canonical redirect to the login URL is cancelled for logged-out visitors', function () {
    set_settings(array('login_slug' => 'cmpt-door', 'block_wp_login' => true));
    wp_set_current_user(0);
    $login = Router::login_url();
    ok(Router::points_to_login(add_query_arg('action', 'register', $login)), 'login URL recognised');
    ok(Router::points_to_login(home_url('/cmpt-door/?action=register')), 'pretty URL recognised');
    ok(Router::points_to_login(home_url('/?cmpt-door&action=register')), 'plain-permalink URL recognised');
    no(Router::points_to_login(home_url('/cmpt-doorway/')), 'a longer slug is not the login URL');
    eq(false, Router::filter_canonical(add_query_arg('action', 'register', $login), home_url('/x/wp-register.php')));
    eq(home_url('/about/'), Router::filter_canonical(home_url('/about/'), home_url('/about')));
});

t('CMPT-04: toolbar items that link to the login page are removed for logged-out visitors', function () {
    set_settings(array('login_slug' => 'cmpt-door', 'block_wp_login' => true));
    wp_set_current_user(0);
    require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
    $GLOBALS['wp_admin_bar'] = new WP_Admin_Bar();
    $GLOBALS['wp_admin_bar']->add_node(array('id' => 'bp-login', 'title' => 'Log In', 'href' => add_query_arg('redirect_to', rawurlencode(home_url('/')), Router::login_url())));
    $GLOBALS['wp_admin_bar']->add_node(array('id' => 'bp-register', 'title' => 'Register', 'href' => home_url('/register/')));
    Router::hide_toolbar_login_links();
    ok(null === $GLOBALS['wp_admin_bar']->get_node('bp-login'), 'Log In node removed');
    ok(null !== $GLOBALS['wp_admin_bar']->get_node('bp-register'), 'other nodes stay');
    unset($GLOBALS['wp_admin_bar']);
});

// ---------------------------------------------------------------- CMPT-03.

t('CMPT-03: the Leak Check visits common login pages and plugin login pages', function () {
    $page = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Member sign in', 'post_name' => 'member-sign-in'));
    update_option('pmpro_login_page_id', $page);
    try {
        $pages = LeakCheck::login_pages();
        ok(isset($pages['login_page_login']), '/login/');
        ok(isset($pages['login_page_my_account']), '/my-account/');
        ok(isset($pages['login_page_pmpro_login']), 'PMPro login page');
        eq(get_permalink($page), $pages['login_page_pmpro_login'][1]);
    } finally {
        delete_option('pmpro_login_page_id');
        wp_delete_post($page, true);
    }
});

// ---------------------------------------------------------------- CMPT-05.

t('CMPT-05: another brute-force limiter is reported, with one message per plugin', function () {
    set_settings(array('limit_enabled' => true, 'login_slug' => 'cmpt-door'));
    add_test_filter('option_active_plugins', function ($plugins) {
        return array_merge((array) $plugins, array('limit-login-attempts-reloaded/limit-login-attempts-reloaded.php', 'loginizer/loginizer.php'));
    });
    $found = Conflicts::detect();
    ok(isset($found['llar']) && $found['llar']['limits'], 'LLAR');
    ok(isset($found['loginizer']), 'Loginizer');
    $messages = Conflicts::messages();
    contains('Limit Login Attempts Reloaded also limits failed logins', $messages['llar']);
    eq('recommended', Conflicts::site_health()['status']);

    set_settings(array('limit_enabled' => false));
    eq(array(), Conflicts::messages(), 'no overlap while Authlify\'s limiter is off');
});

t('CMPT-05: AIOS counts only with its lockdown or rename feature on', function () {
    set_settings(array('limit_enabled' => true, 'login_slug' => 'cmpt-door'));
    add_test_filter('option_active_plugins', function ($plugins) {
        return array_merge((array) $plugins, array('all-in-one-wp-security-and-firewall/wp-security.php'));
    });
    $old = get_option('aio_wp_security_configs', null);
    try {
        update_option('aio_wp_security_configs', array('aiowps_enable_login_lockdown' => '', 'aiowps_enable_rename_login_page' => ''));
        no(isset(Conflicts::detect()['aios']), 'features off');
        update_option('aio_wp_security_configs', array('aiowps_enable_login_lockdown' => '1', 'aiowps_enable_rename_login_page' => '1'));
        $msg = Conflicts::messages();
        contains('also limits failed logins', $msg['aios']);
        contains('also changes the login address', $msg['aios']);
    } finally {
        null === $old ? delete_option('aio_wp_security_configs') : update_option('aio_wp_security_configs', $old);
    }
});

t('CMPT-05: no other plugin means a good Site Health result', function () {
    add_test_filter('authlify_conflicting_plugins', '__return_empty_array');
    eq('good', Conflicts::site_health()['status']);
});

// ---------------------------------------------------------------- CMPT-06.

t('CMPT-06: an Authlify cron event overdue by more than an hour is reported', function () {
    $hook = 'authlify_test_overdue_event';
    wp_schedule_single_event(time() - 2 * HOUR_IN_SECONDS, $hook);
    try {
        $status = SiteHealth::cron_status();
        no($status['ok']);
        ok(in_array($hook, $status['hooks'], true));
        eq('recommended', SiteHealth::test_cron()['status']);
    } finally {
        wp_clear_scheduled_hook($hook);
    }
    ok(SiteHealth::cron_status()['ok'] || !empty(SiteHealth::cron_status()['hooks']), 'status computed');
});

t('CMPT-06: a Leak Check pass older than 14 days is "recommended", a fresh one "good"', function () {
    set_settings(array('login_slug' => 'cmpt-door'));
    $old = get_site_option(LeakCheck::OPTION, null);
    try {
        $result = array('time' => time() - 20 * DAY_IN_SECONDS, 'trigger' => 'weekly', 'state' => 'passed', 'counts' => array('passed' => 40, 'failed' => 0, 'warnings' => 0, 'skipped' => 0), 'probes' => array());
        update_site_option(LeakCheck::OPTION, $result);
        $r = SiteHealth::test_leak_check();
        eq('recommended', $r['status']);
        contains('out of date', $r['label']);
        $checks = LeakCheck::dashboard_check(array());
        no($checks['leak_check'][0], 'dashboard item not done');

        $result['time'] = time() - DAY_IN_SECONDS;
        update_site_option(LeakCheck::OPTION, $result);
        eq('good', SiteHealth::test_leak_check()['status']);
    } finally {
        null === $old ? delete_site_option(LeakCheck::OPTION) : update_site_option(LeakCheck::OPTION, $old);
    }
});

t('CMPT-06: the daily job records when it ran', function () {
    delete_option(SiteHealth::CRON_LAST);
    SiteHealth::note_cron_run();
    ok(abs(time() - (int) get_option(SiteHealth::CRON_LAST)) < 5);
    delete_option(SiteHealth::CRON_LAST);
});

// ---------------------------------------------------------------- CMPT-09.

t('CMPT-09: passkey counts for a page of users come from one query', function () {
    if (!Passkeys::table_exists()) {
        skip('passkey table missing');
    }
    $ids = array();
    for ($i = 0; $i < 5; $i++) {
        $ids[] = make_user()->ID;
    }
    global $wpdb;
    $wpdb->insert(Passkeys::table(), array('user_id' => $ids[0], 'rp_id' => Passkeys::rp_id(), 'credential_hash' => hash('sha256', 'cmpt-' . microtime()), 'credential_id' => 'cmpt-' . wp_generate_password(8, false), 'public_key' => 'x', 'name' => 'Test', 'created_at' => current_time('mysql', true)));
    try {
        foreach ($ids as $id) {
            Passkeys::flush($id);
        }
        $before = $wpdb->num_queries;
        Passkeys::prime($ids);
        eq(1, $wpdb->num_queries - $before, 'one grouped query');
        $before = $wpdb->num_queries;
        eq(1, Passkeys::count_for_user($ids[0]));
        eq(0, Passkeys::count_for_user($ids[1]));
        eq(0, $wpdb->num_queries - $before, 'counts served from the primed data');
    } finally {
        $wpdb->delete(Passkeys::table(), array('user_id' => $ids[0]));
        Passkeys::flush($ids[0]);
    }
});

// ---------------------------------------------------------------- WooCommerce block settings.

t('Woo block settings: wp_login_url() gives My Account (not the login URL) while Woo prints wcSettings', function () {
    set_settings(array('login_slug' => 'cmpt-door', 'block_wp_login' => true));
    wp_set_current_user(0);
    $out = subprocess('
        if (!class_exists("WooCommerce", false)) { eval("class WooCommerce {}"); }
        wp_set_current_user(0);
        add_filter("authlify_public_login_url", function () { return home_url("/my-account/"); });
        \Authlify\Login\Router::start_public_login_url();
        $during = wp_login_url();
        \Authlify\Login\Router::end_public_login_url();
        echo "during=" . $during . " after=" . wp_login_url();
    ');
    contains('during=' . home_url('/my-account/'), $out, $out);
    contains('after=' . home_url('/cmpt-door/'), $out, $out);
});
