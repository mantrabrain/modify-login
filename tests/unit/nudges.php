<?php
/**
 * The free-to-Pro channel: inline cards, the review request, facts, link targets
 * and what the Free vs Pro page promises.
 *
 * The suite's site has Pro active, so most tests switch pointers on with the
 * authlify_show_pro_pointers filter to see what a free site sees.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Admin\Dashboard;
use Authlify\Admin\Docs;
use Authlify\Admin\Facts;
use Authlify\Admin\Nudges;
use Authlify\Admin\ProPage;
use Authlify\Admin\Upsell;
use Authlify\Log\Log;
use Authlify\TwoFactor\TwoFactor;

function nudge_free()
{
    add_test_filter('authlify_show_pro_pointers', '__return_true');
}

function nudge_installed($days)
{
    delete_option(Nudges::FIRST_SEEN);
    add_option(Nudges::FIRST_SEEN, time() - $days * DAY_IN_SECONDS, '', false);
}

function nudge_facts($week_failed = 0, $week_lockouts = 0, $month_failed = null, $month_lockouts = null)
{
    set_transient(Facts::CACHE, array(
        'week' => array('failed' => $week_failed, 'lockouts' => $week_lockouts),
        'month' => array('failed' => null === $month_failed ? $week_failed : $month_failed, 'lockouts' => null === $month_lockouts ? $week_lockouts : $month_lockouts),
        'days' => array(),
    ), 300);
}

function nudge_coverage($admins, $with, $customers = 0)
{
    $coverage = array('administrator' => array('label' => 'Administrator', 'total' => $admins, 'with' => $with));
    if ($customers) {
        $coverage['customer'] = array('label' => 'Customer', 'total' => $customers, 'with' => 0);
    }
    set_transient(TwoFactor::COVERAGE_CACHE, $coverage, 300);
}

function nudge_html($place, array $data = array())
{
    ob_start();
    Nudges::render($place, $data);

    return ob_get_clean();
}

function nudge_row($spot)
{
    ob_start();
    Upsell::render($spot);

    return ob_get_clean();
}

before_each(function () {
    wp_set_current_user(make_user('administrator')->ID);
    $_GET['page'] = 'modify-login';
    Nudges::reset_screen();
    nudge_installed(30);
    nudge_facts();
    nudge_coverage(1, 1);
});

after_each(function () {
    delete_option(Nudges::FIRST_SEEN);
    delete_transient(Facts::CACHE);
    delete_transient(TwoFactor::COVERAGE_CACHE);
    Nudges::reset_screen();
});

t('timing: nothing in the first 7 days, then the card appears', function () {
    nudge_free();
    nudge_facts(50, 5);
    nudge_installed(6);
    eq('', nudge_html('security'), 'day 6');
    eq('', nudge_row('security'), 'rows wait too');
    Nudges::reset_screen();
    nudge_installed(7);
    contains('moment-lockouts', nudge_html('security'), 'day 7');
});

t('timing: the clock starts at the fresh install when known, else now; never autoloaded', function () {
    delete_option(Nudges::FIRST_SEEN);
    $was = get_option('authlify_fresh_install', null);
    update_option('authlify_fresh_install', time() - 9 * DAY_IN_SECONDS);
    eq(9, Nudges::days_installed(), 'counts from the install');
    if (function_exists('wp_load_alloptions')) {
        wp_cache_delete('alloptions', 'options');
        no(isset(wp_load_alloptions()[Nudges::FIRST_SEEN]), 'not autoloaded');
    }
    delete_option(Nudges::FIRST_SEEN);
    delete_option('authlify_fresh_install');
    eq(0, Nudges::days_installed(), 'an updated site counts from now');
    if (null !== $was) {
        update_option('authlify_fresh_install', $was);
    }
});

t('dismissal: per user, for good', function () {
    nudge_free();
    nudge_facts(50, 5);
    Nudges::dismiss('moment-lockouts');
    Nudges::reset_screen();
    eq('', nudge_html('security'), 'dismissed');
    $stored = get_user_meta(get_current_user_id(), Nudges::META, true);
    ok(true === $stored['moment-lockouts'], 'stored in user meta for good');

    wp_set_current_user(make_user('administrator')->ID);
    Nudges::reset_screen();
    contains('moment-lockouts', nudge_html('security'), 'another administrator still sees it');
});

t('dismissal: "later" comes back after its time, and never overrides "never"', function () {
    Nudges::dismiss('review', 30);
    ok(Nudges::is_dismissed('review'), 'hidden for now');
    $list = get_user_meta(get_current_user_id(), Nudges::META, true);
    $list['review'] = time() - 1;
    update_user_meta(get_current_user_id(), Nudges::META, $list);
    no(Nudges::is_dismissed('review'), 'back after the wait');
    Nudges::dismiss('review');
    Nudges::dismiss('review', 30);
    ok(true === get_user_meta(get_current_user_id(), Nudges::META, true)['review'], '"never" stays');
});

t('one per screen: a second card or a row does not show', function () {
    nudge_free();
    nudge_facts(50, 5);
    nudge_coverage(3, 1);
    contains('moment-2fa-coverage', nudge_html('two-factor'));
    eq('', nudge_html('security'), 'second card');
    eq('', nudge_row('two-factor'), 'row gives way');
});

t('rows: dismissible, and "Upgrade" goes to the plans', function () {
    nudge_free();
    $html = nudge_row('alerts');
    contains('data-authlify-nudge="row-alerts"', $html);
    contains('data-authlify-nudge-done', $html);
    contains('Don', $html);
    contains('utm_content=row-alerts#pricing', str_replace('&#038;', '&', $html));
    contains('#pro-alerts', $html, 'Learn more goes to the feature');
    Nudges::dismiss('row-alerts');
    Nudges::reset_screen();
    eq('', nudge_row('alerts'));
});

t('review footer: gone once the review card is answered, for that user only', function () {
    contains('A review', \Authlify\Admin\UI::footer_text('core text'), 'asked until answered');
    Nudges::dismiss('review');
    eq('', \Authlify\Admin\UI::footer_text('core text'), 'Don\'t ask again');
    wp_set_current_user(make_user('administrator')->ID);
    contains('A review', \Authlify\Admin\UI::footer_text('core text'), 'another administrator is still asked');
    Nudges::dismiss('review', 30);
    eq('', \Authlify\Admin\UI::footer_text('core text'), 'Maybe later: not now');
    update_user_meta(get_current_user_id(), Nudges::META, array('review' => time() - 60));
    contains('A review', \Authlify\Admin\UI::footer_text('core text'), 'Maybe later: back when its time comes');
});

t('security row: no second pitch after the lockouts card was dismissed, for that user only', function () {
    nudge_free();
    contains('row-security', nudge_row('security'), 'no card was ever answered: the row may show');
    Nudges::reset_screen();
    nudge_facts(50, 5);
    contains('moment-lockouts', nudge_html('security'));
    Nudges::dismiss('moment-lockouts');
    Nudges::reset_screen();
    eq('', nudge_html('security'), 'card gone');
    eq('', nudge_row('security'), 'row stays away');
    contains('row-alerts', nudge_row('alerts'), 'other screens keep their own row');
    wp_set_current_user(make_user('administrator')->ID);
    Nudges::reset_screen();
    contains('moment-lockouts', nudge_html('security'), 'another administrator still sees the card');
    eq('', nudge_row('security'), 'and the row gives way to it');
});

t('off: with Pro active by default, and with the filter', function () {
    nudge_facts(50, 5);
    if (defined('AUTHLIFY_PRO_VERSION')) {
        no(Upsell::active(), 'Pro active means no pointers');
        eq('', nudge_html('security'));
        eq('', nudge_row('security'));
    }
    add_test_filter('authlify_show_pro_pointers', '__return_false');
    eq('', nudge_html('security'), 'filter');
    eq('', nudge_row('security'), 'filter, row');
    no(Nudges::can_show('dashboard-card', false), 'filter, Dashboard card');
});

t('capability: an editor gets nothing', function () {
    nudge_free();
    nudge_facts(50, 5);
    wp_set_current_user(make_user('editor')->ID);
    eq('', nudge_html('security'));
    eq('', nudge_row('security'));
    no(Nudges::can_show('review'));
});

t('screens: nothing outside Authlify, and never an admin notice, popup or modal', function () {
    nudge_free();
    nudge_facts(50, 5);
    $_GET['page'] = 'some-other-plugin';
    eq('', nudge_html('security'));
    eq('', nudge_row('security'));
    unset($_GET['page']);
    Nudges::reset_screen();
    eq('', nudge_html('security'), 'no page at all');
    foreach (array('Nudges.php', 'Upsell.php') as $file) {
        $src = file_get_contents(AUTHLIFY_DIR . 'inc/Admin/' . $file);
        not_contains("add_action('admin_notices", $src, $file);
        not_contains("add_action('network_admin_notices", $src, $file);
        not_contains('notice-', $src, $file);
    }
});

t('facts: a card shows only while its fact is true and non-zero', function () {
    nudge_free();
    eq('', nudge_html('security'), 'no attempts');
    Nudges::reset_screen();
    nudge_facts(19, 2);
    eq('', nudge_html('security'), 'under both thresholds');
    Nudges::reset_screen();
    nudge_facts(20, 0);
    $html = nudge_html('security');
    contains('20 failed logins this week.', $html);
    not_contains('lockout', str_replace('moment-lockouts', '', $html), 'a zero is not quoted');
    Nudges::reset_screen();
    nudge_facts(0, 3);
    contains('3 lockouts this week.', nudge_html('security'));
    Nudges::reset_screen();
    nudge_facts(37, 4);
    $html = nudge_html('security');
    contains('37 failed logins and 4 lockouts this week.', $html, 'exact copy');
    contains('Pro can refuse everyone else before they guess a password.', $html);
    contains('#pro-access-rules', $html);
    contains('utm_content=moment-lockouts', str_replace('&#038;', '&', $html));
});

t('facts: live Dashboard counts win over the cache, so the card agrees with the numbers beside it', function () {
    nudge_free();
    set_settings(array('login_slug' => '')); // A site left with a login address also qualifies for the review card.
    nudge_facts(0, 0);
    contains('25 failed logins this week.', nudge_html('dashboard', array('week' => array('login_failed' => 25))));
});

t('admins without 2FA: exact copy, plural and singular; needs 2 admins and a gap', function () {
    nudge_free();
    nudge_coverage(3, 1);
    $html = nudge_html('two-factor');
    contains('2 of 3 administrators have no two-factor login.', $html);
    contains('Each one chooses to set it up on Free. Pro can require it for the role, with a grace period.', $html);
    contains('#pro-2fa-rules', $html);
    Nudges::reset_screen();
    nudge_coverage(3, 2);
    contains('1 of 3 administrators has no two-factor login.', nudge_html('two-factor'));
    Nudges::reset_screen();
    nudge_coverage(1, 0);
    eq('', nudge_html('two-factor'), 'a single admin');
    Nudges::reset_screen();
    nudge_coverage(3, 3);
    eq('', nudge_html('two-factor'), 'everyone has it');
});

t('WooCommerce: needs the store and a customer', function () {
    nudge_free();
    nudge_coverage(1, 1, 0);
    if (!class_exists('WooCommerce')) {
        eq('', nudge_html('two-factor'), 'no WooCommerce');
        Nudges::reset_screen();
        eval('class WooCommerce {}');
    }
    eq('', nudge_html('two-factor'), 'no customers');
    Nudges::reset_screen();
    nudge_coverage(1, 1, 4);
    $html = nudge_html('two-factor');
    contains('customers can', $html);
    contains('#pro-woocommerce', $html);
});

t('network: only on multisite, for a super admin', function () {
    nudge_free();
    if (!is_multisite()) {
        not_contains('moment-network', nudge_html('dashboard'));

        return;
    }
    contains('moment-network', nudge_html('dashboard'));
});

t('designer: printed hidden for the script to reveal, with 22 designs', function () {
    nudge_free();
    $html = nudge_html('designer');
    contains('hidden', $html);
    contains('22 more designs in Pro:', $html);
    contains('#pro-designs', $html);
});

t('review: 14 days AND real value, plain ask, no star gating or reward', function () {
    nudge_free();
    nudge_installed(13);
    nudge_facts(0, 0, 184, 9);
    not_contains('"review"', nudge_html('dashboard'), 'day 13');

    Nudges::reset_screen();
    nudge_installed(14);
    nudge_facts(0, 0, 0, 0);
    settings_no_hiding();
    not_contains('"review"', nudge_html('dashboard'), 'no value yet');

    Nudges::reset_screen();
    nudge_facts(0, 0, 184, 9);
    $html = nudge_html('dashboard');
    contains('data-authlify-nudge="review"', $html);
    contains('Authlify has recorded 184 failed logins and 9 lockouts on this site.', $html);
    contains('honest review', $html);
    contains('Leave a review', $html);
    contains('Maybe later', $html);
    contains('data-authlify-nudge-later="30"', $html);
    contains('ask again', $html);
    contains(Nudges::REVIEW_URL, $html);
    not_contains('notice', $html);
    foreach (array('star', 'discount', 'coupon', 'reward', 'free month') as $word) {
        not_contains($word, strtolower($html), $word);
    }
    not_contains('authlify-pro-tag', $html, 'it is not an upsell');
});

function settings_no_hiding()
{
    set_settings(array('login_slug' => ''));
}

t('review: a hidden login URL counts as value', function () {
    nudge_free();
    nudge_facts();
    set_settings(array('login_slug' => 'test-door', 'block_wp_login' => true, 'pending_slug' => ''));
    if (!\Authlify\Login\Router::is_hiding()) {
        skip('hiding is not on with these settings');
    }
    contains('keeping your login address hidden', nudge_html('dashboard'));
});

t('review: comes first, then the next card once answered', function () {
    nudge_free();
    nudge_facts(50, 5);
    contains('"review"', nudge_html('dashboard'));
    Nudges::dismiss('review', 30);
    Nudges::reset_screen();
    contains('moment-lockouts', nudge_html('dashboard'));
});

t('review: off with Pro or the filter', function () {
    nudge_facts(50, 5);
    add_test_filter('authlify_show_pro_pointers', '__return_false');
    eq('', nudge_html('dashboard'));
});

t('facts: real counts from the log, cached, and the second read adds no queries', function () {
    global $wpdb;
    delete_transient(Facts::CACHE);
    Log::add('login_failed', array('username' => 'nudge_test'));
    Log::add('lockout', array('username' => 'nudge_test'));
    $facts = Facts::get();
    ok($facts['week']['failed'] >= 1 && $facts['week']['lockouts'] >= 1, 'counted');
    ok($facts['month']['failed'] >= $facts['week']['failed'], 'month covers the week');
    eq(7, count($facts['days']), 'seven days');
    eq(wp_date('Y-m-d'), array_keys($facts['days'])[6], 'today is last');
    ok($facts['days'][wp_date('Y-m-d')] >= 1, "today's attempt is counted");

    $before = $wpdb->num_queries;
    wp_cache_delete('_transient_' . Facts::CACHE, 'options'); // Read it the way a later request would.
    Facts::get();
    ok($wpdb->num_queries - $before <= 2, 'cached: at most the transient read');
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Log::table() . ' WHERE username = %s', 'nudge_test'));
});

t('phrase: leaves out zero, formats numbers', function () {
    eq('', Facts::phrase(array('failed' => 0, 'lockouts' => 0)));
    eq('1 failed login', Facts::phrase(array('failed' => 1, 'lockouts' => 0)));
    eq('2 lockouts', Facts::phrase(array('failed' => 0, 'lockouts' => 2)));
    eq(number_format_i18n(1234) . ' failed logins and 1 lockout', Facts::phrase(array('failed' => 1234, 'lockouts' => 1)));
});

t('links: "Upgrade" goes to #pricing, with one UTM scheme', function () {
    $url = Docs::upgrade_url('row-security');
    contains('#pricing', $url);
    contains('utm_source=authlify-free', $url);
    contains('utm_medium=plugin', $url);
    contains('utm_campaign=upgrade', $url);
    contains('utm_content=row-security', $url);
    contains('#compare', Docs::upgrade_url('freevspro-header', 'compare'), 'an explicit section is kept');
});

t('links: every "Learn more" in the V3 map goes to its anchor', function () {
    $map = array(
        'two-factor' => 'pro-2fa-rules', 'pro-2fa-policies' => 'pro-2fa-rules', 'pro-email-codes' => 'pro-2fa-rules', 'pro-trusted-devices' => 'pro-2fa-rules',
        'security' => 'pro-access-rules', 'pro-access-rules' => 'pro-access-rules', 'pro-honeypot-url' => 'pro-access-rules',
        'pro-sessions' => 'pro-sessions', 'pro-password-policy' => 'pro-password-policy', 'pro-sudo' => 'pro-sudo-mode',
        'alerts' => 'pro-alerts', 'pro-alerts' => 'pro-alerts', 'pro-webhooks' => 'pro-alerts',
        'pro-magic-links' => 'pro-passwordless', 'pro-temporary-access' => 'pro-passwordless', 'pro-social-login' => 'pro-social-login',
        'pro-templates' => 'pro-designs', 'pro-emails' => 'pro-designs', 'pro-blocks' => 'pro-designs',
        'pro-woocommerce' => 'pro-woocommerce', 'pro-agency' => 'pro-agency', 'pro-rest-api' => 'pro-api-cli', 'pro-geo-db' => 'pro-insights',
    );
    foreach ($map as $key => $anchor) {
        $url = Docs::feature_url($key);
        eq($anchor, parse_url($url, PHP_URL_FRAGMENT), $key);
        contains('utm_content=' . $key, $url);
    }
});

t('links: checkout carries UTM and the right plan', function () {
    $url = ProPage::checkout_url(2, 'freevspro-plus-yearly');
    contains('utm_source=authlify-free', $url);
    contains('utm_content=freevspro-plus-yearly', $url);
    contains('download_id=39065', $url);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    eq('2', (string) $q['edd_options']['price_id']);
});

t('Free vs Pro: promises what the code does, and installs nothing', function () {
    set_current_screen('dashboard');
    ob_start();
    ProPage::render();
    $html = ob_get_clean();

    not_contains('Most popular', $html);
    not_contains('Priority email', $html);
    contains('Email (priority on Agency)', $html);
    contains('Free protects your login. Pro makes it a rule for everyone who signs in.', $html);
    contains('Renews yearly. Cancel any time.', $html);
    contains('14-day', $html);
    contains('What Pro adds', $html);
    contains('Show everything in Free too', $html);
    contains('Already bought Pro?', $html);
    contains('What happens when I stop paying?', $html);
    contains('one-click updates and support', $html);
    contains('wNZVy6q90B8', $html);
    foreach (array('personal', 'plus', 'agency') as $plan) {
        foreach (array('yearly', 'lifetime') as $term) {
            contains('utm_content=freevspro-' . $plan . '-' . $term, str_replace('&#038;', '&', $html));
        }
    }
    foreach (array('two-factor-rules', 'login-alerts', 'designs') as $shot) {
        contains('assets/admin/pro/' . $shot . '.webp', $html);
        ok(filesize(AUTHLIFY_DIR . 'assets/admin/pro/' . $shot . '.webp') < 40 * 1024, $shot . ' is small');
    }

    $src = file_get_contents(AUTHLIFY_DIR . 'inc/Admin/ProPage.php');
    foreach (array('Plugin_Upgrader', 'download_url', 'wp_remote_', 'WP_Upgrader') as $bad) {
        not_contains($bad, $src, 'guideline 8: ' . $bad);
    }
});

t('Free vs Pro: "What Pro adds" lists only rows that differ, and each of them says why', function () {
    foreach (ProPage::groups() as $group) {
        foreach ($group[2] as $row) {
            if ($row[1] === $row[2]) {
                ok(is_string($row[0]), 'a row both have is plain: ' . wp_json_encode($row[0]));
            } elseif (!in_array($row[0], array(__('Support', 'modify-login'), __('Updates', 'modify-login')), true)) {
                ok(is_array($row[0]) && '' !== $row[0][0], 'a Pro row leads with a benefit: ' . wp_json_encode($row[0]));
            }
        }
    }
});

t('Free vs Pro and readme: new Pro features are listed, with the honesty limits', function () {
    $rows = wp_json_encode(ProPage::groups(), JSON_UNESCAPED_UNICODE);
    $readme = file_get_contents(AUTHLIFY_DIR . 'readme.txt');
    foreach (array($rows, $readme) as $text) {
        contains('Facebook, LinkedIn and Discord', $text);
        contains('Easy Digital Downloads and Ultimate Member, with MemberPress and LearnDash support in beta', $text);
        contains('printable', $text);
        contains('WordPress 6.9', $text);
        not_contains('compliance', strtolower($text));
        not_contains('Facebook email', $text);
    }
    contains('Read-only status', $rows, 'free has read-only abilities, so the row must not say Free has none');
});

t('Docs: the license article has the install steps, and installs nothing', function () {
    $src = file_get_contents(AUTHLIFY_DIR . 'inc/Admin/Docs.php');
    contains('download the Authlify Pro zip file from your account', $src);
    contains('Plugins → Add New → Upload Plugin', $src);
    not_contains('Plugin_Upgrader', $src);
});

t('Free vs Pro: the FAQ matches the license code: updates are gated, features are not', function () {
    $file = dirname(AUTHLIFY_DIR) . '/authlify-pro/inc/License.php';
    if (!is_readable($file)) {
        skip('Pro is not next to this plugin');
    }
    $src = file_get_contents($file);
    contains("'package' => self::is_valid()", $src, 'the update package needs a valid license');
    $answer = ProPage::faq()['What happens when I stop paying?'];
    contains('Pro keeps working', $answer);
    contains('one-click updates and support', $answer);
    // Screens may show the license state. No code that does the protecting may read it.
    foreach (array('Security', 'TwoFactor', 'Alerts', 'Social', 'Passwordless', 'Design', 'Agency', 'Geo', 'Api', 'Mail') as $folder) {
        $dir = dirname($file) . '/' . $folder;
        if (!is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ('php' === $f->getExtension()) {
                not_contains('License::is_valid()', file_get_contents($f->getPathname()), $folder . '/' . $f->getFilename() . ' must not depend on the license');
            }
        }
    }
});

t('Dashboard card: state-aware, with price, refund and Hide; gone beside a card or once hidden', function () {
    nudge_free();
    set_current_screen('dashboard');
    nudge_coverage(3, 0);
    // The cards come first; answered, the Dashboard's own card shows (whatever is in this site's log).
    foreach (array('review', 'moment-lockouts', 'moment-network') as $id) {
        Nudges::dismiss($id);
    }
    ob_start();
    Dashboard::render();
    $html = ob_get_clean();
    contains('Make two-factor a rule, not a request', $html);
    contains('From $49 a year, or $129 once. 14-day refund.', $html);
    contains('data-authlify-nudge="dashboard-card"', $html);
    contains('Hide', $html);
    contains('See what', $html);

    Nudges::reset_screen();
    nudge_coverage(1, 1);
    ob_start();
    Dashboard::render();
    $html = ob_get_clean();
    if (!class_exists('WooCommerce')) {
        contains('Protect every account on the site', $html);
    }

    Nudges::dismiss('dashboard-card');
    Nudges::reset_screen();
    ob_start();
    Dashboard::render();
    not_contains('dashboard-card', ob_get_clean(), 'hidden');
});

t('Dashboard card: not beside a card; the only thing that shows in the quiet period', function () {
    nudge_free();
    set_current_screen('dashboard');
    nudge_facts(50, 5);
    ob_start();
    Dashboard::render();
    $html = ob_get_clean();
    contains('data-authlify-nudge="review"', $html);
    not_contains('data-authlify-nudge="dashboard-card"', $html, 'not beside a card');

    Nudges::reset_screen();
    nudge_installed(1);
    ob_start();
    Dashboard::render();
    $html = ob_get_clean();
    contains('data-authlify-nudge="dashboard-card"', $html, 'the standing card');
    not_contains('moment-lockouts', $html, 'no cards in the quiet period');
});

t('Dashboard: impact line says recorded, only when non-zero, with no Pro mention', function () {
    set_current_screen('dashboard');
    nudge_facts(0, 0, 184, 9);
    ob_start();
    Dashboard::render();
    $html = ob_get_clean();
    contains('Authlify recorded', $html);
    contains('184 failed logins and 9 lockouts', $html);
    Nudges::reset_screen();
    nudge_facts(0, 0, 0, 0);
    ob_start();
    Dashboard::render();
    not_contains('authlify-impact', ob_get_clean());
});

t('Dashboard: the chart has text for every day and needs no library', function () {
    set_current_screen('dashboard');
    set_transient(Facts::CACHE, array(
        'week' => array('failed' => 3, 'lockouts' => 0),
        'month' => array('failed' => 3, 'lockouts' => 0),
        'days' => array('2026-10-31' => 0, '2026-11-01' => 1, '2026-11-02' => 2, '2026-11-03' => 0, '2026-11-04' => 0, '2026-11-05' => 0, '2026-11-06' => 0),
    ), 300);
    ob_start();
    Dashboard::render();
    $html = ob_get_clean();
    contains('authlify-week', $html);
    contains(wp_date(get_option('date_format'), strtotime('2026-10-31 12:00:00 UTC'), new DateTimeZone('UTC')) . ': 0 failed logins', $html, 'the first day has its text');
    contains('2 failed logins', $html);
    contains('height:100%', $html);
    contains('height:50%', $html);

    Nudges::reset_screen();
    nudge_facts();
    ob_start();
    Dashboard::render();
    not_contains('authlify-week', ob_get_clean(), 'no empty chart');
});

t('AJAX: rejects unknown ids (no user-meta growth from a crafted request)', function () {
    ok(in_array('review', Nudges::ids(), true));
    no(in_array('anything-else', Nudges::ids(), true));
    foreach (array('moment-2fa-coverage', 'moment-lockouts', 'moment-woo', 'moment-network', 'moment-designer', 'row-alerts', 'row-security', 'row-two-factor', 'dashboard-card') as $id) {
        ok(in_array($id, Nudges::ids(), true), $id);
    }
});
