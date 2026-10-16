<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Cms;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;

/**
 * Takes a detached CMS made outside the library — by a signing tool, a
 * service, CryptoPro — and makes it fit to embed: it must cover exactly the
 * signed bytes, be made with the expected certificate and verify.
 *
 * @internal Used by `PreparedSignature::complete()` and by signing with a `CmsSigner`.
 */
final class OutsideCms
{
    /**
     * @param  string  $der  The CMS as it came: DER or BER, detached or attached.
     * @param  string  $content  The bytes the signature must cover.
     * @param  ?Certificate  $certificate  The certificate the CMS must be made with, when known.
     * @param  list<Certificate>  $chain
     * @param  string  $origin  Where the certificate was given, for the error messages.
     * @return string The DER to embed: detached, with the signer, the chain and the timestamp.
     */
    public static function accept(
        string $der,
        string $content,
        ?Certificate $certificate,
        array $chain,
        ?TsaClient $tsa,
        bool $verify,
        string $origin,
    ): string {
        $cms = SignedDataReader::parse($der);

        // Tools often sign "attached" by default; the copy inside is fine as
        // long as it is this document, and is dropped when embedding.
        $attached = $cms->encapsulatedContent();
        if ($attached !== null && $attached !== $content) {
            throw new CmsException('The CMS carries content other than this document');
        }

        if ($cms->messageDigest() !== $cms->digestAlgorithm()->digest($content)) {
            throw new CmsException('The CMS signs different data: its message digest does not match this document');
        }

        $signer = $cms->signerCertificate($certificate !== null ? [$certificate, ...$chain] : $chain);
        if ($signer === null) {
            throw new CmsException("The CMS does not identify a certificate it carries or that was given to $origin");
        }
        if ($certificate !== null && $signer->der !== $certificate->der) {
            throw new CmsException("The CMS was made with a different certificate than the one given to $origin");
        }
        if ($verify && ! $cms->verifySignature($signer)) {
            throw new CmsException('The CMS signature does not verify');
        }

        $unsigned = [];
        if ($tsa !== null) {
            $unsigned[] = CmsBuilder::attribute(Oid::SIGNATURE_TIMESTAMP_TOKEN, $tsa->timestamp($cms->signatureValue())->token);
        }
        // Make sure the validator can find the signer and the chain.
        $certificates = array_values(array_filter([$signer, ...$chain]));

        return $cms->amend($certificates, $unsigned);
    }
}
