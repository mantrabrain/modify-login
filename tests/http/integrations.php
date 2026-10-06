<?php
/**
 * 3.1.0 over HTTP:
 * - CAPTCHA, lockout and block-list coverage of other plugins' forms
 *   (Easy Digital Downloads, Ultimate Member, BuddyPress, WooCommerce block
 *   checkout) and that none of them adds a leak of the login slug;
 * - "Block" from the log (CMP-08), the 2FA import screen action (CMP-09),
 *   and the new sign-in email on a real login (CMP-10).
 *
 * Each plugin test runs only when that plugin's folder is in the test site's
 * plugins directory (symlink them from the wordpress.org zips); otherwise it
 * is skipped. Plugins are activated per test and deactivated afterwards.
 * Turnstile's siteverify is answered by the harness (token "good" passes).
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

const ISLUG = 'int-door';

function int_has($plugin)
{
    return is_dir(SITE_DIR . '/wp-content/plugins/' . $plugin);
}

function int_activate($plugin)
{
    if (!int_has($plugin)) {
        skip($plugin . ' is not installed on the test site');
    }
    wp_cli('plugin activate ' . escapeshellarg($plugin));
    get(BASE . '/'); // first request finishes the plugin's own setup
}

function int_deactivate($plugin)
{
    wp_cli('plugin deactivate ' . escapeshellarg($plugin));
}

function int_logged_in($res)
{
    foreach (live_cookies($res) as $name => $v) {
        if (0 === strpos($name, 'wordpress_logged_in_')) {
            return true;
        }
    }

    return false;
}

function int_field($html, $name)
{
    return preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $m) || preg_match('/value="([^"]*)"[^>]*name="' . preg_quote($name, '/') . '"/', $html, $m) ? html_entity_decode($m[1]) : '';
}

/** Text of the page without tags. */
function int_text($html)
{
    return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
}

/** A published page with this content (deleted by the test that made it). */
function int_page($slug, $content)
{
    return wp_eval('$p = get_page_by_path("' . $slug . '"); if ($p) { wp_delete_post($p->ID, true); } $id = wp_insert_post(array("post_type" => "page", "post_status" => "publish", "post_name" => "' . $slug . '", "post_title" => "' . $slug . '", "post_content" => base64_decode("' . base64_encode($content) . '"))); echo wp_make_link_relative(get_permalink($id));');
}

/** Slug occurrences on a page with the CAPTCHA off and on (an integration must add none). */
function int_no_new_leak($path)
{
    settings(array('captcha_forms' => array()));
    $off = substr_count(get($path)->body, ISLUG);
    settings(array('captcha_forms' => array('login', 'register', 'lostpassword', 'woo_checkout', 'woo_register')));
    $res = get($path);
    contains('authlify-captcha', $res->body, 'widget on ' . $path);
    eq($off, substr_count($res->body, ISLUG), 'no new slug occurrence on ' . $path);
    preg_match_all('/<div class="authlify-captcha.*?<\/div>\s*(<noscript>.*?<\/noscript>)?\s*<\/div>/s', $res->body, $m);
    foreach ($m[0] as $box) {
        not_contains(ISLUG, $box, 'widget markup');
    }
}

before_all(function () {
    $GLOBALS['iu'] = http_user('subscriber');
    wp_eval('update_option("authlify_test_http", array(array("match" => "challenges.cloudflare.com", "siteverify" => true))); update_option("users_can_register", 1);');
    settings(array(
        'login_slug' => ISLUG, 'block_wp_login' => true, 'limit_enabled' => true, 'limit_attempts' => 5, 'lockout_minutes' => 15,
        'captcha_provider' => 'turnstile', 'captcha_site_key' => 'test-site-key', 'captcha_secret_key' => 'test-secret',
        'captcha_forms' => array('login', 'register', 'lostpassword', 'woo_checkout'), 'captcha_mode' => 'always', 'captcha_test_mode' => false,
        'honeypot' => false, 'ip_allowlist' => '', 'ip_denylist' => '', 'auto_block_lockouts' => 0, 'signin_notice' => false,
    ));
});

after_all(function () {
    foreach (array('easy-digital-downloads', 'ultimate-member', 'buddypress', 'woocommerce') as $p) {
        if (int_has($p)) {
            int_deactivate($p);
        }
    }
    wp_eval('update_option("users_can_register", 0); foreach (array("int-edd-login", "int-edd-register") as $s) { $p = get_page_by_path($s); if ($p) { wp_delete_post($p->ID, true); } }');
});

