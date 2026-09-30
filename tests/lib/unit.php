<?php
/**
 * In-WordPress test runner.
 *
 *   wp eval-file tests/lib/unit.php tests/unit/settings.php
 *
 * Loads one test file inside a fully booted WordPress (WP-CLI), runs its tests
 * and prints one result line per test. Every test runs isolated: the Authlify
 * options, the lockout table, $_SERVER/$_POST/$_GET/$_REQUEST/$_COOKIE, the
 * current user, filters added with add_test_filter() and users made with
 * make_user() are restored or removed afterwards.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

require_once __DIR__ . '/assert.php';

$authlify_test_file = isset($args[0]) ? $args[0] : '';
if ('' === $authlify_test_file || !is_readable($authlify_test_file)) {
    echo "FAIL\trunner\t(load)\tno test file: {$authlify_test_file}\n";
    return;
}

// Options a test may change. Every test gets them back as they were.
$GLOBALS['authlify_snapshot_options'] = array(
    'authlify_settings', 'authlify_design', 'authlify_design_draft', 'authlify_pro_license', 'authlify_pro_sync_key',
    'authlify_version', 'authlify_migrated_from', 'authlify_fresh_install', 'permalink_structure', 'users_can_register',
);
$GLOBALS['authlify_test_filters'] = array();
$GLOBALS['authlify_test_users'] = array();

/**
 * add_filter() that is undone after the test.
 */
function add_test_filter($hook, $callback, $priority = 10, $accepted = 1)
{
    add_filter($hook, $callback, $priority, $accepted);
    $GLOBALS['authlify_test_filters'][] = array($hook, $callback, $priority);
}

/**
 * Answer every outgoing HTTP request with $callback($url, $args) (array response or WP_Error).
 */
function mock_http($callback)
{
    add_test_filter('pre_http_request', function ($pre, $args, $url) use ($callback) {
        return $callback($url, $args);
    }, 1, 3);
}

/**
 * A fake HTTP response.
 */
function http_response($code, $body)
{
    return array(
        'headers' => array(),
        'body' => is_string($body) ? $body : wp_json_encode($body),
        'response' => array('code' => $code, 'message' => get_status_header_desc($code)),
        'cookies' => array(),
        'filename' => null,
    );
}

/**
 * A throwaway user, deleted after the test.
 */
function make_user($role = 'subscriber', array $extra = array())
{
    $login = 'atest_' . $role . '_' . wp_generate_password(6, false, false);
    $id = wp_insert_user(array_merge(array(
        'user_login' => strtolower($login),
        'user_pass' => 'Test-Pass-' . wp_generate_password(8, false, false) . '!',
        'user_email' => strtolower($login) . '@example.test',
        'role' => $role,
    ), $extra));
    if (is_wp_error($id)) {
        fail('make_user: ' . $id->get_error_message());
    }
    $GLOBALS['authlify_test_users'][] = $id;

    return get_userdata($id);
}

/**
 * Change settings for this test (restored afterwards) and reset caches.
 */
function set_settings(array $values)
{
    \Authlify\Settings::update($values);
    \Authlify\Settings::flush();
    \Authlify\Net\Ip::reset();
}

/**
 * Pretend the request comes from $ip (and optionally with headers).
 */
function as_ip($ip, array $server = array())
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    foreach ($server as $key => $value) {
        $_SERVER[$key] = $value;
    }
    \Authlify\Net\Ip::reset();
}

/**
 * Run PHP in a separate WP-CLI process (for constants that cannot be undefined).
 *
 * @param string $php Code for `wp eval`.
 * @return string Output.
 */
function subprocess($php)
{
    $wp = getenv('AUTHLIFY_TEST_WPCLI');
    if (!$wp) {
        skip('AUTHLIFY_TEST_WPCLI is not set');
    }

    return (string) shell_exec(escapeshellarg($wp) . ' eval ' . escapeshellarg($php) . ' 2>&1');
}

/**
 * Snapshot and restore everything a test may change.
 */
function authlify_isolate($body)
{
    global $wpdb;

    $options = array();
    foreach ($GLOBALS['authlify_snapshot_options'] as $name) {
        $options[$name] = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    }
    $limits_table = $wpdb->base_prefix . 'authlify_limits';
    $limits = $wpdb->get_results("SELECT * FROM {$limits_table}", ARRAY_A);
    $globals = array($_SERVER, $_POST, $_GET, $_REQUEST, $_COOKIE);
    $user = get_current_user_id();

    try {
        $body();
    } finally {
        foreach ($GLOBALS['authlify_test_filters'] as $filter) {
            remove_filter($filter[0], $filter[1], $filter[2]);
        }
        $GLOBALS['authlify_test_filters'] = array();

        list($_SERVER, $_POST, $_GET, $_REQUEST, $_COOKIE) = $globals;

        foreach ($options as $name => $value) {
            if (null === $value) {
                $wpdb->delete($wpdb->options, array('option_name' => $name));
            } else {
                $wpdb->replace($wpdb->options, array('option_name' => $name, 'option_value' => $value, 'autoload' => 'auto'));
            }
            wp_cache_delete($name, 'options');
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');

        $wpdb->query("DELETE FROM {$limits_table}");
        foreach ($limits as $row) {
            $wpdb->insert($limits_table, $row);
        }

        if ($GLOBALS['authlify_test_users']) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ($GLOBALS['authlify_test_users'] as $id) {
                wp_delete_user($id);
            }
            $GLOBALS['authlify_test_users'] = array();
        }

        wp_set_current_user($user);
        \Authlify\Settings::flush();
        \Authlify\Net\Ip::reset();
    }
}

try {
    require $authlify_test_file;
} catch (Throwable $e) {
    echo "FAIL\t" . basename(dirname($authlify_test_file)) . '/' . basename($authlify_test_file, '.php') . "\t(load)\t" . str_replace(array("\n", "\t"), ' ', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) . "\n";
    return;
}

authlify_run_tests($authlify_test_file, 'authlify_isolate');
