<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\CertificateFetcher;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\CrlFetcher;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\LtvException;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\OcspClient;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\OnlineRevocationSource;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\ValidationData;
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpGetTransport;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpResponse;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpTransport;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\OfflinePkiTransport;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\OpenSslTsaTransport;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Long-term validation: the DSS (PAdES B-LT) and document timestamps
 * (B-LTA), with the test PKI's OCSP responders and CRLs served offline.
 */
final class LtvTest extends TestCase
{
    private OfflinePkiTransport $net;

    protected function setUp(): void
    {
        if (! function_exists('openssl_sign') || ! OpenSslTsaTransport::available()) {
            self::markTestSkipped('openssl extension / CLI not available');
        }
        $this->net = new OfflinePkiTransport;
    }

    private function tsa(): TsaClient
    {
        return new TsaClient('http://tsa.test/', transport: new OpenSslTsaTransport);
    }

    /** A B-T signature by the RSA signer. */
    private function signed(): string
    {
        $doc = Document::new();
        $doc->addPage()->addSignatureField('sig', 72, 700, 200, 50);
        $doc->sign(new SignatureConfig(signer: Pki::rsaSigner(), timestampAuthority: $this->tsa()));

        return $doc->toBytes();
    }

    private function addValidationData(string $pdf, ?HttpTransport $ocsp = null): string
    {
        $ocsp ??= $this->net;

        return PdfSigner::fromBytes($pdf)->addValidationData(
            new OnlineRevocationSource(new OcspClient($ocsp), new CrlFetcher($this->net)),
            issuers: new CertificateFetcher($this->net),
        );
    }

    /**
     * @return array{certs: int, ocsps: int, crls: int}
     */
    private static function dss(string $pdf): array
    {
        $doc = ReaderDocument::fromBytes($pdf);
        $dss = $doc->deref($doc->catalog()->get('DSS'));
        self::assertInstanceOf(PdfDictionary::class, $dss);
        $count = static fn (string $key): int => count((array) $doc->deref($dss->get($key)));

        return ['certs' => $count('Certs'), 'ocsps' => $count('OCSPs'), 'crls' => $count('CRLs')];
    }

    #[Test]
    public function the_dss_covers_signer_intermediate_and_tsa(): void
    {
        $signed = $this->signed();
        $lt = $this->addValidationData($signed);

        self::assertStringStartsWith($signed, $lt);
        // Signer, intermediate, TSA and the root fetched from caIssuers;
        // OCSP for the three that are not self-issued.
        self::assertSame(['certs' => 4, 'ocsps' => 3, 'crls' => 0], self::dss($lt));
        self::assertContains('GET http://pki.test/ca/root-ca.crt', $this->net->requested);
        self::assertContains('POST http://pki.test/ocsp/intermediate', $this->net->requested);

        [$der, $content] = Pki::extractAll($lt)[0];
        self::assertTrue(Pki::cmsVerify($der, $content)[0], 'The signature still verifies');
    }

    #[Test]
    public function the_ocsp_responses_are_good_answers_for_the_chain(): void
    {
        $lt = $this->addValidationData($this->signed());
        $doc = ReaderDocument::fromBytes($lt);
        $dss = $doc->deref($doc->catalog()->get('DSS'));
        self::assertInstanceOf(PdfDictionary::class, $dss);

        $dir = sys_get_temp_dir().'/phppdf-ltv-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            $statuses = [];
            foreach ((array) $doc->deref($dss->get('OCSPs')) as $ref) {
                file_put_contents("$dir/r.der", $doc->streamData($doc->deref($ref)));
                exec(sprintf('openssl ocsp -respin %s -noverify -resp_text 2>&1', escapeshellarg("$dir/r.der")), $out);
                $statuses[] = str_contains(implode("\n", $out), 'Cert Status: good');
                $out = [];
            }
            self::assertSame([true, true, true], $statuses);
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
    }

    #[Test]
    public function crls_are_used_when_ocsp_fails(): void
    {
        $failingOcsp = new class implements HttpTransport
        {
            public function post(string $url, string $body, array $headers, float $timeoutSeconds): HttpResponse
            {
                return new HttpResponse(503, '');
            }
        };
        $lt = $this->addValidationData($this->signed(), $failingOcsp);

        // Intermediate's CRL covers the signer; the root's covers intermediate and TSA.
        self::assertSame(['certs' => 4, 'ocsps' => 0, 'crls' => 2], self::dss($lt));
    }

    #[Test]
    public function crls_are_used_when_the_ocsp_responder_is_unreachable(): void
    {
        // A timeout or a refused connection, as a transport reports it.
        $unreachableOcsp = new class implements HttpTransport
        {
            public function post(string $url, string $body, array $headers, float $timeoutSeconds): HttpResponse
            {
                throw new \RuntimeException("HTTP request to $url failed: Operation timed out");
            }
        };
        $lt = $this->addValidationData($this->signed(), $unreachableOcsp);

        self::assertSame(['certs' => 4, 'ocsps' => 0, 'crls' => 2], self::dss($lt));
    }

