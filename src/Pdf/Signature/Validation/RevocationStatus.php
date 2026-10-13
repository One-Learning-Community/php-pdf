<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

enum RevocationStatus
{
    /** An OCSP response or CRL says the certificate was not revoked. */
    case Good;

    /** Revoked before the signing time. */
    case Revoked;

    /** Revoked, but after the (timestamped) signing time — the signature stands. */
    case RevokedAfterSigning;

    /** No usable OCSP response or CRL found. */
    case Unknown;
}
