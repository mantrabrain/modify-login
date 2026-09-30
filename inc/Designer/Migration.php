<?php
/**
 * Design migration and importers.
 *
 * @package Authlify
 */

namespace Authlify\Designer;

defined('ABSPATH') || exit;

/**
 * Maps the Modify Login 2.x builder options, LoginPress and Colorlib Login
 * Customizer settings to a design, and adds the design to the Tools
 * export/import. Old options are never deleted.
 *
 * @since 3.0.0
 */
final class Migration
{
    /**
     * The 2.x builder options (without the modify_login_ prefix).
     *
     * @return string[]
     * @since 3.0.0
     */
    public static function legacy_keys()
    {
        return array(
            'background_color', 'background_image', 'background_size', 'background_position', 'background_repeat', 'background_opacity',
            'logo_url', 'logo_width', 'logo_height',
            'form_background', 'form_border_radius', 'form_padding',
            'button_color', 'button_text_color',
            'custom_css',
            'label_color', 'link_color', 'link_hover_color',
        );
    }

    /**
     * On the first run after an upgrade from 2.x, carry the builder design over.
     *
     * @param string $from Previous version ('' on first run).
     * @since 3.0.0
     */
    public static function upgraded($from)
    {
        if ('' !== $from || '2.x' !== \Authlify\Install\Upgrader::get('authlify_migrated_from') || Design::exists()) {
            return;
        }

        // 2.x always linked the logo to the site and titled it with the site name.
        update_site_option('authlify_design_legacy_header', 1);

        $old = array();
        foreach (self::legacy_keys() as $key) {
            $value = get_option('modify_login_' . $key, null);
            if (null !== $value && false !== $value) {
                $old[$key] = $value;
            }
        }

        if (!$old) {
            return;
        }

        Design::save(self::map_2x($old));
    }

    /**
     * Map 2.x builder values to a design that renders the same way.
     *
     * 2.x only printed a declaration when its option was non-empty, so empty
     * values stay empty here (the WordPress default).
     *
     * @param array $old key (no prefix) => value.
     * @return array Sanitized design (enabled).
     * @since 3.0.0
     */
    public static function map_2x(array $old)
    {
        $get = function ($key) use ($old) {
            return isset($old[$key]) && is_scalar($old[$key]) ? trim((string) $old[$key]) : '';
        };
        $num = function ($value) {
            return preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/', (string) $value, $m) ? (int) round((float) $m[1]) : '';
        };

        $design = Design::defaults();
        $design['enabled'] = true;
        $design['template'] = 'legacy-2x';
        $design['layout'] = 'classic';

        // Background: the image was drawn over the colour at the chosen opacity,
        // which equals the colour laid over the image at (1 - opacity).
        $color = Color::sanitize($get('background_color'));
        $design['background']['color'] = $color;
        $image = esc_url_raw($get('background_image'));
        if ('' !== $image) {
            $design['background']['type'] = 'image';
            $design['background']['image'] = $image;
            $design['background']['size'] = in_array($get('background_size'), array('cover', 'contain', 'auto'), true) ? $get('background_size') : 'auto';
            $position = str_replace(array('top center', 'bottom center', 'center left', 'center right', 'top left', 'top right', 'bottom left', 'bottom right'), array('center top', 'center bottom', 'left center', 'right center', 'left top', 'right top', 'left bottom', 'right bottom'), strtolower($get('background_position')));
            if ('center' === $position) {
                $position = 'center center';
            }
            $design['background']['position'] = in_array($position, Design::schema()['background']['position'][2], true) ? $position : 'left top';
            $design['background']['repeat'] = in_array($get('background_repeat'), array('no-repeat', 'repeat', 'repeat-x', 'repeat-y'), true) ? $get('background_repeat') : 'repeat';
            $opacity = '' !== $get('background_opacity') && is_numeric($get('background_opacity')) ? max(0, min(1, (float) $get('background_opacity'))) : 1;
            if ($opacity < 1) {
                $design['background']['overlay'] = '' !== $color ? $color : '#f0f0f1';
                $design['background']['overlay_opacity'] = (int) round((1 - $opacity) * 100);
            }
        }

        // Logo: 2.x defaulted to 84 × 84 when a logo was set.
        $logo = esc_url_raw($get('logo_url'));
        if ('' !== $logo) {
            $design['logo']['type'] = 'image';
            $design['logo']['image'] = $logo;
            $design['logo']['width'] = '' !== $num($get('logo_width')) ? $num($get('logo_width')) : 84;
            $design['logo']['height'] = '' !== $num($get('logo_height')) ? $num($get('logo_height')) : 84;
        }
        $design['logo']['link'] = home_url();
        $design['logo']['title'] = get_bloginfo('name');

        // Form: 2.x added a light shadow whenever it styled the form.
        $form_bg = Color::sanitize($get('form_background'));
        $radius = $num($get('form_border_radius'));
        $padding = $num($get('form_padding'));
        $design['form']['background'] = $form_bg;
        $design['form']['radius'] = $radius;
        $design['form']['padding'] = $padding;
        if ('' !== $form_bg || '' !== $radius || '' !== $padding) {
            $design['form']['shadow'] = 'sm';
        }
        $design['form']['label'] = Color::sanitize($get('label_color'));

        // Button, with 2.x's hover (10 steps darker) and message accent.
        $button = Color::sanitize($get('button_color'));
        $design['button']['background'] = $button;
        $design['button']['text'] = Color::sanitize($get('button_text_color'));
        if ('' !== $button) {
            $rgba = Color::parse($button);
            $design['button']['hover_background'] = Color::format(array($rgba[0] - 10, $rgba[1] - 10, $rgba[2] - 10, 1.0));
            $design['messages']['notice'] = $button;
            $design['messages']['success'] = $button;
        }

        $design['links']['color'] = Color::sanitize($get('link_color'));
        $design['links']['hover'] = Color::sanitize($get('link_hover_color'));
        $design['custom_css'] = $get('custom_css');

        return Design::sanitize($design);
    }

