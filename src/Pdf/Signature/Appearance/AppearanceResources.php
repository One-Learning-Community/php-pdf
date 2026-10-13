<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\PdfExtGState;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\StandardFont;

/**
 * Hands out resource names for what a stamp draws with. The stamp's
 * appearance stream shares the resource dictionary of the page it sits on,
 * so the page registers the fonts and images and the stream uses its names.
 *
 * @internal
 */
interface AppearanceResources
{
    public function fontResource(PdfFont $font): string;

    public function standardFontResource(StandardFont $font): string;

    public function imageResource(PdfImage $image): string;

    public function extGStateResource(PdfExtGState $state): string;
}
