<?php
/**
 * Permissions: every REST route, admin-post action and AJAX action in Authlify
 * and Authlify Pro, called as a logged-out visitor, a subscriber, an editor and
 * an administrator, each with and without a valid nonce.
 *
 * The endpoint list below was derived from the code:
 *   grep -rn "register_rest_route\|admin_post_\|wp_ajax_" modify-login/inc authlify-pro/inc
 * The first test fails when that grep finds an endpoint this file does not cover.
 *
 * Expected:
 * - "admin" endpoints: only an administrator with a valid nonce succeeds;
 * - "self" endpoints: any signed-in user with a valid nonce succeeds (they only
 *   touch the caller's own account);
 * - "edit_user" endpoints aimed at another user: only an administrator;
 * - "public" endpoints: anyone (by design; noted per endpoint).
 * Refusal is 401/403 from REST (core rest_* codes), 400/403 from admin-post and
 * admin-ajax. Everyone else must get neither the action nor a success response.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

$GLOBALS['perm'] = array();

/**
 * Endpoint table. Placeholders: {victim} (another subscriber's ID), {self} (the caller's ID).
 *
 * kind: rest | post (admin-post.php) | ajax (admin-ajax.php).
 */
function perm_endpoints()
{
    return array(
        // ------------------------------------------------ Free REST: authlify/v1.
        array('rest', 'GET', 'authlify/v1/twofactor/status', array(), 'self'),
        array('rest', 'GET', 'authlify/v1/twofactor/status?user_id={victim}', array(), 'admin', 'status of another user'),
        array('rest', 'POST', 'authlify/v1/twofactor/totp/setup', array(), 'self'),
        array('rest', 'POST', 'authlify/v1/twofactor/totp/verify', array('code' => '000000'), 'self'),
        array('rest', 'DELETE', 'authlify/v1/twofactor/totp', array(), 'self'),
        array('rest', 'POST', 'authlify/v1/twofactor/backup-codes', array(), 'self'),
        array('rest', 'POST', 'authlify/v1/twofactor/passkeys/options', array(), 'self'),
        array('rest', 'POST', 'authlify/v1/twofactor/passkeys', array('clientDataJSON' => 'x'), 'self'),
        array('rest', 'PUT', 'authlify/v1/twofactor/passkeys/999999', array('name' => 'x'), 'self'),
        array('rest', 'DELETE', 'authlify/v1/twofactor/passkeys/999999', array(), 'self'),
        array('rest', 'POST', 'authlify/v1/twofactor/reset', array('user_id' => '{victim}'), 'admin', 'reset another user'),
        array('rest', 'POST', 'authlify/v1/twofactor/reset', array('user_id' => '{self}'), 'self', 'reset own'),
        array('rest', 'GET', 'authlify/v1/designer', array(), 'admin'),
        array('rest', 'POST', 'authlify/v1/designer', array('design' => array('layout' => 'centered')), 'admin'),
        array('rest', 'POST', 'authlify/v1/designer/draft', array('design' => array('layout' => 'centered')), 'admin'),
        array('rest', 'DELETE', 'authlify/v1/designer/draft', array(), 'admin'),
        array('rest', 'GET', 'authlify/v1/designer/templates', array(), 'admin'),
        array('rest', 'GET', 'authlify/v1/designer/match-site', array(), 'admin'),

        // ------------------------------------------------ Pro REST: authlify-pro/v1.
        array('rest', 'GET', 'authlify-pro/v1/settings', array(), 'admin', '', 'pro'),
        array('rest', 'POST', 'authlify-pro/v1/settings', array('settings' => array('no_such_key' => 1)), 'admin', 'unknown key: 400 when allowed', 'pro'),
        array('rest', 'GET', 'authlify-pro/v1/activity', array(), 'admin', '', 'pro'),
        array('rest', 'GET', 'authlify-pro/v1/lockouts', array(), 'admin', '', 'pro'),
        array('rest', 'POST', 'authlify-pro/v1/lockouts/unlock', array(), 'admin', 'no IP sent', 'pro'),
        array('rest', 'GET', 'authlify-pro/v1/design', array(), 'admin', '', 'pro'),
        array('rest', 'POST', 'authlify-pro/v1/design', array('design' => array('nope' => 1)), 'admin', 'invalid design: 400 when allowed', 'pro'),
        array('rest', 'POST', 'authlify-pro/v1/identity/email/send', array(), 'self', '', 'pro'),
        array('rest', 'POST', 'authlify-pro/v1/identity/email/verify', array('code' => '000000'), 'self', '', 'pro'),
        array('rest', 'DELETE', 'authlify-pro/v1/identity/email', array(), 'self', '', 'pro'),

        // ------------------------------------------------ Free admin-post.
        array('post', 'POST', 'authlify_save', array('authlify_page' => 'tools', 'authlify_tab' => '', 'authlify_fields' => 'onboarding_done', 'authlify' => array('onboarding_done' => '1')), 'admin', '', '', array('authlify_save_tools', '_authlify_nonce')),
        array('post', 'GET', 'authlify_unlock', array('subject' => '203.0.113.250'), 'admin'),
        array('post', 'GET', 'authlify_cancel_pending', array(), 'admin'),
        array('post', 'GET', 'authlify_email_url', array(), 'admin'),
        array('post', 'GET', 'authlify_export_log', array(), 'admin'),
        array('post', 'POST', 'authlify_clear_log', array(), 'admin'),
        array('post', 'GET', 'authlify_export_settings', array(), 'admin'),
        array('post', 'POST', 'authlify_import_settings', array(), 'admin', 'no file (error notice when allowed)'),
        array('post', 'POST', 'authlify_run_importer', array('importer' => 'none'), 'admin'),
        array('post', 'GET', 'authlify_block_ip', array('subject' => '203.0.113.249', 'from' => 'protection'), 'admin', 'adds to the block list'),
        array('post', 'POST', 'authlify_2fa_import', array('source' => 'two-factor'), 'admin', 'preview (dry run)'),
        array('post', 'POST', 'authlify_leak_check', array(), 'admin'),
        array('post', 'POST', 'authlify_2fa_reset', array('user_id' => '{victim}'), 'admin', 'reset another user', '', array('authlify_2fa_reset_{victim}')),
        array('post', 'GET', 'authlify_2fa_coexist_dismiss', array(), 'admin'),
        array('post', 'GET', 'authlify_dismiss_notice', array(), 'self', 'per-user notice flag'),
        array('post', 'GET', 'authlify_dismiss_conflict', array('plugin' => 'llar'), 'admin', 'per-plugin conflict notice (CMPT-05)', '', array('authlify_dismiss_conflict_llar')),

        // ------------------------------------------------ Pro admin-post.
        array('post', 'POST', 'authlify_pro_license', array('task' => 'activate', 'license_key' => 'test-key'), 'admin', 'store blocked by the harness', 'pro'),
        array('post', 'GET', 'authlify_pro_renew', array(), 'admin', 'redirects to the store checkout', 'pro'),
        array('post', 'POST', 'authlify_pro_test_email', array(), 'admin', '', 'pro'),
        array('post', 'POST', 'authlify_pro_geodb_update', array(), 'admin', 'download blocked by the harness', 'pro'),
        array('post', 'GET', 'authlify_pro_device_revoke', array('user_id' => '{victim}', 'device' => 'x'), 'admin', 'another user', 'pro', array('authlify_pro_device_revoke_{victim}')),
        array('post', 'GET', 'authlify_pro_device_revoke', array('user_id' => '{self}', 'device' => 'x'), 'self', 'own devices', 'pro', array('authlify_pro_device_revoke_{self}')),
        array('post', 'POST', 'authlify_pro_devices_forget_all', array(), 'admin', '', 'pro'),
        array('post', 'POST', 'authlify_pro_email_test', array(), 'admin', '', 'pro'),
        array('post', 'GET', 'authlify_pro_end_session', array('user' => '{victim}', 'session' => 'x'), 'admin', 'another user', 'pro', array('authlify_pro_end_session_{victim}')),
        array('post', 'GET', 'authlify_pro_end_session', array('user' => '{self}', 'session' => 'x'), 'self', 'own sessions', 'pro', array('authlify_pro_end_session_{self}')),
        array('post', 'POST', 'authlify_pro_webhook_test', array('index' => '0'), 'admin', '', 'pro'),
        array('post', 'POST', 'authlify_pro_webhook_secret', array('index' => '0'), 'admin', '', 'pro'),
        array('post', 'POST', 'authlify_pro_send_export', array(), 'admin', '', 'pro'),
        array('post', 'GET', 'authlify_pro_2fa_report_csv', array(), 'admin', '', 'pro'),
        array('post', 'POST', 'authlify_pro_sync_key', array('task' => 'noop'), 'admin', '', 'pro'),
        array('post', 'POST', 'authlify_pro_design_remote', array('site' => 'https://remote.example', 'key' => 'x', 'task' => 'pull'), 'admin', 'remote blocked by the harness', 'pro'),
        array('post', 'GET', 'authlify_pro_design_download', array(), 'admin', '', 'pro'),
        array('post', 'POST', 'authlify_pro_design_upload', array(), 'admin', 'no file', 'pro'),
        array('post', 'POST', 'authlify_pro_unban', array('ip' => '203.0.113.251'), 'admin', '', 'pro'),
        array('post', 'POST', 'authlify_pro_temp_create', array('authlify_pro_temp' => array('role' => 'subscriber', 'days' => '1', 'email' => 'temp-perm@example.test')), 'admin', '', 'pro'),
        array('post', 'GET', 'authlify_pro_temp_revoke', array('user_id' => '{victim}'), 'admin-refused', 'not a temporary user: refused for everyone', 'pro', array('authlify_pro_temp_revoke_{victim}')),
        array('post', 'GET', 'authlify_pro_social_unlink', array('user_id' => '{victim}', 'provider' => 'google'), 'admin', 'another user', 'pro', array('authlify_pro_social_unlink_{victim}_google')),
        array('post', 'GET', 'authlify_pro_social_unlink', array('user_id' => '{self}', 'provider' => 'google'), 'self', 'own account', 'pro', array('authlify_pro_social_unlink_{self}_google')),
        array('post', 'POST', 'authlify_pro_site_overrides', array(), 'multisite', 'multisite only', 'pro'),
        array('post', 'POST', 'authlify_pro_clear_site', array(), 'multisite', 'multisite only', 'pro'),
        array('post', 'POST', 'authlify_pro_signed_in', array('do' => 'user', 'user' => '{victim}'), 'admin', 'sign out another user', 'pro'),

        // ------------------------------------------------ AJAX.
        array('ajax', 'POST', 'authlify_pro_social_test', array('provider' => 'google'), 'admin', 'nonce field "nonce"', 'pro', array('authlify_pro_social_test', 'nonce')),
        array('ajax', 'POST', 'authlify_pro_ping', array(), 'self-signed-in', 'idle ping, nonce field "nonce"', 'pro', array('authlify_pro_ping', 'nonce')),
    );
}

