<?php
/**
 * Compiles a design to login-page CSS.
 *
 * @package Authlify
 */

namespace Authlify\Designer;

defined('ABSPATH') || exit;

/**
 * Turns a design into one scoped stylesheet for wp-login.php.
 *
 * Every rule is prefixed with `body.login.authlify-designed` (and `#login`
 * where core uses an ID), so core, admin-colour and plugin CSS cannot
 * out-rank it. Values are also exposed as `--al-*` custom properties for
 * custom CSS. Only what the design sets is emitted, so an empty field keeps
 * the WordPress look. The result is cached as a file in uploads/authlify/.
 *
 * @since 3.0.0
 */
final class Compiler
{
    const P = 'body.login.authlify-designed';
    const DIR = 'authlify';

    /**
     * Resolve a design to concrete values, filling blanks from its tokens.
     *
     * @param array $d Sanitized design.
     * @return array
     * @since 3.0.0
     */
    public static function resolve(array $d)
    {
        $t = $d['tokens'];
        $pick = function () {
            foreach (func_get_args() as $value) {
                if ('' !== $value && null !== $value) {
                    return $value;
                }
            }

            return '';
        };

        $split = in_array($d['layout'], array('split-left', 'split-right'), true);
        $sidebar = in_array($d['layout'], array('sidebar-left', 'sidebar-right'), true);
        $box = 'box' === $d['form']['card'] || 'full' === $d['layout'];

        $r = array(
            'classic' => 'classic' === $d['layout'],
            'split' => $split,
            'sidebar' => $sidebar,
            'box' => $box,
            'page_bg' => $pick($d['background']['color'], $t['background']),
            'panel' => $pick($d['background']['panel'], $split ? $t['background'] : $t['surface'], $t['background']),
            'page_text' => $pick($d['background']['text'], $t['muted'], $t['text']),
            'card_bg' => $pick($d['form']['background'], $t['surface']),
            'card_text' => $pick($d['form']['text'], $t['text']),
            'card_border' => $pick($d['form']['border_color'], $t['border']),
            'card_radius' => $pick($d['form']['radius'], $t['radius']),
            'input_bg' => $pick($d['inputs']['background'], $t['surface']),
            'input_text' => $pick($d['inputs']['text'], $t['text']),
            'input_border' => $pick($d['inputs']['border'], $t['border']),
            'input_radius' => $pick($d['inputs']['radius'], $t['radius']),
            'focus' => $pick($d['inputs']['focus'], $t['primary']),
            'btn_bg' => $pick($d['button']['background'], $t['primary']),
            'btn_text' => $pick($d['button']['text'], $t['on_primary']),
            'btn_radius' => $pick($d['button']['radius'], $t['radius']),
            'msg_bg' => $pick($d['messages']['background'], $t['surface']),
            'msg_text' => $pick($d['messages']['text'], $t['text']),
            'msg_notice' => $pick($d['messages']['notice'], $t['primary']),
        );

        $r['label'] = $pick($d['form']['label'], $r['card_text']);
        $r['btn_hover'] = $pick($d['button']['hover_background'], '' !== $r['btn_bg'] ? Color::shade($r['btn_bg'], Color::is_dark($r['btn_bg']) ? -0.14 : -0.08) : '');
        $r['btn_hover_text'] = $pick($d['button']['hover_text'], $r['btn_text']);
        $r['link'] = $pick($d['links']['color'], $box ? $pick($t['muted'], $t['text']) : $r['page_text']);
        $r['link_hover'] = $pick($d['links']['hover'], $r['link']);
        $r['width'] = $pick($d['form']['width'], $box ? 400 : '');
        $r['padding'] = $d['form']['padding'];

        return $r;
    }

