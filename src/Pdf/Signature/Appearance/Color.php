<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

/**
 * Hex colour → RGB components in 0..1.
 *
 * @internal
 */
final class Color
{
    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public static function parse(string $hex): array
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }
        if (strlen($h) !== 6 || ! ctype_xdigit($h)) {
            throw new \InvalidArgumentException("Not a hex colour: '$hex'");
        }

        return [
            hexdec(substr($h, 0, 2)) / 255.0,
            hexdec(substr($h, 2, 2)) / 255.0,
            hexdec(substr($h, 4, 2)) / 255.0,
        ];
    }
}