/**
 * Nonce action and field for an endpoint.
 */
function perm_nonce_spec(array $e)
{
    if (isset($e[7])) {
        return array($e[7][0], isset($e[7][1]) ? $e[7][1] : ('ajax' === $e[0] ? '_ajax_nonce' : '_wpnonce'));
    }

    return array($e[2], 'ajax' === $e[0] ? '_ajax_nonce' : '_wpnonce');
}

function perm_fill($value, $self)
{
    if (is_array($value)) {
        return array_map(function ($v) use ($self) {
            return perm_fill($v, $self);
        }, $value);
    }

    return str_replace(array('{victim}', '{self}'), array((string) $GLOBALS['perm']['victim']['id'], (string) $self), (string) $value);
}

/**
 * Call an endpoint as a role.
 *
 * @return object Response.
 */
function perm_call(array $e, $role, $with_nonce)
{
    $s = 'anon' === $role ? null : $GLOBALS['perm']['sessions'][$role];
    $self = 'anon' === $role ? 0 : $GLOBALS['perm']['users'][$role]['id'];
    $o = array('headers' => array('Referer: ' . BASE . '/wp-admin/'));
    if ($s) {
        $o['cookies'] = $s['cookies'];
    }
    $params = perm_fill($e[3], $self);

    if ('rest' === $e[0]) {
        if ($s && $with_nonce) {
            $o['headers'][] = 'X-WP-Nonce: ' . $s['rest'];
        }
        $url = BASE . '/wp-json/' . perm_fill($e[2], $self);
        if ($params) {
            $o['json'] = $params;
        }

        return request($e[1], $url, $o);
    }

    list($action, $field) = perm_nonce_spec($e);
    $action = perm_fill($action, $self);
    $params = array_merge(array('action' => $e[2]), $params);
    if ($s && $with_nonce) {
        $params[$field] = $s['nonces'][$action];
    }
    $script = 'ajax' === $e[0] ? 'admin-ajax.php' : 'admin-post.php';

    if ('GET' === $e[1]) {
        return get(BASE . '/wp-admin/' . $script . '?' . http_build_query($params), $o);
    }
    $o['body'] = http_build_query($params);

    return request('POST', BASE . '/wp-admin/' . $script, $o);
}