    /**
     * Compile a design to CSS.
     *
     * @param array $d Sanitized design.
     * @return string
     * @since 3.0.0
     */
    public static function compile(array $d)
    {
        $p = self::P;
        $r = self::resolve($d);
        $css = array('/* Authlify login design v' . (int) $d['version'] . ' */');

        $css[] = self::font_faces($d);

        // Custom properties, for custom CSS and add-ons.
        $vars = array(
            '--al-primary' => $r['btn_bg'],
            '--al-on-primary' => $r['btn_text'],
            '--al-page' => $r['page_bg'],
            '--al-page-text' => $r['page_text'],
            '--al-panel' => $r['panel'],
            '--al-surface' => $r['card_bg'],
            '--al-text' => $r['card_text'],
            '--al-label' => $r['label'],
            '--al-border' => $r['card_border'],
            '--al-link' => $r['link'],
            '--al-focus' => $r['focus'],
            '--al-radius' => self::px($r['card_radius']),
            '--al-form-width' => self::px($r['width']),
        );
        $css[] = self::rule($p, $vars);

        // Page.
        $family = self::family($d);
        $size = $d['text']['size'];
        $css[] = self::rule($p, array('font-family' => $family, 'font-size' => self::px($size)));
        if ($r['classic']) {
            // WordPress's own placement; only the width changes when set.
            if ('' !== $r['width']) {
                $css[] = $p . ' #login{width:min(' . (int) $r['width'] . 'px,100%);box-sizing:border-box}';
            }
        } else {
            $css[] = self::page_css($r);
        }

        if ('' !== $size) {
            $css[] = self::rule($p . ' #login label,' . $p . ' #login form p,' . $p . ' #login .message p,' . $p . ' #login .notice p,' . $p . ' #login #nav,' . $p . ' #login #backtoblog', array(
                'font-size' => self::px($size),
            ));
            $css[] = self::rule($p . ' #login label', array('font-size' => self::px((int) $size + 1)));
        }

        $css[] = self::layout_css($d, $r);
        $css[] = self::logo_css($d, $r);
        $css[] = self::card_css($d, $r);
        $css[] = self::field_css($d, $r);
        $css[] = self::button_css($d, $r);
        $css[] = self::link_css($d, $r);
        $css[] = self::message_css($d, $r);
        $css[] = self::interim_css($d, $r);

        if ($d['links']['hide_backtoblog']) {
            $css[] = $p . ' #backtoblog{display:none}';
        }
        if ($d['links']['hide_privacy']) {
            $css[] = $p . ' .privacy-policy-page-link{display:none}';
        }
        if ($d['links']['hide_language']) {
            $css[] = $p . ' .language-switcher{display:none}';
        }

        // Extra elements added by the designer.
        $css[] = self::rule($p . ' .authlify-intro', array('margin' => '0 0 16px', 'text-align' => 'center', 'color' => $r['box'] ? $r['card_text'] : $r['page_text'], 'line-height' => '1.5'));
        $css[] = $p . ' .authlify-intro p{margin:0;font-size:1.1em}';
        $css[] = self::rule($p . ' .authlify-footer-text', array('margin' => '8px auto 0', 'text-align' => 'center', 'font-size' => '12px', 'color' => $r['page_text'], 'line-height' => '1.5', 'padding' => '0 16px'));
        $css[] = self::rule($p . ' .authlify-footer-text a', array('color' => $r['page_text']));

        // Third-party buttons (social, passkeys) stay visible and neutral.
        $css[] = $p . ' #login form .button:not(.button-primary):not(.wp-hide-pw){max-width:100%;white-space:normal}';

        $css[] = self::rule($p . ' .language-switcher label', array('color' => $r['page_text']));

        $css = implode("\n", array_filter($css));

        if ('' !== $d['custom_css']) {
            $css .= "\n/* Custom CSS */\n" . $d['custom_css'];
        }

        /**
         * Filters the compiled login CSS.
         *
         * @param string $css    CSS.
         * @param array  $design Design.
         * @since 3.0.0
         */
        return (string) apply_filters('authlify_design_css', $css, $d);
    }

