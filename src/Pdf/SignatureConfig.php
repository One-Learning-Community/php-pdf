<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf;

use Dskripchenko\PhpPdf\Pdf\Signature\Cms\CmsBuilder;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\OutsideCms;
use Dskripchenko\PhpPdf\Pdf\Signature\DocMdpPermission;
use Dskripchenko\PhpPdf\Pdf\Signature\FieldLock;
use Dskripchenko\PhpPdf\Pdf\Signature\External\ExternalSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\CmsSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\CmsSignerKey;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\OpenSslSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Signer;
use Dskripchenko\PhpPdf\Pdf\Signature\SubFilter;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;

/**
 * Digital signature configuration (ISO 32000-1 §12.8, ETSI EN 319 142 PAdES).
 *
 * Used with `Pdf\Document::sign()`. The signed bytes cover the whole PDF
 * except the /Contents hex string, which is patched with a detached CMS
 * signature after the document body is fully emitted.
 *
 * The key comes either as PEM strings (`certPem`, `privateKeyPem`, optional
 * `privateKeyPassphrase` and `certificateChainPem`) or as a {@see Signer} —
 * an `OpenSslSigner` from PKCS#12, or your own implementation for a token or
 * HSM — or as a {@see CmsSigner}, software that returns a whole CMS
 * (CryptoPro CSP, a signing service). Optional fields (`signerName`, `reason`, `location`, `contactInfo`,
 * `signedAt`) populate the signature dictionary per §12.8.1, and the
 * placeholders of the signature stamp.
 *
 * - `subFilter` — `ETSI.CAdES.detached` (PAdES, the default) or the older
 *   `adbe.pkcs7.detached`.
 * - `timestampAuthority` — with a TSA the signature gets an RFC 3161
 *   timestamp (PAdES B-T); without one it is B-B.
 * - `fieldName` picks the signature field to sign; null signs the first one.
 * - `contentsReserveBytes` overrides the space reserved for the signature;
 *   by default it is estimated from the certificates and the TSA.
 * - `certification` makes this a certification signature (DocMDP): what may
 *   change after it. Only the first signature of a document can certify.
 * - `lockFields` locks form fields with the signature (FieldMDP).
 */