/**
 * Whether a response is a refusal.
 */
function perm_refused(array $e, $res)
{
    if ('rest' === $e[0]) {
        $json = json_decode($res->body, true);
        $code = is_array($json) && isset($json['code']) ? (string) $json['code'] : '';

        // Core permission errors, plus Authlify's "browser session required" refusal.
        return in_array($res->code, array(401, 403), true) && (0 === strpos($code, 'rest_') || in_array($code, array('authlify_pro_bad_key', 'authlify_2fa_session_required'), true));
    }
    if ('ajax' === $e[0]) {
        return in_array($res->code, array(400, 403), true) || '-1' === trim($res->body) || '0' === trim($res->body);
    }

    return in_array($res->code, array(400, 403), true);
}

function perm_describe($res)
{
    return $res->code . ' ' . ($res->location ? '-> ' . str_replace(BASE, '', $res->location) . ' ' : '') . substr(preg_replace('/\s+/', ' ', strip_tags($res->body)), 0, 140);
}

before_all(function () {
    wp_eval('update_option("authlify_perm_maxid", (int) $GLOBALS["wpdb"]->get_var("SELECT MAX(ID) FROM " . $GLOBALS["wpdb"]->users));');
    $GLOBALS['perm']['pro'] = 'yes' === wp_eval('echo class_exists("\AuthlifyPro\Plugin") ? "yes" : "no";');
    $GLOBALS['perm']['multisite'] = 'yes' === wp_eval('echo is_multisite() ? "yes" : "no";');
    $GLOBALS['perm']['victim'] = http_user('subscriber');
    foreach (array('subscriber', 'editor', 'administrator') as $role) {
        $GLOBALS['perm']['users'][$role] = http_user($role);
    }
    // Nonces per role, for every action (with {self} filled for that role).
    foreach ($GLOBALS['perm']['users'] as $role => $u) {
        $actions = array();
        foreach (perm_endpoints() as $e) {
            if ('rest' !== $e[0]) {
                $actions[] = perm_fill(perm_nonce_spec($e)[0], $u['id']);
            }
        }
        $GLOBALS['perm']['sessions'][$role] = session_for($u['id'], array_unique($actions));
    }
    // Remote endpoints (licence store, geo download, webhooks) are blocked by the harness.
    settings(array('limit_enabled' => true, 'captcha_provider' => 'none', 'honeypot' => false));
});

