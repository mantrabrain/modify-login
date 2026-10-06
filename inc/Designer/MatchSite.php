<?php
/**
 * "Match my site": build a design from the active theme.
 *
 * @package Authlify
 */

namespace Authlify\Designer;

defined('ABSPATH') || exit;

/**
 * Reads the active theme's global settings and styles (theme.json and the
 * user's Site Editor changes): palette, body font, button radius, plus the
 * site logo or icon. Classic themes fall back to their custom logo,
 * background colour and editor palette. Only local font files are used.
 *
 * @since 3.0.0
 */
final class MatchSite
{
    /**
     * Palette: slug => colour.
     *
     * @var array
     */
    private static $palette = array();

    /**
     * CSS custom properties the theme defines: name (without --) => colour.
     *
     * @var array
     */
    private static $vars = array();

    /**
     * WordPress core's own global styles (so core defaults are never taken for the theme's).
     *
     * @var array
     */
    private static $core = array();

    /**
     * Build a design from the theme.
     *
     * @return array array( design, found, missing ): what was used, and what fell back to a default.
     * @since 3.0.0
     */
    public static function build()
    {
        // Without a theme.json, global styles are only core's defaults, not the theme's look.
        $has_json = function_exists('wp_theme_has_theme_json') ? wp_theme_has_theme_json() : wp_is_block_theme();
        $styles = $has_json && function_exists('wp_get_global_styles') ? (array) wp_get_global_styles() : array();
        $settings = function_exists('wp_get_global_settings') ? (array) wp_get_global_settings() : array();
        self::$core = self::core_styles();
        $found = array();
        $missing = array();

        // Classic and hybrid themes keep their colours in options and CSS custom properties.
        $roles = self::theme_roles();
        self::$vars = array_merge(self::stylesheet_vars(), self::$vars);
        self::$palette = self::palette($settings);
        if (self::$palette) {
            /* translators: %d: number of colours. */
            $found[] = sprintf(_n('color palette (%d color)', 'color palette (%d colors)', count(self::$palette), 'modify-login'), count(self::$palette));
        }

        // Background and text.
        $bg = self::theme_color($styles, array('color', 'background'));
        if ('' === $bg && !empty($roles['background'])) {
            $bg = $roles['background'];
        }
        if ('' === $bg) {
            $bg = self::first_slug(array('base', 'background', 'base-2', 'white'));
        }
        if ('' === $bg && get_theme_mod('background_color')) {
            $bg = Color::sanitize('#' . ltrim((string) get_theme_mod('background_color'), '#'));
        }
        if ('' === $bg || Color::parse($bg)[3] < 1) {
            $bg = '#ffffff';
            $missing[] = __('background', 'modify-login');
        } else {
            /* translators: %s: colour. */
            $found[] = sprintf(__('background %s', 'modify-login'), $bg);
        }

        $text = self::theme_color($styles, array('color', 'text'));
        if ('' === $text && !empty($roles['text'])) {
            $text = $roles['text'];
        }
        if ('' === $text) {
            $text = self::first_slug(array('contrast', 'foreground', 'text', 'black', 'dark'));
        }
        if ('' === $text) {
            $missing[] = __('text color', 'modify-login');
        } else {
            /* translators: %s: colour. */
            $found[] = sprintf(__('text %s', 'modify-login'), $text);
        }
        if ('' === $text || Color::contrast($text, $bg) < 4.5) {
            $text = Color::ensure_contrast('' !== $text ? $text : Color::readable_on($bg), $bg, 7);
        }

        $dark = Color::is_dark($bg);
        $surface = $dark ? Color::mix($bg, $text, 0.07) : $bg;
        $page = $dark ? $bg : Color::mix($bg, $text, 0.045);

        // Primary: the theme's brand colour, its button, an accent palette colour, then its link colour.
        $source = isset($roles['source']) ? $roles['source'] : '';
        $candidates = array(
            /* translators: 1: colour, 2: theme name. */
            array(isset($roles['primary']) ? $roles['primary'] : '', __('accent %1$s (%2$s global colors)', 'modify-login')),
            /* translators: %s: colour. */
            array(self::theme_color($styles, array('elements', 'button', 'color', 'background')), __('accent %s (theme button color)', 'modify-login')),
            /* translators: %s: colour. */
            array(self::theme_color($styles, array('blocks', 'core/button', 'color', 'background')), __('accent %s (theme button color)', 'modify-login')),
            /* translators: %s: colour. */
            array(self::first_slug(array('primary', 'accent', 'accent-1', 'brand', 'secondary', 'accent-2')), __('accent %s (theme palette)', 'modify-login')),
            /* translators: %s: colour. */
            array(self::theme_color($styles, array('elements', 'link', 'color', 'text')), __('accent %s (theme link color)', 'modify-login')),
            /* translators: %s: colour. */
            array(self::classic_primary(), __('accent %s (theme stylesheet)', 'modify-login')),
        );
        $primary = '';
        foreach ($candidates as $candidate) {
            list($value, $label) = $candidate;
            if ('' !== $value && Color::parse($value)[3] >= 1 && Color::contrast($value, $surface) >= 1.4) {
                $primary = $value;
                $found[] = sprintf($label, $value, $source);
                break;
            }
        }
        if ('' === $primary) {
            $primary = $dark ? '#8ab4ff' : '#3858e9';
            $missing[] = __('accent color', 'modify-login');
        }

        $on_primary = self::theme_color($styles, array('elements', 'button', 'color', 'text'));
        if ('' === $on_primary || Color::contrast($on_primary, $primary) < 4.5) {
            $on_primary = Color::readable_on($primary);
        }
        if (Color::contrast($on_primary, $primary) < 4.5) {
            $primary = Color::ensure_contrast($primary, $on_primary, 4.5);
        }

        $link = self::theme_color($styles, array('elements', 'link', 'color', 'text'));
        if ('' === $link && !empty($roles['link'])) {
            $link = $roles['link'];
        }
        $link = Color::ensure_contrast('' !== $link ? $link : $primary, $surface, 4.5);
        $muted = Color::ensure_contrast(Color::mix($text, $surface, 0.3), $page, 4.5);
        $muted = Color::ensure_contrast($muted, $surface, 4.5);
        $border = Color::ensure_contrast(Color::mix($surface, $text, 0.35), $surface, 3);

        // Radius.
        $radius = self::length(self::theme_value($styles, array('elements', 'button', 'border', 'radius')));
        if (null === $radius) {
            $radius = self::length(self::theme_value($styles, array('blocks', 'core/button', 'border', 'radius')));
        }
        if (null !== $radius) {
            $found[] = __('button radius', 'modify-login');
        }
        $button_radius = null === $radius ? 8 : min(40, $radius);
        $card_radius = null === $radius ? 12 : max(6, min(24, $radius > 30 ? 20 : $radius + 4));

        $design = array(
            'enabled' => true,
            'template' => 'match-site',
            'layout' => 'center',
            'tokens' => array(
                'primary' => $primary,
                'on_primary' => $on_primary,
                'background' => $page,
                'surface' => $surface,
                'text' => $text,
                'muted' => $muted,
                'border' => $border,
                'radius' => min(12, $button_radius),
            ),
            'background' => array('type' => 'color', 'color' => $page, 'text' => $muted),
            'form' => array('card' => 'box', 'width' => 400, 'padding' => 32, 'radius' => $card_radius, 'shadow' => $dark ? 'lg' : 'md', 'border_width' => 1, 'border_color' => Color::mix($surface, $text, 0.1)),
            'inputs' => array('size' => 16, 'background' => $dark ? Color::mix($surface, $bg, 0.6) : '#ffffff', 'border' => $border, 'focus' => $primary, 'radius' => min(12, $button_radius)),
            'button' => array('radius' => $button_radius, 'full_width' => true),
            'links' => array('color' => $link, 'hover' => $text),
            'messages' => array(
                'error' => Color::ensure_contrast('#cc1818', $surface, 3),
                'notice' => $primary,
                'success' => Color::ensure_contrast('#008a20', $surface, 3),
                'background' => $surface,
                'text' => $text,
            ),
            'text' => array('font' => 'system', 'size' => 15),
            'logo' => self::logo($text),
        );

        $font = self::font($styles, $settings);
        if ($font) {
            $design['text']['font'] = 'theme';
            $design['text']['custom_family'] = $font['family'];
            $design['text']['faces'] = $font['faces'];
            $found[] = $font['faces'] ? __('font (served from your theme)', 'modify-login') : __('font', 'modify-login');
        }

        if ('text' !== $design['logo']['type']) {
            $found[] = 'site-icon' === $design['logo']['type'] ? __('site icon', 'modify-login') : __('logo', 'modify-login');
        }

        /**
         * Filters the design built by "Match my site".
         *
         * @param array $design Partial design.
         * @since 3.0.0
         */
        $design = apply_filters('authlify_design_match_site', $design);

        return array(
            'design' => Design::from_partial($design),
            'found' => $found,
            'missing' => $missing,
        );
    }

