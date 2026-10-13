<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Transport;

/**
 * HTTP GET, for downloading certificate revocation lists. A separate
 * interface so an existing {@see HttpTransport} keeps working; implement both
 * to route CRL downloads through your client too.
 */
interface HttpGetTransport
{
    /**
     * @param  array<string, string>  $headers
     */
    public function get(string $url, array $headers, float $timeoutSeconds): HttpResponse;
}
