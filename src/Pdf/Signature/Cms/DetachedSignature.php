<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Cms;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\RevocationSource;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\CmsSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Signer;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\CmsChecks;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\SignatureReport;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\TrustStore;

/**
 * A detached CMS signature of any bytes — the `.p7s` beside a file.
 *
 * ```php
 * $p7s = DetachedSignature::sign($pdf, OpenSslSigner::fromPkcs12($p12, 'secret'), new TsaClient('https://freetsa.org/tsr'));
 * $report = DetachedSignature::verify($pdf, $p7s, TrustStore::fromPem('root.crt'));
 * $report->isValid();
 * ```
 *
 * The signature is CAdES-BES (ETSI EN 319 122): `content-type`,
 * `message-digest`, `signing-time` and `signing-certificate-v2`, the
 * signer's certificate and chain embedded; with a timestamp authority it is
 * CAdES-T. Signatures made elsewhere — CryptoPro's BER, an attached
 * signature of the same content — are verified the same way.
 */
final class DetachedSignature
{
    /**
     * Signs `$content`. Returns the CMS as DER.
     *
     * A `CmsSigner` (CryptoPro CSP, a signing service) makes the CMS itself;
     * it is checked as for a PDF — it covers exactly `$content`, it was made
     * with the signer's certificate, it verifies — and comes back detached,
     * with the chain and the timestamp added. Its `signing-time` is the
     * signer's own; `$signingTime` applies to a `Signer` only.
     *
     * @param  ?TsaClient  $timestampAuthority  Adds a signature timestamp (CAdES-T).
     * @param  ?\DateTimeInterface  $signingTime  The `signing-time` attribute; defaults to now.
     */
    public static function sign(string $content, Signer|CmsSigner $signer, ?TsaClient $timestampAuthority = null, ?\DateTimeInterface $signingTime = null): string
    {
        if ($signer instanceof CmsSigner) {
            $cms = $signer->signCms($content, $signer->digestAlgorithm()->digest($content));

            return OutsideCms::accept($cms, $content, $signer->certificate(), $signer->chain(), $timestampAuthority, true, 'the CmsSigner');
        }

        $builder = new CmsBuilder(
            $signer->certificate(),
            $signer->chain(),
            $signer->digestAlgorithm(),
            $signingTime ?? new \DateTimeImmutable,
        );

        return $builder->sign($content, $signer->sign(...), $timestampAuthority);
    }

    /**
     * Timestamps a signature made elsewhere (CAdES-BES → CAdES-T). The
     * signed part is untouched; an attached copy of the content is dropped,
     * so the result is detached.
     *
     * @param  string  $cms  DER, BER, base64 or PEM.
     */
    public static function addTimestamp(string $cms, TsaClient $timestampAuthority): string
    {
        $reader = SignedDataReader::parse(self::binary($cms));
        $token = $timestampAuthority->timestamp($reader->signatureValue())->token;

        return $reader->amend([], [CmsBuilder::attribute(Oid::SIGNATURE_TIMESTAMP_TOKEN, $token)]);
    }

    /**
     * Checks a signature of `$content`: the digest and the signature value,
     * the signing-certificate binding, a signature timestamp, the path to a
     * trust anchor (at the timestamped time, if there is one) and, given a
     * source, the revocation of that path.
     *
     * A signature that carries content of its own (attached) must carry
     * exactly `$content`.
     *
     * @param  string  $cms  DER, BER, base64 or PEM.
     * @param  ?TrustStore  $trust  Trust anchors; none — nothing is trusted, but the rest is still checked.
     * @param  ?RevocationSource  $revocation  Where to look up revocation (network).
     */
    public static function verify(string $content, string $cms, ?TrustStore $trust = null, ?RevocationSource $revocation = null, ?\DateTimeImmutable $now = null): SignatureReport
    {
        $report = new SignatureReport('', 'signature', null, strlen($content));
        try {
            $reader = SignedDataReader::parse(self::binary($cms), requireSignedAttributes: false);
        } catch (\Throwable $e) {
            $report->error('The signature is not a readable CMS: '.$e->getMessage());

            return $report;
        }

        $attached = $reader->encapsulatedContent();
        if ($attached !== null && $attached !== $content) {
            $report->error('The signature carries content other than the one given');

            return $report;
        }
        // Nothing outside the signature to protect: the bytes are whatever
        // was handed in, and the digest check below decides.
        $report->intact = true;
        $report->coversWholeFile = true;
        try {
            $report->claimedSigningTime = CmsChecks::claimedSigningTime($reader);
        } catch (\Throwable) {
            // A malformed signing-time is not a reason to stop.
        }

        $checks = new CmsChecks($trust ?? new TrustStore, $reader->certificates(), $now ?? new \DateTimeImmutable);
        if ($checks->signature($report, $reader, $content, essExpected: true) && $report->trusted && $report->chain !== []) {
            $checks->revocation($report, $revocation);
        }

        return $report;
    }

    /** DER/BER as is; base64 and PEM decoded. */
    private static function binary(string $cms): string
    {
        $clean = (string) preg_replace(['/-----[A-Z0-9 ]+-----/', '/\s+/'], '', $cms);
        // Text that decodes to a SEQUENCE is base64 (or PEM); anything else
        // is taken as the binary signature itself.
        if ($clean !== '' && preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $clean) === 1) {
            $binary = base64_decode($clean, true);
            if ($binary !== false && $binary !== '' && $binary[0] === "\x30") {
                return $binary;
            }
        }

        return $cms;
    }
}
