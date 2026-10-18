<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Support;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfBytesTest extends TestCase
{
    private static function render(string $date, string $text = 'same'): string
    {
        $doc = Document::new(compressStreams: false);
        $doc->metadata(creationDate: new \DateTimeImmutable($date));
        $doc->addPage()->showText($text, 72, 720, StandardFont::Helvetica, 12);

        return $doc->toBytes();
    }

    #[Test]
    public function renders_a_second_apart_compare_equal(): void
    {
        $a = self::render('2026-10-18 16:52:08');
        $b = self::render('2026-10-18 16:52:09');

        self::assertNotSame($a, $b, 'The dates must differ for the test to mean anything');
        self::assertSame(PdfBytes::stable($a), PdfBytes::stable($b));
    }

    #[Test]
    public function a_real_difference_survives(): void
    {
        self::assertNotSame(
            PdfBytes::stable(self::render('2026-10-18 16:52:08', 'one')),
            PdfBytes::stable(self::render('2026-10-18 16:52:08', 'two')),
        );
    }
}
