<?php
/**
 * Colour helpers for the login designer.
 *
 * @package Authlify
 */

namespace Authlify\Designer;

defined('ABSPATH') || exit;

/**
 * Parses, validates, mixes and measures colours. Accepts #rgb, #rgba,
 * #rrggbb, #rrggbbaa, rgb() and rgba(), and the keyword "transparent".
 *
 * @since 3.0.0
 */
final class Color
{
    /**
     * Validate and normalise a colour. Returns '' for anything else.
     *
     * @param mixed $value Raw value.
     * @return string
     * @since 3.0.0
     */
    public static function sanitize($value)
    {
        if (!is_string($value)) {
            return '';
        }

        $value = strtolower(trim($value));

        if ('transparent' === $value) {
            return $value;
        }

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value)) {
            return $value;
        }

        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(0|1|0?\.\d+|1\.0+)\s*)?\)$/', $value, $m)) {
            if ((int) $m[1] > 255 || (int) $m[2] > 255 || (int) $m[3] > 255) {
                return '';
            }
            if (isset($m[4]) && '' !== $m[4]) {
                return sprintf('rgba(%d, %d, %d, %s)', $m[1], $m[2], $m[3], rtrim(rtrim(number_format((float) $m[4], 3, '.', ''), '0'), '.'));
            }

            return sprintf('rgb(%d, %d, %d)', $m[1], $m[2], $m[3]);
        }

        return '';
    }

    /**
     * Parse a valid colour to array( r, g, b, a ).
     *
     * @param string $color Colour.
     * @return array|null
     * @since 3.0.0
     */
    public static function parse($color)
    {
        $color = self::sanitize($color);
        if ('' === $color) {
            return null;
        }

        if ('transparent' === $color) {
            return array(0, 0, 0, 0.0);
        }

        if ('#' === $color[0]) {
            $hex = substr($color, 1);
            if (strlen($hex) <= 4) {
                $expanded = '';
                foreach (str_split($hex) as $char) {
                    $expanded .= $char . $char;
                }
                $hex = $expanded;
            }
            $alpha = 8 === strlen($hex) ? hexdec(substr($hex, 6, 2)) / 255 : 1.0;

            return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
        }

        preg_match_all('/[\d.]+/', $color, $m);
        $parts = array_map('floatval', $m[0]);

        return array((int) $parts[0], (int) $parts[1], (int) $parts[2], isset($parts[3]) ? $parts[3] : 1.0);
    }

    /**
     * Colour to #rrggbb, or rgba() when translucent.
     *
     * @param array $rgba array( r, g, b, a ).
     * @return string
     * @since 3.0.0
     */
    public static function format(array $rgba)
    {
        list($r, $g, $b, $a) = array_values($rgba) + array(0, 0, 0, 1.0);
        $r =(int) round(max(0, min(255, $r)));
        $g = (int) round(max(0, min(255, $g)));
        $b = (int) round(max(0, min(255, $b)));

        if ($a < 1) {
            return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim(rtrim(number_format(max(0, $a), 3, '.', ''), '0'), '.'));
        }

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * A colour with a given opacity (0-100).
     *
     * @param string $color   Colour.
     * @param int    $percent Opacity.
     * @return string
     * @since 3.0.0
     */
    public static function alpha($color, $percent)
    {
        $rgba = self::parse($color);
        if (!$rgba) {
            return '';
        }
        $rgba[3] = $rgba[3] * max(0, min(100, (int) $percent)) / 100;

        return self::format($rgba);
    }

    /**
     * Mix two colours. $weight is the share of $b (0-1).
     *
     * @param string $a      First colour.
     * @param string $b      Second colour.
     * @param float  $weight Share of the second colour.
     * @return string
     * @since 3.0.0
     */
    public static function mix($a, $b, $weight)
    {
        $x = self::parse($a);
        $y = self::parse($b);
        if (!$x || !$y) {
            return $x ? self::format($x) : ($y ? self::format($y) : '');
        }

        $out = array();
        for ($i = 0; $i < 4; $i++) {
            $out[$i] = $x[$i] + ($y[$i] - $x[$i]) * $weight;
        }

        return self::format($out);
    }

    /**
     * Darken (negative) or lighten (positive) by a share (-1 to 1).
     *
     * @param string $color  Colour.
     * @param float  $amount Amount.
     * @return string
     * @since 3.0.0
     */
    public static function shade($color, $amount)
    {
        $rgba = self::parse($color);
        if (!$rgba) {
            return '';
        }

        return self::mix($color, self::format(array($amount < 0 ? 0 : 255, $amount < 0 ? 0 : 255, $amount < 0 ? 0 : 255, $rgba[3])), abs($amount));
    }

    /**
     * Composite a (possibly translucent) colour over an opaque one.
     *
     * @param string $top    Top colour.
     * @param string $bottom Bottom colour.
     * @return string Opaque #rrggbb.
     * @since 3.0.0
     */
    public static function over($top, $bottom)
    {
        $t = self::parse($top);
        $b = self::parse($bottom);
        if (!$b) {
            $b = array(255, 255, 255, 1.0);
        }
        if (!$t) {
            $b[3] = 1.0;

            return self::format($b);
        }

        $a = $t[3];

        return self::format(array(
            $t[0] * $a + $b[0] * (1 - $a),
            $t[1] * $a + $b[1] * (1 - $a),
            $t[2] * $a + $b[2] * (1 - $a),
            1.0,
        ));
    }

    /**
     * WCAG relative luminance.
     *
     * @param string $color Colour.
     * @return float
     * @since 3.0.0
     */
    public static function luminance($color)
    {
        $rgba = self::parse($color);
        if (!$rgba) {
            return 1.0;
        }

        $lin = array();
        for ($i = 0; $i < 3; $i++) {
            $c = $rgba[$i] / 255;
            $lin[$i] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /**
     * WCAG contrast ratio between two opaque colours.
     *
     * @param string $a First colour.
     * @param string $b Second colour.
     * @return float
     * @since 3.0.0
     */
    public static function contrast($a, $b)
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /**
     * Whether a colour is dark (white text reads better on it).
     *
     * @param string $color Colour.
     * @return bool
     * @since 3.0.0
     */
    public static function is_dark($color)
    {
        return self::contrast($color, '#ffffff') > self::contrast($color, '#000000');
    }

    /**
     * The more readable of near-black and white on a background.
     *
     * @param string $background Background.
     * @return string
     * @since 3.0.0
     */
    public static function readable_on($background)
    {
        return self::contrast('#ffffff', $background) >= self::contrast('#111111', $background) ? '#ffffff' : '#111111';
    }

    /**
     * Nudge a foreground colour darker or lighter until it reaches a contrast ratio.
     *
     * @param string $color      Foreground.
     * @param string $background Background (opaque).
     * @param float  $ratio      Target ratio.
     * @return string
     * @since 3.0.0
     */
    public static function ensure_contrast($color, $background, $ratio = 4.5)
    {
        if ('' === self::sanitize($color)) {
            return self::readable_on($background);
        }

        $dir = self::is_dark($background) ? 1 : -1;
        $current = self::over($color, $background);
        for ($i = 0; $i < 20 && self::contrast($current, $background) < $ratio; $i++) {
            $current = self::shade($current, 0.1 * $dir);
        }

        return self::contrast($current, $background) >= $ratio ? $current : self::readable_on($background);
    }
}
