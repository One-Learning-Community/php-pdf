<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

use Dskripchenko\PhpPdf\Barcode\QrEccLevel;

/**
 * A QR code in a signature stamp — typically a link to a page where the
 * document can be checked.
 *
 * `$data` may hold placeholders like a {@see TextBlock}, e.g.
 * `https://example.com/verify?serial={serial}`. The code is square, as large
 * as the box allows, centred, on a white ground with a quiet zone of
 * `$quietZoneModules` — scanners need at least two modules of it.
 */
final readonly class QrBlock implements AppearanceBlock
{
    public function __construct(
        public string $data,
        public Box $box = new Box,
        public string $color = '#000000',
        public int $quietZoneModules = 2,
        public ?QrEccLevel $eccLevel = null,
    ) {
        if ($data === '') {
            throw new \InvalidArgumentException('QrBlock data must not be empty');
        }
        if ($quietZoneModules < 0) {
            throw new \InvalidArgumentException('QrBlock quiet zone must not be negative');
        }
        Color::parse($color);
    }

    public function box(): Box
    {
        return $this->box;
    }
}
