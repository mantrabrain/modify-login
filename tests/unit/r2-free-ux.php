<?php
/**
 * Round-2 fix phase (free UX and functional): multisite load order, signup
 * page, Leak Check on private sites, lockout notice, redirect address, slug
 * case, importers, CAPTCHA threshold import, event labels, login switcher.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Admin\LoginUrlPage;
use Authlify\Admin\ToolsPage;
use Authlify\Captcha\Admin as CaptchaAdmin;
use Authlify\Captcha\RecaptchaV3;
use Authlify\Designer\Compiler;
use Authlify\Designer\Design;
use Authlify\Designer\Migration;
use Authlify\Diagnostics\LeakCheck;
use Authlify\Log\Log;
use Authlify\Login\Router;
use Authlify\Security\Hardening;
use Authlify\Security\Limiter;
use Authlify\Security\Passwords;
use Authlify\Settings;

/**
 * Run $fn as if plugins_loaded had not fired yet (Authlify booting while plugins load).
 */
function r2fu_before_plugins_loaded(callable $fn)
{
    $had = isset($GLOBALS['wp_actions']['plugins_loaded']) ? $GLOBALS['wp_actions']['plugins_loaded'] : null;
    unset($GLOBALS['wp_actions']['plugins_loaded']);
    try {
        $fn();
    } finally {
        if (null !== $had) {
            $GLOBALS['wp_actions']['plugins_loaded'] = $had;
        }
    }
}

function r2fu_private($class, $method)
{
    $m = new ReflectionMethod($class, $method);
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); } // No-op since PHP 8.1, deprecated in 8.5.

    return $m;
}

// ---------------------------------------------------------------- PQA-AG-01.

t('PQA-AG-01: switches read at boot wait for plugins_loaded, after Pro applies a site\'s overrides', function () {
    r2fu_before_plugins_loaded(function () {
        Hardening::init();
        Router::init();
        Passwords::init();
        eq(7, has_action('plugins_loaded', array(Hardening::class, 'wire')), 'Hardening reads its switches at plugins_loaded 7');
        eq(7, has_action('plugins_loaded', array(Router::class, 'wire')), 'the router looks at the login URL again at plugins_loaded 7');
        eq(7, has_action('plugins_loaded', array(Passwords::class, 'wire')), 'the breach check too');
    });
    remove_action('plugins_loaded', array(Hardening::class, 'wire'), 7);
    remove_action('plugins_loaded', array(Router::class, 'wire'), 7);
    remove_action('plugins_loaded', array(Passwords::class, 'wire'), 7);
    ok(Pro_free_order_ok(), 'Pro boots before 7 and free flushes the settings cache at 6');
});

function Pro_free_order_ok()
{
    // Pro boots on plugins_loaded 5 (authlify-pro.php); free flushes cached settings on 6.
    return 6 === has_action('plugins_loaded', array(Settings::class, 'flush'));
}

t('PQA-AG-01: a login URL that only exists after boot (a site override) is routed', function () {
    $had = has_action('wp_loaded', array(Router::class, 'serve'));
    if (false !== $had) {
        remove_action('wp_loaded', array(Router::class, 'serve'), $had);
    }
    add_test_filter('option_authlify_settings', function ($v) {
        $v = is_array($v) ? $v : array();
        $v['login_slug'] = 'ag01-site-door';

        return $v;
    });
    Settings::flush();
    try {
        Router::wire();
        eq(1, has_action('wp_loaded', array(Router::class, 'serve')), 'serve() hooked');
        eq(10, has_filter('site_url', array(Router::class, 'filter_url')), 'URLs rewritten');
        contains('ag01-site-door', wp_login_url(), 'wp_login_url() points at the site\'s own address');
        Router::wire();
        eq(1, count(array_filter((array) $GLOBALS['wp_filter']['wp_loaded']->callbacks[1], function ($cb) {
            return is_array($cb['function']) && Router::class === $cb['function'][0] && 'serve' === $cb['function'][1];
        })), 'wiring twice adds nothing');
    } finally {
        Settings::flush();
    }
});

