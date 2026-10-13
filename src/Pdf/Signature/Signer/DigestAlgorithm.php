<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Signer;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;

/**
 * Hash used for the document digest and the signature.
 *
 * The GOST R 34.11-2012 (Streebog) digests need OpenSSL's gost engine loaded
 * into the PHP process.
 */
enum DigestAlgorithm: string
{
    case Sha256 = 'sha256';
    case Sha384 = 'sha384';
    case Sha512 = 'sha512';
    case Gost256 = 'md_gost12_256';
    case Gost512 = 'md_gost12_512';

    public function oid(): string
    {
        return match ($this) {
            self::Sha256 => Oid::SHA256,
            self::Sha384 => Oid::SHA384,
            self::Sha512 => Oid::SHA512,
            self::Gost256 => Oid::GOST_3411_2012_256,
            self::Gost512 => Oid::GOST_3411_2012_512,
        };
    }

    public static function fromOid(string $oid): self
    {
        foreach (self::cases() as $case) {
            if ($case->oid() === $oid) {
                return $case;
            }
        }
        throw new \InvalidArgumentException("Unsupported digest algorithm $oid");
    }

    /** The name `openssl_sign()` and `openssl_digest()` know it by. */
    public function opensslName(): string
    {
        return $this->value;
    }

    public function isGost(): bool
    {
        return $this === self::Gost256 || $this === self::Gost512;
    }

    /** Raw binary digest. */
    public function digest(string $data): string
    {
        if (! $this->isGost()) {
            return hash($this->value, $data, true);
        }
        if (! function_exists('openssl_digest') || ! in_array($this->value, openssl_get_md_methods(), true)) {
            throw new \RuntimeException(
                'GOST R 34.11-2012 needs the OpenSSL gost engine loaded into PHP (openssl.cnf engines section)',
            );
        }
        $digest = openssl_digest($data, $this->value, true);
        if ($digest === false) {
            throw new \RuntimeException('openssl_digest failed: '.openssl_error_string());
        }

        return $digest;
    }

    /** The hash a key is customarily paired with. */
    public static function defaultFor(Certificate $certificate): self
    {
        return match ($certificate->keyType()) {
            KeyType::Gost256 => self::Gost256,
            KeyType::Gost512 => self::Gost512,
            KeyType::Ec => $certificate->keyBits() > 384 ? self::Sha512 : ($certificate->keyBits() > 256 ? self::Sha384 : self::Sha256),
            KeyType::Rsa => self::Sha256,
        };
    }
}
