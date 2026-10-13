<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Incremental;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\PdfExtGState;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\AppearanceResources;
use Dskripchenko\PhpPdf\Pdf\StandardFont;

/**
 * Resources of an appearance stream written into an incremental update:
 * names are handed out while the stamp is drawn, the objects are emitted
 * afterwards (fonts subset to the glyphs actually used).
 *
 * @internal
 */
final class UpdateResources implements AppearanceResources
{
    /** @var array<string, PdfFont> */
    private array $fonts = [];

    /** @var array<string, StandardFont> */
    private array $standardFonts = [];

    /** @var array<string, PdfImage> */
    private array $images = [];

    /** @var array<string, PdfExtGState> */
    private array $states = [];

    public function fontResource(PdfFont $font): string
    {
        $name = array_search($font, $this->fonts, true);
        if ($name === false) {
            $name = 'F'.(count($this->fonts) + count($this->standardFonts) + 1);
            $this->fonts[$name] = $font;
        }

        return $name;
    }

    public function standardFontResource(StandardFont $font): string
    {
        $name = array_search($font, $this->standardFonts, true);
        if ($name === false) {
            $name = 'F'.(count($this->fonts) + count($this->standardFonts) + 1);
            $this->standardFonts[$name] = $font;
        }

        return $name;
    }

    public function imageResource(PdfImage $image): string
    {
        $name = array_search($image, $this->images, true);
        if ($name === false) {
            $name = 'Im'.(count($this->images) + 1);
            $this->images[$name] = $image;
        }

        return $name;
    }

    public function extGStateResource(PdfExtGState $state): string
    {
        foreach ($this->states as $name => $existing) {
            if ($existing->key() === $state->key()) {
                return $name;
            }
        }
        $name = 'Gs'.(count($this->states) + 1);
        $this->states[$name] = $state;

        return $name;
    }

    /** Emits the objects into the update and returns the /Resources dictionary. */
    public function emit(IncrementalUpdate $update): string
    {
        $writer = $update->writer();
        $fonts = '';
        foreach ($this->fonts as $name => $font) {
            $fonts .= sprintf(' /%s %d 0 R', $name, $font->registerWith($writer));
        }
        foreach ($this->standardFonts as $name => $font) {
            $fonts .= sprintf(' /%s %d 0 R', $name, $writer->addObject($font->pdfDictionary()));
        }
        $xobjects = '';
        foreach ($this->images as $name => $image) {
            $xobjects .= sprintf(' /%s %d 0 R', $name, $image->registerWith($writer));
        }
        $states = '';
        foreach ($this->states as $name => $state) {
            $states .= sprintf(' /%s %d 0 R', $name, $writer->addObject($state->toDictBody()));
        }

        $parts = [];
        if ($fonts !== '') {
            $parts[] = '/Font <<'.$fonts.' >>';
        }
        if ($xobjects !== '') {
            $parts[] = '/XObject <<'.$xobjects.' >>';
        }
        if ($states !== '') {
            $parts[] = '/ExtGState <<'.$states.' >>';
        }

        return '<< '.implode(' ', $parts).' >>';
    }
}
