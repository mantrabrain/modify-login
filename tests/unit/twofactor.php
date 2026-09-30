<?php
/**
 * Two-factor: TOTP (RFC 6238 vectors, window, replay), backup codes, Crypto,
 * login nonce records, passkey user verification (software authenticator).
 *
 * The HTTP flows (second step cannot be skipped, XML-RPC refusal, recovery
 * link) are in http/twofactor.php.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\TwoFactor\BackupCodes;
use Authlify\TwoFactor\Crypto;
use Authlify\TwoFactor\LoginFlow;
use Authlify\TwoFactor\Passkeys;
use Authlify\TwoFactor\Totp;
use Authlify\TwoFactor\TwoFactor;

// RFC 6238 appendix B, SHA-1 key "12345678901234567890". Six digits are the
// last six of the RFC's eight-digit values.
const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
const RFC_VECTORS = array(
    59 => '287082',
    1111111109 => '081804',
    1111111111 => '050471',
    1234567890 => '005924',
    2000000000 => '279037',
    20000000000 => '353130',
);

t('TOTP: Base32 of the RFC key round-trips', function () {
    eq(RFC_SECRET, Totp::base32_encode('12345678901234567890'));
    eq('12345678901234567890', Totp::base32_decode(strtolower(RFC_SECRET)));
    eq('12345678901234567890', Totp::base32_decode('GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ===='), 'spaces and padding');
    eq(false, Totp::base32_decode('0189!'), 'invalid characters');
    eq(false, Totp::base32_decode(''));
});

t('TOTP: RFC 6238 SHA-1 test vectors', function () {
    foreach (RFC_VECTORS as $time => $code) {
        eq($code, Totp::code(RFC_SECRET, Totp::step($time)), 'T=' . $time);
    }
});

t('TOTP: match() accepts ±1 step and nothing wider', function () {
    $t = 1111111111;
    $step = Totp::step($t);
    eq($step, Totp::match(RFC_SECRET, Totp::code(RFC_SECRET, $step), 0, $t));
    eq($step - 1, Totp::match(RFC_SECRET, Totp::code(RFC_SECRET, $step - 1), 0, $t), 'previous step');
    eq($step + 1, Totp::match(RFC_SECRET, Totp::code(RFC_SECRET, $step + 1), 0, $t), 'next step');
    eq(false, Totp::match(RFC_SECRET, Totp::code(RFC_SECRET, $step - 2), 0, $t), 'two steps old');
    eq(false, Totp::match(RFC_SECRET, Totp::code(RFC_SECRET, $step + 2), 0, $t), 'two steps ahead');
    eq($step, Totp::match(RFC_SECRET, '050 471', 0, $t), 'spaces ignored');
    eq(false, Totp::match(RFC_SECRET, '05047', 0, $t), 'too short');
    eq(false, Totp::match(RFC_SECRET, '0504711', 0, $t), 'too long');
});

t('TOTP: min_step refuses the used step and older ones', function () {
    $t = 1111111111;
    $step = Totp::step($t);
    eq(false, Totp::match(RFC_SECRET, '050471', $step, $t));
    eq(false, Totp::match(RFC_SECRET, Totp::code(RFC_SECRET, $step - 1), $step, $t));
    eq($step + 1, Totp::match(RFC_SECRET, Totp::code(RFC_SECRET, $step + 1), $step, $t));
});

t('TOTP: verify() burns the step, so a code works once', function () {
    $user = make_user('subscriber');
    $secret = Totp::new_secret();
    eq(32, strlen($secret), '20 random bytes');
    update_user_meta($user->ID, Totp::META_SECRET, Crypto::encrypt($secret));
    $code = Totp::code($secret, Totp::step());
    ok(Totp::verify($user->ID, $code), 'first use');
    no(Totp::verify($user->ID, $code), 'replay');
    no(Totp::verify($user->ID, Totp::code($secret, Totp::step() - 1)), 'an older neighbouring code after a newer one');
    no(Totp::verify($user->ID, '000000') && '000000' !== Totp::code($secret, Totp::step() + 1));
});

t('TOTP: setup needs a right code for the pending secret, and expires', function () {
    $user = make_user('subscriber');
    $secret = Totp::begin_setup($user->ID);
    no(Totp::is_configured($user->ID));
    is_error_code('authlify_totp_invalid', Totp::finish_setup($user->ID, '000000' === Totp::code($secret, Totp::step()) ? '111111' : '000000'));
    eq(true, Totp::finish_setup($user->ID, Totp::code($secret, Totp::step())));
    ok(Totp::is_configured($user->ID));
    ok(TwoFactor::is_active_for($user->ID));
    no(Totp::verify($user->ID, Totp::code($secret, Totp::step())), 'the setup code cannot be reused to log in');

    $other = make_user('subscriber');
    Totp::begin_setup($other->ID);
    $pending = get_user_meta($other->ID, Totp::META_PENDING, true);
    $pending['expires'] = time() - 1;
    update_user_meta($other->ID, Totp::META_PENDING, $pending);
    is_error_code('authlify_totp_expired', Totp::finish_setup($other->ID, '123456'));
});

t('TOTP: the stored secret is encrypted, never plain', function () {
    $user = make_user('subscriber');
    $secret = Totp::begin_setup($user->ID);
    Totp::finish_setup($user->ID, Totp::code($secret, Totp::step()));
    $stored = get_user_meta($user->ID, Totp::META_SECRET, true);
    not_contains($secret, $stored);
    eq('s2:', substr($stored, 0, 3));
});

t('TOTP: otpauth URI', function () {
    $user = make_user('subscriber');
    $uri = Totp::uri('ABCDEF', $user);
    ok(0 === strpos($uri, 'otpauth://totp/'));
    contains('secret=ABCDEF', $uri);
    contains('digits=6', $uri);
    contains('period=30', $uri);
    contains(rawurlencode($user->user_login), $uri);
});

t('Backup codes: ten, hashed, each works once, case and dashes ignored', function () {
    $user = make_user('subscriber');
    $codes = BackupCodes::generate($user->ID);
    eq(10, count($codes));
    eq(10, count(array_unique($codes)));
    foreach ($codes as $code) {
        ok((bool) preg_match('/^[a-z2-9]{4}-[a-z2-9]{4}$/', $code), 'format ' . $code);
    }
    $stored = get_user_meta($user->ID, BackupCodes::META, true);
    not_contains(str_replace('-', '', $codes[0]), wp_json_encode($stored), 'stored hashed');

    ok(BackupCodes::use_code($user->ID, strtoupper($codes[0])), 'upper case with dash');
    no(BackupCodes::use_code($user->ID, $codes[0]), 'second use');
    eq(9, BackupCodes::remaining($user->ID));
    ok(BackupCodes::use_code($user->ID, ' ' . str_replace('-', ' ', $codes[1]) . ' '));
    no(BackupCodes::use_code($user->ID, 'aaaaaaaa'), 'unknown code');
    no(BackupCodes::use_code($user->ID, substr($codes[2], 0, 4)), 'partial code');
    eq(8, BackupCodes::remaining($user->ID));
});

t('Backup codes: a new set replaces the old one; codes alone are not 2FA', function () {
    $user = make_user('subscriber');
    $old = BackupCodes::generate($user->ID);
    BackupCodes::generate($user->ID);
    no(BackupCodes::use_code($user->ID, $old[0]), 'old set is void');
    no(TwoFactor::is_active_for($user->ID), 'backup codes on their own do not turn 2FA on');
});

t('Crypto: round trip, fresh nonce each time, tamper and junk refused', function () {
    $a = Crypto::encrypt('JBSWY3DPEHPK3PXP');
    $b = Crypto::encrypt('JBSWY3DPEHPK3PXP');
    ok(Crypto::available(), 'sodium available');
    neq($a, $b, 'random nonce');
    eq('JBSWY3DPEHPK3PXP', Crypto::decrypt($a));
    eq('', Crypto::decrypt(Crypto::encrypt('')));

    $raw = base64_decode(substr($a, 3));
    $raw[30] = chr(ord($raw[30]) ^ 1);
    eq(false, Crypto::decrypt('s1:' . base64_encode($raw)), 'bit flip');
    eq(false, Crypto::decrypt('s1:' . base64_encode('short')), 'too short');
    eq(false, Crypto::decrypt('x9:' . base64_encode(str_repeat('a', 60))), 'unknown prefix');
    eq(false, Crypto::decrypt('s1:***'), 'not base64');
    delete_site_option(Crypto::PLAIN_FLAG);
    eq(false, Crypto::decrypt('p1:' . base64_encode('legacy')), 'a plain value is refused where this site never wrote one (F9)');
    update_site_option(Crypto::PLAIN_FLAG, 1);
    eq('legacy', Crypto::decrypt('p1:' . base64_encode('legacy')), 'plain fallback format still reads where the site wrote it');
    delete_site_option(Crypto::PLAIN_FLAG);
});

t('Login nonce: valid once per record, wrong nonce and expiry refused', function () {
    $user = make_user('subscriber');
    $nonce = LoginFlow::create_nonce($user->ID, array('remember' => true, 'recover' => true));
    eq(40, strlen($nonce));
    $record = LoginFlow::record($user->ID, $nonce);
    ok(is_array($record));
    eq(true, $record['remember']);
    not_contains($nonce, wp_json_encode(get_user_meta($user->ID, LoginFlow::META, true)), 'stored hashed');
    eq(false, LoginFlow::record($user->ID, 'x' . substr($nonce, 1)));
    eq(false, LoginFlow::record($user->ID, ''));

    $other = make_user('subscriber');
    eq(false, LoginFlow::record($other->ID, $nonce), 'bound to its user');

    $meta = get_user_meta($user->ID, LoginFlow::META, true);
    $meta['expires'] = time() - 1;
    update_user_meta($user->ID, LoginFlow::META, $meta);
    eq(false, LoginFlow::record($user->ID, $nonce), 'expired');
    eq('', get_user_meta($user->ID, LoginFlow::META, true), 'expired record is removed');
});

t('2FA settings: twofa_enabled off means the flow does not run', function () {
    set_settings(array('twofa_enabled' => false));
    no(TwoFactor::runs());
    set_settings(array('twofa_enabled' => true));
    ok(TwoFactor::runs());
});

// ---------------------------------------------------------------- Passkeys (software authenticator).

/**
 * A software authenticator: an EC P-256 key registered straight into the table.
 */
