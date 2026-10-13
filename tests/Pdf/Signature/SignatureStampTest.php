<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\SignatureField;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\TextBlock;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The signature stamp end to end: which field is signed, what its appearance
 * shows, repeated copies, and that the signature still verifies.
 */
final class SignatureStampTest extends TestCase
{
    private const FONT_DIR = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/';

    private string $certPem;

    private string $keyPem;

    protected function setUp(): void
    {
        if (! function_exists('openssl_pkcs7_sign')) {
            self::markTestSkipped('openssl extension not available');
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            self::markTestSkipped('openssl_pkey_new unavailable');
        }
        $csr = openssl_csr_new(['commonName' => 'Jane Doe', 'organizationName' => 'Acme'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256'], 0xC0FFEE);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        $this->certPem = $certPem;
        $this->keyPem = $keyPem;
    }

    private function font(string $file = 'LiberationSans-Regular.ttf'): PdfFont
    {
        if (! is_readable(self::FONT_DIR.$file)) {
            self::markTestSkipped('Liberation fonts not fetched (scripts/fetch-fonts.sh)');
        }

        return new PdfFont(TtfFile::fromFile(self::FONT_DIR.$file));
    }

    private function config(?string $fieldName = null, ?\DateTimeImmutable $signedAt = null): SignatureConfig
    {
        return new SignatureConfig(
            $this->certPem,
            $this->keyPem,
            signerName: 'J. Doe',
            reason: 'Approval',
            signedAt: $signedAt,
            fieldName: $fieldName,
        );
    }

    /**
     * The widget dictionary of a signature field, by name.
     */
    private static function widget(string $bytes, string $name): string
    {
        self::assertSame(
            1,
            preg_match('@<< /Type /Annot /Subtype /Widget /FT /Sig [^\n]*?/T \('.preg_quote($name, '@').'\)[^\n]*?>>@', $bytes, $m),
            "No widget for field $name",
        );

        return $m[0];
    }

    /**
     * The appearance stream content referenced by a widget.
     */
    private static function appearance(string $bytes, string $widget): string
    {
        self::assertSame(1, preg_match('@/AP << /N (\d+) 0 R >>@', $widget, $ap), 'Widget has no /AP');
        self::assertSame(1, preg_match('@\n'.$ap[1].' 0 obj\n<<[^\n]*>>\nstream\n(.*?)\nendstream@s', $bytes, $m));

        return $m[1];
    }

    #[Test]
    public function the_signed_field_shows_certificate_details(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage()->addSignatureField(
            'sig', 50, 50, 250, 75,
            new SignatureAppearance([new TextBlock(['By {signer}', 'Cert {serial}', 'Reason {reason}', 'On {signedAt}'])], dateTimeFormat: 'Y-m-d'),
        );
        $pdf->sign($this->config(signedAt: new \DateTimeImmutable('2026-10-13 12:00:00')));
        $bytes = $pdf->toBytes();

        $ap = self::appearance($bytes, self::widget($bytes, 'sig'));
        foreach (['By J. Doe', 'Cert C0FFEE', 'Reason Approval', 'On 2026-10-13'] as $line) {
            self::assertStringContainsString('<'.strtoupper(bin2hex($line)).'> Tj', $ap);
        }
    }

    #[Test]
    public function the_stamp_and_the_signature_dictionary_share_one_signing_time(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage()->addSignatureField(
            'sig', 50, 50, 250, 40,
            new SignatureAppearance([new TextBlock('{signedAt}')], dateTimeFormat: 'YmdHis'),
        );
        $pdf->sign($this->config());
        $bytes = $pdf->toBytes();

        self::assertSame(1, preg_match("@/M \(D:(\d{14})@", $bytes, $m));
        $ap = self::appearance($bytes, self::widget($bytes, 'sig'));
        self::assertStringContainsString('<'.strtoupper(bin2hex($m[1])).'> Tj', $ap);
    }

    #[Test]
    public function field_name_picks_the_field_to_sign(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $page = $pdf->addPage();
        $stamp = new SignatureAppearance([new TextBlock(['Signed by {cn}', 'Place for signature'])]);
        $page->addSignatureField('first', 50, 200, 200, 50, $stamp);
        $page->addSignatureField('second', 50, 50, 200, 50, $stamp);
        $pdf->sign($this->config('second'));
        $bytes = $pdf->toBytes();

        $first = self::widget($bytes, 'first');
        $second = self::widget($bytes, 'second');
        self::assertStringNotContainsString('/V ', $first);
        self::assertStringContainsString('/V ', $second);

        $signedBy = '<'.strtoupper(bin2hex('Signed by Jane Doe')).'> Tj';
        self::assertStringNotContainsString($signedBy, self::appearance($bytes, $first));
        self::assertStringContainsString($signedBy, self::appearance($bytes, $second));
        // The unsigned field keeps its static line.
        self::assertStringContainsString(strtoupper(bin2hex('Place for signature')), self::appearance($bytes, $first));
    }

    #[Test]
    public function an_unknown_field_name_is_an_error(): void
    {
        $pdf = PdfDocument::new();
        $pdf->addPage()->addSignatureField('sig', 50, 50, 200, 50);
        $pdf->sign($this->config('nope'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/"nope"/');
        $pdf->toBytes();
    }

    #[Test]
    public function the_widget_prints_and_need_appearances_is_off_when_signed(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage()->addSignatureField('sig', 50, 50, 200, 50, SignatureAppearance::simple());
        $pdf->sign($this->config());
        $bytes = $pdf->toBytes();

        self::assertStringContainsString('/F 4', self::widget($bytes, 'sig'));
        self::assertStringNotContainsString('/NeedAppearances', $bytes);
        self::assertStringContainsString('/SigFlags 3', $bytes);
    }

    #[Test]
    public function a_zero_size_field_is_an_invisible_signature(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage()->addSignatureField('sig', 0, 0, 0, 0);
        $pdf->sign($this->config());
        $bytes = $pdf->toBytes();

        $widget = self::widget($bytes, 'sig');
        self::assertStringContainsString('/Rect [0 0 0 0]', $widget);
        self::assertStringNotContainsString('/AP', $widget);
        self::assertStringContainsString('/V ', $widget);
    }

    #[Test]
    public function a_field_without_appearance_keeps_the_plain_frame(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage()->addSignatureField('sig', 50, 50, 200, 50);
        $bytes = $pdf->toBytes();

        self::assertStringContainsString("0.5 w\n0 0 200 50 re S", self::appearance($bytes, self::widget($bytes, 'sig')));
    }

    #[Test]
    public function a_repeating_stamp_is_drawn_on_the_other_pages_once_per_emit(): void
    {
        $pdf = PdfDocument::new(compressStreams: false);
        $first = $pdf->addPage();
        $pdf->addPage();
        $pdf->addPage();
        $first->addSignatureField(
            'sig', 40, 30, 200, 50,
            new SignatureAppearance([new TextBlock('Signed by {cn}')]),
            repeatOnOtherPages: true,
        );
        $pdf->sign($this->config());

        $line = '<'.strtoupper(bin2hex('Signed by Jane Doe')).'> Tj';
        foreach ([1, 2] as $_) {
            $bytes = $pdf->toBytes();
            // One copy in the widget appearance, one on each of the two other pages.
            self::assertSame(3, substr_count($bytes, $line));
            self::assertSame(2, substr_count($bytes, "/Artifact BMC\nq\n1 0 0 1 40 30 cm"));
        }
    }

    #[Test]
    public function stamp_fonts_are_registered_on_the_page_resources(): void
    {
        $font = $this->font();
        $pdf = PdfDocument::new(compressStreams: false);
        $pdf->addPage()->addSignatureField(
            'sig', 50, 50, 250, 75,
            SignatureAppearance::electronicSignatureStamp('ru', $font),
        );
        $pdf->sign($this->config());
        $bytes = $pdf->toBytes();

        self::assertSame(1, preg_match('@/AP << /N (\d+) 0 R >>@', self::widget($bytes, 'sig'), $ap));
        self::assertSame(1, preg_match('@\n'.$ap[1].' 0 obj\n<<[^\n]*/Resources << /Font << /F1 (\d+) 0 R@', $bytes, $res));
        self::assertMatchesRegularExpression('@\n'.$res[1].' 0 obj\n<< /Type /Font /Subtype /Type0@', $bytes);
        self::assertStringContainsString($font->encodeText('ДОКУМЕНТ ПОДПИСАН'), $bytes);
    }

    #[Test]
    public function the_signature_verifies_with_a_stamp(): void
    {
        $font = $this->font();
        $pdf = PdfDocument::new();
        $pdf->addPage()->addSignatureField('sig', 50, 50, 250, 75, SignatureAppearance::electronicSignatureStamp('en', $font));
        $pdf->sign($this->config());
        $bytes = $pdf->toBytes();

        self::assertSame(1, preg_match('@/ByteRange \[(\d+) (\d+) (\d+) (\d+)\s*\]@', $bytes, $br));
        [, $a, $b, $c, $d] = array_map(intval(...), $br);
        self::assertSame(strlen($bytes), $c + $d);
        $signed = substr($bytes, $a, $b).substr($bytes, $c, $d);
        $hex = substr($bytes, $b + 1, $c - $b - 2);
        $der = (string) hex2bin($hex);

        $dir = sys_get_temp_dir().'/phppdf-stamp-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/data", $signed);
            // The DER blob is zero-padded to the placeholder size: trim by
            // re-reading the outer SEQUENCE length.
            file_put_contents("$dir/sig.der", self::trimDer($der));
            file_put_contents("$dir/cert.pem", $this->certPem);
            $ok = openssl_cms_verify(
                "$dir/data",
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY,
                null,
                [],
                null,
                null,
                null,
                "$dir/sig.der",
                OPENSSL_ENCODING_DER,
            );
            self::assertTrue($ok, 'CMS verification failed: '.openssl_error_string());
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
    }

    #[Test]
    public function signed_documents_write_to_files(): void
    {
        $path = sys_get_temp_dir().'/phppdf-stamp-'.bin2hex(random_bytes(6)).'.pdf';
        try {
            $pdf = PdfDocument::new();
            $pdf->addPage()->addSignatureField('sig', 50, 50, 200, 50);
            $pdf->sign($this->config());
            $pdf->toFile($path);
            $bytes = (string) file_get_contents($path);

            self::assertStringStartsWith('%PDF-', $bytes);
            self::assertSame(1, preg_match('@/ByteRange \[0 (\d+) (\d+) (\d+)\s*\]@', $bytes, $br));
            self::assertSame(strlen($bytes), (int) $br[2] + (int) $br[3]);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function the_layout_engine_places_the_field_in_the_flow(): void
    {
        $regular = $this->font();
        $bold = $this->font('LiberationSans-Bold.ttf');
        $section = new Section(body: [
            new Paragraph([new Run('Договор № 1')]),
            new SignatureField('sig', 250, 75, SignatureAppearance::electronicSignatureStamp('ru'), alignment: Alignment::End),
            new PageBreak,
            new Paragraph([new Run('Вторая страница')]),
        ]);
        $doc = new Document($section, signature: $this->config());

        $path = sys_get_temp_dir().'/phppdf-stamp-'.bin2hex(random_bytes(6)).'.pdf';
        try {
            $doc->toFile($path, new Engine(defaultFont: $regular, boldFont: $bold, compressStreams: false));
            $bytes = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        $widget = self::widget($bytes, 'sig');
        self::assertStringContainsString('/V ', $widget);
        // Right-aligned: the field ends at the right margin, as far from the
        // page edge (A4, 595.28 pt) as the text starts from the left one.
        self::assertSame(1, preg_match('@/Rect \[([0-9.]+) ([0-9.]+) ([0-9.]+) ([0-9.]+)\]@', $widget, $rect));
        self::assertEqualsWithDelta(250.0, (float) $rect[3] - (float) $rect[1], 0.01);
        self::assertSame(1, preg_match('@\n([0-9.]+) [0-9.]+ Td\n@', $bytes, $td), 'No text position found');
        self::assertEqualsWithDelta(595.28 - (float) $td[1], (float) $rect[3], 0.5);
        // The document's bold font draws the heading, not stroked emboldening.
        self::assertStringContainsString($bold->encodeText('ДОКУМЕНТ ПОДПИСАН'), $bytes);
        self::assertStringContainsString($regular->encodeText('Владелец: Jane Doe'), $bytes);
    }

    #[Test]
    public function pdfa_stamps_need_embedded_fonts(): void
    {
        $pdf = PdfDocument::new();
        $pdf->addPage()->addSignatureField('sig', 50, 50, 200, 50, SignatureAppearance::simple());
        $pdf->enablePdfA(new PdfAConfig(__DIR__.'/../../fixtures/dummy.icc'));
        $pdf->sign($this->config());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/PDF\/A/');
        $pdf->toBytes();
    }

    /** The DER value without the zero padding that follows it. */
    private static function trimDer(string $der): string
    {
        $len = ord($der[1]);
        if ($len < 0x80) {
            return substr($der, 0, 2 + $len);
        }
        $n = $len & 0x7F;
        $value = 0;
        for ($i = 0; $i < $n; $i++) {
            $value = ($value << 8) | ord($der[2 + $i]);
        }

        return substr($der, 0, 2 + $n + $value);
    }
}
