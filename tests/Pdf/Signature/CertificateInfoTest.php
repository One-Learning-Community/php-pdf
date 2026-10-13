<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\CertificateInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CertificateInfoTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('openssl_x509_parse')) {
            self::markTestSkipped('openssl extension not available');
        }
    }

    #[Test]
    public function reads_subject_serial_and_validity(): void
    {
        $info = CertificateInfo::fromPem($this->certificate([
            'commonName' => 'Иванов Иван Иванович',
            'organizationName' => 'ООО «Ромашка»',
            'emailAddress' => 'ivanov@example.com',
            'countryName' => 'RU',
        ], serial: 0x1A2B3C));

        self::assertSame('Иванов Иван Иванович', $info->commonName);
        self::assertSame('ООО «Ромашка»', $info->organization);
        self::assertSame('ivanov@example.com', $info->email);
        self::assertSame('1A2B3C', $info->serialHex);
        self::assertNotNull($info->validFrom);
        self::assertNotNull($info->validTo);
        self::assertGreaterThan($info->validFrom, $info->validTo);
        // Self-signed: the issuer is the subject.
        self::assertSame('Иванов Иван Иванович', $info->issuerCommonName);
    }

    #[Test]
    public function reads_russian_identifiers(): void
    {
        $info = CertificateInfo::fromPem($this->certificate([
            'commonName' => 'Петров П.П.',
            'INN' => '771234567890',
            'SNILS' => '12345678901',
            'OGRN' => '1027700000000',
        ]));

        self::assertSame('771234567890', $info->inn);
        self::assertSame('12345678901', $info->snils);
        self::assertSame('1027700000000', $info->ogrn);
        self::assertSame('', $info->ogrnip);
    }

    #[Test]
    public function reads_identifiers_reported_by_oid(): void
    {
        $info = CertificateInfo::fromParsed([
            'subject' => ['CN' => 'X', '1.2.643.3.131.1.1' => '500100732259', '1.2.643.100.4' => '7700000000'],
            'serialNumberHex' => 'abcdef',
        ]);

        self::assertSame('500100732259', $info->inn);
        self::assertSame('7700000000', $info->innLe);
        self::assertSame('ABCDEF', $info->serialHex);
        self::assertNull($info->validFrom);
    }

    #[Test]
    public function placeholders_use_the_date_format(): void
    {
        $info = new CertificateInfo(
            commonName: 'Jane',
            validFrom: new \DateTimeImmutable('2026-01-02 10:00:00'),
            validTo: new \DateTimeImmutable('2027-01-02 10:00:00'),
        );

        $values = $info->placeholders('d.m.Y');
        self::assertSame('02.01.2026', $values['validFrom']);
        self::assertSame('02.01.2027', $values['validTo']);
        self::assertSame('Jane', $values['cn']);
        self::assertSame('', $values['inn']);
    }

    #[Test]
    public function rejects_garbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CertificateInfo::fromPem('not a certificate');
    }

    /**
     * @param  array<string, string>  $dn
     */
    private function certificate(array $dn, int $serial = 1): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            self::markTestSkipped('openssl_pkey_new unavailable');
        }
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        if ($csr === false || $csr === true) {
            self::markTestSkipped('openssl_csr_new refused the subject');
        }
        $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256'], $serial);
        if ($cert === false) {
            self::markTestSkipped('openssl_csr_sign failed');
        }
        $pem = '';
        openssl_x509_export($cert, $pem);

        return $pem;
    }
}