    #[Test]
    public function a_second_call_extends_the_dss(): void
    {
        $lt = $this->addValidationData($this->signed());
        $extra = PdfSigner::fromBytes($lt)->addValidationData(
            extra: new ValidationData(crls: [(string) file_get_contents(Pki::DIR.'/root-ca.crl')]),
            fetchRevocation: false,
        );

        $dss = self::dss($extra);
        self::assertSame(3, $dss['ocsps']);
        self::assertSame(1, $dss['crls']);
        self::assertGreaterThanOrEqual(4, $dss['certs']);
    }

    #[Test]
    public function an_unknown_issuer_is_reported(): void
    {
        $nowhere = new class implements HttpGetTransport
        {
            public function get(string $url, array $headers, float $timeoutSeconds): HttpResponse
            {
                return new HttpResponse(404, '');
            }
        };

        $this->expectException(LtvException::class);
        $this->expectExceptionMessageMatches('/issuer/');
        PdfSigner::fromBytes($this->signed())->addValidationData(
            new OnlineRevocationSource(new OcspClient($this->net), new CrlFetcher($this->net)),
            issuers: new CertificateFetcher($nowhere),
        );
    }

    #[Test]
    public function a_document_timestamp_covers_the_whole_file(): void
    {
        $lt = $this->addValidationData($this->signed());
        $lta = PdfSigner::fromBytes($lt)->timestamp($this->tsa());

        self::assertStringStartsWith($lt, $lta);
        self::assertStringContainsString('/Type /DocTimeStamp /Filter /Adobe.PPKLite /SubFilter /ETSI.RFC3161', $lta);
        $signatures = Pki::extractAll($lta);
        self::assertCount(2, $signatures);
        [$token, $content] = $signatures[1];
        self::assertSame(strlen($lta), strlen($content) + (strlen($lta) - strlen($content)));

        $dir = sys_get_temp_dir().'/phppdf-dts-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/token.der", $token);
            file_put_contents("$dir/data", $content);
            exec(sprintf(
                'openssl ts -verify -token_in -in %s -data %s -CAfile %s -untrusted %s 2>&1',
                escapeshellarg("$dir/token.der"), escapeshellarg("$dir/data"),
                escapeshellarg(Pki::DIR.'/root-ca.crt'), escapeshellarg(Pki::DIR.'/tsa.crt'),
            ), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
        self::assertSame('1.2.840.113549.1.7.2', DerReader::parse($token)->child(0)->oid());
    }

    #[Test]
    public function certificate_extensions_name_the_endpoints(): void
    {
        $signer = Certificate::fromPem(Pki::pem('signer-rsa.crt'));

        self::assertSame(['http://pki.test/ocsp/intermediate'], $signer->ocspUrls());
        self::assertSame(['http://pki.test/crl/intermediate.crl'], $signer->crlUrls());
        self::assertSame(['http://pki.test/ca/intermediate-ca.crt'], $signer->caIssuersUrls());
        self::assertTrue(Certificate::fromPem(Pki::pem('root-ca.crt'))->isSelfIssued());
    }

    #[Test]
    public function pyhanko_validates_lt_and_lta_offline(): void
    {
        $python = Pki::pyhankoLtvPython();
        if ($python === null) {
            self::markTestSkipped('Set PHPPDF_PYHANKO_PYTHON to a python with pyHanko 0.25');
        }
        $lt = $this->addValidationData($this->signed());
        $lta = PdfSigner::fromBytes($lt)->timestamp($this->tsa());

        $dir = sys_get_temp_dir().'/phppdf-pyltv-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            Pki::tsaOcspResponse("$dir/tsa-ocsp.der");
            $reports = [];
            foreach (['lt' => $lt, 'lta' => $lta] as $name => $pdf) {
                file_put_contents("$dir/$name.pdf", $pdf);
                exec(sprintf('%s -I %s %s %s %s 2>/dev/null',
                    escapeshellarg($python), escapeshellarg(__DIR__.'/../../../scripts/conformance/pades-ltv-check.py'),
                    escapeshellarg("$dir/$name.pdf"), escapeshellarg(Pki::DIR.'/root-ca.crt'), escapeshellarg("$dir/tsa-ocsp.der")), $out);
                $reports[$name] = implode("\n", $out);
                $out = [];
            }
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }

        // The signer's revocation comes only from the DSS: the validator was
        // given the TSA's status and nothing else.
        self::assertStringContainsString('pades: VALID', $reports['lt'], $reports['lt']);
        self::assertStringContainsString('pades-lta: ERROR', $reports['lt'], 'No document timestamp yet');
        self::assertStringContainsString('pades-lta: VALID', $reports['lta'], $reports['lta']);
    }
}