    /**
     * Importers for the Tools page.
     *
     * @param array $importers Importers.
     * @return array
     * @since 3.0.0
     */
    public static function importers($importers)
    {
        $importers['loginpress-design'] = array(
            __('LoginPress: login page design (logo, background, form, button and link colours, custom CSS)', 'modify-login'),
            function () {
                return is_array(get_option('loginpress_customization'));
            },
            function () {
                return self::import(self::map_loginpress((array) get_option('loginpress_customization', array())), 'LoginPress');
            },
        );

        $importers['colorlib-design'] = array(
            __('Colorlib Login Customizer: login page design (logo, background, columns, form, fields, button and link colours, custom CSS)', 'modify-login'),
            function () {
                return is_array(get_option('clc-options'));
            },
            function () {
                return self::import(self::map_colorlib((array) get_option('clc-options', array())), 'Colorlib Login Customizer');
            },
        );

        return $importers;
    }

    /**
     * Save an imported design.
     *
     * @param array  $design Design.
     * @param string $source Source plugin name.
     * @return string Summary.
     * @since 3.0.0
     */
    private static function import(array $design, $source)
    {
        Design::save($design);

        /* translators: %s: plugin name. */
        return sprintf(__('Imported the login page design from %s. Review it in the Login designer.', 'modify-login'), $source);
    }

    /**
     * An image option that may be an attachment ID or a URL.
     *
     * @param mixed $value Value.
     * @return string
     * @since 3.0.0
     */
    private static function image($value)
    {
        if (is_numeric($value) && (int) $value > 0) {
            $url = wp_get_attachment_url((int) $value);

            return $url ? esc_url_raw($url) : '';
        }

        return is_string($value) ? esc_url_raw($value) : '';
    }

