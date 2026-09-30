<?php
/**
 * Redirects screen.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * Global and per-role login/logout redirects.
 */
final class RedirectsPage
{
    /**
     * Render.
     */
    public static function render()
    {
        $rules = (array) Settings::get('role_redirects', array());
        $roles = wp_roles()->get_names();
        ?>
        <div class="wrap authlify-page">
            <?php UI::header(__('Redirects', 'modify-login'), __('Choose where people land after they log in or out.', 'modify-login'), array(), 'redirects'); ?>
            <?php UI::form_start('redirects'); ?>

            <?php UI::panel_start(__('Everyone', 'modify-login'), __('Leave empty for the WordPress default: the dashboard, or the page the person came from.', 'modify-login')); ?>
                <?php UI::input_row('login_redirect_url', __('After login', 'modify-login'), '', array('type' => 'url', 'placeholder' => admin_url(), 'class' => 'large-text')); ?>
                <?php UI::input_row('logout_redirect_url', __('After logout', 'modify-login'), '', array('type' => 'url', 'placeholder' => home_url('/'), 'class' => 'large-text')); ?>
            <?php UI::panel_end(); ?>

            <?php UI::panel_start(__('By role', 'modify-login'), __('Send a role somewhere else. Empty fields use the addresses above.', 'modify-login')); ?>
                <?php UI::own('role_redirects'); ?>
                <div class="authlify-expand-list">
                    <?php foreach ($roles as $role => $name) : ?>
                        <?php
                        $label = translate_user_role($name);
                        $login = isset($rules[$role]['login']) ? (string) $rules[$role]['login'] : '';
                        $logout = isset($rules[$role]['logout']) ? (string) $rules[$role]['logout'] : '';
                        $meta = array();
                        if ('' !== $login) {
                            /* translators: %s: URL */
                            $meta[] = sprintf(__('Login → %s', 'modify-login'), self::short($login));
                        }
                        if ('' !== $logout) {
                            /* translators: %s: URL */
                            $meta[] = sprintf(__('Logout → %s', 'modify-login'), self::short($logout));
                        }
                        $id = 'authlify-redirect-' . sanitize_html_class($role);
                        ?>
                        <details class="authlify-expand"<?php echo $meta ? ' open' : ''; ?>>
                            <summary>
                                <span class="authlify-expand__title"><?php echo esc_html($label); ?></span>
                                <span class="authlify-expand__meta"><?php echo esc_html($meta ? implode(' · ', $meta) : __('Uses the defaults', 'modify-login')); ?></span>
                            </summary>
                            <div class="authlify-expand__body">
                                <?php UI::field_start(__('After login', 'modify-login'), '', $id . '-login'); ?>
                                    <input type="url" id="<?php echo esc_attr($id . '-login'); ?>" class="large-text" name="authlify[role_redirects][<?php echo esc_attr($role); ?>][login]" value="<?php echo esc_attr($login); ?>" placeholder="<?php esc_attr_e('Same as everyone', 'modify-login'); ?>">
                                <?php UI::field_end(); ?>
                                <?php UI::field_start(__('After logout', 'modify-login'), '', $id . '-logout'); ?>
                                    <input type="url" id="<?php echo esc_attr($id . '-logout'); ?>" class="large-text" name="authlify[role_redirects][<?php echo esc_attr($role); ?>][logout]" value="<?php echo esc_attr($logout); ?>" placeholder="<?php esc_attr_e('Same as everyone', 'modify-login'); ?>">
                                <?php UI::field_end(); ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
                <p class="authlify-panel__note"><?php echo wp_kses(__('Use <code>{username}</code> or <code>{user_id}</code> in a URL. Only addresses on this site (or hosts your site allows) are used, and a "log in to continue" link still returns people to that page.', 'modify-login'), UI::inline_html()); ?></p>
            <?php UI::panel_end(); ?>

            <?php UI::form_end(); ?>
        </div>
        <?php
    }

    /**
     * A URL shortened for a summary line (path only for this site's URLs).
     *
     * @param string $url URL.
     * @return string
     */
    private static function short($url)
    {
        $home = untrailingslashit(home_url());
        if (0 === strpos($url, $home)) {
            $path = substr($url, strlen($home));

            return '' !== $path ? $path : '/';
        }

        return $url;
    }
}
