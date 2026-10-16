<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A paragraph's box — padding, border, background — takes the room it is
 * drawn in: what follows starts below it, in a table row as well as on the
 * page.
 */
final class ParagraphBoxTest extends TestCase
{
    private static function render(array $blocks): string
    {
        return (new Document(new Section($blocks)))->toBytes(new Engine(compressStreams: false));
    }

    private static function boxed(string $text, ParagraphStyle $style): Paragraph
    {
        return new Paragraph([new Run($text)], $style);
    }

    #[Test]
    public function the_next_table_row_starts_below_a_padded_paragraph(): void
    {
        $pdf = self::render([new Table([
            new Row([new Cell([self::boxed('Heading', new ParagraphStyle(
                paddingTopPt: 6, paddingBottomPt: 6, backgroundColor: 'd5e8f7',
            ))])]),
            new Row([new Cell([new Paragraph([new Run('Body')])])]),
        ])]);

        $fill = PdfGeometry::fills($pdf, 'd5e8f7')[0];
        $body = PdfGeometry::word($pdf, 'Body');

        self::assertGreaterThanOrEqual($fill['bottom'], $body['yMin'], 'The next row overlaps the paragraph background');
    }

    #[Test]
    public function padding_and_border_count_in_the_page_flow(): void
    {
        $style = new ParagraphStyle(
            borders: new BorderSet(bottom: new Border(BorderStyle::Single, 16, '000000')),
            paddingBottomPt: 10,
        );
        $plain = self::render([new Paragraph([new Run('First')]), new Paragraph([new Run('Second')])]);
        $boxed = self::render([self::boxed('First', $style), new Paragraph([new Run('Second')])]);

        $shift = PdfGeometry::word($boxed, 'Second')['yMin'] - PdfGeometry::word($plain, 'Second')['yMin'];

        // 10pt of padding + a 2pt border.
        self::assertEqualsWithDelta(12.0, $shift, 0.5);
    }

    #[Test]
    public function paragraph_borders_are_drawn_on_the_box_edges(): void
    {
        $pdf = self::render([self::boxed('Framed', new ParagraphStyle(
            borders: new BorderSet(
                top: new Border(BorderStyle::Single, 8, 'ff0000'),
                bottom: new Border(BorderStyle::Single, 8, 'ff0000'),
                left: new Border(BorderStyle::Single, 8, 'ff0000'),
                right: new Border(BorderStyle::Single, 8, 'ff0000'),
            ),
            paddingTopPt: 4, paddingRightPt: 4, paddingBottomPt: 4, paddingLeftPt: 4,
        ))]);

        $strokes = array_values(array_filter(PdfGeometry::rects($pdf), static fn (array $r): bool => ! $r['fill'] && $r['color'] === 'ff0000'));
        self::assertCount(4, $strokes);

        $word = PdfGeometry::word($pdf, 'Framed');
        $tops = array_column($strokes, 'top');
        // The text sits inside: below the top line, above the bottom one.
        self::assertLessThan($word['yMin'], min($tops));
        self::assertGreaterThan($word['yMax'], max($tops));
    }

    #[Test]
    public function a_paragraph_without_a_box_draws_nothing(): void
    {
        $pdf = self::render([new Paragraph([new Run('plain')])]);

        self::assertSame([], PdfGeometry::rects($pdf));
    }

    #[Test]
    public function the_box_stays_inside_the_paragraph_indents(): void
    {
        // An HTML container's padding reaches its paragraphs as indents: the
        // bottom rule of `.lead` must be as wide as the table under it, not
        // the whole content area.
        $pdf = self::render([self::boxed('Indented', new ParagraphStyle(
            indentLeftPt: 30, indentRightPt: 40,
            borders: new BorderSet(bottom: new Border(BorderStyle::Single, 8, '0000ff')),
            backgroundColor: 'eeeeee',
        ))]);

        $plain = self::render([new Paragraph([new Run('Indented')])]);
        $left = PdfGeometry::word($plain, 'Indented')['xMin'];
        $fill = PdfGeometry::fills($pdf, 'eeeeee')[0];
        $rule = array_values(array_filter(PdfGeometry::rects($pdf), static fn (array $r): bool => ! $r['fill'] && $r['color'] === '0000ff'))[0];

        self::assertEqualsWithDelta($left + 30, $fill['x'], 0.5);
        self::assertEqualsWithDelta($fill['x'], $rule['x'], 0.5);
        self::assertEqualsWithDelta($fill['w'], $rule['w'], 0.5);
        self::assertEqualsWithDelta($left + 30, PdfGeometry::word($pdf, 'Indented')['xMin'], 0.5);
    }
}
