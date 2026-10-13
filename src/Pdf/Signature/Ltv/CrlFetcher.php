<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Ltv;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Exception;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpGetTransport;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\StreamHttpTransport;

/** Downloads the CRL a certificate names (cRLDistributionPoints). */
final class CrlFetcher
{
    private readonly HttpGetTransport $transport;

    public function __construct(?HttpGetTransport $transport = null, private readonly float $timeoutSeconds = 30.0)
    {
        $this->transport = $transport ?? new StreamHttpTransport;
    }

    /** DER CRL; PEM answers are converted. */
    public function fetch(Certificate $certificate, ?string $url = null): string
    {
        $url ??= $certificate->crlUrls()[0] ?? throw new LtvException('The certificate names no CRL distribution point');
        try {
            $response = $this->transport->get($url, [], $this->timeoutSeconds);
        } catch (\RuntimeException $e) {
            throw new LtvException("CRL $url unreachable: ".$e->getMessage(), previous: $e);
        }
        if ($response->status !== 200) {
            throw new LtvException(sprintf('CRL %s answered HTTP %d', $url, $response->status));
        }
        $body = $response->body;
        if (str_contains($body, '-----BEGIN X509 CRL-----')) {
            $body = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $body), true);
        }
        try {
            DerReader::parse($body);
        } catch (Asn1Exception $e) {
            throw new LtvException("Unreadable CRL from $url: ".$e->getMessage(), previous: $e);
        }

        return $body;
    }
}
