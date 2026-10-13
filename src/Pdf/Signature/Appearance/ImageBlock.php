<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

use Dskripchenko\PhpPdf\Image\PdfImage;

/**
 * A picture in a signature stamp — a scanned handwritten signature, a seal,
 * an emblem. Scaled to fit the box keeping its proportions and centred;
 * PNG transparency is kept.
 */
final readonly class ImageBlock implements AppearanceBlock
{
    public function __construct(
        public PdfImage $image,
        public Box $box = new Box,
        public float $opacity = 1.0,
    ) {
        if ($opacity <= 0.0 || $opacity > 1.0) {
            throw new \InvalidArgumentException('ImageBlock opacity must be in (0, 1]');
        }
    }

    public function box(): Box
    {
        return $this->box;
    }
}
