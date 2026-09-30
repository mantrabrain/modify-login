<?php
/**
 * Activity log.
 *
 * @package Authlify
 */

namespace Authlify\Log;

use Authlify\Net\Geo;
use Authlify\Net\Ip;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Stores login and security events in {prefix}authlify_log.
 *
 * Everything stays on the site: no third-party lookups. Rows older than the
 * retention period are removed daily, IPs can be anonymized on write, and the
 * WordPress personal-data exporter and eraser cover the table.
 */
final class Log
{
    /**
     * Event types and their labels.
     *
     * @return array
     */
    public static function events()
    {
        /**
         * Filters the known event types (key → label).
         *
         * @param array $events Events.
         * @since 3.0.0
         */
        return apply_filters('authlify_log_events', array(
            'login_success' => __('Logged in', 'modify-login'),
            'login_failed' => __('Failed login', 'modify-login'),
            'lockout' => __('Locked out', 'modify-login'),
            'unlock' => __('Unlocked', 'modify-login'),
            'denied' => __('Blocked IP', 'modify-login'),
            'captcha_failed' => __('CAPTCHA failed', 'modify-login'),
            'logout' => __('Logged out', 'modify-login'),
            'password_reset' => __('Password reset', 'modify-login'),
            'slug_changed' => __('Login URL changed', 'modify-login'),
            'log_cleared' => __('Log cleared', 'modify-login'),
        ));
    }

    /**
     * Table name.
     *
     * @return string
     */
    public static function table()
    {
        global $wpdb;

        return $wpdb->base_prefix . 'authlify_log';
    }

