<?php
/**
 * A tiny assertion library shared by the in-WordPress runner (unit.php) and the
 * HTTP runner (http.php).
 *
 * Tests register with t('name', function () { ... }). Assertions throw
 * AuthlifyTestFailure; skip() throws AuthlifyTestSkip. Results are printed one
 * per line as "RESULT<TAB>file<TAB>name<TAB>message" for tests/run.sh to count.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

class AuthlifyTestFailure extends Exception
{
}

class AuthlifyTestSkip extends Exception
{
}

$GLOBALS['authlify_tests'] = array();
$GLOBALS['authlify_hooks'] = array('before' => array(), 'after' => array(), 'before_all' => array(), 'after_all' => array());

/**
 * Register a test.
 *
 * @param string   $name Name.
 * @param callable $fn   Body.
 */
function t($name, $fn)
{
    $GLOBALS['authlify_tests'][] = array($name, $fn);
}

function before_each($fn)
{
    $GLOBALS['authlify_hooks']['before'][] = $fn;
}

function after_each($fn)
{
    $GLOBALS['authlify_hooks']['after'][] = $fn;
}

function before_all($fn)
{
    $GLOBALS['authlify_hooks']['before_all'][] = $fn;
}

function after_all($fn)
{
    $GLOBALS['authlify_hooks']['after_all'][] = $fn;
}

function fail($message)
{
    throw new AuthlifyTestFailure($message);
}

function skip($message)
{
    throw new AuthlifyTestSkip($message);
}

function export_value($value)
{
    if (is_object($value) && function_exists('is_wp_error') && is_wp_error($value)) {
        return 'WP_Error(' . $value->get_error_code() . ': ' . strip_tags($value->get_error_message()) . ')';
    }
    $out = is_string($value) ? '"' . $value . '"' : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    return strlen((string) $out) > 300 ? substr($out, 0, 300) . '…' : (string) $out;
}

function ok($condition, $message = 'expected true')
{
    if (!$condition) {
        fail($message);
    }
}

function no($condition, $message = 'expected false')
{
    if ($condition) {
        fail($message);
    }
}

function eq($expected, $actual, $message = '')
{
    if ($expected !== $actual) {
        fail(($message ? $message . ': ' : '') . 'expected ' . export_value($expected) . ', got ' . export_value($actual));
    }
}

function neq($unexpected, $actual, $message = '')
{
    if ($unexpected === $actual) {
        fail(($message ? $message . ': ' : '') . 'did not expect ' . export_value($actual));
    }
}

function contains($needle, $haystack, $message = '')
{
    if (is_array($haystack) ? !in_array($needle, $haystack, true) : false === strpos((string) $haystack, (string) $needle)) {
        fail(($message ? $message . ': ' : '') . export_value($needle) . ' not found in ' . export_value($haystack));
    }
}

function not_contains($needle, $haystack, $message = '')
{
    if (is_array($haystack) ? in_array($needle, $haystack, true) : false !== strpos((string) $haystack, (string) $needle)) {
        fail(($message ? $message . ': ' : '') . export_value($needle) . ' unexpectedly found in ' . export_value($haystack));
    }
}

function is_error_code($code, $value, $message = '')
{
    if (!is_wp_error($value) || $value->get_error_code() !== $code) {
        fail(($message ? $message . ': ' : '') . 'expected WP_Error ' . $code . ', got ' . export_value($value));
    }
}

/**
 * Run the registered tests and print one result line each.
 *
 * @param string   $file    Test file label.
 * @param callable $isolate Wraps each test (snapshot and restore of state).
 * @return int Failures.
 */
function authlify_run_tests($file, $isolate = null)
{
    $failures = 0;
    $label = basename(dirname($file)) . '/' . basename($file, '.php');

    foreach ($GLOBALS['authlify_hooks']['before_all'] as $hook) {
        try {
            $hook();
        } catch (Throwable $e) {
            echo "FAIL\t{$label}\t(setup)\t" . str_replace(array("\n", "\t"), ' ', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) . "\n";

            return 1;
        }
    }

    foreach ($GLOBALS['authlify_tests'] as $test) {
        list($name, $fn) = $test;
        $result = 'PASS';
        $message = '';
        $start = microtime(true);

        $body = function () use ($fn) {
            foreach ($GLOBALS['authlify_hooks']['before'] as $hook) {
                $hook();
            }
            try {
                $fn();
            } finally {
                foreach ($GLOBALS['authlify_hooks']['after'] as $hook) {
                    $hook();
                }
            }
        };

        try {
            if ($isolate) {
                $isolate($body);
            } else {
                $body();
            }
        } catch (AuthlifyTestSkip $e) {
            $result = 'SKIP';
            $message = $e->getMessage();
        } catch (AuthlifyTestFailure $e) {
            $result = 'FAIL';
            $message = $e->getMessage() . ' (line ' . authlify_test_line($e, $file) . ')';
        } catch (Throwable $e) {
            $result = 'FAIL';
            $message = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        }

        if ('FAIL' === $result) {
            $failures++;
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        echo $result . "\t" . $label . "\t" . str_replace(array("\n", "\t"), ' ', $name) . "\t" . str_replace(array("\n", "\t", "\r"), ' ', $message) . "\t" . $ms . "\n";
    }

    foreach ($GLOBALS['authlify_hooks']['after_all'] as $hook) {
        try {
            $hook();
        } catch (Throwable $e) {
            echo "FAIL\t{$label}\t(teardown)\t" . str_replace(array("\n", "\t"), ' ', $e->getMessage()) . "\n";
            $failures++;
        }
    }

    return $failures;
}

/**
 * Line in the test file where an assertion failed.
 *
 * @param Throwable $e    Exception.
 * @param string    $file Test file.
 * @return string
 */
function authlify_test_line($e, $file)
{
    foreach ($e->getTrace() as $frame) {
        if (isset($frame['file'], $frame['line']) && realpath($frame['file']) === realpath($file)) {
            return (string) $frame['line'];
        }
    }

    return '?';
}
