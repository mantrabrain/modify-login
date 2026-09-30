<?php
/**
 * The login page design model.
 *
 * @package Authlify
 */

namespace Authlify\Designer;

use Authlify\Settings;

defined('ABSPATH') || exit;

/**
 * One versioned design stored as an array in the `authlify_design` option
 * (a network option when Authlify is network-activated).
 *
 * Every field is declared in schema() and sanitized by type. An empty value
 * means "keep the WordPress default", so a design only changes what it sets.
 * Designs carry a version and are upgraded by migrate(), so a plugin update
 * never changes a published design.
 *
 * @since 3.0.0
 */
final class Design
{
    const OPTION = 'authlify_design';
    const VERSION = 1;
    const DRAFT = 'authlify_design_draft_';

    /**
     * Allowed layouts. "classic" keeps WordPress's own placement and sizing and
     * only changes colours and images (used for designs migrated from 2.x).
     *
     * @return string[]
     * @since 3.0.0
     */
    public static function layouts()
    {
        return array('center', 'split-left', 'split-right', 'sidebar-left', 'sidebar-right', 'full', 'classic');
    }

    /**
     * Field definitions: section => field => array( type, default [, extra] ).
     *
     * Types: color, int (extra: min, max), choice (extra: list), url, image
     * (url or a bundled plugin:images/… file), id, bool, text, html, family, faces.
     *
     * @return array
     * @since 3.0.0
     */
    public static function schema()
    {
        $colors = function (array $keys) {
            $out = array();
            foreach ($keys as $key) {
                $out[$key] = array('color', '');
            }

            return $out;
        };

        $schema = array(
            'tokens' => array_merge(
                $colors(array('primary', 'on_primary', 'background', 'surface', 'text', 'muted', 'border')),
                array('radius' => array('int', '', array(0, 40)))
            ),
            'background' => array_merge(
                array(
                    'type' => array('choice', 'color', array('color', 'gradient', 'image')),
                    'gradient_angle' => array('int', 135, array(0, 360)),
                    'image' => array('image', ''),
                    'image_id' => array('id', 0),
                    'size' => array('choice', 'cover', array('cover', 'contain', 'auto')),
                    'position' => array('choice', 'center center', array('center center', 'center top', 'center bottom', 'left center', 'left top', 'left bottom', 'right center', 'right top', 'right bottom')),
                    'repeat' => array('choice', 'no-repeat', array('no-repeat', 'repeat', 'repeat-x', 'repeat-y')),
                    'overlay_opacity' => array('int', 0, array(0, 100)),
                    'mobile_image' => array('image', ''),
                    'mobile_image_id' => array('id', 0),
                ),
                $colors(array('color', 'gradient_from', 'gradient_to', 'overlay', 'panel', 'text'))
            ),
            'logo' => array(
                'type' => array('choice', 'wordpress', array('wordpress', 'image', 'text', 'site-icon')),
                'image' => array('image', ''),
                'image_id' => array('id', 0),
                'text' => array('text', ''),
                'text_color' => array('color', ''),
                'text_size' => array('int', '', array(12, 72)),
                'width' => array('int', '', array(16, 400)),
                'height' => array('int', '', array(16, 400)),
                'link' => array('url', ''),
                'title' => array('text', ''),
                'hide' => array('bool', false),
            ),
            'form' => array_merge(
                array(
                    'card' => array('choice', 'form', array('form', 'box')),
                    'width' => array('int', '', array(280, 640)),
                    'padding' => array('int', '', array(0, 80)),
                    'radius' => array('int', '', array(0, 40)),
                    'border_width' => array('int', '', array(0, 8)),
                    'shadow' => array('choice', '', array('', 'none', 'sm', 'md', 'lg')),
                    'blur' => array('int', 0, array(0, 40)),
                ),
                $colors(array('background', 'border_color', 'text', 'label'))
            ),
            'inputs' => array_merge(
                $colors(array('background', 'text', 'border', 'focus')),
                array(
                    'radius' => array('int', '', array(0, 40)),
                    'size' => array('int', '', array(12, 28)),
                )
            ),
            'button' => array_merge(
                $colors(array('background', 'text', 'hover_background', 'hover_text')),
                array(
                    'radius' => array('int', '', array(0, 40)),
                    'full_width' => array('bool', false),
                )
            ),
            'links' => array_merge(
                $colors(array('color', 'hover')),
                array(
                    'hide_backtoblog' => array('bool', false),
                    'hide_language' => array('bool', false),
                    'hide_privacy' => array('bool', false),
                )
            ),
            'messages' => $colors(array('error', 'notice', 'success', 'background', 'text')),
            'text' => array(
                'font' => array('choice', '', array_keys(self::fonts())),
                'size' => array('int', '', array(11, 22)),
                'custom_family' => array('family', ''),
                'faces' => array('faces', array()),
                'message' => array('html', ''),
                'button_label' => array('text', ''),
                'footer' => array('html', ''),
            ),
        );

        /**
         * Filters the design schema (Authlify Pro adds fields here).
         *
         * @param array $schema section => field => array( type, default [, extra] ).
         * @since 3.0.0
         */
        return apply_filters('authlify_design_schema', $schema);
    }

