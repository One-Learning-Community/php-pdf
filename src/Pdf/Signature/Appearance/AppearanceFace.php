<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

use Dskripchenko\PhpPdf\Layout\TextMeasurer;
use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\StandardFontMetrics;
use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\WinAnsi;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\StandardFont;

/**
 * A font as the stamp uses it: measuring, encoding, its resource name.
 *
 * @internal
 */
final class AppearanceFace
{
    private function __construct(
        public readonly string $resource,
        public readonly bool $fauxBold,
        private readonly ?PdfFont $font,
        private readonly ?StandardFont $standard,
    ) {}

    public static function embedded(PdfFont $font, string $resource, bool $fauxBold): self
    {
        return new self($resource, $fauxBold, $font, null);
    }

    public static function standard(StandardFont $font, string $resource): self
    {
        return new self($resource, false, null, $font);
    }

    public function width(string $text, float $size): float
    {
        if ($this->font !== null) {
            return (new TextMeasurer($this->font, $size, useKerning: false))->widthPt($text);
        }
        $bytes = (string) WinAnsi::encode($text);
        $units = 0;
        $name = $this->standard?->postScriptName() ?? 'Helvetica';
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $units += StandardFontMetrics::width($name, ord($bytes[$i]));
        }

        return $units * $size / 1000;
    }

    /** The text as a PDF hex string for `Tj`. */
    public function encode(string $text): string
    {
        if ($this->font !== null) {
            return $this->font->encodeText($text);
        }

        return '<'.strtoupper(bin2hex((string) WinAnsi::encode($text))).'>';
    }
}
