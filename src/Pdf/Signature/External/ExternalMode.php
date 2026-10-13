<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\External;

/** What the outside signer is asked for and what it returns. */
enum ExternalMode
{
    /**
     * The signer gets the document digest (or the signed bytes) and returns
     * a complete detached CMS — what CryptoPro's `CadesSignedData.SignHash`,
     * `cryptcp`, cptools and most signing services produce.
     */
    case Cms;

    /**
     * The library builds the signed attributes; the signer signs them and
     * returns only the raw signature value — for HSMs, PKCS#11 tokens and
     * cloud KMS. Needs the signing certificate up front.
     */
    case RawSignature;
}