    /**
     * Curated fonts: key => array( label, CSS stack, bundled file or '' ).
     *
     * System stacks load nothing. Bundled fonts are OFL files in
     * assets/designer/fonts, served from this site. No CDN requests.
     *
     * @return array
     * @since 3.0.0
     */
    public static function fonts()
    {
        return array(
            '' => array(__('WordPress default', 'modify-login'), '', ''),
            'system' => array(__('System UI', 'modify-login'), 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif', ''),
            'humanist' => array(__('Humanist (system)', 'modify-login'), 'Seravek, "Gill Sans Nova", Ubuntu, Calibri, "DejaVu Sans", source-sans-pro, sans-serif', ''),
            'geometric' => array(__('Geometric (system)', 'modify-login'), 'Avenir, "Avenir Next LT Pro", Montserrat, Corbel, "URW Gothic", source-sans-pro, sans-serif', ''),
            'rounded' => array(__('Rounded (system)', 'modify-login'), 'ui-rounded, "Hiragino Maru Gothic ProN", Quicksand, Comfortaa, Manjari, "Arial Rounded MT", "Arial Rounded MT Bold", Calibri, source-sans-pro, sans-serif', ''),
            'transitional' => array(__('Serif (system)', 'modify-login'), 'Charter, "Bitstream Charter", "Sitka Text", Cambria, serif', ''),
            'old-style' => array(__('Old style serif (system)', 'modify-login'), '"Iowan Old Style", "Palatino Linotype", "URW Palladio L", P052, serif', ''),
            'mono' => array(__('Monospace (system)', 'modify-login'), 'ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, "DejaVu Sans Mono", monospace', ''),
            'inter' => array(__('Inter (bundled)', 'modify-login'), '"Authlify Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', 'inter.woff2'),
            'nunito' => array(__('Nunito (bundled)', 'modify-login'), '"Authlify Nunito", ui-rounded, system-ui, sans-serif', 'nunito.woff2'),
            'space-grotesk' => array(__('Space Grotesk (bundled)', 'modify-login'), '"Authlify Space Grotesk", system-ui, sans-serif', 'space-grotesk.woff2'),
            'lora' => array(__('Lora (bundled serif)', 'modify-login'), '"Authlify Lora", Charter, Cambria, Georgia, serif', 'lora.woff2'),
            'theme' => array(__('Theme font', 'modify-login'), '', ''),
        );
    }

    /**
     * The default (empty) design: the core WordPress look, not applied.
     *
     * @return array
     * @since 3.0.0
     */
    public static function defaults()
    {
        $design = array(
            'version' => self::VERSION,
            'enabled' => false,
            'template' => '',
            'layout' => 'center',
            'custom_css' => '',
        );

        foreach (self::schema() as $section => $fields) {
            $design[$section] = array();
            foreach ($fields as $key => $def) {
                $design[$section][$key] = $def[1];
            }
        }

        return $design;
    }

    /**
     * Upgrade an older design to the current version.
     *
     * @param array $design Design.
     * @return array
     * @since 3.0.0
     */
    public static function migrate(array $design)
    {
        $version = isset($design['version']) ? (int) $design['version'] : 1;

        /**
         * Filters a stored design while it is upgraded to the current version.
         *
         * @param array $design  Design.
         * @param int   $version Its version.
         * @since 3.0.0
         */
        $design = apply_filters('authlify_design_migrate', $design, $version);
        $design['version'] = self::VERSION;

        return $design;
    }

    /**
     * Sanitize a full design. Unknown keys are dropped, missing keys get defaults.
     *
     * @param mixed $input    Raw design.
     * @param array $warnings Filled with human-readable notes about removed input.
     * @return array
     * @since 3.0.0
     */
    public static function sanitize($input, &$warnings = array())
    {
        $input = is_array($input) ? self::migrate($input) : array();
        $defaults = self::defaults();
        $clean = array(
            'version' => self::VERSION,
            'enabled' => !empty($input['enabled']) && filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN),
            'template' => isset($input['template']) ? sanitize_key($input['template']) : '',
            'layout' => isset($input['layout']) && in_array($input['layout'], self::layouts(), true) ? $input['layout'] : 'center',
            'custom_css' => self::sanitize_css(isset($input['custom_css']) ? $input['custom_css'] : '', $warnings),
        );

        foreach (self::schema() as $section => $fields) {
            $raw = isset($input[$section]) && is_array($input[$section]) ? $input[$section] : array();
            $clean[$section] = array();
            foreach ($fields as $key => $def) {
                $clean[$section][$key] = array_key_exists($key, $raw) ? self::sanitize_field($raw[$key], $def) : $defaults[$section][$key];
            }
        }

        return $clean;
    }

