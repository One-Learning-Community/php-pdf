<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Document as LayoutDocument;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\SignatureField;
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\TextBlock;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\CmsBuilder;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\CmsException;
use Dskripchenko\PhpPdf\Pdf\Signature\External\ExternalMode;
use Dskripchenko\PhpPdf\Pdf\Signature\External\ExternalSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\External\PreparedSignature;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\OpenSslTsaTransport;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two-phase signing: prepare, sign outside, complete — with outside signers
 * played by the openssl CLI and by an in-process key.
 */
final class ExternalSigningTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        if (! function_exists('openssl_sign')) {
            self::markTestSkipped('openssl extension not available');
        }
        exec('openssl version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('openssl CLI not available');
        }
        $this->dir = sys_get_temp_dir().'/phppdf-ext-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', (array) glob($this->dir.'/*'));
            rmdir($this->dir);
        }
    }

    private static function prepare(SignatureConfig $config, ExternalMode $mode = ExternalMode::Cms, ?SignatureAppearance $appearance = null): PreparedSignature
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->addSignatureField('sig', 72, 600, 250, 60, $appearance);

        return $doc->prepareSignature($config, $mode);
    }

    /**
     * What a file-signing tool does: `openssl cms -sign` over the bytes,
     * with the intermediate certificate attached unless told otherwise.
     */
    private function cliSign(string $content, string $signer = 'signer-rsa', string $extra = ''): string
    {
        if (! str_contains($extra, '-nocerts')) {
            $extra .= ' -certfile '.escapeshellarg(Pki::DIR.'/intermediate-ca.crt');
        }
        file_put_contents("$this->dir/content", $content);
        exec(sprintf(
            'openssl cms -sign -binary -in %s -signer %s -inkey %s -outform DER -out %s %s 2>&1',
            escapeshellarg("$this->dir/content"),
            escapeshellarg(Pki::DIR."/$signer.crt"),
            escapeshellarg(Pki::DIR."/$signer.key"),
            escapeshellarg("$this->dir/out.cms"),
            $extra,
        ), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        return (string) file_get_contents("$this->dir/out.cms");
    }

    private static function assertVerifies(string $pdf): void
    {
        [$der, $content] = Pki::extract($pdf);
        [$ok, $out] = Pki::cmsVerify($der, $content);
        self::assertTrue($ok, $out);
    }

    #[Test]
    public function a_cms_from_a_file_signing_tool_completes_the_document(): void
    {
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner, reason: 'External'));

        // The state survives between requests.
        $restored = PreparedSignature::fromJson($prepared->toJson());
        $cms = $this->cliSign($restored->signedContent());
        $pdf = $restored->complete($cms);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(strlen($prepared->preparedPdf()), strlen($pdf), 'Only /Contents changes');
        self::assertVerifies($pdf);
    }

    #[Test]
    public function a_cms_over_the_document_digest_completes_the_document(): void
    {
        // What a hash-signing service (CryptoPro SignHash) does: signed
        // attributes over the digest it is given.
        $signer = Pki::rsaSigner();
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner(Pki::pem('signer-rsa.crt'), Pki::pem('intermediate-ca.crt'))));
        self::assertSame(64, strlen($prepared->documentDigestHex()));

        $builder = new CmsBuilder($signer->certificate(), [], $signer->digestAlgorithm(), new \DateTimeImmutable);
        $attributes = $builder->signedAttributes($prepared->documentDigest());
        $cms = $builder->assemble($attributes, $signer->sign($attributes));

        self::assertVerifies($prepared->complete($cms));
    }

    #[Test]
    public function missing_certificates_are_added_from_the_preparation(): void
    {
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner(Pki::pem('signer-rsa.crt'), Pki::pem('intermediate-ca.crt'))));
        $cms = $this->cliSign($prepared->signedContent(), extra: '-nocerts');
        self::assertSame([], \Dskripchenko\PhpPdf\Pdf\Signature\Cms\SignedDataReader::parse($cms)->certificates());

        $pdf = $prepared->complete($cms);
        [$der] = Pki::extract($pdf);
        self::assertCount(2, \Dskripchenko\PhpPdf\Pdf\Signature\Cms\SignedDataReader::parse($der)->certificates());
        self::assertVerifies($pdf);
    }

    #[Test]
    public function a_timestamp_can_be_added_to_an_outside_cms(): void
    {
        if (! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl ts not available');
        }
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner, contentsReserveBytes: 24000));
        $pdf = $prepared->complete(
            $this->cliSign($prepared->signedContent()),
            new TsaClient('http://tsa.test/', transport: new OpenSslTsaTransport),
        );

        [$der] = Pki::extract($pdf);
        self::assertStringContainsString(Der::oid(Oid::SIGNATURE_TIMESTAMP_TOKEN), $der);
        self::assertVerifies($pdf);
    }

    #[Test]
    public function base64_and_pem_results_are_accepted(): void
    {
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner));
        $cms = $this->cliSign($prepared->signedContent());
        $pem = "-----BEGIN CMS-----\r\n".chunk_split(base64_encode($cms), 64, "\r\n")."-----END CMS-----\r\n";

        self::assertVerifies($prepared->completeBase64($pem));
    }

    #[Test]
    public function a_cms_over_other_data_is_refused(): void
    {
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner));

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/different data/');
        $prepared->complete($this->cliSign('something else'));
    }

    #[Test]
    public function a_cms_by_another_certificate_is_refused(): void
    {
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner(Pki::pem('signer-rsa.crt'))));

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/different certificate/');
        $prepared->complete($this->cliSign($prepared->signedContent(), 'signer-ec'));
    }

    #[Test]
    public function a_broken_signature_is_refused(): void
    {
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner));
        $cms = $this->cliSign($prepared->signedContent());
        // Flip a bit in the signature value — the last bytes of the CMS.
        $cms[strlen($cms) - 5] = chr(ord($cms[strlen($cms) - 5]) ^ 0x01);

        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/does not verify/');
        $prepared->complete($cms);
    }

    #[Test]
    public function an_attached_cms_of_this_document_is_made_detached(): void
    {
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner));
        $attached = $this->cliSign($prepared->signedContent(), extra: '-nodetach');
        self::assertSame($prepared->signedContent(), \Dskripchenko\PhpPdf\Pdf\Signature\Cms\SignedDataReader::parse($attached)->encapsulatedContent());

        $pdf = $prepared->complete($attached);
        [$der] = Pki::extract($pdf);
        self::assertNull(\Dskripchenko\PhpPdf\Pdf\Signature\Cms\SignedDataReader::parse($der)->encapsulatedContent());
        self::assertLessThan(strlen($attached), strlen($der));
        self::assertVerifies($pdf);
    }

    #[Test]
    public function an_attached_cms_of_other_content_is_refused(): void
    {
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner));

        $this->expectException(CmsException::class);
        $prepared->complete($this->cliSign('other content', extra: '-nodetach'));
    }

    #[Test]
    public function a_ber_cms_with_indefinite_lengths_is_accepted(): void
    {
        // `openssl cms -stream` writes indefinite lengths, as CryptoPro does.
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner));
        $ber = $this->cliSign($prepared->signedContent(), extra: '-stream');
        self::assertSame("\x30\x80", substr($ber, 0, 2), 'The fixture must be indefinite-length BER');

        $pdf = $prepared->complete($ber);
        [$der] = Pki::extract($pdf);
        self::assertNotSame("\x80", $der[1], 'Embedded as definite lengths');
        self::assertVerifies($pdf);
    }

    #[Test]
    public function a_cms_too_big_for_the_reserve_says_so(): void
    {
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner, contentsReserveBytes: 1024));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/contentsReserveBytes/');
        $prepared->complete($this->cliSign($prepared->signedContent()));
    }

    #[Test]
    public function raw_signatures_from_a_device_complete_the_document(): void
    {
        foreach (['signer-rsa' => Pki::rsaSigner(), 'signer-ec' => Pki::ecSigner()] as $name => $key) {
            $prepared = self::prepare(
                new SignatureConfig(signer: new ExternalSigner(Pki::pem("$name.crt"), Pki::pem('intermediate-ca.crt'))),
                ExternalMode::RawSignature,
            );
            self::assertSame(0x31, ord($prepared->dataToSign()[0]));
            self::assertSame(hash('sha256', $prepared->dataToSign(), true), $prepared->hashToSign());

            $restored = PreparedSignature::fromJson($prepared->toJson());
            self::assertVerifies($restored->complete($key->sign($restored->dataToSign())));
        }
    }

    #[Test]
    public function a_wrong_raw_signature_is_refused(): void
    {
        $prepared = self::prepare(new SignatureConfig(signer: new ExternalSigner(Pki::pem('signer-rsa.crt'))), ExternalMode::RawSignature);

        $this->expectException(CmsException::class);
        $prepared->complete(Pki::rsaSigner()->sign('not the attributes'));
    }

    #[Test]
    public function raw_mode_needs_the_certificate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::prepare(new SignatureConfig(signer: new ExternalSigner), ExternalMode::RawSignature);
    }

    #[Test]
    public function writing_with_an_external_key_points_to_prepare(): void
    {
        $doc = Document::new();
        $doc->addPage()->addSignatureField('sig', 72, 600, 200, 50);
        $doc->sign(new SignatureConfig(signer: new ExternalSigner));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/prepareSignature/');
        $doc->toBytes();
    }

    #[Test]
    public function the_stamp_shows_the_certificate_when_known(): void
    {
        $stamp = new SignatureAppearance([new TextBlock(['{cn}', 'Reason {reason}'])]);
        $known = self::prepare(new SignatureConfig(signer: new ExternalSigner(Pki::pem('signer-ec.crt')), reason: 'R'), appearance: $stamp);
        $unknown = self::prepare(new SignatureConfig(signer: new ExternalSigner, reason: 'R'), appearance: $stamp);

        $cn = '<'.strtoupper(bin2hex('Test Signer ECDSA')).'> Tj';
        $reason = '<'.strtoupper(bin2hex('Reason R')).'> Tj';
        self::assertStringContainsString($cn, $known->preparedPdf());
        self::assertStringNotContainsString($cn, $unknown->preparedPdf());
        self::assertStringContainsString($reason, $unknown->preparedPdf());
    }

    #[Test]
    public function a_damaged_state_is_refused(): void
    {
        $state = json_decode(self::prepare(new SignatureConfig(signer: new ExternalSigner))->toJson(), true);
        $state['pdf'] = base64_encode('%PDF-1.7 tampered');

        $this->expectException(\InvalidArgumentException::class);
        PreparedSignature::fromJson((string) json_encode($state));
    }

    #[Test]
    public function a_laid_out_document_prepares_too(): void
    {
        $doc = new LayoutDocument(
            new Section([new Paragraph([new Run('Contract')]), new SignatureField('sig', appearance: SignatureAppearance::simple())]),
            signature: new SignatureConfig(signer: new ExternalSigner(Pki::pem('signer-rsa.crt'))),
        );
        $prepared = $doc->prepareSignature();

        self::assertSame(1, preg_match('@/Contents <0+>@', $prepared->preparedPdf()));
        self::assertVerifies($prepared->complete($this->cliSign($prepared->signedContent())));
    }
}
