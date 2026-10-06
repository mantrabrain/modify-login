<?php
/**
 * Two-factor admin screen and Users-list column.
 *
 * @package Authlify
 */

namespace Authlify\TwoFactor;

use Authlify\Admin\Menu;
use Authlify\Admin\UI;
use Authlify\Plugin;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Authlify → Two-factor: which methods are offered, who uses 2FA, and a
 * "reset for user" search. Also adds a 2FA column to the Users list.
 *
 * @since 3.0.0
 */
final class AdminPage
{
    const RESET_ACTION = 'authlify_2fa_reset';
    const COEXIST_DISMISS = 'authlify_2fa_coexist_dismiss';

    /**
     * Wire up.
     *
     * @since 3.0.0
     */
    public static function init()
    {
        add_filter('authlify_admin_pages', array(__CLASS__, 'page'));
        add_filter('authlify_admin_notice_messages', array(__CLASS__, 'notice_messages'));
        add_action('admin_post_' . self::RESET_ACTION, array(__CLASS__, 'handle_reset'));
        add_action('admin_notices', array(__CLASS__, 'coexist_notice'));
        add_action('admin_post_' . self::COEXIST_DISMISS, array(__CLASS__, 'dismiss_coexist'));
        add_filter('manage_users_columns', array(__CLASS__, 'column'));
        add_filter('wpmu_users_columns', array(__CLASS__, 'column'));
        add_filter('manage_users_custom_column', array(__CLASS__, 'column_value'), 10, 3);
    }

    /**
     * Register the page.
     *
     * @param array $pages Pages.
     * @return array
     */
    public static function page($pages)
    {
        $pages['two-factor'] = array('authlify-two-factor', __('Two-factor', 'modify-login'), array(__CLASS__, 'render'), 30);

        return $pages;
    }

    /**
     * Notice after a reset.
     *
     * @param array $messages Messages.
     * @return array
     */
    public static function notice_messages($messages)
    {
        $messages['twofa_reset'] = __('Two-factor login was reset for that user.', 'modify-login');

        return $messages;
    }

    /**
     * Render the page.
     */
    public static function render()
    {
        $other = TwoFactor::other_provider();
        $supported = Passkeys::supported();
        $offered = (array) Settings::get('twofa_methods', array());
        ?>
        <div class="wrap authlify-page">
            <?php UI::header(__('Two-factor login', 'modify-login'), __('Let people protect their account with an authenticator app, backup codes or a passkey.', 'modify-login'), array(
                array('label' => __('Set up for my account', 'modify-login'), 'url' => admin_url('profile.php#authlify-two-factor')),
            ), 'two-factor'); ?>

            <?php if ('' !== $other) : ?>
                <div class="notice notice-<?php echo self::stranded() ? 'warning' : 'info'; ?>">
                    <p><?php echo esc_html(sprintf(
                        /* translators: %s: plugin name */
                        __('%s is active and handles two-factor login on this site, so Authlify does not add a second step or a passkey button. The settings below take effect if you deactivate it.', 'modify-login'),
                        $other
                    )); ?></p>
                    <?php foreach (self::paused_lines($other) as $line) : ?>
                        <p><?php echo esc_html($line); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php $problems = TwoFactor::method_problems(); ?>
            <?php if ($problems) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Some two-factor methods cannot be used right now.', 'modify-login'); ?></strong></p>
                    <?php foreach ($problems as $problem) : ?>
                        <p><?php echo esc_html($problem); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (defined('AUTHLIFY_DISABLE_2FA') && AUTHLIFY_DISABLE_2FA) : ?>
                <div class="notice notice-warning"><p><?php echo wp_kses(__('Two-factor login is switched off by <code>AUTHLIFY_DISABLE_2FA</code> in wp-config.php. Remove that line to turn it back on.', 'modify-login'), UI::inline_html()); ?></p></div>
            <?php endif; ?>

            <?php UI::form_start('two-factor'); ?>
            <?php UI::panel_start(__('Methods', 'modify-login'), __('Two-factor login is opt-in: each person turns it on from their own profile.', 'modify-login')); ?>
                <?php UI::toggle_row('twofa_enabled', __('Let users turn on two-factor login', 'modify-login'), __('When off, nobody is asked for a second step and the profile section is hidden. Existing setups are kept.', 'modify-login'), __('Two-factor login', 'modify-login')); ?>

                <?php
                UI::own('twofa_methods');
                UI::field_start(__('Offered methods', 'modify-login'), __('Turning a method off stops new setups. People who already use it keep it until they remove it or you reset them.', 'modify-login'), '', 'authlify[twofa_enabled]=1');
                $labels = array(
                    'totp' => array(__('Authenticator app', 'modify-login'), __('A 6-digit code from Google Authenticator, 1Password, Authy and similar apps.', 'modify-login')),
                    'backup' => array(__('Backup codes', 'modify-login'), __('One-time codes for when the phone or passkey is not at hand.', 'modify-login')),
                    'passkey' => array(__('Passkeys and security keys', 'modify-login'), $supported
                        ? __('Fingerprint, face, screen lock or a hardware key. Needs https.', 'modify-login')
                        /* translators: %s: PHP version */
                        : sprintf(__('Needs PHP 8.0 or newer; this server runs PHP %s. Ask your host to upgrade.', 'modify-login'), PHP_VERSION)),
                );
                ?>
                <fieldset class="authlify-checklist">
                    <legend class="screen-reader-text"><?php esc_html_e('Offered methods', 'modify-login'); ?></legend>
                    <?php foreach ($labels as $key => $label) : ?>
                        <label>
                            <input type="checkbox" name="authlify[twofa_methods][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $offered, true)); ?><?php disabled('passkey' === $key && !$supported); ?>>
                            <span class="authlify-option"><span class="authlify-option__title"><?php echo esc_html($label[0]); ?></span><span class="authlify-option__desc"><?php echo esc_html($label[1]); ?></span></span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$supported && in_array('passkey', $offered, true)) : ?>
                        <input type="hidden" name="authlify[twofa_methods][]" value="passkey">
                    <?php endif; ?>
                </fieldset>
                <?php UI::field_end(); ?>

