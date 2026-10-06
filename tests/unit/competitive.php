<?php
/**
 * 3.1.0 competitive features (BUILD-free-competitive):
 * - CMPT-10: the CAPTCHA is checked before the password (no hash for bots);
 * - CMP-06: integration helpers, the render arguments, WooCommerce classic
 *   checkout posted to xmlrpc.php (SEC2-03 leftover);
 * - CMP-08: block list from the log, automatic block after N lockouts;
 * - CMP-09: authenticator-app import from Two Factor and WP 2FA;
 * - CMP-10: new sign-in emails;
 * - CMPT-12: WPS Hide Login detected on its defaults.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Captcha\Captcha;
use Authlify\Captcha\Integrations;
use Authlify\Security\Limiter;
use Authlify\Security\SigninNotice;
use Authlify\Settings;
use Authlify\TwoFactor\Import;
use Authlify\TwoFactor\Totp;

function cmp_captcha_reset()
{
    $p = new ReflectionProperty(Captcha::class, 'checked');
    if (PHP_VERSION_ID < 80100) { $p->setAccessible(true); } // No-op since PHP 8.1, deprecated in 8.5.
    $p->setValue(null, array());
}

function cmp_log_count($event)
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . \Authlify\Log\Log::table() . ' WHERE event = %s', $event));
}

/** Mail sent during the test: list of wp_mail() arguments. */
function cmp_capture_mail()
{
    $GLOBALS['cmp_mail'] = array();
    add_test_filter('pre_wp_mail', function ($return, $atts) {
        $GLOBALS['cmp_mail'][] = $atts;

        return true;
    }, 1, 2);
}

/** WP 2FA's encryption (AES-256-CTR under SHA-256 of its secret), for fixtures. */
function cmp_wp2fa_encrypt($plain, $material)
{
    $iv = random_bytes(16);
    $key = openssl_digest(base64_decode($material), 'SHA256', true);

    return 'lsc_' . base64_encode($iv . openssl_encrypt($plain, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv));
}

before_each(function () {
    cmp_captcha_reset();
    set_settings(array(
        'limit_enabled' => true, 'limit_attempts' => 5, 'limit_window' => 15, 'lockout_minutes' => 15, 'lockout_escalate' => true,
        'ip_source' => 'remote_addr', 'ip_allowlist' => '', 'ip_denylist' => '', 'auto_block_lockouts' => 0,
        'captcha_provider' => 'turnstile', 'captcha_site_key' => 'k', 'captcha_secret_key' => 's',
        'captcha_forms' => array('login', 'register', 'lostpassword'), 'captcha_mode' => 'always', 'captcha_test_mode' => false, 'honeypot' => false,
        'signin_notice' => false, 'signin_notice_roles' => array('administrator'), 'log_enabled' => true, 'log_anonymize_ip' => false,
    ));
    as_ip('198.51.100.200');
});

/* ------------------------------------------------------------ CMPT-10 */

t('CMPT-10: a login without a CAPTCHA answer is refused before the password is hashed, and core\'s password check is put back afterwards', function () {
    $user = make_user('subscriber');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('log' => $user->user_login, 'pwd' => 'wrong');
    add_test_filter('authlify_captcha_login_form', function () {
        return 'login';
    });
    $hashed = 0;
    add_test_filter('check_password', function ($check) use (&$hashed) {
        $hashed++;

        return $check;
    });

    $result = wp_authenticate($user->user_login, 'wrong');
    is_error_code('authlify_captcha', $result);
    eq(0, $hashed, 'no password check');
    eq(20, has_filter('authenticate', 'wp_authenticate_username_password'), 'core filter restored');
    eq(20, has_filter('authenticate', 'wp_authenticate_email_password'), 'email filter restored');
    eq(0, Limiter::ip_failures('198.51.100.200'), 'a CAPTCHA failure is not a password failure');
});

t('CMPT-10: with a passing CAPTCHA the password is checked as before', function () {
    $user = make_user('subscriber');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('cf-turnstile-response' => 'good');
    add_test_filter('authlify_captcha_login_form', function () {
        return 'login';
    });
    mock_http(function () {
        return http_response(200, array('success' => true, 'hostname' => wp_parse_url(home_url(), PHP_URL_HOST)));
    });
    $hashed = 0;
    add_test_filter('check_password', function ($check) use (&$hashed) {
        $hashed++;

        return $check;
    });

    is_error_code('incorrect_password', wp_authenticate($user->user_login, 'wrong'));
    ok($hashed > 0, 'password checked');
});

