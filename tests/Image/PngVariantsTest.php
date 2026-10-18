<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Image;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Forms\ExistingFormFiller;
use Dskripchenko\PhpPdf\Pdf\Reader\Filters;
use Dskripchenko\PhpPdf\Pdf\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Palette, 16-bit and Adam7-interlaced PNGs.
 *
 * The PNGs are encoded right here (zlib only, no GD), so every case runs
 * everywhere: rows cycle through all five PNG filters, interlaced images go
 * through all seven Adam7 passes.
 */
final class PngVariantsTest extends TestCase
{
    /** @return iterable<string, array{int, int, bool, int, int, bool}> colorType, bitDepth, interlaced, width, height, tRNS */
    public static function variants(): iterable
    {
        foreach ([false, true] as $interlaced) {
            $tag = $interlaced ? 'adam7' : 'plain';
            yield "palette 1-bit $tag" => [3, 1, $interlaced, 9, 9, false];
            yield "palette 2-bit tRNS $tag" => [3, 2, $interlaced, 5, 3, true];
            yield "palette 4-bit tRNS $tag" => [3, 4, $interlaced, 11, 7, true];
            yield "palette 8-bit $tag" => [3, 8, $interlaced, 3, 2, false];
            yield "palette 8-bit tRNS $tag" => [3, 8, $interlaced, 10, 10, true];
            yield "gray 1-bit $tag" => [0, 1, $interlaced, 13, 5, false];
            yield "gray 4-bit $tag" => [0, 4, $interlaced, 3, 9, false];
            yield "gray 8-bit $tag" => [0, 8, $interlaced, 8, 8, false];
            yield "gray 16-bit $tag" => [0, 16, $interlaced, 6, 4, false];
            yield "gray+alpha 8-bit $tag" => [4, 8, $interlaced, 7, 3, false];
            yield "gray+alpha 16-bit $tag" => [4, 16, $interlaced, 4, 5, false];
            yield "rgb 8-bit $tag" => [2, 8, $interlaced, 9, 6, false];
            yield "rgb 16-bit $tag" => [2, 16, $interlaced, 5, 5, false];
            yield "rgba 8-bit $tag" => [6, 8, $interlaced, 6, 9, false];
            yield "rgba 16-bit $tag" => [6, 16, $interlaced, 3, 3, false];
        }
        yield 'single pixel adam7' => [6, 8, true, 1, 1, false];
    }

    #[Test]
    #[DataProvider('variants')]
    public function decodes_to_the_expected_samples(int $colorType, int $bitDepth, bool $interlaced, int $width, int $height, bool $withTrns): void
    {
        $pixels = self::pixels($colorType, $bitDepth, $width, $height);
        $palette = $colorType === 3 ? self::palette($bitDepth) : null;
        $trns = $withTrns ? "\x00\x80" : null;

        $image = PdfImage::fromBytes(self::png($pixels, $colorType, $bitDepth, $interlaced, $palette, $trns));

        $hasAlphaChannel = $colorType === 4 || $colorType === 6;
        $colorChannels = in_array($colorType, [2, 6], true) ? 3 : 1;

        self::assertSame($width, $image->widthPx);
        self::assertSame($height, $image->heightPx);
        self::assertSame($bitDepth, $image->bitsPerComponent);
        self::assertSame($colorChannels === 3 || $colorType === 3 ? '/DeviceRGB' : '/DeviceGray', $image->colorSpace);
        self::assertSame($palette, $image->palette);

        // Colour samples, alpha dropped.
        $color = array_map(
            static fn (array $row): array => array_map(static fn (array $p): array => array_slice($p, 0, $colorChannels), $row),
            $pixels,
        );
        self::assertSame(self::rows($color, $bitDepth), self::decodedSamples($image));

        // Non-interlaced images without an alpha channel keep IDAT as is.
        self::assertSame(! $interlaced && ! $hasAlphaChannel, $image->decodeParms !== null);

        if ($hasAlphaChannel) {
            $alpha = array_map(
                static fn (array $row): array => array_map(static fn (array $p): array => [$p[count($p) - 1]], $row),
                $pixels,
            );
            self::assertNotNull($image->alphaData);
            self::assertSame($bitDepth, $image->alphaBitsPerComponent);
            self::assertSame(self::rows($alpha, $bitDepth), gzuncompress($image->alphaData));
        } elseif ($withTrns) {
            // Index 0 → alpha 0, index 1 → 0x80, the rest are opaque.
            $expected = '';
            foreach ($pixels as $row) {
                foreach ($row as $p) {
                    $expected .= chr([0, 0x80][$p[0]] ?? 0xFF);
                }
            }
            self::assertNotNull($image->alphaData);
            self::assertSame(8, $image->alphaBitsPerComponent);
            self::assertSame($expected, gzuncompress($image->alphaData));
        } else {
            self::assertNull($image->alphaData);
        }
    }

