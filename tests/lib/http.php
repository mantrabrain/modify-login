<?php
/**
 * HTTP test runner (plain PHP CLI, outside WordPress).
 *
 *   AUTHLIFY_TEST_URL=http://localhost:8919 AUTHLIFY_TEST_WPCLI=/path/wp.sh php tests/lib/http.php tests/http/router.php
 *
 * Tests talk to the test site with curl and set it up with WP-CLI (`wp eval`).
 * Every file gets the Authlify settings, the lockout table and the test users it
 * made restored afterwards.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

require_once __DIR__ . '/assert.php';

$file = isset($argv[1]) ? $argv[1] : '';
if ('' === $file || !is_readable($file)) {
    echo "FAIL\trunner\t(load)\tno test file: {$file}\n";
    exit(1);
}

define('BASE', rtrim((string) getenv('AUTHLIFY_TEST_URL'), '/'));
define('WPCLI', (string) getenv('AUTHLIFY_TEST_WPCLI'));
define('SITE_DIR', dirname(dirname(WPCLI)) === '' ? '' : dirname(WPCLI) . '/site');
define('PASS', 'Test-Pass-9x!Q');

$GLOBALS['http_users'] = array();

/**
 * Run PHP inside WordPress (a WP-CLI process).
 *
 * @param string $php Code.
 * @return string Output (trimmed).
 */
function wp_eval($php)
{
    $out = shell_exec(escapeshellarg(WPCLI) . ' eval ' . escapeshellarg($php) . ' 2>&1');

    return trim((string) $out);
}

/**
 * wp_eval() whose code echoes JSON.
 */
function wp_json($php)
{
    $out = wp_eval($php);
    $data = json_decode($out, true);
    if (null === $data && 'null' !== $out) {
        fail('wp eval did not return JSON: ' . substr($out, 0, 400));
    }

    return $data;
}

/**
 * Run a WP-CLI command.
 */
function wp_cli($args)
{
    return trim((string) shell_exec(escapeshellarg(WPCLI) . ' ' . $args . ' 2>&1'));
}

/**
 * Change Authlify settings (restored after the file).
 */
function settings(array $values)
{
    wp_eval('\Authlify\Settings::update(json_decode(base64_decode("' . base64_encode(json_encode($values)) . '"), true));');
}

/**
 * The test-IP header for a request (see harness-mu-plugin.php).
 */
function test_ip($ip)
{
    return 'X-Authlify-Test-Ip: ' . $ip . '|' . $GLOBALS['http_secret'];
}

/**
 * HTTP request.
 *
 * @param string $method GET, POST, ...
 * @param string $url    Absolute, or a path on the test site.
 * @param array  $o      headers (list), body (array|string), cookies (array name => value or "a=b; c=d" string), json (mixed), ip (string).
 * @return object code, headers (lowercase name => list), body, location, cookies (set, name => value).
 */
function request($method, $url, array $o = array())
{
    if (0 !== strpos($url, 'http')) {
        $url = BASE . '/' . ltrim($url, '/');
    }
    $ch = curl_init($url);
    $headers = isset($o['headers']) ? $o['headers'] : array();
    if (isset($o['ip'])) {
        $headers[] = test_ip($o['ip']);
    }
    if (array_key_exists('json', $o)) {
        $headers[] = 'Content-Type: application/json';
        $o['body'] = json_encode($o['json']);
    }
    if (!empty($o['cookies'])) {
        $headers[] = 'Cookie: ' . (is_array($o['cookies']) ? implode('; ', array_map(function ($k, $v) {
            return $k . '=' . $v;
        }, array_keys($o['cookies']), $o['cookies'])) : $o['cookies']);
    }
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => !empty($o['follow']),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
    ));
    if (isset($o['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($o['body']) ? http_build_query($o['body']) : $o['body']);
    }
    $raw = curl_exec($ch);
    if (false === $raw) {
        fail('request failed: ' . curl_error($ch) . ' ' . $method . ' ' . $url);
    }
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $res = (object) array('code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => array(), 'body' => (string) substr($raw, $size), 'location' => '', 'cookies' => array(), 'url' => $url);
    // With redirects followed, only the last header block counts.
    $blocks = preg_split("/\r\n\r\n(?=HTTP\/)/", trim(substr($raw, 0, $size)));
    foreach (explode("\r\n", end($blocks)) as $line) {
        if (false === strpos($line, ':')) {
            continue;
        }
        list($name, $value) = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $res->headers[$name][] = trim($value);
        if ('set-cookie' === $name) {
            $pair = explode(';', trim($value), 2)[0];
            list($cn, $cv) = array_pad(explode('=', $pair, 2), 2, '');
            // A deletion (Max-Age=0 or a date in the past) counts as no cookie.
            if (preg_match('/max-age=0/i', $value) || (preg_match('/expires=([^;]+)/i', $value, $exp) && strtotime($exp[1]) < time())) {
                $cv = '';
            }
            $res->cookies[$cn] = $cv;
        }
    }
    $res->location = isset($res->headers['location']) ? end($res->headers['location']) : '';

    return $res;
}

function get($url, array $o = array())
{
    return request('GET', $url, $o);
}

function post($url, $body = array(), array $o = array())
{
    $o['body'] = $body;

    return request('POST', $url, $o);
}

/**
 * Cookies from a response that are not deletions.
 */
function live_cookies($res)
{
    return array_filter($res->cookies, function ($v) {
        return '' !== $v && 'deleted' !== $v;
    });
}

/**
 * Create a test user through WP-CLI (deleted after the file).
 *
 * @return array id, login, email, pass.
 */
function http_user($role = 'subscriber', $meta_php = '')
{
    $login = 'atest_' . $role . '_' . substr(md5(uniqid('', true)), 0, 6);
    $id = (int) wp_eval('$id = wp_insert_user(array("user_login" => "' . $login . '", "user_pass" => "' . PASS . '", "user_email" => "' . $login . '@example.test", "role" => "' . $role . '")); echo is_wp_error($id) ? 0 : $id;');
    if (!$id) {
        fail('could not create user ' . $login);
    }
    $GLOBALS['http_users'][] = $id;

    return array('id' => $id, 'login' => $login, 'email' => $login . '@example.test', 'pass' => PASS, 'role' => $role);
}

/**
 * A logged-in browser session made on the server (no login form, no lockout risk).
 *
 * @param int      $user_id User.
 * @param string[] $actions Nonce actions to create for this session.
 * @return array cookies (name => value), rest (wp_rest nonce), nonces (action => nonce), token.
 */
function session_for($user_id, array $actions = array())
{
    $php = '$u = get_userdata(' . (int) $user_id . '); $exp = time() + 3600; $m = WP_Session_Tokens::get_instance($u->ID); $tok = $m->create($exp);'
        . '$auth = wp_generate_auth_cookie($u->ID, $exp, "auth", $tok); $li = wp_generate_auth_cookie($u->ID, $exp, "logged_in", $tok);'
        . '$_COOKIE[LOGGED_IN_COOKIE] = $li; wp_set_current_user($u->ID); $n = array();'
        . 'foreach (json_decode(base64_decode("' . base64_encode(json_encode(array_values($actions))) . '"), true) as $a) { $n[$a] = wp_create_nonce($a); }'
        . 'echo json_encode(array("cookies" => array(AUTH_COOKIE => $auth, LOGGED_IN_COOKIE => $li), "rest" => wp_create_nonce("wp_rest"), "nonces" => $n, "token" => $tok));';

    return wp_json($php);
}

/**
 * Mails captured by the harness since $since (index into the file).
 */
function mails($since = 0)
{
    $file = SITE_DIR . '/wp-content/authlify-test-mail.jsonl';
    if (!is_readable($file)) {
        return array();
    }
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));

    return array_map(function ($l) {
        return json_decode($l, true);
    }, array_slice($lines, $since));
}