/* ---------------------------------------------------------- Easy Digital Downloads */

t('EDD: CAPTCHA on the login and registration forms, Authlify\'s lockout message instead of "Invalid username or password", no new slug leak', function () {
    int_activate('easy-digital-downloads');
    try {
        $u = $GLOBALS['iu'];
        $login = int_page('int-edd-login', '[edd_login]');
        $register = int_page('int-edd-register', '[edd_register]');
        int_no_new_leak($login);
        int_no_new_leak($register);

        $form = get($login)->body;
        contains('data-before=".edd-login-submit', $form, 'moved in front of the button');
        $post = function ($pass, $ip, $extra = array()) use ($login, $form, $u) {
            return post($login, array_merge(array('edd_user_login' => $u['login'], 'edd_user_pass' => $pass, 'edd_login_nonce' => int_field($form, 'edd_login_nonce'), 'edd_action' => 'user_login', 'edd_redirect' => BASE . '/'), $extra), array('ip' => $ip));
        };

        $res = $post($u['pass'], '203.0.113.201');
        no(int_logged_in($res), 'no token');
        contains('Please complete the security check', int_text($res->body));
        not_contains('Invalid username or password', int_text($res->body));

        ok(int_logged_in($post($u['pass'], '203.0.113.202', array('cf-turnstile-response' => 'good'))), 'good token logs in');

        for ($i = 0; $i < 5; $i++) {
            $post('wrong-' . $i, '203.0.113.203', array('cf-turnstile-response' => 'good'));
        }
        $res = $post($u['pass'], '203.0.113.203', array('cf-turnstile-response' => 'good'));
        no(int_logged_in($res), 'locked out');
        contains('Too many failed login attempts', int_text($res->body));
        not_contains('Invalid username or password', int_text($res->body), 'a locked-out customer is not told the password is wrong');

        $rform = get($register)->body;
        sleep(2);
        $name = 'atest_edd_' . substr(md5(uniqid('', true)), 0, 6);
        $data = array('edd_user_login' => $name, 'edd_user_email' => $name . '@example.test', 'edd_user_pass' => 'Reg-Pass-123456!', 'edd_user_pass2' => 'Reg-Pass-123456!', 'edd_register_timestamp' => int_field($rform, 'edd_register_timestamp'), 'edd_register_token' => int_field($rform, 'edd_register_token'), 'edd_honeypot' => '', 'edd_action' => 'user_register', 'edd_redirect' => BASE . '/', 'edd_register_submit' => 'Register');
        $res = post($register, $data, array('ip' => '203.0.113.204'));
        contains('Please complete the security check', int_text($res->body));
        eq('0', wp_eval('echo (int) username_exists("' . $name . '");'), 'not registered');
        $res = post($register, array_merge($data, array('cf-turnstile-response' => 'good')), array('ip' => '203.0.113.204'));
        $id = (int) wp_eval('echo (int) username_exists("' . $name . '");');
        ok($id > 0, 'registered with a token');
        $GLOBALS['http_users'][] = $id;
    } finally {
        int_deactivate('easy-digital-downloads');
    }
});

/* ---------------------------------------------------------- Ultimate Member */