    /**
     * Page grid: content centred in one column.
     *
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function page_css(array $r)
    {
        $p = self::P;
        $css = array();
        $css[] = $p . '{min-height:100vh;min-height:100dvh;height:auto;box-sizing:border-box;display:grid;grid-template-columns:minmax(0,1fr);align-content:center;justify-items:center;padding:32px 16px}';
        $css[] = $p . ' #login{box-sizing:border-box;margin:0 auto;padding:0;width:min(' . ('' !== $r['width'] ? (int) $r['width'] . 'px' : '320px') . ',100%);max-width:100%;position:relative;z-index:1}';
        $css[] = $p . ' .language-switcher,' . $p . ' .authlify-footer-text{position:relative;z-index:1;max-width:100%}';
        $css[] = $p . '.login-action-confirm_admin_email #login{width:min(650px,100%)}';

        return implode("\n", $css);
    }

    /**
     * Layout rules: centred, split, sidebar, full; phones collapse to a centred card.
     *
     * @param array $d Design.
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function layout_css(array $d, array $r)
    {
        $p = self::P;
        $css = array();
        $bg = self::background($d, false);
        $mobile_bg = '' !== $d['background']['mobile_image'] ? self::background($d, true) : array();
        $art = $p . ' .authlify-art';

        if ($r['split']) {
            $left = 'split-left' === $d['layout'];
            $css[] = self::rule($p, array('background' => $r['panel'] ? $r['panel'] : '#f0f0f1', 'padding-' . ($left ? 'left' : 'right') => 'calc(50% + 16px)'));
            $css[] = self::rule($art, array_merge(array(
                'position' => 'fixed',
                'top' => '0',
                'bottom' => '0',
                ($left ? 'left' : 'right') => '0',
                'width' => '50%',
                'z-index' => '0',
            ), $bg));
        } elseif ($r['sidebar']) {
            $left = 'sidebar-left' === $d['layout'];
            $width = '' !== $r['width'] ? max(360, (int) $r['width'] + 96) : 440;
            $css[] = self::rule($p, array_merge($bg, array('padding-' . ($left ? 'right' : 'left') => 'max(16px, calc(100% - ' . $width . 'px + 16px))')));
            $css[] = self::rule($art, array(
                'position' => 'fixed',
                'top' => '0',
                'bottom' => '0',
                ($left ? 'left' : 'right') => '0',
                'width' => 'min(' . $width . 'px, 100%)',
                'background' => $r['panel'] ? $r['panel'] : '#ffffff',
                'box-shadow' => '0 0 40px rgba(0, 0, 0, 0.18)',
                'z-index' => '0',
            ));
        } else {
            $css[] = self::rule($p, $bg);
        }

        if ($r['classic']) {
            return implode("\n", array_filter(array_merge($css, array($mobile_bg ? '@media screen and (max-width: 782px){' . self::rule($p, $mobile_bg) . '}' : ''))));
        }

        // Phones and small tablets: one centred column, no side panels.
        $mobile = array();
        $mobile[] = $p . '{padding:24px 16px}';
        $mobile[] = $art . '{display:none}';
        if ($mobile_bg) {
            $mobile[] = self::rule($p, $mobile_bg);
        } elseif ($r['split'] && $r['box'] && ('image' === $d['background']['type'] || 'gradient' === $d['background']['type'])) {
            // Split layouts drop the art panel on phones; a boxed card can sit on the artwork instead.
            $mobile[] = self::rule($p, self::background($d, false));
        }
        if ($r['sidebar']) {
            // The layout class out-ranks the card rules that follow.
            $mobile[] = self::rule($p . '.authlify-layout-' . $d['layout'] . ' #login', array(
                'background' => $r['panel'] ? $r['panel'] : '#ffffff',
                'padding' => '24px 20px',
                'border-radius' => '12px',
                'box-shadow' => '0 10px 30px rgba(0, 0, 0, 0.2)',
            ));
            $mobile[] = self::rule($p . '.authlify-layout-' . $d['layout'] . ' .language-switcher,' . $p . '.authlify-layout-' . $d['layout'] . ' .authlify-footer-text', array('background' => $r['panel'] ? $r['panel'] : '#ffffff', 'padding' => '8px 12px', 'border-radius' => '8px', 'margin-top' => '12px'));
        }
        $css[] = '@media screen and (max-width: 782px){' . implode('', array_filter($mobile)) . '}';
        $css[] = '@media screen and (max-height: 550px){' . $p . '{align-content:start}}';
        if ($r['box'] || $r['sidebar']) {
            // Room for 300px-wide widgets (CAPTCHAs) on 360px phones.
            $css[] = '@media screen and (max-width: 480px){' . $p . ' #login{padding-left:20px;padding-right:20px}}';
        }

        return implode("\n", array_filter($css));
    }

    /**
     * Background declarations for the page (or the split image panel).
     *
     * @param array $d      Design.
     * @param bool  $mobile Use the mobile image.
     * @return array
     * @since 3.0.0
     */
    public static function background(array $d, $mobile = false)
    {
        $b = $d['background'];
        $layers = array();
        $sizes = array();
        $positions = array();
        $repeats = array();

        if ('' !== $b['overlay'] && $b['overlay_opacity'] > 0 && ('image' === $b['type'] || $mobile)) {
            $overlay = Color::alpha($b['overlay'], $b['overlay_opacity']);
            $layers[] = 'linear-gradient(' . $overlay . ', ' . $overlay . ')';
            $sizes[] = 'auto';
            $positions[] = '0 0';
            $repeats[] = 'no-repeat';
        }

        $image = $mobile ? $b['mobile_image'] : $b['image'];
        if (('image' === $b['type'] || $mobile) && '' !== $image) {
            $layers[] = 'url("' . esc_url_raw(Design::image_url($image)) . '")';
            $sizes[] = $b['size'];
            $positions[] = $b['position'];
            $repeats[] = $b['repeat'];
        } elseif ('gradient' === $b['type'] && '' !== $b['gradient_from'] && '' !== $b['gradient_to']) {
            $layers[] = 'linear-gradient(' . (int) $b['gradient_angle'] . 'deg, ' . $b['gradient_from'] . ', ' . $b['gradient_to'] . ')';
            $sizes[] = 'auto';
            $positions[] = '0 0';
            $repeats[] = 'no-repeat';
        }

        $color = '' !== $b['color'] ? $b['color'] : $d['tokens']['background'];
        $out = array('background-color' => $color);
        if ($layers) {
            $out['background-image'] = implode(', ', $layers);
            $out['background-size'] = implode(', ', $sizes);
            $out['background-position'] = implode(', ', $positions);
            $out['background-repeat'] = implode(', ', $repeats);
            $out['background-attachment'] = 'scroll';
        }

        return $out;
    }

