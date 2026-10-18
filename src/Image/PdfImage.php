<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Image;

use Dskripchenko\PhpPdf\Pdf\Writer;

/**
 * Image XObject for PDF embedding.
 *
 * Supports PNG and JPEG. For JPEG passes bytes through (PDF accepts
 * DCT-encoded streams natively). For PNG:
 *  - non-interlaced gray / RGB / palette: IDAT passed through as a Flate
 *    stream with the PNG predictor (`/DecodeParms /Predictor 15`);
 *  - palette (color type 3, 1/2/4/8 bit): `/Indexed` over `/DeviceRGB`,
 *    tRNS alpha becomes an 8-bit `/SMask`;
 *  - gray+alpha / RGBA: decoded, alpha split into an `/SMask`;
 *  - 16-bit channels: kept as `/BitsPerComponent 16` (SMask too);
 *  - Adam7 interlacing: decoded into plain scanlines and re-compressed.
 *
 * SCOPE LIMITATIONS:
 *  - JPEG: only baseline / extended sequential DCT (SOF0/SOF1/SOF2/SOF3)
 *  - PNG tRNS colour key for gray / RGB: ignored
 *  - ICC color profiles, gamma: skip
 *
 * Usage:
 *   $img = PdfImage::fromPath('/tmp/photo.jpg');
 *   $imgRef = $img->registerWith($writer);
 *   // in content stream:
 *   q  w 0 0 h x y cm  /Im1 Do  Q
 */
final class PdfImage
{
    /**
     * Object number per writer. An image instance is often reused — a logo
     * cached between requests — and a number from one document means
     * another object in the next.
     *
     * @var \WeakMap<Writer, int>|null
     */
    private ?\WeakMap $objectIds = null;

    public function __construct(
        public readonly int $widthPx,
        public readonly int $heightPx,
        public readonly string $filter,         // /FlateDecode or /DCTDecode
        public readonly string $colorSpace,     // /DeviceRGB or /DeviceGray (base space when $palette is set)
        public readonly int $bitsPerComponent,  // 1, 2, 4, 8 or 16
        public readonly string $imageData,      // raw bytes for PDF stream
        /**
         * The alpha channel as a separate stream (Flate, 8 bit, DeviceGray).
         *
         * PDF cannot carry transparency inside the image itself: it is given by
         * a separate mask object through `/SMask`. The alpha used to be simply
         * discarded, and transparent pixels printed in whatever colour lay
         * beneath them — for signatures and stamps that is a black rectangle
         * instead of the background.
         */
        public readonly ?string $alphaData = null,
        /**
         * Palette as RGB triplets (PNG PLTE). When set, samples are indices
         * and the image is emitted as `[/Indexed $colorSpace hival <palette>]`.
         */
        public readonly ?string $palette = null,
        /** Bits per component of the alpha stream: 8, or 16 for 16-bit PNG. */
        public readonly int $alphaBitsPerComponent = 8,
        /**
         * `/DecodeParms` of the image stream — the PNG predictor when IDAT is
         * passed through as is.
         *
         * @var array<string, int>|null
         */
        public readonly ?array $decodeParms = null,
    ) {}

    /** The `/ColorSpace` operand: a name, or an `/Indexed` array for a palette. */
    private function colorSpaceOperand(): string
    {
        if ($this->palette === null) {
            return $this->colorSpace;
        }

        return sprintf(
            '[/Indexed %s %d <%s>]',
            $this->colorSpace,
            intdiv(strlen($this->palette), 3) - 1,
            bin2hex($this->palette),
        );
    }

    public static function fromPath(string $path): self
    {
        if (! is_readable($path)) {
            throw new \InvalidArgumentException("Cannot read image: $path");
        }
        $bytes = (string) file_get_contents($path);

        return self::fromBytes($bytes);
    }

