<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Footnote;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Footnotes at the foot of the page that references them, with as much room
 * as they take — the way a word processor places them. A fixed area
 * reserved on every page cost a seven-page form three extra pages.
 */
final class FootnotesAtPageFootTest extends TestCase
{
    /**
     * @param  list<\Dskripchenko\PhpPdf\Element\BlockElement>  $body
     */
    private static function render(array $body): string
    {
        return (new Document(new Section($body, footnotesAtPageFoot: true)))->toBytes(new Engine(compressStreams: false));
    }

    /**
     * @return list<\Dskripchenko\PhpPdf\Element\BlockElement>
     */
    private static function lines(int $count, string $prefix = 'Line'): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = new Paragraph([new Run($prefix.$i)]);
        }

        return $out;
    }

    private static function pages(string $pdf): int
    {
        return (int) preg_match_all('/\/Type\s*\/Page\b/', $pdf);
    }

    #[Test]
    public function pages_without_notes_lose_no_room(): void
    {
        $plain = (new Document(new Section(self::lines(120))))->toBytes(new Engine(compressStreams: false));
        $growing = self::render([...self::lines(119), new Paragraph([new Run('Line120'), new Footnote('The note')])]);

        // The first page carries as many lines as without notes at all.
        self::assertSame(PdfGeometry::word($plain, 'Line60')['page'], PdfGeometry::word($growing, 'Line60')['page']);
        self::assertSame(self::pages($plain), self::pages($growing));
        self::assertSame(PdfGeometry::word($growing, 'Line120')['page'], PdfGeometry::word($growing, 'note')['page']);
    }

    #[Test]
    public function a_line_whose_note_does_not_fit_moves_on_with_it(): void
    {
        // Fill the first page to its last line, then reference a long note.
        $words = PdfGeometry::words((new Document(new Section(self::lines(200))))->toBytes(new Engine(compressStreams: false)));
        self::assertNotNull($words);
        $perPage = count(array_filter($words, static fn (array $w): bool => $w['page'] === 1));

        $note = str_repeat('A long explanation of the term. ', 12);
        $pdf = self::render([...self::lines($perPage - 1), new Paragraph([new Run('Marked'), new Footnote($note)])]);

        $marked = PdfGeometry::word($pdf, 'Marked');
        self::assertSame(2, $marked['page']);
        self::assertSame(2, PdfGeometry::word($pdf, 'explanation')['page']);
    }

    #[Test]
    public function a_note_in_a_table_row_takes_room_on_the_rows_page(): void
    {
        $rows = [];
        for ($i = 1; $i <= 40; $i++) {
            $rows[] = new Row([new Cell([new Paragraph([new Run('Row'.$i)])])]);
        }
        $rows[] = new Row([new Cell([new Paragraph([new Run('Noted'), new Footnote('From the cell')])])]);
        $pdf = self::render([new Table($rows)]);

        $noted = PdfGeometry::word($pdf, 'Noted');
        $cell = PdfGeometry::word($pdf, 'cell');
        self::assertSame($noted['page'], $cell['page']);
        self::assertGreaterThan($noted['yMax'], $cell['yMin']); // the note below the row, not over it
    }
}
