<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Text sits in the middle of its line box, as in CSS: the extra room a
 * line height gives is split between the top and the bottom of the line.
 */
final class LineHeightTest extends TestCase
{
    private static function wordTop(float $lineHeightMult): float
    {
        $p = new Paragraph([new Run('Word', new RunStyle(sizePt: 10))], new ParagraphStyle(lineHeightMult: $lineHeightMult));
        $pdf = (new Document(new Section([$p])))->toBytes(new Engine(compressStreams: false));

        return PdfGeometry::word($pdf, 'Word')['yMin'];
    }

    #[Test]
    public function half_of_the_extra_line_height_goes_above_the_text(): void
    {
        // 10pt text, line height 1 → 2: the line grows by 10pt, the text
        // moves down by half of it.
        self::assertEqualsWithDelta(5.0, self::wordTop(2.0) - self::wordTop(1.0), 0.05);
    }

    #[Test]
    public function text_under_a_top_border_keeps_the_browser_gap(): void
    {
        // The case from the report: in a bordered cell the caption touched
        // the top line. With line height 1.2 the baseline sits 0.9465 em
        // below the line top (0.1 em half-leading + 0.905 em ascent minus
        // 0.0585 em of the content area overflow), as in Chrome.
        $border = new Border(BorderStyle::Single, 8, 'ff0000');
        $p = new Paragraph([new Run('Word', new RunStyle(sizePt: 10))], new ParagraphStyle(borders: new BorderSet(top: $border)));
        $pdf = (new Document(new Section([$p])))->toBytes(new Engine(compressStreams: false));

        $rule = array_values(array_filter(PdfGeometry::rects($pdf), static fn (array $r): bool => ! $r['fill'] && $r['color'] === 'ff0000'))[0];
        preg_match('/([\d.-]+) ([\d.-]+) Td\n/', $pdf, $m);
        $baselineFromTop = 841.89 - (float) $m[2];
        // The line top is the border's bottom edge: 1pt border.
        $lineTop = $rule['top'] + 0.5;

        self::assertEqualsWithDelta(9.465, $baselineFromTop - $lineTop, 0.05);
    }
}
