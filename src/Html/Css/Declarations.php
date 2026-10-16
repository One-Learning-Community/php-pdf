<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Html\Css;

/**
 * CSS declaration blocks: parsing, and shorthands spelled out into the
 * longhands the parser reads — so that a later `padding-top` overrides an
 * earlier `padding`, as the cascade requires.
 *
 * @internal
 */
final class Declarations
{
    private const SIDES = ['top', 'right', 'bottom', 'left'];

    /**
     * `color: red; margin: 0 !important` → name → [value, important].
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function parse(string $block): array
    {
        $result = [];
        foreach (self::split($block) as $part) {
            $colon = strpos($part, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($part, 0, $colon)));
            $value = trim(substr($part, $colon + 1));
            if ($name === '' || $value === '') {
                continue;
            }
            $important = false;
            if (preg_match('/\s*!\s*important\s*$/i', $value, $m) === 1) {
                $important = true;
                $value = trim(substr($value, 0, -strlen($m[0])));
            }
            foreach (self::expand($name, $value) as $longhand => $v) {
                $result[$longhand] = [$v, $important];
            }
        }

        return $result;
    }

    /**
     * Splits on `;` outside parentheses and quotes (`url(data:…;base64,…)`).
     *
     * @return list<string>
     */
    private static function split(string $block): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $len = strlen($block);
        for ($i = 0; $i < $len; $i++) {
            $c = $block[$i];
            if ($quote !== null) {
                $current .= $c;
                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($c === ';' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }
            $current .= $c;
        }
        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * A declaration as longhands: `padding: 1pt 2pt` → padding-top … ;
     * `border: 1px solid #000` → border-{side}-{width,style,color}.
     *
     * @return array<string, string>
     */
    public static function expand(string $name, string $value): array
    {
        if ($name === 'margin' || $name === 'padding') {
            return self::box($name.'-%s', $value);
        }
        if ($name === 'border') {
            $out = [];
            foreach (self::SIDES as $side) {
                $out += self::border("border-$side", $value);
            }

            return $out;
        }
        if (preg_match('/^border-(top|right|bottom|left)$/', $name, $m) === 1) {
            return self::border($name, $value);
        }
        if (preg_match('/^border-(width|style|color)$/', $name, $m) === 1) {
            return self::box('border-%s-'.$m[1], $value);
        }
        if ($name === 'background') {
            // Only the colour matters here: the first token that is not an
            // image, a position or a repeat keyword.
            foreach (self::tokens($value) as $token) {
                if (Values::color($token) !== null || in_array(strtolower($token), ['transparent', 'none'], true)) {
                    return ['background-color' => $token];
                }
            }

            return [];
        }
        if ($name === 'break-before' || $name === 'break-after' || $name === 'break-inside') {
            return ['page-'.$name => $value];
        }

        return [$name => $value];
    }

    /**
     * @return array<string, string>
     */
    private static function box(string $pattern, string $value): array
    {
        $v = self::tokens($value);
        [$top, $right, $bottom, $left] = match (count($v)) {
            1 => [$v[0], $v[0], $v[0], $v[0]],
            2 => [$v[0], $v[1], $v[0], $v[1]],
            3 => [$v[0], $v[1], $v[2], $v[1]],
            4 => [$v[0], $v[1], $v[2], $v[3]],
            default => [null, null, null, null],
        };
        if ($top === null) {
            return [];
        }

        return [
            sprintf($pattern, 'top') => $top,
            sprintf($pattern, 'right') => (string) $right,
            sprintf($pattern, 'bottom') => (string) $bottom,
            sprintf($pattern, 'left') => (string) $left,
        ];
    }

    /**
     * `1px solid #000` for one side → its width, style and colour; what the
     * shorthand leaves out is reset, as in CSS.
     *
     * @return array<string, string>
     */
    private static function border(string $prefix, string $value): array
    {
        $width = 'medium';
        $style = 'none';
        $color = 'currentcolor';
        foreach (self::tokens($value) as $token) {
            $lower = strtolower($token);
            if (in_array($lower, ['none', 'hidden', 'solid', 'double', 'dashed', 'dotted', 'groove', 'ridge', 'inset', 'outset'], true)) {
                $style = $lower;
            } elseif (in_array($lower, ['thin', 'medium', 'thick'], true) || preg_match('/^[\d.]/', $lower) === 1) {
                $width = $lower;
            } else {
                $color = $token;
            }
        }

        return ["$prefix-width" => $width, "$prefix-style" => $style, "$prefix-color" => $color];
    }

    /**
     * Space-separated tokens, keeping `rgb(1, 2, 3)` whole.
     *
     * @return list<string>
     */
    public static function tokens(string $value): array
    {
        preg_match_all('/[a-z-]+\([^)]*\)|"[^"]*"|\'[^\']*\'|[^\s,]+/i', trim($value), $m);

        return $m[0];
    }
}