    /**
     * WordPress core's own theme.json styles.
     *
     * @return array
     * @since 3.0.0
     */
    private static function core_styles()
    {
        if (!class_exists('\WP_Theme_JSON_Resolver') || !method_exists('\WP_Theme_JSON_Resolver', 'get_core_data')) {
            return array();
        }
        $raw = \WP_Theme_JSON_Resolver::get_core_data()->get_raw_data();

        return isset($raw['styles']) && is_array($raw['styles']) ? $raw['styles'] : array();
    }

    /**
     * A global style value that the theme (or the user) set, not one of core's defaults.
     *
     * @param array $styles Merged global styles.
     * @param array $path   Keys.
     * @return mixed|null
     * @since 3.0.0
     */
    private static function theme_value(array $styles, array $path)
    {
        $value = self::dig($styles, $path);
        if (null !== $value && $value === self::dig(self::$core, $path)) {
            return null;
        }

        return $value;
    }

    /**
     * A colour from the theme's global styles (core defaults ignored).
     *
     * @param array $styles Merged global styles.
     * @param array $path   Keys.
     * @return string
     * @since 3.0.0
     */
    private static function theme_color(array $styles, array $path)
    {
        return self::color(self::theme_value($styles, $path));
    }

    /**
     * Brand colours of popular classic and hybrid themes, which keep them in
     * their own settings and print them as CSS custom properties (so their
     * theme.json palettes only hold `var(--…)` references). Also fills
     * self::$vars with those properties.
     *
     * @return array { primary, text, background, link, source } (any may be missing).
     * @since 3.0.0
     */
    private static function theme_roles()
    {
        self::$vars = array();
        $roles = array();
        $template = get_template();

        // Astra: astra-settings → global-color-palette, printed as --ast-global-color-N.
        if ('astra' === $template) {
            $palette = array();
            if (function_exists('astra_get_option')) {
                $option = astra_get_option('global-color-palette');
                $palette = is_array($option) && isset($option['palette']) ? (array) $option['palette'] : array();
            }
            if (!$palette) {
                $settings = get_option('astra-settings', array());
                $palette = is_array($settings) && isset($settings['global-color-palette']['palette']) ? (array) $settings['global-color-palette']['palette'] : array();
            }
            if (!$palette && class_exists('\Astra_Global_Palette') && method_exists('\Astra_Global_Palette', 'get_default_color_palette')) {
                $defaults = \Astra_Global_Palette::get_default_color_palette();
                $current = isset($defaults['currentPalette']) ? $defaults['currentPalette'] : 'palette_1';
                $palette = isset($defaults['palettes'][$current]) ? (array) $defaults['palettes'][$current] : array();
            }
            $palette = array_values(array_map(array(__CLASS__, 'color'), $palette));
            foreach ($palette as $i => $color) {
                if ('' !== $color) {
                    self::$vars['ast-global-color-' . $i] = $color;
                }
            }
            if ($palette) {
                // 0 brand, 1 alternate brand, 2 headings, 3 text, 4 and 5 backgrounds.
                $reorganized = class_exists('\Astra_Dynamic_CSS') && method_exists('\Astra_Dynamic_CSS', 'astra_4_8_9_compatibility') ? \Astra_Dynamic_CSS::astra_4_8_9_compatibility() : true;
                $roles = array(
                    'primary' => isset($palette[0]) ? $palette[0] : '',
                    'link' => isset($palette[0]) ? $palette[0] : '',
                    'text' => isset($palette[2]) ? $palette[2] : (isset($palette[3]) ? $palette[3] : ''),
                    'background' => isset($palette[$reorganized ? 4 : 5]) ? $palette[$reorganized ? 4 : 5] : '',
                    'source' => 'Astra',
                );
            }
        }

        // GeneratePress: generate_settings → global_colors (slug => colour), printed as --slug.
        if ('generatepress' === $template) {
            $colors = function_exists('generate_get_option') ? generate_get_option('global_colors') : null;
            if (!is_array($colors)) {
                $settings = get_option('generate_settings', array());
                $colors = is_array($settings) && isset($settings['global_colors']) ? $settings['global_colors'] : array();
            }
            if (!$colors && function_exists('generate_get_defaults')) {
                $defaults = generate_get_defaults();
                $colors = isset($defaults['global_colors']) ? $defaults['global_colors'] : array();
            }
            foreach ((array) $colors as $entry) {
                if (is_array($entry) && isset($entry['slug'], $entry['color']) && '' !== self::color($entry['color'])) {
                    self::$vars[sanitize_key($entry['slug'])] = self::color($entry['color']);
                }
            }
            $roles = array(
                'primary' => isset(self::$vars['accent']) ? self::$vars['accent'] : '',
                'text' => isset(self::$vars['contrast']) ? self::$vars['contrast'] : '',
                'background' => isset(self::$vars['base-3']) ? self::$vars['base-3'] : '',
                'source' => 'GeneratePress',
            );
        }

        // Kadence: kadence_global_palette (JSON), printed as --global-paletteN.
        if ('kadence' === $template) {
            $json = json_decode((string) get_option('kadence_global_palette', ''), true);
            if (is_array($json)) {
                $active = isset($json['active']) && is_string($json['active']) ? $json['active'] : 'palette';
                $list = isset($json[$active]) && is_array($json[$active]) ? $json[$active] : (isset($json['palette']) ? (array) $json['palette'] : array());
                foreach ($list as $i => $entry) {
                    $color = is_array($entry) && isset($entry['color']) ? self::color($entry['color']) : '';
                    if ('' !== $color) {
                        self::$vars['global-palette' . ($i + 1)] = $color;
                    }
                }
                $roles = array(
                    'primary' => isset(self::$vars['global-palette1']) ? self::$vars['global-palette1'] : '',
                    'text' => isset(self::$vars['global-palette3']) ? self::$vars['global-palette3'] : '',
                    'background' => isset(self::$vars['global-palette9']) ? self::$vars['global-palette9'] : '',
                    'source' => 'Kadence',
                );
            }
        }

        // Blocksy: the colorPalette theme mod, printed as --theme-palette-color-N.
        if ('blocksy' === $template) {
            $palette = get_theme_mod('colorPalette', array());
            if (is_array($palette)) {
                for ($i = 1; $i <= 8; $i++) {
                    $color = isset($palette['color' . $i]['color']) ? self::color($palette['color' . $i]['color']) : '';
                    if ('' !== $color) {
                        self::$vars['theme-palette-color-' . $i] = $color;
                    }
                }
                $roles = array(
                    'primary' => isset(self::$vars['theme-palette-color-1']) ? self::$vars['theme-palette-color-1'] : '',
                    'text' => isset(self::$vars['theme-palette-color-4']) ? self::$vars['theme-palette-color-4'] : '',
                    'background' => isset(self::$vars['theme-palette-color-8']) ? self::$vars['theme-palette-color-8'] : '',
                    'source' => 'Blocksy',
                );
            }
        }

        /**
         * Filters the brand colours "Match my site" reads from the theme.
         *
         * @param array $roles { primary, text, background, link, source } hex colours.
         * @param string $template Parent theme folder.
         * @since 3.0.0
         */
        $roles = (array) apply_filters('authlify_match_site_theme_colors', $roles, $template);
        foreach (array('primary', 'text', 'background', 'link') as $key) {
            $roles[$key] = isset($roles[$key]) ? Color::sanitize((string) $roles[$key]) : '';
        }
        $roles['source'] = isset($roles['source']) ? sanitize_text_field((string) $roles['source']) : '';
        if ('' === $roles['source']) {
            $theme = wp_get_theme();
            $roles['source'] = $theme->exists() ? (string) $theme->get('Name') : __('Theme', 'modify-login');
        }

        return $roles;
    }