after_all(function () {
    // Remove anything an allowed call created (e.g. a temporary-access user).
    wp_eval('require_once ABSPATH . "wp-admin/includes/user.php"; $max = (int) get_option("authlify_perm_maxid"); foreach ($GLOBALS["wpdb"]->get_col($GLOBALS["wpdb"]->prepare("SELECT ID FROM " . $GLOBALS["wpdb"]->users . " WHERE ID > %d", $max)) as $id) { if (!in_array((int) $id, ' . json_encode(array_map('intval', $GLOBALS['http_users'])) . ', true)) { wp_delete_user($id); } } delete_option("authlify_perm_maxid"); delete_option("authlify_pro_sync_key");');
});

t('every REST route, admin-post and AJAX action in the code is covered here', function () {
    $root = dirname(dirname(__DIR__));
    $roots = array($root . '/inc');
    if (is_dir(dirname($root) . '/authlify-pro/inc')) {
        $roots[] = dirname($root) . '/authlify-pro/inc';
    }
    $found = array();
    foreach ($roots as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if ('php' !== $f->getExtension()) {
                continue;
            }
            $src = file_get_contents($f->getPathname());
            // Resolve class constants used in hook names.
            preg_match_all("/const ([A-Z_]+) = '([a-z0-9_]+)'/", $src, $consts, PREG_SET_ORDER);
            $map = array();
            foreach ($consts as $c) {
                $map['self::' . $c[1]] = $c[2];
            }
            preg_match_all("/'(admin_post_|wp_ajax_)(nopriv_)?' \. (self::[A-Z_]+)|'(admin_post_|wp_ajax_)(nopriv_)?([a-z0-9_]+)'/", $src, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $kind = !empty($hit[1]) ? $hit[1] : $hit[4];
                $name = !empty($hit[3]) ? (isset($map[$hit[3]]) ? $map[$hit[3]] : $hit[3]) : $hit[6];
                if ('' === $name) {
                    continue;
                }
                $found[('admin_post_' === $kind ? 'post:' : 'ajax:') . $name] = true;
            }
            preg_match_all("/register_rest_route\(\s*(self::NS|'[a-z0-9\-\/]+')\s*,\s*'([^']+)'/", $src, $r, PREG_SET_ORDER);
            preg_match("/const NS = '([^']+)'/", $src, $ns);
            foreach ($r as $hit) {
                $namespace = 'self::NS' === $hit[1] ? (isset($ns[1]) ? $ns[1] : '?') : trim($hit[1], "'");
                $route = preg_replace('/\(\?P<id>[^)]+\)/', '999999', $hit[2]);
                $found['rest:' . $namespace . $route] = true;
            }
        }
    }
    $covered = array();
    foreach (perm_endpoints() as $e) {
        $path = 'rest' === $e[0] ? preg_replace('/\?.*$/', '', $e[2]) : $e[2];
        $covered[$e[0] . ':' . ('rest' === $e[0] ? $path : $e[2])] = true;
    }
    $covered['ajax:authlify_altcha'] = true; // Public by design: tested below.
    $covered['post:authlify_pro_not_me'] = true; // Signed-token link from alert emails: tested below.
    $missing = array_diff(array_keys($found), array_keys($covered));
    eq(array(), array_values($missing), 'endpoints in the code without a permission test');
    ok(count($found) >= 50, 'the scan found the endpoints (' . count($found) . ')');
});

