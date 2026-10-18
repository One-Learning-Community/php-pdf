<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\StandardFontMetrics;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A word split into runs — Word cuts text where the formatting changes,
 * "inter" + "national" — wraps as one word: the line breaks before its first
 * piece, never between the pieces.
 */
final class GluedWordWrapTest extends TestCase
{
    private const SIZE = 10.0;

    private static function width(string $text): float
    {
        $units = 0;
        foreach (str_split($text) as $char) {
            $units += StandardFontMetrics::width('Helvetica', ord($char));
        }

        return $units * self::SIZE / 1000;
    }

    #[Test]
    public function a_word_of_two_runs_moves_to_the_next_line_whole(): void
    {
        $style = new RunStyle(sizePt: self::SIZE);
        // Room for "Lead inter" but not for "Lead international".
        $room = self::width('Lead inter') + 2;
        $indentRight = (new PageSetup)->contentWidthPt() - $room;

        $pdf = (new Document(new Section([new Paragraph(
            [new Run('Lead ', $style), new Run('inter', $style), new Run('national', $style), new Run(' end', $style)],
            new ParagraphStyle(indentRightPt: $indentRight),
        )])))->toBytes(new Engine(compressStreams: false));

        $lead = PdfGeometry::word($pdf, 'Lead');
        $word = PdfGeometry::word($pdf, 'international');
        self::assertGreaterThan($lead['yMax'], $word['yMin'], 'The word goes to the next line');
        self::assertEqualsWithDelta($lead['xMin'], $word['xMin'], 0.5, 'It starts that line');
    }

    #[Test]
    public function a_word_that_is_the_whole_line_still_breaks(): void
    {
        // Nothing before the word to break at: the pieces go where they fit.
        $style = new RunStyle(sizePt: self::SIZE);
        $indentRight = (new PageSetup)->contentWidthPt() - self::width('inter') - 2;

        $pdf = (new Document(new Section([new Paragraph(
            [new Run('inter', $style), new Run('national', $style)],
            new ParagraphStyle(indentRightPt: $indentRight),
        )])))->toBytes(new Engine(compressStreams: false));

        $words = PdfGeometry::words($pdf) ?? self::markTestSkipped('pdftotext is not available');
        self::assertSame('international', implode('', array_column($words, 'text')), 'Nothing is lost');
        self::assertSame('inter', $words[0]['text'], 'The first line holds the first piece');
        self::assertGreaterThan(1, count(array_unique(array_column($words, 'yMin'))));
    }
}
