<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\OpenSslSigner;

/** The committed test PKI (tests/fixtures/pki, see its README). */
final class Pki
{
    public const DIR = __DIR__.'/../../../fixtures/pki';

    public static function pem(string $name): string
    {
        return (string) file_get_contents(self::DIR.'/'.$name);
    }

    public static function rsaSigner(): OpenSslSigner
    {
        return new OpenSslSigner(self::pem('signer-rsa.crt'), self::pem('signer-rsa.key'), chainPem: self::pem('intermediate-ca.crt'));
    }

    public static function ecSigner(): OpenSslSigner
    {
        return new OpenSslSigner(self::pem('signer-ec.crt'), self::pem('signer-ec.key'), chainPem: self::pem('intermediate-ca.crt'));
    }

    /**
     * Verifies a detached CMS signature with the openssl CLI, building the
     * path to the test root. Returns [ok, output].
     *
     * @return array{0: bool, 1: string}
     */
    public static function cmsVerify(string $der, string $content, bool $verifyChain = true): array
    {
        $dir = sys_get_temp_dir().'/phppdf-cms-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/sig.der", $der);
            file_put_contents("$dir/data", $content);
            $cmd = sprintf(
                'openssl cms -verify -binary -inform DER -in %s -content %s %s -purpose any -out /dev/null 2>&1',
                escapeshellarg("$dir/sig.der"),
                escapeshellarg("$dir/data"),
                $verifyChain ? '-CAfile '.escapeshellarg(self::DIR.'/root-ca.crt') : '-noverify',
            );
            exec($cmd, $out, $code);

            return [$code === 0, implode("\n", $out)];
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
    }

    /**
     * The DER signature and the signed bytes of a signed PDF.
     *
     * @return array{0: string, 1: string}
     */
    public static function extract(string $pdf): array
    {
        if (! preg_match('@/ByteRange \[(\d+) (\d+) (\d+) (\d+)\s*\]@', $pdf, $br)) {
            throw new \RuntimeException('No /ByteRange');
        }
        [, $a, $b, $c, $d] = array_map(intval(...), $br);
        $hex = substr($pdf, $b + 1, $c - $b - 2);
        $node = \Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader::parseFirst((string) hex2bin($hex));

        return [$node->der, substr($pdf, $a, $b).substr($pdf, $c, $d)];
    }

    /**
     * Every signature of a signed PDF, in file order: [DER, signed bytes].
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function extractAll(string $pdf): array
    {
        preg_match_all('@/ByteRange \[(\d+) (\d+) (\d+) (\d+)\s*\]@', $pdf, $all, PREG_SET_ORDER);
        $out = [];
        foreach ($all as $br) {
            [, $a, $b, $c, $d] = array_map(intval(...), $br);
            if ($b === 0) {
                continue;
            }
            $hex = substr($pdf, $b + 1, $c - $b - 2);
            $node = \Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader::parseFirst((string) hex2bin($hex));
            $out[] = [$node->der, substr($pdf, $a, $b).substr($pdf, $c, $d)];
        }

        return $out;
    }

    /** A python with pyHanko 0.25 (classic LTV validation), from PHPPDF_PYHANKO_PYTHON. */
    public static function pyhankoLtvPython(): ?string
    {
        $python = getenv('PHPPDF_PYHANKO_PYTHON');

        return is_string($python) && $python !== '' && is_executable($python) ? $python : null;
    }

    /** A fresh OCSP response for the test TSA — what a validator would fetch live. */
    public static function tsaOcspResponse(string $out): void
    {
        $dir = sys_get_temp_dir().'/phppdf-tsaocsp-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            exec(sprintf('openssl ocsp -issuer %s -cert %s -reqout %s -no_nonce 2>&1', escapeshellarg(self::DIR.'/root-ca.crt'), escapeshellarg(self::DIR.'/tsa.crt'), escapeshellarg("$dir/req.der")));
            exec(sprintf(
                'openssl ocsp -index %s -CA %s -rsigner %s -rkey %s -reqin %s -respout %s -ndays 3650 2>&1',
                escapeshellarg(self::DIR.'/ocsp-root.index'), escapeshellarg(self::DIR.'/root-ca.crt'),
                escapeshellarg(self::DIR.'/root-ca.crt'), escapeshellarg(self::DIR.'/root-ca.key'),
                escapeshellarg("$dir/req.der"), escapeshellarg($out),
            ));
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
    }

    public static function pyhanko(): ?string
    {
        exec('command -v pyhanko 2>/dev/null', $out, $code);

        return $code === 0 && $out !== [] ? trim($out[0]) : null;
    }
}
