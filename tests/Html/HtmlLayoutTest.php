<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HTML rendered end to end: where things land on the page.
 */
final class HtmlLayoutTest extends TestCase
{
    private static function render(string $html): string
    {
        return Document::fromHtml($html)->toBytes(new Engine(compressStreams: false));
    }

    #[Test]
    public function a_padded_paragraph_in_a_cell_does_not_run_into_the_next_row(): void
    {
        // The case from the report: "Body" was drawn over the blue band.
        $pdf = self::render('<table><tr><td><p style="background-color:#D5E8F7;padding:3pt">Heading</p></td></tr>'
            .'<tr><td><p>Body</p></td></tr></table>');

        $band = PdfGeometry::fills($pdf, 'd5e8f7')[0];
        self::assertGreaterThanOrEqual($band['bottom'], PdfGeometry::word($pdf, 'Body')['yMin']);
    }

    #[Test]
    public function rowspan_keeps_the_next_row_out_of_the_merged_column(): void
    {
        // The case from the report: C landed under A.
        $pdf = self::render('<table style="table-layout: fixed"><tr><td rowspan="2">Alpha</td><td>Bravo</td></tr><tr><td>Charlie</td></tr></table>');

        self::assertEqualsWithDelta(PdfGeometry::word($pdf, 'Bravo')['xMin'], PdfGeometry::word($pdf, 'Charlie')['xMin'], 0.5);
    }

    #[Test]
    public function col_widths_place_the_columns(): void
    {
        $pdf = self::render('<style>@page { margin: 0 } td { padding: 0 }</style>'
            .'<table style="width: 400pt"><colgroup><col style="width: 25%"><col style="width: 75%"></colgroup>'
            .'<tr><td>Left</td><td>Right</td></tr></table>');

        self::assertEqualsWithDelta(100.0, PdfGeometry::word($pdf, 'Right')['xMin'] - PdfGeometry::word($pdf, 'Left')['xMin'], 0.5);
    }

    #[Test]
    public function a_table_border_is_drawn_around_the_table(): void
    {
        $pdf = self::render('<table style="border: 2pt solid #ff0000"><tr><td>A</td></tr><tr><td>B</td></tr></table>');

        $red = array_values(array_filter(PdfGeometry::rects($pdf), static fn (array $r): bool => ! $r['fill'] && $r['color'] === 'ff0000'));
        self::assertCount(4, $red);
        $a = PdfGeometry::word($pdf, 'A');
        $b = PdfGeometry::word($pdf, 'B');
        self::assertLessThan($a['yMin'], min(array_column($red, 'top')));
        self::assertGreaterThan($b['yMax'], max(array_column($red, 'top')));
    }

    #[Test]
    public function a_section_page_break_starts_a_new_page(): void
    {
        $pdf = self::render('<style>section { page-break-after: always }</style><section><p>First</p></section><section><p>Second</p></section>');

        self::assertSame(1, PdfGeometry::word($pdf, 'First')['page']);
        self::assertSame(2, PdfGeometry::word($pdf, 'Second')['page']);
        self::assertSame(2, substr_count($pdf, '/Type /Page '));
    }

    #[Test]
    public function an_image_at_full_width_fills_the_line(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $pdf = self::render('<style>@page { size: A4; margin: 20pt }</style><img src="'.$png.'" style="width: 100%">');

        // The image is drawn by a `cm` scaling it to the content width.
        self::assertMatchesRegularExpression('/555\.28 0 0 555\.28 20 /', $pdf);
    }

    #[Test]
    public function a_full_width_image_keeps_the_container_padding(): void
    {
        // The policy header (width: 100%) inside a section with padding was
        // as wide as the page content, wider than the text under it.
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $pdf = self::render('<style>@page { size: A4; margin: 20pt }</style>'
            .'<section style="padding: 0 30pt 0 40pt"><img src="'.$png.'" style="width: 100%"><p>Body</p></section>');

        // 595.28 − 2×20 − 40 − 30 = 485.28, starting at 20 + 40.
        self::assertMatchesRegularExpression('/485\.28 0 0 485\.28 60 /', $pdf);
        self::assertEqualsWithDelta(60.0, PdfGeometry::word($pdf, 'Body')['xMin'], 0.5);
    }

    #[Test]
    public function css_dashed_and_dotted_borders_keep_their_style(): void
    {
        $pdf = self::render('<div style="border: 1pt dashed #ff0000">Dashed</div>'
            .'<table><tr><td style="border-bottom: 2pt dotted #0000ff">Dotted</td></tr></table>');

        self::assertMatchesRegularExpression('/1 0 0 RG\n\[3 [\d.]+\] 0 d\n0 J\n/', $pdf);
        self::assertMatchesRegularExpression('/0 0 1 RG\n\[0 [\d.]+\] 0 d\n1 J\n/', $pdf);
    }
}
