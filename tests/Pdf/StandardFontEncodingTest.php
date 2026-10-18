<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The base-14 fonts are written with /WinAnsiEncoding, so UTF-8 text must
 * reach them as WinAnsi codes — not as its raw bytes, which every viewer
 * showed as "CafÃ©".
 */
final class StandardFontEncodingTest extends TestCase
{
    private static function page(): array
    {
        $doc = PdfDocument::new(compressStreams: false);

        return [$doc, $doc->addPage()];
    }

    #[Test]
    public function utf8_text_is_written_as_winansi_codes(): void
    {
        [$doc, $page] = self::page();
        $page->showText('Café · 10 € — “ok”', 72, 720, StandardFont::Helvetica, 12);

        // é = 0xE9, · = 0xB7, € = 0x80, — = 0x97, “ ” = 0x93 0x94.
        self::assertStringContainsString('(Caf\351 \267 10 \200 \227 \223ok\224) Tj', $doc->toBytes());
    }

    #[Test]
    public function a_character_outside_winansi_becomes_a_question_mark(): void
    {
        [$doc, $page] = self::page();
        $page->showText('Да, OK', 72, 720, StandardFont::TimesRoman, 12);

        self::assertStringContainsString('(??, OK) Tj', $doc->toBytes());
    }

    #[Test]
    public function symbol_fonts_and_winansi_bytes_pass_through(): void
    {
        [$doc, $page] = self::page();
        $page->showText('4', 72, 720, StandardFont::ZapfDingbats, 12);
        $page->showText("Caf\xE9", 72, 700, StandardFont::Helvetica, 12);
        $bytes = $doc->toBytes();

        self::assertStringContainsString('(4) Tj', $bytes);
        self::assertStringContainsString('(Caf\351) Tj', $bytes);
    }

    #[Test]
    public function watermarks_are_encoded_too(): void
    {
        [$doc, $page] = self::page();
        $page->drawWatermark('Entwurf · Ü', 300, 400, StandardFont::HelveticaBold, 48);

        self::assertStringContainsString('(Entwurf \267 \334) Tj', $doc->toBytes());
    }

    #[Test]
    public function a_laid_out_document_without_fonts_reads_back_right(): void
    {
        $pdf = (new Document(new Section([new Paragraph([new Run('Crème brûlée · 5 €')])])))->toBytes(new Engine(compressStreams: false));

        self::assertStringContainsString('Cr\350me', $pdf);
        self::assertStringContainsString('br\373l\351e \267 5 \200', $pdf);
    }

    #[Test]
    public function a_text_field_appearance_uses_winansi(): void
    {
        [$doc, $page] = self::page();
        $page->addFormField('text', 'city', 72, 700, 200, 20, defaultValue: 'Zürich');

        self::assertStringContainsString("(Z\xFCrich) Tj", $doc->toBytes());
    }
}
