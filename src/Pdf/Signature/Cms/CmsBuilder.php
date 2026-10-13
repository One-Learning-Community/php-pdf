<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Cms;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\KeyType;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;

/**
 * Builds a detached CMS `SignedData` (RFC 5652) over PDF bytes.
 *
 * Signed attributes: `content-type`, `message-digest`,
 * `signing-certificate-v2` (RFC 5035, binding the signature to the
 * certificate as PAdES requires) and, when asked for, `signing-time`. With a
 * timestamp authority the signature value is timestamped and the token goes
 * in as the unsigned `signature-time-stamp` attribute (PAdES B-T).
 *
 * Signing is split so the key can live elsewhere: {@see signedAttributes()}
 * gives the DER to sign, {@see assemble()} wraps a signature value made from
 * it.
 *
 * @internal
 */
final class CmsBuilder
{
    /**
     * @param  list<Certificate>  $chain
     */
    public function __construct(
        private readonly Certificate $certificate,
        private readonly array $chain,
        private readonly DigestAlgorithm $digest,
        private readonly ?\DateTimeInterface $signingTime = null,
    ) {}

    /**
     * DER of the signed attributes — a universal SET, which is what gets
     * signed (RFC 5652 §5.4).
     */
    public function signedAttributes(string $contentDigest): string
    {
        $attrs = [
            self::attribute(Oid::CONTENT_TYPE, Der::oid(Oid::DATA)),
            self::attribute(Oid::MESSAGE_DIGEST, Der::octetString($contentDigest)),
            self::attribute(Oid::SIGNING_CERTIFICATE_V2, $this->signingCertificateV2()),
        ];
        if ($this->signingTime !== null) {
            $attrs[] = self::attribute(Oid::SIGNING_TIME, Der::time($this->signingTime));
        }

        return Der::set(...$attrs);
    }

    /**
     * The complete `ContentInfo` around a signature value.
     *
     * @param  string  $signedAttributes  As returned by {@see signedAttributes()}.
     * @param  list<string>  $unsignedAttributes  Encoded `Attribute`s.
     */
    public function assemble(string $signedAttributes, string $signatureValue, array $unsignedAttributes = []): string
    {
        $digestAlg = Der::algorithm($this->digest->oid());

        $signerInfo = Der::sequence(
            Der::integer(1),
            Der::sequence($this->certificate->issuerDer(), $this->certificate->serialDer()),
            $digestAlg,
            // [0] IMPLICIT: the SET's own tag replaced by the context tag.
            Der::implicitConstructed(0, self::content($signedAttributes)),
            $this->signatureAlgorithm(),
            Der::octetString($signatureValue),
            ...($unsignedAttributes === [] ? [] : [Der::implicitConstructed(1, self::content(Der::set(...$unsignedAttributes)))]),
        );

        $certificates = [$this->certificate->der];
        foreach ($this->chain as $cert) {
            if ($cert->der !== $this->certificate->der && ! in_array($cert->der, $certificates, true)) {
                $certificates[] = $cert->der;
            }
        }

        $signedData = Der::sequence(
            Der::integer(1),
            Der::set($digestAlg),
            Der::sequence(Der::oid(Oid::DATA)),
            Der::implicitConstructed(0, self::content(Der::set(...$certificates))),
            Der::set($signerInfo),
        );

        return Der::sequence(Der::oid(Oid::SIGNED_DATA), Der::explicit(0, $signedData));
    }

    /**
     * Signs content end to end: digest, signed attributes, the signer's
     * signature, an optional timestamp.
     *
     * @param  callable(string): string  $sign  Signs the signed-attributes DER.
     */
    public function sign(string $content, callable $sign, ?TsaClient $tsa = null): string
    {
        $attributes = $this->signedAttributes($this->digest->digest($content));
        $signature = $sign($attributes);

        $unsigned = [];
        if ($tsa !== null) {
            $unsigned[] = self::attribute(Oid::SIGNATURE_TIMESTAMP_TOKEN, $tsa->timestamp($signature)->token);
        }

        return $this->assemble($attributes, $signature, $unsigned);
    }

    /** The `signatureAlgorithm` of the SignerInfo for this key and digest. */
    public function signatureAlgorithm(): string
    {
        return match ($this->certificate->keyType()) {
            // rsaEncryption with NULL parameters, as OpenSSL writes it; the
            // digest is named by digestAlgorithm.
            KeyType::Rsa => Der::algorithm(Oid::RSA_ENCRYPTION, nullParameters: true),
            KeyType::Ec => Der::algorithm(match ($this->digest) {
                DigestAlgorithm::Sha384 => Oid::ECDSA_WITH_SHA384,
                DigestAlgorithm::Sha512 => Oid::ECDSA_WITH_SHA512,
                default => Oid::ECDSA_WITH_SHA256,
            }),
            // The key algorithm, as OpenSSL's gost engine and CryptoPro write it.
            KeyType::Gost256 => Der::algorithm(Oid::GOST_3410_2012_256),
            KeyType::Gost512 => Der::algorithm(Oid::GOST_3410_2012_512),
        };
    }

    /** `Attribute ::= SEQUENCE { attrType OID, attrValues SET OF value }`. */
    public static function attribute(string $oid, string $value): string
    {
        return Der::sequence(Der::oid($oid), Der::set($value));
    }

    /**
     * SigningCertificateV2 with one ESSCertIDv2: the certificate hash and its
     * issuer and serial. The hash algorithm is omitted when it is the
     * SHA-256 default.
     */
    private function signingCertificateV2(): string
    {
        $issuerSerial = Der::sequence(
            // GeneralNames { directoryName [4] Name }
            Der::sequence(Der::explicit(4, $this->certificate->issuerDer())),
            $this->certificate->serialDer(),
        );
        $certId = Der::sequence(
            ...[
                ...($this->digest === DigestAlgorithm::Sha256 ? [] : [Der::algorithm($this->digest->oid())]),
                Der::octetString($this->digest->digest($this->certificate->der)),
                $issuerSerial,
            ],
        );

        return Der::sequence(Der::sequence($certId));
    }

    /** The content octets of an encoded TLV. */
    private static function content(string $tlv): string
    {
        $len = ord($tlv[1]);
        $header = $len < 0x80 ? 2 : 2 + ($len & 0x7F);

        return substr($tlv, $header);
    }
}