    /**
     * Logo rules.
     *
     * @param array $d Design.
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function logo_css(array $d, array $r)
    {
        $p = self::P . ' #login h1.wp-login-logo';
        $logo = $d['logo'];

        if ($logo['hide']) {
            return $p . '{display:none}';
        }

        $css = array();
        $url = '';
        if ('image' === $logo['type'] && '' !== $logo['image']) {
            $url = Design::image_url($logo['image']);
        } elseif ('site-icon' === $logo['type']) {
            $url = (string) get_site_icon_url(256);
        }

        if ('' !== $url) {
            $w = '' !== $logo['width'] ? (int) $logo['width'] : 84;
            $h = '' !== $logo['height'] ? (int) $logo['height'] : 84;
            $classic = $r['classic'] && 'image' === $logo['type'];
            $css[] = self::rule($p . ' a', array(
                'background-image' => 'url("' . esc_url_raw($url) . '")',
                'background-size' => $classic ? '' : 'contain',
                'background-position' => $classic ? '' : 'center',
                'background-repeat' => 'no-repeat',
                'width' => $w . 'px',
                'height' => $h . 'px',
                'max-width' => '100%',
                'margin' => $classic ? '' : '0 auto 24px',
                'border-radius' => 'site-icon' === $logo['type'] ? '18%' : '',
            ));
        } elseif ('text' === $logo['type']) {
            $color = '' !== $logo['text_color'] ? $logo['text_color'] : ($r['box'] ? $r['card_text'] : $r['page_text']);
            $css[] = self::rule($p . ' a', array(
                'background' => 'none',
                'text-indent' => '0',
                'width' => 'auto',
                'height' => 'auto',
                'overflow' => 'visible',
                'color' => $color,
                'font-size' => self::px('' !== $logo['text_size'] ? $logo['text_size'] : 28),
                'font-weight' => '700',
                'line-height' => '1.2',
                'letter-spacing' => '-0.01em',
                'text-decoration' => 'none',
                'margin' => '0 auto 24px',
                'white-space' => 'normal',
                'word-wrap' => 'break-word',
                'text-wrap' => 'balance',
            ));
            $css[] = self::rule($p . ' a:hover,' . $p . ' a:focus', array('color' => $color));
            $size = '' !== $logo['text_size'] ? (int) $logo['text_size'] : 28;
            if ($size > 24) {
                $css[] = '@media screen and (max-width: 480px){' . $p . ' a{font-size:' . max(22, (int) round($size * 0.82)) . 'px}}';
            }
        } elseif ('' !== $logo['width'] || '' !== $logo['height']) {
            $w = '' !== $logo['width'] ? (int) $logo['width'] : 84;
            $h = '' !== $logo['height'] ? (int) $logo['height'] : 84;
            $css[] = self::rule($p . ' a', array('width' => $w . 'px', 'height' => $h . 'px', 'background-size' => 'contain', 'max-width' => '100%'));
        }

        return implode("\n", $css);
    }

    /**
     * Card rules: the form (core look) or the whole #login box.
     *
     * @param array $d Design.
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function card_css(array $d, array $r)
    {
        $p = self::P;
        $f = $d['form'];
        $shadows = array(
            'none' => 'none',
            'sm' => '0 1px 3px rgba(0, 0, 0, 0.13)',
            'md' => '0 8px 24px rgba(15, 23, 42, 0.12)',
            'lg' => '0 24px 60px rgba(15, 23, 42, 0.28)',
        );
        $card = array(
            'background' => 'full' === $d['layout'] && '' === $f['background'] ? 'transparent' : $r['card_bg'],
            'color' => $r['card_text'],
            'border-radius' => self::px($r['card_radius']),
            'padding' => '' !== $r['padding'] ? (int) $r['padding'] . 'px' : ($r['box'] ? '32px 28px' : ''),
            'box-shadow' => '' !== $f['shadow'] ? $shadows[$f['shadow']] : ($r['box'] && 'full' !== $d['layout'] ? $shadows['md'] : ''),
            'border' => '' !== $f['border_width'] ? ((int) $f['border_width'] > 0 ? (int) $f['border_width'] . 'px solid ' . ('' !== $r['card_border'] ? $r['card_border'] : 'currentColor') : '0') : ('' !== $r['card_border'] && !$r['box'] ? '1px solid ' . $r['card_border'] : ''),
        );
        if ($f['blur'] > 0) {
            $card['-webkit-backdrop-filter'] = 'blur(' . (int) $f['blur'] . 'px) saturate(140%)';
            $card['backdrop-filter'] = 'blur(' . (int) $f['blur'] . 'px) saturate(140%)';
        }

        $css = array();
        $forms = $p . ' #login form:not(#language-switcher)';

        if ($r['box']) {
            if ('full' === $d['layout'] && '' === $f['shadow']) {
                $card['box-shadow'] = 'none';
            }
            $css[] = self::rule($p . ' #login', $card);
            $css[] = $forms . '{background:transparent;border:0;box-shadow:none;padding:0;margin:16px 0 0;overflow:visible}';
            $css[] = $p . ' #login #nav,' . $p . ' #login #backtoblog{padding:0;text-align:center}';
            $css[] = $p . ' #login #nav{margin:20px 0 0}' . $p . ' #login #backtoblog{margin:8px 0 0}';
            $css[] = $p . ' #login .privacy-policy-page-link{margin:16px 0 0}';
            $css[] = $p . ' #login .message,' . $p . ' #login .notice,' . $p . ' #login .success{margin-bottom:12px}';
        } else {
            $css[] = self::rule($forms, $card);
        }

        $css[] = self::rule($p . ' #login form label,' . $p . ' #login form .forgetmenot label,' . $p . ' #login .admin-email__heading', array('color' => $r['label']));
        $css[] = self::rule($p . ' #login form,' . $p . ' #login form p,' . $p . ' #login form .description,' . $p . ' #login form .indicator-hint,' . $p . ' #login #reg_passmail', array('color' => $r['card_text']));
        if ('' !== $r['card_text']) {
            $css[] = self::rule($p . ' #login .admin-email__heading', array('border-bottom-color' => Color::alpha($r['card_text'], 20)));
        }

        return implode("\n", array_filter($css));
    }

    /**
     * Input rules.
     *
     * @param array $d Design.
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function field_css(array $d, array $r)
    {
        $p = self::P . ' #login';
        $inputs = implode(',', array_map(function ($s) use ($p) {
            return $p . ' ' . $s;
        }, array('form .input', 'input[type="text"]', 'input[type="password"]', 'input[type="email"]', 'input[type="number"]', 'input[type="tel"]', 'input[type="url"]', 'select', 'textarea')));
        $size = $d['inputs']['size'];

        $css = array();
        $css[] = self::rule($inputs, array(
            'background-color' => $r['input_bg'],
            'color' => $r['input_text'],
            'border-color' => $r['input_border'],
            'border-radius' => self::px($r['input_radius']),
            'font-size' => self::px($size),
            'line-height' => '' !== $size ? '1.4' : '',
            'padding' => '' !== $size ? '8px 12px' : '',
            'min-height' => '' !== $size ? '44px' : '',
            'box-shadow' => '' !== $r['input_border'] ? 'none' : '',
        ));
        if ('' !== $size) {
            $css[] = self::P . '.js #login input.password-input{padding-right:2.75rem}';
            $css[] = $p . ' .button.wp-hide-pw{height:44px;min-height:44px}';
        }
        if ('' !== $r['focus']) {
            $focus = implode(',', array_map(function ($s) {
                return $s . ':focus';
            }, explode(',', $inputs)));
            $css[] = self::rule($focus, array(
                'border-color' => $r['focus'],
                'box-shadow' => '0 0 0 1px ' . $r['focus'],
                'outline' => '2px solid transparent',
            ));
            $css[] = self::rule($p . ' input[type="checkbox"]:focus,' . $p . ' .button.wp-hide-pw:focus', array('border-color' => $r['focus'], 'box-shadow' => '0 0 0 1px ' . $r['focus']));
        }
        if ('' !== $r['input_text']) {
            $css[] = self::rule($p . ' input::placeholder', array('color' => Color::alpha($r['input_text'], 60)));
            $css[] = self::rule($p . ' .button.wp-hide-pw,' . $p . ' .button.wp-hide-pw:hover', array('color' => $r['input_text']));
        }
        $css[] = self::rule($p . ' input[type="checkbox"]', array(
            'background-color' => $r['input_bg'],
            'border-color' => $r['input_border'],
            'accent-color' => $r['classic'] ? '' : $r['btn_bg'],
        ));
        if ('' !== $r['input_radius']) {
            $css[] = $p . ' input[type="checkbox"]{border-radius:' . min(4, (int) $r['input_radius']) . 'px}';
        }

        $css[] = self::strength_css($d, $r);
        $css[] = self::tooltip_css($r);

        return implode("\n", array_filter($css));
    }

    /**
     * Reset-password screen: the strength meter as a slim bar in the design's
     * colours (instead of core's pastel box), and the Generate button sized
     * like the other buttons.
     *
     * @param array $d Design.
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function strength_css(array $d, array $r)
    {
        if ($r['classic']) {
            return '';
        }

        $p = self::P . ' #login';
        $m = $d['messages'];
        $text = '' !== $r['card_text'] ? $r['card_text'] : '#3c434a';
        $track = Color::alpha($text, 16);
        $levels = array(
            'short' => array('' !== $m['error'] ? $m['error'] : '#d63638', 25),
            'bad' => array('' !== $m['error'] ? $m['error'] : '#d63638', 50),
            'good' => array('#dba617', 75),
            'strong' => array('' !== $m['success'] ? $m['success'] : '#00a32a', 100),
        );

        $css = array();
        $css[] = $p . ' #pass-strength-result{background:transparent;border:0;margin:8px 0 16px;padding:0;text-align:left;font-size:12px;font-weight:600;line-height:1.4;color:' . $text . ';opacity:1}';
        $css[] = $p . ' #pass-strength-result::before{content:"";display:block;height:4px;margin:0 0 6px;border-radius:2px;background:' . $track . '}';
        foreach ($levels as $class => $level) {
            $css[] = $p . ' #pass-strength-result.' . $class . '{background:transparent;border:0}';
            $css[] = $p . ' #pass-strength-result.' . $class . '::before{background:linear-gradient(90deg,' . $level[0] . ' ' . $level[1] . '%,' . $track . ' ' . $level[1] . '%)}';
        }
        $css[] = $p . ' .reset-pass-submit{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between}';
        $css[] = $p . ' .reset-pass-submit .button{float:none;margin:0}';
        if ($d['button']['full_width']) {
            $css[] = $p . ' .reset-pass-submit .button{flex:1 1 100%;width:100%}';
        }

        return implode("\n", $css);
    }

    /**
     * The "?" help toggle next to "Remember Me" (WordPress 7.1+) and its bubble.
     *
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function tooltip_css(array $r)
    {
        $p = self::P . ' #login';
        $css = array();
        if ('' !== $r['label']) {
            $css[] = self::rule($p . ' .wp-tooltip .wp-tooltip__toggle', array('color' => Color::alpha($r['label'], 80), 'border-radius' => '50%'));
        }
        if ('' !== $r['focus']) {
            $css[] = self::rule($p . ' .wp-tooltip .wp-tooltip__toggle:hover,' . $p . ' .wp-tooltip .wp-tooltip__toggle:focus,' . $p . ' .wp-tooltip .wp-tooltip__close:hover,' . $p . ' .wp-tooltip .wp-tooltip__close:focus', array('color' => $r['focus']));
            $css[] = self::rule($p . ' .wp-tooltip .wp-tooltip__toggle:focus,' . $p . ' .wp-tooltip .wp-tooltip__close:focus', array('box-shadow' => '0 0 0 2px ' . $r['focus'], 'outline' => '2px solid transparent'));
        }
        if ('' !== $r['msg_bg'] || '' !== $r['msg_text']) {
            $bubble = '' !== $r['msg_bg'] ? Color::over($r['msg_bg'], '#ffffff') : '';
            $css[] = self::rule($p . ' .wp-tooltip:not(.wp-is-tooltip) .wp-tooltip__bubble', array(
                'background' => $bubble,
                'color' => $r['msg_text'],
                'border-color' => '' !== $r['card_border'] ? $r['card_border'] : '',
                'border-radius' => '' !== $r['card_radius'] ? min(10, (int) $r['card_radius']) . 'px' : '',
            ));
            if ('' !== $bubble) {
                $css[] = $p . ' .wp-tooltip:not(.wp-is-tooltip) .wp-tooltip__bubble::after{border-top-color:' . $bubble . '}';
            }
            if ('' !== $r['msg_text']) {
                $css[] = $p . ' .wp-tooltip .wp-tooltip__close{color:' . $r['msg_text'] . '}';
            }
        }

        return implode("\n", array_filter($css));
    }

    /**
     * Button rules.
     *
     * @param array $d Design.
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function button_css(array $d, array $r)
    {
        $p = self::P . ' #login';
        $primary = $p . ' .button-primary,' . $p . ' input[type="submit"].button-primary,' . $p . ' #wp-submit';
        $css = array();

        $polish = '' !== $r['btn_bg'] && !$r['classic'];
        $css[] = self::rule($primary, array(
            'background' => $r['btn_bg'],
            'border-color' => $r['btn_bg'],
            'color' => $r['btn_text'],
            'border-radius' => self::px($r['btn_radius']),
            'text-shadow' => '' !== $r['btn_bg'] || '' !== $r['btn_text'] ? 'none' : '',
            'box-shadow' => $polish ? 'none' : '',
            'font-weight' => $polish ? '600' : '',
            'min-height' => $polish ? '44px' : '',
            'padding' => $polish ? '0 20px' : '',
            'font-size' => $polish ? '14px' : '',
        ));
        // Hover and focus use the same selectors as the base rule plus the
        // state, so they always win (a lower-specificity focus rule lost to
        // "input[type=submit].button-primary" and left some buttons without a
        // visible focus ring).
        $css[] = self::rule(self::states($primary, array(':hover', ':focus')), array(
            'background' => $r['btn_hover'],
            'border-color' => $r['btn_hover'],
            'color' => $r['btn_hover_text'],
        ));
        if ($polish) {
            $css[] = self::rule(self::states($primary, array(':focus')), array(
                'box-shadow' => '0 0 0 2px ' . ('' !== $r['card_bg'] ? Color::over($r['card_bg'], '' !== $r['page_bg'] ? $r['page_bg'] : '#ffffff') : '#ffffff') . ', 0 0 0 4px ' . ('' !== $r['focus'] ? $r['focus'] : $r['btn_bg']),
                'outline' => '2px solid transparent',
            ));
        }

        // Secondary buttons (generate password, third-party buttons): neutral.
        $css[] = self::rule($p . ' .button:not(.button-primary):not(.wp-hide-pw)', array(
            'color' => '' !== $r['input_bg'] ? $r['input_text'] : $r['card_text'],
            'border-color' => $r['input_border'],
            'background' => '' !== $r['input_bg'] ? $r['input_bg'] : '',
            'border-radius' => self::px($r['btn_radius']),
        ));

        // WordPress's own secondary actions (Generate Password, Update the admin
        // email): outlined in the card's text colour, so they never look like
        // the primary button.
        if ($polish) {
            $text = '' !== $r['card_text'] ? $r['card_text'] : '#3c434a';
            $secondary = $p . ' .reset-pass-submit .button.wp-generate-pw,' . $p . ' .admin-email__actions .button:not(.button-primary)';
            $css[] = $secondary . '{background:transparent;color:' . $text . ';border:1px solid ' . Color::alpha($text, 45) . ';box-shadow:none;min-height:44px;padding:0 20px;font-size:14px;font-weight:500;line-height:42px;border-radius:' . ('' !== $r['btn_radius'] ? (int) $r['btn_radius'] . 'px' : '3px') . '}';
            $css[] = str_replace(',', ':hover,', $secondary) . ':hover,' . str_replace(',', ':focus,', $secondary) . ':focus{background:' . Color::alpha($text, 8) . ';color:' . $text . ';border-color:' . $text . '}';
            $css[] = $p . ' .admin-email__actions-primary{display:flex;flex-wrap:wrap;gap:8px;align-items:center}';
            $css[] = $p . ' .admin-email__actions-primary .button{margin:0}';
        }

        if ($d['button']['full_width']) {
            $css[] = $p . ' #loginform .forgetmenot{float:none;margin-bottom:12px;min-height:0}';
            $css[] = $p . ' #loginform p.submit .button-primary,' . $p . ' #lostpasswordform .button-primary,' . $p . ' #registerform .button-primary,' . $p . ' #wp-submit{float:none;width:100%;display:block}';
        }

        return implode("\n", array_filter($css));
    }

    /**
     * Add state pseudo-classes to every selector in a list.
     *
     * @param string   $selectors Comma-separated selectors.
     * @param string[] $states    Pseudo-classes, e.g. ':focus'.
     * @return string
     * @since 3.0.0
     */
    private static function states($selectors, array $states)
    {
        $out = array();
        foreach ($states as $state) {
            foreach (explode(',', $selectors) as $selector) {
                $out[] = trim($selector) . $state;
            }
        }

        return implode(',', $out);
    }