t('Ultimate Member: CAPTCHA on login, registration and password reset; a locked-out member sees the lockout, never "Password is incorrect" (CMPT-08); no new slug leak', function () {
    int_activate('ultimate-member');
    try {
        $pages = wp_json('wp_set_current_user(1); UM()->setup()->install_default_forms(); UM()->setup()->install_default_pages(); flush_rewrite_rules(); $o = get_option("um_options"); $f = get_option("um_core_forms");'
            . ' echo json_encode(array("login" => wp_make_link_relative(get_permalink($o["core_login"])), "register" => wp_make_link_relative(get_permalink($o["core_register"])), "reset" => wp_make_link_relative(get_permalink($o["core_password-reset"])), "lf" => (int) $f["login"], "rf" => (int) $f["register"]));');
        foreach (array('login', 'register', 'reset') as $k) {
            int_no_new_leak($pages[$k]);
        }

        $u = $GLOBALS['iu'];
        $lf = $pages['lf'];
        $post = function ($pass, $ip, $extra = array()) use ($pages, $lf, $u) {
            $form = get($pages['login'])->body;

            return post($pages['login'], array_merge(array('username-' . $lf => $u['login'], 'user_password-' . $lf => $pass, 'form_id' => $lf, 'um_request' => '', '_wpnonce' => int_field($form, '_wpnonce')), $extra), array('ip' => $ip));
        };

        $res = $post($u['pass'], '203.0.113.211');
        no(int_logged_in($res));
        contains('Please complete the security check', int_text($res->body));
        ok(int_logged_in($post($u['pass'], '203.0.113.212', array('cf-turnstile-response' => 'good'))), 'good token');

        for ($i = 0; $i < 5; $i++) {
            $post('wrong-' . $i, '203.0.113.213', array('cf-turnstile-response' => 'good'));
        }
        $right = $post($u['pass'], '203.0.113.213', array('cf-turnstile-response' => 'good'));
        $wrong = $post('still-wrong', '203.0.113.213', array('cf-turnstile-response' => 'good'));
        no(int_logged_in($right));
        contains('Too many failed login attempts', int_text($right->body));
        not_contains('Password is incorrect', int_text($right->body), 'right password while locked');
        not_contains('Password is incorrect', int_text($wrong->body), 'wrong password while locked: same answer, no oracle');

        settings(array('ip_denylist' => '203.0.113.214'));
        $res = $post($u['pass'], '203.0.113.214', array('cf-turnstile-response' => 'good'));
        contains('Access from your network is blocked', int_text($res->body));
        settings(array('ip_denylist' => ''));

        $rf = $pages['rf'];
        $name = 'atest_um_' . substr(md5(uniqid('', true)), 0, 6);
        $reg = function ($extra) use ($pages, $rf, $name) {
            $form = get($pages['register'])->body;

            return post($pages['register'], array_merge(array('user_login-' . $rf => $name, 'user_email-' . $rf => $name . '@example.test', 'user_password-' . $rf => 'Reg-Pass-123456!', 'confirm_user_password-' . $rf => 'Reg-Pass-123456!', 'first_name-' . $rf => 'A', 'last_name-' . $rf => 'B', 'form_id' => $rf, 'um_request' => '', '_wpnonce' => int_field($form, '_wpnonce')), $extra), array('ip' => '203.0.113.215'));
        };
        contains('Please complete the security check', int_text($reg(array())->body));
        eq('0', wp_eval('echo (int) username_exists("' . $name . '");'));
        $reg(array('cf-turnstile-response' => 'good'));
        $id = (int) wp_eval('echo (int) username_exists("' . $name . '");');
        ok($id > 0, 'registered with a token');
        $GLOBALS['http_users'][] = $id;

        $reset = function ($extra) use ($pages, $u) {
            return post($pages['reset'], array_merge(array('username_b' => $u['login'], '_um_password_reset' => '1', 'form_id' => 'um_password_id', 'um_request' => ''), $extra), array('ip' => '203.0.113.216'));
        };
        $res = $reset(array());
        eq(200, $res->code);
        contains('Please complete the security check', int_text($res->body));
        $res = $reset(array('cf-turnstile-response' => 'good'));
        contains('checkemail', $res->location, 'accepted with a token');
    } finally {
        int_deactivate('ultimate-member');
    }
});

/* ---------------------------------------------------------- BuddyPress */

t('BuddyPress: CAPTCHA on the sign-up page, no new slug leak', function () {
    int_activate('buddypress');
    try {
        $path = wp_eval('wp_set_current_user(1); bp_core_add_page_mappings(array("register" => 1, "activate" => 1, "members" => 1), "keep", false); flush_rewrite_rules(); echo wp_make_link_relative(bp_get_signup_page());');
        int_no_new_leak($path);
        $name = 'atest_bp_' . substr(md5(uniqid('', true)), 0, 6);
        $sign = function ($extra) use ($path, $name) {
            $form = get($path)->body;

            return post($path, array_merge(array('signup_username' => $name, 'signup_email' => $name . '@example.test', 'signup_password' => 'Bp-Pass-123456!', 'signup_password_confirm' => 'Bp-Pass-123456!', 'signup_submit' => 'Complete Sign Up', '_wpnonce' => int_field($form, '_wpnonce'), 'field_1' => 'Bob', 'signup_profile_field_ids' => '1'), $extra), array('ip' => '203.0.113.221'));
        };
        $res = $sign(array());
        contains('Please complete the security check', int_text($res->body));
        not_contains('completed-confirmation', $res->body);
        $res = $sign(array('cf-turnstile-response' => 'good'));
        not_contains('Please complete the security check', int_text($res->body));
        wp_eval('global $wpdb; $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->base_prefix}signups WHERE user_login = %s", "' . $name . '"));');
    } finally {
        int_deactivate('buddypress');
    }
});

