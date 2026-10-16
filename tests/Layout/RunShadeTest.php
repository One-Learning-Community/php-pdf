<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\RunStyle;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A run's background and highlight are painted under its text. */
final class RunShadeTest extends TestCase
{
    private static function render(RunStyle $style): string
    {
        return (new Document(new Section([new Paragraph([
            new Run('plain '),
            new Run('shaded', $style),
            new Run(' plain'),
        ])])))->toBytes(new Engine(compressStreams: false));
    }

    #[Test]
    public function a_background_lies_under_its_words_only(): void
    {
        $pdf = self::render(new RunStyle(backgroundColor: 'ffcc00'));

        // The word, and at most the space after it (drawn in the word's style).
        $fills = PdfGeometry::fills($pdf, 'ffcc00');
        self::assertNotEmpty($fills);
        $word = PdfGeometry::word($pdf, 'shaded');
        self::assertEqualsWithDelta($word['xMin'], $fills[0]['x'], 0.5);
        // Standard-font metrics are approximate: within 10% of what poppler measures.
        self::assertEqualsWithDelta($word['xMax'] - $word['xMin'], $fills[0]['w'], ($word['xMax'] - $word['xMin']) * 0.1);
        self::assertLessThan(PdfGeometry::words($pdf)[2]['xMin'] + 0.5, max(array_map(static fn (array $f): float => $f['x'] + $f['w'], $fills)));
        self::assertLessThanOrEqual($word['yMin'] + 0.5, $fills[0]['top']);
        self::assertGreaterThanOrEqual($word['yMax'] - 0.5, $fills[0]['bottom']);
    }

    #[Test]
    public function a_named_highlight_is_painted_too(): void
    {
        self::assertNotEmpty(PdfGeometry::fills(self::render(new RunStyle(highlight: 'yellow')), 'ffff00'));
    }
}
