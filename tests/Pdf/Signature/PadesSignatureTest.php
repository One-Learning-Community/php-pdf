<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\TextBlock;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Signer;
use Dskripchenko\PhpPdf\Pdf\Signature\SubFilter;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\OpenSslTsaTransport;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Signed PDFs end to end: PAdES dictionary, the CMS verified by the openssl
 * CLI up to the test root, and — where installed — pyHanko's verdict.
 */
final class PadesSignatureTest extends TestCase
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

    private static function signed(SignatureConfig $config, ?SignatureAppearance $appearance = null): string
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->addSignatureField('sig', 72, 600, 250, 60, $appearance);
        $doc->sign($config);

        return $doc->toBytes();
    }

    private static function tsa(): TsaClient
    {
        if (! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl ts not available');
        }

        return new TsaClient('http://tsa.test/', transport: new OpenSslTsaTransport);
    }

    #[Test]
    public function pades_is_the_default_and_verifies_up_to_the_root(): void
    {
        foreach ([Pki::rsaSigner(), Pki::ecSigner()] as $signer) {
            $pdf = self::signed(new SignatureConfig(signer: $signer));

            self::assertStringContainsString('/SubFilter /ETSI.CAdES.detached', $pdf);
            [$der, $content] = Pki::extract($pdf);
            [$ok, $out] = Pki::cmsVerify($der, $content);
            self::assertTrue($ok, $out);
        }
    }

    #[Test]
    public function pem_keys_with_a_chain_still_work(): void
    {
        $pdf = self::signed(new SignatureConfig(
            Pki::pem('signer-rsa.crt'),
            Pki::pem('signer-rsa.key'),
            certificateChainPem: Pki::pem('intermediate-ca.crt'),
        ));
        [$der, $content] = Pki::extract($pdf);

        self::assertTrue(Pki::cmsVerify($der, $content)[0]);
    }

    #[Test]
    public function the_stamp_shows_the_signer_certificate(): void
    {
        $pdf = self::signed(
            new SignatureConfig(signer: Pki::ecSigner()),
            new SignatureAppearance([new TextBlock('{cn}')]),
        );

        self::assertStringContainsString('<'.strtoupper(bin2hex('Test Signer ECDSA')).'> Tj', $pdf);
    }

    #[Test]
    public function the_contents_reserve_follows_the_certificates_and_the_tsa(): void
    {
        $plain = new SignatureConfig(signer: Pki::rsaSigner());
        $stamped = new SignatureConfig(signer: Pki::rsaSigner(), timestampAuthority: new TsaClient('http://tsa.test/'));

        self::assertGreaterThan(strlen(Pki::rsaSigner()->certificate()->der) * 2, $plain->contentsReserve());
        self::assertGreaterThan($plain->contentsReserve() + 8000, $stamped->contentsReserve());
        self::assertSame(20000, (new SignatureConfig(signer: Pki::rsaSigner(), contentsReserveBytes: 20000))->contentsReserve());

        $pdf = self::signed($plain);
        self::assertSame(1, preg_match('@/Contents <([0-9A-F]+)>@', $pdf, $m));
        self::assertSame(2 * $plain->contentsReserve(), strlen($m[1]));
    }

    #[Test]
    public function a_too_small_reserve_says_how_much_is_needed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/contentsReserveBytes to at least \d+/');
        self::signed(new SignatureConfig(signer: Pki::rsaSigner(), contentsReserveBytes: 1024));
    }

    #[Test]
    public function a_timestamped_signature_still_verifies(): void
    {
        $pdf = self::signed(new SignatureConfig(signer: Pki::rsaSigner(), timestampAuthority: self::tsa()));
        [$der, $content] = Pki::extract($pdf);

        self::assertTrue(Pki::cmsVerify($der, $content)[0]);
        self::assertStringContainsString(hex2bin('060B2A864886F70D010910020E') ?: '', $der, 'signature-time-stamp attribute present');
    }

    #[Test]
    public function a_custom_signer_receives_the_signed_attributes(): void
    {
        $inner = Pki::rsaSigner();
        $spy = new class($inner) implements Signer
        {
            /** @var list<string> */
            public array $signed = [];

            public function __construct(private readonly Signer $inner) {}

            public function certificate(): \Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate
            {
                return $this->inner->certificate();
            }

            public function chain(): array
            {
                return $this->inner->chain();
            }

            public function digestAlgorithm(): \Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm
            {
                return $this->inner->digestAlgorithm();
            }

            public function sign(string $data): string
            {
                $this->signed[] = $data;

                return $this->inner->sign($data);
            }
        };
        $pdf = self::signed(new SignatureConfig(signer: $spy));

        self::assertCount(1, $spy->signed);
        self::assertSame(0x31, ord($spy->signed[0][0]), 'The signed attributes are a DER SET');
        [$der, $content] = Pki::extract($pdf);
        self::assertTrue(Pki::cmsVerify($der, $content)[0]);
    }

    #[Test]
    public function a_config_needs_a_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureConfig(reason: 'no key');
    }

    #[Test]
    public function pyhanko_judges_the_signatures_valid(): void
    {
        $pyhanko = Pki::pyhanko();
        if ($pyhanko === null) {
            self::markTestSkipped('pyhanko not installed (pip install pyhanko-cli)');
        }
        $cases = [
            'pades-rsa' => new SignatureConfig(signer: Pki::rsaSigner(), signedAt: new \DateTimeImmutable('2026-10-01 12:00:00+03:00')),
            'pades-ec-tsa' => new SignatureConfig(signer: Pki::ecSigner(), timestampAuthority: self::tsa()),
            'pkcs7-rsa' => new SignatureConfig(signer: Pki::rsaSigner(), subFilter: SubFilter::Pkcs7Detached, signedAt: new \DateTimeImmutable('2026-10-01 12:00:00+03:00')),
        ];
        $path = sys_get_temp_dir().'/phppdf-pyhanko-'.bin2hex(random_bytes(6)).'.pdf';
        try {
            foreach ($cases as $name => $config) {
                file_put_contents($path, self::signed($config, SignatureAppearance::simple()));
                exec(sprintf(
                    '%s sign validate --pretty-print --no-revocation-check --trust-replace --trust %s %s 2>&1',
                    escapeshellarg($pyhanko),
                    escapeshellarg(Pki::DIR.'/root-ca.crt'),
                    escapeshellarg($path),
                ), $out, $code);
                $report = implode("\n", $out);
                $out = [];

                self::assertSame(0, $code, "$name:\n$report");
                self::assertStringContainsString('The signature is judged VALID', $report, $name);
                self::assertStringContainsString('The signature covers the entire file', $report, $name);
                if ($config->timestampAuthority !== null) {
                    self::assertStringContainsString('The timestamp token is cryptographically sound', $report, $name);
                    self::assertStringContainsString('The TSA certificate is trusted', $report, $name);
                }
                if ($config->signedAt !== null) {
                    // The time the validator reports is the configured one in both formats.
                    self::assertMatchesRegularExpression('/Signing time as reported by signer: 2026-10-01T(12:00:00\+03:00|09:00:00\+00:00)/', $report, $name);
                }
            }
        } finally {
            @unlink($path);
        }
    }
}
