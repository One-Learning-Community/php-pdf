<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Support;

/**
 * Where things landed on a rendered page — for layout tests that check
 * geometry, not bytes.
 *
 * Coordinates are in points from the TOP-left corner of the page, the way
 * pdftotext reports them; rectangles from the content stream are flipped to
 * match. Rectangles need an uncompressed stream (`compressStreams: false`).
 */
final class PdfGeometry
{
    /**
     * Words with their boxes, by `pdftotext -bbox`; null without poppler.
     *
     * @return list<array{text: string, page: int, xMin: float, yMin: float, xMax: float, yMax: float}>|null
     */
    public static function words(string $pdf): ?array
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'geo-').'.pdf';
        file_put_contents($path, $pdf);
        try {
            $xml = (string) shell_exec('pdftotext -bbox '.escapeshellarg($path).' - 2>/dev/null');
        } finally {
            @unlink($path);
        }

        $words = [];
        $page = 0;
        foreach (preg_split('/\R/', $xml) ?: [] as $line) {
            if (str_contains($line, '<page ')) {
                $page++;
            }
            if (preg_match('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">(.*)<\/word>/', $line, $m)) {
                $words[] = [
                    'text' => html_entity_decode($m[5], ENT_QUOTES | ENT_XML1, 'UTF-8'),
                    'page' => $page,
                    'xMin' => (float) $m[1], 'yMin' => (float) $m[2],
                    'xMax' => (float) $m[3], 'yMax' => (float) $m[4],
                ];
            }
        }

        return $words;
    }

    /**
     * The first word equal to `$text`.
     *
     * @return array{text: string, page: int, xMin: float, yMin: float, xMax: float, yMax: float}
     */
    public static function word(string $pdf, string $text): array
    {
        $words = self::words($pdf);
        if ($words === null) {
            \PHPUnit\Framework\Assert::markTestSkipped('pdftotext (poppler) is not available');
        }
        foreach ($words as $w) {
            if ($w['text'] === $text) {
                return $w;
            }
        }
        \PHPUnit\Framework\Assert::fail("No word \"$text\" on the page; words: ".implode(' ', array_column($words, 'text')));
    }

    /**
     * Filled (`f`) or stroked (`S`) rectangles with their colour.
     *
     * @return list<array{x: float, top: float, w: float, h: float, bottom: float, color: string, fill: bool}>
     */
    public static function rects(string $pdf, float $pageHeight = 841.89): array
    {
        preg_match_all(
            '/([\d.]+) ([\d.]+) ([\d.]+) (RG|rg)\n(?:[^\n]*\n)*?([\d.-]+) ([\d.-]+) ([\d.-]+) ([\d.-]+) re\n([fS])/',
            $pdf,
            $all,
            PREG_SET_ORDER,
        );
        $rects = [];
        foreach ($all as $m) {
            $y = (float) $m[6];
            $h = (float) $m[8];
            $rects[] = [
                'x' => (float) $m[5],
                'top' => $pageHeight - ($y + $h),
                'w' => (float) $m[7],
                'h' => $h,
                'bottom' => $pageHeight - $y,
                'color' => sprintf('%02x%02x%02x', (int) round((float) $m[1] * 255), (int) round((float) $m[2] * 255), (int) round((float) $m[3] * 255)),
                'fill' => $m[9] === 'f',
            ];
        }

        return $rects;
    }

    /**
     * Filled rectangles of one colour (hex, no #).
     *
     * @return list<array{x: float, top: float, w: float, h: float, bottom: float, color: string, fill: bool}>
     */
    public static function fills(string $pdf, string $color): array
    {
        return array_values(array_filter(self::rects($pdf), static fn (array $r): bool => $r['fill'] && $r['color'] === strtolower($color)));
    }
}
