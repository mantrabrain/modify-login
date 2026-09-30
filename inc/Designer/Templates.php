<?php
/**
 * Built-in design templates.
 *
 * @package Authlify
 */

namespace Authlify\Designer;

defined('ABSPATH') || exit;

/**
 * Loads the free templates from inc/Designer/templates/*.json. Each file holds
 * a partial design; missing fields take the defaults, so templates keep
 * working when the design schema grows.
 *
 * @since 3.0.0
 */
final class Templates
{
    /**
     * Template keys and translatable names, in gallery order.
     *
     * @return array key => array( name, description ).
     * @since 3.0.0
     */
    public static function names()
    {
        return array(
            'default' => array(__('Default', 'modify-login'), __('The WordPress screen, polished.', 'modify-login')),
            'minimal-light' => array(__('Minimal light', 'modify-login'), __('White, borderless and calm.', 'modify-login')),
            'minimal-dark' => array(__('Minimal dark', 'modify-login'), __('Near-black with an indigo accent.', 'modify-login')),
            'glass-photo' => array(__('Glass over photo', 'modify-login'), __('Frosted card over a night sky.', 'modify-login')),
            'split-left' => array(__('Split image left', 'modify-login'), __('Artwork left, form right.', 'modify-login')),
            'split-right' => array(__('Split image right', 'modify-login'), __('Form left, sunset right.', 'modify-login')),
            'corporate-blue' => array(__('Corporate blue', 'modify-login'), __('Blue grid, crisp white card.', 'modify-login')),
            'soft-gradient' => array(__('Soft gradient', 'modify-login'), __('Pastel gradient, rounded card.', 'modify-login')),
            'midnight' => array(__('Midnight', 'modify-login'), __('Starry sky, navy card.', 'modify-login')),
            'warm-sand' => array(__('Warm sand', 'modify-login'), __('Dunes, cream card, serif type.', 'modify-login')),
            'high-contrast' => array(__('High contrast', 'modify-login'), __('Black on white, WCAG AAA.', 'modify-login')),
            'sidebar' => array(__('Sidebar', 'modify-login'), __('Full-height sidebar over hills.', 'modify-login')),
        );
    }

    /**
     * Raw template file data.
     *
     * @param string $key Template key.
     * @return array|null
     * @since 3.0.0
     */
    public static function raw($key)
    {
        $key = sanitize_key($key);
        $file = __DIR__ . '/templates/' . $key . '.json';
        if (!is_readable($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

        return is_array($data) && isset($data['design']) && is_array($data['design']) ? $data : null;
    }

    /**
     * One template as a full, sanitized design (enabled, tagged with its key).
     *
     * @param string $key Template key.
     * @return array|null
     * @since 3.0.0
     */
    public static function get($key)
    {
        $data = self::raw($key);
        if (!$data) {
            return null;
        }

        $design = $data['design'];
        $design['template'] = sanitize_key($key);
        $design['enabled'] = true;

        return Design::from_partial($design);
    }

    /**
     * All templates for the gallery.
     *
     * @return array[] Each: key, name, description, backdrop, image, design, category,
     *                 category_label; add-ons may add badge ("Pro").
     * @since 3.0.0
     */
    public static function all()
    {
        $out = array();
        foreach (self::names() as $key => $label) {
            $design = self::get($key);
            if (!$design) {
                continue;
            }
            $raw = self::raw($key);
            $out[] = array(
                'key' => $key,
                'name' => $label[0],
                'description' => $label[1],
                'backdrop' => isset($raw['backdrop']) ? Color::sanitize($raw['backdrop']) : '',
                'image' => '' !== $design['background']['image'] ? Design::image_url($design['background']['image']) : '',
                'design' => $design,
                'category' => 'essentials',
                'category_label' => __('Essentials', 'modify-login'),
            );
        }

        /**
         * Filters the template gallery (Authlify Pro adds its templates).
         *
         * @param array[] $out Templates.
         * @since 3.0.0
         */
        return apply_filters('authlify_design_templates', $out);
    }
}