/* ---------------------------------------------------------- WooCommerce block checkout */

function int_store_checkout($ip, $extensions, $create_account = false)
{
    $jar = array();
    $cart = get(BASE . '/wp-json/wc/store/v1/cart', array('ip' => $ip));
    $nonce = isset($cart->headers['nonce']) ? end($cart->headers['nonce']) : '';
    $jar = live_cookies($cart);
    $add = request('POST', BASE . '/wp-json/wc/store/v1/cart/add-item', array('ip' => $ip, 'cookies' => $jar, 'headers' => array('Nonce: ' . $nonce), 'json' => array('id' => (int) $GLOBALS['int_product'], 'quantity' => 1)));
    $jar = array_merge($jar, live_cookies($add));
    $address = array('first_name' => 'G', 'last_name' => 'Uest', 'address_1' => '1 Road', 'city' => 'Town', 'postcode' => '12345', 'country' => 'US', 'state' => 'CA');
    $body = array(
        'billing_address' => $address + array('email' => 'atest-' . substr(md5(uniqid('', true)), 0, 8) . '@example.test', 'phone' => ''),
        'shipping_address' => $address,
        'payment_method' => 'cod',
        'create_account' => $create_account,
        'extensions' => (object) $extensions,
    );

    return request('POST', BASE . '/wp-json/wc/store/v1/checkout', array('ip' => $ip, 'cookies' => $jar, 'headers' => array('Nonce: ' . $nonce), 'json' => $body));
}

/** The checkout page with something in the cart (an empty cart redirects). */
function int_checkout_page($path, $ip)
{
    $cart = get(BASE . '/wp-json/wc/store/v1/cart', array('ip' => $ip));
    $nonce = isset($cart->headers['nonce']) ? end($cart->headers['nonce']) : '';
    $jar = live_cookies($cart);
    $add = request('POST', BASE . '/wp-json/wc/store/v1/cart/add-item', array('ip' => $ip, 'cookies' => $jar, 'headers' => array('Nonce: ' . $nonce), 'json' => array('id' => (int) $GLOBALS['int_product'], 'quantity' => 1)));
    $jar = array_merge($jar, live_cookies($add));

    return get($path, array('ip' => $ip, 'cookies' => $jar))->body;
}

t('WooCommerce block checkout: guest orders need the CAPTCHA (token sent as Store API extension data); with only "WooCommerce registration" ticked, only orders that create an account; no new slug leak', function () {
    int_activate('woocommerce');
    try {
        $GLOBALS['int_product'] = wp_eval('wp_set_current_user(1); WC_Install::create_pages(); update_option("woocommerce_cod_settings", array("enabled" => "yes", "title" => "COD")); update_option("woocommerce_enable_guest_checkout", "yes"); update_option("woocommerce_enable_signup_and_login_from_checkout", "yes");'
            . ' $p = new WC_Product_Simple(); $p->set_name("Int Tee"); $p->set_regular_price("10"); $p->set_status("publish"); $p->save(); echo $p->get_id();');
        $checkout = wp_eval('echo wp_make_link_relative(wc_get_checkout_url());');
        contains('wp:woocommerce/checkout', wp_eval('echo get_post(wc_get_page_id("checkout"))->post_content;'), 'block checkout page');

        settings(array('captcha_forms' => array('woo_checkout')));
        $page = int_checkout_page($checkout, '203.0.113.230');
        contains('data-wc-blocks="1"', $page, 'box after the checkout block');
        contains('assets/captcha/wc-blocks.js', $page);

        $res = int_store_checkout('203.0.113.231', array());
        eq(400, $res->code, 'no token');
        contains('authlify_captcha', $res->body);
        $res = int_store_checkout('203.0.113.232', array('authlify' => array('token' => 'forged')));
        eq(400, $res->code, 'rejected token');
        $res = int_store_checkout('203.0.113.233', array('authlify' => array('token' => 'good')));
        eq(200, $res->code, 'good token: order placed ' . substr($res->body, 0, 200));

        settings(array('captcha_forms' => array('woo_register')));
        eq(200, int_store_checkout('203.0.113.234', array())->code, 'guest order without an account: no check');
        eq(400, int_store_checkout('203.0.113.235', array(), true)->code, 'order that creates an account: checked');
        eq(200, int_store_checkout('203.0.113.236', array('authlify' => array('token' => 'good')), true)->code, 'with a token');

        settings(array('captcha_forms' => array()));
        eq(200, int_store_checkout('203.0.113.237', array(), true)->code, 'switches off: no check');

        // Woo's own wcSettings.wpLoginUrl carries wp_login_url() (pre-existing,
        // see BUILD report); the integration itself adds nothing.
        settings(array('captcha_forms' => array()));
        $off = substr_count(int_checkout_page($checkout, '203.0.113.238'), ISLUG);
        settings(array('captcha_forms' => array('woo_checkout')));
        $on = int_checkout_page($checkout, '203.0.113.239');
        contains('data-wc-blocks="1"', $on);
        eq($off, substr_count($on, ISLUG), 'no new slug occurrence on the checkout');
        wp_eval('global $wpdb; foreach (wc_get_orders(array("limit" => -1, "return" => "ids")) as $id) { wp_delete_post($id, true); $o = wc_get_order($id); if ($o) { $o->delete(true); } } foreach (get_users(array("search" => "atest-*", "search_columns" => array("user_email"), "fields" => "ID")) as $id) { require_once ABSPATH . "wp-admin/includes/user.php"; wp_delete_user($id); } wp_delete_post(' . (int) $GLOBALS['int_product'] . ', true);');
    } finally {
        int_deactivate('woocommerce');
    }
});