    #[Test]
    public function palette_becomes_an_indexed_color_space(): void
    {
        $palette = "\xFF\x00\x00\x00\xFF\x00\x00\x00\xFF";
        $png = self::png([[[0], [1], [2]], [[2], [1], [0]]], 3, 8, false, $palette, null);

        $dict = self::imageDictionary(PdfImage::fromBytes($png));

        self::assertStringContainsString('/ColorSpace [/Indexed /DeviceRGB 2 <ff000000ff000000ff>]', $dict);
        self::assertStringContainsString('/BitsPerComponent 8', $dict);
        self::assertStringContainsString('/DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns 3 >>', $dict);
        self::assertStringNotContainsString('/SMask', $dict);
    }

    #[Test]
    public function palette_transparency_becomes_a_soft_mask(): void
    {
        $png = self::png([[[0], [1]]], 3, 1, false, "\x00\x00\x00\xFF\xFF\xFF", "\x00");

        $pdf = self::registered(PdfImage::fromBytes($png));

        self::assertMatchesRegularExpression('@/ColorSpace \[/Indexed /DeviceRGB 1 <000000ffffff>\] /BitsPerComponent 1 .*/SMask \d+ 0 R@', $pdf);
        self::assertStringContainsString('/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode', $pdf);
    }

    #[Test]
    public function an_all_opaque_trns_adds_no_mask(): void
    {
        $png = self::png([[[0], [1]]], 3, 8, false, "\x00\x00\x00\xFF\xFF\xFF", "\xFF\xFF");

        self::assertNull(PdfImage::fromBytes($png)->alphaData);
    }

    #[Test]
    public function sixteen_bit_alpha_gets_a_sixteen_bit_mask(): void
    {
        $png = self::png([[[0, 0, 0, 0], [65535, 0, 0, 65535]]], 6, 16, false, null, null);

        $pdf = self::registered(PdfImage::fromBytes($png));

        self::assertStringContainsString('/ColorSpace /DeviceRGB /BitsPerComponent 16 /Filter /FlateDecode /SMask', $pdf);
        self::assertStringContainsString('/ColorSpace /DeviceGray /BitsPerComponent 16 /Filter /FlateDecode', $pdf);
    }

    #[Test]
    public function interlaced_image_is_stored_without_predictor(): void
    {
        $png = self::png(self::pixels(2, 8, 4, 4), 2, 8, true, null, null);

        $dict = self::imageDictionary(PdfImage::fromBytes($png));

        self::assertStringContainsString('/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length', $dict);
        self::assertStringNotContainsString('/DecodeParms', $dict);
    }

    #[Test]
    public function same_indices_over_another_palette_are_another_image(): void
    {
        $pixels = [[[0], [1]]];
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $page->drawImage(PdfImage::fromBytes(self::png($pixels, 3, 8, false, "\x00\x00\x00\xFF\xFF\xFF", null)), 0, 0, 10, 10);
        $page->drawImage(PdfImage::fromBytes(self::png($pixels, 3, 8, false, "\xFF\x00\x00\x00\xFF\x00", null)), 20, 0, 10, 10);

        self::assertSame(2, substr_count($pdf->toBytes(), '/Indexed'));
    }

    #[Test]
    public function stamps_a_palette_image_onto_an_existing_pdf(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $png = self::png([[[0], [1]]], 3, 2, true, "\x00\x00\x00\xFF\x00\x00", "\x00");

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())
            ->stampImageBytes(0, $png, 10, 10, 20, 10)
            ->toBytes();

