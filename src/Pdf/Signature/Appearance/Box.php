<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

/**
 * Where a block sits inside a signature stamp.
 *
 * Fractions of the stamp's inner area (inside the border and padding), with
 * the origin at the top-left corner — the way a stamp is drawn on paper, not
 * the PDF's bottom-up coordinates. `new Box(0.5, 0, 0.5, 1)` is the right
 * half; `Box::full()` is the whole inner area.
 */
final readonly class Box
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $width = 1.0,
        public float $height = 1.0,
    ) {
        foreach (['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height] as $name => $value) {
            if ($value < 0.0 || $value > 1.0) {
                throw new \InvalidArgumentException("Box $name must be a fraction between 0 and 1, got $value");
            }
        }
        if ($width === 0.0 || $height === 0.0) {
            throw new \InvalidArgumentException('Box must have a non-zero width and height');
        }
        if ($x + $width > 1.0 + 1e-9 || $y + $height > 1.0 + 1e-9) {
            throw new \InvalidArgumentException('Box must lie within the stamp');
        }
    }

    public static function full(): self
    {
        return new self;
    }

    /**
     * The box in PDF coordinates of an area with its lower-left corner at
     * (`$areaX`, `$areaY`).
     *
     * @return array{0: float, 1: float, 2: float, 3: float} x, y (bottom), width, height
     */
    public function resolve(float $areaX, float $areaY, float $areaWidth, float $areaHeight): array
    {
        $w = $this->width * $areaWidth;
        $h = $this->height * $areaHeight;
        $x = $areaX + $this->x * $areaWidth;
        $y = $areaY + $areaHeight - $this->y * $areaHeight - $h;

        return [$x, $y, $w, $h];
    }
}