    /**
     * Colour custom properties defined in the theme's style.css (`--name: #hex`).
     *
     * @return array name => colour.
     * @since 3.0.0
     */
    private static function stylesheet_vars()
    {
        $vars = array();
        $files = array_unique(array(get_stylesheet_directory() . '/style.css', get_template_directory() . '/style.css'));
        foreach ($files as $file) {
            if (!is_readable($file) || filesize($file) > 1024 * 1024) {
                continue;
            }
            $css = (string) file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
            if (preg_match_all('/--([a-z0-9_-]+)\s*:\s*(#[0-9a-f]{3,8}|rgba?\([^)]*\))\s*[;}]/i', $css, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $name = strtolower($match[1]);
                    $color = Color::sanitize($match[2]);
                    if ('' !== $color && !isset($vars[$name])) {
                        $vars[$name] = $color;
                    }
                }
            }
        }

        return $vars;
    }

    /**
     * Theme and user palette: slug => colour. Entries stored as `var(--…)`
     * (Astra, GeneratePress, Kadence…) are resolved from the theme's settings.
     *
     * @param array $settings Global settings.
     * @return array
     * @since 3.0.0
     */
    private static function palette(array $settings)
    {
        $palette = array();
        $origins = self::dig($settings, array('color', 'palette'));
        if (!is_array($origins)) {
            return $palette;
        }

        foreach (array('theme', 'custom') as $origin) {
            if (empty($origins[$origin]) || !is_array($origins[$origin])) {
                continue;
            }
            foreach ($origins[$origin] as $entry) {
                if (!isset($entry['slug'], $entry['color'])) {
                    continue;
                }
                $color = self::color($entry['color']);
                if ('' !== $color) {
                    $palette[$entry['slug']] = $color;
                }
            }
        }

        return $palette;
    }

