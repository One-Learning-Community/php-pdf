<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Forms\ExistingFormFiller;
use Dskripchenko\PhpPdf\Pdf\Incremental\IncrementalUpdate;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Signature\DocMdpPermission;
use Dskripchenko\PhpPdf\Pdf\Signature\FieldLock;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\ValidationData;
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\ModificationLevel;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\RevocationStatus;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\SignatureReport;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\SignatureValidator;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\TrustStore;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\OpenSslTsaTransport;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The validator on good signatures — and on the known ways of faking one:
 * incremental saving (content changed after signing), shadow attacks
 * (overlays, replaced content), signature wrapping and universal forgery
 * (a byte range that does not describe the file), tampering, untrusted and
 * revoked signers, locked fields.
 */
final class SignatureValidatorTest extends TestCase
{
    private const FONT = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

    private TrustStore $trust;

    protected function setUp(): void
    {
        if (! function_exists('openssl_sign')) {
            self::markTestSkipped('openssl extension not available');
        }
        $this->trust = TrustStore::fromPem(Pki::DIR.'/root-ca.crt');
    }

    /** A one-page document with text, a text field and a signature. */
    private static function signed(?SignatureConfig $config = null): string
    {
        $doc = Document::new();
        $page = $doc->addPage();
        $page->showText('Pay 100 EUR to Alice', 72, 760, StandardFont::Helvetica, 14);
        $page->addFormField('text', 'amount', 72, 700, 200, 20);
        $page->addFormField('text', 'note', 72, 670, 200, 20);
        $page->addSignatureField('sig', 72, 600, 200, 50);
        $doc->sign($config ?? new SignatureConfig(signer: Pki::rsaSigner()));

        return $doc->toBytes();
    }

    private function report(string $pdf, string $field = 'sig'): SignatureReport
    {
        $report = SignatureValidator::fromBytes($pdf)->validate($this->trust)->signature($field);
        self::assertNotNull($report, "No report for $field");

        return $report;
    }

    /** The page's content stream object number. */
    private static function contentStream(string $pdf): int
    {
        $doc = \Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument::fromBytes($pdf);
        $contents = $doc->pages()[0]->dict->get('Contents');
        self::assertInstanceOf(PdfReference::class, $contents);

        return $contents->number;
    }

    #[Test]
    public function a_good_signature_is_valid(): void
    {
        $pdf = self::signed();
        $report = $this->report($pdf);

        self::assertTrue($report->isValid(), implode('; ', $report->errors));
        self::assertTrue($report->coversWholeFile);
        self::assertSame(ModificationLevel::None, $report->modifications);
        self::assertSame('Test Signer RSA', $report->signer?->subjectCommonName());
        self::assertCount(3, $report->chain);
        self::assertSame('ETSI.CAdES.detached', $report->subFilter);
        self::assertNotNull($report->claimedSigningTime);
        self::assertSame($pdf, SignatureValidator::fromBytes($pdf)->signedRevision('sig'));
    }

    #[Test]
    public function without_trust_anchors_the_signer_is_not_trusted(): void
    {
        $report = SignatureValidator::fromBytes(self::signed())->validate()->signature('sig');

        self::assertNotNull($report);
        self::assertTrue($report->intact);
        self::assertTrue($report->cryptographicallyValid);
        self::assertFalse($report->trusted);
        self::assertFalse($report->isValid());
    }

    #[Test]
    public function a_tampered_byte_breaks_the_digest(): void
    {
        $pdf = self::signed();
        $pdf = str_replace('Pay 100 EUR', 'Pay 900 EUR', $pdf);
        // The text is compressed — tamper with a byte inside the first range instead.
        $pdf[40] = $pdf[40] === 'A' ? 'B' : 'A';
        $report = $this->report($pdf);

        self::assertFalse($report->isValid());
        self::assertFalse($report->cryptographicallyValid);
    }

    #[Test]
    public function incremental_saving_attack_changes_content_after_signing(): void
    {
        $pdf = self::signed();
        $update = IncrementalUpdate::fromBytes($pdf);
        $content = 'BT /F1 14 Tf 72 760 Td (Pay 900 EUR to Mallory) Tj ET';
        $update->replaceBody(self::contentStream($pdf), sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content));
        $report = $this->report($update->toBytes());