/* ---------------------------------------------------------- CMP-08 */

t('CMP-08: "Block" from the log adds the address (nonce and capability checked) and that address can no longer log in; your own address is refused', function () {
    $admin = http_user('administrator');
    $s = session_for($admin['id'], array('authlify_block_ip'));
    $block = function ($subject, $ip, $nonce) use ($s) {
        return get(BASE . '/wp-admin/admin-post.php?' . http_build_query(array('action' => 'authlify_block_ip', 'subject' => $subject, 'from' => 'activity', '_wpnonce' => $nonce)), array('cookies' => $s['cookies'], 'ip' => $ip));
    };

    $res = $block('203.0.113.240', '203.0.113.9', 'bad-nonce');
    eq(403, $res->code, 'bad nonce');
    $res = $block('203.0.113.9', '203.0.113.9', $s['nonces']['authlify_block_ip']);
    contains('page=modify-login-logs', $res->location);
    eq('', wp_eval('echo \Authlify\Settings::get("ip_denylist");'), 'own address refused');
    $res = $block('203.0.113.240', '203.0.113.9', $s['nonces']['authlify_block_ip']);
    contains('authlify_notice=ip_blocked', $res->location);
    eq('203.0.113.240', wp_eval('echo \Authlify\Settings::get("ip_denylist");'));

    $u = $GLOBALS['iu'];
    $res = login_post(login_url(ISLUG), $u['login'], $u['pass'], array('cf-turnstile-response' => 'good'), array('ip' => '203.0.113.240'));
    no(int_logged_in($res));
    contains('Access from your network is blocked', int_text($res->body));
    settings(array('ip_denylist' => ''));

    $editor = http_user('editor');
    $es = session_for($editor['id'], array('authlify_block_ip'));
    $res = get(BASE . '/wp-admin/admin-post.php?' . http_build_query(array('action' => 'authlify_block_ip', 'subject' => '203.0.113.241', '_wpnonce' => $es['nonces']['authlify_block_ip'])), array('cookies' => $es['cookies'], 'ip' => '203.0.113.9'));
    eq(403, $res->code, 'editor refused');
    eq('', wp_eval('echo \Authlify\Settings::get("ip_denylist");'));
});