    /**
     * Wire up.
     */
    public static function init()
    {
        add_action('wp_login', array(__CLASS__, 'on_login'), 20, 2);
        add_action('wp_login_failed', array(__CLASS__, 'on_login_failed'), 20, 2);
        add_action('wp_logout', array(__CLASS__, 'on_logout'));
        add_action('after_password_reset', array(__CLASS__, 'on_password_reset'));
        add_action('authlify_daily', array(__CLASS__, 'prune'));
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'register_eraser'));
        add_action('admin_init', array(__CLASS__, 'privacy_policy_text'));
        add_action('authlify_lockout', array(__CLASS__, 'alert_admin_lockout'), 10, 4);
        add_action('wp_delete_site', array(__CLASS__, 'on_delete_site'));
    }

    /**
     * A deleted network site takes its log rows with it (IP addresses and
     * usernames should not outlive the site).
     *
     * @param \WP_Site $site Deleted site.
     */
    public static function on_delete_site($site)
    {
        global $wpdb;

        $blog_id = is_object($site) && isset($site->blog_id) ? (int) $site->blog_id : 0;
        if ($blog_id <= 0) {
            return;
        }

        do {
            $deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE blog_id = %d LIMIT 5000', $blog_id)); // phpcs:ignore
        } while ($deleted >= 5000);
    }

    /**
     * Email the site admin when an administrator account triggers a lockout (max once an hour).
     *
     * @param string $scope    ip or net.
     * @param string $subject  IP or CIDR.
     * @param int    $until    Lockout end.
     * @param string $username Username.
     */
    public static function alert_admin_lockout($scope, $subject, $until, $username)
    {
        /**
         * Filters whether the free admin-lockout email is sent (Authlify Pro turns it off when its own alerts cover it).
         *
         * @param bool $send Send.
         * @since 3.0.0
         */
        if (!apply_filters('authlify_admin_lockout_email', (bool) Settings::get('alert_admin_lockout', false)) || get_transient('authlify_alert_sent')) {
            return;
        }

        $user = get_user_by(is_email($username) ? 'email' : 'login', $username);
        if (!$user || !user_can($user, 'manage_options')) {
            return;
        }

        set_transient('authlify_alert_sent', 1, HOUR_IN_SECONDS);
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        wp_mail(
            get_option('admin_email'),
            /* translators: %s: site name */
            sprintf(__('[%s] Administrator login locked out', 'modify-login'), $site),
            sprintf(
                /* translators: 1: username, 2: IP or network, 3: time */
                __("Too many failed logins for the administrator account \"%1\$s\" came from %2\$s, so that address is locked out until %3\$s.\n\nIf this was you, wait or use the unlock link on the login page. If not, consider turning on two-factor login.", 'modify-login'),
                $user->user_login,
                $subject,
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), $until)
            )
        );
    }

    /**
     * Record an event.
     *
     * @param string $event   Event key.
     * @param array  $args    user_id, username, ip, context (array).
     * @return int|false Row ID, or false when logging is off or the insert failed.
     */
    public static function add($event, array $args = array())
    {
        if (!Settings::get('log_enabled', true) && !in_array($event, array('slug_changed'), true)) {
            return false;
        }

        /**
         * Short-circuits logging (Leak Check probes use this). Return true to skip.
         *
         * @param bool   $skip  Skip.
         * @param string $event Event.
         * @param array  $args  Args.
         * @since 3.0.0
         */
        if (apply_filters('authlify_pre_log', false, $event, $args)) {
            return false;
        }

        global $wpdb;

        $ip = isset($args['ip']) ? (string) $args['ip'] : Ip::client();
        if (Settings::get('log_anonymize_ip', false)) {
            $ip = Ip::anonymize($ip);
        }

        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $username = isset($args['username']) ? sanitize_text_field((string) $args['username']) : '';
        $context = isset($args['context']) && is_array($args['context']) ? $args['context'] : array();
        if (Settings::get('log_anonymize_ip', false) && isset($context['subject']) && Ip::valid((string) $context['subject'])) {
            $context['subject'] = Ip::anonymize((string) $context['subject']);
        }

        $row = array(
            'blog_id' => get_current_blog_id(),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'event' => substr(sanitize_key($event), 0, 32),
            'user_id' => isset($args['user_id']) ? (int) $args['user_id'] : 0,
            'username' => self::cut($username, 191),
            'ip' => self::cut($ip, 45),
            'country' => self::cut(isset($args['country']) ? (string) $args['country'] : Geo::country($ip), 2),
            'user_agent' => self::cut($agent, 255),
            'context' => $context ? wp_json_encode($context) : null,
        );

        $ok = $wpdb->insert(self::table(), $row, array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if (false === $ok) {
            // Surfaced in Site Health instead of failing silently.
            update_option('authlify_log_error', array('time' => time(), 'error' => $wpdb->last_error), false);

            return false;
        }

        $id = (int) $wpdb->insert_id;

        /**
         * Fires after an event is logged (alerts and add-ons listen here).
         *
         * @param string $event Event key.
         * @param array  $row   Stored row.
         * @param int    $id    Row ID.
         * @since 3.0.0
         */
        do_action('authlify_logged', $event, $row, $id);

        return $id;
    }

    /**
     * Successful login.
     *
     * @param string   $user_login Login.
     * @param \WP_User $user       User.
     */
    public static function on_login($user_login, $user)
    {
        self::add('login_success', array(
            'user_id' => $user instanceof \WP_User ? $user->ID : 0,
            'username' => $user_login,
            'context' => array('via' => self::channel()),
        ));
    }

    /**
     * Failed login.
     *
     * @param string         $username Username.
     * @param \WP_Error|null $error    Error.
     */
    public static function on_login_failed($username, $error = null)
    {
        $user = get_user_by(is_email($username) ? 'email' : 'login', $username);
        $code = $error instanceof \WP_Error ? $error->get_error_code() : '';

        // Lockout and CAPTCHA failures are logged by their own modules.
        if (in_array($code, (array) apply_filters('authlify_ignored_failure_codes', array('authlify_locked', 'authlify_captcha', 'authlify_denied', 'authlify_2fa_failed', 'authlify_social_failed'), 'log'), true)) {
            return;
        }

        self::add('login_failed', array(
            'user_id' => $user ? $user->ID : 0,
            'username' => $username,
            'context' => array_filter(array('reason' => $code, 'via' => self::channel())),
        ));
    }

    /**
     * Logout.
     *
     * @param int $user_id User ID.
     */
    public static function on_logout($user_id = 0)
    {
        $user = get_userdata($user_id);
        self::add('logout', array('user_id' => (int) $user_id, 'username' => $user ? $user->user_login : ''));
    }

    /**
     * Password reset.
     *
     * @param \WP_User $user User.
     */
    public static function on_password_reset($user)
    {
        self::add('password_reset', array('user_id' => $user->ID, 'username' => $user->user_login));
    }

    /**
     * How the login arrived: form, xmlrpc, rest, woocommerce.
     *
     * @return string
     */
    public static function channel()
    {
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return 'xmlrpc';
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }
        if (did_action('login_init')) {
            return 'form';
        }
        if (did_action('woocommerce_init') && isset($_POST['woocommerce-login-nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return 'woocommerce';
        }

        return 'other';
    }

    /**
     * The site whose rows this request may see, or 0 for every site.
     *
     * The log table is shared by the whole network. When Authlify is activated
     * per site, each site's admins see (and clear) only their own rows; when it
     * is network-activated the network admin sees everything.
     *
     * @return int Blog ID, or 0 for no filter.
     */
    public static function scope_blog()
    {
        if (!is_multisite() || Settings::is_network()) {
            $blog = 0;
        } else {
            $blog = get_current_blog_id();
        }

        /**
         * Filters the site the activity log is limited to (0 = every site).
         *
         * @param int $blog Blog ID or 0.
         * @since 3.0.0
         */
        return max(0, (int) apply_filters('authlify_log_scope_blog', $blog));
    }

    /**
     * Query rows.
     *
     * @param array $args event, user_id, ip, search, from, to (Y-m-d), per_page, page,
     *                    before_id (keyset paging: only rows with a smaller ID),
     *                    count (false skips the COUNT query; total is then -1),
     *                    blog_id (null = scope_blog(), 0 = every site).
     * @return array { rows: object[], total: int }
     */
    public static function query(array $args = array())
    {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'event' => '',
            'user_id' => 0,
            'ip' => '',
            'search' => '',
            'from' => '',
            'to' => '',
            'per_page' => 50,
            'page' => 1,
            'before_id' => 0,
            'count' => true,
            'blog_id' => null,
        ));

        $where = array('1=1');
        $params = array();

        $blog = null === $args['blog_id'] ? self::scope_blog() : (int) $args['blog_id'];
        if ($blog > 0) {
            $where[] = 'blog_id = %d';
            $params[] = $blog;
        }
        if ('' !== $args['event']) {
            $events = array_filter(array_map('sanitize_key', (array) $args['event']));
            if ($events) {
                $where[] = 'event IN (' . implode(',', array_fill(0, count($events), '%s')) . ')';
                $params = array_merge($params, $events);
            }
        }
        if ($args['user_id']) {
            $where[] = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if ('' !== $args['ip']) {
            $where[] = 'ip = %s';
            $params[] = $args['ip'];
        }
        if ('' !== $args['search']) {
            $search = (string) $args['search'];
            if (Ip::valid($search)) {
                // A full address uses the ip index.
                $where[] = 'ip = %s';
                $params[] = $search;
            } else {
                $like = '%' . $wpdb->esc_like($search) . '%';
                // Partial addresses match from the start (index-friendly); names anywhere.
                $where[] = '(username LIKE %s OR ip LIKE %s)';
                $params[] = $like;
                $params[] = $wpdb->esc_like($search) . '%';
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'])) {
            $where[] = 'created_at >= %s';
            $params[] = get_gmt_from_date($args['from'] . ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'])) {
            $where[] = 'created_at <= %s';
            $params[] = get_gmt_from_date($args['to'] . ' 23:59:59');
        }

        $count_where = implode(' AND ', $where);
        $count_params = $params;

        if ((int) $args['before_id'] > 0) {
            $where[] = 'id < %d';
            $params[] = (int) $args['before_id'];
        }

        $sql_where = implode(' AND ', $where);
        $table = self::table();
        $per_page = max(1, min(1000, (int) $args['per_page']));
        $offset = (int) $args['before_id'] > 0 ? 0 : max(0, ((int) $args['page'] - 1) * $per_page);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $total = -1;
        if ($args['count']) {
            $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$count_where}";
            $total = (int) $wpdb->get_var($count_params ? $wpdb->prepare($count_sql, $count_params) : $count_sql);
        }
        $rows_sql = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, array_merge($params, array($per_page, $offset))));
        // phpcs:enable

        return array('rows' => $rows ? $rows : array(), 'total' => $total);
    }

    /**
     * Counts per event since a time.
     *
     * @param int $since Unix time.
     * @return array event => count
     */
    public static function counts($since)
    {
        global $wpdb;
        $table = self::table();
        $blog = self::scope_blog();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        if ($blog > 0) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT event, COUNT(*) AS n FROM {$table} WHERE created_at >= %s AND blog_id = %d GROUP BY event", gmdate('Y-m-d H:i:s', $since), $blog));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT event, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY event", gmdate('Y-m-d H:i:s', $since)));
        }
        // phpcs:enable

        $out = array();
        foreach ((array) $rows as $row) {
            $out[$row->event] = (int) $row->n;
        }

        return $out;
    }

    /**
     * Delete rows older than the retention period.
     */
    public static function prune()
    {
        global $wpdb;

        $days = (int) Settings::get('log_retention_days', 90);
        if ($days <= 0) {
            return;
        }

        $table = self::table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $blog = self::scope_blog();

        // Delete in batches so a huge table never locks for long. On a network
        // where each site runs Authlify itself, each site prunes its own rows
        // with its own retention setting.
        do {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            if ($blog > 0) {
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s AND blog_id = %d LIMIT 5000", $cutoff, $blog));
            } else {
                $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", $cutoff));
            }
            // phpcs:enable
        } while ($deleted >= 5000);
    }

    /**
     * Empty the log (this site's rows only when each site runs Authlify itself).
     */
    public static function clear()
    {
        global $wpdb;
        $blog = self::scope_blog();

        if ($blog > 0) {
            do {
                $deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE blog_id = %d LIMIT 5000', $blog)); // phpcs:ignore
            } while ($deleted >= 5000);
        } else {
            $wpdb->query('TRUNCATE TABLE ' . self::table()); // phpcs:ignore
        }

        // Record who emptied the log.
        self::add('log_cleared', array('user_id' => get_current_user_id(), 'username' => wp_get_current_user()->user_login));
    }

    /**
     * Register the personal-data exporter.
     *
     * @param array $exporters Exporters.
     * @return array
     */
    public static function register_exporter($exporters)
    {
        $exporters['authlify-log'] = array(
            'exporter_friendly_name' => __('Authlify login activity', 'modify-login'),
            'callback' => array(__CLASS__, 'export_personal_data'),
        );

        return $exporters;
    }

    /**
     * Register the personal-data eraser.
     *
     * @param array $erasers Erasers.
     * @return array
     */
    public static function register_eraser($erasers)
    {
        $erasers['authlify-log'] = array(
            'eraser_friendly_name' => __('Authlify login activity', 'modify-login'),
            'callback' => array(__CLASS__, 'erase_personal_data'),
        );

        return $erasers;
    }

    /**
     * Export a user's log rows.
     *
     * @param string $email Email.
     * @param int    $page  Page.
     * @return array
     */
    public static function export_personal_data($email, $page = 1)
    {
        $user = get_user_by('email', $email);
        $items = array();
        $done = true;

        if ($user) {
            $result = self::query(array('user_id' => $user->ID, 'per_page' => 100, 'page' => $page, 'blog_id' => 0));
            $events = self::events();
            foreach ($result['rows'] as $row) {
                $items[] = array(
                    'group_id' => 'authlify-log',
                    'group_label' => __('Login activity', 'modify-login'),
                    'item_id' => 'authlify-log-' . $row->id,
                    'data' => array(
                        array('name' => __('Date', 'modify-login'), 'value' => get_date_from_gmt($row->created_at)),
                        array('name' => __('Event', 'modify-login'), 'value' => isset($events[$row->event]) ? $events[$row->event] : $row->event),
                        array('name' => __('IP address', 'modify-login'), 'value' => $row->ip),
                        array('name' => __('Country', 'modify-login'), 'value' => $row->country),
                        array('name' => __('Browser', 'modify-login'), 'value' => $row->user_agent),
                    ),
                );
            }
            $done = $page * 100 >= $result['total'];
        }

        return array('data' => $items, 'done' => $done);
    }

    /**
     * Erase a user's log rows.
     *
     * @param string $email Email.
     * @param int    $page  Page.
     * @return array
     */
    public static function erase_personal_data($email, $page = 1)
    {
        global $wpdb;
        $user = get_user_by('email', $email);
        $removed = 0;

        if ($user) {
            $removed = (int) $wpdb->delete(self::table(), array('user_id' => $user->ID), array('%d')); // phpcs:ignore
        }

        return array('items_removed' => $removed, 'items_retained' => false, 'messages' => array(), 'done' => true);
    }

    /**
     * Suggested privacy-policy text.
     */
    public static function privacy_policy_text()
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $days = (int) Settings::get('log_retention_days', 90);
        $kept = $days > 0
            /* translators: %d: number of days */
            ? sprintf(esc_html__('The records are kept for %d days and are not shared with anyone.', 'modify-login'), $days)
            : esc_html__('The records are kept until an administrator deletes them and are not shared with anyone.', 'modify-login');
        $text = '<p>' . esc_html__('When you log in, or try to, this site records the date, your IP address, your browser, the country your connection comes from and whether the attempt succeeded. This is used to protect accounts from password-guessing attacks.', 'modify-login') . ' ' . $kept . '</p>';

        wp_add_privacy_policy_content('Authlify', wp_kses_post($text));
    }

    /**
     * Truncate a string to a byte-safe length.
     *
     * @param string $value  Value.
     * @param int    $length Max characters.
     * @return string
     */
    private static function cut($value, $length)
    {
        $value = (string) $value;

        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