        self::assertStringContainsString('/ColorSpace [/Indexed /DeviceRGB 1 <000000ff0000>]', $out);
        self::assertStringContainsString('/SMask', $out);
    }

    #[Test]
    public function stamps_a_passed_through_png_with_its_predictor(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage();
        $png = self::png(self::pixels(0, 16, 3, 3), 0, 16, false, null, null);

        $out = ExistingFormFiller::fromBytes($pdf->toBytes())
            ->stampImageBytes(0, $png, 10, 10, 20, 10)
            ->toBytes();

        self::assertMatchesRegularExpression('@/DecodeParms\s*<</Predictor 15/Colors 1/BitsPerComponent 16/Columns 3>>@', $out);
    }

    #[Test]
    public function rejects_a_bit_depth_the_color_type_does_not_allow(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bit depth 4 not supported for color type 2');

        PdfImage::fromBytes(self::png([[[1, 1, 1]]], 2, 4, false, null, null));
    }

    #[Test]
    public function rejects_a_palette_image_without_plte(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PLTE');

        PdfImage::fromBytes(self::png([[[0]]], 3, 8, false, null, null));
    }

    #[Test]
    public function rejects_truncated_image_data(): void
    {
        $png = self::png(self::pixels(6, 8, 4, 4), 6, 8, false, null, null);
        // Re-pack IDAT with one row missing.
        $idat = (string) gzcompress(str_repeat("\x00", 3 * (1 + 16)));
        $truncated = substr($png, 0, 33).self::chunk('IDAT', $idat).self::chunk('IEND', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('truncated');

        PdfImage::fromBytes($truncated);
    }

    /**
     * Rasterises the result: transparent palette entries let the white page
     * through, opaque ones keep their colour — for a palette and a 16-bit
     * interlaced RGBA image.
     */
    #[Test]
    public function renders_with_pdftoppm(): void
    {
        exec('pdftoppm -v 2>&1', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('pdftoppm not available');
        }

        // 2×2 palette, 2-bit, interlaced: red, transparent / blue, green.
        $paletteImage = PdfImage::fromBytes(self::png(
            [[[0], [3]], [[2], [1]]],
            3, 2, true,
            "\xFF\x00\x00\x00\xFF\x00\x00\x00\xFF\x00\x00\x00",
            "\xFF\xFF\xFF\x00",
        ));
        // 2×2 RGBA, 16-bit, interlaced: transparent / blue // green / red.
        $rgbaImage = PdfImage::fromBytes(self::png(
            [[[65535, 0, 0, 0], [0, 0, 65535, 65535]], [[0, 65535, 0, 65535], [65535, 0, 0, 65535]]],
            6, 16, true, null, null,
        ));

        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage(customDimensionsPt: [80, 40]);
        $page->drawImage($paletteImage, 0, 0, 40, 40);
        $page->drawImage($rgbaImage, 40, 0, 40, 40);

        $dir = sys_get_temp_dir().'/php-pdf-png-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/in.pdf", $pdf->toBytes());
            exec(sprintf('pdftoppm -r 72 -singlefile %s %s 2>&1', escapeshellarg("$dir/in.pdf"), escapeshellarg("$dir/out")), $o, $rc);
            self::assertSame(0, $rc, implode("\n", $o));
            $pixel = self::ppm((string) file_get_contents("$dir/out.ppm"));
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }

        // Each source pixel covers 20×20 px; sample cell centres (top-down).
        self::assertSame([255, 0, 0], $pixel(10, 10));
        self::assertSame([255, 255, 255], $pixel(30, 10));
        self::assertSame([0, 0, 255], $pixel(10, 30));
        self::assertSame([0, 255, 0], $pixel(30, 30));

        self::assertSame([255, 255, 255], $pixel(50, 10));
        self::assertSame([0, 0, 255], $pixel(70, 10));
        self::assertSame([0, 255, 0], $pixel(50, 30));
        self::assertSame([255, 0, 0], $pixel(70, 30));
    }

    // ---- helpers -------------------------------------------------------

    /**
     * Deterministic pixels with every sample in range; alpha spans 0..max
     * so the mask is never dropped as opaque.
     *
     * @return list<list<list<int>>>
     */
    private static function pixels(int $colorType, int $bitDepth, int $width, int $height): array
    {
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$colorType];
        $max = (1 << $bitDepth) - 1;
        $rows = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $p = [];
                for ($c = 0; $c < $channels; $c++) {
                    $v = ($x * 7919 + $y * 104729 + $c * 15485863 + $x * $y * 31) % ($max + 1);
                    $p[] = $v;
                }
                if ($colorType === 4 || $colorType === 6) {
                    $p[$channels - 1] = ($x + $y) % 3 === 0 ? 0 : (($x + $y) % 3 === 1 ? $max : intdiv($max, 3));
                }
                $row[] = $p;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function palette(int $bitDepth): string
    {
        $palette = '';
        for ($i = 0; $i < (1 << $bitDepth); $i++) {
            $palette .= chr($i * 37 & 0xFF).chr($i * 91 & 0xFF).chr(255 - $i);
        }

        return $palette;
    }

    /**
     * Packs pixel rows at the given bit depth: big-endian 16-bit, sub-byte
     * samples MSB first, every row padded to a whole byte.
     *
     * @param list<list<list<int>>> $pixels
     * @return list<string>
     */
    private static function packRows(array $pixels, int $bitDepth): array
    {
        $rows = [];
        foreach ($pixels as $row) {
            $bytes = '';
            $acc = 0;
            $bits = 0;
            foreach ($row as $p) {
                foreach ($p as $v) {
                    if ($bitDepth === 16) {
                        $bytes .= pack('n', $v);
                    } elseif ($bitDepth === 8) {
                        $bytes .= chr($v);
                    } else {
                        $acc = ($acc << $bitDepth) | $v;
                        $bits += $bitDepth;
                        if ($bits === 8) {
                            $bytes .= chr($acc);
                            $acc = 0;
                            $bits = 0;
                        }
                    }
                }
            }
            if ($bits > 0) {
                $bytes .= chr($acc << (8 - $bits));
            }
            $rows[] = $bytes;
        }

        return $rows;
    }

    /** @param list<list<list<int>>> $pixels */
    private static function rows(array $pixels, int $bitDepth): string
    {
        return implode('', self::packRows($pixels, $bitDepth));
    }

    /**
     * Filtered scanlines of one (sub)image; the filter cycles 0..4 by row.
     *
     * @param list<list<list<int>>> $pixels
     */
    private static function scanlines(array $pixels, int $bitDepth): string
    {
        if ($pixels === [] || $pixels[0] === []) {
            return '';
        }
        $bpp = max(1, intdiv(count($pixels[0][0]) * $bitDepth, 8));
        $out = '';
        $prev = null;
        foreach (self::packRows($pixels, $bitDepth) as $y => $row) {
            $prev ??= str_repeat("\x00", strlen($row));
            $filter = $y % 5;
            $line = chr($filter);
            for ($i = 0, $n = strlen($row); $i < $n; $i++) {
                $x = ord($row[$i]);
                $a = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
                $b = ord($prev[$i]);
                $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                $predicted = match ($filter) {
                    0 => 0,
                    1 => $a,
                    2 => $b,
                    3 => intdiv($a + $b, 2),
                    default => self::paeth($a, $b, $c),
                };
                $line .= chr(($x - $predicted) & 0xFF);
            }
            $out .= $line;
            $prev = $row;
        }

        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }

    /** @param list<list<list<int>>> $pixels */
    private static function png(array $pixels, int $colorType, int $bitDepth, bool $interlaced, ?string $palette, ?string $trns): string
    {
        $height = count($pixels);
        $width = count($pixels[0]);

        if (! $interlaced) {
            $data = self::scanlines($pixels, $bitDepth);
        } else {
            $data = '';
            foreach ([[0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4], [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2]] as [$x0, $y0, $dx, $dy]) {
                $pass = [];
                for ($y = $y0; $y < $height; $y += $dy) {
                    $row = [];
                    for ($x = $x0; $x < $width; $x += $dx) {
                        $row[] = $pixels[$y][$x];
                    }
                    if ($row !== []) {
                        $pass[] = $row;
                    }
                }
                $data .= self::scanlines($pass, $bitDepth);
            }
        }

        $png = "\x89PNG\r\n\x1A\n"
            .self::chunk('IHDR', pack('NNCCCCC', $width, $height, $bitDepth, $colorType, 0, 0, $interlaced ? 1 : 0));
        if ($palette !== null) {
            $png .= self::chunk('PLTE', $palette);
        }
        if ($trns !== null) {
            $png .= self::chunk('tRNS', $trns);
        }
        // Split IDAT in two: decoders must concatenate chunks.
        $z = (string) gzcompress($data, 9);
        $half = intdiv(strlen($z), 2);

        return $png.self::chunk('IDAT', substr($z, 0, $half)).self::chunk('IDAT', substr($z, $half)).self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /** Image stream decoded as a reader would: inflate, then the predictor. */
    private static function decodedSamples(PdfImage $image): string
    {
        $data = (string) gzuncompress($image->imageData);
        $parms = $image->decodeParms;
        if ($parms === null) {
            return $data;
        }

        return Filters::applyPredictor($data, $parms['Predictor'], $parms['Colors'], $parms['BitsPerComponent'], $parms['Columns']);
    }

    private static function registered(PdfImage $image): string
    {
        $writer = new Writer;
        $image->registerWith($writer);
        $writer->setRoot($writer->addObject('<< /Type /Catalog >>'));

        return $writer->toBytes();
    }

    private static function imageDictionary(PdfImage $image): string
    {
        preg_match('@<< /Type /XObject /Subtype /Image .*?>>(?=\nstream)@', self::registered($image), $m);
        self::assertNotEmpty($m);

        return $m[0];
    }

    /** @return \Closure(int, int): list<int> RGB at (x, y), top-down */
    private static function ppm(string $ppm): \Closure
    {
        self::assertSame(1, preg_match('/^P6\s+(\d+)\s+(\d+)\s+255\s/', $ppm, $m));
        $width = (int) $m[1];
        $offset = strlen($m[0]);

        return static function (int $x, int $y) use ($ppm, $width, $offset): array {
            $at = $offset + ($y * $width + $x) * 3;

            return [ord($ppm[$at]), ord($ppm[$at + 1]), ord($ppm[$at + 2])];
        };
    }
}