    /**
     * Resolve a style value (preset reference, custom property or literal) to a colour.
     *
     * @param mixed $value Value.
     * @return string
     * @since 3.0.0
     */
    private static function color($value)
    {
        if (!is_string($value) || '' === $value) {
            return '';
        }
        $value = trim($value);

        if (preg_match('/^var:preset\|color\|([a-z0-9_-]+)$/i', $value, $m) || preg_match('/^var\(\s*--wp--preset--color--([a-z0-9_-]+)\s*\)$/i', $value, $m)) {
            return isset(self::$palette[$m[1]]) ? self::$palette[$m[1]] : '';
        }

        // var(--name) or var(--name, fallback).
        if (preg_match('/^var\(\s*--([a-z0-9_-]+)\s*(?:,\s*(.+))?\)$/i', $value, $m)) {
            $name = strtolower($m[1]);
            if (isset(self::$vars[$name])) {
                return self::$vars[$name];
            }

            return isset($m[2]) ? self::color($m[2]) : '';
        }

        $color = Color::sanitize($value);
        if ('' === $color && preg_match('/^#?[0-9a-f]{3,6}$/i', $value)) {
            $color = Color::sanitize('#' . ltrim($value, '#'));
        }

        return 'transparent' === $color ? '' : $color;
    }

