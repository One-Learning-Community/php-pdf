<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\External;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\CmsBuilder;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\CmsException;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\SignedDataReader;
use Dskripchenko\PhpPdf\Pdf\Signature\SignaturePlaceholder;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\KeyType;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;

/**
 * A document emitted with an empty signature, waiting to be signed outside
 * the process.
 *
 * 1. Hand the signer what it needs:
 *    - {@see ExternalMode::Cms}: {@see documentDigest()} (hex:
 *      {@see documentDigestHex()}) for a service that signs a hash, or
 *      {@see signedContent()} for a tool that signs a file;
 *    - {@see ExternalMode::RawSignature}: {@see dataToSign()}, or its hash
 *      {@see hashToSign()} for a device that signs pre-hashed values.
 * 2. Keep the state between requests: {@see toJson()} / {@see fromJson()}.
 * 3. {@see complete()} with what came back: the signed PDF.
 *
 * `complete()` checks the result before embedding it: the CMS covers this
 * document's bytes, its signature verifies against the certificate, and it
 * fits the reserved space.
 */
final class PreparedSignature
{
    private const FORMAT = 1;

    /**
     * @param  list<Certificate>  $chain
     */
    private function __construct(
        private readonly string $pdf,
        private readonly SignaturePlaceholder $placeholder,
        public readonly ExternalMode $mode,
        public readonly DigestAlgorithm $digest,
        private readonly ?Certificate $certificate,
        private readonly array $chain,
        private readonly ?string $signedAttributes,
    ) {}

    /**
     * @internal Made by `Document::prepareSignature()`.
     *
     * @param  list<Certificate>  $chain
     */
    public static function create(
        string $pdf,
        SignaturePlaceholder $placeholder,
        ExternalMode $mode,
        DigestAlgorithm $digest,
        ?Certificate $certificate,
        array $chain,
        ?\DateTimeInterface $signingTime,
    ): self {
        $signedAttributes = null;
        if ($mode === ExternalMode::RawSignature) {
            if ($certificate === null) {
                throw new \InvalidArgumentException('ExternalMode::RawSignature needs the signing certificate up front');
            }
            $builder = new CmsBuilder($certificate, $chain, $digest, $signingTime);
            $signedAttributes = $builder->signedAttributes($digest->digest($placeholder->signedContent($pdf)));
        }

        return new self($pdf, $placeholder, $mode, $digest, $certificate, $chain, $signedAttributes);
    }

    /** The bytes the signature covers — for a tool that signs a file. */
    public function signedContent(): string
    {
        return $this->placeholder->signedContent($this->pdf);
    }

    /** Digest of {@see signedContent()} — for a service that signs a hash and returns CMS. */
    public function documentDigest(): string
    {
        return $this->digest->digest($this->signedContent());
    }

    public function documentDigestHex(): string
    {
        return strtoupper(bin2hex($this->documentDigest()));
    }

    /**
     * {@see ExternalMode::RawSignature}: the DER signed attributes — what the
     * key signs (hashing it itself).
     */
    public function dataToSign(): string
    {
        return $this->signedAttributes ?? throw new \LogicException('dataToSign() is for ExternalMode::RawSignature; in CMS mode use documentDigest()');
    }

    /** {@see ExternalMode::RawSignature}: the hash of {@see dataToSign()}, for devices that sign a hash. */
    public function hashToSign(): string
    {
        return $this->digest->digest($this->dataToSign());
    }

    /** The prepared PDF, unsigned. */
    public function preparedPdf(): string
    {
        return $this->pdf;
    }

    /** Bytes available for the signature in /Contents. */
    public function capacity(): int
    {
        return $this->placeholder->capacity();
    }

    /**
     * Finishes the document.
     *
     * @param  string  $result  Binary: the detached CMS (DER) in CMS mode, the signature value in raw mode.
     * @param  ?TsaClient  $timestampAuthority  Adds an RFC 3161 timestamp over the signature (PAdES B-T).
     * @param  bool  $verify  Check the signature with the public key first (needs the gost engine for GOST).
     */
    public function complete(string $result, ?TsaClient $timestampAuthority = null, bool $verify = true): string
    {
        $der = $this->mode === ExternalMode::RawSignature
            ? $this->fromRawSignature($result, $timestampAuthority, $verify)
            : $this->fromCms($result, $timestampAuthority, $verify);

        return $this->placeholder->embed($this->pdf, $der);
    }

    /** {@see complete()} for a base64 result — what browser plug-ins and REST services return. */
    public function completeBase64(string $result, ?TsaClient $timestampAuthority = null, bool $verify = true): string
    {
        // PEM armour and line breaks are dropped.
        $clean = (string) preg_replace(['/-----[A-Z0-9 ]+-----/', '/\s+/'], '', $result);
        $binary = base64_decode($clean, true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('The result is not base64');
        }

        return $this->complete($binary, $timestampAuthority, $verify);
    }