// One test per endpoint and role.
foreach (perm_endpoints() as $e) {
    foreach (array('anon', 'subscriber', 'editor', 'administrator') as $role) {
        $label = strtoupper($e[0]) . ' ' . $e[1] . ' ' . $e[2] . (!empty($e[5]) ? ' (' . $e[5] . ')' : '') . ' as ' . $role;
        t($label, function () use ($e, $role) {
            if ('pro' === (isset($e[6]) ? $e[6] : '') && !$GLOBALS['perm']['pro']) {
                skip('Authlify Pro is not active');
            }
            if ('multisite' === $e[4] && !$GLOBALS['perm']['multisite']) {
                // Not registered on a single site: nobody may get a success response.
                $res = perm_call($e, $role, true);
                ok(perm_refused($e, $res), 'expected a refusal, got ' . perm_describe($res));

                return;
            }

            // Without a nonce: always refused (CSRF), whatever the role.
            if ('anon' !== $role) {
                $res = perm_call($e, $role, false);
                ok(perm_refused($e, $res), 'without a nonce, expected a refusal, got ' . perm_describe($res));
            }

            $res = perm_call($e, $role, true);
            if ('self-signed-in' === $e[4] && 'anon' === $role) {
                // The logged-out variant only reports "no session" (tested below).
                return;
            }
            $allowed = ('admin' === $e[4] && 'administrator' === $role) || (in_array($e[4], array('self', 'self-signed-in'), true) && 'anon' !== $role);
            if ($allowed) {
                no(perm_refused($e, $res), 'expected success, got ' . perm_describe($res));
                ok($res->code < 500, 'no server error: ' . perm_describe($res));
                if ('rest' !== $e[0]) {
                    ok(in_array($res->code, array(200, 302, 303), true), 'admin-post/ajax success is a page, a download or a redirect: ' . perm_describe($res));
                    no(false !== strpos((string) $res->location, 'wp-login.php') || false !== strpos((string) $res->location, 'reauth=1'), 'not bounced to the login page: ' . perm_describe($res));
                }
                not_contains('not allowed', strtolower($res->body), 'refusal text in the response');
            } else {
                ok(perm_refused($e, $res), 'expected a refusal, got ' . perm_describe($res));
            }
        });
    }
}