function soft_passkey($user)
{
    if (!Passkeys::supported()) {
        skip('passkeys need PHP 8');
    }
    global $wpdb;
    $key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
    $pem = openssl_pkey_get_details($key)['key'];
    $id = Passkeys::b64url_encode(random_bytes(16));
    $wpdb->insert(Passkeys::table(), array(
        'user_id' => $user->ID, 'rp_id' => Passkeys::rp_id(), 'credential_hash' => hash('sha256', $id), 'credential_id' => $id,
        'public_key' => $pem, 'sign_count' => 0, 'name' => 'soft', 'created_at' => gmdate('Y-m-d H:i:s'),
    ));

    return array('id' => $id, 'key' => $key, 'user' => $user, 'row' => (int) $wpdb->insert_id);
}

/**
 * An assertion from the software authenticator.
 *
 * @param array  $pk        soft_passkey().
 * @param string $challenge Binary challenge.
 * @param int    $flags     0x01 user present, 0x04 user verified.
 */
function soft_assert(array $pk, $challenge, $flags, $origin = null, $counter = 1)
{
    $origin = null === $origin ? untrailingslashit(set_url_scheme(home_url(), wp_parse_url(home_url(), PHP_URL_SCHEME))) : $origin;
    $client = wp_json_encode(array('type' => 'webauthn.get', 'challenge' => Passkeys::b64url_encode($challenge), 'origin' => $origin, 'crossOrigin' => false));
    $auth = hash('sha256', Passkeys::rp_id(), true) . chr($flags) . pack('N', $counter);
    openssl_sign($auth . hash('sha256', $client, true), $signature, $pk['key'], OPENSSL_ALGO_SHA256);

    return array(
        'id' => $pk['id'],
        'clientDataJSON' => Passkeys::b64url_encode($client),
        'authenticatorData' => Passkeys::b64url_encode($auth),
        'signature' => Passkeys::b64url_encode($signature),
        'userHandle' => '',
    );
}

