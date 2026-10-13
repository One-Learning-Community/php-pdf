<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Asn1;

/**
 * Object identifiers used by CMS signing and timestamps.
 *
 * @internal
 */
final class Oid
{
    // PKCS#7 / CMS content types (RFC 5652).
    public const DATA = '1.2.840.113549.1.7.1';

    public const SIGNED_DATA = '1.2.840.113549.1.7.2';

    public const TST_INFO = '1.2.840.113549.1.9.16.1.4';

    // Signed and unsigned attributes.
    public const CONTENT_TYPE = '1.2.840.113549.1.9.3';

    public const MESSAGE_DIGEST = '1.2.840.113549.1.9.4';

    public const SIGNING_TIME = '1.2.840.113549.1.9.5';

    public const SIGNING_CERTIFICATE_V2 = '1.2.840.113549.1.9.16.2.47';

    public const SIGNATURE_TIMESTAMP_TOKEN = '1.2.840.113549.1.9.16.2.14';

    // Digests.
    public const SHA256 = '2.16.840.1.101.3.4.2.1';

    public const SHA384 = '2.16.840.1.101.3.4.2.2';

    public const SHA512 = '2.16.840.1.101.3.4.2.3';

    public const GOST_3411_2012_256 = '1.2.643.7.1.1.2.2';

    public const GOST_3411_2012_512 = '1.2.643.7.1.1.2.3';

    // Public keys and signatures.
    public const RSA_ENCRYPTION = '1.2.840.113549.1.1.1';

    public const SHA256_WITH_RSA = '1.2.840.113549.1.1.11';

    public const SHA384_WITH_RSA = '1.2.840.113549.1.1.12';

    public const SHA512_WITH_RSA = '1.2.840.113549.1.1.13';

    public const EC_PUBLIC_KEY = '1.2.840.10045.2.1';

    public const ECDSA_WITH_SHA256 = '1.2.840.10045.4.3.2';

    public const ECDSA_WITH_SHA384 = '1.2.840.10045.4.3.3';

    public const ECDSA_WITH_SHA512 = '1.2.840.10045.4.3.4';

    public const GOST_3410_2012_256 = '1.2.643.7.1.1.1.1';

    public const GOST_3410_2012_512 = '1.2.643.7.1.1.1.2';

    public const GOST_SIGN_2012_256 = '1.2.643.7.1.1.3.2';

    public const GOST_SIGN_2012_512 = '1.2.643.7.1.1.3.3';
}
