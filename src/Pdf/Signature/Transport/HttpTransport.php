<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Transport;

/**
 * How the library reaches a timestamp authority (and, later, OCSP and CRL
 * servers). {@see StreamHttpTransport} needs nothing beyond PHP; implement
 * this to go through your HTTP client, proxy or retry policy.
 */
interface HttpTransport
{
    /**
     * @param  array<string, string>  $headers
     */
    public function post(string $url, string $body, array $headers, float $timeoutSeconds): HttpResponse;
}
