<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;

/**
 * Signature-algorithm OIDs of certificates, OCSP responses and CRLs → the
 * digest `openssl_verify()` takes.
 *
 * @internal
 */
final class Algorithms
{
    private const DIGESTS = [
        '1.2.840.113549.1.1.5' => 'sha1',
        Oid::SHA256_WITH_RSA => 'sha256',
        Oid::SHA384_WITH_RSA => 'sha384',
        Oid::SHA512_WITH_RSA => 'sha512',
        '1.2.840.10045.4.1' => 'sha1',
        Oid::ECDSA_WITH_SHA256 => 'sha256',
        Oid::ECDSA_WITH_SHA384 => 'sha384',
        Oid::ECDSA_WITH_SHA512 => 'sha512',
        Oid::GOST_SIGN_2012_256 => 'md_gost12_256',
        Oid::GOST_SIGN_2012_512 => 'md_gost12_512',
    ];

    public static function digestFor(string $signatureAlgorithmOid): ?string
    {
        return self::DIGESTS[$signatureAlgorithmOid] ?? null;
    }
}