    /**
     * Link rules. Classless links only, so styled third-party buttons keep their look.
     *
     * @param array $d Design.
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function link_css(array $d, array $r)
    {
        $p = self::P . ' #login';
        $links = array('#nav a', '#backtoblog a', '.privacy-policy-page-link a', 'form a:not([class])', '.message a', '.notice a', '#login_error a');
        $sel = implode(',', array_map(function ($s) use ($p) {
            return $p . ' ' . $s;
        }, $links));
        $hover = implode(',', array_map(function ($s) use ($p) {
            return $p . ' ' . $s . ':hover,' . $p . ' ' . $s . ':focus';
        }, $links));

        $css = array();
        $css[] = self::rule($sel, array('color' => $r['link']));
        $css[] = self::rule($hover, array('color' => $r['link_hover'], 'text-decoration' => '' !== $r['link'] && !$r['classic'] ? 'underline' : ''));
        if ('' !== $r['focus']) {
            // Links and link-style buttons (the 2FA "use a backup code" switches).
            $css[] = self::rule($p . ' a:focus,' . $p . ' .button-link:focus', array('box-shadow' => '0 0 0 2px ' . $r['focus'], 'border-radius' => '2px', 'outline' => '2px solid transparent'));
        }

        // Links inside messages use the message text colour, for the focus ring too
        // (the template's focus colour can vanish on a light message box).
        if ('' !== $r['msg_text']) {
            $css[] = self::rule($p . ' .message a,' . $p . ' .notice a,' . $p . ' #login_error a', array('color' => $r['msg_text'], 'text-decoration' => 'underline'));
            $css[] = self::rule($p . ' .message a:focus,' . $p . ' .notice a:focus,' . $p . ' #login_error a:focus', array('box-shadow' => '0 0 0 2px ' . $r['msg_text']));
        }

        return implode("\n", array_filter($css));
    }

    /**
     * Message rules (errors, notices, success).
     *
     * @param array $d Design.
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function message_css(array $d, array $r)
    {
        $p = self::P . ' #login';
        $m = $d['messages'];
        $css = array();
        $css[] = self::rule($p . ' .message,' . $p . ' .notice,' . $p . ' .success,' . $p . ' #login_error', array(
            'background-color' => $r['msg_bg'],
            'color' => $r['msg_text'],
            'border-radius' => '' !== $r['card_radius'] && !$r['classic'] ? min(8, (int) $r['card_radius']) . 'px' : '',
            'box-shadow' => '' !== $r['msg_bg'] && !$r['classic'] ? '0 1px 2px rgba(0, 0, 0, 0.06)' : '',
        ));
        $css[] = self::rule($p . ' .message,' . $p . ' .notice-info', array('border-left-color' => $r['msg_notice']));
        $css[] = self::rule($p . ' .notice-error,' . $p . ' #login_error', array('border-left-color' => $m['error']));
        $css[] = self::rule($p . ' .success,' . $p . ' .notice-success', array('border-left-color' => $m['success']));
        $css[] = self::rule($p . ' .message p,' . $p . ' .notice p,' . $p . ' #login_error p', array('color' => $r['msg_text']));

        return implode("\n", array_filter($css));
    }

    /**
     * The interim login modal (wp-admin's session-expired iframe): no panels, compact.
     *
     * @param array $d Design.
     * @param array $r Resolved values.
     * @return string
     * @since 3.0.0
     */
    private static function interim_css(array $d, array $r)
    {
        if ($r['classic']) {
            return '';
        }

        $p = self::P . '.interim-login';
        $b = $d['background'];
        $bg = $r['split'] ? $r['panel'] : ('' !== $b['overlay'] && 'image' === $b['type'] ? Color::over(Color::alpha($b['overlay'], max(60, $b['overlay_opacity'])), '#333333') : ('gradient' === $b['type'] && '' !== $b['gradient_from'] ? Color::mix($b['gradient_from'], $b['gradient_to'], 0.5) : $r['page_bg']));

        $css = array();
        $css[] = self::rule($p, array(
            'display' => 'block',
            'padding' => '0 12px',
            'min-height' => '0',
            'background' => $bg ? $bg : '#f0f0f1',
        ));
        $css[] = $p . ' .authlify-art{display:none}';
        $css[] = $p . ' #login{width:auto;max-width:360px;margin:8px auto 16px;' . ($r['box'] ? 'padding:20px 18px' : '') . '}';
        $css[] = $p . ' #login h1.wp-login-logo a{max-height:56px;margin-bottom:12px}';
        $css[] = $p . ' .authlify-intro,' . $p . ' .authlify-footer-text{display:none}';

        return implode("\n", $css);
    }

