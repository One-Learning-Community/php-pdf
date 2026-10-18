<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CSS beyond boxes and tables: grid, wrapping and column flex, floats,
 * absolute and fixed positioning, kept-together blocks, generated
 * content, linked style sheets and font-family lists.
 */
final class HtmlCssLayoutModelTest extends TestCase
{
    private const PAGE = '<style>@page { size: A4; margin: 20pt }</style>';

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private static function render(string $html): string
    {
        return Document::fromHtml(self::PAGE.$html)->toBytes(new Engine(compressStreams: false));
    }

    /** @return array{page: int, xMin: float, yMin: float, xMax: float, yMax: float, text: string} */
    private static function at(string $pdf, string $word): array
    {
        return PdfGeometry::word($pdf, $word);
    }

    protected function setUp(): void
    {
        if (PdfGeometry::words('%PDF-1.4') === null) {
            self::markTestSkipped('pdftotext is not available');
        }
    }

    #[Test]
    public function grid_columns_follow_the_template(): void
    {
        $pdf = self::render('<div style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: 10pt">'
            .'<div>Aa</div><div>Bb</div><div>Cc</div><div style="grid-column: span 2">Dd</div><div>Ee</div></div>');

        // 555.28 wide: columns 25 / 50 / 25 %, the gap split between them.
        self::assertEqualsWithDelta(20.0, self::at($pdf, 'Aa')['xMin'], 0.5);
        self::assertEqualsWithDelta(20 + 555.28 * 0.25 + 5, self::at($pdf, 'Bb')['xMin'], 1.0);
        self::assertEqualsWithDelta(20 + 555.28 * 0.75 + 5, self::at($pdf, 'Cc')['xMin'], 1.0);
        // Dd spans two columns, so Ee lands in the third.
        self::assertEqualsWithDelta(self::at($pdf, 'Cc')['xMin'], self::at($pdf, 'Ee')['xMin'], 0.5);
        self::assertGreaterThan(self::at($pdf, 'Aa')['yMax'], self::at($pdf, 'Dd')['yMin']);
    }

    #[Test]
    public function grid_tracks_in_points_and_repeat(): void
    {
        $pdf = self::render('<div style="display: grid; grid-template-columns: 100pt repeat(2, 1fr)">'
            .'<div>One</div><div>Two</div><div>Three</div></div>');

        self::assertEqualsWithDelta(120.0, self::at($pdf, 'Two')['xMin'], 2.0);
        self::assertGreaterThan(self::at($pdf, 'Two')['xMin'] + 100, self::at($pdf, 'Three')['xMin']);
    }

    #[Test]
    public function a_wrapping_flex_box_starts_new_lines(): void
    {
        $pdf = self::render('<div style="display: flex; flex-wrap: wrap; gap: 10pt">'
            .'<div style="width: 45%">One</div><div style="width: 45%">Two</div><div style="width: 45%">Three</div></div>');

        self::assertEqualsWithDelta(self::at($pdf, 'One')['yMin'], self::at($pdf, 'Two')['yMin'], 0.5);
        self::assertGreaterThan(self::at($pdf, 'One')['yMax'], self::at($pdf, 'Three')['yMin']);
        self::assertEqualsWithDelta(self::at($pdf, 'One')['xMin'], self::at($pdf, 'Three')['xMin'], 0.5);
    }

    #[Test]
    public function a_column_flex_box_stacks(): void
    {
        $pdf = self::render('<div style="display: flex; flex-direction: column"><div>Top</div><div>Bottom</div></div>');

        self::assertEqualsWithDelta(self::at($pdf, 'Top')['xMin'], self::at($pdf, 'Bottom')['xMin'], 0.5);
        self::assertGreaterThan(self::at($pdf, 'Top')['yMax'], self::at($pdf, 'Bottom')['yMin']);
    }

    #[Test]
    public function text_runs_beside_a_float_until_a_clear(): void
    {
        $pdf = self::render('<img src="'.self::PNG.'" style="float: left; width: 60pt; height: 40pt; margin-right: 10pt">'
            .'<p>Beside</p><p style="clear: both">Below</p>');

        self::assertEqualsWithDelta(90.0, self::at($pdf, 'Beside')['xMin'], 0.5);
        self::assertEqualsWithDelta(20.0, self::at($pdf, 'Below')['xMin'], 0.5);
        // The image is 40pt tall from the top margin.
        self::assertGreaterThanOrEqual(60.0, self::at($pdf, 'Below')['yMin']);
    }

    #[Test]
    public function a_right_float_sits_at_the_right(): void
    {
        $pdf = self::render('<div style="float: right; width: 100pt">Aside</div><p>Main</p>');

        self::assertEqualsWithDelta(20.0, self::at($pdf, 'Main')['xMin'], 0.5);
        self::assertEqualsWithDelta(575.28 - 100, self::at($pdf, 'Aside')['xMin'], 1.0);
    }

