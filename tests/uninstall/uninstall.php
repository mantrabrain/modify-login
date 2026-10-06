<?php
/**
 * Uninstall: runs both plugins' uninstall.php (through `wp plugin uninstall`)
 * on disposable WordPress sites with COPIES of the plugins, once with "Delete
 * all data" off and once with it on, and checks exactly what remains.
 *
 * The sites live next to the test site, in {AUTHLIFY_TEST_DIR}-uninstall-{off,on},
 * with their own databases named after that folder, so parallel suites on other
 * sites never share them (CMPT-14). They are rebuilt on every run and removed
 * afterwards; the main test site is not touched.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

/**
 * Folder and database of one disposable site, derived from AUTHLIFY_TEST_DIR.
 */
function u_paths($name)
{
    $base = rtrim((string) getenv('AUTHLIFY_TEST_DIR'), '/');
    if ('' === $base) {
        $base = '/tmp/claude-501/authlify-testsuite';
    }

    return array(
        'dir' => $base . '-uninstall-' . $name,
        'db' => 'authlify_un_' . substr(md5($base), 0, 10) . '_' . $name,
    );
}

function u_site($name)
{
    $paths = u_paths($name);
    $dir = $paths['dir'];
    $GLOBALS['u_sites'][$name] = $dir;
    $env = 'AUTHLIFY_TEST_DIR=' . escapeshellarg($dir) . ' AUTHLIFY_TEST_DB=' . escapeshellarg($paths['db']) . ' AUTHLIFY_TEST_COPY_PLUGINS=1 AUTHLIFY_TEST_NO_SERVER=1';
    $out = shell_exec($env . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/make-site.sh') . ' 2>&1');
    if (false === strpos((string) $out, 'Site ready')) {
        fail('could not build the disposable site: ' . substr((string) $out, -300));
    }
    ok(!is_link($dir . '/site/wp-content/plugins/modify-login'), 'plugins are copies, not symlinks');

    return $dir . '/wp.sh';
}

function u_eval($wp, $php)
{
    return trim((string) shell_exec(escapeshellarg($wp) . ' eval ' . escapeshellarg($php) . ' 2>&1'));
}

$GLOBALS['u_sites'] = array();

// Remove the disposable sites and their databases when the file is done.
after_all(function () {
    foreach ($GLOBALS['u_sites'] as $dir) {
        if (is_executable($dir . '/wp.sh')) {
            // Through PHP: `wp db drop` needs the mysql client, which may be missing.
            shell_exec(escapeshellarg($dir . '/wp.sh') . ' eval ' . escapeshellarg('global $wpdb; $wpdb->query("DROP DATABASE IF EXISTS `" . DB_NAME . "`");') . ' 2>&1');
        }
        if (0 === strpos($dir, '/') && false !== strpos(basename($dir), '-uninstall-')) {
            shell_exec('rm -rf ' . escapeshellarg($dir));
        }
    }
});

/**
 * Fill the site with data from both plugins, plus unrelated data that must survive.
 */
function u_populate($wp, $delete)
{
    $php = <<<'PHP'
global $wpdb;
\Authlify\Settings::update(array("delete_data" => DELETE, "login_slug" => "gone-door", "geo_source" => "dbip", "limit_attempts" => 7));
$s = get_option("authlify_settings"); $s["pro_sudo"] = true; $s["pro_twofa_roles"] = array("administrator"); update_option("authlify_settings", $s);
update_user_meta(1, "authlify_totp_secret", "s1:x");
update_user_meta(1, "authlify_pro_trusted_devices", array("x"));
update_user_meta(1, "unrelated_meta", "keep");
update_option("authlify_pro_license", array("key" => "k", "status" => "valid"));
update_option("authlify_pro_sync_key", array("hash" => "h"));
update_option("modify_login_settings", array("login_endpoint" => "old"));
update_option("modify_login_version", "2.1.0");
update_option("mb_login_endpoint", "older");
update_option("unrelated_option", "keep");
set_transient("authlify_free_t", 1, 3600);
set_transient("authlify_pro_t", 1, 3600);
set_transient("unrelated_t", 1, 3600);
$up = wp_upload_dir(null, false);
wp_mkdir_p($up["basedir"] . "/authlify"); file_put_contents($up["basedir"] . "/authlify/login-test.css", "a{}");
wp_mkdir_p($up["basedir"] . "/authlify-geo"); file_put_contents($up["basedir"] . "/authlify-geo/v4.bin", "x"); file_put_contents($up["basedir"] . "/authlify-geo/v6.bin", "x");
wp_mkdir_p($up["basedir"] . "/unrelated"); file_put_contents($up["basedir"] . "/unrelated/keep.txt", "x");
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}modify_login_logs (id bigint(20) NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->base_prefix}authlify_pro_testtable (id int)");
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->base_prefix}unrelated_table (id int)");
\Authlify\Log\Log::add("login_failed", array("username" => "someone"));
wp_schedule_event(time() + 3600, "daily", "authlify_daily");
wp_schedule_event(time() + 3600, "daily", "authlify_pro_test_cron");
wp_schedule_event(time() + 3600, "daily", "unrelated_cron");
get_role("administrator")->add_cap("manage_modify_login");
echo "ok";
PHP;
    eq('ok', u_eval($wp, str_replace('DELETE', $delete ? 'true' : 'false', $php)), 'populate');
}