t('CMP-08: the activity log shows "Block" next to other addresses, not your own', function () {
    $admin = http_user('administrator');
    $s = session_for($admin['id']);
    wp_eval('\Authlify\Log\Log::add("login_failed", array("username" => "x", "ip" => "203.0.113.242")); \Authlify\Log\Log::add("login_failed", array("username" => "x", "ip" => "203.0.113.9"));');
    $page = get(BASE . '/wp-admin/admin.php?page=modify-login-logs', array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'))->body;
    contains('subject=203.0.113.242', html_entity_decode($page));
    not_contains('subject=203.0.113.9&', html_entity_decode($page), 'own address');
});

/* ---------------------------------------------------------- CMP-09 */

t('CMP-09: Tools → Switch plugins previews, then imports Two Factor authenticator apps; the user signs in with the same app', function () {
    $admin = http_user('administrator');
    $u = http_user('editor');
    $secret = wp_eval('echo \Authlify\TwoFactor\Totp::new_secret();');
    wp_eval('update_user_meta(' . $u['id'] . ', "_two_factor_totp_key", "' . $secret . '"); update_user_meta(' . $u['id'] . ', "_two_factor_enabled_providers", array("Two_Factor_Totp"));');
    $s = session_for($admin['id'], array('authlify_2fa_import'));

    $tools = get(BASE . '/wp-admin/admin.php?page=authlify-tools', array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'))->body;
    contains('Two-factor secrets', $tools);
    contains('value="two-factor"', $tools);

    $send = function ($run) use ($s) {
        return post(BASE . '/wp-admin/admin-post.php', array_merge(array('action' => 'authlify_2fa_import', 'source' => 'two-factor', '_wpnonce' => $s['nonces']['authlify_2fa_import']), $run ? array('run' => '1') : array('preview' => '1')), array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'));
    };
    $send(false);
    $notice = get(BASE . '/wp-admin/admin.php?page=authlify-tools', array('cookies' => $s['cookies'], 'ip' => '203.0.113.9'))->body;
    contains('Preview:', int_text($notice));
    eq('', wp_eval('echo \Authlify\TwoFactor\Totp::is_configured(' . $u['id'] . ') ? "yes" : "";'), 'preview changed nothing');

    $send(true);
    eq('yes', wp_eval('echo \Authlify\TwoFactor\Totp::is_configured(' . $u['id'] . ') ? "yes" : "";'), 'imported');

    // Real sign-in: password, then the code from the same app.
    $res = login_post(login_url(ISLUG), $u['login'], $u['pass'], array('cf-turnstile-response' => 'good'), array('ip' => '203.0.113.243'));
    no(int_logged_in($res), 'second step required');
    contains('authlify_2fa_form', $res->body);
    $step = array('authlify_nonce' => int_field($res->body, 'authlify_nonce'), 'authlify_uid' => int_field($res->body, 'authlify_uid'), 'authlify_method' => 'totp', 'authlify_code' => totp_now($secret));
    $res = post(login_url(ISLUG) . '?action=authlify_2fa', $step, array('ip' => '203.0.113.243'));
    ok(int_logged_in($res), 'signed in with the imported app code: ' . $res->code . ' ' . substr(int_text($res->body), 0, 160));
});

/* ---------------------------------------------------------- CMP-10 */

t('CMP-10: a real sign-in from a new address emails the user once; the first one is only remembered', function () {
    $u = http_user('editor');
    settings(array('signin_notice' => true, 'signin_notice_roles' => array('editor')));
    wp_eval('\Authlify\Settings::update(array("pro_alert_new_device" => false, "pro_alert_new_country" => false));');
    $before = count(array_filter(mails(), function ($m) {
        return false !== strpos($m['subject'], 'New sign-in');
    }));
    $count = function () use ($before) {
        return count(array_filter(mails(), function ($m) {
            return false !== strpos($m['subject'], 'New sign-in');
        })) - $before;
    };
    $agent = array('User-Agent: Mozilla/5.0 (Windows NT 10.0; rv:131.0) Gecko/20100101 Firefox/131.0');
    ok(int_logged_in(login_post(login_url(ISLUG), $u['login'], $u['pass'], array('cf-turnstile-response' => 'good'), array('ip' => '203.0.113.250', 'headers' => $agent))));
    eq(0, $count(), 'first sign-in');
    login_post(login_url(ISLUG), $u['login'], $u['pass'], array('cf-turnstile-response' => 'good'), array('ip' => '203.0.113.251', 'headers' => $agent));
    eq(1, $count(), 'new address');
    $mail = array_values(array_filter(mails(), function ($m) {
        return false !== strpos($m['subject'], 'New sign-in');
    }));
    $last = end($mail);
    eq($u['email'], is_array($last['to']) ? $last['to'][0] : $last['to']);
    contains('203.0.113.251', $last['message']);
    settings(array('signin_notice' => false));
    wp_eval('delete_transient("authlify_signin_notice_' . $u['id'] . '");');
});
