<?php
/**
 * Tools: import/export, switching from other plugins, data.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Login\Router;
use Authlify\Plugin;
use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Settings export/import, importers from other login plugins, and the
 * "delete data on uninstall" switch.
 */
final class ToolsPage
{
    /**
     * Wire up.
     */
    public static function boot()
    {
        add_action('admin_post_authlify_export_settings', array(__CLASS__, 'export'));
        add_action('admin_post_authlify_import_settings', array(__CLASS__, 'import'));
        add_action('admin_post_authlify_run_importer', array(__CLASS__, 'run_importer'));
    }

    /**
     * Importers from other plugins: key => array( label, detect callable, run callable ).
     *
     * @return array
     */
    public static function importers()
    {
        /**
         * Filters the importers (the designer adds LoginPress and Colorlib designs).
         *
         * @param array $importers key => array( label, detect callable → bool, run callable → string summary ).
         * @since 3.0.0
         */
        return apply_filters('authlify_importers', array(
            'wps-hide-login' => array(
                __('WPS Hide Login: login URL and redirect page', 'modify-login'),
                function () {
                    return '' !== (string) get_option('whl_page', get_site_option('whl_page', ''));
                },
                function () {
                    $slug = Settings::clean_slug(get_option('whl_page', get_site_option('whl_page', '')));
                    if (is_wp_error($valid = LoginUrlPage::validate_slug($slug))) {
                        return sprintf(__('Not imported: %s', 'modify-login'), $valid->get_error_message());
                    }
                    $redirect = (string) get_option('whl_redirect_admin', '');
                    $values = array('login_slug' => $slug, 'block_wp_login' => true, 'blocked_response' => '404');
                    if ('' !== $redirect && '404' !== $redirect) {
                        $values['blocked_response'] = 'redirect';
                        $values['blocked_redirect_url'] = home_url('/' . $redirect);
                    }
                    Settings::update($values);

                    return sprintf(__('Login URL set to /%s. Deactivate WPS Hide Login now; both plugins must not run together.', 'modify-login'), $slug);
                },
            ),
            'limit-login-attempts-reloaded' => array(
                __('Limit Login Attempts Reloaded: attempts, lockout length, allow and block lists', 'modify-login'),
                function () {
                    return false !== get_option('limit_login_allowed_retries', false);
                },
                function () {
                    $lines = function ($opt) {
                        $v = get_option($opt, array());

                        return is_array($v) ? implode("\n", array_filter(array_map('trim', $v))) : '';
                    };
                    Settings::update(array(
                        'limit_enabled' => true,
                        'limit_attempts' => (int) get_option('limit_login_allowed_retries', 4),
                        'lockout_minutes' => max(1, (int) round((int) get_option('limit_login_lockout_duration', 1200) / 60)),
                        'ip_allowlist' => $lines('limit_login_whitelist'),
                        'ip_denylist' => $lines('limit_login_blacklist'),
                    ));

                    return __('Brute-force settings imported.', 'modify-login');
                },
            ),
            'admin-site-enhancements' => array(
                __('Admin and Site Enhancements: custom login URL', 'modify-login'),
                function () {
                    $o = get_option('admin_site_enhancements', array());

                    return is_array($o) && !empty($o['custom_login_slug']) && !empty($o['change_login_url']);
                },
                function () {
                    $o = get_option('admin_site_enhancements', array());
                    $slug = Settings::clean_slug($o['custom_login_slug']);
                    if (is_wp_error($valid = LoginUrlPage::validate_slug($slug))) {
                        return sprintf(__('Not imported: %s', 'modify-login'), $valid->get_error_message());
                    }
                    Settings::update(array('login_slug' => $slug, 'block_wp_login' => true));

                    return sprintf(__('Login URL set to /%s. Turn off "Change Login URL" in ASE.', 'modify-login'), $slug);
                },
            ),
        ));
    }

    /**
     * Sections: key => array( label, optional icon, group, keywords ).
     *
     * @return array
     */
    public static function sections()
    {
        return array(
            'switch' => array(__('Switch plugins', 'modify-login')),
            'import' => array(__('Import & export', 'modify-login')),
            'data' => array(__('Data', 'modify-login')),
        );
    }