    /**
     * @font-face rules for bundled or theme fonts.
     *
     * @param array $d Design.
     * @return string
     * @since 3.0.0
     */
    private static function font_faces(array $d)
    {
        $fonts = Design::fonts();
        $font = $d['text']['font'];

        if (isset($fonts[$font]) && '' !== $fonts[$font][2]) {
            $name = trim(strtok($fonts[$font][1], ','), '"');
            $range = 'lora' === $font ? '400 700' : ('space-grotesk' === $font ? '300 700' : '200 900');

            return '@font-face{font-family:"' . $name . '";src:url("' . esc_url_raw(AUTHLIFY_URL . 'assets/designer/fonts/' . $fonts[$font][2]) . '") format("woff2");font-weight:' . $range . ';font-style:normal;font-display:swap}';
        }

        if ('theme' === $font && $d['text']['faces']) {
            $out = array();
            foreach ($d['text']['faces'] as $face) {
                $family = '' !== $face['family'] ? $face['family'] : trim(strtok($d['text']['custom_family'], ','), '"\' ');
                $format = preg_match('/\.woff2(\?|$)/i', $face['src']) ? 'woff2' : (preg_match('/\.woff(\?|$)/i', $face['src']) ? 'woff' : 'truetype');
                $out[] = '@font-face{font-family:"' . str_replace('"', '', $family) . '";src:url("' . esc_url_raw($face['src']) . '") format("' . $format . '");font-weight:' . $face['weight'] . ';font-style:' . $face['style'] . ';font-display:swap}';
            }

            return implode("\n", $out);
        }

        return '';
    }

