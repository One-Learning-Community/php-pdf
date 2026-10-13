<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Ltv;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Exception;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpTransport;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\StreamHttpTransport;

/**
 * RFC 6960 OCSP client: asks the responder named in a certificate whether it
 * is revoked and returns the DER `OCSPResponse` to embed. The response's own
 * signature is the validator's business.
 */
final class OcspClient
{
    private const SHA1 = '1.3.14.3.2.26';

    private readonly HttpTransport $transport;

    public function __construct(?HttpTransport $transport = null, private readonly float $timeoutSeconds = 15.0)
    {
        $this->transport = $transport ?? new StreamHttpTransport;
    }

    /**
     * @param  ?string  $url  The responder; by default the certificate's first AIA OCSP URL.
     */
    public function fetch(Certificate $certificate, Certificate $issuer, ?string $url = null): string
    {
        $url ??= $certificate->ocspUrls()[0] ?? throw new LtvException('The certificate names no OCSP responder');
        try {
            $response = $this->transport->post($url, $this->request($certificate, $issuer), [
                'Content-Type' => 'application/ocsp-request',
                'Accept' => 'application/ocsp-response',
            ], $this->timeoutSeconds);
        } catch (\RuntimeException $e) {
            // Unreachable is a revocation failure like any other: the source
            // falls back to the CRL instead of giving up.
            throw new LtvException("OCSP responder $url unreachable: ".$e->getMessage(), previous: $e);
        }
        if ($response->status !== 200) {
            throw new LtvException(sprintf('OCSP responder %s answered HTTP %d', $url, $response->status));
        }
        try {
            $status = DerReader::parse($response->body)->child(0)->expect(0x0A)->content;
        } catch (Asn1Exception $e) {
            throw new LtvException('Unreadable OCSP response: '.$e->getMessage(), previous: $e);
        }
        if ($status !== "\x00") {
            throw new LtvException(sprintf('OCSP responder %s refused the request (status %d)', $url, ord($status)));
        }

        return $response->body;
    }

    /** DER `OCSPRequest` for one certificate, CertID hashed with SHA-1 (what responders expect). */
    public function request(Certificate $certificate, Certificate $issuer): string
    {
        $certId = Der::sequence(
            Der::algorithm(self::SHA1, nullParameters: true),
            Der::octetString(sha1($issuer->subjectDer(), true)),
            Der::octetString(sha1($issuer->publicKeyBits(), true)),
            $certificate->serialDer(),
        );

        return Der::sequence(Der::sequence(Der::sequence(Der::sequence($certId))));
    }
}