/**
 * What is left, as data.
 */
function u_state($wp)
{
    $php = <<<'PHP'
global $wpdb;
wp_cache_flush();
$up = wp_upload_dir(null, false);
$cron = array(); foreach ((array) _get_cron_array() as $events) { $cron = array_merge($cron, array_keys((array) $events)); }
echo json_encode(array(
  "tables" => $wpdb->get_col("SHOW TABLES"),
  "options" => $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%authlify%' OR option_name LIKE 'modify\\_login%' OR option_name LIKE 'mb\\_%' OR option_name LIKE 'unrelated%' OR option_name LIKE '%unrelated\\_t'"),
  "settings" => get_option("authlify_settings", null),
  "usermeta" => $wpdb->get_col("SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = 1 AND (meta_key LIKE 'authlify%' OR meta_key = 'unrelated_meta')"),
  "files" => array(
    "css" => file_exists($up["basedir"] . "/authlify/login-test.css"),
    "css_dir" => is_dir($up["basedir"] . "/authlify"),
    "geo" => file_exists($up["basedir"] . "/authlify-geo/v4.bin"),
    "geo_dir" => is_dir($up["basedir"] . "/authlify-geo"),
    "unrelated" => file_exists($up["basedir"] . "/unrelated/keep.txt"),
  ),
  "cron" => array_values(array_unique($cron)),
  "cap" => (bool) get_role("administrator")->has_cap("manage_modify_login"),
  "blogname" => get_option("blogname"),
));
PHP;
    $out = u_eval($wp, $php);
    $state = json_decode($out, true);
    if (!is_array($state)) {
        fail('state: ' . substr($out, 0, 300));
    }

    return $state;
}

function u_uninstall($wp, $plugin)
{
    $out = trim((string) shell_exec(escapeshellarg($wp) . ' plugin uninstall ' . $plugin . ' --deactivate --skip-delete 2>&1'));
    contains('Success', $out, 'wp plugin uninstall ' . $plugin);
    not_contains('Fatal', $out);
}

function has_table(array $state, $suffix)
{
    return (bool) preg_grep('/_' . preg_quote($suffix, '/') . '$/', $state['tables']);
}