t('PQA-AG-01: generic errors switched on after boot are applied by wire()', function () {
    $prop = new ReflectionProperty(Hardening::class, 'wired');
    if (PHP_VERSION_ID < 80100) { $prop->setAccessible(true); } // No-op since PHP 8.1, deprecated in 8.5.
    $was = $prop->getValue();
    $prop->setValue(null, false);
    add_test_filter('option_authlify_settings', function ($v) {
        $v = is_array($v) ? $v : array();
        $v['generic_errors'] = true;
        $v['force_login'] = false;
        $v['xmlrpc'] = 'on';
        $v['block_user_enumeration'] = false;

        return $v;
    });
    Settings::flush();
    try {
        Hardening::wire();
        eq(10, has_filter('login_errors', array(Hardening::class, 'generic_login_error')));
    } finally {
        $prop->setValue(null, $was);
        Settings::flush();
    }
});

t('PQA-AG-01: the welcome-email filter leaves the text alone when a site has no login URL', function () {
    add_test_filter('option_authlify_settings', function ($v) {
        $v = is_array($v) ? $v : array();
        $v['login_slug'] = '';

        return $v;
    });
    Settings::flush();
    eq('Log in at wp-login.php', Router::filter_welcome_email('Log in at wp-login.php'));
    Settings::flush();
});

// ---------------------------------------------------------------- SEC2-10.

t('SEC2-10: the sign-up filter only acts on multisite wp-signup.php', function () {
    global $pagenow;
    $was = $pagenow;
    Settings::update(array('login_slug' => 'sec2-ten-door', 'block_wp_login' => true));
    wp_set_current_user(0);
    $pagenow = 'wp-signup.php';
    try {
        $url = 'http://example.test/sec2-ten-door/';
        eq(is_multisite() ? '#' : $url, Router::filter_login_url_on_404($url), 'single site: unchanged; network: "#"');
        $pagenow = 'index.php';
        eq($url, Router::filter_login_url_on_404($url), 'other pages unchanged');
    } finally {
        $pagenow = $was;
    }
    $defs = r2fu_private(LeakCheck::class, 'definitions')->invoke(null);
    eq(is_multisite() ? 'public' : 'hidden', $defs['wp_signup']['kind'], 'Leak Check probes wp-signup.php on multisite as a public page');
    eq('', $defs['wp_signup']['skip'], 'never skipped');
});

// ---------------------------------------------------------------- FQA-04.

t('FQA-04: on a private site, the force-login redirect is reported as info, not a leak', function () {
    $judge = r2fu_private(LeakCheck::class, 'judge');
    $def = array('label' => 'Homepage HTML', 'kind' => 'public', 'fix' => 'x');
    $response = array('code' => 302, 'location' => 'http://example.test/fqa-door/?redirect_to=http%3A%2F%2Fexample.test%2F', 'body' => '', 'headers' => array());
    Settings::update(array('force_login' => false));
    eq('fail', $judge->invoke(null, $def, $response, array('fqa-door'), true)['status'], 'force login off: a leak');
    Settings::update(array('force_login' => true));
    $r = $judge->invoke(null, $def, $response, array('fqa-door'), true);
    eq('info', $r['status'], 'force login on: by design');
    contains('Private site', $r['detail']);
    $form = array('code' => 200, 'location' => '', 'body' => '<form name="loginform" id="loginform"', 'headers' => array());
    eq('fail', $judge->invoke(null, $def, $form, array('fqa-door'), true)['status'], 'a served login form is still a failure');
    $bare = array('code' => 302, 'location' => 'http://example.test/fqa-door/', 'body' => '', 'headers' => array());
    eq('fail', $judge->invoke(null, array('label' => 'x', 'kind' => 'hidden', 'fix' => 'x'), $bare, array('fqa-door'), true)['status'], 'a redirect that is not the force-login one is still a leak');
});

// ---------------------------------------------------------------- FQA-05.

t('FQA-05: the attempt that starts a lockout says so at once', function () {
    global $wpdb, $errors;
    $ip = '198.51.100.205';
    $_SERVER['REMOTE_ADDR'] = $ip;
    Settings::update(array('limit_enabled' => true, 'limit_attempts' => 3, 'generic_errors' => true));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Limiter::table() . ' WHERE subject = %s', Limiter::ip_key($ip)));
    try {
        for ($i = 0; $i < 3; $i++) {
            Limiter::record_failure('fqa05-nobody', new WP_Error('invalid_username', 'x'));
        }
        ok(Limiter::locked_until($ip) > time(), 'locked after the 3rd failure');
        $e = Limiter::attempts_left_hint(new WP_Error('incorrect_password', 'The password you entered is incorrect.'));
        ok(in_array('authlify_locked', $e->get_error_codes(), true), 'lockout notice added to the same response');
        contains('Too many failed login attempts', $e->get_error_message('authlify_locked'));
        $errors = $e;
        $text = Hardening::generic_login_error('anything');
        contains('The username or password is incorrect.', $text, 'generic errors still hide which part was wrong');
        contains('Too many failed login attempts', $text, 'and keep the lockout notice');
        $again = Limiter::attempts_left_hint(new WP_Error('authlify_locked', 'locked'));
        eq(array('authlify_locked'), $again->get_error_codes(), 'the next try is not doubled');
        $p = new ReflectionProperty(Limiter::class, 'locked_now');
        if (PHP_VERSION_ID < 80100) { $p->setAccessible(true); } // No-op since PHP 8.1, deprecated in 8.5.
        $p->setValue(null, 0);
    } finally {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Limiter::table() . ' WHERE subject IN (%s, %s)', Limiter::ip_key($ip), Limiter::user_key('fqa05-nobody')));
        $errors = null;
    }
});

