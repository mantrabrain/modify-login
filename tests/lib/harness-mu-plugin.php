<?php
/**
 * Plugin Name: Authlify test harness (test site only)
 * Description: Installed by tests/run.sh into the dedicated test site and removed afterwards. Captures mail, blocks outgoing HTTP (with mocks), and lets the suite choose the client IP of a request.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

defined('ABSPATH') || exit;

/*
 * Client IP for this request: "X-Authlify-Test-Ip: <ip>|<secret>". The secret is
 * written by the runner into the option below, so only the suite can use it.
 * This keeps brute-force tests from locking out the address the suite runs from.
 */
if (!empty($_SERVER['HTTP_X_AUTHLIFY_TEST_IP'])) {
    $authlify_test_parts = explode('|', (string) $_SERVER['HTTP_X_AUTHLIFY_TEST_IP'], 2);
    add_action('muplugins_loaded', function () use ($authlify_test_parts) {
        $secret = (string) get_option('authlify_test_secret', '');
        if ('' !== $secret && isset($authlify_test_parts[1]) && hash_equals($secret, $authlify_test_parts[1]) && filter_var($authlify_test_parts[0], FILTER_VALIDATE_IP)) {
            $_SERVER['REMOTE_ADDR'] = $authlify_test_parts[0];
        }
    }, 0);
    // muplugins_loaded fired already for this file? Apply right away as well.
    if (function_exists('get_option')) {
        $secret = (string) get_option('authlify_test_secret', '');
        if ('' !== $secret && isset($authlify_test_parts[1]) && hash_equals($secret, $authlify_test_parts[1]) && filter_var($authlify_test_parts[0], FILTER_VALIDATE_IP)) {
            $_SERVER['REMOTE_ADDR'] = $authlify_test_parts[0];
        }
    }
    unset($_SERVER['HTTP_X_AUTHLIFY_TEST_IP']);
}

// Mail: never sent, written to a JSON-lines file for the suite to read.
add_filter('pre_wp_mail', function ($return, $atts) {
    $line = wp_json_encode(array(
        'time' => time(),
        'to' => $atts['to'],
        'subject' => $atts['subject'],
        'message' => $atts['message'],
    ));
    file_put_contents(WP_CONTENT_DIR . '/authlify-test-mail.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);

    return true;
}, 999, 2);

/*
 * Outgoing HTTP: requests to this site pass; everything else is answered from
 * the mocks in the authlify_test_http option or refused.
 *
 * Mock rules: array( array( 'match' => substring, 'code' => 200, 'body' => '...' ) ).
 * Special rule 'siteverify': token "good" succeeds, anything else fails.
 */
add_filter('pre_http_request', function ($pre, $args, $url) {
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if (in_array($host, array('localhost', '127.0.0.1', '::1', '[::1]'), true)) {
        return $pre;
    }

    $rules = get_option('authlify_test_http', array());
    foreach ((array) $rules as $rule) {
        if (!isset($rule['match']) || false === strpos($url, $rule['match'])) {
            continue;
        }
        if (!empty($rule['siteverify'])) {
            $token = isset($args['body']['response']) ? (string) $args['body']['response'] : '';
            $body = 'good' === $token
                ? array('success' => true, 'hostname' => $host = (string) wp_parse_url(home_url(), PHP_URL_HOST))
                : array('success' => false, 'error-codes' => array('invalid-input-response'));

            return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
        }

        return array('headers' => array(), 'body' => isset($rule['body']) ? (string) $rule['body'] : '', 'response' => array('code' => isset($rule['code']) ? (int) $rule['code'] : 200, 'message' => ''), 'cookies' => array(), 'filename' => null);
    }

    return new WP_Error('authlify_test_offline', 'Outgoing HTTP is blocked on the test site: ' . $url);
}, 1, 3);
