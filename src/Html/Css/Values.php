<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Html\Css;

/**
 * CSS values: colours and lengths.
 *
 * @internal
 */
final class Values
{
    private const NAMED = [
        'black' => '000000', 'white' => 'ffffff', 'gray' => '808080', 'grey' => '808080',
        'red' => 'ff0000', 'green' => '008000', 'blue' => '0000ff',
        'yellow' => 'ffff00', 'cyan' => '00ffff', 'magenta' => 'ff00ff',
        'silver' => 'c0c0c0', 'maroon' => '800000', 'olive' => '808000',
        'purple' => '800080', 'teal' => '008080', 'navy' => '000080',
        'lime' => '00ff00', 'aqua' => '00ffff', 'fuchsia' => 'ff00ff',
        'orange' => 'ffa500', 'pink' => 'ffc0cb', 'brown' => 'a52a2a',
        'darkgray' => 'a9a9a9', 'darkgrey' => 'a9a9a9', 'lightgray' => 'd3d3d3',
        'lightgrey' => 'd3d3d3', 'dimgray' => '696969', 'gainsboro' => 'dcdcdc',
        'whitesmoke' => 'f5f5f5', 'darkblue' => '00008b', 'darkred' => '8b0000',
        'darkgreen' => '006400', 'lightblue' => 'add8e6', 'gold' => 'ffd700',
    ];

    /** `#abc`, `#aabbcc`, `rgb()`/`rgba()` (opaque enough), a named colour → hex without #. */
    public static function color(string $css): ?string
    {
        $css = strtolower(trim($css));
        if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $css, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $css, $m) === 1) {
            return $m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }
        if (preg_match('/^rgba?\(\s*(\d+)\s*[,\s]\s*(\d+)\s*[,\s]\s*(\d+)\s*(?:[,\/]\s*([\d.]+%?))?\s*\)$/', $css, $m) === 1) {
            if (isset($m[4]) && (float) $m[4] === 0.0) {
                return null; // fully transparent
            }

            return sprintf('%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }

        return self::NAMED[$css] ?? null;
    }

    /**
     * A length in points.
     *
     * @param  float  $em  The font size `em` refers to.
     * @param  float  $rem  The root font size.
     * @param  ?float  $percentOf  What `%` refers to; null — `%` is not a length here.
     */
    public static function length(string $css, float $em = 12.0, float $rem = 12.0, ?float $percentOf = null): ?float
    {
        $css = strtolower(trim($css));
        if ($css === '' || preg_match('/^(-?[\d.]+)\s*([a-z%]*)$/', $css, $m) !== 1 || ! is_numeric($m[1])) {
            return null;
        }
        $n = (float) $m[1];

        return match ($m[2]) {
            'pt', '' => $n,
            'px' => $n * 0.75,
            'mm' => $n * 72.0 / 25.4,
            'cm' => $n * 72.0 / 2.54,
            'in' => $n * 72.0,
            'pc' => $n * 12.0,
            'q' => $n * 72.0 / 101.6,
            'em' => $n * $em,
            'rem' => $n * $rem,
            'ex', 'ch' => $n * $em * 0.5,
            '%' => $percentOf !== null ? $n * $percentOf / 100.0 : null,
            default => null,
        };
    }

    /** `thin`/`medium`/`thick` or a length → points. */
    public static function borderWidth(string $css, float $em = 12.0): ?float
    {
        return match (strtolower(trim($css))) {
            'thin' => 0.75,
            'medium' => 2.25,
            'thick' => 3.75,
            default => self::length($css, $em),
        };
    }

    /** A font-size: lengths, `%`/`em` of the parent, keywords. */
    public static function fontSize(string $css, float $parent, float $root): ?float
    {
        $keywords = [
            'xx-small' => 7.0, 'x-small' => 7.5, 'small' => 10.0, 'medium' => 12.0,
            'large' => 13.5, 'x-large' => 18.0, 'xx-large' => 24.0, 'xxx-large' => 36.0,
        ];
        $lower = strtolower(trim($css));
        if (isset($keywords[$lower])) {
            return $keywords[$lower];
        }

        return match ($lower) {
            'smaller' => $parent / 1.2,
            'larger' => $parent * 1.2,
            default => self::length($css, $parent, $root, $parent),
        };
    }
}