// ---------------------------------------------------------------- FQA-06.

t('FQA-06: "Redirect to" must be on this site', function () {
    $off = LoginUrlPage::validate(array('blocked_response' => 'redirect', 'blocked_redirect_url' => 'https://evil.example.com/'), 'login-url');
    ok(is_wp_error($off), 'another host is refused');
    contains('on this site', $off->get_error_message());
    $same = LoginUrlPage::validate(array('blocked_response' => 'redirect', 'blocked_redirect_url' => home_url('/sample-page/')), 'login-url');
    ok(is_array($same), 'this site is fine');
    ok(is_array(LoginUrlPage::validate(array('blocked_response' => 'redirect', 'blocked_redirect_url' => ''), 'login-url')), 'empty = homepage');
    ok(is_array(LoginUrlPage::validate(array('blocked_response' => '404', 'blocked_redirect_url' => 'https://evil.example.com/'), 'login-url')), 'ignored while the response is not a redirect');
});

// ---------------------------------------------------------------- FQA-07.

t('FQA-07: /?SLUG matches in any case, like the pretty /SLUG/ path', function () {
    $m = r2fu_private(Router::class, 'matches_slug');
    $_GET = array('SECRET-DOOR-42' => '');
    ok($m->invoke(null, '', 'secret-door-42'), 'upper case');
    $_GET = array('Secret-Door-42' => '', 'redirect_to' => 'x');
    ok($m->invoke(null, '', 'secret-door-42'), 'mixed case');
    $_GET = array('secret-door-4' => '');
    no($m->invoke(null, '', 'secret-door-42'), 'a different key does not');
    $_GET = array('SECRET-DOOR-42' => '');
    no($m->invoke(null, 'some-page', 'secret-door-42'), 'only on the homepage');
});

// ---------------------------------------------------------------- FQA-08.

t('FQA-08: Limit Login Attempts Reloaded is offered when active, even before its settings were saved', function () {
    delete_option('limit_login_allowed_retries');
    $importers = ToolsPage::importers();
    no(call_user_func($importers['limit-login-attempts-reloaded'][1]), 'not installed: not offered');
    add_test_filter('option_active_plugins', function ($v) {
        return array_merge((array) $v, array('limit-login-attempts-reloaded/limit-login-attempts-reloaded.php'));
    });
    ok(call_user_func($importers['limit-login-attempts-reloaded'][1]), 'active with no saved options: offered');
    call_user_func($importers['limit-login-attempts-reloaded'][2]);
    eq(4, Settings::get('limit_attempts'), 'its default of 4 retries');
    eq(20, Settings::get('lockout_minutes'), 'and 20 minutes');
});

// ---------------------------------------------------------------- FQA-10.

t('FQA-10: LoginPress 6.x button text and hover colours are imported', function () {
    $d = Migration::map_loginpress(array('button_text_color' => '#ffffff', 'button_hover_color' => '#123456', 'button_hover_text_color' => '#fefefe', 'custom_button_color' => '#00aa00'));
    eq('#ffffff', $d['button']['text']);
    eq('#123456', $d['button']['hover_background']);
    eq('#fefefe', $d['button']['hover_text']);
    $old = Migration::map_loginpress(array('login_button_text_color' => '#eeeeee', 'login_button_hover' => '#333333'));
    eq('#eeeeee', $old['button']['text'], 'older key names still work');
    eq('#333333', $old['button']['hover_background']);
});

// ---------------------------------------------------------------- FQA-11.