t('CMPT-10: a request no form claims is never checked (API, passkeys, other plugins)', function () {
    $user = make_user('subscriber');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    is_error_code('incorrect_password', wp_authenticate($user->user_login, 'wrong'));
});

/* ------------------------------------------------------------ CMP-06 helpers */

t('CMP-06: render() can ask to be moved in front of the submit button and carry data attributes', function () {
    $html = Captcha::render('login', array('before' => '.edd-login-submit', 'attrs' => array('wc-blocks' => '1')));
    contains('data-before=".edd-login-submit"', $html);
    contains('data-wc-blocks="1"', $html);
    contains('data-form="login"', $html);
    eq(Captcha::render('login'), preg_replace('/ data-(before|wc-blocks)="[^"]*"/', '', $html), 'otherwise the same markup');
});

t('CMP-06: messages for other plugins lose core\'s "Error:" label but keep a lockout sentence', function () {
    eq('Please complete the security check.', Integrations::plain(new WP_Error('authlify_captcha', '<strong>Error:</strong> Please complete the security check.')));
    eq('Too many failed login attempts. Please try again in 15 minutes.', Integrations::plain(new WP_Error('authlify_locked', '<strong>Too many failed login attempts.</strong> Please try again in 15 minutes.')));
    eq('Locked. Is this your account?', Integrations::plain(new WP_Error('authlify_locked', 'Locked.<br><a href="#">Is this your account?</a>')));
});

t('CMP-06: an integration\'s login gate returns the block list, the lockout, then the CAPTCHA', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('x' => '1');
    set_settings(array('ip_denylist' => '198.51.100.200'));
    is_error_code('authlify_denied', Integrations::login_gate('someone'));
    set_settings(array('ip_denylist' => ''));
    is_error_code('authlify_captcha', Integrations::login_gate('someone'));
    foreach (array('authlify_locked', 'authlify_denied', 'authlify_captcha') as $code) {
        ok(in_array($code, Integrations::error_codes(), true), $code);
    }
});

t('CMP-06: integrations load only for active plugins', function () {
    $loaded = Integrations::loaded();
    eq(class_exists('WooCommerce'), in_array('woo_blocks', $loaded, true), 'Woo blocks');
    eq(function_exists('edd_log_user_in'), in_array('edd', $loaded, true), 'EDD');
    eq(function_exists('UM'), in_array('ultimate_member', $loaded, true), 'UM');
    eq(function_exists('buddypress'), in_array('buddypress', $loaded, true), 'BuddyPress');
    eq(defined('MEPR_VERSION'), in_array('memberpress', $loaded, true), 'MemberPress');
});

t('SEC2-03 leftover: a WooCommerce classic checkout posted to xmlrpc.php still needs the CAPTCHA', function () {
    set_settings(array('captcha_forms' => array('woo_checkout')));
    $out = subprocess('define("XMLRPC_REQUEST", true); $_SERVER["REQUEST_METHOD"] = "POST"; wp_set_current_user(0);'
        . ' $_POST["woocommerce-process-checkout-nonce"] = wp_create_nonce("woocommerce-process_checkout"); $_REQUEST = $_POST;'
        . ' $e = new WP_Error(); \Authlify\Captcha\Captcha::check_woo_checkout(array("billing_email" => "a@example.test"), $e); echo "codes:" . implode(",", $e->get_error_codes());'
        . ' $_POST = $_REQUEST = array(); \Authlify\Captcha\Captcha::check_woo_checkout(array(), $e2 = new WP_Error()); echo " plain-xmlrpc:" . implode(",", $e2->get_error_codes());');
    contains('codes:authlify_captcha', $out, 'checkout nonce: checked');
    contains('plain-xmlrpc:', $out);
    not_contains('plain-xmlrpc:authlify', $out, 'a real XML-RPC call is not');
});

/* ------------------------------------------------------------ CMP-08 */

