<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One image instance drawn into several documents — a logo cached between
 * requests — must be emitted into each of them.
 */
final class ImageReuseTest extends TestCase
{
    #[Test]
    public function a_shared_image_points_at_an_image_in_every_document(): void
    {
        $image = PdfImage::fromPath(__DIR__.'/../fixtures/sample.png');

        $first = Document::new(compressStreams: false);
        $first->addPage()->drawImage($image, 50, 50, 100, 100);
        $first->toBytes();

        // Different objects before the image, so a stale number would land elsewhere.
        $second = Document::new(compressStreams: false);
        $page = $second->addPage();
        $page->showText('a', 10, 10, StandardFont::Helvetica, 10);
        $page->showText('b', 10, 30, StandardFont::TimesRoman, 10);
        $page->drawImage($image, 50, 50, 100, 100);
        $bytes = $second->toBytes();

        self::assertSame(1, preg_match('@/Im1 (\d+) 0 R@', $bytes, $m));
        self::assertMatchesRegularExpression('@\n'.$m[1].' 0 obj\n<< /Type /XObject /Subtype /Image@', $bytes);
    }

    #[Test]
    public function a_shared_font_does_not_keep_finished_documents_alive(): void
    {
        $path = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($path)) {
            self::markTestSkipped('Liberation fonts not fetched');
        }
        $font = new \Dskripchenko\PhpPdf\Pdf\PdfFont(\Dskripchenko\PhpPdf\Font\Ttf\TtfFile::fromFile($path));
        $image = PdfImage::fromPath(__DIR__.'/../fixtures/sample.png');
        $render = static function () use ($font, $image): void {
            $doc = Document::new();
            $page = $doc->addPage();
            $page->showEmbeddedText(str_repeat('Память ', 40), 10, 700, $font, 10);
            $page->drawImage($image, 50, 50, 100, 100);
            $doc->toBytes();
        };
        for ($i = 0; $i < 20; $i++) {
            $render();
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        for ($i = 0; $i < 100; $i++) {
            $render();
        }
        gc_collect_cycles();

        // A retained writer per document would add megabytes here.
        self::assertLessThan(512 * 1024, memory_get_usage() - $before);
    }
}