    /**
     * Map LoginPress `loginpress_customization`.
     *
     * @param array $o Options.
     * @return array Sanitized design.
     * @since 3.0.0
     */
    public static function map_loginpress(array $o)
    {
        $v = function ($key) use ($o) {
            return isset($o[$key]) && is_scalar($o[$key]) ? trim((string) $o[$key]) : '';
        };
        $n = function ($key) use ($v) {
            return preg_match('/^(\d+(?:\.\d+)?)/', $v($key), $m) ? (int) round((float) $m[1]) : '';
        };
        $on = function ($key) use ($o) {
            return isset($o[$key]) && in_array($o[$key], array(true, 1, '1', 'on', 'yes', 'true'), true);
        };
        $off = function ($key) use ($o) {
            return isset($o[$key]) && in_array($o[$key], array(false, 0, '0', 'off', 'no', 'false'), true);
        };

        $d = Design::defaults();
        $d['enabled'] = true;
        $d['template'] = 'loginpress';
        $d['layout'] = 'classic';

        $logo = self::image(isset($o['setting_logo']) ? $o['setting_logo'] : '');
        if ('' !== $logo) {
            $d['logo']['type'] = 'image';
            $d['logo']['image'] = $logo;
            $d['logo']['width'] = $n('customize_logo_width');
            $d['logo']['height'] = $n('customize_logo_height');
        }
        $d['logo']['link'] = esc_url_raw($v('customize_logo_hover'));
        $d['logo']['title'] = $v('customize_logo_hover_title');

        $d['background']['color'] = Color::sanitize($v('setting_background_color'));
        $bg = self::image(isset($o['setting_background']) ? $o['setting_background'] : '');
        if ('' !== $bg && !$off('loginpress_display_bg')) {
            $d['background']['type'] = 'image';
            $d['background']['image'] = $bg;
            $size = $v('background_image_size');
            $d['background']['size'] = in_array($size, array('cover', 'contain', 'auto'), true) ? $size : 'cover';
            $d['background']['repeat'] = in_array($v('background_repeat_radio'), array('no-repeat', 'repeat', 'repeat-x', 'repeat-y'), true) ? $v('background_repeat_radio') : 'no-repeat';
            $position = str_replace('-', ' ', $v('background_position'));
            $d['background']['position'] = in_array($position, Design::schema()['background']['position'][2], true) ? $position : 'center center';
        }

        $d['form']['background'] = Color::sanitize($v('form_background_color'));
        $d['form']['width'] = $n('customize_form_width');
        $d['form']['padding'] = $n('customize_form_padding');
        $d['form']['radius'] = $n('customize_form_radius');
        $d['form']['label'] = Color::sanitize($v('customize_form_label'));
        if ('' !== $v('customize_form_shadow') && 0 === (int) $v('customize_form_shadow')) {
            $d['form']['shadow'] = 'none';
        }

        $d['inputs']['background'] = Color::sanitize($v('textfield_background_color'));
        $d['inputs']['text'] = Color::sanitize($v('textfield_color'));
        $d['inputs']['radius'] = $n('textfield_radius');

        $d['button']['background'] = Color::sanitize($v('custom_button_color'));
        if ('' === $d['button']['background']) {
            $d['button']['background'] = Color::sanitize($v('login_button_color'));
        }
        $d['button']['hover_background'] = Color::sanitize($v('login_button_hover'));
        $d['button']['text'] = Color::sanitize($v('login_button_text_color'));
        $d['button']['radius'] = $n('login_button_radius');

        $d['links']['color'] = Color::sanitize($v('login_footer_color'));
        $d['links']['hover'] = Color::sanitize($v('login_footer_color_hover'));
        if ($off('login_back_display_bool') || $off('login_back_display')) {
            $d['links']['hide_backtoblog'] = true;
        }

        $d['text']['message'] = $v('welcome_message');
        $d['text']['button_label'] = $v('login_button_text');
        $d['text']['footer'] = $on('login_copy_right_display') ? $v('login_footer_copy_right') : '';
        $d['custom_css'] = $v('loginpress_custom_css');

        return Design::sanitize($d);
    }