t('CMP-08: deny() adds an address or range once, refuses bad input and allowlisted addresses, and logs it', function () {
    $before = cmp_log_count('ip_blocked');
    eq(true, Limiter::deny('198.51.100.9', array('by' => 'admin')));
    ok(Limiter::is_denylisted('198.51.100.9'));
    is_error_code('authlify_already_blocked', Limiter::deny('198.51.100.9'));
    eq(true, Limiter::deny('2001:db8:1:2::/64'));
    ok(Limiter::is_denylisted('2001:db8:1:2::abcd'));
    is_error_code('authlify_bad_ip', Limiter::deny('not-an-ip'));
    is_error_code('authlify_bad_ip', Limiter::deny('198.51.100.0/40'));
    set_settings(array('ip_allowlist' => '198.51.100.77'));
    is_error_code('authlify_allowlisted', Limiter::deny('198.51.100.77'));
    eq($before + 2, cmp_log_count('ip_blocked'));
    eq(array('198.51.100.9', '2001:db8:1:2::/64'), Settings::lines('ip_denylist'));
});

t('CMP-08: "Block" is offered for other addresses only, never your own or listed ones', function () {
    as_ip('198.51.100.200');
    no(\Authlify\Admin\ProtectionPage::can_block('198.51.100.200'), 'own address');
    no(\Authlify\Admin\ProtectionPage::can_block('198.51.100.0/24'), 'a range with your own address');
    ok(\Authlify\Admin\ProtectionPage::can_block('198.51.100.10'));
    set_settings(array('ip_denylist' => '198.51.100.10', 'ip_allowlist' => '198.51.100.11'));
    no(\Authlify\Admin\ProtectionPage::can_block('198.51.100.10'), 'already blocked');
    no(\Authlify\Admin\ProtectionPage::can_block('198.51.100.11'), 'allowlisted');
    no(\Authlify\Admin\ProtectionPage::can_block('nonsense'));
});

t('CMP-08: off by default; the Nth lockout in a row adds the address to the block list', function () {
    eq(0, (int) Settings::defaults()['auto_block_lockouts'], 'default off');
    global $wpdb;
    set_settings(array('limit_attempts' => 1, 'auto_block_lockouts' => 2));
    as_ip('198.51.100.31');
    $_POST = array('log' => 'x');
    Limiter::record_failure('nobody', new WP_Error('incorrect_password', 'x'));
    ok(Limiter::locked_until('198.51.100.31') > time(), 'first lockout');
    no(Limiter::is_denylisted('198.51.100.31'), 'not blocked after one');
    // The first lockout ends; the next failure locks again (second in a row).
    $wpdb->query($wpdb->prepare('UPDATE ' . Limiter::table() . " SET locked_until = %d WHERE scope = 'ip' AND subject = %s", time() - 5, '198.51.100.31'));
    Limiter::record_failure('nobody', new WP_Error('incorrect_password', 'x'));
    ok(Limiter::is_denylisted('198.51.100.31'), 'blocked after two');
    is_error_code('authlify_denied', Limiter::gate_error('anyone'));
});

t('CMP-08: never blocks a private address, nor one an administrator logged in from; stops when the list is full', function () {
    set_settings(array('auto_block_lockouts' => 1));
    no(Limiter::maybe_auto_block('10.1.2.3', 5), 'private');
    no(Limiter::maybe_auto_block('127.0.0.1', 5), 'loopback');

    $admin = make_user('administrator');
    \Authlify\Log\Log::add('login_success', array('user_id' => $admin->ID, 'username' => $admin->user_login, 'ip' => '198.51.100.44'));
    no(Limiter::maybe_auto_block('198.51.100.44', 5), 'admin address');
    no(Limiter::is_denylisted('198.51.100.44'));

    add_test_filter('authlify_auto_block_cap', function () {
        return 10;
    });
    set_settings(array('ip_denylist' => implode("\n", array_map(function ($i) {
        return '192.0.2.' . $i;
    }, range(1, 10)))));
    no(Limiter::maybe_auto_block('198.51.100.45', 5), 'list full');
    set_settings(array('ip_denylist' => ''));
    ok(Limiter::maybe_auto_block('198.51.100.45', 1), 'otherwise blocked');
    no(Limiter::maybe_auto_block('198.51.100.46', 0), 'below the count');
});

/* ------------------------------------------------------------ CMP-09 */

