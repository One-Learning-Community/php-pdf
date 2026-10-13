<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Ltv;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;

/**
 * Where revocation information for a certificate comes from.
 * {@see OnlineRevocationSource} asks the OCSP responder and falls back to the
 * CRL; implement this to serve it from a cache or a store of your own.
 */
interface RevocationSource
{
    /** OCSP responses and CRLs (DER) that cover `$certificate`. */
    public function revocationData(Certificate $certificate, Certificate $issuer): ValidationData;
}