t('FQA-11: a reCAPTCHA v3 threshold that is not a number is never saved as 0.0', function () {
    $v = CaptchaAdmin::validate(array('captcha_v3_threshold' => 'abc'), 'protection', 'captcha');
    ok(is_array($v) && !array_key_exists('captcha_v3_threshold', $v), 'dropped: the current value stays');
    $v = CaptchaAdmin::validate(array('captcha_v3_threshold' => '0,7'), 'protection', 'captcha');
    eq('0.7', $v['captcha_v3_threshold'], 'a comma decimal still works');
    $v = CaptchaAdmin::validate(array('captcha_v3_threshold' => '5'), 'protection', 'captcha');
    eq('1.0', $v['captcha_v3_threshold'], 'clamped');
    Settings::update(array('captcha_v3_threshold' => 'abc'));
    eq(0.5, RecaptchaV3::threshold(), 'a stored non-number reads as the default');
    if (false === has_filter('authlify_validate_settings', array(CaptchaAdmin::class, 'validate'))) {
        add_test_filter('authlify_validate_settings', array(CaptchaAdmin::class, 'validate'), 10, 3); // Registered in wp-admin only.
    }
    $r = r2fu_private(ToolsPage::class, 'validate_import')->invoke(null, array('captcha_v3_threshold' => 'abc', 'limit_attempts' => 4));
    ok(is_array($r) && !isset($r['captcha_v3_threshold']), 'settings import drops it too');
});

// ---------------------------------------------------------------- UX2-04.

t('UX2-04: events from an add-on that is off get a readable label', function () {
    eq('Logged in', Log::label('login_success'));
    // As after Authlify Pro is deactivated: its labels are gone.
    eq('Plugin deactivated', Log::label('plugin_deactivated', array()));
    eq('Promoted to administrator', Log::label('role_admin', array()));
    eq('Temporary access used', Log::label('temp_access_used', array()));
    eq('Two-factor setup done', Log::label('twofa_setup_done', array()));
    eq('Custom label', Log::label('x_y', array('x_y' => 'Custom label')), 'known labels win');
    eq('', Log::label(''), 'empty stays empty');
});

// ---------------------------------------------------------------- UX2-01.

t('UX2-01: the language switcher button follows the design', function () {
    $d = Design::sanitize(\Authlify\Designer\Templates::get('corporate-blue'));
    $css = Compiler::compile($d);
    ok((bool) preg_match('/#language-switcher \.button\{color:[^;]+;border-color:[^;]+;background:transparent/', $css), 'outline button in the page text colour');
    contains('.language-switcher label .dashicons', $css, 'the globe icon too');
});

// ---------------------------------------------------------------- PQA-AG-10 (free side).

t('PQA-AG-10: the settings export file name can be filtered', function () {
    $src = file_get_contents(AUTHLIFY_DIR . 'inc/Admin/ToolsPage.php');
    contains("apply_filters('authlify_export_filename'", $src);
});

// ---------------------------------------------------------------- FQA-09.

if (!function_exists('wc_get_page_permalink')) {
    // Stand-in for WooCommerce (not installed on the test site).
    function wc_get_page_permalink($page)
    {
        return home_url('/my-account/');
    }
}

t('FQA-09: a WooCommerce customer who is locked out can ask for an unlock link without the login URL', function () {
    Settings::update(array('login_slug' => 'fqa09-hidden-door', 'block_wp_login' => true));
    wp_set_current_user(0);
    $_POST = array('login' => 'Log in', 'username' => 'frank', 'password' => 'x');
    $msg = \Authlify\Login\Recovery::lockout_message_link('Too many failed login attempts.', time() + 600);
    contains('/my-account/?authlify_unlock=1', $msg, 'link to My Account');
    not_contains('fqa09-hidden-door', $msg, 'never the login URL');
    $_POST = array();
    eq('Too many failed login attempts.', \Authlify\Login\Recovery::lockout_message_link('Too many failed login attempts.', time() + 600), 'other front-end forms: no link');

    $_GET = array('authlify_unlock' => '1');
    ob_start();
    \Authlify\Login\Recovery::woo_unlock_form();
    $html = ob_get_clean();
    contains('name="authlify_unlock_login"', $html, 'the request form');
    contains('name="authlify_unlock_nonce"', $html);
    not_contains('fqa09-hidden-door', $html);
    $_GET = array();
    ob_start();
    \Authlify\Login\Recovery::woo_unlock_form();
    eq('', ob_get_clean(), 'only when asked for');
});