function mail_count()
{
    return count(mails());
}

/**
 * The login URL for the current slug.
 */
function login_url($slug)
{
    return BASE . '/' . $slug . '/';
}

/**
 * Submit the core login form (optionally from a test IP).
 */
function login_post($url, $log, $pwd, array $extra = array(), array $o = array())
{
    $o['cookies'] = isset($o['cookies']) ? $o['cookies'] : array('wordpress_test_cookie' => 'WP%20Cookie%20check');

    return post($url, array_merge(array('log' => $log, 'pwd' => $pwd, 'wp-submit' => 'Log In', 'testcookie' => '1'), $extra), $o);
}

/**
 * A current TOTP code for a Base32 secret.
 */
function totp_now($secret, $offset = 0)
{
    $A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($secret)) as $c) {
        $bits .= str_pad(decbin(strpos($A, $c)), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $b) {
        if (8 === strlen($b)) {
            $key .= chr(bindec($b));
        }
    }
    $step = (int) floor(time() / 30) + $offset;
    $h = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
    $o = ord($h[19]) & 15;
    $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);

    return str_pad((string) ($v % 1000000), 6, '0', STR_PAD_LEFT);
}

// Snapshot per file: settings, design, lockouts, HTTP mocks.
$snapshot = wp_eval('global $wpdb; echo base64_encode(serialize(array("settings" => get_option("authlify_settings"), "design" => get_option("authlify_design"), "limits" => $wpdb->get_results("SELECT * FROM " . $wpdb->base_prefix . "authlify_limits", ARRAY_A), "structure" => get_option("permalink_structure"))));');
$GLOBALS['http_secret'] = wp_eval('echo get_option("authlify_test_secret");');

try {
    require $file;
} catch (Throwable $e) {
    echo "FAIL\t" . basename(dirname($file)) . '/' . basename($file, '.php') . "\t(load)\t" . str_replace(array("\n", "\t"), ' ', $e->getMessage()) . "\n";
    exit(1);
}

$failures = authlify_run_tests($file);

// Restore.
$restore = 'global $wpdb; $s = unserialize(base64_decode("' . $snapshot . '"));'
    . 'update_option("authlify_settings", $s["settings"]); \Authlify\Settings::flush();'
    . 'if (false === $s["design"]) { delete_option("authlify_design"); } else { update_option("authlify_design", $s["design"]); }'
    . 'if (get_option("permalink_structure") !== $s["structure"]) { update_option("permalink_structure", $s["structure"]); flush_rewrite_rules(); }'
    . '$t = $wpdb->base_prefix . "authlify_limits"; $wpdb->query("DELETE FROM $t"); foreach ($s["limits"] as $r) { $wpdb->insert($t, $r); }'
    . 'delete_option("authlify_test_http");'
    . 'require_once ABSPATH . "wp-admin/includes/user.php"; foreach (json_decode("' . json_encode($GLOBALS['http_users']) . '") as $id) { wp_delete_user($id); }';
$out = wp_eval($restore);
if ('' !== $out) {
    echo "FAIL\t" . basename(dirname($file)) . '/' . basename($file, '.php') . "\t(teardown)\t" . str_replace(array("\n", "\t"), ' ', substr($out, 0, 300)) . "\n";
}

exit($failures ? 1 : 0);