    /**
     * The font-family value for a design.
     *
     * @param array $d Design.
     * @return string
     * @since 3.0.0
     */
    private static function family(array $d)
    {
        $fonts = Design::fonts();
        $font = $d['text']['font'];

        if ('theme' === $font) {
            return '' !== $d['text']['custom_family'] ? $d['text']['custom_family'] . ', system-ui, sans-serif' : '';
        }

        return isset($fonts[$font]) ? $fonts[$font][1] : '';
    }

    /**
     * One CSS rule from non-empty declarations.
     *
     * @param string $selector Selector.
     * @param array  $props    property => value.
     * @return string
     * @since 3.0.0
     */
    public static function rule($selector, array $props)
    {
        $decl = '';
        foreach ($props as $prop => $value) {
            if ('' === $value || null === $value || false === $value) {
                continue;
            }
            $decl .= $prop . ':' . str_replace(array('{', '}', ';', '<'), '', (string) $value) . ';';
        }

        return '' === $decl ? '' : $selector . '{' . $decl . '}';
    }

    /**
     * Pixel value, or '' when unset.
     *
     * @param mixed $value Number.
     * @return string
     * @since 3.0.0
     */
    private static function px($value)
    {
        return '' === $value || null === $value ? '' : (int) $value . 'px';
    }

