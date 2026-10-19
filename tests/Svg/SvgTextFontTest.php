<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SVG text takes the document's fonts: an embedded face for scripts the
 * base-14 ones lack (a stamp "ОПЛАЧЕНО" printed as "????????"), the anchor,
 * the group's rotation and the inherited font properties.
 */
final class SvgTextFontTest extends TestCase
{
    private function font(string $face = 'Regular'): PdfFont
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-'.$face.'.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached.');
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    private function render(string $svg, ?Engine $engine = null): string
    {
        // The box in the SVG's own proportions: an unequal scale stretches the
        // text along with the shapes, which would blur the angle checked here.
        preg_match('/width="(\d+)" height="(\d+)"/', $svg, $size);

        return (new Document(new Section([new SvgElement($svg, (float) $size[1], (float) $size[2])])))
            ->toBytes($engine ?? new Engine(compressStreams: false));
    }

    private function pdfText(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'svgt-');
        file_put_contents($tmp, $bytes);
        try {
            return (string) shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null');
        } finally {
            @unlink($tmp);
        }
    }

    /** @return list<list<float>> the operands of every `Tm` */
    private function textMatrices(string $bytes): array
    {
        preg_match_all('/(-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) Tm/', $bytes, $m, PREG_SET_ORDER);

        return array_map(static fn (array $row): array => array_map('floatval', array_slice($row, 1)), $m);
    }

    #[Test]
    public function cyrillic_text_is_shown_in_the_document_font(): void
    {
        $svg = '<svg width="240" height="80"><text x="10" y="50" fill="#b91c1c" font-size="26">ОПЛАЧЕНО</text></svg>';
        $bytes = $this->render($svg, new Engine(compressStreams: false, defaultFont: $this->font()));

        self::assertStringContainsString('LiberationSans', $bytes);
        self::assertStringContainsString('ОПЛАЧЕНО', $this->pdfText($bytes));
    }

    #[Test]
    public function the_family_and_weight_are_inherited_from_the_group_and_style(): void
    {
        $svg = '<svg width="240" height="80"><g font-weight="700"><text x="10" y="30" style="font-size: 20px">Жирный</text></g>'
            .'<text x="10" y="70" font-size="20">Обычный</text></svg>';
        $bytes = $this->render($svg, new Engine(compressStreams: false, defaultFont: $this->font(), boldFont: $this->font('Bold')));

        self::assertStringContainsString('LiberationSans-Bold', $bytes);
        self::assertMatchesRegularExpression('/\/F\d+ 20 Tf/', $bytes);
        $text = $this->pdfText($bytes);
        self::assertStringContainsString('Жирный', $text);
        self::assertStringContainsString('Обычный', $text);
    }

    #[Test]
    public function text_anchor_moves_the_text_by_its_width(): void
    {
        $at = fn (string $anchor): float => $this->textMatrices($this->render(
            '<svg width="200" height="40"><text x="100" y="20" font-size="10" text-anchor="'.$anchor.'">Anchor</text></svg>',
        ))[0][4];

        $start = $at('start');
        $middle = $at('middle');
        $end = $at('end');

        self::assertLessThan($start, $middle);
        self::assertEqualsWithDelta($start - $end, 2 * ($start - $middle), 0.01);
    }

    #[Test]
    public function a_rotated_group_turns_the_text(): void
    {
        $bytes = $this->render('<svg width="240" height="80"><g transform="rotate(-10 120 40)"><text x="20" y="50" font-size="20">PAID</text></g></svg>');

        [$a, $b] = $this->textMatrices($bytes)[0];
        // rotate(-10) in SVG (y down) is +10° counter-clockwise on the page.
        self::assertEqualsWithDelta(atan2($b, $a), deg2rad(10), 1e-3);
        self::assertStringContainsString('(PAID) Tj', $bytes);
    }

    #[Test]
    public function without_an_embedded_font_latin_text_still_uses_base_14(): void
    {
        $bytes = $this->render('<svg width="100" height="40"><text x="0" y="20" font-weight="bold">Bold</text></svg>');

        self::assertStringContainsString('/BaseFont /Helvetica-Bold', $bytes);
        self::assertStringContainsString('(Bold) Tj', $bytes);
    }
}
