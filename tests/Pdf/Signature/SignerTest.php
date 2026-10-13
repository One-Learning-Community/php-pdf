<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\KeyType;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\OpenSslSigner;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('openssl_sign')) {
            self::markTestSkipped('openssl extension not available');
        }
    }

    #[Test]
    public function certificate_fields_match_openssl(): void
    {
        $pem = Pki::pem('signer-rsa.crt');
        $cert = Certificate::fromPem($pem);
        $parsed = (array) openssl_x509_parse($pem);

        // serialDer is the INTEGER TLV; its value is the serial.
        self::assertSame(
            strtoupper(ltrim((string) $parsed['serialNumberHex'], '0')),
            strtoupper(ltrim(bin2hex(substr($cert->serialDer(), 2)), '0')),
        );
        self::assertSame(KeyType::Rsa, $cert->keyType());
        self::assertSame(2048, $cert->keyBits());
        // The issuer of the signer is the intermediate's subject.
        self::assertSame(Certificate::fromPem(Pki::pem('intermediate-ca.crt'))->subjectDer(), $cert->issuerDer());
        self::assertStringContainsString('BEGIN CERTIFICATE', $cert->pem());
        self::assertSame($cert->der, Certificate::fromPem($cert->pem())->der);
    }

    #[Test]
    public function ec_certificates_report_the_curve(): void
    {
        $cert = Certificate::fromPem(Pki::pem('signer-ec.crt'));

        self::assertSame(KeyType::Ec, $cert->keyType());
        self::assertSame('1.2.840.10045.3.1.7', $cert->ecCurve());
        self::assertSame(256, $cert->keyBits());
        self::assertSame(DigestAlgorithm::Sha256, DigestAlgorithm::defaultFor($cert));
    }

    #[Test]
    public function a_pem_bundle_yields_every_certificate(): void
    {
        $bundle = Pki::pem('intermediate-ca.crt').Pki::pem('root-ca.crt');

        self::assertCount(2, Certificate::allFromPem($bundle));
    }

    #[Test]
    public function garbage_is_not_a_certificate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Certificate::fromDer("\x30\x03\x02\x01\x01");
    }

    #[Test]
    public function openssl_signer_signs_verifiably(): void
    {
        foreach ([Pki::rsaSigner(), Pki::ecSigner()] as $signer) {
            $signature = $signer->sign('data');
            $public = openssl_pkey_get_public($signer->certificate()->pem());
            self::assertNotFalse($public);
            self::assertSame(1, openssl_verify('data', $signature, $public, 'sha256'));
            self::assertCount(1, $signer->chain());
        }
    }

    #[Test]
    public function pkcs12_brings_the_chain(): void
    {
        $signer = OpenSslSigner::fromPkcs12((string) file_get_contents(Pki::DIR.'/signer-rsa.p12'), 'test');

        self::assertSame(Certificate::fromPem(Pki::pem('signer-rsa.crt'))->der, $signer->certificate()->der);
        self::assertSame(Certificate::fromPem(Pki::pem('intermediate-ca.crt'))->der, $signer->chain()[0]->der);
    }

    #[Test]
    public function wrong_pkcs12_password_is_reported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/password/');
        OpenSslSigner::fromPkcs12((string) file_get_contents(Pki::DIR.'/signer-rsa.p12'), 'nope');
    }

    #[Test]
    public function a_key_of_another_certificate_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not match/');
        new OpenSslSigner(Pki::pem('signer-rsa.crt'), Pki::pem('signer-ec.key'));
    }

    #[Test]
    public function gost_digest_without_the_engine_explains_itself(): void
    {
        if (in_array('md_gost12_256', openssl_get_md_methods(), true)) {
            self::markTestSkipped('gost engine is loaded here');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/gost engine/');
        DigestAlgorithm::Gost256->digest('x');
    }
}
