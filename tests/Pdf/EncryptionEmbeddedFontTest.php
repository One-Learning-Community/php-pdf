<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\EncryptionAlgorithm;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Encryption must leave stream data to the stream cipher: scanning compressed
 * font bytes for strings used to corrupt embedded fonts. And non-ASCII text
 * strings — written as hex — must be encrypted too.
 */
final class EncryptionEmbeddedFontTest extends TestCase
{
    private const FONT = __DIR__.'/../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

    /** @return iterable<string, array{EncryptionAlgorithm}> */
    public static function algorithms(): iterable
    {
        yield 'RC4-128' => [EncryptionAlgorithm::Rc4_128];
        yield 'AES-128' => [EncryptionAlgorithm::Aes_128];
        yield 'AES-256' => [EncryptionAlgorithm::Aes_256];
    }

    #[Test]
    #[DataProvider('algorithms')]
    public function embedded_fonts_and_text_strings_survive_encryption(EncryptionAlgorithm $algorithm): void
    {
        if (! is_readable(self::FONT) || ! function_exists('openssl_encrypt')) {
            self::markTestSkipped('Liberation fonts or openssl not available');
        }
        $font = new PdfFont(TtfFile::fromFile(self::FONT));
        $doc = Document::new();
        $page = $doc->addPage();
        for ($i = 0; $i < 30; $i++) {
            $page->showEmbeddedText("Строка $i ((скобки)) текст", 50, 800 - $i * 20, $font, 11);
        }
        $doc->metadata(title: 'Отчёт (за) год', author: 'Plain (ASCII)');
        $doc->encrypt(userPassword: '', ownerPassword: 'owner', algorithm: $algorithm);
        $bytes = $doc->toBytes();

        $reader = ReaderDocument::fromBytes($bytes);
        $info = $reader->deref($reader->trailer()->get('Info'));
        self::assertInstanceOf(PdfDictionary::class, $info);
        $title = $info->get('Title');
        self::assertInstanceOf(PdfString::class, $title);
        self::assertSame("\xFE\xFF".mb_convert_encoding('Отчёт (за) год', 'UTF-16BE', 'UTF-8'), $title->bytes);
        self::assertSame('Plain (ASCII)', $info->get('Author') instanceof PdfString ? $info->get('Author')->bytes : null);

        // The embedded font program decrypts and inflates to a TrueType file.
        $fontFile = null;
        foreach ($reader->objectNumbers() as $num) {
            $obj = $reader->getObject($num);
            if ($obj instanceof PdfStream && $obj->dict->get('Length1') !== null) {
                $fontFile = $reader->streamData($obj);
            }
        }
        self::assertNotNull($fontFile, 'No FontFile2 stream');
        self::assertContains(substr($fontFile, 0, 4), ["\x00\x01\x00\x00", 'true']);

        exec('command -v pdftotext', $out, $code);
        if ($code === 0) {
            $path = sys_get_temp_dir().'/phppdf-enc-'.bin2hex(random_bytes(6)).'.pdf';
            file_put_contents($path, $bytes);
            $text = (string) shell_exec('pdftotext '.escapeshellarg($path).' - 2>&1');
            @unlink($path);
            self::assertStringContainsString('Строка 0 ((скобки)) текст', $text);
            self::assertStringNotContainsString('Syntax Error', $text);
        }
    }
}