    #[Test]
    public function absolute_and_fixed_elements_are_out_of_the_flow(): void
    {
        $body = '';
        for ($i = 1; $i <= 70; $i++) {
            $body .= "<p>Para$i</p>";
        }
        $pdf = self::render('<div style="position: fixed; top: 0; right: 0; width: 100pt">Stamp</div>'
            .'<div style="position: absolute; bottom: 0; left: 0">Foot</div>'.$body);

        $words = PdfGeometry::words($pdf) ?? [];
        $stamps = array_values(array_filter($words, static fn (array $w): bool => $w['text'] === 'Stamp'));
        self::assertCount(2, $stamps, 'A fixed element repeats on every page');
        foreach ($stamps as $stamp) {
            self::assertEqualsWithDelta(475.28, $stamp['xMin'], 1.0);
            self::assertEqualsWithDelta(20.0 + 3, $stamp['yMin'], 3.0);
        }
        // Not in the flow: the first paragraph is at the top.
        self::assertEqualsWithDelta(self::at($pdf, 'Stamp')['yMin'], self::at($pdf, 'Para1')['yMin'], 1.0);
        self::assertGreaterThan(780.0, self::at($pdf, 'Foot')['yMin']);
    }

    #[Test]
    public function absolute_inside_a_positioned_box_starts_from_the_box(): void
    {
        $pdf = self::render('<p>Before</p><div style="position: relative">'
            .'<div style="position: absolute; top: 0; right: 0; width: 50pt">Badge</div><p>Inside</p></div>');

        self::assertEqualsWithDelta(self::at($pdf, 'Inside')['yMin'], self::at($pdf, 'Badge')['yMin'], 1.0);
        self::assertEqualsWithDelta(575.28 - 50, self::at($pdf, 'Badge')['xMin'], 1.0);
    }

    #[Test]
    public function a_block_kept_together_moves_to_the_next_page_whole(): void
    {
        $filler = '';
        for ($i = 1; $i <= 58; $i++) {
            $filler .= "<p>Fill$i</p>";
        }
        $pdf = self::render($filler.'<div style="break-inside: avoid"><p>KeepA</p><p>KeepB</p><p>KeepC</p><p>KeepD</p></div>');

        self::assertSame(2, self::at($pdf, 'KeepA')['page']);
        self::assertSame(2, self::at($pdf, 'KeepD')['page']);

        $loose = self::render($filler.'<div><p>KeepA</p><p>KeepB</p><p>KeepC</p><p>KeepD</p></div>');
        self::assertSame(1, self::at($loose, 'KeepA')['page'], 'Without avoid the block splits — the test needs a break');
    }

    #[Test]
    public function a_kept_block_taller_than_a_page_still_splits(): void
    {
        $inner = '';
        for ($i = 1; $i <= 90; $i++) {
            $inner .= "<p>Long$i</p>";
        }
        $pdf = self::render('<p>Intro</p><div style="break-inside: avoid">'.$inner.'</div>');

        self::assertSame(1, self::at($pdf, 'Long1')['page']);
        self::assertSame(2, self::at($pdf, 'Long90')['page']);
    }

    #[Test]
    public function generated_content_before_and_after(): void
    {
        $pdf = self::render('<style>'
            .'.req::after { content: " *" } q::before { content: open-quote } q::after { content: close-quote }'
            .' a.ext::after { content: " (" attr(href) ")" } h2::before { content: "Part\\A0" }'
            .' li.tick:before { content: "OK: " } .gone::before { content: "no"; display: none }'
            .'</style><p><span class="req">Name</span></p><p>Say <q>hi</q></p>'
            .'<p><a class="ext" href="https://x.test">site</a></p><h2>Intro</h2><ul><li class="tick">done</li></ul>'
            .'<p class="gone">plain</p>');

        $text = (string) shell_exec('pdftotext -layout '.escapeshellarg(self::file($pdf)).' -');
        self::assertStringContainsString('Name *', $text);
        self::assertStringContainsString("Say \u{201C}hi\u{201D}", $text);
        self::assertStringContainsString('site (https://x.test)', $text);
        // \A0 is a no-break space; pdftotext gives it back as a space.
        self::assertMatchesRegularExpression('/Part[\x{00A0} ]Intro/u', $text);
        self::assertStringContainsString('OK: done', $text);
        self::assertStringNotContainsString('noplain', $text);
    }

    #[Test]
    public function linked_style_sheets_apply_in_order(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'css').'.css';
        file_put_contents($file, '.x { text-align: right } .y { font-weight: bold }');
        try {
            $data = 'data:text/css;base64,'.base64_encode('.y { font-weight: normal }');
            $pdf = self::render('<link rel="stylesheet" href="'.$file.'"><link rel="stylesheet" href="'.$data.'">'
                .'<link rel="stylesheet" href="https://example.invalid/never.css"><p class="x">Right</p><p class="y">Plain</p>');
        } finally {
            @unlink($file);
        }

        self::assertGreaterThan(400.0, self::at($pdf, 'Right')['xMin']);
        // The later data: sheet wins over the file.
        self::assertStringNotContainsString('/BaseFont /Helvetica-Bold', $pdf);
    }

    #[Test]
    public function the_first_usable_family_of_a_list_is_taken(): void
    {
        $pdf = self::render('<p style="font-family: NoSuchFont, Georgia, serif">Serif</p>'
            .'<p style="font-family: \'Fancy Mono\', Consolas, monospace">Mono</p>');

        self::assertStringContainsString('/BaseFont /Times-Roman', $pdf);
        self::assertStringContainsString('/BaseFont /Courier', $pdf);
    }

    private static function file(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($path, $pdf);
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }
}