    /**
     * Sanitize one field.
     *
     * @param mixed $value Value.
     * @param array $def   Definition.
     * @return mixed
     * @since 3.0.0
     */
    public static function sanitize_field($value, array $def)
    {
        switch ($def[0]) {
            case 'color':
                return Color::sanitize($value);

            case 'int':
                if ('' === $value || null === $value || !is_numeric($value)) {
                    return '' === $def[1] ? '' : (int) $def[1];
                }
                list($min, $max) = $def[2];

                return (int) max($min, min($max, round((float) $value)));

            case 'choice':
                return in_array($value, $def[2], true) ? $value : $def[1];

            case 'url':
                return is_string($value) ? esc_url_raw(trim($value), array('http', 'https')) : '';

            case 'image':
                return self::sanitize_image($value);

            case 'id':
                return max(0, (int) $value);

            case 'bool':
                return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'text':
                return is_scalar($value) ? mb_substr(sanitize_text_field((string) $value), 0, 200) : '';

            case 'html':
                return is_scalar($value) ? mb_substr(trim(wp_kses((string) $value, self::allowed_html())), 0, 2000) : '';

            case 'family':
                return self::sanitize_family($value);

            case 'faces':
                return self::sanitize_faces($value);
        }

        return '';
    }

    /**
     * Tags allowed in the login message and footer text.
     *
     * @return array
     * @since 3.0.0
     */
    public static function allowed_html()
    {
        return array(
            'a' => array('href' => true, 'title' => true, 'target' => true, 'rel' => true),
            'strong' => array(),
            'em' => array(),
            'b' => array(),
            'i' => array(),
            'br' => array(),
            'span' => array(),
        );
    }

    /**
     * An image reference: an http(s) URL or a bundled plugin:images/name.svg.
     *
     * @param mixed $value Value.
     * @return string
     * @since 3.0.0
     */
    public static function sanitize_image($value)
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if (preg_match('#^plugin:images/[a-z0-9-]+\.(svg|png|jpg|webp)$#', $value)) {
            return $value;
        }

