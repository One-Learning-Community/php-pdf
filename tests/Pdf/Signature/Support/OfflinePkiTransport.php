<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support;

use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpGetTransport;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpResponse;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpTransport;

/**
 * The test PKI's http://pki.test/ endpoints, served without a network: OCSP
 * answered by `openssl ocsp -index` (signed by the issuing CA), CRLs from
 * tests/fixtures/pki.
 */
final class OfflinePkiTransport implements HttpTransport, HttpGetTransport
{
    /** @var list<string> */
    public array $requested = [];

    public function post(string $url, string $body, array $headers, float $timeoutSeconds): HttpResponse
    {
        $this->requested[] = "POST $url";
        if (! preg_match('#^http://pki\.test/ocsp/(root|intermediate)$#', $url, $m)) {
            return new HttpResponse(404, '');
        }
        $ca = $m[1] === 'root' ? 'root-ca' : 'intermediate-ca';
        $dir = sys_get_temp_dir().'/phppdf-ocsp-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/req.der", $body);
            exec(sprintf(
                'openssl ocsp -index %s -CA %s -rsigner %s -rkey %s -reqin %s -respout %s -ndays 3650 2>&1',
                escapeshellarg(Pki::DIR."/ocsp-{$m[1]}.index"),
                escapeshellarg(Pki::DIR."/$ca.crt"),
                escapeshellarg(Pki::DIR."/$ca.crt"),
                escapeshellarg(Pki::DIR."/$ca.key"),
                escapeshellarg("$dir/req.der"),
                escapeshellarg("$dir/resp.der"),
            ), $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException("openssl ocsp failed:\n".implode("\n", $out));
            }

            return new HttpResponse(200, (string) file_get_contents("$dir/resp.der"), ['content-type' => 'application/ocsp-response']);
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function get(string $url, array $headers, float $timeoutSeconds): HttpResponse
    {
        $this->requested[] = "GET $url";
        if (preg_match('#^http://pki\.test/ca/(root-ca|intermediate-ca)\.crt$#', $url, $m)) {
            return new HttpResponse(200, (string) file_get_contents(Pki::DIR."/{$m[1]}.crt"));
        }
        if (! preg_match('#^http://pki\.test/crl/(root|intermediate)\.crl$#', $url, $m)) {
            return new HttpResponse(404, '');
        }

        return new HttpResponse(200, (string) file_get_contents(Pki::DIR."/{$m[1]}-ca.crl"));
    }
}
