<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Signer;

/**
 * A key that signs through software producing a whole CMS — CryptoPro CSP
 * (`CadesSignedData`, `cryptcp`), a signing service, an HSM front end —
 * rather than returning a bare signature value.
 *
 * Signing is then one call, like with a key in the process: the library
 * hands over the document, takes the CMS back and checks it the way
 * `PreparedSignature::complete()` does — it covers exactly this document,
 * it was made with {@see certificate()}, its signature verifies — before
 * embedding it. A timestamp authority in the config timestamps it.
 */
interface CmsSigner
{
    /** The signing certificate: drawn on the stamp, and the CMS must be made with it. */
    public function certificate(): Certificate;

    /**
     * Intermediate (and optionally root) certificates to embed when the CMS
     * does not carry them.
     *
     * @return list<Certificate>
     */
    public function chain(): array;

    /** The hash the signer uses; `$digest` given to {@see signCms()} is made with it. */
    public function digestAlgorithm(): DigestAlgorithm;

    /**
     * Signs the document and returns a CMS SignedData in DER or BER, detached
     * or attached (an attached copy must be `$content` itself).
     *
     * @param  string  $content  The bytes the signature covers — for a tool that signs a file.
     * @param  string  $digest  Their hash by {@see digestAlgorithm()}, binary — for one that signs a hash.
     */
    public function signCms(string $content, string $digest): string;
}