        self::assertTrue($report->intact, 'The signature itself is intact');
        self::assertTrue($report->cryptographicallyValid);
        self::assertFalse($report->coversWholeFile);
        self::assertSame(ModificationLevel::Other, $report->modifications);
        self::assertFalse($report->isValid());
        self::assertStringContainsString('changed after signing', implode(' ', $report->errors));
    }

    #[Test]
    public function shadow_replace_points_the_page_at_new_content(): void
    {
        $pdf = self::signed();
        $update = IncrementalUpdate::fromBytes($pdf);
        $doc = $update->document();
        $page = $doc->pages()[0];
        $content = 'BT /F1 14 Tf 72 760 Td (Pay 900 EUR to Mallory) Tj ET';
        $new = $update->add(sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content));
        $update->replace($page->objectNumber, new PdfDictionary([...$page->dict->all(), 'Contents' => new PdfReference($new, 0)]));
        $report = $this->report($update->toBytes());

        self::assertSame(ModificationLevel::Other, $report->modifications);
        self::assertFalse($report->isValid());
    }

    #[Test]
    public function shadow_hide_overlays_an_annotation(): void
    {
        $certified = self::signed(new SignatureConfig(signer: Pki::rsaSigner(), certification: DocMdpPermission::FormFilling));
        $overlay = static function (string $pdf): string {
            $update = IncrementalUpdate::fromBytes($pdf);
            $page = $update->document()->pages()[0];
            $annot = $update->add('<< /Type /Annot /Subtype /Square /Rect [60 750 400 780] /IC [1 1 1] /F 4 >>');
            $annots = (array) $update->document()->deref($page->dict->get('Annots'));
            $update->replace($page->objectNumber, new PdfDictionary([...$page->dict->all(), 'Annots' => [...$annots, new PdfReference($annot, 0)]]));

            return $update->toBytes();
        };

        // Under a "form filling only" certification an overlay is refused…
        $report = $this->report($overlay($certified));
        self::assertSame(ModificationLevel::Annotations, $report->modifications);
        self::assertFalse($report->isValid());
        self::assertStringContainsString('certification allows less', implode(' ', $report->errors));

        // …an approval signature reports it, and stands.
        $approval = $this->report($overlay(self::signed()));
        self::assertSame(ModificationLevel::Annotations, $approval->modifications);
        self::assertTrue($approval->isValid(), implode('; ', $approval->errors));
    }

    #[Test]
    public function a_byte_range_that_leaves_out_more_than_the_signature_is_refused(): void
    {
        // Signature wrapping: widen the gap so that it hides other bytes.
        $pdf = self::signed();
        self::assertSame(1, preg_match('@/ByteRange \[0 (\d+) (\d+) (\d+)\s*\]@', $pdf, $m, PREG_OFFSET_CAPTURE));
        [$b, $c, $d] = [(int) $m[1][0], (int) $m[2][0], (int) $m[3][0]];
        $forged = sprintf('/ByteRange [0 %d %d %d]', $b, $c + 10, $d - 10);
        $pdf = substr_replace($pdf, str_pad($forged, strlen($m[0][0])), (int) $m[0][1], strlen($m[0][0]));
        $report = $this->report($pdf);

        self::assertFalse($report->intact);
        self::assertStringContainsString('not just the signature value', implode(' ', $report->errors));
    }

    #[Test]
    public function a_byte_range_outside_the_file_is_refused(): void
    {
        $pdf = self::signed();
        self::assertSame(1, preg_match('@/ByteRange \[0 (\d+) (\d+) (\d+)\s*\]@', $pdf, $m, PREG_OFFSET_CAPTURE));
        $forged = sprintf('/ByteRange [0 %d %d %d]', (int) $m[1][0], (int) $m[2][0], (int) $m[3][0] + 1000);
        $pdf = substr_replace($pdf, str_pad($forged, strlen($m[0][0])), (int) $m[0][1], strlen($m[0][0]));

        self::assertFalse($this->report($pdf)->intact);
    }

    #[Test]
    public function form_filling_after_a_signature_is_allowed_but_locked_fields_are_not(): void
    {
        if (! is_readable(self::FONT)) {
            self::markTestSkipped('Liberation fonts not fetched');
        }
        $locked = self::signed(new SignatureConfig(signer: Pki::rsaSigner(), lockFields: FieldLock::only(['amount'])));

        $note = ExistingFormFiller::fromBytes($locked)->useFont(self::FONT)->setValue('note', 'ok')->toBytes(incremental: true);
        $report = $this->report($note);
        self::assertSame(ModificationLevel::FormFilling, $report->modifications);
        self::assertTrue($report->isValid(), implode('; ', $report->errors));

        $amount = ExistingFormFiller::fromBytes($locked)->useFont(self::FONT)->setValue('amount', '900')->toBytes(incremental: true);
        $report = $this->report($amount);
        self::assertFalse($report->isValid());
        self::assertStringContainsString('"amount" is locked', implode(' ', $report->errors));
    }

    #[Test]
    public function no_changes_certification_refuses_form_filling(): void
    {
        $certified = self::signed(new SignatureConfig(signer: Pki::rsaSigner(), certification: DocMdpPermission::NoChanges));

        $this->expectException(\LogicException::class);
        ExistingFormFiller::fromBytes($certified)->setValue('note', 'x')->toBytes(incremental: true);
    }

    #[Test]
    public function a_full_rewrite_destroys_the_signature_and_says_so(): void
    {
        $rewritten = ExistingFormFiller::fromBytes(self::signed())->setValue('note', 'x')->toBytes();
        $report = SignatureValidator::fromBytes($rewritten)->validate($this->trust);

        // The rewrite keeps the field, but the byte range no longer fits.
        foreach ($report->signatures as $signature) {
            self::assertFalse($signature->isValid());
        }
        self::assertFalse($report->allValid());
    }

    #[Test]
    public function several_signers_each_cover_their_revision(): void
    {
        $doc = Document::new();
        $page = $doc->addPage();
        $page->addSignatureField('first', 72, 700, 200, 50);
        $page->addSignatureField('second', 300, 700, 200, 50);
        $doc->sign(new SignatureConfig(signer: Pki::rsaSigner(), fieldName: 'first'));
        $two = PdfSigner::fromBytes($doc->toBytes())->sign(new SignatureConfig(signer: Pki::ecSigner(), fieldName: 'second'));
        $report = SignatureValidator::fromBytes($two)->validate($this->trust);

        self::assertTrue($report->allValid());
        self::assertSame(ModificationLevel::Signing, $report->signature('first')?->modifications);
        self::assertSame(ModificationLevel::None, $report->signature('second')?->modifications);
        self::assertSame(strlen(SignatureValidator::fromBytes($two)->signedRevision('first')), $report->signature('first')?->signedRevisionLength);
        self::assertStringStartsWith(SignatureValidator::fromBytes($two)->signedRevision('first'), $two);
    }

    #[Test]
    public function a_signer_revoked_before_signing_is_invalid(): void
    {
        if (! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl CLI not available');
        }
        $pdf = self::signed();
        $revoked = self::ocspFor('signer-rsa.crt', 'intermediate', revokedAt: '200101000000Z');
        $withData = PdfSigner::fromBytes($pdf)->addValidationData(extra: new ValidationData(ocspResponses: [$revoked]), fetchRevocation: false);
        $report = $this->report($withData);

        self::assertSame(RevocationStatus::Revoked, $report->revocation);
        self::assertFalse($report->isValid());
    }

    #[Test]
    public function a_good_ocsp_answer_in_the_dss_is_used(): void
    {
        if (! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl CLI not available');
        }
        $pdf = self::signed(new SignatureConfig(signer: Pki::rsaSigner(), timestampAuthority: new TsaClient('http://tsa.test/', transport: new OpenSslTsaTransport)));
        $withData = PdfSigner::fromBytes($pdf)->addValidationData(
            extra: new ValidationData(ocspResponses: [self::ocspFor('signer-rsa.crt', 'intermediate'), self::ocspFor('intermediate-ca.crt', 'root')]),
            fetchRevocation: false,
        );
        $report = $this->report($withData);

        self::assertTrue($report->isValid(), implode('; ', $report->errors));
        self::assertSame(RevocationStatus::Good, $report->revocation);
        self::assertSame('dss', $report->revocationSource);
        self::assertNotNull($report->timestampTime);
        self::assertSame(ModificationLevel::Signing, $report->modifications);
    }

    #[Test]
    public function a_timestamp_from_an_untrusted_tsa_keeps_its_time_apart(): void
    {
        if (! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl CLI not available');
        }
        $pdf = self::signed(new SignatureConfig(signer: Pki::rsaSigner(), timestampAuthority: new TsaClient('http://tsa.test/', transport: new OpenSslTsaTransport)));

        // Nothing trusted: the token verifies, its time is reported apart.
        $untrusted = SignatureValidator::fromBytes($pdf)->validate(new TrustStore)->signatures[0];
        self::assertNull($untrusted->timestampTime);
        self::assertNotNull($untrusted->untrustedTimestampTime);

        $trusted = $this->report($pdf);
        self::assertNotNull($trusted->timestampTime);
        self::assertNull($trusted->untrustedTimestampTime);
    }

    #[Test]
    public function pyhanko_agrees_on_the_attacks(): void
    {
        $pyhanko = Pki::pyhanko();
        if ($pyhanko === null) {
            self::markTestSkipped('pyhanko not installed');
        }
        $pdf = self::signed();
        $update = IncrementalUpdate::fromBytes($pdf);
        $content = 'BT /F1 14 Tf 72 760 Td (Pay 900 EUR to Mallory) Tj ET';
        $update->replaceBody(self::contentStream($pdf), sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content));
        $attacked = $update->toBytes();

        $path = sys_get_temp_dir().'/phppdf-attack-'.bin2hex(random_bytes(6)).'.pdf';
        file_put_contents($path, $attacked);
        try {
            exec(sprintf('%s sign validate --pretty-print --no-revocation-check --trust-replace --trust %s %s 2>&1',
                escapeshellarg($pyhanko), escapeshellarg(Pki::DIR.'/root-ca.crt'), escapeshellarg($path)), $out);
        } finally {
            @unlink($path);
        }
        $verdict = implode("\n", $out);

        self::assertStringContainsString('judged INVALID', $verdict, $verdict);
        self::assertFalse($this->report($attacked)->isValid());
    }

    /** An OCSP response from the test PKI, optionally saying "revoked". */
    private static function ocspFor(string $cert, string $ca, ?string $revokedAt = null): string
    {
        $dir = sys_get_temp_dir().'/phppdf-vocsp-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            $index = (string) file_get_contents(Pki::DIR."/ocsp-$ca.index");
            if ($revokedAt !== null) {
                $serial = strtoupper(trim((string) shell_exec(sprintf('openssl x509 -in %s -noout -serial', escapeshellarg(Pki::DIR."/$cert")))));
                $serial = substr($serial, strpos($serial, '=') + 1);
                $index = (string) preg_replace('/^V(\t[0-9Z]+\t)\t('.preg_quote($serial, '/').')/m', "R\${1}".$revokedAt."\t\${2}", $index);
            }
            file_put_contents("$dir/index", $index);
            $caFile = Pki::DIR.'/'.($ca === 'root' ? 'root-ca' : 'intermediate-ca');
            exec(sprintf('openssl ocsp -issuer %s -cert %s -reqout %s -no_nonce 2>&1', escapeshellarg("$caFile.crt"), escapeshellarg(Pki::DIR."/$cert"), escapeshellarg("$dir/req.der")));
            exec(sprintf('openssl ocsp -index %s -CA %s -rsigner %s -rkey %s -reqin %s -respout %s -ndays 3650 2>&1',
                escapeshellarg("$dir/index"), escapeshellarg("$caFile.crt"), escapeshellarg("$caFile.crt"), escapeshellarg("$caFile.key"),
                escapeshellarg("$dir/req.der"), escapeshellarg("$dir/resp.der")));

            return (string) file_get_contents("$dir/resp.der");
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
    }
}
