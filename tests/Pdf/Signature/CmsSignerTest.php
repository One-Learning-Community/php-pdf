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
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\CmsException;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\SignedDataReader;
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\CmsSignerKey;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\SignatureValidator;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\TrustStore;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\FakeCmsSigner;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\OpenSslTsaTransport;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Signing in one call with a key behind software that returns a whole CMS
 * (CryptoPro CSP and the like), played by {@see FakeCmsSigner}.
 */
final class CmsSignerTest extends TestCase
{
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

    private static function sign(FakeCmsSigner $signer, ?SignatureAppearance $appearance = null, ?TsaClient $tsa = null): string
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->addSignatureField('sig', 72, 600, 250, 60, $appearance);
        $doc->sign(new SignatureConfig(signer: $signer, reason: 'Approval', timestampAuthority: $tsa));

        return $doc->toBytes();
    }

    private static function assertVerifies(string $pdf): void
    {
        [$der, $content] = Pki::extract($pdf);
        [$ok, $out] = Pki::cmsVerify($der, $content);
        self::assertTrue($ok, $out);
    }

    #[Test]
    public function a_cms_over_the_document_digest_signs_in_one_call(): void
    {
        $signer = new FakeCmsSigner('hash');
        $pdf = self::sign($signer);

        self::assertCount(1, $signer->calls);
        [$content, $digest] = $signer->calls[0];
        self::assertSame(hash('sha256', $content, true), $digest);
        self::assertVerifies($pdf);

        $report = SignatureValidator::fromBytes($pdf)->validate(TrustStore::fromPem(Pki::pem('root-ca.crt')));
        self::assertTrue($report->allValid(), implode('; ', $report->signatures[0]->errors));
    }

    #[Test]
    public function an_attached_ber_cms_from_a_file_signing_tool_is_embedded_detached(): void
    {
        // cryptcp and CryptoPro write attached, indefinite-length CMS by default.
        $pdf = self::sign(new FakeCmsSigner('file', cliOptions: '-nodetach -stream -nocerts'));

        [$der] = Pki::extract($pdf);
        $cms = SignedDataReader::parse($der);
        self::assertNull($cms->encapsulatedContent());
        self::assertCount(2, $cms->certificates(), 'The signer and the chain are added');
        self::assertVerifies($pdf);
    }

    #[Test]
    public function an_existing_pdf_is_signed_incrementally(): void
    {
        $original = Document::new();
        $original->addPage();
        $bytes = $original->toBytes();

        $pdf = PdfSigner::fromBytes($bytes)
            ->addField('approver', page: 0, x: 72, y: 72, width: 250, height: 75)
            ->sign(new SignatureConfig(signer: new FakeCmsSigner('file')));

        self::assertStringStartsWith($bytes, $pdf);
        self::assertVerifies($pdf);
    }

    #[Test]
    public function a_laid_out_document_signs_too(): void
    {
        $doc = new LayoutDocument(
            new Section([new Paragraph([new Run('Contract')]), new SignatureField('sig', appearance: SignatureAppearance::simple())]),
            signature: new SignatureConfig(signer: new FakeCmsSigner),
        );

        self::assertVerifies($doc->toBytes());
    }

    #[Test]
    public function the_stamp_shows_the_signers_certificate(): void
    {
        $pdf = self::sign(new FakeCmsSigner, new SignatureAppearance([new TextBlock('Serial {serial}')]));
        $serial = strtoupper((string) openssl_x509_parse(Pki::pem('signer-rsa.crt'))['serialNumberHex']);

        self::assertStringContainsString($serial, $pdf);
    }

    #[Test]
    public function a_timestamp_from_the_config_is_added(): void
    {
        if (! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl ts not available');
        }
        $pdf = self::sign(new FakeCmsSigner, tsa: new TsaClient('http://tsa.test/', transport: new OpenSslTsaTransport));

        [$der] = Pki::extract($pdf);
        self::assertStringContainsString(Der::oid(Oid::SIGNATURE_TIMESTAMP_TOKEN), $der);
        self::assertVerifies($pdf);
    }

    #[Test]
    public function a_cms_over_other_data_is_refused(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/different data/');
        self::sign(new FakeCmsSigner(tamper: 'something else'));
    }

    #[Test]
    public function a_cms_by_another_key_is_refused(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessageMatches('/different certificate than the one given to the CmsSigner/');
        self::sign(new FakeCmsSigner('file', signWith: 'signer-ec'));
    }

    #[Test]
    public function more_room_is_reserved_than_for_a_key_in_the_process(): void
    {
        $outside = new SignatureConfig(signer: new FakeCmsSigner);
        $inside = new SignatureConfig(signer: Pki::rsaSigner());

        self::assertInstanceOf(CmsSignerKey::class, $outside->signer);
        self::assertGreaterThan($inside->contentsReserve(), $outside->contentsReserve());
    }

    #[Test]
    public function the_bridge_never_returns_a_bare_signature(): void
    {
        $this->expectException(\LogicException::class);
        (new CmsSignerKey(new FakeCmsSigner))->sign('data');
    }
}