    /**
     * First palette colour among slugs.
     *
     * @param string[] $slugs Slugs.
     * @return string
     * @since 3.0.0
     */
    private static function first_slug(array $slugs)
    {
        foreach ($slugs as $slug) {
            if (isset(self::$palette[$slug])) {
                return self::$palette[$slug];
            }
        }

        return '';
    }

    /**
     * Classic themes: a --primary / --accent / --brand custom property in style.css.
     *
     * @return string
     * @since 3.0.0
     */
    private static function classic_primary()
    {
        if (wp_is_block_theme()) {
            return '';
        }

        $file = get_stylesheet_directory() . '/style.css';
        if (!is_readable($file) || filesize($file) > 1024 * 1024) {
            return '';
        }

        $css = (string) file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
        if (preg_match('/--[a-z0-9-]*(primary|accent|brand)[a-z0-9-]*\s*:\s*(#[0-9a-f]{3,8})\b/i', $css, $m)) {
            return Color::sanitize($m[2]);
        }

        return '';
    }

    /**
     * A CSS length in px (px, rem, em; 9999px pills become 40).
     *
     * @param mixed $value Value (string, or per-corner array).
     * @return int|null
     * @since 3.0.0
     */
    private static function length($value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        if (preg_match('/^([\d.]+)\s*(px|rem|em)?$/', trim((string) $value), $m)) {
            $px = (float) $m[1] * (isset($m[2]) && in_array($m[2], array('rem', 'em'), true) ? 16 : 1);

            return (int) min(40, round($px));
        }

        return null;
    }

