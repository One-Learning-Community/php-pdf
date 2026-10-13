<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Ltv;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpGetTransport;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\StreamHttpTransport;

/**
 * Downloads a certificate's issuer from its AIA caIssuers URL — the root a
 * chain usually leaves out, needed to ask about the intermediate.
 */
final class CertificateFetcher
{
    private readonly HttpGetTransport $transport;

    public function __construct(?HttpGetTransport $transport = null, private readonly float $timeoutSeconds = 15.0)
    {
        $this->transport = $transport ?? new StreamHttpTransport;
    }

    public function issuerOf(Certificate $certificate): ?Certificate
    {
        foreach ($certificate->caIssuersUrls() as $url) {
            try {
                $response = $this->transport->get($url, [], $this->timeoutSeconds);
            } catch (\RuntimeException) {
                // The next URL, if any; none found means the issuer is unknown.
                continue;
            }
            if ($response->status !== 200 || $response->body === '') {
                continue;
            }
            try {
                $issuer = str_contains($response->body, 'BEGIN CERTIFICATE')
                    ? Certificate::fromPem($response->body)
                    : Certificate::fromDer($response->body);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($issuer->subjectDer() === $certificate->issuerDer()) {
                return $issuer;
            }
        }

        return null;
    }
}