    /**
     * Render.
     */
    public static function render()
    {
        $tab = UI::current_tab(self::sections());
        $docs = array('switch' => 'howto-from-wps-hide-login', 'import' => 'import-export', 'data' => 'faq-uninstall');
        ?>
        <div class="wrap authlify-page">
            <?php UI::header(__('Settings', 'modify-login'), __('Move settings between sites, switch from another login plugin, and manage stored data.', 'modify-login'), array(), $docs[$tab]); ?>

            <?php
            $message = get_transient('authlify_import_result_' . get_current_user_id());
            if ($message) {
                delete_transient('authlify_import_result_' . get_current_user_id());
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
            }
            ?>

            <?php if ('switch' === $tab) : ?>
            <?php UI::panel_start(__('Switch from another plugin', 'modify-login'), __('Copy settings from login plugins found on this site. Nothing in the other plugin is changed.', 'modify-login') . ' ' . UI::learn_more('howto-from-wps-hide-login', __('What is copied', 'modify-login'))); ?>
                <?php
                $found = 0;
                echo '<ul class="authlify-checks">';
                foreach (self::importers() as $key => $importer) {
                    if (!call_user_func($importer[1])) {
                        continue;
                    }
                    $found++;
                    $parts = explode(':', $importer[0], 2);
                    ?>
                    <li class="authlify-row">
                        <div class="authlify-row__main">
                            <strong><?php echo esc_html(trim($parts[0])); ?></strong>
                            <?php if (isset($parts[1])) : ?>
                                <span class="authlify-sublabel"><?php echo esc_html(ucfirst(trim($parts[1]))); ?></span>
                            <?php endif; ?>
                        </div>
                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=authlify_run_importer&importer=' . rawurlencode($key)), 'authlify_run_importer')); ?>"><?php esc_html_e('Import', 'modify-login'); ?></a>
                    </li>
                    <?php
                }
                echo '</ul>';
                if (!$found) {
                    echo '<p class="authlify-empty">' . esc_html__('No other login plugin was found. Settings from WPS Hide Login, Limit Login Attempts Reloaded, Admin and Site Enhancements and LoginPress can be copied when they are installed.', 'modify-login') . '</p>';
                }
                ?>
            <?php UI::panel_end(); ?>

            <?php elseif ('import' === $tab) : ?>
            <?php UI::panel_start(__('Export and import', 'modify-login'), __('Move your setup to another site. CAPTCHA secret keys are never exported.', 'modify-login')); ?>
                <ul class="authlify-checks">
                    <li class="authlify-row">
                        <div class="authlify-row__main">
                            <strong><?php esc_html_e('Export settings', 'modify-login'); ?></strong>
                            <span class="authlify-sublabel"><?php esc_html_e('Downloads a JSON file with every Authlify setting.', 'modify-login'); ?></span>
                        </div>
                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=authlify_export_settings'), 'authlify_export_settings')); ?>"><?php esc_html_e('Download', 'modify-login'); ?></a>
                    </li>
                    <li class="authlify-row authlify-row--top">
                        <div class="authlify-row__main">
                            <strong><?php esc_html_e('Import settings', 'modify-login'); ?></strong>
                            <span class="authlify-sublabel"><?php esc_html_e('Replaces the settings in the file. Your login URL stays unless you tick the box.', 'modify-login'); ?></span>
                            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="authlify-import">
                                <input type="hidden" name="action" value="authlify_import_settings">
                                <?php wp_nonce_field('authlify_import_settings'); ?>
                                <label for="authlify-import-file" class="screen-reader-text"><?php esc_html_e('Settings file', 'modify-login'); ?></label>
                                <input type="file" id="authlify-import-file" name="authlify_file" accept=".json,application/json" required>
                                <label class="authlify-import__check"><input type="checkbox" name="include_login_url" value="1"> <?php esc_html_e('Also import the login URL', 'modify-login'); ?></label>
                                <button class="button"><?php esc_html_e('Import', 'modify-login'); ?></button>
                            </form>
                        </div>
                    </li>
                </ul>
            <?php UI::panel_end(); ?>

            <?php else : ?>
            <?php UI::form_start('tools'); ?>
            <?php UI::panel_start(__('Data', 'modify-login'), __('What happens to Authlify\'s data if you remove the plugin.', 'modify-login')); ?>
                <?php UI::toggle_row('delete_data', __('Delete all Authlify settings, logs and two-factor data when the plugin is deleted', 'modify-login'), __('Off by default so reinstalling keeps everything. Deactivating never deletes anything.', 'modify-login') . ' ' . UI::learn_more('faq-uninstall', __('What is removed', 'modify-login')), __('On uninstall', 'modify-login')); ?>
            <?php UI::panel_end(); ?>
            <?php UI::form_end(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Download settings as JSON.
     */
    public static function export()
    {
        if (!current_user_can(Plugin::cap()) || !check_admin_referer('authlify_export_settings')) {
            wp_die(esc_html__('You are not allowed to do that.', 'modify-login'), 403);
        }

        // Only keys a loaded module declares (never data kept for an inactive
        // add-on), and never anything that looks like a secret.
        $settings = self::without_secrets(array_intersect_key(Settings::all(), Settings::schema()));
        unset($settings['pending_token'], $settings['pending_slug'], $settings['pending_expires']);

        /**
         * Filters the exported data (the designer adds the login page design).
         *
         * @param array $data Data.
         * @since 3.0.0
         */
        $data = apply_filters('authlify_export_data', array(
            'plugin' => 'authlify',
            'version' => AUTHLIFY_VERSION,
            'exported' => gmdate('c'),
            'settings' => $settings,
        ));

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=authlify-settings-' . gmdate('Y-m-d') . '.json');
        echo wp_json_encode($data, JSON_PRETTY_PRINT); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Drop secrets from exported settings: known secret keys, anything whose
     * name says secret, private key, token or password, and encrypted values.
     *
     * @param array $settings Settings.
     * @return array
     */
    public static function without_secrets(array $settings)
    {
        foreach ($settings as $key => $value) {
            $secret_name = (bool) preg_match('/secret|private_key|token|password|api_key|license_key|licence_key/i', (string) $key);
            $secret_value = is_string($value) && (bool) preg_match('/^(s1|p1):/', $value);
            if ($secret_name || $secret_value) {
                unset($settings[$key]);
            } elseif (is_array($value)) {
                $settings[$key] = self::without_secrets($value);
            }
        }

        /**
         * Filters exported settings after secrets were removed.
         *
         * @param array $settings Settings.
         * @since 3.0.0
         */
        return (array) apply_filters('authlify_export_settings_clean', $settings);
    }

    /**
     * Run the same checks a settings screen runs before saving (bounds, "your
     * own address is on the block list", CAPTCHA keys), screen by screen.
     *
     * @param array $values Imported values.
     * @return array|\WP_Error Values to save, or the first refusal.
     */
    private static function validate_import(array $values)
    {
        $groups = array(
            'protection|limits' => array('limit_enabled', 'limit_attempts', 'limit_window', 'lockout_minutes', 'lockout_escalate', 'limit_network', 'user_attempts', 'limit_user_lock', 'ip_source', 'trusted_proxies', 'ip_allowlist', 'ip_denylist'),
            'protection|captcha' => array('captcha_provider', 'captcha_site_key', 'captcha_secret_key', 'captcha_v3_threshold', 'captcha_forms', 'captcha_mode', 'captcha_after', 'captcha_test_mode', 'captcha_fail', 'honeypot'),
            'protection|hardening' => array('xmlrpc', 'block_user_enumeration', 'app_passwords', 'generic_errors', 'force_login', 'force_login_exclude'),
            'protection|passwords' => array('hibp_enabled', 'hibp_roles'),
        );

        $checked = array();
        foreach ($groups as $where => $keys) {
            list($page, $tab) = explode('|', $where);
            $part = array_intersect_key($values, array_flip($keys));
            if (!$part) {
                continue;
            }
            /** This filter is documented in inc/Admin/Menu.php */
            $part = apply_filters('authlify_validate_settings', $part, $page, $tab);
            if (is_wp_error($part)) {
                return $part;
            }
            $checked = array_merge($checked, $part);
        }

        // Everything else goes through its own screen's validator too (keys are
        // grouped by screen only for the core screens above).
        $rest = array_diff_key($values, $checked);
        foreach (array('two-factor', 'activity', 'redirects', 'tools') as $page) {
            /** This filter is documented in inc/Admin/Menu.php */
            $rest = apply_filters('authlify_validate_settings', $rest, $page, '');
            if (is_wp_error($rest)) {
                return $rest;
            }
        }

        return array_merge($rest, $checked);
    }

    /**
     * Import settings from JSON.
     */
    public static function import()
    {
        if (!current_user_can(Plugin::cap()) || !check_admin_referer('authlify_import_settings')) {
            wp_die(esc_html__('You are not allowed to do that.', 'modify-login'), 403);
        }

        $file = isset($_FILES['authlify_file']['tmp_name']) ? $_FILES['authlify_file']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $raw = $file && is_uploaded_file($file) ? file_get_contents($file) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
        $data = json_decode((string) $raw, true);

        if (!is_array($data) || !isset($data['plugin'], $data['settings']) || 'authlify' !== $data['plugin'] || !is_array($data['settings'])) {
            set_transient('authlify_error_' . get_current_user_id(), __('That file is not an Authlify settings export.', 'modify-login'), MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg('authlify_error', 1, Menu::url('tools', array('tab' => 'import'))));
            exit;
        }

        $values = array_intersect_key($data['settings'], Settings::schema());
        // Never import the "delete everything on uninstall" switch or one-time state.
        unset($values['captcha_secret_key'], $values['pending_slug'], $values['pending_token'], $values['pending_expires'], $values['onboarding_done'], $values['delete_data']);

        $slug = isset($values['login_slug']) ? Settings::clean_slug($values['login_slug']) : '';
        unset($values['login_slug']);
        if (empty($_POST['include_login_url'])) {
            unset($values['block_wp_login']);
            $slug = '';
        }

        // The CAPTCHA secret is never exported, so another site's provider keys
        // cannot work here (they would refuse every login). Keep this site's
        // CAPTCHA and say so; key-less providers (ALTCHA) import normally.
        $notes = array();
        $new_provider = isset($values['captcha_provider']) ? (string) $values['captcha_provider'] : (string) Settings::get('captcha_provider');
        $changes_keys = $new_provider !== Settings::get('captcha_provider') || (isset($values['captcha_site_key']) && $values['captcha_site_key'] !== Settings::get('captcha_site_key'));
        $provider = class_exists('Authlify\\Captcha\\Captcha') ? \Authlify\Captcha\Captcha::provider($new_provider) : null;
        if ($changes_keys && $provider && $provider->needs_keys()) {
            unset($values['captcha_provider'], $values['captcha_site_key'], $values['captcha_v3_threshold']);
            $notes[] = __('The CAPTCHA provider and keys were not imported (the secret key is never exported). Set them up under Security → CAPTCHA.', 'modify-login');
        }

        $values = self::validate_import($values);
        if (is_wp_error($values)) {
            set_transient('authlify_error_' . get_current_user_id(), sprintf(__('Nothing was imported: %s', 'modify-login'), $values->get_error_message()), MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg('authlify_error', 1, Menu::url('tools', array('tab' => 'import'))));
            exit;
        }

        Settings::update($values);
        if ($notes) {
            set_transient('authlify_error_' . get_current_user_id(), implode(' ', $notes), MINUTE_IN_SECONDS);
        }

        // A new login URL goes through the same confirm step as the Login URL screen.
        if ('' !== $slug && $slug !== Router::slug()) {
            $valid = LoginUrlPage::validate_slug($slug);
            if (is_wp_error($valid)) {
                set_transient('authlify_error_' . get_current_user_id(), sprintf(__('The other settings were imported, but not the login URL: %s', 'modify-login'), $valid->get_error_message()), MINUTE_IN_SECONDS);
            } else {
                set_transient('authlify_confirm_url_' . get_current_user_id(), \Authlify\Login\Recovery::start_pending($slug), 30 * MINUTE_IN_SECONDS);
                /** This action is documented below. */
                do_action('authlify_imported', $data);
                wp_safe_redirect(add_query_arg('authlify_notice', 'imported', Menu::url('login-url')));
                exit;
            }
        }

        /**
         * Fires after settings were imported.
         *
         * @param array $data Imported data.
         * @since 3.0.0
         */
        do_action('authlify_imported', $data);

        wp_safe_redirect(add_query_arg('authlify_notice', 'imported', Menu::url('tools', array('tab' => 'import'))));
        exit;
    }

    /**
     * Run a plugin importer.
     */
    public static function run_importer()
    {
        if (!current_user_can(Plugin::cap()) || !check_admin_referer('authlify_run_importer')) {
            wp_die(esc_html__('You are not allowed to do that.', 'modify-login'), 403);
        }

        $key = isset($_GET['importer']) ? sanitize_key(wp_unslash($_GET['importer'])) : '';
        $importers = self::importers();

        if (isset($importers[$key]) && call_user_func($importers[$key][1])) {
            $summary = (string) call_user_func($importers[$key][2]);
            set_transient('authlify_import_result_' . get_current_user_id(), $summary, MINUTE_IN_SECONDS);
        }

        wp_safe_redirect(Menu::url('tools'));
        exit;
    }
}