t('AJAX authlify_altcha is public on purpose (a fresh challenge, no secrets)', function () {
    foreach (array('anon', 'subscriber') as $role) {
        $o = 'anon' === $role ? array() : array('cookies' => $GLOBALS['perm']['sessions'][$role]['cookies']);
        $res = get(BASE . '/wp-admin/admin-ajax.php?action=authlify_altcha', $o);
        eq(200, $res->code);
        $json = json_decode($res->body, true);
        eq(array('algorithm', 'challenge', 'maxnumber', 'salt', 'signature'), array_keys($json));
        contains('no-store', strtolower(implode(',', isset($res->headers['cache-control']) ? $res->headers['cache-control'] : array())), 'never cached');
    }
});

t('admin-post not_me (nopriv) does nothing without a valid signed token', function () {
    if (!$GLOBALS['perm']['pro']) {
        skip('Authlify Pro is not active');
    }
    $v = $GLOBALS['perm']['victim'];
    $before = wp_eval('echo (int) get_user_meta(' . $v['id'] . ', "authlify_pro_force_reset", true);');
    foreach (array('GET', 'POST') as $method) {
        $res = request($method, BASE . '/wp-admin/admin-post.php?action=authlify_pro_not_me&u=' . $v['id'] . '&t=forged-token');
        ok($res->code >= 400, $method . ': expected an error page, got ' . perm_describe($res));
    }
    eq($before, wp_eval('echo (int) get_user_meta(' . $v['id'] . ', "authlify_pro_force_reset", true);'), 'no forced reset');
});

t('AJAX authlify_pro_ping logged out only says the session is gone', function () {
    if (!$GLOBALS['perm']['pro']) {
        skip('Authlify Pro is not active');
    }
    $res = post(BASE . '/wp-admin/admin-ajax.php', array('action' => 'authlify_pro_ping', 'touch' => '1'));
    eq(200, $res->code);
    eq(array('success' => true, 'data' => array('remaining' => 0)), json_decode($res->body, true));
});

t('sync key grants the design route only, nothing else', function () {
    if (!$GLOBALS['perm']['pro']) {
        skip('Authlify Pro is not active');
    }
    $key = wp_eval('echo \AuthlifyPro\Agency\DesignSync::create_key();');
    $h = array('X-Authlify-Pro-Key: ' . $key);
    eq(200, get(BASE . '/wp-json/authlify-pro/v1/design', array('headers' => $h))->code, 'design with the key');
    eq(403, get(BASE . '/wp-json/authlify-pro/v1/design', array('headers' => array('X-Authlify-Pro-Key: alp_wrong')))->code, 'wrong key');
    foreach (array('settings', 'activity', 'lockouts') as $route) {
        eq(401, get(BASE . '/wp-json/authlify-pro/v1/' . $route, array('headers' => $h))->code, $route . ' with the sync key');
    }
    eq(401, get(BASE . '/wp-json/authlify/v1/designer', array('headers' => $h))->code, 'free designer route with the sync key');
    wp_eval('\AuthlifyPro\Agency\DesignSync::revoke_key();');
    eq(403, get(BASE . '/wp-json/authlify-pro/v1/design', array('headers' => $h))->code, 'revoked key');
});

t('application password: the admin REST routes accept an admin app password, refuse a subscriber one', function () {
    $admin = $GLOBALS['perm']['users']['administrator'];
    $sub = $GLOBALS['perm']['users']['subscriber'];
    $ap = wp_json('$a = WP_Application_Passwords::create_new_application_password(' . $admin['id'] . ', array("name" => "perm")); $s = WP_Application_Passwords::create_new_application_password(' . $sub['id'] . ', array("name" => "perm")); echo json_encode(array($a[0], $s[0]));');
    $route = $GLOBALS['perm']['pro'] ? 'authlify-pro/v1/settings' : 'authlify/v1/designer';
    $res = get(BASE . '/wp-json/' . $route, array('headers' => array('Authorization: Basic ' . base64_encode($admin['login'] . ':' . $ap[0]))));
    eq(200, $res->code, 'admin app password: ' . perm_describe($res));
    $res = get(BASE . '/wp-json/' . $route, array('headers' => array('Authorization: Basic ' . base64_encode($sub['login'] . ':' . $ap[1]))));
    eq(403, $res->code, 'subscriber app password');
});
