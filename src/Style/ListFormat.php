<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Style;

/**
 * List marker format.
 */
enum ListFormat: string
{
    case Bullet = 'bullet';
    case Decimal = 'decimal';            // 1, 2, 3
    case LowerLetter = 'lowerLetter';    // a, b, c
    case UpperLetter = 'upperLetter';    // A, B, C
    case LowerRoman = 'lowerRoman';      // i, ii, iii
    case UpperRoman = 'upperRoman';      // I, II, III

    public function isOrdered(): bool
    {
        return $this !== self::Bullet;
    }

    /** A number in this format: `3`, `c`, `C`, `iii`, `III`; a bullet for Bullet. */
    public function number(int $n): string
    {
        return match ($this) {
            self::Bullet => "\u{2022}",
            self::Decimal => (string) $n,
            self::LowerLetter => strtolower(self::letters($n)),
            self::UpperLetter => self::letters($n),
            self::LowerRoman => strtolower(self::roman($n)),
            self::UpperRoman => self::roman($n),
        };
    }

    private static function letters(int $n): string
    {
        $result = '';
        while ($n > 0) {
            $n--;
            $result = chr(65 + ($n % 26)).$result;
            $n = intdiv($n, 26);
        }

        return $result;
    }

    private static function roman(int $n): string
    {
        $result = '';
        foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $symbol => $value) {
            while ($n >= $value) {
                $result .= $symbol;
                $n -= $value;
            }
        }

        return $result;
    }
}