t('CMP-09: Two Factor secrets: preview changes nothing, import keeps the same app entry, skips users without it turned on or already set up', function () {
    $on = make_user('editor');
    $off = make_user('subscriber');
    $has = make_user('subscriber');
    $secret = Totp::new_secret();
    update_user_meta($on->ID, '_two_factor_totp_key', $secret);
    update_user_meta($on->ID, '_two_factor_enabled_providers', array('Two_Factor_Totp', 'Two_Factor_Backup_Codes'));
    update_user_meta($off->ID, '_two_factor_totp_key', Totp::new_secret());
    update_user_meta($off->ID, '_two_factor_enabled_providers', array('Two_Factor_Email'));
    update_user_meta($has->ID, '_two_factor_totp_key', Totp::new_secret());
    update_user_meta($has->ID, '_two_factor_enabled_providers', array('Two_Factor_Totp'));
    Totp::import_secret($has->ID, Totp::new_secret());

    $preview = Import::run('two-factor', true);
    ok($preview['dry']);
    ok($preview['imported'] >= 1, 'would import');
    no(Totp::is_configured($on->ID), 'preview changed nothing');

    $before = cmp_log_count('twofa_import');
    $result = Import::run('two-factor', false);
    ok(Totp::is_configured($on->ID), 'imported');
    ok(true === Totp::verify($on->ID, Totp::code($secret, Totp::step())), 'the code from the same app works');
    no(Totp::is_configured($off->ID), 'not turned on there: skipped');
    ok($result['skipped']['not_enabled'] >= 1);
    ok($result['skipped']['already'] >= 1);
    ok(\Authlify\TwoFactor\TwoFactor::is_active_for($on->ID), 'two-factor flag on');
    eq($before + 1, cmp_log_count('twofa_import'), 'logged');
    contains('Two Factor', Import::summary($result));
    eq($secret, get_user_meta($on->ID, '_two_factor_totp_key', true), 'the other plugin\'s data is left alone');
});

t('CMP-09: WP 2FA secrets are decrypted with its key (constant or option); unreadable ones are skipped', function () {
    if (!function_exists('openssl_encrypt')) {
        skip('OpenSSL is not available');
    }
    if (defined('WP2FA_ENCRYPT_KEY')) {
        skip('WP2FA_ENCRYPT_KEY is defined on this site');
    }
    $material = base64_encode(random_bytes(16));
    update_option('wp_2fa_secret_key', $material);
    try {
        $u = make_user('author');
        $bad = make_user('author');
        $secret = Totp::new_secret();
        update_user_meta($u->ID, 'wp_2fa_totp_key', cmp_wp2fa_encrypt($secret, $material));
        update_user_meta($u->ID, 'wp_2fa_enabled_methods', 'totp');
        update_user_meta($bad->ID, 'wp_2fa_totp_key', cmp_wp2fa_encrypt($secret, base64_encode(random_bytes(16))));
        update_user_meta($bad->ID, 'wp_2fa_enabled_methods', 'totp');

        eq($secret, Import::wp2fa_secret(get_user_meta($u->ID, 'wp_2fa_totp_key', true)));
        eq('', Import::wp2fa_secret('lsc_' . base64_encode('garbage-garbage-garbage')));
        eq($secret, Import::wp2fa_secret($secret), 'stored without encryption');

        $result = Import::run('wp-2fa', false);
        ok(Totp::is_configured($u->ID));
        ok(true === Totp::verify($u->ID, Totp::code($secret, Totp::step())));
        no(Totp::is_configured($bad->ID), 'wrong key: skipped');
        ok($result['skipped']['unreadable'] >= 1);
    } finally {
        delete_option('wp_2fa_secret_key');
    }
});

t('CMP-09: import_secret() refuses an invalid key and never replaces an existing one', function () {
    $u = make_user('subscriber');
    is_error_code('authlify_totp_import_invalid', Totp::import_secret($u->ID, 'not base32!'));
    is_error_code('authlify_totp_import_invalid', Totp::import_secret($u->ID, 'ABCD'));
    eq(true, Totp::import_secret($u->ID, 'jbsw y3dp ehpk 3pxp'), 'spaces and case ignored');
    is_error_code('authlify_totp_import_exists', Totp::import_secret($u->ID, Totp::new_secret()));
});

/* ------------------------------------------------------------ CMP-10 */

