<div class="modify-login-table-container">
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Date/Time', 'modify-login'); ?></th>
                <th><?php esc_html_e('Username', 'modify-login'); ?></th>
                <th><?php esc_html_e('IP Address', 'modify-login'); ?></th>
                <th><?php esc_html_e('Status', 'modify-login'); ?></th>
                <th><?php esc_html_e('User Agent', 'modify-login'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log) : ?>
                <tr>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->timestamp))); ?></td>
                    <td>
                        <?php
                        if ($log->user_id > 0) {
                            $user = get_user_by('id', $log->user_id);
                            if ($user) {
                                echo esc_html($user->user_login);
                            } else {
                                echo esc_html__('User not found', 'modify-login');
                            }
                        } else {
                            echo esc_html($log->attempted_username);
                        }
                        ?>
                    </td>
                    <td><?php echo esc_html($log->ip_address); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo esc_attr($log->status); ?>">
                            <?php echo esc_html(ucfirst($log->status)); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($log->user_agent); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div> 