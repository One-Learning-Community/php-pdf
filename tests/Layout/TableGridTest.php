<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\LineBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\CellStyle;
use Dskripchenko\PhpPdf\Style\TableStyle;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where cells go: merged down (rowSpan) and by the widths of columns.
 */
final class TableGridTest extends TestCase
{
    /** @param  list<BlockElement>  $blocks */
    private static function render(array $blocks): string
    {
        return (new Document(new Section($blocks)))->toBytes(new Engine(compressStreams: false));
    }

    private static function cell(string $text, int $rowSpan = 1, ?CellStyle $style = null): Cell
    {
        return new Cell([new Paragraph([new Run($text)])], $style ?? new CellStyle, rowSpan: $rowSpan);
    }

    #[Test]
    public function a_cell_merged_down_holds_its_column_in_the_next_row(): void
    {
        $pdf = self::render([new Table([
            new Row([self::cell('Alpha', 2, new CellStyle(backgroundColor: 'ffeeaa')), self::cell('Bravo')]),
            new Row([self::cell('Charlie')]),
        ])]);

        $bravo = PdfGeometry::word($pdf, 'Bravo');
        $charlie = PdfGeometry::word($pdf, 'Charlie');
        // Charlie goes under Bravo, not under Alpha.
        self::assertEqualsWithDelta($bravo['xMin'], $charlie['xMin'], 0.5);
        self::assertGreaterThan($bravo['yMax'], $charlie['yMin']);

        // Alpha's background covers both rows.
        $fill = PdfGeometry::fills($pdf, 'ffeeaa')[0];
        self::assertGreaterThan($charlie['yMax'], $fill['bottom']);
    }

    #[Test]
    public function a_tall_merged_cell_grows_the_last_row_it_spans(): void
    {
        $tall = new Cell([new Paragraph([
            new Run('One'), new LineBreak, new Run('Two'), new LineBreak, new Run('Three'), new LineBreak, new Run('Four'),
        ])], rowSpan: 2);
        $pdf = self::render([
            new Table([
                new Row([$tall, self::cell('Bravo')]),
                new Row([self::cell('Charlie')]),
            ]),
            new Paragraph([new Run('After')]),
        ]);

        // What follows the table starts below the merged cell's last line.
        self::assertGreaterThan(PdfGeometry::word($pdf, 'Four')['yMax'], PdfGeometry::word($pdf, 'After')['yMin']);
    }

    #[Test]
    public function rows_bound_by_a_merge_move_to_the_next_page_together(): void
    {
        $filler = [];
        for ($i = 0; $i < 53; $i++) {
            $filler[] = new Paragraph([new Run("line $i")]);
        }
        $pdf = self::render([...$filler, new Table([
            new Row([self::cell('Alpha', 2), self::cell('Bravo')]),
            new Row([self::cell('Charlie')]),
        ])]);

        // The first row alone would still fit on page 1; the pair does not.
        $alpha = PdfGeometry::word($pdf, 'Alpha');
        $charlie = PdfGeometry::word($pdf, 'Charlie');
        self::assertSame(2, $alpha['page']);
        self::assertSame(2, $charlie['page'], 'The merged rows were torn apart');
    }

    #[Test]
    public function declared_cell_widths_set_their_columns(): void
    {
        $pdf = self::render([new Table([
            new Row([self::cell('Narrow', style: new CellStyle(widthPt: 100)), self::cell('Wide')]),
        ])]);

        // The second column starts 100pt after the first (default page margin 72pt).
        self::assertEqualsWithDelta(100.0, PdfGeometry::word($pdf, 'Wide')['xMin'] - PdfGeometry::word($pdf, 'Narrow')['xMin'], 0.5);
    }

    #[Test]
    public function auto_layout_gives_long_content_the_room(): void
    {
        $rows = [new Row([
            self::cell('Id'),
            self::cell('A rather long description that needs most of the line'),
        ])];
        $equal = self::render([new Table($rows)]);
        $auto = self::render([new Table($rows, new TableStyle(autoLayout: true))]);

        $descEqual = PdfGeometry::word($equal, 'rather')['xMin'] - PdfGeometry::word($equal, 'Id')['xMin'];
        $descAuto = PdfGeometry::word($auto, 'rather')['xMin'] - PdfGeometry::word($auto, 'Id')['xMin'];

        // Equal columns by default (the second starts mid-table); by
        // content, the short one shrinks to its word.
        $tableWidth = PdfGeometry::word($equal, 'Id')['xMin'] + $descEqual * 2;
        self::assertGreaterThan(200.0, $descEqual);
        self::assertLessThan($descEqual / 4, $descAuto);

    }
}
