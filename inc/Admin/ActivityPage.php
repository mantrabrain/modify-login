<?php
/**
 * Activity log screen.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Log\Log;
use Authlify\Plugin;

defined('ABSPATH') || exit;

/**
 * Filterable log with CSV export, plus log settings.
 */
final class ActivityPage
{
    /**
     * Wire up.
     */
    public static function boot()
    {
        add_action('admin_post_authlify_export_log', array(__CLASS__, 'export'));
        add_action('admin_post_authlify_clear_log', array(__CLASS__, 'clear'));
    }

    /**
     * Filters from the request.
     *
     * @return array
     */
    private static function filters()
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $get = function ($key) {
            return isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
        };

        return array(
            'event' => $get('event'),
            'search' => $get('s'),
            'from' => $get('from'),
            'to' => $get('to'),
            'page' => max(1, (int) $get('paged')),
            'per_page' => 50,
        );
        // phpcs:enable
    }

    /**
     * Sections (key => array( label[, callable], optional icon, group, keywords )), including add-on tabs.
     *
     * @return array
     */
    public static function sections()
    {
        $sections = array('log' => array(__('Log', 'modify-login'), 'icon' => 'list'));
        foreach ((array) apply_filters('authlify_activity_tabs', array()) as $key => $def) {
            $sections[$key] = (array) $def;
        }
        $sections['settings'] = array(__('Settings', 'modify-login'), 'icon' => 'settings');

        return $sections;
    }

    /**
     * Tab labels (key => label), including add-on tabs.
     *
     * @return array
     */
    public static function tab_labels()
    {
        return array_map(function ($def) {
            return $def[0];
        }, self::sections());
    }

    /**
     * Render.
     */
    public static function render()
    {
        /**
         * Filters the Activity tabs: key => array( label, callable ). "log" and "settings" are built in.
         *
         * @param array $extra Extra tabs.
         * @since 3.0.0
         */
        $extra = (array) apply_filters('authlify_activity_tabs', array());
        $tabs = self::tab_labels();
        $tab = UI::current_tab($tabs);
        ?>
        <div class="wrap authlify-page">
            <?php UI::header(__('Activity', 'modify-login'), __('Every login, failed attempt and lockout on this site. Stored only here, never sent anywhere.', 'modify-login'), array(), 'activity-log'); ?>
            <?php UI::tabs($tabs, $tab, 'activity'); ?>
            <?php
            if (isset($extra[$tab])) {
                call_user_func($extra[$tab][1]);
            } elseif ('settings' === $tab) {
                self::render_settings();
            } else {
                self::render_log();
            }

            /**
             * Fires at the end of the Activity page (attribution notices, extra panels).
             *
             * @param string $tab Current tab.
             * @since 3.0.0
             */
            do_action('authlify_activity_after', $tab);
            ?>
        </div>
        <?php
    }

    /**
     * Log table.
     */
    private static function render_log()
    {
        $filters = self::filters();
        $result = Log::query($filters);
        $events = Log::events();
        $total = (int) $result['total'];

        // One query for every user on the page instead of one per row.
        $user_ids = array_unique(array_filter(array_map('intval', wp_list_pluck($result['rows'], 'user_id'))));
        if ($user_ids) {
            cache_users($user_ids);
        }
        $pages = (int) ceil($total / $filters['per_page']);
        $filtered = '' !== $filters['event'] || '' !== $filters['search'] || '' !== $filters['from'] || '' !== $filters['to'];

        // Only show the Country column when at least one row has a country.
        $has_country = false;
        foreach ($result['rows'] as $row) {
            $context = $row->context ? json_decode($row->context, true) : array();
            if ($row->country || !empty($context['country_name'])) {
                $has_country = true;
                break;
            }
        }
        ?>
        <form method="get" class="authlify-toolbar" role="search">
            <input type="hidden" name="page" value="modify-login-logs">
            <label class="screen-reader-text" for="authlify-event"><?php esc_html_e('Event', 'modify-login'); ?></label>
            <select name="event" id="authlify-event">
                <option value=""><?php esc_html_e('All events', 'modify-login'); ?></option>
                <?php foreach ($events as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['event'], $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <label class="screen-reader-text" for="authlify-from"><?php esc_html_e('From', 'modify-login'); ?></label>
            <input type="date" id="authlify-from" name="from" value="<?php echo esc_attr($filters['from']); ?>" title="<?php esc_attr_e('From', 'modify-login'); ?>">
            <label class="screen-reader-text" for="authlify-to"><?php esc_html_e('To', 'modify-login'); ?></label>
            <input type="date" id="authlify-to" name="to" value="<?php echo esc_attr($filters['to']); ?>" title="<?php esc_attr_e('To', 'modify-login'); ?>">
            <label class="screen-reader-text" for="authlify-s"><?php esc_html_e('Search username or IP', 'modify-login'); ?></label>
            <input type="search" id="authlify-s" name="s" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Username or IP', 'modify-login'); ?>">
            <button class="button"><?php esc_html_e('Filter', 'modify-login'); ?></button>
            <?php if ($filtered) : ?>
                <a class="button-link" href="<?php echo esc_url(Menu::url('activity')); ?>"><?php esc_html_e('Clear', 'modify-login'); ?></a>
            <?php endif; ?>
            <span class="authlify-toolbar__spacer"></span>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_filter(array('action' => 'authlify_export_log', 'event' => $filters['event'], 's' => $filters['search'], 'from' => $filters['from'], 'to' => $filters['to'])), admin_url('admin-post.php')), 'authlify_export_log')); ?>"><?php esc_html_e('Export CSV', 'modify-login'); ?></a>
        </form>

        <?php if (!$result['rows']) : ?>
            <div class="authlify-panel"><div class="authlify-panel__body">
                <p class="authlify-empty"><?php echo esc_html($filtered
                    ? __('Nothing matches these filters.', 'modify-login')
                    : __('Logins, failed attempts and lockouts will appear here as they happen.', 'modify-login')); ?></p>
            </div></div>
            <?php return; ?>
        <?php endif; ?>

        <div class="authlify-table-wrap">
        <table class="widefat striped authlify-table authlify-log">
            <thead><tr>
                <th scope="col"><?php esc_html_e('When', 'modify-login'); ?></th>
                <th scope="col"><?php esc_html_e('Event', 'modify-login'); ?></th>
                <th scope="col"><?php esc_html_e('User', 'modify-login'); ?></th>
                <th scope="col"><?php esc_html_e('IP address', 'modify-login'); ?></th>
                <?php if ($has_country) : ?>
                    <th scope="col"><?php esc_html_e('Country', 'modify-login'); ?></th>
                <?php endif; ?>
                <th scope="col"><?php esc_html_e('Details', 'modify-login'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row) : ?>
                <?php
                $context = $row->context ? json_decode($row->context, true) : array();
                $time = strtotime($row->created_at . ' UTC');
                ?>
                <tr>
                    <td class="authlify-log__when" title="<?php echo esc_attr(get_date_from_gmt($row->created_at, get_option('date_format') . ' ' . get_option('time_format'))); ?>"><?php echo esc_html(self::when($time)); ?></td>
                    <td><?php echo UI::pill(isset($events[$row->event]) ? $events[$row->event] : $row->event, self::tone($row->event)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    <td class="authlify-log__user">
                        <?php if ($row->user_id && ($user = get_userdata((int) $row->user_id))) : ?>
                            <a href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>"><?php echo esc_html($user->user_login); ?></a>
                        <?php elseif ('' !== (string) $row->username) : ?>
                            <?php echo esc_html($row->username); ?>
                        <?php else : ?>
                            <span class="authlify-table__sub" aria-hidden="true">—</span>
                        <?php endif; ?>
                    </td>
                    <td><a class="authlify-log__ip" href="<?php echo esc_url(add_query_arg(array('page' => 'modify-login-logs', 's' => $row->ip), admin_url('admin.php'))); ?>" title="<?php esc_attr_e('Show everything from this address', 'modify-login'); ?>"><code><?php echo esc_html($row->ip); ?></code></a></td>
                    <?php if ($has_country) : ?>
                        <td><?php echo esc_html($row->country ? $row->country : (isset($context['country_name']) ? $context['country_name'] : '')); ?></td>
                    <?php endif; ?>
                    <td class="authlify-table__sub"><?php echo esc_html(self::describe($row->event, (array) $context)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="authlify-pager">
            <span><?php
                $first = ($filters['page'] - 1) * $filters['per_page'] + 1;
                printf(
                    /* translators: 1: first row, 2: last row, 3: total rows */
                    esc_html__('%1$s–%2$s of %3$s entries', 'modify-login'),
                    esc_html(number_format_i18n($first)),
                    esc_html(number_format_i18n($first + count($result['rows']) - 1)),
                    esc_html(number_format_i18n($total))
                );
            ?></span>
            <?php if ($pages > 1) : ?>
                <span class="authlify-pager__links">
                    <?php if ($filters['page'] > 1) : ?>
                        <a class="button button-small" href="<?php echo esc_url(add_query_arg('paged', $filters['page'] - 1)); ?>"><?php esc_html_e('Newer', 'modify-login'); ?></a>
                    <?php endif; ?>
                    <span class="authlify-pager__page"><?php
                        /* translators: 1: current page, 2: number of pages */
                        printf(esc_html__('Page %1$s of %2$s', 'modify-login'), esc_html(number_format_i18n($filters['page'])), esc_html(number_format_i18n($pages)));
                    ?></span>
                    <?php if ($filters['page'] < $pages) : ?>
                        <a class="button button-small" href="<?php echo esc_url(add_query_arg('paged', $filters['page'] + 1)); ?>"><?php esc_html_e('Older', 'modify-login'); ?></a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Short time: "5 min ago" today, otherwise a compact date and time.
     *
     * @param int $time Unix time.
     * @return string
     */
    private static function when($time)
    {
        $diff = time() - (int) $time;
        if ($diff >= 0 && $diff < 60) {
            return __('Just now', 'modify-login');
        }
        if ($diff >= 0 && $diff < DAY_IN_SECONDS) {
            /* translators: %s: human time difference, e.g. "5 mins" */
            return sprintf(__('%s ago', 'modify-login'), human_time_diff((int) $time));
        }

        /* translators: date format for the activity log, see https://www.php.net/date */
        $format = gmdate('Y', (int) $time) === gmdate('Y') ? __('M j, g:i a', 'modify-login') : __('M j Y, g:i a', 'modify-login');

        return wp_date($format, (int) $time);
    }

    /**
     * Log settings.
     */
    private static function render_settings()
    {
        UI::form_start('activity', 'settings');
        UI::panel_start(__('What to keep', 'modify-login'), __('The log stays in your database. Nothing is sent anywhere.', 'modify-login'));
        UI::toggle_row('log_enabled', __('Record logins and failed attempts', 'modify-login'), __('Lockouts still work when this is off.', 'modify-login'), __('Activity log', 'modify-login'));
        UI::input_row('log_retention_days', __('Keep entries for', 'modify-login'), __('Older entries are deleted daily. 0 keeps them forever.', 'modify-login') . ' ' . UI::learn_more('privacy-retention'), array('type' => 'number', 'min' => 0, 'max' => 3650, 'suffix' => __('days', 'modify-login')));
        UI::toggle_row('log_anonymize_ip', __('Store IP addresses without their last part (e.g. 203.0.113.0)', 'modify-login'), __('More private, but you can no longer tell visitors apart in the log. Lockouts still use full addresses.', 'modify-login'), __('Anonymize IPs', 'modify-login'));
        UI::choice_row('geo_source', __('Country', 'modify-login'), apply_filters('authlify_geo_sources', array(
            'off' => __('Do not record', 'modify-login'),
            'headers' => __('Use the country Cloudflare provides (needs "Through Cloudflare" under Security → Brute force)', 'modify-login'),
        )), __('Never looked up through third-party services.', 'modify-login'), true);
        UI::panel_end();

        UI::panel_start(__('Alerts', 'modify-login'));
        UI::toggle_row('alert_admin_lockout', __('Email me when an administrator account triggers a lockout', 'modify-login'), __('At most one email per hour.', 'modify-login'), __('Lockout email', 'modify-login'));
        Upsell::render('alerts');
        UI::panel_end();
        UI::form_end();

        UI::panel_start(__('Clear the log', 'modify-login'));
        ?>
        <div class="authlify-row">
            <div class="authlify-row__main">
                <strong><?php esc_html_e('Delete all entries', 'modify-login'); ?></strong>
                <span class="authlify-sublabel"><?php esc_html_e('Removes every entry now. This cannot be undone; export a CSV first if you need a copy.', 'modify-login'); ?></span>
            </div>
            <a class="button button-link-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=authlify_clear_log'), 'authlify_clear_log')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete every entry in the activity log?', 'modify-login')); ?>');"><?php esc_html_e('Delete all entries', 'modify-login'); ?></a>
        </div>
        <?php
        UI::panel_end();
    }

    /**
     * Pill colour for an event.
     *
     * @param string $event Event.
     * @return string
     */
    public static function tone($event)
    {
        if (in_array($event, array('login_success', 'unlock', 'passkey_added', 'twofa_enabled'), true)) {
            return 'ok';
        }
        if (in_array($event, array('lockout', 'denied', 'captcha_failed', 'twofa_failed'), true)) {
            return 'error';
        }
        if (in_array($event, array('login_failed', 'hidden_probe'), true)) {
            return 'warning';
        }

        return 'info';
    }

    /**
     * One-line description from context.
     *
     * @param string $event   Event.
     * @param array  $context Context.
     * @return string
     */
    private static function describe($event, array $context)
    {
        $reasons = array(
            'incorrect_password' => __('Wrong password', 'modify-login'),
            'invalid_username' => __('Unknown username', 'modify-login'),
            'invalid_email' => __('Unknown email', 'modify-login'),
        );
        $parts = array();

        if (!empty($context['reason'])) {
            $parts[] = isset($reasons[$context['reason']]) ? $reasons[$context['reason']] : $context['reason'];
        }
        if (!empty($context['via']) && 'form' !== $context['via']) {
            $parts[] = sprintf(__('via %s', 'modify-login'), strtoupper($context['via']));
        }
        if ('lockout' === $event && !empty($context['minutes'])) {
            $parts[] = sprintf(_n('%d minute', '%d minutes', (int) $context['minutes'], 'modify-login'), (int) $context['minutes']);
        }
        if ('slug_changed' === $event) {
            $parts[] = sprintf('%s → %s', $context['from'] ?: 'wp-login.php', $context['to'] ?: 'wp-login.php');
        }
        if (!empty($context['method'])) {
            $methods = array(
                'totp' => __('Authenticator app', 'modify-login'),
                'backup' => __('Backup code', 'modify-login'),
                'passkey' => __('Passkey', 'modify-login'),
                'email' => __('Email code', 'modify-login'),
            );
            $parts[] = isset($methods[$context['method']]) ? $methods[$context['method']] : $context['method'];
        }

        /**
         * Filters the details column.
         *
         * @param string[] $parts   Parts.
         * @param string   $event   Event.
         * @param array    $context Context.
         * @since 3.0.0
         */
        return implode(' · ', apply_filters('authlify_log_details', $parts, $event, $context));
    }

    /**
     * CSV export.
     */
    public static function export()
    {
        if (!current_user_can(Plugin::cap()) || !check_admin_referer('authlify_export_log')) {
            wp_die(esc_html__('You are not allowed to do that.', 'modify-login'), 403);
        }

        $filters = self::filters();
        $filters['per_page'] = 1000;
        $filters['count'] = false;
        $events = Log::events();

        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=authlify-activity-' . gmdate('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, array('time_utc', 'event', 'user_id', 'username', 'ip', 'country', 'user_agent', 'details'), ',', '"', '\\');

        // Keyset paging: each batch starts below the last ID, so the cost per
        // batch stays constant however large the log is. No row cap.
        $before = 0;
        do {
            $filters['before_id'] = $before;
            $result = Log::query($filters);
            foreach ($result['rows'] as $row) {
                fputcsv($out, array_map(array(__CLASS__, 'csv_safe'), array($row->created_at, isset($events[$row->event]) ? $events[$row->event] : $row->event, $row->user_id, $row->username, $row->ip, $row->country, $row->user_agent, (string) $row->context)), ',', '"', '\\');
                $before = (int) $row->id;
            }
            if (function_exists('flush')) {
                flush();
            }
        } while (count($result['rows']) === $filters['per_page'] && $before > 1);

        fclose($out);
        exit;
    }

    /**
     * Stop spreadsheet formula injection.
     *
     * @param mixed $value Value.
     * @return string
     */
    public static function csv_safe($value)
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    /**
     * Clear the log.
     */
    public static function clear()
    {
        if (!current_user_can(Plugin::cap()) || !check_admin_referer('authlify_clear_log')) {
            wp_die(esc_html__('You are not allowed to do that.', 'modify-login'), 403);
        }

        Log::clear();
        wp_safe_redirect(add_query_arg('authlify_notice', 'log_cleared', Menu::url('activity')));
        exit;
    }
}