    private function fromRawSignature(string $signature, ?TsaClient $tsa, bool $verify): string
    {
        $certificate = $this->certificate ?? throw new \LogicException('Raw mode without a certificate');
        $attributes = $this->dataToSign();

        if ($verify) {
            $key = $certificate->publicKey();
            $ok = openssl_verify($attributes, $signature, $key, $this->digest->opensslName()) === 1;
            // CryptoAPI-based tools (CryptoPro's raw signature objects among
            // them) return GOST values little-endian; take the reversed value
            // when it is the one that verifies.
            if (! $ok && in_array($certificate->keyType(), [KeyType::Gost256, KeyType::Gost512], true)
                && openssl_verify($attributes, strrev($signature), $key, $this->digest->opensslName()) === 1) {
                $signature = strrev($signature);
                $ok = true;
            }
            if (! $ok) {
                throw new CmsException('The signature value does not verify against the certificate and the prepared data');
            }
        }

        $builder = new CmsBuilder($certificate, $this->chain, $this->digest);
        $unsigned = [];
        if ($tsa !== null) {
            $unsigned[] = CmsBuilder::attribute(Oid::SIGNATURE_TIMESTAMP_TOKEN, $tsa->timestamp($signature)->token);
        }

        return $builder->assemble($attributes, $signature, $unsigned);
    }

    private function fromCms(string $der, ?TsaClient $tsa, bool $verify): string
    {
        $cms = SignedDataReader::parse($der);

        $digest = $cms->digestAlgorithm();
        if ($cms->messageDigest() !== $digest->digest($this->signedContent())) {
            throw new CmsException('The CMS signs different data: its message digest does not match this document');
        }

        $signer = $cms->signerCertificate($this->certificate !== null ? [$this->certificate, ...$this->chain] : $this->chain);
        if ($signer === null) {
            throw new CmsException('The CMS does not identify a certificate it carries or that was given to prepareSignature()');
        }
        if ($this->certificate !== null && $signer->der !== $this->certificate->der) {
            throw new CmsException('The CMS was made with a different certificate than the one given to prepareSignature()');
        }
        if ($verify && ! $cms->verifySignature($signer)) {
            throw new CmsException('The CMS signature does not verify');
        }

        $unsigned = [];
        if ($tsa !== null) {
            $unsigned[] = CmsBuilder::attribute(Oid::SIGNATURE_TIMESTAMP_TOKEN, $tsa->timestamp($cms->signatureValue())->token);
        }
        // Make sure the validator can find the signer and the chain.
        $certificates = array_values(array_filter([$signer, ...$this->chain]));

        return $cms->amend($certificates, $unsigned);
    }

    public function toJson(): string
    {
        return (string) json_encode([
            'format' => self::FORMAT,
            'mode' => $this->mode->name,
            'digest' => $this->digest->value,
            'pdf' => base64_encode($this->pdf),
            'pdfSha256' => hash('sha256', $this->pdf),
            'byteRange' => $this->placeholder->byteRange,
            'hexOffset' => $this->placeholder->hexOffset,
            'hexLength' => $this->placeholder->hexLength,
            'certificate' => $this->certificate !== null ? base64_encode($this->certificate->der) : null,
            'chain' => array_map(static fn (Certificate $c): string => base64_encode($c->der), $this->chain),
            'signedAttributes' => $this->signedAttributes !== null ? base64_encode($this->signedAttributes) : null,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Restores a prepared signature. The stored hash catches a truncated or
     * mangled state; keep the state where the signer cannot change it, since
     * the hash alone does not stop deliberate tampering.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException('Not a prepared signature state');
        }
        $pdf = base64_decode((string) ($data['pdf'] ?? ''), true);
        if ($pdf === false || hash('sha256', $pdf) !== ($data['pdfSha256'] ?? null)) {
            throw new \InvalidArgumentException('The prepared document is damaged');
        }
        $mode = constant(ExternalMode::class.'::'.(string) $data['mode']);
        if (! $mode instanceof ExternalMode) {
            throw new \InvalidArgumentException('Unknown external mode');
        }
        $byteRange = array_map('intval', (array) $data['byteRange']);
        if (count($byteRange) !== 4) {
            throw new \InvalidArgumentException('Bad byte range in the prepared state');
        }
        $certificate = isset($data['certificate']) ? Certificate::fromDer((string) base64_decode((string) $data['certificate'], true)) : null;
        $chain = array_map(static fn (mixed $c): Certificate => Certificate::fromDer((string) base64_decode((string) $c, true)), (array) ($data['chain'] ?? []));
        $attributes = isset($data['signedAttributes']) ? (string) base64_decode((string) $data['signedAttributes'], true) : null;

        return new self(
            $pdf,
            new SignaturePlaceholder([$byteRange[0], $byteRange[1], $byteRange[2], $byteRange[3]], (int) $data['hexOffset'], (int) $data['hexLength']),
            $mode,
            DigestAlgorithm::from((string) $data['digest']),
            $certificate,
            array_values($chain),
            $attributes,
        );
    }
}