    /**
     * The stylesheet for a design: a cached file URL, or '' when uploads isn't writable.
     *
     * @param string $css Compiled CSS.
     * @return string URL or ''.
     * @since 3.0.0
     */
    public static function file_url($css)
    {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error'])) {
            return '';
        }

        $dir = trailingslashit($uploads['basedir']) . self::DIR;
        $name = 'login-' . substr(md5($css . AUTHLIFY_VERSION), 0, 12) . '.css';
        $path = $dir . '/' . $name;

        if (!file_exists($path)) {
            if (!wp_mkdir_p($dir) || !wp_is_writable($dir)) {
                return '';
            }
            if (!file_exists($dir . '/index.php')) {
                @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore
            }
            if (false === @file_put_contents($path, $css, LOCK_EX)) { // phpcs:ignore
                return '';
            }
        }

        return set_url_scheme(trailingslashit($uploads['baseurl']) . self::DIR . '/' . $name);
    }

    /**
     * Delete cached stylesheets (after a save; the next login page view writes a new one).
     *
     * @since 3.0.0
     */
    public static function purge()
    {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error'])) {
            return;
        }

        foreach ((array) glob(trailingslashit($uploads['basedir']) . self::DIR . '/login-*.css') as $file) {
            if (is_string($file) && is_file($file)) {
                wp_delete_file($file);
            }
        }
    }
}