t('CMP-10: off by default', function () {
    no(Settings::defaults()['signin_notice']);
    $u = make_user('administrator');
    no(SigninNotice::covers($u));
});

t('CMP-10: the first sign-in is remembered, a new address or device is emailed once, then rate-limited; other roles are not emailed', function () {
    set_settings(array('signin_notice' => true, 'signin_notice_roles' => array('editor')));
    cmp_capture_mail();
    add_test_filter('authlify_signin_notice_email', function ($send) {
        return $send && !SigninNotice::pro_handles();
    });
    $u = make_user('editor');
    $other = make_user('subscriber');
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; rv:131.0) Gecko/20100101 Firefox/131.0';

    as_ip('198.51.100.60');
    SigninNotice::on_login($u->user_login, $u);
    eq(0, count($GLOBALS['cmp_mail']), 'first sign-in: only remembered');
    SigninNotice::on_login($u->user_login, $u);
    eq(0, count($GLOBALS['cmp_mail']), 'same place');

    // A browser update (new version number) is the same device.
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; rv:132.0) Gecko/20100101 Firefox/132.0';
    SigninNotice::on_login($u->user_login, $u);
    eq(0, count($GLOBALS['cmp_mail']), 'new browser version');

    as_ip('198.51.100.61');
    SigninNotice::on_login($u->user_login, $u);
    if (SigninNotice::pro_handles($u)) {
        eq(0, count($GLOBALS['cmp_mail']), 'Authlify Pro sends its own');
        return;
    }
    eq(1, count($GLOBALS['cmp_mail']), 'new address');
    eq($u->user_email, $GLOBALS['cmp_mail'][0]['to']);
    contains('198.51.100.61', $GLOBALS['cmp_mail'][0]['message']);
    contains('Firefox on Windows', $GLOBALS['cmp_mail'][0]['message']);

    as_ip('198.51.100.62');
    SigninNotice::on_login($u->user_login, $u);
    eq(1, count($GLOBALS['cmp_mail']), 'one email per 15 minutes');

    as_ip('198.51.100.70');
    SigninNotice::on_login($other->user_login, $other);
    SigninNotice::on_login($other->user_login, $other);
    eq(1, count($GLOBALS['cmp_mail']), 'role not chosen');
    delete_transient('authlify_signin_notice_' . $u->ID);
});

t('CMP-10: Authlify Pro\'s device alerts take over for the users they cover (no double email)', function () {
    if (!class_exists('AuthlifyPro\Alerts\Alerts')) {
        skip('Authlify Pro is not active');
    }
    set_settings(array('signin_notice' => true, 'signin_notice_roles' => array('editor'), 'pro_alert_new_device' => true, 'pro_alert_roles' => array('editor')));
    $u = make_user('editor');
    $a = make_user('author');
    ok(SigninNotice::pro_handles($u), 'covered by Pro');
    no(SigninNotice::pro_handles($a), 'role Pro does not cover');
    set_settings(array('pro_alert_new_device' => false));
    no(SigninNotice::pro_handles($u), 'Pro alerts off');
});

t('CMP-10: device labels ignore version numbers', function () {
    $a = SigninNotice::device('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15');
    $b = SigninNotice::device('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15');
    eq('Safari on macOS', $a['label']);
    eq($a['key'], $b['key']);
    eq('Chrome on Android', SigninNotice::device('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36')['label']);
    eq('Edge on Windows', SigninNotice::device('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0')['label']);
});

/* ------------------------------------------------------------ CMPT-12 */

t('CMPT-12: WPS Hide Login and LLAR are offered when active on their defaults (never saved)', function () {
    $active = get_option('active_plugins');
    delete_option('whl_page');
    delete_option('limit_login_allowed_retries');
    try {
        update_option('active_plugins', array_merge((array) $active, array('wps-hide-login/wps-hide-login.php', 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php')));
        $importers = \Authlify\Admin\ToolsPage::importers();
        ok(call_user_func($importers['wps-hide-login'][1]), 'WPS Hide Login found');
        ok(call_user_func($importers['limit-login-attempts-reloaded'][1]), 'LLAR found');
        update_option('active_plugins', $active);
        no(call_user_func($importers['wps-hide-login'][1]), 'not active, nothing saved: not offered');
    } finally {
        update_option('active_plugins', $active);
    }
});