    /**
     * The theme's body font: family plus local font files.
     *
     * @param array $styles   Global styles.
     * @param array $settings Global settings.
     * @return array|null array( family, faces ).
     * @since 3.0.0
     */
    private static function font(array $styles, array $settings)
    {
        $value = self::dig($styles, array('typography', 'fontFamily'));
        if (!is_string($value) || '' === $value) {
            return null;
        }

        $families = array();
        $origins = self::dig($settings, array('typography', 'fontFamilies'));
        if (is_array($origins)) {
            foreach (array('theme', 'custom') as $origin) {
                if (!empty($origins[$origin]) && is_array($origins[$origin])) {
                    foreach ($origins[$origin] as $family) {
                        if (isset($family['slug'])) {
                            $families[$family['slug']] = $family;
                        }
                    }
                }
            }
        }

        $entry = null;
        if (preg_match('/^var:preset\|font-family\|([a-z0-9_-]+)$/i', $value, $m) || preg_match('/var\(--wp--preset--font-family--([a-z0-9_-]+)\)/i', $value, $m)) {
            $entry = isset($families[$m[1]]) ? $families[$m[1]] : null;
            if (!$entry) {
                return null;
            }
            $stack = isset($entry['fontFamily']) ? (string) $entry['fontFamily'] : '';
        } else {
            $stack = $value;
        }

        $stack = Design::sanitize_family($stack);
        if ('' === $stack) {
            return null;
        }

        $faces = array();
        if ($entry && !empty($entry['fontFace']) && is_array($entry['fontFace'])) {
            foreach ($entry['fontFace'] as $face) {
                if (!empty($face['fontStyle']) && 'normal' !== $face['fontStyle']) {
                    continue;
                }
                $src = self::local_src(isset($face['src']) ? $face['src'] : '');
                if ('' === $src) {
                    continue;
                }
                $faces[] = array(
                    'family' => isset($face['fontFamily']) ? trim((string) $face['fontFamily'], '"\' ') : '',
                    'src' => $src,
                    'weight' => isset($face['fontWeight']) ? (string) $face['fontWeight'] : '400',
                    'style' => 'normal',
                );
                if (count($faces) >= 4) {
                    break;
                }
            }
            $faces = Design::sanitize_faces($faces);
        }

        // A web font the theme names but does not ship cannot be used (no CDN).
        if ($entry && !empty($entry['fontFace']) && !$faces) {
            return null;
        }

        return array('family' => $stack, 'faces' => $faces);
    }

    /**
     * A local font file URL from a theme.json src (string or list), woff2 preferred.
     *
     * @param mixed $src Source(s).
     * @return string
     * @since 3.0.0
     */
    private static function local_src($src)
    {
        $list = is_array($src) ? $src : array($src);
        usort($list, function ($a, $b) {
            return (int) (false === stripos((string) $a, '.woff2')) - (int) (false === stripos((string) $b, '.woff2'));
        });

        foreach ($list as $item) {
            $item = (string) $item;
            if (0 === strpos($item, 'file:./')) {
                $url = get_theme_file_uri(substr($item, 7));
            } else {
                $url = $item;
            }
            if (Design::is_local_url($url) && preg_match('/\.(woff2?|ttf|otf)(\?.*)?$/i', $url)) {
                return $url;
            }
        }

        return '';
    }

    /**
     * Logo: site logo, custom logo, site icon, or the site name as text.
     *
     * @param string $text Text colour.
     * @return array
     * @since 3.0.0
     */
    private static function logo($text)
    {
        $id = (int) get_option('site_logo');
        if (!$id) {
            $id = (int) get_theme_mod('custom_logo');
        }

        if ($id) {
            $image = wp_get_attachment_image_src($id, 'medium');
            if ($image) {
                list($url, $w, $h) = $image;
                $w = max(1, (int) $w);
                $h = max(1, (int) $h);
                $height = min(80, $h);
                $width = (int) round($w * $height / $h);
                if ($width > 280) {
                    $width = 280;
                    $height = (int) round($h * 280 / $w);
                }

                return array(
                    'type' => 'image',
                    'image' => $url,
                    'image_id' => $id,
                    'width' => max(16, $width),
                    'height' => max(16, $height),
                );
            }
        }

        if (get_site_icon_url()) {
            return array('type' => 'site-icon', 'width' => 72, 'height' => 72);
        }

        return array('type' => 'text', 'text_size' => 28, 'text_color' => $text);
    }

    /**
     * Read a nested key without falling back to the whole array.
     *
     * @param array $array Array.
     * @param array $path  Keys.
     * @return mixed|null
     * @since 3.0.0
     */
    private static function dig($array, array $path)
    {
        foreach ($path as $key) {
            if (!is_array($array) || !array_key_exists($key, $array)) {
                return null;
            }
            $array = $array[$key];
        }

        return $array;
    }
}
