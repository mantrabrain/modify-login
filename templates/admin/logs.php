<?php
/**
 * Admin logs template
 *
 * @package ModifyLogin
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check and create tables if needed
require_once MODIFY_LOGIN_PATH . 'includes/class-modify-login-install.php';
\ModifyLogin\Core\Modify_Login_Install::maybe_create_tables();

global $wpdb;

// Get filter values
$start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

// Build the query
$where_clauses = array();
$query_params = array();

if (!empty($start_date)) {
    $where_clauses[] = 'DATE(created_at) >= %s';
    $query_params[] = $start_date;
}

if (!empty($end_date)) {
    $where_clauses[] = 'DATE(created_at) <= %s';
    $query_params[] = $end_date;
}

if (!empty($status)) {
    $where_clauses[] = 'status = %s';
    $query_params[] = $status;
}

// Base query for counting
$count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}modify_login_logs";
$status_query = "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}modify_login_logs GROUP BY status";

// Add where clauses if any
if (!empty($where_clauses)) {
    $where_sql = ' WHERE ' . implode(' AND ', $where_clauses);
    $count_query .= $where_sql;
    $status_query .= $where_sql;
}

// Get total counts
$total_logs = !empty($query_params) ? 
    $wpdb->get_var($wpdb->prepare($count_query, $query_params)) : 
    $wpdb->get_var($count_query);

// Get status counts
$status_counts = array(
    'success' => 0,
    'failed' => 0
);

$status_results = !empty($query_params) ? 
    $wpdb->get_results($wpdb->prepare($status_query, $query_params)) : 
    $wpdb->get_results($status_query);

foreach ($status_results as $result) {
    $status_counts[$result->status] = (int) $result->count;
}

$successful_logins = $status_counts['success'];
$failed_logins = $status_counts['failed'];

// Pagination settings
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;
$total_pages = ceil($total_logs / $per_page);

// Get logs with pagination
$logs_query = "SELECT l.*, u.user_login, u.user_email 
               FROM {$wpdb->prefix}modify_login_logs l 
               LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID";

if (!empty($where_clauses)) {
    $logs_query .= $where_sql;
}

$logs_query .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
$query_params[] = $per_page;
$query_params[] = $offset;

$logs = $wpdb->get_results($wpdb->prepare($logs_query, $query_params));
?>

<div class="modify-login-settings">
    <div class="modify-login-container">
        <div class="modify-login-header">
            <h1><?php esc_html_e('Login Logs', 'modify-login'); ?></h1>
            <p class="description"><?php esc_html_e('View and filter login attempts to your site.', 'modify-login'); ?></p>
        </div>

        <div class="modify-login-stats">
            <div class="modify-login-stat-card">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
                <div class="stat-content">
                    <h3><?php echo esc_html($total_logs); ?></h3>
                    <p><?php esc_html_e('Total Logs', 'modify-login'); ?></p>
                </div>
            </div>

            <div class="modify-login-stat-card">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="stat-content">
                    <h3><?php echo esc_html($successful_logins); ?></h3>
                    <p><?php esc_html_e('Successful Logins', 'modify-login'); ?></p>
                </div>
            </div>

            <div class="modify-login-stat-card">
                <div class="stat-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <div class="stat-content">
                    <h3><?php echo esc_html($failed_logins); ?></h3>
                    <p><?php esc_html_e('Failed Logins', 'modify-login'); ?></p>
                </div>
            </div>
        </div>

        <div class="modify-login-filters">
            <form method="get" class="modify-login-filter-form">
                <input type="hidden" name="page" value="modify-login-logs">
                <?php wp_nonce_field('modify_login_logs_filter', 'modify_login_logs_nonce'); ?>

                <div class="filter-group">
                    <label for="start_date"><?php esc_html_e('Start Date', 'modify-login'); ?></label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                </div>

                <div class="filter-group">
                    <label for="end_date"><?php esc_html_e('End Date', 'modify-login'); ?></label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                </div>

                <div class="filter-group">
                    <label for="status"><?php esc_html_e('Status', 'modify-login'); ?></label>
                    <select id="status" name="status">
                        <option value=""><?php esc_html_e('All', 'modify-login'); ?></option>
                        <option value="success" <?php selected($status, 'success'); ?>><?php esc_html_e('Success', 'modify-login'); ?></option>
                        <option value="failed" <?php selected($status, 'failed'); ?>><?php esc_html_e('Failed', 'modify-login'); ?></option>
                    </select>
                </div>

                <button type="submit" class="filter-submit">
                    <?php esc_html_e('Filter', 'modify-login'); ?>
                </button>
            </form>
        </div>

        <div class="modify-login-table-container">
            <?php if (!empty($logs)) : ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date/Time', 'modify-login'); ?></th>
                            <th><?php esc_html_e('Username', 'modify-login'); ?></th>
                            <th><?php esc_html_e('IP Address', 'modify-login'); ?></th>
                            <th><?php esc_html_e('Location', 'modify-login'); ?></th>
                            <th><?php esc_html_e('Status', 'modify-login'); ?></th>
                            <th><?php esc_html_e('User Agent', 'modify-login'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td data-label="<?php esc_attr_e('Date/Time', 'modify-login'); ?>">
                                    <?php echo esc_html(get_date_from_gmt($log->created_at)); ?>
                                </td>
                                <td data-label="<?php esc_attr_e('Username', 'modify-login'); ?>">
                                    <?php
                                    if ($log->user_login) {
                                        echo esc_html($log->user_login);
                                    } elseif (!empty($log->attempted_username)) {
                                        echo '<span class="attempted-username">' . esc_html($log->attempted_username) . '</span>';
                                    } else {
                                        echo '<em>' . esc_html__('Unknown', 'modify-login') . '</em>';
                                    }
                                    ?>
                                </td>
                                <td data-label="<?php esc_attr_e('IP Address', 'modify-login'); ?>">
                                    <?php echo esc_html($log->ip_address); ?>
                                </td>
                                <td data-label="<?php esc_attr_e('Location', 'modify-login'); ?>">
                                    <?php
                                    if (!empty($log->country)) {
                                        $country_code = strtolower($log->country);
                                        echo '<div class="location">';
                                        echo '<span class="country-flag" style="background-image: url(https://flagcdn.com/16x12/' . esc_attr($country_code) . '.png)"></span>';
                                        echo '<div class="location-text">';
                                        echo '<span class="country-name">' . esc_html($log->country) . '</span>';
                                        if (!empty($log->city)) {
                                            echo '<span class="city-name">' . esc_html($log->city) . '</span>';
                                        }
                                        echo '</div>';
                                        echo '</div>';
                                    } else {
                                        echo '<em>' . esc_html__('Unknown', 'modify-login') . '</em>';
                                    }
                                    ?>
                                </td>
                                <td data-label="<?php esc_attr_e('Status', 'modify-login'); ?>">
                                    <span class="status-badge status-<?php echo esc_attr($log->status); ?>">
                                        <?php if ($log->status === 'success') : ?>
                                            <svg class="status-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                            </svg>
                                            <?php esc_html_e('Success', 'modify-login'); ?>
                                        <?php else : ?>
                                            <svg class="status-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                            </svg>
                                            <?php esc_html_e('Failed', 'modify-login'); ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td data-label="<?php esc_attr_e('User Agent', 'modify-login'); ?>">
                                    <span class="user-agent"><?php echo esc_html($log->user_agent); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php
                                printf(
                                    /* translators: %s: Number of items. */
                                    _n('%s item', '%s items', $total_logs, 'modify-login'),
                                    number_format_i18n($total_logs)
                                );
                                ?>
                            </span>
                            <span class="pagination-links">
                                <?php
                                echo paginate_links(array(
                                    'base' => add_query_arg('paged', '%#%'),
                                    'format' => '',
                                    'prev_text' => __('&laquo;'),
                                    'next_text' => __('&raquo;'),
                                    'total' => $total_pages,
                                    'current' => $current_page
                                ));
                                ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div class="modify-login-empty-state">
                    <div class="empty-state-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                        </svg>
                    </div>
                    <h3><?php esc_html_e('No Logs Found', 'modify-login'); ?></h3>
                    <p><?php esc_html_e('No login attempts match your current filters.', 'modify-login'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div> 