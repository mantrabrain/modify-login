<?php
/**
 * Two-factor and identity fixes inside WordPress (audit fix phase,
 * 08-fixes-identity): Crypto format and migration (F9), atomic TOTP step and
 * backup-code use (F8), the atomic counter and account throttle (F4), signed
 * passkey tokens (F5), passkey rename ownership (F10), passkey counts cache
 * (PERF-04), methods that cannot run (ARCH-03, F11), identity confirmation
 * for new TOTP and backup codes (F3), and account blocks (ARCH-02).
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\TwoFactor\BackupCodes;
use Authlify\TwoFactor\Crypto;
use Authlify\TwoFactor\LoginFlow;
use Authlify\TwoFactor\Passkeys;
use Authlify\TwoFactor\Rest;
use Authlify\TwoFactor\Totp;
use Authlify\TwoFactor\TwoFactor;

/**
 * An "s1:" value as 3.0.0 wrote it.
 */
function legacy_s1($plain)
{
    $auth = defined('AUTH_KEY') ? AUTH_KEY : '';
    $salt = defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '';
    if ('' === $auth . $salt || 'put your unique phrase here' === $auth) {
        $auth = wp_salt('auth');
        $salt = wp_salt('secure_auth');
    }
    $nonce = random_bytes(24);

    return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, hash('sha256', $auth . $salt, true)));
}

function pk_row($user_id, $rp = null)
{
    global $wpdb;
    $id = Passkeys::b64url_encode(random_bytes(16));
    $wpdb->insert(Passkeys::table(), array(
        'user_id' => $user_id, 'rp_id' => null === $rp ? Passkeys::rp_id() : $rp, 'credential_hash' => hash('sha256', $id), 'credential_id' => $id,
        'public_key' => 'x', 'sign_count' => 0, 'name' => 'unit', 'created_at' => gmdate('Y-m-d H:i:s'),
    ));

    return (int) $wpdb->insert_id;
}

after_each(function () {
    global $wpdb;
    $wpdb->query("DELETE FROM " . Passkeys::table() . " WHERE name = 'unit'");
    delete_site_option('authlify_passkeys_any');
    wp_cache_flush();
});

// ---------------------------------------------------------------- F9: Crypto.

t('F9: s2 values are bound to their context; a value copied to another user does not decrypt', function () {
    $a = Crypto::encrypt('JBSWY3DPEHPK3PXP', 'totp|1');
    eq('s2:', substr($a, 0, 3));
    eq('JBSWY3DPEHPK3PXP', Crypto::decrypt($a, 'totp|1'));
    eq(false, Crypto::decrypt($a, 'totp|2'), 'other user');
    eq(false, Crypto::decrypt($a), 'no context');
    no(Crypto::needs_upgrade($a));
});

t('F9: 3.0.0 values (s1) still read, and TOTP moves them to s2 bound to the user on first use', function () {
    $user = make_user('subscriber');
    $secret = Totp::new_secret();
    $old = legacy_s1($secret);
    eq($secret, Crypto::decrypt($old), 's1 reads');
    ok(Crypto::needs_upgrade($old));
    update_user_meta($user->ID, Totp::META_SECRET, $old);

    ok(Totp::verify($user->ID, Totp::code($secret, Totp::step())), 'login code works with the old value');
    $stored = get_user_meta($user->ID, Totp::META_SECRET, true);
    eq('s2:', substr($stored, 0, 3), 'migrated');
    eq($secret, Crypto::decrypt($stored, 'totp|' . $user->ID));

    // The migrated value copied to another account is useless there.
    $other = make_user('subscriber');
    update_user_meta($other->ID, Totp::META_SECRET, $stored);
    no(Totp::verify($other->ID, Totp::code($secret, Totp::step() + 1)), 'copied secret refused');
});

t('F9: the note_plain_secrets upgrade keeps existing p1 values readable', function () {
    $user = make_user('subscriber');
    delete_site_option(Crypto::PLAIN_FLAG);
    update_user_meta($user->ID, Totp::META_SECRET, 'p1:' . base64_encode('JBSWY3DPEHPK3PXP'));
    TwoFactor::note_plain_secrets();
    ok(get_site_option(Crypto::PLAIN_FLAG), 'flag set when p1 data exists');
    eq('JBSWY3DPEHPK3PXP', Crypto::decrypt('p1:' . base64_encode('JBSWY3DPEHPK3PXP')));
    delete_site_option(Crypto::PLAIN_FLAG);
});

// ---------------------------------------------------------------- F8: atomic single use.

t('F8: a time step is burned with a compare-and-set (a second request with the same step loses)', function () {
    $user = make_user('subscriber');
    update_user_meta($user->ID, Totp::META_LAST_STEP, 100);
    ok(Totp::burn_step($user->ID, 101));
    no(Totp::burn_step($user->ID, 101), 'same step again');
    no(Totp::burn_step($user->ID, 99), 'older step');
    eq('101', (string) get_user_meta($user->ID, Totp::META_LAST_STEP, true));
});