final readonly class SignatureConfig
{
    /** Room for a timestamp token, its certificates included. */
    private const TIMESTAMP_RESERVE = 10240;

    /** Room for the CMS structure and attributes beyond certificates and signature. */
    private const CMS_OVERHEAD = 2048;

    /**
     * An outside signer may embed more than it was told about — its whole
     * chain, extra attributes — so its CMS gets this much on top.
     */
    private const EXTERNAL_SLACK = 8192;

    /** Assumed certificates of an outside signer whose certificate is not known. */
    private const UNKNOWN_CERTIFICATES = 8192;

    /** The key the document is signed with. */
    public Signer $signer;

    /**
     * @param  list<string>|string  $certificateChainPem  Intermediate certificates for the PEM key.
     */
    public function __construct(
        public string $certPem = '',
        #[\SensitiveParameter] public string $privateKeyPem = '',
        #[\SensitiveParameter] public ?string $privateKeyPassphrase = null,
        public ?string $signerName = null,
        public ?string $reason = null,
        public ?string $location = null,
        public ?string $contactInfo = null,
        public ?\DateTimeImmutable $signedAt = null,
        public ?string $fieldName = null,
        Signer|CmsSigner|null $signer = null,
        public SubFilter $subFilter = SubFilter::CadesDetached,
        public ?TsaClient $timestampAuthority = null,
        public array|string $certificateChainPem = [],
        public ?int $contentsReserveBytes = null,
        public ?DocMdpPermission $certification = null,
        public ?FieldLock $lockFields = null,
    ) {
        if ($signer === null) {
            if ($certPem === '' || $privateKeyPem === '') {
                throw new \InvalidArgumentException('SignatureConfig needs a Signer, or certPem and privateKeyPem');
            }
            $signer = new OpenSslSigner($certPem, $privateKeyPem, $privateKeyPassphrase, $certificateChainPem);
        } elseif ($signer instanceof CmsSigner) {
            $signer = new CmsSignerKey($signer);
        }
        $this->signer = $signer;
        if ($contentsReserveBytes !== null && $contentsReserveBytes < 1024) {
            throw new \InvalidArgumentException('contentsReserveBytes must be at least 1024');
        }
    }

    /** The same configuration with the signing time fixed. */
    public function withSignedAt(\DateTimeImmutable $signedAt): self
    {
        return new self(
            $this->certPem,
            $this->privateKeyPem,
            $this->privateKeyPassphrase,
            $this->signerName,
            $this->reason,
            $this->location,
            $this->contactInfo,
            $signedAt,
            $this->fieldName,
            $this->signer,
            $this->subFilter,
            $this->timestampAuthority,
            $this->certificateChainPem,
            $this->contentsReserveBytes,
            $this->certification,
            $this->lockFields,
        );
    }

    public function effectiveSignedAt(): \DateTimeImmutable
    {
        return $this->signedAt ?? new \DateTimeImmutable;
    }

    /**
     * Signing time formatted per PDF spec: `D:YYYYMMDDHHMMSS+HH'MM'`.
     */
    public function pdfSignedAt(): string
    {
        $dt = $this->effectiveSignedAt();
        $tz = $dt->format('P');            // e.g. +03:00
        $tzPdf = str_replace(':', "'", $tz) . "'"; // → +03'00'

        return 'D:' . $dt->format('YmdHis') . $tzPdf;
    }

    /**
     * Bytes reserved for the DER signature in /Contents (the hex string is
     * twice as long).
     */
    public function contentsReserve(): int
    {
        if ($this->contentsReserveBytes !== null) {
            return $this->contentsReserveBytes;
        }
        $certificate = $this->certificate();
        $certificates = $certificate !== null ? strlen($certificate->der) : self::UNKNOWN_CERTIFICATES;
        foreach ($this->signer->chain() as $cert) {
            $certificates += strlen($cert->der);
        }
        // An RSA signature is as long as the modulus; EC and GOST are smaller.
        $signature = max(512, $certificate !== null ? intdiv($certificate->keyBits(), 8) * 2 : 0);
        $timestamp = $this->timestampAuthority !== null ? self::TIMESTAMP_RESERVE : 0;
        $slack = $this->signer instanceof ExternalSigner || $this->signer instanceof CmsSignerKey ? self::EXTERNAL_SLACK : 0;

        return $certificates + $signature + $timestamp + self::CMS_OVERHEAD + $slack;
    }

    /** The signing certificate, when it is known before signing. */
    public function certificate(): ?Certificate
    {
        if ($this->signer instanceof ExternalSigner && ! $this->signer->hasCertificate()) {
            return null;
        }

        return $this->signer->certificate();
    }

    /**
     * The detached CMS signature over `$data` — the PDF bytes outside
     * /Contents.
     *
     * PAdES leaves `signing-time` out of the CMS (the time is /M);
     * `adbe.pkcs7.detached` carries it, set to the same moment.
     */
    public function signBytes(string $data): string
    {
        if ($this->signer instanceof ExternalSigner) {
            throw new \LogicException('The key is outside this process: use prepareSignature() and PreparedSignature::complete()');
        }
        if ($this->signer instanceof CmsSignerKey) {
            $cms = $this->signer->cmsSigner->signCms($data, $this->signer->digestAlgorithm()->digest($data));

            return OutsideCms::accept($cms, $data, $this->signer->certificate(), $this->signer->chain(), $this->timestampAuthority, true, 'the CmsSigner');
        }
        $builder = new CmsBuilder(
            $this->signer->certificate(),
            $this->signer->chain(),
            $this->signer->digestAlgorithm(),
            $this->subFilter === SubFilter::Pkcs7Detached ? $this->effectiveSignedAt() : null,
        );

        return $builder->sign($data, $this->signer->sign(...), $this->timestampAuthority);
    }
}
