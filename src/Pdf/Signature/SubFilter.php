<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature;

/** The signature dictionary's /SubFilter — which signature format is used. */
enum SubFilter: string
{
    /** PAdES (ETSI EN 319 142): CAdES detached, signing-certificate-v2, no signing-time in the CMS. */
    case CadesDetached = 'ETSI.CAdES.detached';

    /** The pre-PAdES PKCS#7 detached format (ISO 32000-1 §12.8.3.3). */
    case Pkcs7Detached = 'adbe.pkcs7.detached';
}
