<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Signer;

/**
 * Whatever holds the signing key.
 *
 * {@see OpenSslSigner} signs with a PEM or PKCS#12 key in the PHP process.
 * Implement this interface to sign elsewhere synchronously — a PKCS#11
 * token, an HSM, a remote signing service — the library only ever hands
 * over the bytes to sign.
 */
interface Signer
{
    /** The signing certificate. */
    public function certificate(): Certificate;

    /**
     * Intermediate (and optionally root) certificates to embed so a
     * validator can build the path to a trusted root.
     *
     * @return list<Certificate>
     */
    public function chain(): array;

    public function digestAlgorithm(): DigestAlgorithm;

    /**
     * Signs `$data` — the DER of the CMS signed attributes — hashing it with
     * {@see digestAlgorithm()} first. Returns the signature value as CMS
     * carries it: PKCS#1 v1.5 for RSA, a DER `ECDSA-Sig-Value` for EC, the
     * raw GOST R 34.10-2012 value for GOST.
     */
    public function sign(string $data): string;
}