                <?php UI::toggle_row('passkey_login_button', __('Show "Sign in with a passkey" on the login form', 'modify-login'), __('People with a passkey can skip the password; it counts as both factors. The button appears once someone has added a passkey.', 'modify-login') . ' ' . UI::learn_more('passkeys'), __('Passkey sign-in', 'modify-login')); ?>
            <?php UI::panel_end(); ?>
            <?php
            /**
             * Adds panels inside the Two-factor settings form (Authlify Pro policies).
             *
             * @since 3.0.0
             */
            do_action('authlify_twofactor_admin_panels');
            ?>
            <?php UI::form_end(); ?>

            <?php
            /**
             * Fires after the Two-factor settings form.
             *
             * @since 3.0.0
             */
            do_action('authlify_twofactor_admin_after');
            ?>

            <?php self::coverage_panel(); ?>
            <?php self::reset_panel(); ?>

            <?php \Authlify\Admin\Upsell::render('two-factor'); ?>
        </div>
        <?php
    }

    /**
     * Number of users who set up two-factor login with Authlify.
     *
     * @return int
     * @since 3.0.0
     */
    public static function stranded()
    {
        $query = new \WP_User_Query(array(
            'blog_id' => 0,
            'meta_key' => TwoFactor::META_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_compare' => 'EXISTS',
            'fields' => 'ID',
            'number' => 1,
            'count_total' => true,
        ));

        return (int) $query->get_total();
    }

    /**
     * What stops while another two-factor plugin is active.
     *
     * @param string $other Plugin name.
     * @return string[]
     */
    private static function paused_lines($other)
    {
        $lines = array();
        $count = self::stranded();
        if ($count) {
            $lines[] = sprintf(
                /* translators: 1: number of users, 2: plugin name */
                _n(
                    '%1$d person set up two-factor login with Authlify and now signs in with only a password. Deactivate %2$s to protect them again, or ask them to set up %2$s.',
                    '%1$d people set up two-factor login with Authlify and now sign in with only a password. Deactivate %2$s to protect them again, or ask them to set up %2$s.',
                    $count,
                    'modify-login'
                ),
                $count,
                $other
            );
        }

        /**
         * Filters the lines that explain what is paused while another two-factor
         * plugin is active (Authlify Pro adds its role enforcement).
         *
         * @param string[] $lines Plain-text lines.
         * @param string   $other Plugin name.
         * @since 3.0.0
         */
        return array_values(array_filter((array) apply_filters('authlify_twofactor_paused_lines', $lines, $other)));
    }

    /**
     * A warning on the Dashboard, Plugins and Users screens when another
     * two-factor plugin took over and Authlify users lost their second step.
     */
    public static function coexist_notice()
    {
        global $pagenow;

        $other = TwoFactor::other_provider();
        if ('' === $other || TwoFactor::disabled() || !current_user_can(Plugin::cap()) || !in_array($pagenow, array('index.php', 'plugins.php', 'users.php'), true)) {
            return;
        }
        if ($other === get_user_meta(get_current_user_id(), 'authlify_2fa_coexist_dismissed', true)) {
            return;
        }

        $lines = self::paused_lines($other);
        if (!$lines) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p><strong><?php echo esc_html(sprintf(
                /* translators: %s: plugin name */
                __('Authlify two-factor login is paused because %s is active.', 'modify-login'),
                $other
            )); ?></strong></p>
            <?php foreach ($lines as $line) : ?>
                <p><?php echo esc_html($line); ?></p>
            <?php endforeach; ?>
            <p>
                <a href="<?php echo esc_url(Menu::url('two-factor')); ?>"><?php esc_html_e('Two-factor settings', 'modify-login'); ?></a> ·
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::COEXIST_DISMISS), self::COEXIST_DISMISS)); ?>"><?php esc_html_e('Dismiss', 'modify-login'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Dismiss the coexistence warning (until another plugin takes over).
     */
    public static function dismiss_coexist()
    {
        if (!current_user_can(Plugin::cap()) || !check_admin_referer(self::COEXIST_DISMISS)) {
            wp_die(esc_html__('You are not allowed to do this.', 'modify-login'), 403);
        }

        update_user_meta(get_current_user_id(), 'authlify_2fa_coexist_dismissed', TwoFactor::other_provider());
        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
        exit;
    }

    /**
     * Who uses two-factor login, by role.
     */
    private static function coverage_panel()
    {
        $coverage = TwoFactor::coverage();
        UI::panel_start(__('Who uses two-factor login', 'modify-login'), __('People with an authenticator app or a passkey. Backup codes alone do not count.', 'modify-login'));
        ?>
        <table class="widefat striped authlify-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Role', 'modify-login'); ?></th>
                    <th scope="col" class="num"><?php esc_html_e('Users', 'modify-login'); ?></th>
                    <th scope="col" class="num"><?php esc_html_e('With 2FA', 'modify-login'); ?></th>
                    <th scope="col" class="num"><?php esc_html_e('Without', 'modify-login'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$coverage) : ?>
                <tr><td colspan="4" class="authlify-table__sub"><?php esc_html_e('No users yet.', 'modify-login'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($coverage as $role => $row) : ?>
                <tr>
                    <th scope="row"><a href="<?php echo esc_url(add_query_arg('role', $role, admin_url('users.php'))); ?>"><?php echo esc_html($row['label']); ?></a></th>
                    <td class="num"><?php echo esc_html(number_format_i18n($row['total'])); ?></td>
                    <td class="num"><?php echo wp_kses_post($row['with'] >= $row['total'] ? UI::pill(number_format_i18n($row['with']), 'ok') : number_format_i18n($row['with'])); ?></td>
                    <td class="num"><?php echo esc_html(number_format_i18n(max(0, $row['total'] - $row['with']))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        UI::panel_end();
    }

    /**
     * Find a user and reset their two-factor login.
     */
    private static function reset_panel()
    {
        $search = isset($_GET['authlify_user']) ? sanitize_text_field(wp_unslash($_GET['authlify_user'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        UI::panel_start(__('Reset for a user', 'modify-login'), __('For someone who lost their phone and backup codes. It removes their methods (you never see their secrets) so they can sign in with their password and set up again.', 'modify-login'));
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="authlify-search" role="search">
                <input type="hidden" name="page" value="authlify-two-factor">
                <label for="authlify-2fa-user" class="screen-reader-text"><?php esc_html_e('Username or email', 'modify-login'); ?></label>
                <input type="search" id="authlify-2fa-user" name="authlify_user" value="<?php echo esc_attr($search); ?>" class="regular-text" placeholder="<?php esc_attr_e('Username or email', 'modify-login'); ?>">
                <?php submit_button(__('Find user', 'modify-login'), 'secondary', '', false); ?>
        </form>
        <?php
        if ('' !== $search) {
            $users = get_users(array(
                'search' => '*' . $search . '*',
                'search_columns' => array('user_login', 'user_email', 'display_name'),
                'number' => 20,
            ));
            ?>
            <table class="widefat striped authlify-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('User', 'modify-login'); ?></th>
                        <th scope="col"><?php esc_html_e('Two-factor', 'modify-login'); ?></th>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'modify-login'); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$users) : ?>
                    <tr><td colspan="3" class="authlify-table__sub"><?php esc_html_e('No users match that search.', 'modify-login'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($users as $user) : ?>
                    <?php $methods = TwoFactor::user_methods($user->ID); ?>
                    <tr>
                        <td><strong><?php echo esc_html($user->user_login); ?></strong><span class="authlify-table__sub"><?php echo esc_html($user->user_email); ?></span></td>
                        <td><?php echo wp_kses_post(self::summary($user->ID)); ?></td>
                        <td class="num">
                            <?php if ($methods && current_user_can('edit_user', $user->ID)) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="authlify-inline-form">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::RESET_ACTION); ?>">
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user->ID); ?>">
                                    <?php wp_nonce_field(self::RESET_ACTION . '_' . $user->ID); ?>
                                    <button type="submit" class="button button-small" onclick="return confirm(<?php echo esc_attr(wp_json_encode(sprintf(
                                        /* translators: %s: username */
                                        __('Reset two-factor login for %s? They can then sign in with just their password.', 'modify-login'),
                                        $user->user_login
                                    ))); ?>);"><?php esc_html_e('Reset', 'modify-login'); ?></button>
                                </form>
                            <?php elseif ($methods) : ?>
                                <span class="authlify-sublabel"><?php esc_html_e('You cannot reset this user.', 'modify-login'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
        UI::panel_end();
    }

    /**
     * Short HTML summary of a user's methods.
     *
     * @param int $user_id User ID.
     * @return string
     */
    private static function summary($user_id)
    {
        $methods = TwoFactor::user_methods($user_id);
        if (!$methods) {
            return UI::pill(__('Off', 'modify-login'), 'neutral');
        }

        $all = TwoFactor::methods();
        $names = array();
        foreach ($methods as $key) {
            if ('passkey' === $key) {
                $names[] = sprintf(
                    /* translators: %d: number of passkeys */
                    _n('%d passkey', '%d passkeys', Passkeys::count_for_user($user_id), 'modify-login'),
                    Passkeys::count_for_user($user_id)
                );
            } elseif ('backup' === $key) {
                $names[] = sprintf(
                    /* translators: %d: number of backup codes */
                    _n('%d backup code', '%d backup codes', BackupCodes::remaining($user_id), 'modify-login'),
                    BackupCodes::remaining($user_id)
                );
            } else {
                $names[] = isset($all[$key]['label']) ? $all[$key]['label'] : $key;
            }
        }

        $pill = TwoFactor::is_active_for($user_id) ? UI::pill(__('On', 'modify-login'), 'ok') : UI::pill(__('Off', 'modify-login'), 'neutral');

        return $pill . ' <span class="authlify-sublabel">' . esc_html(implode(', ', $names)) . '</span>';
    }

    /**
     * Reset handler.
     */
    public static function handle_reset()
    {
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        if (!$user_id || !check_admin_referer(self::RESET_ACTION . '_' . $user_id) || !current_user_can('edit_users') || !current_user_can('edit_user', $user_id)) {
            wp_die(esc_html__('You are not allowed to reset two-factor login for this user.', 'modify-login'), 403);
        }

        /** This filter is documented in inc/TwoFactor/Rest.php */
        $ok = apply_filters('authlify_confirm_identity', true, 'twofa_reset', $user_id);
        if (is_wp_error($ok)) {
            wp_die(esc_html($ok->get_error_message()), 403);
        }

        TwoFactor::reset($user_id);

        $back = wp_get_referer() ? wp_get_referer() : Menu::url('two-factor');
        wp_safe_redirect(add_query_arg('authlify_notice', 'twofa_reset', remove_query_arg(array('authlify_saved', 'authlify_error', 'authlify_notice'), $back)));
        exit;
    }

    /**
     * Users-list column.
     *
     * @param array $columns Columns.
     * @return array
     */
    public static function column($columns)
    {
        if ('' === TwoFactor::other_provider()) {
            $columns['authlify_2fa'] = __('2FA', 'modify-login');
        }

        return $columns;
    }

    /**
     * Users-list column value.
     *
     * @param string $output  Output.
     * @param string $column  Column.
     * @param int    $user_id User ID.
     * @return string
     */
    public static function column_value($output, $column, $user_id)
    {
        if ('authlify_2fa' !== $column) {
            return $output;
        }

        // One passkey query for the whole page instead of one per row (CMPT-09).
        static $primed = false;
        if (!$primed) {
            $primed = true;
            $ids = array((int) $user_id);
            $table = isset($GLOBALS['wp_list_table']) ? $GLOBALS['wp_list_table'] : null;
            if ($table instanceof \WP_List_Table && is_array($table->items)) {
                foreach ($table->items as $item) {
                    if ($item instanceof \WP_User) {
                        $ids[] = (int) $item->ID;
                    }
                }
            }
            Passkeys::prime($ids);
        }

        if (!TwoFactor::is_active_for($user_id)) {
            return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('Off', 'modify-login') . '</span>';
        }

        $labels = array();
        $all = TwoFactor::methods();
        foreach (TwoFactor::user_methods($user_id) as $key) {
            if ('backup' !== $key) {
                $labels[] = 'passkey' === $key ? __('Passkey', 'modify-login') : (isset($all[$key]['label']) ? $all[$key]['label'] : $key);
            }
        }

        return '<span class="dashicons dashicons-yes" aria-hidden="true" style="color:#00a32a"></span> ' . esc_html(implode(', ', $labels));
    }
}
