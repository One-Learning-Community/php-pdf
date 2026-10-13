<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\DetachedSignature;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\CrlFetcher;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\OcspClient;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\OnlineRevocationSource;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\RevocationStatus;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\TrustStore;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\OfflinePkiTransport;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\OpenSslTsaTransport;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Detached CMS signatures of arbitrary bytes: made by us and checked by the
 * openssl CLI, made by the openssl CLI and checked by us.
 */
final class DetachedSignatureTest extends TestCase
{
    private const CONTENT = "%PDF-1.7\nnot really a PDF, any bytes will do\n%%EOF\n";

    private string $dir = '';

    protected function setUp(): void
    {
        exec('openssl version 2>/dev/null', $out, $code);
        if (! function_exists('openssl_sign') || $code !== 0) {
            self::markTestSkipped('openssl extension or CLI not available');
        }
        $this->dir = sys_get_temp_dir().'/phppdf-detached-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', (array) glob($this->dir.'/*'));
            rmdir($this->dir);
        }
    }

    private static function trust(): TrustStore
    {
        return TrustStore::fromPem(Pki::pem('root-ca.crt'));
    }

    private static function tsa(): TsaClient
    {
        return new TsaClient('http://tsa.test/', transport: new OpenSslTsaTransport);
    }

    /** `openssl cms -sign` over the content, as a file-signing tool does. */
    private function cliSign(string $content, string $extra = ''): string
    {
        file_put_contents("$this->dir/content", $content);
        exec(sprintf(
            'openssl cms -sign -binary -in %s -signer %s -inkey %s -certfile %s -outform DER -out %s %s 2>&1',
            escapeshellarg("$this->dir/content"),
            escapeshellarg(Pki::DIR.'/signer-rsa.crt'),
            escapeshellarg(Pki::DIR.'/signer-rsa.key'),
            escapeshellarg(Pki::DIR.'/intermediate-ca.crt'),
            escapeshellarg("$this->dir/out.cms"),
            $extra,
        ), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        return (string) file_get_contents("$this->dir/out.cms");
    }

    /** @return list<string> OIDs of the signed attributes. */
    private static function signedAttributeOids(string $der): array
    {
        $signerInfo = DerReader::parse($der)->child(1)->child(0)->children[4]->child(0);

        return array_map(static fn ($attr): string => $attr->child(0)->oid(), $signerInfo->contextChild(0)->children ?? []);
    }

    #[Test]
    public function a_detached_signature_verifies_with_openssl_and_with_us(): void
    {
        foreach (['rsa' => Pki::rsaSigner(), 'ec' => Pki::ecSigner()] as $type => $signer) {
            $p7s = DetachedSignature::sign(self::CONTENT, $signer);

            [$ok, $out] = Pki::cmsVerify($p7s, self::CONTENT);
            self::assertTrue($ok, "$type: $out");

            $report = DetachedSignature::verify(self::CONTENT, $p7s, self::trust());
            self::assertTrue($report->isValid(), "$type: ".implode('; ', $report->errors));
            // Without a revocation source the only warnings are about revocation.
            self::assertSame([], array_values(array_filter($report->warnings, static fn (string $w): bool => ! str_starts_with($w, 'No revocation data'))), $type);
            self::assertNotNull($report->claimedSigningTime, $type);
            self::assertCount(3, $report->chain, $type);
        }
    }

    #[Test]
    public function the_signature_is_cades_bes(): void
    {
        $at = new \DateTimeImmutable('2026-10-13 12:00:00 UTC');
        $p7s = DetachedSignature::sign(self::CONTENT, Pki::rsaSigner(), signingTime: $at);

        // A DER SET is sorted, so the order is not the order of writing.
        $oids = self::signedAttributeOids($p7s);
        sort($oids);
        $expected = [Oid::CONTENT_TYPE, Oid::MESSAGE_DIGEST, Oid::SIGNING_CERTIFICATE_V2, Oid::SIGNING_TIME];
        sort($expected);
        self::assertSame($expected, $oids);
        self::assertEquals($at, DetachedSignature::verify(self::CONTENT, $p7s, self::trust())->claimedSigningTime);
    }

    #[Test]
    public function a_timestamp_authority_makes_it_cades_t(): void
    {
        if (! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl ts not available');
        }
        $p7s = DetachedSignature::sign(self::CONTENT, Pki::rsaSigner(), self::tsa());

        [$ok, $out] = Pki::cmsVerify($p7s, self::CONTENT);
        self::assertTrue($ok, $out);

        $report = DetachedSignature::verify(self::CONTENT, $p7s, self::trust());
        self::assertTrue($report->isValid(), implode('; ', $report->errors));
        self::assertNotNull($report->timestampTime);
    }

    #[Test]
    public function changed_content_fails(): void
    {
        $p7s = DetachedSignature::sign(self::CONTENT, Pki::rsaSigner());

        $report = DetachedSignature::verify(self::CONTENT.' ', $p7s, self::trust());

        self::assertFalse($report->isValid());
        self::assertFalse($report->cryptographicallyValid);
        self::assertStringContainsString('digest does not match', implode('; ', $report->errors));
    }

    #[Test]
    public function an_unknown_root_is_intact_but_not_trusted(): void
    {
        $report = DetachedSignature::verify(self::CONTENT, DetachedSignature::sign(self::CONTENT, Pki::rsaSigner()));

        self::assertTrue($report->cryptographicallyValid);
        self::assertFalse($report->trusted);
        self::assertFalse($report->isValid());
    }

    #[Test]
    public function a_signature_from_a_file_signing_tool_verifies(): void
    {
        $report = DetachedSignature::verify(self::CONTENT, $this->cliSign(self::CONTENT), self::trust());

        self::assertTrue($report->isValid(), implode('; ', $report->errors));
        self::assertSame('Test Signer RSA', $report->signer?->subjectCommonName());
    }

    #[Test]
    public function an_attached_signature_must_carry_the_same_content(): void
    {
        $attached = $this->cliSign(self::CONTENT, '-nodetach');

        self::assertTrue(DetachedSignature::verify(self::CONTENT, $attached, self::trust())->isValid());

        $other = DetachedSignature::verify('other bytes', $attached, self::trust());
        self::assertFalse($other->isValid());
        self::assertStringContainsString('content other than', implode('; ', $other->errors));
    }

    #[Test]
    public function a_signature_without_signed_attributes_verifies_with_a_warning(): void
    {
        // What `openssl_cms_sign(..., OPENSSL_CMS_NOATTR)` writes.
        $p7s = $this->cliSign(self::CONTENT, '-noattr');

        $report = DetachedSignature::verify(self::CONTENT, $p7s, self::trust());
        self::assertTrue($report->isValid(), implode('; ', $report->errors));
        self::assertNull($report->claimedSigningTime);
        self::assertStringContainsString('No signed attributes', implode('; ', $report->warnings));

        self::assertFalse(DetachedSignature::verify(self::CONTENT.'x', $p7s, self::trust())->isValid());
    }

    #[Test]
    public function base64_and_pem_are_accepted(): void
    {
        $p7s = DetachedSignature::sign(self::CONTENT, Pki::rsaSigner());
        $pem = "-----BEGIN CMS-----\n".chunk_split(base64_encode($p7s), 64)."-----END CMS-----\n";

        self::assertTrue(DetachedSignature::verify(self::CONTENT, base64_encode($p7s), self::trust())->isValid());
        self::assertTrue(DetachedSignature::verify(self::CONTENT, $pem, self::trust())->isValid());
    }

    #[Test]
    public function garbage_is_reported_not_thrown(): void
    {
        $report = DetachedSignature::verify(self::CONTENT, 'not a signature');

        self::assertFalse($report->isValid());
        self::assertStringContainsString('not a readable CMS', implode('; ', $report->errors));
    }

    #[Test]
    public function a_timestamp_is_added_to_a_signature_made_elsewhere(): void
    {
        if (! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl ts not available');
        }
        $p7s = DetachedSignature::addTimestamp($this->cliSign(self::CONTENT, '-nodetach'), self::tsa());

        [$ok, $out] = Pki::cmsVerify($p7s, self::CONTENT);
        self::assertTrue($ok, $out);
        $report = DetachedSignature::verify(self::CONTENT, $p7s, self::trust());
        self::assertTrue($report->isValid(), implode('; ', $report->errors));
        self::assertNotNull($report->timestampTime);
    }

    #[Test]
    public function revocation_is_checked_through_a_source(): void
    {
        $net = new OfflinePkiTransport;
        $report = DetachedSignature::verify(
            self::CONTENT,
            DetachedSignature::sign(self::CONTENT, Pki::rsaSigner()),
            self::trust(),
            new OnlineRevocationSource(new OcspClient($net), new CrlFetcher($net)),
        );

        self::assertTrue($report->isValid(), implode('; ', $report->errors));
        self::assertSame(RevocationStatus::Good, $report->revocation);
        self::assertSame('fetched', $report->revocationSource);
    }
}