    public static function fromBytes(string $bytes): self
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return self::parseJpeg($bytes);
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return self::parsePng($bytes);
        }
        throw new \InvalidArgumentException('Unsupported image format (expected PNG or JPEG).');
    }

    public function registerWith(Writer $writer): int
    {
        $this->objectIds ??= new \WeakMap;
        if (isset($this->objectIds[$writer])) {
            return $this->objectIds[$writer];
        }
        // The mask is registered first: its identifier is needed in the
        // dictionary of the image itself. PDF cannot carry transparency inside
        // an image — it lives as a separate object, attached via `/SMask`.
        $smask = '';
        if ($this->alphaData !== null && $this->alphaData !== '') {
            $maskId = $writer->addObject(sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d "
                ."/ColorSpace /DeviceGray /BitsPerComponent %d /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                $this->widthPx,
                $this->heightPx,
                $this->alphaBitsPerComponent,
                strlen($this->alphaData),
                $this->alphaData,
            ));
            $smask = sprintf(' /SMask %d 0 R', $maskId);
        }

        $parms = '';
        if ($this->decodeParms !== null) {
            foreach ($this->decodeParms as $key => $value) {
                $parms .= sprintf(' /%s %d', $key, $value);
            }
            $parms = ' /DecodeParms <<'.$parms.' >>';
        }

        $dict = sprintf(
            '<< /Type /XObject /Subtype /Image /Width %d /Height %d '
            .'/ColorSpace %s /BitsPerComponent %d /Filter %s%s%s /Length %d >>',
            $this->widthPx,
            $this->heightPx,
            $this->colorSpaceOperand(),
            $this->bitsPerComponent,
            $this->filter,
            $parms,
            $smask,
            strlen($this->imageData),
        );

        $id = $writer->addObject(sprintf(
            "%s\nstream\n%s\nendstream",
            $dict, $this->imageData,
        ));
        $this->objectIds[$writer] = $id;

        return $id;
    }

    /**
     * Parses a JPEG file. PDF accepts DCT-encoded streams directly (without
     * decoding), so the raw bytes are simply wrapped.
     *
     * JPEG structure: SOI(FF D8) + segments + SOS + compressed
     * data + EOI(FF D9). Each segment marker = 0xFF + type byte +
     * 2-byte length (the length bytes themselves are included).
     *
     * For dimensions, look for SOF0/SOF1/SOF2/SOF3 marker (0xFFC0..0xFFC3):
     *   1 byte sample precision
     *   2 bytes height (big-endian)
     *   2 bytes width
     *   1 byte numComponents (1=gray, 3=RGB, 4=CMYK)
     */
    private static function parseJpeg(string $bytes): self
    {
        $len = strlen($bytes);
        $pos = 2; // skip SOI marker FF D8

        while ($pos < $len - 1) {
            if (ord($bytes[$pos]) !== 0xFF) {
                throw new \RuntimeException('Invalid JPEG: marker expected at pos '.$pos);
            }
            $marker = ord($bytes[$pos + 1]);
            $pos += 2;

            // Markers without payload (TEM, RSTn, etc.)
            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            // SOS — start of scan; dimensions should already have been in SOF.
            if ($marker === 0xDA) {
                break;
            }

            // Payload length (includes the length bytes themselves, not payload only).
            $segLen = (ord($bytes[$pos]) << 8) | ord($bytes[$pos + 1]);

            // SOF0..SOF3 — baseline / extended / progressive / lossless.
            if ($marker >= 0xC0 && $marker <= 0xC3) {
                $precision = ord($bytes[$pos + 2]);
                $height = (ord($bytes[$pos + 3]) << 8) | ord($bytes[$pos + 4]);
                $width = (ord($bytes[$pos + 5]) << 8) | ord($bytes[$pos + 6]);
                $components = ord($bytes[$pos + 7]);
                $colorSpace = match ($components) {
                    1 => '/DeviceGray',
                    3 => '/DeviceRGB',
                    4 => '/DeviceCMYK',
                    default => throw new \RuntimeException("Unsupported JPEG components: $components"),
                };

                return new self(
                    widthPx: $width,
                    heightPx: $height,
                    filter: '/DCTDecode',
                    colorSpace: $colorSpace,
                    bitsPerComponent: $precision,
                    imageData: $bytes,
                );
            }

            $pos += $segLen;
        }
        throw new \RuntimeException('JPEG SOF marker not found.');
    }

    /** Adam7 passes: [x start, y start, x step, y step]. */
    private const ADAM7 = [
        [0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4],
        [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2],
    ];

    /**
     * Parses PNG file. PNG = magic (8 bytes) + sequence of chunks.
     * Each chunk: 4-byte length + 4-byte type + data + 4-byte CRC.
     *
     * IHDR (first chunk): 13 bytes
     *   4 bytes width
     *   4 bytes height
     *   1 byte bit depth
     *   1 byte color type (2 = RGB, 6 = RGBA, 0 = gray, 3 = palette, 4 = gray+alpha)
     *   1 byte compression (always 0 = deflate)
     *   1 byte filter (always 0)
     *   1 byte interlace (0 = none, 1 = Adam7)
     *
     * IDAT chunks — zlib-deflated, PNG-filtered scanlines, concatenated.
     * PDF's Flate filter with `/Predictor 15` undoes exactly that, so a
     * non-interlaced image without an alpha channel is embedded as is —
     * no decode, no re-compression. Alpha channels and Adam7 need the
     * pixels, so those images are decoded and re-compressed.
     */
    private static function parsePng(string $bytes): self
    {
        $pos = 8; // skip magic
        $len = strlen($bytes);

        $width = 0;
        $height = 0;
        $colorType = 0;
        $bitDepth = 0;
        $interlace = 0;
        $idat = '';
        $palette = null;
        $trns = null;

        while ($pos + 8 <= $len) {
            $chunkLen = (ord($bytes[$pos]) << 24)
                | (ord($bytes[$pos + 1]) << 16)
                | (ord($bytes[$pos + 2]) << 8)
                | ord($bytes[$pos + 3]);
            $type = substr($bytes, $pos + 4, 4);
            $data = substr($bytes, $pos + 8, $chunkLen);
            $pos += 8 + $chunkLen + 4; // length + type + data + crc

            if ($type === 'IHDR' && strlen($data) >= 13) {
                $width = (ord($data[0]) << 24) | (ord($data[1]) << 16)
                    | (ord($data[2]) << 8) | ord($data[3]);
                $height = (ord($data[4]) << 24) | (ord($data[5]) << 16)
                    | (ord($data[6]) << 8) | ord($data[7]);
                $bitDepth = ord($data[8]);
                $colorType = ord($data[9]);
                $interlace = ord($data[12]);
            } elseif ($type === 'PLTE') {
                $palette = $data;
            } elseif ($type === 'tRNS') {
                $trns = $data;
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
        }

        if ($width <= 0 || $height <= 0 || $idat === '') {
            throw new \RuntimeException('Invalid PNG: missing IHDR or IDAT.');
        }
        if ($interlace > 1) {
            throw new \RuntimeException("PNG interlace method $interlace not supported.");
        }

        // Color type → ColorSpace + samples per pixel. A palette image has
        // one sample (the index) over an /Indexed /DeviceRGB space.
        [$colorSpace, $channels, $depths] = match ($colorType) {
            0 => ['/DeviceGray', 1, [1, 2, 4, 8, 16]],
            2 => ['/DeviceRGB', 3, [8, 16]],
            3 => ['/DeviceRGB', 1, [1, 2, 4, 8]],
            4 => ['/DeviceGray', 2, [8, 16]],
            6 => ['/DeviceRGB', 4, [8, 16]],
            default => throw new \RuntimeException("PNG color type $colorType not supported."),
        };
        if (! in_array($bitDepth, $depths, true)) {
            throw new \RuntimeException("PNG bit depth $bitDepth not supported for color type $colorType.");
        }
        if ($colorType === 3) {
            if ($palette === null || $palette === '' || strlen($palette) % 3 !== 0 || strlen($palette) > 768) {
                throw new \RuntimeException('Invalid PNG: missing or malformed PLTE.');
            }
        } else {
            $palette = null; // a suggested palette of a truecolor image
        }
        $bitsPerPixel = $channels * $bitDepth;

        // Palette transparency: tRNS holds one alpha per palette entry. A tRNS
        // with only opaque entries changes nothing.
        $paletteAlpha = $colorType === 3 && $trns !== null && strspn($trns, "\xFF") !== strlen($trns);

        // The common case: IDAT goes into the PDF untouched.
        if ($interlace === 0 && $colorType !== 4 && $colorType !== 6) {
            $alpha = $paletteAlpha
                ? self::paletteAlpha(self::unfilter(self::inflate($idat), 0, $width, $height, $bitsPerPixel), (string) $trns, $width, $height, $bitDepth)
                : null;

            return new self(
                widthPx: $width,
                heightPx: $height,
                filter: '/FlateDecode',
                colorSpace: $colorSpace,
                bitsPerComponent: $bitDepth,
                imageData: $idat,
                alphaData: $alpha !== null ? (string) gzcompress($alpha) : null,
                palette: $palette,
                decodeParms: [
                    'Predictor' => 15,
                    'Colors' => $channels,
                    'BitsPerComponent' => $bitDepth,
                    'Columns' => $width,
                ],
            );
        }

        $inflated = self::inflate($idat);
        $raw = $interlace === 1
            ? self::deinterlace($inflated, $width, $height, $bitsPerPixel)
            : self::unfilter($inflated, 0, $width, $height, $bitsPerPixel);

        // The alpha channel is separated from the colour: PDF keeps
        // transparency as a separate mask object (`/SMask`), and it cannot live
        // inside the image itself. The alpha used to be simply thrown away, and
        // transparent pixels printed in whatever colour lay beneath them — for
        // signatures and stamps that is a black rectangle instead of the
        // background.
        $alpha = null;
        if ($colorType === 4 || $colorType === 6) {
            $sampleBytes = $bitDepth >> 3;
            [$raw, $alpha] = self::splitAlpha($raw, ($channels - 1) * $sampleBytes, $sampleBytes);
        } elseif ($paletteAlpha) {
            $alpha = self::paletteAlpha($raw, (string) $trns, $width, $height, $bitDepth);
        }

        // A fully opaque image needs no mask — an extra object in the file buys
        // nothing.
        if ($alpha !== null && strspn($alpha, "\xFF") === strlen($alpha)) {
            $alpha = null;
        }

        return new self(
            widthPx: $width,
            heightPx: $height,
            filter: '/FlateDecode',
            colorSpace: $colorSpace,
            bitsPerComponent: $bitDepth,
            imageData: (string) gzcompress($raw),
            alphaData: $alpha !== null ? (string) gzcompress($alpha) : null,
            palette: $palette,
            // Palette alpha comes from 8-bit tRNS entries.
            alphaBitsPerComponent: $colorType === 3 ? 8 : $bitDepth,
        );
    }

    private static function inflate(string $idat): string
    {
        $inflated = @gzuncompress($idat);
        if ($inflated === false) {
            throw new \RuntimeException('Failed to inflate PNG IDAT.');
        }

        return $inflated;
    }

    /**
     * Undoes the PNG filters of $height scanlines starting at $offset.
     * Each scanline is a filter byte followed by ceil(width × bits / 8)
     * bytes; filters work on whole bytes (one byte for sub-byte pixels).
     */
    private static function unfilter(string $data, int $offset, int $width, int $height, int $bitsPerPixel): string
    {
        $rowBytes = intdiv($width * $bitsPerPixel + 7, 8);
        if (strlen($data) < $offset + $height * ($rowBytes + 1)) {
            throw new \RuntimeException('PNG image data is truncated.');
        }
        $bytesPerPixel = max(1, $bitsPerPixel >> 3);
        $out = '';
        $prevRow = str_repeat("\x00", $rowBytes);
        for ($y = 0; $y < $height; $y++) {
            $at = $offset + $y * ($rowBytes + 1);
            $row = self::applyPngFilter(ord($data[$at]), substr($data, $at + 1, $rowBytes), $prevRow, $bytesPerPixel);
            $out .= $row;
            $prevRow = $row;
        }

        return $out;
    }

    /**
     * Adam7: seven reduced images, each filtered on its own, whose pixels are
     * scattered back onto the full grid. The result is the plain scanlines
     * of a non-interlaced image, without filter bytes.
     */
    private static function deinterlace(string $data, int $width, int $height, int $bitsPerPixel): string
    {
        $rows = array_fill(0, $height, str_repeat("\x00", intdiv($width * $bitsPerPixel + 7, 8)));
        $bytesPerPixel = $bitsPerPixel >> 3;
        $mask = (1 << $bitsPerPixel) - 1;
        $offset = 0;

        foreach (self::ADAM7 as [$x0, $y0, $dx, $dy]) {
            $passWidth = $width > $x0 ? intdiv($width - $x0 + $dx - 1, $dx) : 0;
            $passHeight = $height > $y0 ? intdiv($height - $y0 + $dy - 1, $dy) : 0;
            if ($passWidth === 0 || $passHeight === 0) {
                continue; // an empty pass has no scanlines, not even filter bytes
            }
            $passRowBytes = intdiv($passWidth * $bitsPerPixel + 7, 8);
            $pass = self::unfilter($data, $offset, $passWidth, $passHeight, $bitsPerPixel);
            $offset += $passHeight * ($passRowBytes + 1);

            for ($j = 0; $j < $passHeight; $j++) {
                $y = $y0 + $j * $dy;
                $src = substr($pass, $j * $passRowBytes, $passRowBytes);
                $dst = $rows[$y];
                for ($i = 0; $i < $passWidth; $i++) {
                    $x = $x0 + $i * $dx;
                    if ($bytesPerPixel > 0) {
                        for ($k = 0; $k < $bytesPerPixel; $k++) {
                            $dst[$x * $bytesPerPixel + $k] = $src[$i * $bytesPerPixel + $k];
                        }
                    } else {
                        // Sub-byte pixels (1/2/4-bit gray or palette): move bits.
                        $srcBit = $i * $bitsPerPixel;
                        $value = (ord($src[$srcBit >> 3]) >> (8 - $bitsPerPixel - ($srcBit & 7))) & $mask;
                        $dstBit = $x * $bitsPerPixel;
                        $at = $dstBit >> 3;
                        $dst[$at] = chr(ord($dst[$at]) | ($value << (8 - $bitsPerPixel - ($dstBit & 7))));
                    }
                }
                $rows[$y] = $dst;
            }
        }

        return implode('', $rows);
    }

    /**
     * Splits interleaved pixels into colour and alpha streams.
     *
     * @return array{string, string}
     */
    private static function splitAlpha(string $raw, int $colorBytes, int $alphaBytes): array
    {
        $color = '';
        $alpha = '';
        $pixelBytes = $colorBytes + $alphaBytes;
        $n = strlen($raw);
        for ($i = 0; $i < $n; $i += $pixelBytes) {
            $color .= substr($raw, $i, $colorBytes);
            $alpha .= substr($raw, $i + $colorBytes, $alphaBytes);
        }

        return [$color, $alpha];
    }

    /**
     * Per-pixel 8-bit alpha of a palette image: indices (unpacked from
     * 1/2/4-bit rows if needed) mapped through the tRNS table. Entries
     * missing from tRNS are opaque.
     */
    private static function paletteAlpha(string $raw, string $trns, int $width, int $height, int $bitDepth): string
    {
        if ($bitDepth === 8) {
            $indices = $raw;
        } else {
            $rowBytes = intdiv($width * $bitDepth + 7, 8);
            $mask = (1 << $bitDepth) - 1;
            $indices = '';
            for ($y = 0; $y < $height; $y++) {
                $row = substr($raw, $y * $rowBytes, $rowBytes);
                for ($x = 0; $x < $width; $x++) {
                    $bit = $x * $bitDepth;
                    $indices .= chr((ord($row[$bit >> 3]) >> (8 - $bitDepth - ($bit & 7))) & $mask);
                }
            }
        }

        return strtr($indices, implode('', array_map('chr', range(0, 255))), str_pad(substr($trns, 0, 256), 256, "\xFF"));
    }

    /**
     * Apply PNG filter to a scanline. Filter byte (0..4):
     *   0 = None (passthrough)
     *   1 = Sub (x - left)
     *   2 = Up (x - above)
     *   3 = Average (x - (left + above) / 2)
     *   4 = Paeth (x - paeth predictor)
     *
     * Minimal implementation for several filter types — filter 0 (None)
     * is sufficient as libpng defaults to it for small images.
     */
    private static function applyPngFilter(int $filter, string $row, string $prev, int $bytesPerPixel): string
    {
        if ($filter === 0) {
            return $row;
        }
        $len = strlen($row);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $x = ord($row[$i]);
            $a = $i >= $bytesPerPixel ? ord($out[$i - $bytesPerPixel]) : 0;
            $b = ord($prev[$i]);
            $c = $i >= $bytesPerPixel ? ord($prev[$i - $bytesPerPixel]) : 0;

            $result = match ($filter) {
                1 => ($x + $a) & 0xFF,
                2 => ($x + $b) & 0xFF,
                3 => ($x + (int) (($a + $b) / 2)) & 0xFF,
                4 => ($x + self::paethPredictor($a, $b, $c)) & 0xFF,
                default => throw new \RuntimeException("Unknown PNG filter $filter"),
            };
            $out .= chr($result);
        }

        return $out;
    }

    private static function paethPredictor(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }

        return $c;
    }
}
