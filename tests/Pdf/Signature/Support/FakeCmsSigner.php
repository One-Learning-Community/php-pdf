<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support;

use Dskripchenko\PhpPdf\Pdf\Signature\Cms\CmsBuilder;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\CmsSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\OpenSslSigner;

/**
 * Plays CryptoPro: either signs the hash it is given (`CadesSignedData.SignHash`)
 * or signs the bytes with a file-signing tool (`openssl cms -sign`, as
 * `cryptcp` would) — attached, BER or without certificates on request.
 */
final class FakeCmsSigner implements CmsSigner
{
    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    /**
     * @param  'hash'|'file'  $how
     * @param  string  $cliOptions  Extra `openssl cms -sign` options for 'file'.
     * @param  ?string  $signWith  Key pair under tests/fixtures/pki that actually signs; defaults to `$name`.
     * @param  ?string  $tamper  Content signed instead of the document.
     */
    public function __construct(
        private readonly string $how = 'hash',
        private readonly string $name = 'signer-rsa',
        private readonly string $cliOptions = '',
        private readonly ?string $signWith = null,
        private readonly ?string $tamper = null,
    ) {}

    public function certificate(): Certificate
    {
        return Certificate::fromPem(Pki::pem("$this->name.crt"));
    }

    public function chain(): array
    {
        return Certificate::allFromPem(Pki::pem('intermediate-ca.crt'));
    }

    public function digestAlgorithm(): DigestAlgorithm
    {
        return DigestAlgorithm::Sha256;
    }

    public function signCms(string $content, string $digest): string
    {
        $this->calls[] = [$content, $digest];
        $key = $this->signWith ?? $this->name;
        if ($this->tamper !== null) {
            $content = $this->tamper;
            $digest = hash('sha256', $content, true);
        }

        return $this->how === 'hash' ? $this->signHash($key, $digest) : $this->signFile($key, $content);
    }

    private function signHash(string $key, string $digest): string
    {
        $signer = new OpenSslSigner(Pki::pem("$key.crt"), Pki::pem("$key.key"));
        $builder = new CmsBuilder($signer->certificate(), [], $signer->digestAlgorithm(), new \DateTimeImmutable);
        $attributes = $builder->signedAttributes($digest);

        return $builder->assemble($attributes, $signer->sign($attributes));
    }

    private function signFile(string $key, string $content): string
    {
        $dir = sys_get_temp_dir().'/phppdf-cms-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/content", $content);
            exec(sprintf(
                'openssl cms -sign -binary -in %s -signer %s -inkey %s -outform DER -out %s %s 2>&1',
                escapeshellarg("$dir/content"),
                escapeshellarg(Pki::DIR."/$key.crt"),
                escapeshellarg(Pki::DIR."/$key.key"),
                escapeshellarg("$dir/out.cms"),
                $this->cliOptions,
            ), $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException(implode("\n", $out));
            }

            return (string) file_get_contents("$dir/out.cms");
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }
    }
}