    /**
     * Map Colorlib Login Customizer `clc-options`.
     *
     * @param array $o Options.
     * @return array Sanitized design.
     * @since 3.0.0
     */
    public static function map_colorlib(array $o)
    {
        $v = function ($key) use ($o) {
            return isset($o[$key]) && is_scalar($o[$key]) ? trim((string) $o[$key]) : '';
        };
        $n = function ($key) use ($v) {
            return preg_match('/^(\d+(?:\.\d+)?)/', $v($key), $m) ? (int) round((float) $m[1]) : '';
        };
        $on = function ($key) use ($o) {
            return isset($o[$key]) && in_array($o[$key], array(true, 1, '1', 'on', 'yes', 'true'), true);
        };

        $d = Design::defaults();
        $d['enabled'] = true;
        $d['template'] = 'colorlib';
        $d['layout'] = 'classic';

        if ($on('hide-logo')) {
            $d['logo']['hide'] = true;
        } elseif ($on('use-text-logo')) {
            $d['logo']['type'] = 'text';
            $d['logo']['text'] = $v('logo-title');
            $d['logo']['text_color'] = Color::sanitize($v('logo-text-color'));
            $d['logo']['text_size'] = $n('logo-text-size');
        } else {
            $logo = self::image(isset($o['custom-logo']) ? $o['custom-logo'] : '');
            if ('' !== $logo) {
                $d['logo']['type'] = 'image';
                $d['logo']['image'] = $logo;
                $d['logo']['width'] = $n('logo-width');
                $d['logo']['height'] = $n('logo-height');
            }
        }
        $d['logo']['link'] = esc_url_raw($v('logo-url'));
        if (!$on('use-text-logo')) {
            $d['logo']['title'] = $v('logo-title');
        }

        $d['background']['color'] = Color::sanitize($v('custom-background-color'));
        $bg = self::image(isset($o['custom-background']) ? $o['custom-background'] : '');
        if ('' !== $bg) {
            $d['background']['type'] = 'image';
            $d['background']['image'] = $bg;
        }

        // Two columns: the form column becomes the split panel.
        if ((int) $v('columns') === 2) {
            $d['layout'] = 'left' === $v('form-column-align') ? 'split-right' : 'split-left';
            $d['background']['panel'] = Color::sanitize($v('form-column-background-color'));
        }

        $d['form']['background'] = Color::sanitize($v('form-background-color'));
        $d['form']['width'] = $n('form-width');
        $d['form']['padding'] = $n('form-padding');
        $d['form']['radius'] = $n('form-border-radius');
        $d['form']['label'] = Color::sanitize($v('form-label-color'));
        if ('' !== $v('form-shadow') && 'none' === $v('form-shadow')) {
            $d['form']['shadow'] = 'none';
        }

        $d['inputs']['background'] = Color::sanitize($v('form-field-background'));
        $d['inputs']['text'] = Color::sanitize($v('form-field-color'));
        $d['inputs']['border'] = Color::sanitize($v('form-field-border-color'));
        $d['inputs']['radius'] = $n('form-field-border-radius');

        $d['button']['background'] = Color::sanitize($v('button-background'));
        $d['button']['hover_background'] = Color::sanitize($v('button-background-hover'));
        $d['button']['text'] = Color::sanitize($v('button-color'));

        $d['links']['color'] = Color::sanitize($v('link-color'));
        $d['links']['hover'] = Color::sanitize($v('link-color-hover'));
        if ($on('hide-extra-links')) {
            $d['links']['hide_backtoblog'] = true;
        }

        $d['text']['button_label'] = $v('login-label');
        $d['custom_css'] = $v('custom-css');

        return Design::sanitize($d);
    }

    /**
     * Add the design to the Tools export.
     *
     * @param array $data Export data.
     * @return array
     * @since 3.0.0
     */
    public static function export($data)
    {
        $data['design'] = Design::saved();

        return $data;
    }

    /**
     * Restore the design from a Tools import.
     *
     * @param array $data Imported data.
     * @since 3.0.0
     */
    public static function imported($data)
    {
        if (is_array($data) && isset($data['design']) && is_array($data['design'])) {
            Design::save($data['design']);
        }
    }
}
