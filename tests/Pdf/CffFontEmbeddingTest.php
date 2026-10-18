<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Font\DirectoryFontProvider;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Font\Ttf\TtfSubsetter;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Layout\TextMeasurer;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * OpenType fonts with CFF outlines: CIDFontType0 + FontFile3/CIDFontType0C.
 *
 * The MiniCff fixtures (tests/fixtures/fonts, built by
 * scripts/fixtures/generate-cff-test-fonts.py) need no download:
 *  - MiniCff-Regular.otf    — name-keyed CFF, CID == glyph index;
 *  - MiniCffCid-Regular.otf — CID-keyed CFF whose charset maps glyph i to
 *                             CID 100 + 2i.
 * Source Sans 3 (scripts/fetch-fonts.sh) covers a real font; those tests are
 * skipped when it is not cached.
 */
final class CffFontEmbeddingTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/fonts';

    private const NAME_KEYED = self::FIXTURES.'/MiniCff-Regular.otf';

    private const CID_KEYED = self::FIXTURES.'/MiniCffCid-Regular.otf';

    private const SOURCE_SANS = __DIR__.'/../../.cache/fonts/SourceSans3-Regular.otf';

    #[Test]
    public function parses_name_keyed_cff_font(): void
    {
        $ttf = TtfFile::fromFile(self::NAME_KEYED);

        self::assertTrue($ttf->isCff());
        self::assertSame('MiniCff-Regular', $ttf->postScriptName());
        self::assertSame(1000, $ttf->unitsPerEm());
        self::assertSame(10, $ttf->numGlyphs());
        self::assertSame(2, $ttf->glyphIdForChar(0x48)); // H
        self::assertSame(640, $ttf->advanceWidth(2));
        self::assertSame(2, $ttf->cidForGlyph(2));
        self::assertSame("\x01\x00", substr($ttf->cffTableBytes(), 0, 2));
    }

    #[Test]
    public function cid_keyed_font_maps_glyphs_through_the_charset(): void
    {
        $ttf = TtfFile::fromFile(self::CID_KEYED);

        self::assertTrue($ttf->isCff());
        self::assertSame(0, $ttf->cidForGlyph(0));
        self::assertSame(102, $ttf->cidForGlyph(1));
        self::assertSame(104, $ttf->cidForGlyph(2));
        self::assertSame(118, $ttf->cidForGlyph(9));
    }

    #[Test]
    public function truetype_fonts_keep_cid_equal_to_glyph_id(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation Sans not cached — run scripts/fetch-fonts.sh.');
        }
        $ttf = TtfFile::fromFile($path);

        self::assertFalse($ttf->isCff());
        self::assertSame(43, $ttf->cidForGlyph(43));
    }

    #[Test]
    public function refuses_cff2_fonts_with_a_clear_message(): void
    {
        // 'OTTO' header with a single CFF2 table record.
        $bytes = 'OTTO'.pack('nnnn', 1, 16, 0, 0)
            .'CFF2'.pack('NNN', 0, 28, 4)
            ."\x02\x00\x05\x00";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CFF2 outlines (variable CFF) are not supported');
        new TtfFile($bytes);
    }

    #[Test]
    public function refuses_a_truncated_cff_table(): void
    {
        $font = (string) file_get_contents(self::NAME_KEYED);
        // Point the 'CFF ' record at 8 bytes of its own table only.
        $numTables = unpack('n', substr($font, 4, 2))[1];
        for ($i = 0; $i < $numTables; $i++) {
            $record = 12 + $i * 16;
            if (substr($font, $record, 4) === 'CFF ') {
                $font = substr_replace($font, pack('N', 8), $record + 12, 4);
            }
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Malformed CFF');
        new TtfFile($font);
    }

    #[Test]
    public function subsetter_refuses_cff_fonts(): void
    {
        $this->expectException(\LogicException::class);
        (new TtfSubsetter)->subset(TtfFile::fromFile(self::NAME_KEYED), [2]);
    }

    #[Test]
    public function embeds_a_cid_font_type0_with_the_raw_cff_table(): void
    {
        $ttf = TtfFile::fromFile(self::NAME_KEYED);
        $pdf = $this->render(new PdfFont($ttf), 'Hello');

        self::assertStringContainsString('/Subtype /Type0 /BaseFont /MiniCff-Regular-Identity-H /Encoding /Identity-H', $pdf);
        self::assertStringContainsString('/Subtype /CIDFontType0 /BaseFont /MiniCff-Regular', $pdf);
        self::assertStringContainsString('/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>', $pdf);
        self::assertStringNotContainsString('/CIDToGIDMap', $pdf);
        self::assertStringNotContainsString('/FontFile2', $pdf);
        self::assertStringNotContainsString('/Length1', $pdf);
        self::assertMatchesRegularExpression('#/FontFile3 \d+ 0 R#', $pdf);

        // The font program is the CFF table, byte for byte.
        $cff = $ttf->cffTableBytes();
        self::assertStringContainsString(
            sprintf("<< /Length %d /Subtype /CIDFontType0C >>\nstream\n%s\nendstream", strlen($cff), $cff),
            $pdf,
        );

        // Widths of H e l o (glyphs 2, 5, 6, 7) from hmtx.
        self::assertStringContainsString('/W [2 [640] 5 [540] 6 [240] 7 [560]]', $pdf);
        self::assertStringContainsString('<00020005000600060007>', $pdf);
        self::assertStringContainsString("<0002> <0048>\n", $pdf);
    }

    #[Test]
    public function cid_keyed_font_writes_cids_into_text_widths_and_to_unicode(): void
    {
        $pdf = $this->render(new PdfFont(TtfFile::fromFile(self::CID_KEYED)), 'Hello');

        // H e l o are glyphs 2 5 6 7 → CIDs 104 110 112 114.
        self::assertStringContainsString('<0068006E007000700072>', $pdf);
        self::assertStringContainsString('/W [104 [640] 110 [540] 112 [240] 114 [560]]', $pdf);
        self::assertStringContainsString("<0068> <0048>\n", $pdf);
        self::assertStringContainsString("<0072> <006F>\n", $pdf);
        self::assertStringNotContainsString('<0002> <0048>', $pdf);
    }

    #[Test]
    public function layout_widths_follow_hmtx(): void
    {
        $font = new PdfFont(TtfFile::fromFile(self::NAME_KEYED));

        // H 640 + e 540 + l 240 + l 240 + o 560 = 2220 units/em.
        self::assertEqualsWithDelta(22.2, (new TextMeasurer($font, 10))->widthPt('Hello'), 1e-9);
        self::assertSame(640, $font->widthOfCharPdfUnits(0x41F)); // П
    }

    #[Test]
    public function source_sans_widths_follow_hmtx(): void
    {
        $font = new PdfFont($this->sourceSans());

        // H 652 + e 496 + l 255 + l 255 + o 542 (Source Sans 3.052 hmtx).
        self::assertEqualsWithDelta(22.0, (new TextMeasurer($font, 10, useKerning: false))->widthPt('Hello'), 1e-9);
        self::assertSame(645, $font->widthOfCharPdfUnits(0x41F));
        self::assertNotSame(0, $font->kerningPdfUnits(
            $font->ttf()->glyphIdForChar(0x41), // A
            $font->ttf()->glyphIdForChar(0x56), // V
        ), 'GPOS kerning works for CFF fonts as well');
    }

    #[Test]
    public function text_extracts_with_pdftotext(): void
    {
        $this->requireTool('pdftotext');

        foreach ([self::NAME_KEYED, self::CID_KEYED] as $path) {
            $text = $this->pdftotext($this->renderDocument(TtfFile::fromFile($path), 'Hello World П'));
            self::assertSame('Hello World П', trim($text, " \n\f"), basename($path));
        }

        $text = $this->pdftotext($this->renderDocument($this->sourceSans(), 'Office floor — Съешь же ещё этих булок'));
        self::assertSame('Office floor — Съешь же ещё этих булок', trim($text, " \n\f"));
    }

    #[Test]
    public function renders_the_right_glyphs_with_pdftoppm(): void
    {
        $this->requireTool('pdftoppm');

        // "H" at 200 pt from (100, 500). The glyph has a crossbar at
        // y 300..400 and nothing between its stems above it; the .notdef box
        // a wrong CID would select is the other way round.
        foreach ([self::NAME_KEYED, self::CID_KEYED] as $path) {
            $doc = PdfDocument::new();
            $doc->addPage()->showEmbeddedText('H', 100, 500, new PdfFont(TtfFile::fromFile($path)), 200);
            $gray = $this->rasterize($doc->toBytes());

            $ink = fn (float $x, float $y): bool => $gray($x, $y) < 128;
            self::assertTrue($ink(100 + 0.32 * 200, 500 + 0.35 * 200), basename($path).': crossbar');
            self::assertTrue($ink(100 + 0.13 * 200, 500 + 0.60 * 200), basename($path).': left stem');
            self::assertFalse($ink(100 + 0.32 * 200, 500 + 0.68 * 200), basename($path).': gap above the crossbar');
            self::assertFalse($ink(100 + 0.32 * 200, 500 + 0.10 * 200), basename($path).': gap below the crossbar');
        }
    }

    #[Test]
    public function renders_source_sans_with_pdftoppm(): void
    {
        $this->requireTool('pdftoppm');

        $doc = PdfDocument::new();
        $doc->addPage()->showEmbeddedText('Ш', 100, 500, new PdfFont($this->sourceSans()), 200);
        $gray = $this->rasterize($doc->toBytes());

        $dark = 0;
        for ($x = 100; $x < 260; $x += 2) {
            for ($y = 500; $y < 640; $y += 2) {
                $dark += $gray($x, $y) < 128 ? 1 : 0;
            }
        }
        self::assertGreaterThan(300, $dark, 'the glyph area has ink');
    }

    #[Test]
    public function pdfa_1b_with_a_cff_font_keeps_pdf_1_4_font_types(): void
    {
        $engine = new Engine(defaultFont: new PdfFont(TtfFile::fromFile(self::NAME_KEYED)));
        $pdf = (new Document(
            new Section([new Paragraph([new Run('Hello World')])]),
            lang: 'en',
            pdfA: new PdfAConfig(
                __DIR__.'/../../resources/icc/sRGB2014.icc',
                part: PdfAConfig::PART_1,
                conformance: PdfAConfig::CONFORMANCE_B,
            ),
        ))->toBytes($engine);

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        // OpenType font programs (/FontFile3 /Subtype /OpenType) are PDF 1.6.
        self::assertStringNotContainsString('/OpenType', $pdf);
        self::assertStringContainsString('/CIDFontType0', $pdf);
    }

    #[Test]
    public function directory_provider_resolves_cff_fonts(): void
    {
        $provider = new DirectoryFontProvider(self::FIXTURES);
        $ttf = $provider->resolve('MiniCffCid-Regular');

        self::assertNotNull($ttf);
        self::assertTrue($ttf->isCff());
    }

    private function sourceSans(): TtfFile
    {
        if (! is_readable(self::SOURCE_SANS)) {
            self::markTestSkipped('Source Sans 3 not cached — run scripts/fetch-fonts.sh.');
        }

        return TtfFile::fromFile(self::SOURCE_SANS);
    }

    /** Uncompressed low-level document with one line of text. */
    private function render(PdfFont $font, string $text): string
    {
        $doc = PdfDocument::new(compressStreams: false);
        $doc->addPage()->showEmbeddedText($text, 72, 700, $font, 12);

        return $doc->toBytes();
    }

    private function renderDocument(TtfFile $ttf, string $text): string
    {
        $engine = new Engine(defaultFont: new PdfFont($ttf));

        return (new Document(new Section([new Paragraph([new Run($text)])])))->toBytes($engine);
    }

    private function requireTool(string $tool): void
    {
        exec($tool.' -v 2>&1', $out, $code);
        if ($code !== 0 && $code !== 99) {
            self::markTestSkipped("$tool not available");
        }
    }

    private function pdftotext(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cff').'.pdf';
        file_put_contents($path, $pdf);
        try {
            return (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($path).' - 2>/dev/null');
        } finally {
            @unlink($path);
        }
    }

    /**
     * Renders page 1 at 72 dpi and returns a lookup of the gray level at a
     * point given in PDF user space (origin bottom-left).
     *
     * @return \Closure(float, float): int
     */
    private function rasterize(string $pdf): \Closure
    {
        $base = tempnam(sys_get_temp_dir(), 'cff');
        file_put_contents($base.'.pdf', $pdf);
        try {
            exec(sprintf(
                'pdftoppm -r 72 -gray -singlefile %s %s 2>&1',
                escapeshellarg($base.'.pdf'),
                escapeshellarg($base),
            ), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
            $pgm = (string) file_get_contents($base.'.pgm');
        } finally {
            @unlink($base);
            @unlink($base.'.pdf');
            @unlink($base.'.pgm');
        }

        self::assertSame(1, preg_match('/\AP5\s+(\d+)\s+(\d+)\s+255\s/', $pgm, $m));
        $width = (int) $m[1];
        $height = (int) $m[2];
        $pixels = substr($pgm, strlen($m[0]));

        return static fn (float $x, float $y): int => ord($pixels[(int) ($height - $y) * $width + (int) $x]);
    }
}
