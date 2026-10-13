<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support;

use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpResponse;
use Dskripchenko\PhpPdf\Pdf\Signature\Transport\HttpTransport;

/**
 * A timestamp authority for tests: answers TimeStampReq with `openssl ts
 * -reply`, signed by the test TSA certificate. An implementation independent
 * of ours, and no network.
 */
final class OpenSslTsaTransport implements HttpTransport
{
    private const PKI = __DIR__.'/../../../fixtures/pki';

    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $requests = [];

    public static function available(): bool
    {
        exec('openssl ts -help 2>&1', $out, $code);

        return $code === 0 || str_contains(implode("\n", $out), 'Usage: ts');
    }

    public function post(string $url, string $body, array $headers, float $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];

        $dir = sys_get_temp_dir().'/phppdf-tsa-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/serial", "01\n");
            file_put_contents("$dir/tsa.cnf", <<<CNF
                [tsa]
                default_tsa = tsa_config
                [tsa_config]
                dir = $dir
                serial = \$dir/serial
                signer_digest = sha256
                default_policy = 1.2.3.4.1
                digests = sha256, sha384, sha512
                accuracy = secs:1
                ordering = no
                tsa_name = no
                ess_cert_id_chain = no
                ess_cert_id_alg = sha256
                CNF);
            file_put_contents("$dir/req.der", $body);
            $cmd = sprintf(
                'openssl ts -reply -config %s -queryfile %s -inkey %s -signer %s -out %s 2>&1',
                escapeshellarg("$dir/tsa.cnf"),
                escapeshellarg("$dir/req.der"),
                escapeshellarg(self::PKI.'/tsa.key'),
                escapeshellarg(self::PKI.'/tsa.crt'),
                escapeshellarg("$dir/resp.der"),
            );
            exec($cmd, $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException("openssl ts -reply failed:\n".implode("\n", $out));
            }

            return new HttpResponse(200, (string) file_get_contents("$dir/resp.der"), ['content-type' => 'application/timestamp-reply']);
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
    }
}
