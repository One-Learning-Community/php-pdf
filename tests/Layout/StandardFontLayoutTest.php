<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Text without an embedded font: the base-14 face follows the run's
 * weight, slant and family, and lines are measured with its real widths.
 */
final class StandardFontLayoutTest extends TestCase
{
    private static function render(array $blocks, ?Engine $engine = null): string
    {
        return (new Document(new Section($blocks)))->toBytes($engine ?? new Engine(compressStreams: false));
    }

    #[Test]
    public function the_face_follows_weight_slant_and_family(): void
    {
        $pdf = self::render([new Paragraph([
            new Run('plain '),
            new Run('bold ', new RunStyle(bold: true)),
            new Run('italic ', new RunStyle(italic: true)),
            new Run('serif ', new RunStyle(fontFamily: 'Times New Roman', bold: true, italic: true)),
            new Run('code', new RunStyle(fontFamily: 'monospace')),
        ])]);

        foreach (['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Times-BoldItalic', 'Courier'] as $font) {
            self::assertStringContainsString('/BaseFont /'.$font.' ', $pdf, $font);
        }
    }

    #[Test]
    public function the_fallback_family_is_kept(): void
    {
        $pdf = self::render([new Paragraph([new Run('bold', new RunStyle(bold: true))])], new Engine(fallbackStandard: StandardFont::TimesRoman, compressStreams: false));

        self::assertStringContainsString('/BaseFont /Times-Bold ', $pdf);
    }

    #[Test]
    public function centred_text_is_centred_by_its_real_width(): void
    {
        // Narrow and wide letters: the old estimate (half an em per letter)
        // put "iiii WWWW" well off centre.
        $pdf = self::render([new Paragraph([new Run('iiiiiiii WWWWWWWW')], new ParagraphStyle(alignment: Alignment::Center))]);

        $left = PdfGeometry::word($pdf, 'iiiiiiii');
        $right = PdfGeometry::word($pdf, 'WWWWWWWW');
        $pageWidth = 595.28;
        self::assertEqualsWithDelta($pageWidth / 2, ($left['xMin'] + $right['xMax']) / 2, 1.0);
    }

    #[Test]
    public function a_right_aligned_line_ends_at_the_margin(): void
    {
        $plain = self::render([new Paragraph([new Run('x')])]);
        $leftMargin = PdfGeometry::word($plain, 'x')['xMin'];
        $pdf = self::render([new Paragraph([new Run('Total: 1 234,56')], new ParagraphStyle(alignment: Alignment::End))]);

        // Symmetric default margins: the line ends where the left margin starts, mirrored.
        self::assertEqualsWithDelta(595.28 - $leftMargin, PdfGeometry::word($pdf, '234,56')['xMax'], 1.0);
    }
}