function soft_cleanup(array $pk)
{
    global $wpdb;
    $wpdb->delete(Passkeys::table(), array('id' => $pk['row']));
}

t('Passkey: passwordless sign-in requires user verification (UV)', function () {
    $pk = soft_passkey(make_user('subscriber'));
    try {
        $challenge = random_bytes(32);
        $result = Passkeys::verify_assertion(soft_assert($pk, $challenge, 0x01), Passkeys::b64url_encode($challenge), 0);
        is_error_code('authlify_passkey_failed', $result, 'presence only (no PIN or biometric)');

        $challenge = random_bytes(32);
        $result = Passkeys::verify_assertion(soft_assert($pk, $challenge, 0x05), Passkeys::b64url_encode($challenge), 0);
        ok($result instanceof WP_User && $result->ID === $pk['user']->ID, 'UV set: signs in as the owner, got ' . export_value($result));
    } finally {
        soft_cleanup($pk);
    }
});

t('Passkey: as a second factor, presence is enough', function () {
    $pk = soft_passkey(make_user('subscriber'));
    try {
        $challenge = random_bytes(32);
        $result = Passkeys::verify_assertion(soft_assert($pk, $challenge, 0x01), Passkeys::b64url_encode($challenge), $pk['user']->ID);
        ok($result instanceof WP_User, 'got ' . export_value($result));
    } finally {
        soft_cleanup($pk);
    }
});

