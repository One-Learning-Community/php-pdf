<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Incremental\IncrementalUpdate;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\TextBlock;
use Dskripchenko\PhpPdf\Pdf\Signature\DocMdpPermission;
use Dskripchenko\PhpPdf\Pdf\Signature\External\ExternalSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\FieldLock;
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Signing existing PDFs through incremental updates: third-party files,
 * several signers, certification, field locks.
 */
final class IncrementalSigningTest extends TestCase
{
    private const EXTERNAL = __DIR__.'/../../fixtures/external';

    protected function setUp(): void
    {
        if (! function_exists('openssl_sign')) {
            self::markTestSkipped('openssl extension not available');
        }
        exec('openssl version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('openssl CLI not available');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function thirdPartyPdfs(): iterable
    {
        foreach (glob(self::EXTERNAL.'/*.pdf') ?: [] as $path) {
            if (! str_contains($path, 'password')) {
                yield basename($path) => [$path];
            }
        }
    }

    private static function assertAllVerify(string $pdf, int $expected): void
    {
        $signatures = Pki::extractAll($pdf);
        self::assertCount($expected, $signatures);
        foreach ($signatures as $i => [$der, $content]) {
            [$ok, $out] = Pki::cmsVerify($der, $content);
            self::assertTrue($ok, "Signature #$i: $out");
        }
    }

    #[Test]
    #[DataProvider('thirdPartyPdfs')]
    public function third_party_pdfs_are_signed_in_place(string $path): void
    {
        $original = (string) file_get_contents($path);
        $signed = PdfSigner::fromBytes($original)->sign(new SignatureConfig(signer: Pki::rsaSigner()));

        self::assertStringStartsWith($original, $signed, 'The original bytes stay as they were');
        self::assertAllVerify($signed, 1);
        [, $content] = Pki::extractAll($signed)[0];
        self::assertSame(strlen($signed) - strlen($content), strlen($content) > 0 ? strlen($signed) - strlen($content) : 0);

        $before = ReaderDocument::fromBytes($original);
        $after = ReaderDocument::fromBytes($signed);
        self::assertSame($before->pageCount(), $after->pageCount());
        self::assertCount(1, PdfSigner::fromBytes($signed)->signatureFields());
        self::assertTrue(PdfSigner::fromBytes($signed)->signatureFields()[0]->signed);
    }

    #[Test]
    public function the_update_keeps_the_kind_of_cross_reference(): void
    {
        foreach (['pdflatex-4pages.pdf' => true, 'google-doc.pdf' => false] as $file => $stream) {
            $original = (string) file_get_contents(self::EXTERNAL."/$file");
            $update = IncrementalUpdate::fromBytes($original);
            $update->add('<< /Probe true >>');
            $tail = substr($update->toBytes(), strlen($original));

            self::assertSame($stream, str_contains($tail, '/Type /XRef'), $file);
            self::assertStringContainsString('/Prev ', $tail);
            self::assertStringEndsWith("%%EOF\n", $tail);
        }
    }

    #[Test]
    public function signers_follow_one_another(): void
    {
        $doc = Document::new();
        $page = $doc->addPage();
        $page->addSignatureField('first', 72, 700, 200, 50);
        $page->addSignatureField('second', 300, 700, 200, 50);
        $doc->sign(new SignatureConfig(signer: Pki::rsaSigner(), fieldName: 'first'));
        $one = $doc->toBytes();

        $two = PdfSigner::fromBytes($one)
            ->withAppearance(new SignatureAppearance([new TextBlock('Second: {cn}')]))
            ->sign(new SignatureConfig(signer: Pki::ecSigner(), fieldName: 'second'));
        $three = PdfSigner::fromBytes($two)
            ->addField('third', 0, 72, 600, 200, 50)
            ->sign(new SignatureConfig(signer: Pki::rsaSigner()));

        self::assertAllVerify($three, 3);
        // The second stamp was redrawn with the second signer.
        self::assertStringContainsString('<'.strtoupper(bin2hex('Second: Test Signer ECDSA')).'> Tj', (string) gzuncompress(self::lastAppearance($two)));
        self::assertSame(['first' => true, 'second' => true, 'third' => true], self::fieldStates($three));
    }

    #[Test]
    public function an_unnamed_signature_goes_into_the_first_empty_field(): void
    {
        $doc = Document::new();
        $doc->addPage()->addSignatureField('reserved', 72, 700, 200, 50);
        $signed = PdfSigner::fromBytes($doc->toBytes())->sign(new SignatureConfig(signer: Pki::rsaSigner()));

        self::assertSame(['reserved' => true], self::fieldStates($signed));
    }

    #[Test]
    public function a_document_without_fields_gets_an_invisible_signature(): void
    {
        $doc = Document::new();
        $doc->addPage();
        $signed = PdfSigner::fromBytes($doc->toBytes())->sign(new SignatureConfig(signer: Pki::rsaSigner()));

        $fields = PdfSigner::fromBytes($signed)->signatureFields();
        self::assertSame('Signature1', $fields[0]->name);
        self::assertSame([0.0, 0.0, 0.0, 0.0], $fields[0]->rect);
        self::assertSame(0, $fields[0]->page);
    }

    #[Test]
    public function a_signed_field_is_not_signed_twice(): void
    {
        $doc = Document::new();
        $doc->addPage()->addSignatureField('only', 72, 700, 200, 50);
        $doc->sign(new SignatureConfig(signer: Pki::rsaSigner()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already signed/');
        PdfSigner::fromBytes($doc->toBytes())->sign(new SignatureConfig(signer: Pki::rsaSigner(), fieldName: 'only'));
    }

    #[Test]
    public function a_certification_signature_names_its_permissions(): void
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->addSignatureField('sig', 72, 700, 200, 50);
        $doc->sign(new SignatureConfig(signer: Pki::rsaSigner(), certification: DocMdpPermission::FormFilling));
        $bytes = $doc->toBytes();

        self::assertMatchesRegularExpression('@/Perms << /DocMDP \d+ 0 R >>@', $bytes);
        self::assertStringContainsString('/TransformMethod /DocMDP /TransformParams << /Type /TransformParams /P 2 /V /1.2 >>', $bytes);
        self::assertAllVerify($bytes, 1);
    }

    #[Test]
    public function no_changes_certification_refuses_more_signatures(): void
    {
        $certified = PdfSigner::fromBytes(self::blank())->sign(new SignatureConfig(signer: Pki::rsaSigner(), certification: DocMdpPermission::NoChanges));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/no changes allowed/');
        PdfSigner::fromBytes($certified)->sign(new SignatureConfig(signer: Pki::ecSigner()));
    }

    #[Test]
    public function only_the_first_signature_certifies(): void
    {
        $signed = PdfSigner::fromBytes(self::blank())->sign(new SignatureConfig(signer: Pki::rsaSigner()));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/first signature/');
        PdfSigner::fromBytes($signed)->sign(new SignatureConfig(signer: Pki::ecSigner(), certification: DocMdpPermission::FormFilling));
    }

    #[Test]
    public function after_certification_only_prepared_fields_can_be_signed(): void
    {
        $certified = PdfSigner::fromBytes(self::blank())
            ->addField('author', 0, 72, 700, 200, 50, SignatureAppearance::simple())
            ->addEmptyField('reviewer', 0, 300, 700, 200, 50, SignatureAppearance::simple())
            ->sign(new SignatureConfig(signer: Pki::rsaSigner(), certification: DocMdpPermission::FormFilling));

        $reviewed = PdfSigner::fromBytes($certified)->withAppearance(SignatureAppearance::simple())
            ->sign(new SignatureConfig(signer: Pki::ecSigner()));
        self::assertSame(['author' => true, 'reviewer' => true], self::fieldStates($reviewed));
        self::assertAllVerify($reviewed, 2);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/certified/');
        PdfSigner::fromBytes($certified)->addField('extra', 0, 72, 600, 200, 50)->sign(new SignatureConfig(signer: Pki::ecSigner()));
    }

    #[Test]
    public function a_field_lock_goes_into_the_signature_and_the_field(): void
    {
        $signed = PdfSigner::fromBytes(self::blank())
            ->addField('sig', 0, 72, 700, 200, 50)
            ->sign(new SignatureConfig(signer: Pki::rsaSigner(), lockFields: FieldLock::only(['amount', 'Дата'])));

        self::assertStringContainsString('/TransformMethod /FieldMDP /TransformParams << /Type /TransformParams /Action /Include /Fields [(amount) <FEFF', $signed);
        self::assertStringContainsString('/Lock << /Type /SigFieldLock /Action /Include', $signed);
        self::assertAllVerify($signed, 1);
    }

    #[Test]
    public function non_ascii_reasons_are_text_strings(): void
    {
        $signed = PdfSigner::fromBytes(self::blank())->sign(new SignatureConfig(signer: Pki::rsaSigner(), reason: 'Согласование'));
        $doc = ReaderDocument::fromBytes($signed);
        $sig = null;
        foreach ($doc->pages()[0]->dict->get('Annots') ?? [] as $ref) {
            $widget = $doc->deref($ref);
            $sig = $widget instanceof PdfDictionary ? $doc->deref($widget->get('V')) : null;
        }

        self::assertInstanceOf(PdfDictionary::class, $sig);
        $reason = $sig->get('Reason');
        self::assertInstanceOf(PdfString::class, $reason);
        self::assertSame("\xFE\xFF".mb_convert_encoding('Согласование', 'UTF-16BE', 'UTF-8'), $reason->bytes);
    }

    #[Test]
    public function an_existing_pdf_can_be_signed_outside(): void
    {
        $prepared = PdfSigner::fromBytes((string) file_get_contents(self::EXTERNAL.'/pypdf-minimal.pdf'))
            ->addField('sig', 0, 50, 50, 200, 50)
            ->prepare(new SignatureConfig(signer: new ExternalSigner(Pki::pem('signer-rsa.crt'), Pki::pem('intermediate-ca.crt'))));
        $signer = Pki::rsaSigner();
        $builder = new \Dskripchenko\PhpPdf\Pdf\Signature\Cms\CmsBuilder($signer->certificate(), [], $signer->digestAlgorithm());
        $attributes = $builder->signedAttributes($prepared->documentDigest());

        self::assertAllVerify($prepared->complete($builder->assemble($attributes, $signer->sign($attributes))), 1);
    }

    #[Test]
    public function encrypted_pdfs_are_refused(): void
    {
        // Refused by the reader (no password) or by the update (encrypted).
        try {
            PdfSigner::fromBytes((string) file_get_contents(self::EXTERNAL.'/libreoffice-password.pdf'))
                ->sign(new SignatureConfig(signer: Pki::rsaSigner()));
            self::fail('An encrypted PDF was signed');
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            self::assertMatchesRegularExpression('/password|encrypted/i', $e->getMessage());
        }
    }

    #[Test]
    public function pyhanko_accepts_a_certified_document_with_a_second_signature(): void
    {
        $pyhanko = Pki::pyhanko();
        if ($pyhanko === null) {
            self::markTestSkipped('pyhanko not installed');
        }
        $certified = PdfSigner::fromBytes((string) file_get_contents(self::EXTERNAL.'/pdflatex-4pages.pdf'))
            ->addField('author', 0, 72, 20, 200, 40, SignatureAppearance::simple())
            ->addEmptyField('reviewer', 0, 300, 20, 200, 40)
            ->sign(new SignatureConfig(signer: Pki::rsaSigner(), certification: DocMdpPermission::FormFilling));
        $reviewed = PdfSigner::fromBytes($certified)->withAppearance(SignatureAppearance::simple())
            ->sign(new SignatureConfig(signer: Pki::ecSigner()));

        $path = sys_get_temp_dir().'/phppdf-cert-'.bin2hex(random_bytes(6)).'.pdf';
        file_put_contents($path, $reviewed);
        try {
            exec(sprintf('%s sign validate --pretty-print --no-revocation-check --trust-replace --trust %s %s 2>&1',
                escapeshellarg($pyhanko), escapeshellarg(Pki::DIR.'/root-ca.crt'), escapeshellarg($path)), $out, $code);
        } finally {
            @unlink($path);
        }
        $report = implode("\n", $out);
        self::assertSame(0, $code, $report);
        self::assertSame(2, substr_count($report, 'The signature is judged VALID'), $report);
    }

    private static function blank(): string
    {
        $doc = Document::new();
        $doc->addPage();

        return $doc->toBytes();
    }

    /** @return array<string, bool> */
    private static function fieldStates(string $pdf): array
    {
        $out = [];
        foreach (PdfSigner::fromBytes($pdf)->signatureFields() as $field) {
            $out[$field->name] = $field->signed;
        }
        ksort($out);

        return $out;
    }

    /** The compressed content of the last appearance stream in the file. */
    private static function lastAppearance(string $pdf): string
    {
        preg_match_all('@/Subtype /Form /BBox \[[^\]]+\] /Resources .*? /Length (\d+) /Filter /FlateDecode >>\nstream\n@s', $pdf, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $last = end($m);
        self::assertNotFalse($last);

        return substr($pdf, $last[0][1] + strlen($last[0][0]), (int) $last[1][0]);
    }
}