t('F8: TOTP verify refuses a code whose step another request burned after this one read it', function () {
    $user = make_user('subscriber');
    $secret = Totp::new_secret();
    update_user_meta($user->ID, Totp::META_SECRET, Crypto::encrypt($secret, 'totp|' . $user->ID));
    get_user_meta($user->ID, Totp::META_LAST_STEP, true); // Warm the meta cache.
    global $wpdb;
    // The parallel request: stores the step straight in the database.
    $wpdb->insert($wpdb->usermeta, array('user_id' => $user->ID, 'meta_key' => Totp::META_LAST_STEP, 'meta_value' => (string) Totp::step()));
    no(Totp::verify($user->ID, Totp::code($secret, Totp::step())), 'the stale cache does not let the code through twice');
});

t('F8: a backup code used by a parallel request (database newer than the cache) is refused', function () {
    $user = make_user('subscriber');
    $codes = BackupCodes::generate($user->ID);
    $hashes = get_user_meta($user->ID, BackupCodes::META, true); // Warm the cache.
    global $wpdb;
    // The parallel request removed the first code.
    $wpdb->update($wpdb->usermeta, array('meta_value' => maybe_serialize(array_slice($hashes, 1))), array('user_id' => $user->ID, 'meta_key' => BackupCodes::META));
    no(BackupCodes::use_code($user->ID, $codes[0]), 'already used elsewhere');
    ok(BackupCodes::use_code($user->ID, $codes[1]), 'another code still works');
    eq(8, BackupCodes::remaining($user->ID));
});

// ---------------------------------------------------------------- F4: counters.

t('F4: increment() is one atomic step per call and starts at 1', function () {
    $user = make_user('subscriber');
    eq(1, LoginFlow::increment($user->ID, 'authlify_test_counter'));
    eq(2, LoginFlow::increment($user->ID, 'authlify_test_counter'));
    eq(3, LoginFlow::increment($user->ID, 'authlify_test_counter'));
    eq('3', (string) get_user_meta($user->ID, 'authlify_test_counter', true));
});

t('F4: the account throttle starts at ten wrong codes in the window and ends with it', function () {
    $user = make_user('subscriber');
    update_user_meta($user->ID, LoginFlow::META_FAILS_SINCE, time());
    update_user_meta($user->ID, LoginFlow::META_FAILS, 9);
    eq(null, LoginFlow::account_throttle($user));
    update_user_meta($user->ID, LoginFlow::META_FAILS, 10);
    is_error_code('authlify_2fa_throttled', LoginFlow::account_throttle($user));
    update_user_meta($user->ID, LoginFlow::META_FAILS_SINCE, time() - LoginFlow::ACCOUNT_WINDOW - 1);
    eq(null, LoginFlow::account_throttle($user), 'window over');
});

// ---------------------------------------------------------------- F5: signed passkey tokens.

t('F5: passkey tokens: signature, expiry and single-use marker', function () {
    $token = LoginFlow::passkey_token('abc_DEF-123');
    eq('abc_DEF-123', LoginFlow::passkey_challenge($token));
    $parts = explode('.', $token);
    eq('', LoginFlow::passkey_challenge('zzz.' . $parts[1] . '.' . $parts[2]), 'other challenge');
    eq('', LoginFlow::passkey_challenge(LoginFlow::passkey_token('abc', time() - 1)), 'expired');
    eq('', LoginFlow::passkey_challenge($parts[0] . '.' . ($parts[1] + 600) . '.' . $parts[2]), 'expiry pushed');
    eq('', LoginFlow::passkey_challenge('garbage'));

    $key = 'authlify_pku_unit_' . wp_generate_password(8, false);
    ok(LoginFlow::claim_once($key, 60));
    no(LoginFlow::claim_once($key, 60), 'second claim');
    delete_transient($key);
});

// ---------------------------------------------------------------- F10 / PERF-04: passkeys.

t('F10: renaming another user\'s passkey is refused (it was a 200 with no change)', function () {
    $owner = make_user('subscriber');
    $other = make_user('subscriber');
    $row = pk_row($owner->ID);
    no(Passkeys::rename($other->ID, $row, 'hijack'), 'not the owner');
    ok(Passkeys::rename($owner->ID, $row, 'Laptop'), 'owner');
    ok(Passkeys::rename($owner->ID, $row, 'Laptop'), 'owner, same name');
});