t('Passkey: wrong user, wrong challenge, other origin, bad signature, no presence', function () {
    $pk = soft_passkey(make_user('subscriber'));
    $other = make_user('subscriber');
    try {
        $c = random_bytes(32);
        is_error_code('authlify_passkey_failed', Passkeys::verify_assertion(soft_assert($pk, $c, 0x05), Passkeys::b64url_encode($c), $other->ID), 'passkey of another user');
        is_error_code('authlify_passkey_failed', Passkeys::verify_assertion(soft_assert($pk, $c, 0x05), Passkeys::b64url_encode(random_bytes(32)), 0), 'challenge mismatch');
        is_error_code('authlify_passkey_failed', Passkeys::verify_assertion(soft_assert($pk, $c, 0x05, 'http://evil.localhost:8919'), Passkeys::b64url_encode($c), 0), 'foreign origin');
        $a = soft_assert($pk, $c, 0x05);
        $a['signature'] = Passkeys::b64url_encode(random_bytes(70));
        is_error_code('authlify_passkey_failed', Passkeys::verify_assertion($a, Passkeys::b64url_encode($c), 0), 'bad signature');
        is_error_code('authlify_passkey_failed', Passkeys::verify_assertion(soft_assert($pk, $c, 0x00), Passkeys::b64url_encode($c), $pk['user']->ID), 'user not present');
        is_error_code('authlify_passkey_failed', Passkeys::verify_assertion(soft_assert($pk, $c, 0x05), '', 0), 'no challenge');
    } finally {
        soft_cleanup($pk);
    }
});

t('Passkey: sign counter must move forward (cloned authenticator)', function () {
    $pk = soft_passkey(make_user('subscriber'));
    try {
        $c = random_bytes(32);
        ok(Passkeys::verify_assertion(soft_assert($pk, $c, 0x05, null, 10), Passkeys::b64url_encode($c), 0) instanceof WP_User);
        $c = random_bytes(32);
        is_error_code('authlify_passkey_failed', Passkeys::verify_assertion(soft_assert($pk, $c, 0x05, null, 5), Passkeys::b64url_encode($c), 0), 'counter went backwards');
    } finally {
        soft_cleanup($pk);
    }
});

t('Passkey: sign-in options ask for UV "required" without a user, "preferred" with one', function () {
    if (!Passkeys::supported()) {
        skip('passkeys need PHP 8');
    }
    $o = Passkeys::assertion_options(0);
    eq('required', $o['options']['publicKey']['userVerification']);
    $user = make_user('subscriber');
    $o = Passkeys::assertion_options($user->ID);
    eq('preferred', $o['options']['publicKey']['userVerification']);
});