        return esc_url_raw($value, array('http', 'https'));
    }

    /**
     * Resolve an image reference to a URL.
     *
     * @param string $value Stored value.
     * @return string
     * @since 3.0.0
     */
    public static function image_url($value)
    {
        if (0 === strpos((string) $value, 'plugin:images/')) {
            return AUTHLIFY_URL . 'assets/designer/images/' . substr($value, 14);
        }

        return (string) $value;
    }

    /**
     * A CSS font-family list: names, quotes, commas, spaces and hyphens only.
     *
     * @param mixed $value Value.
     * @return string
     * @since 3.0.0
     */
    public static function sanitize_family($value)
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('/[^a-zA-Z0-9 ,\'"_-]/', '', $value);

        return substr(trim($value), 0, 200);
    }

    /**
     * Font faces from the active theme: local files only (same host as the site).
     *
     * @param mixed $value Faces.
     * @return array
     * @since 3.0.0
     */
    public static function sanitize_faces($value)
    {
        if (!is_array($value)) {
            return array();
        }

        $clean = array();
        foreach (array_slice($value, 0, 8) as $face) {
            if (!is_array($face) || empty($face['src'])) {
                continue;
            }
            $src = esc_url_raw((string) $face['src'], array('http', 'https'));
            if ('' === $src || !self::is_local_url($src) || !preg_match('/\.(woff2?|ttf|otf)(\?.*)?$/i', $src)) {
                continue;
            }
            $weight = isset($face['weight']) ? preg_replace('/[^0-9 ]/', '', (string) $face['weight']) : '400';
            $clean[] = array(
                'family' => isset($face['family']) ? self::sanitize_family($face['family']) : '',
                'src' => $src,
                'weight' => '' !== trim($weight) ? trim($weight) : '400',
                'style' => isset($face['style']) && 'italic' === $face['style'] ? 'italic' : 'normal',
            );
        }

        return $clean;
    }

    /**
     * Whether a URL points at this site.
     *
     * @param string $url URL.
     * @return bool
     * @since 3.0.0
     */
    public static function is_local_url($url)
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $hosts = array_filter(array(
            wp_parse_url(home_url(), PHP_URL_HOST),
            wp_parse_url(site_url(), PHP_URL_HOST),
            wp_parse_url(content_url(), PHP_URL_HOST),
        ));

        return $host && in_array(strtolower($host), array_map('strtolower', $hosts), true);
    }

    /**
     * Custom CSS: tags stripped; @import, expression(), behaviours and
     * anything that could close the style element are removed.
     *
     * @param mixed $css      CSS.
     * @param array $warnings Notes about removed parts.
     * @return string
     * @since 3.0.0
     */
    public static function sanitize_css($css, &$warnings = array())
    {
        if (!is_string($css)) {
            return '';
        }

        if (preg_match('#<\s*/?\s*[a-z!]#i', $css)) {
            $warnings[] = __('Removed HTML tags from the custom CSS (it must be CSS only).', 'modify-login');
        }
        $css = wp_strip_all_tags($css);

        // CSS escapes (\69mport, \65xpression) would hide the dangerous words
        // from the checks below. When the decoded text holds one of them,
        // continue with the decoded text, so nothing hides behind an escape.
        $decoded = self::decode_css_escapes($css);
        $dangerous = '#@import|expression\s*\(|-moz-binding|behavior\s*:|javascript\s*:|vbscript\s*:|<\s*/?\s*style|<!--|-->#i';
        if ($decoded !== $css && preg_match($dangerous, $decoded)) {
            $css = $decoded;
        }

        // Whole declarations (property: value;) are removed, not just the
        // word, so no half-rule is left behind.
        $decl = '[^;{}]*';
        $patterns = array(
            '#<\s*/?\s*style#i' => __('an HTML style tag', 'modify-login'),
            '#@import[^;{}]*;?#i' => __('@import rules (load fonts and files from your own CSS file instead)', 'modify-login'),
            '#' . $decl . 'expression\s*\(' . $decl . ';?#i' => __('expression()', 'modify-login'),
            '#' . $decl . '(-moz-binding|behavior)\s*:' . $decl . ';?#i' => __('behaviour bindings', 'modify-login'),
            '#' . $decl . '(javascript|vbscript)\s*:' . $decl . ';?#i' => __('javascript: URLs', 'modify-login'),
            '#<!--|-->#' => __('HTML comments', 'modify-login'),
        );

        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $css)) {
                $css = preg_replace($pattern, '', $css);
                /* translators: %s: what was removed. */
                $warnings[] = sprintf(__('Removed %s from the custom CSS.', 'modify-login'), $label);
            }
        }

        // A tag could have been reassembled by the removals above.
        $css = str_replace('<', '', $css);

        return mb_substr(trim($css), 0, 20000);
    }

    /**
     * Whether designs are stored network-wide.
     *
     * @return bool
     * @since 3.0.0
     */
    private static function network()
    {
        return Settings::is_network();
    }

    /**
     * Decode CSS escapes (`\69` or `\i`) so checks see the real characters.
     *
     * @param string $css CSS.
     * @return string
     * @since 3.0.0
     */
    private static function decode_css_escapes($css)
    {
        $css = (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', function ($m) {
            $code = hexdec($m[1]);

            if ($code <= 0 || $code >= 0x110000) {
                return '';
            }

            return function_exists('mb_chr') ? (string) mb_chr($code, 'UTF-8') : ($code < 128 ? chr($code) : '?');
        }, $css);

        return (string) preg_replace('/\\\\(.)/su', '$1', $css);
    }

    /**
     * The saved design (sanitized, with defaults).
     *
     * @return array
     * @since 3.0.0
     */
    public static function saved()
    {
        $stored = self::network() ? get_site_option(self::OPTION, array()) : get_option(self::OPTION, array());

        return self::sanitize(is_array($stored) ? $stored : array());
    }

    /**
     * Whether a design has been stored at all.
     *
     * @return bool
     * @since 3.0.0
     */
    public static function exists()
    {
        $stored = self::network() ? get_site_option(self::OPTION, null) : get_option(self::OPTION, null);

        return is_array($stored);
    }

    /**
     * Save a design.
     *
     * @param array $design   Design.
     * @param array $warnings Notes about removed input.
     * @return array The saved design.
     * @since 3.0.0
     */
    public static function save(array $design, &$warnings = array())
    {
        $old = self::saved();
        $clean = self::sanitize($design, $warnings);

        // Keep sections no loaded module declares (e.g. Authlify Pro's effects
        // while Pro is inactive), so a save never erases an add-on's design data.
        $stored = self::network() ? get_site_option(self::OPTION, array()) : get_option(self::OPTION, array());
        if (is_array($stored)) {
            $known = array_merge(array_keys(self::schema()), array('version', 'enabled', 'template', 'layout', 'custom_css'));
            foreach ($stored as $key => $value) {
                if (is_array($value) && !in_array($key, $known, true) && !array_key_exists($key, $clean)) {
                    $clean[$key] = $value;
                }
            }
        }

        if (self::network()) {
            update_site_option(self::OPTION, $clean);
        } else {
            update_option(self::OPTION, $clean, false);
        }

        Compiler::purge();

        /**
         * Fires after the login design was saved.
         *
         * @param array $clean New design.
         * @param array $old   Previous design.
         * @since 3.0.0
         */
        do_action('authlify_design_saved', $clean, $old);

        return $clean;
    }

    /**
     * A user's unsaved draft, or null.
     *
     * @param int $user_id User ID.
     * @return array|null
     * @since 3.0.0
     */
    public static function draft($user_id)
    {
        $draft = self::network() ? get_site_transient(self::DRAFT . (int) $user_id) : get_transient(self::DRAFT . (int) $user_id);

        return is_array($draft) ? self::sanitize($draft) : null;
    }

    /**
     * Store a user's draft for the live preview (one day).
     *
     * @param int   $user_id  User ID.
     * @param array $design   Design.
     * @param array $warnings Notes about removed input.
     * @return array The sanitized draft.
     * @since 3.0.0
     */
    public static function set_draft($user_id, array $design, &$warnings = array())
    {
        $clean = self::sanitize($design, $warnings);
        if (self::network()) {
            set_site_transient(self::DRAFT . (int) $user_id, $clean, DAY_IN_SECONDS);
        } else {
            set_transient(self::DRAFT . (int) $user_id, $clean, DAY_IN_SECONDS);
        }

        return $clean;
    }

    /**
     * Discard a user's draft.
     *
     * @param int $user_id User ID.
     * @since 3.0.0
     */
    public static function delete_draft($user_id)
    {
        if (self::network()) {
            delete_site_transient(self::DRAFT . (int) $user_id);
        } else {
            delete_transient(self::DRAFT . (int) $user_id);
        }
    }

    /**
     * Merge a partial design (template, import) over the defaults and sanitize.
     *
     * @param array $partial Partial design.
     * @return array
     * @since 3.0.0
     */
    public static function from_partial(array $partial)
    {
        $design = self::defaults();
        foreach ($partial as $key => $value) {
            if (is_array($value) && isset($design[$key]) && is_array($design[$key])) {
                $design[$key] = array_merge($design[$key], $value);
            } else {
                $design[$key] = $value;
            }
        }

        return self::sanitize($design);
    }
}
