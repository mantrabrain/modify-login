<?php
/**
 * The free-to-Pro channel on a real site: admin screens, dismissal over AJAX, and
 * no cost to front-end requests.
 *
 * A temporary mu-plugin switches pointers on (the suite's site has Pro active) and
 * reports what ran during a request.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

function conv_mu()
{
    return SITE_DIR . '/wp-content/mu-plugins/conv-test.php';
}

before_all(function () {
    file_put_contents(conv_mu(), <<<'PHP'
<?php
add_filter('authlify_show_pro_pointers', '__return_true', 20);
add_action('muplugins_loaded', function () {
    if (!empty($_GET['convprobe'])) {
        if (!defined('SAVEQUERIES')) { define('SAVEQUERIES', true); }
        add_action('shutdown', function () {
            global $wpdb;
            $hits = array_values(array_filter(array_map(function ($q) { return $q[0]; }, (array) $wpdb->queries), function ($sql) {
                return false !== stripos($sql, 'authlify_nudge') || false !== stripos($sql, 'authlify_first_seen') || false !== stripos($sql, 'authlify_log');
            }));
            file_put_contents(WP_CONTENT_DIR . '/conv-probe.json', json_encode(array(
                'nudges' => class_exists('Authlify\\Admin\\Nudges', false),
                'facts' => class_exists('Authlify\\Admin\\Facts', false),
                'upsell' => class_exists('Authlify\\Admin\\Upsell', false),
                'queries' => count((array) $wpdb->queries),
                'hits' => $hits,
            )));
        }, 999);
    }
});
PHP
    );
    $admin = http_user('administrator');
    $GLOBALS['conv_admin'] = $admin;
    $GLOBALS['conv_session'] = session_for($admin['id'], array('authlify_nudge'));
    wp_eval('update_option("authlify_first_seen", time() - 30 * DAY_IN_SECONDS, false);');
    wp_eval('set_transient("authlify_nudge_facts", array("week" => array("failed" => 40, "lockouts" => 5), "month" => array("failed" => 40, "lockouts" => 5), "days" => array()), 600);');
});

after_all(function () {
    @unlink(conv_mu());
    @unlink(SITE_DIR . '/wp-content/conv-probe.json');
    wp_eval('delete_option("authlify_first_seen"); delete_transient("authlify_nudge_facts");');
});

function conv_get($path)
{
    return get(BASE . '/wp-admin/' . $path, array('cookies' => $GLOBALS['conv_session']['cookies']));
}

t('front end: a page view loads none of it and runs no query for it', function () {
    @unlink(SITE_DIR . '/wp-content/conv-probe.json');
    get(BASE . '/?convprobe=1');
    $probe = json_decode((string) @file_get_contents(SITE_DIR . '/wp-content/conv-probe.json'), true);
    ok(is_array($probe), 'the probe ran');
    ok($probe['queries'] > 0, 'the probe saw queries');
    no($probe['nudges'], 'Nudges not loaded');
    no($probe['facts'], 'Facts not loaded');
    no($probe['upsell'], 'Upsell not loaded');
    eq(array(), $probe['hits'], 'no log, first-seen or facts queries');
});

t('login page: no Pro mention on a visitor-facing screen', function () {
    $res = get(BASE . '/wp-login.php');
    not_contains('authlify-nudge', $res->body);
    not_contains('Authlify Pro', $res->body);
});

t('Security screen: the card with the site\'s own numbers, not inside a notice', function () {
    $res = conv_get('admin.php?page=authlify-protection');
    eq(200, $res->code);
    contains('data-authlify-nudge="moment-lockouts"', $res->body);
    contains('40 failed logins and 5 lockouts this week.', $res->body);
    eq(1, preg_match_all('/data-authlify-nudge="/', $res->body), 'one per screen');
    no((bool) preg_match('/class="[^"]*notice[^"]*"[^>]*>\s*<section class="authlify-nudge/', $res->body), 'not a notice');
    contains('assets/admin/nudges.js', $res->body);
});

t('Refused usernames: the example shows one name per line, as the help text says', function () {
    $body = conv_get('admin.php?page=authlify-protection')->body;
    ok(1 === preg_match('/<textarea id="authlify-username_denylist"[^>]*placeholder="([^"]*)"/', $body, $m), 'field found');
    eq("admin\nadministrator\ntest", $m[1]);
});

t('other admin screens: no card and no script', function () {
    foreach (array('index.php', 'plugins.php', 'users.php', 'options-general.php') as $path) {
        $res = conv_get($path);
        eq(200, $res->code, $path);
        not_contains('data-authlify-nudge', $res->body, $path);
        not_contains('assets/admin/nudges', $res->body, $path);
    }
});

t('dismissal: AJAX stores it for this user and the card is gone on the next view', function () {
    $s = $GLOBALS['conv_session'];
    $res = post(BASE . '/wp-admin/admin-ajax.php', array('action' => 'authlify_dismiss_nudge', 'nonce' => $s['nonces']['authlify_nudge'], 'id' => 'moment-lockouts'), array('cookies' => $s['cookies']));
    eq(200, $res->code);
    contains('"success":true', $res->body);
    not_contains('moment-lockouts', conv_get('admin.php?page=authlify-protection')->body);
    eq('1', wp_eval('$l = get_user_meta(' . (int) $GLOBALS['conv_admin']['id'] . ', "authlify_nudges", true); echo true === $l["moment-lockouts"] ? 1 : 0;'));
    not_contains('data-authlify-nudge="row-security"', conv_get('admin.php?page=authlify-protection')->body, 'no second pitch on the same screen');
});

t('review footer: on Authlify screens until the card is answered, gone on the next view', function () {
    $s = $GLOBALS['conv_session'];
    contains('Enjoying Authlify?', conv_get('admin.php?page=authlify-protection')->body);
    $res = post(BASE . '/wp-admin/admin-ajax.php', array('action' => 'authlify_dismiss_nudge', 'nonce' => $s['nonces']['authlify_nudge'], 'id' => 'review'), array('cookies' => $s['cookies']));
    contains('"success":true', $res->body);
    not_contains('Enjoying Authlify?', conv_get('admin.php?page=authlify-protection')->body);
    not_contains('Enjoying Authlify?', conv_get('admin.php?page=modify-login')->body);
});

t('dismissal: an unknown id is refused and stores nothing', function () {
    $s = $GLOBALS['conv_session'];
    $res = post(BASE . '/wp-admin/admin-ajax.php', array('action' => 'authlify_dismiss_nudge', 'nonce' => $s['nonces']['authlify_nudge'], 'id' => 'junk'), array('cookies' => $s['cookies']));
    eq(400, $res->code);
    eq('0', wp_eval('$l = get_user_meta(' . (int) $GLOBALS['conv_admin']['id'] . ', "authlify_nudges", true); echo isset($l["junk"]) ? 1 : 0;'));
});

t('an editor cannot open the screens or dismiss for itself through the endpoint', function () {
    $editor = http_user('editor');
    $s = session_for($editor['id'], array('authlify_nudge'));
    $res = post(BASE . '/wp-admin/admin-ajax.php', array('action' => 'authlify_dismiss_nudge', 'nonce' => $s['nonces']['authlify_nudge'], 'id' => 'review'), array('cookies' => $s['cookies']));
    eq(403, $res->code);
});

t('setup notice: dismissible per user, still shown to another admin', function () {
    settings(array('login_slug' => '', 'pending_slug' => '')); // The notice is only for a site with no login address (F3-11).
    wp_eval('$s = get_option("authlify_settings"); update_option("authlify_conv_onboarding", isset($s["onboarding_done"]) ? (int) $s["onboarding_done"] : 1); \\Authlify\\Settings::update(array("onboarding_done" => false));');
    $admin = http_user('administrator');
    $s = session_for($admin['id'], array('authlify_dismiss_notice'));
    $res = get(BASE . '/wp-admin/plugins.php', array('cookies' => $s['cookies']));
    contains('Nothing is hidden yet', $res->body);
    contains('notice=setup', $res->body, 'has a Dismiss link');
    $res = get(BASE . '/wp-admin/admin-post.php?action=authlify_dismiss_notice&notice=setup&_wpnonce=' . $s['nonces']['authlify_dismiss_notice'], array('cookies' => $s['cookies']));
    eq(302, $res->code);
    not_contains('Nothing is hidden yet', get(BASE . '/wp-admin/plugins.php', array('cookies' => $s['cookies']))->body, 'gone for this user');
    contains('Nothing is hidden yet', conv_get('plugins.php')->body, 'another admin still sees it');
    wp_eval('\\Authlify\\Settings::update(array("onboarding_done" => (bool) get_option("authlify_conv_onboarding"))); delete_option("authlify_conv_onboarding");');
});

t('readme: site links carry readme UTM and the review promise is worded honestly', function () {
    $readme = file_get_contents(dirname(dirname(__DIR__)) . '/readme.txt');
    preg_match_all('#https://matrixaddons\.com/plugins/authlify/[^)\s]*#', $readme, $m);
    ok(count($m[0]) > 10, 'found the links');
    foreach ($m[0] as $url) {
        contains('utm_source=wporg', $url);
        contains('utm_medium=readme', $url);
    }
    contains('one small card at a time', $readme);
    contains('honest review', $readme);
    contains('= 3.2.0', $readme);
});