t('"Delete all data" off: Pro removes only its derived country files; free removes nothing', function () {
    $wp = u_site('off');
    u_populate($wp, false);

    u_uninstall($wp, 'authlify-pro');
    $s = u_state($wp);
    no($s['files']['geo'], 'country database files removed (derived data only Pro can read)');
    eq('headers', $s['settings']['geo_source'], 'the Pro-only country source falls back to headers');
    eq(true, $s['settings']['pro_sudo'], 'Pro settings kept');
    contains('authlify_pro_license', $s['options']);
    ok(has_table($s, 'authlify_pro_testtable'), 'Pro tables kept');
    contains('authlify_pro_trusted_devices', $s['usermeta']);

    u_uninstall($wp, 'modify-login');
    $s = u_state($wp);
    foreach (array('authlify_log', 'authlify_limits', 'authlify_passkeys', 'modify_login_logs') as $t) {
        ok(has_table($s, $t), 'table ' . $t . ' kept');
    }
    eq('gone-door', $s['settings']['login_slug']);
    eq(7, $s['settings']['limit_attempts']);
    foreach (array('authlify_settings', 'authlify_version', 'modify_login_settings', 'mb_login_endpoint', '_transient_authlify_free_t') as $o) {
        contains($o, $s['options'], 'option ' . $o . ' kept');
    }
    contains('authlify_totp_secret', $s['usermeta']);
    ok($s['files']['css'], 'compiled login CSS kept');
    ok($s['cap'], 'capability kept');
});

t('"Delete all data" on: Pro removes only Pro data, then free removes the rest; unrelated data survives', function () {
    $wp = u_site('on');
    u_populate($wp, true);

    u_uninstall($wp, 'authlify-pro');
    $s = u_state($wp);
    eq(array(), array_values(preg_grep('/^pro_/', array_keys((array) $s['settings']))), 'pro_* keys removed from the settings');
    eq(7, $s['settings']['limit_attempts'], 'free settings intact');
    eq(array(), array_values(preg_grep('/authlify_pro_/', $s['options'])), 'authlify_pro_* options and transients removed');
    no(has_table($s, 'authlify_pro_testtable'), 'authlify_pro_* tables dropped');
    ok(has_table($s, 'authlify_log'), 'free tables intact');
    not_contains('authlify_pro_trusted_devices', $s['usermeta']);
    contains('authlify_totp_secret', $s['usermeta'], 'free user meta intact');
    not_contains('authlify_pro_test_cron', $s['cron']);
    contains('authlify_daily', $s['cron'], 'free cron intact');
    no($s['files']['geo_dir'], 'country database folder removed');

    u_uninstall($wp, 'modify-login');
    $s = u_state($wp);
    foreach (array('authlify_log', 'authlify_limits', 'authlify_passkeys', 'modify_login_logs') as $t) {
        no(has_table($s, $t), 'table ' . $t . ' dropped');
    }
    eq(null, $s['settings'], 'settings option deleted');
    eq(array(), array_values(preg_grep('/authlify|modify_login|^mb_/', $s['options'])), 'every Authlify, Modify Login and 1.x option and transient');
    eq(array('unrelated_meta'), $s['usermeta'], 'Authlify user meta removed, other meta kept');
    no($s['files']['css'], 'compiled CSS removed');
    no($s['files']['css_dir'], 'uploads/authlify removed');
    not_contains('authlify_daily', $s['cron']);
    no($s['cap'], 'legacy capability removed');

    // Unrelated data untouched.
    ok(has_table($s, 'unrelated_table'));
    ok(has_table($s, 'posts'));
    contains('unrelated_option', $s['options']);
    contains('_transient_unrelated_t', $s['options']);
    contains('unrelated_cron', $s['cron']);
    ok($s['files']['unrelated']);
    eq('Authlify Test Suite', $s['blogname']);
});

t('"Delete all data" on, free uninstalled while Pro is still installed: no fatal, Pro data goes too', function () {
    $wp = u_site('on');
    u_populate($wp, true);
    u_uninstall($wp, 'modify-login');
    $s = u_state($wp);
    eq(null, $s['settings']);
    eq(array(), array_values(preg_grep('/authlify/', $s['options'])), 'free\'s authlify_% sweep includes Pro options');
    // Pro's own uninstall afterwards must still run cleanly with free gone.
    u_uninstall($wp, 'authlify-pro');
    $s = u_state($wp);
    ok($s['files']['unrelated']);
    eq(array(), array_values(preg_grep('/^authlify_pro_/', $s['cron'])), 'Pro cron events cleared');
    no(has_table($s, 'authlify_pro_testtable'), 'authlify_pro_* tables dropped');
});
