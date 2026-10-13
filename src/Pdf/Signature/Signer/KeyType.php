<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Signer;

/** The kind of signing key, read from the certificate. */
enum KeyType
{
    case Rsa;
    case Ec;
    /** GOST R 34.10-2012, 256-bit. */
    case Gost256;
    /** GOST R 34.10-2012, 512-bit. */
    case Gost512;
}
