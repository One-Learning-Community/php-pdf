<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Signer;

/**
 * Signs with a private key held by PHP's openssl extension — RSA, EC, and
 * GOST R 34.10-2012 once the gost engine is loaded into the process.
 */
final class OpenSslSigner implements Signer
{
    private readonly Certificate $certificate;

    /** @var list<Certificate> */
    private readonly array $chain;

    private readonly DigestAlgorithm $digest;

    /** @var \OpenSSLAsymmetricKey */
    private readonly mixed $key;

    /**
     * @param  string  $certificatePem  The signing certificate.
     * @param  string  $privateKeyPem  Its private key, PEM.
     * @param  list<string>|string  $chainPem  Intermediate certificates — a PEM bundle or a list of them.
     * @param  ?DigestAlgorithm  $digest  Defaults to the hash customary for the key.
     */
    public function __construct(
        string $certificatePem,
        #[\SensitiveParameter] string $privateKeyPem,
        #[\SensitiveParameter] ?string $passphrase = null,
        array|string $chainPem = [],
        ?DigestAlgorithm $digest = null,
    ) {
        if (! function_exists('openssl_sign')) {
            throw new \RuntimeException('Signing requires the openssl extension');
        }
        $this->certificate = Certificate::fromPem($certificatePem);
        $chain = [];
        foreach (is_string($chainPem) ? [$chainPem] : $chainPem as $bundle) {
            array_push($chain, ...Certificate::allFromPem($bundle));
        }
        $this->chain = $chain;
        $this->digest = $digest ?? DigestAlgorithm::defaultFor($this->certificate);

        $key = openssl_pkey_get_private($privateKeyPem, $passphrase);
        if ($key === false) {
            throw new \InvalidArgumentException(
                $this->certificate->keyType() === KeyType::Gost256 || $this->certificate->keyType() === KeyType::Gost512
                    ? 'Cannot read the GOST private key — is the OpenSSL gost engine loaded into PHP?'
                    : 'Cannot read the private key (wrong passphrase?)',
            );
        }
        if (! openssl_x509_check_private_key($this->certificate->pem(), $key)) {
            throw new \InvalidArgumentException('The private key does not match the certificate');
        }
        $this->key = $key;
    }

    /**
     * From a PKCS#12 (.p12 / .pfx) container: the key, its certificate and
     * the extra certificates as the chain.
     */
    public static function fromPkcs12(
        #[\SensitiveParameter] string $pkcs12,
        #[\SensitiveParameter] string $password,
        ?DigestAlgorithm $digest = null,
    ): self {
        if (! function_exists('openssl_pkcs12_read')) {
            throw new \RuntimeException('Reading PKCS#12 requires the openssl extension');
        }
        $parts = [];
        if (! openssl_pkcs12_read($pkcs12, $parts, $password)) {
            throw new \InvalidArgumentException('Cannot read the PKCS#12 container (wrong password?)');
        }

        return new self(
            (string) $parts['cert'],
            (string) $parts['pkey'],
            null,
            array_map('strval', $parts['extracerts'] ?? []),
            $digest,
        );
    }

    public function certificate(): Certificate
    {
        return $this->certificate;
    }

    public function chain(): array
    {
        return $this->chain;
    }

    public function digestAlgorithm(): DigestAlgorithm
    {
        return $this->digest;
    }

    public function sign(string $data): string
    {
        $signature = '';
        if (! openssl_sign($data, $signature, $this->key, $this->digest->opensslName())) {
            throw new \RuntimeException('openssl_sign failed: '.openssl_error_string());
        }

        return $signature;
    }
}