t('PERF-04: any() and count_for_user() do not scan the table on every request', function () {
    if (!Passkeys::supported()) {
        skip('passkeys need PHP 8');
    }
    $user = make_user('subscriber');
    pk_row($user->ID);
    Passkeys::flush($user->ID, true);
    ok(Passkeys::any());
    eq(1, Passkeys::count_for_user($user->ID));

    global $wpdb;
    $before = $wpdb->num_queries;
    Passkeys::any();
    Passkeys::count_for_user($user->ID);
    eq($before, $wpdb->num_queries, 'no queries once known');

    // Counting one user is an indexed lookup, not the GROUP BY over everyone.
    Passkeys::flush($user->ID);
    $wpdb->queries = array();
    $save = defined('SAVEQUERIES') && SAVEQUERIES;
    Passkeys::count_for_user($user->ID);
    if ($save) {
        not_contains('GROUP BY', wp_json_encode($wpdb->queries));
    }

    // Removing the last passkey switches the login button off again.
    $rows = Passkeys::for_user($user->ID);
    Passkeys::delete($user->ID, (int) $rows[0]->id);
    no(Passkeys::any(), 'none left');
});

// ---------------------------------------------------------------- ARCH-03 / F11: methods that cannot run.

t('ARCH-03: with Pro\'s email method gone, an email-code user keeps 2FA (fail closed) and Site Health says so', function () {
    $user = make_user('editor');
    update_user_meta($user->ID, 'authlify_pro_email_2fa', 1);
    if (class_exists('\AuthlifyPro\TwoFactor\EmailCode')) {
        remove_filter('authlify_twofactor_methods', array('AuthlifyPro\TwoFactor\EmailCode', 'register'));
    }
    try {
        $methods = TwoFactor::methods();
        ok(!empty($methods['email']['unavailable']), 'stand-in method');
        ok(TwoFactor::is_active_for($user->ID), 'two-factor stays on');
        eq(array('email'), TwoFactor::user_methods($user->ID));
        is_error_code('authlify_2fa_failed', call_user_func($methods['email']['verify'], $user), 'the stand-in never passes');
        eq(array('Email code'), TwoFactor::unavailable_for($user->ID));
        contains('email codes, which need Authlify Pro', implode(' ', TwoFactor::method_problems()));
        TwoFactor::reset($user->ID);
        eq('', get_user_meta($user->ID, 'authlify_pro_email_2fa', true), 'an admin reset clears it');
    } finally {
        if (class_exists('\AuthlifyPro\TwoFactor\EmailCode')) {
            add_filter('authlify_twofactor_methods', array('AuthlifyPro\TwoFactor\EmailCode', 'register'));
        }
    }
});

t('F11: passkeys only for another host are reported in Site Health', function () {
    $user = make_user('subscriber');
    pk_row($user->ID, 'old-host.example');
    $stranded = Passkeys::stranded();
    eq(1, $stranded['other_host']);
    contains('old-host.example', implode(' ', TwoFactor::method_problems()));
    $result = TwoFactor::site_health_methods();
    eq('critical', $result['status']);
});

// ---------------------------------------------------------------- F3: confirm identity.

t('F3: a new authenticator app and new backup codes ask add-ons to confirm identity first', function () {
    $user = make_user('subscriber');
    wp_set_current_user($user->ID);
    set_settings(array('twofa_enabled' => true, 'twofa_methods' => array('totp', 'backup', 'passkey')));
    $asked = array();
    add_test_filter('authlify_confirm_identity', function ($ok, $reason) use (&$asked) {
        $asked[] = $reason;

        return new WP_Error('authlify_sudo_required', 'confirm', array('status' => 403));
    }, 10, 2);

    is_error_code('authlify_sudo_required', Rest::totp_setup());
    is_error_code('authlify_sudo_required', Rest::backup_generate());
    eq(array('totp_setup', 'backup_regenerate'), $asked);
    eq('', get_user_meta($user->ID, Totp::META_PENDING, true), 'no pending secret');
    eq(0, BackupCodes::remaining($user->ID), 'no codes');
});

// ---------------------------------------------------------------- ARCH-02: account blocks.

t('ARCH-02: blocked and temporary accounts: authenticate, reset and application passwords', function () {
    $user = make_user('administrator');
    no(LoginFlow::blocked($user->ID));

    update_user_meta($user->ID, LoginFlow::META_TEMP_EXPIRES, (string) (time() + 600));
    no(LoginFlow::blocked($user->ID), 'valid temporary access is not a block');
    is_error_code('authlify_sign_in_blocked', LoginFlow::refuse_blocked($user), 'but no password sign-in');
    eq(false, LoginFlow::no_reset_when_blocked(true, $user->ID));
    eq(false, LoginFlow::no_app_passwords_when_blocked(true, $user));

    update_user_meta($user->ID, LoginFlow::META_TEMP_EXPIRES, (string) (time() - 1));
    ok(LoginFlow::blocked($user->ID), 'expired');
    is_error_code('authlify_sign_in_blocked', LoginFlow::sign_in_error($user, 'passkey_login'));
});
