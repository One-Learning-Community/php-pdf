<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Node;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\CmsBuilder;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Signer;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\OpenSslTsaTransport;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CmsBuilderTest extends TestCase
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

    private static function builder(Signer $signer, ?\DateTimeInterface $signingTime = null): CmsBuilder
    {
        return new CmsBuilder($signer->certificate(), $signer->chain(), $signer->digestAlgorithm(), $signingTime);
    }

    /** SignerInfo of a ContentInfo. */
    private static function signerInfo(string $der): Asn1Node
    {
        $signedData = DerReader::parse($der)->child(1)->child(0);

        return $signedData->child(4)->child(0);
    }

    /**
     * @return array<string, Asn1Node> attribute OID → its first value
     */
    private static function attributes(?Asn1Node $tagged): array
    {
        $out = [];
        foreach ($tagged?->children ?? [] as $attr) {
            $out[$attr->child(0)->oid()] = $attr->child(1)->child(0);
        }

        return $out;
    }

    #[Test]
    public function rsa_and_ec_signatures_verify_up_to_the_root(): void
    {
        foreach (['RSA' => Pki::rsaSigner(), 'ECDSA' => Pki::ecSigner()] as $name => $signer) {
            $der = self::builder($signer)->sign('the PDF bytes', $signer->sign(...));
            [$ok, $out] = Pki::cmsVerify($der, 'the PDF bytes');
            self::assertTrue($ok, "$name: $out");

            [$tampered] = Pki::cmsVerify($der, 'the PDF byteS');
            self::assertFalse($tampered, "$name: tampering must break verification");
        }
    }

    #[Test]
    public function pades_attributes_bind_the_certificate_without_signing_time(): void
    {
        $signer = Pki::rsaSigner();
        $der = self::builder($signer)->sign('data', $signer->sign(...));
        $attrs = self::attributes(self::signerInfo($der)->contextChild(0));

        self::assertArrayHasKey(Oid::CONTENT_TYPE, $attrs);
        self::assertSame(hash('sha256', 'data', true), $attrs[Oid::MESSAGE_DIGEST]->content);
        self::assertArrayNotHasKey(Oid::SIGNING_TIME, $attrs);

        // SigningCertificateV2 → ESSCertIDv2 → certHash (SHA-256 default, so no algorithm).
        $certId = $attrs[Oid::SIGNING_CERTIFICATE_V2]->child(0)->child(0);
        self::assertSame(hash('sha256', $signer->certificate()->der, true), $certId->child(0)->content);
        self::assertSame($signer->certificate()->serialDer(), $certId->child(1)->child(1)->der);
    }

    #[Test]
    public function signing_time_is_included_when_asked_for(): void
    {
        $signer = Pki::ecSigner();
        $der = self::builder($signer, new \DateTimeImmutable('2026-10-01 12:00:00+03:00'))->sign('data', $signer->sign(...));
        $attrs = self::attributes(self::signerInfo($der)->contextChild(0));

        self::assertSame('2026-10-01T09:00:00+00:00', $attrs[Oid::SIGNING_TIME]->time()->format('c'));
        self::assertTrue(Pki::cmsVerify($der, 'data')[0]);
    }

    #[Test]
    public function the_chain_is_embedded_once(): void
    {
        $signer = Pki::rsaSigner();
        $der = self::builder($signer)->sign('data', $signer->sign(...));
        $certificates = DerReader::parse($der)->child(1)->child(0)->child(3);

        self::assertSame(0, $certificates->contextTag());
        self::assertCount(2, $certificates->children);
    }

    #[Test]
    public function the_signature_value_can_come_from_elsewhere(): void
    {
        $signer = Pki::rsaSigner();
        $builder = self::builder($signer);
        $attrs = $builder->signedAttributes(hash('sha256', 'data', true));
        // As an external device would: sign the attributes DER.
        $der = $builder->assemble($attrs, $signer->sign($attrs));

        self::assertTrue(Pki::cmsVerify($der, 'data')[0]);
    }

    #[Test]
    public function a_timestamp_goes_into_the_unsigned_attributes(): void
    {
        if (! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl ts not available');
        }
        $signer = Pki::rsaSigner();
        $tsa = new TsaClient('http://tsa.test/', transport: new OpenSslTsaTransport);
        $der = self::builder($signer)->sign('data', $signer->sign(...), $tsa);

        $info = self::signerInfo($der);
        $unsigned = self::attributes($info->contextChild(1));
        self::assertArrayHasKey(Oid::SIGNATURE_TIMESTAMP_TOKEN, $unsigned);
        self::assertTrue(Pki::cmsVerify($der, 'data')[0], 'The timestamp must not disturb the signature');

        // The token timestamps the signature value: check with openssl ts -verify.
        $signatureValue = $info->child(5)->content;
        $dir = sys_get_temp_dir().'/phppdf-ts-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/token.der", $unsigned[Oid::SIGNATURE_TIMESTAMP_TOKEN]->der);
            file_put_contents("$dir/data", $signatureValue);
            exec(sprintf(
                'openssl ts -verify -token_in -in %s -data %s -CAfile %s -untrusted %s 2>&1',
                escapeshellarg("$dir/token.der"),
                escapeshellarg("$dir/data"),
                escapeshellarg(Pki::DIR.'/root-ca.crt'),
                escapeshellarg(Pki::DIR.'/tsa.crt'),
            ), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
    }
}
