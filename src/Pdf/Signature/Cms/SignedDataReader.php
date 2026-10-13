<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Cms;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Exception;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Node;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;

/**
 * Reads a detached CMS `SignedData` made elsewhere, checks it against the
 * content it should cover, and amends it (certificates, a timestamp) without
 * touching what was signed.
 *
 * @internal
 */
final class SignedDataReader
{
    private readonly Asn1Node $signedData;

    private readonly Asn1Node $signerInfo;

    private function __construct(Asn1Node $contentInfo, bool $requireSignedAttributes)
    {
        $contentInfo->expect(Der::TAG_SEQUENCE);
        if ($contentInfo->child(0)->oid() !== Oid::SIGNED_DATA) {
            throw new CmsException('Not a CMS SignedData');
        }
        $this->signedData = $contentInfo->child(1)->child(0)->expect(Der::TAG_SEQUENCE);

        $this->signedData->child(2)->expect(Der::TAG_SEQUENCE);
        $signerInfos = $this->signerInfos();
        if (count($signerInfos->children) !== 1) {
            throw new CmsException(sprintf('Expected one SignerInfo, found %d', count($signerInfos->children)));
        }
        $this->signerInfo = $signerInfos->child(0)->expect(Der::TAG_SEQUENCE);
        if ($requireSignedAttributes && $this->signerInfo->contextChild(0) === null) {
            throw new CmsException('The CMS has no signed attributes');
        }
    }

    /**
     * @param  bool  $requireSignedAttributes  False accepts a SignerInfo that signs the content
     *                                         directly (`openssl cms -noattr`) — for verifying only.
     */
    public static function parse(string $der, bool $requireSignedAttributes = true): self
    {
        try {
            // Tools write BER (CryptoPro: indefinite lengths); work on the
            // definite-length re-encoding, which is what gets embedded.
            return new self(DerReader::parse(DerReader::parseBer($der)->toDer()), $requireSignedAttributes);
        } catch (Asn1Exception $e) {
            throw new CmsException('Unreadable CMS: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * The content an attached signature carries, or null for a detached one.
     * A BER constructed OCTET STRING is joined from its pieces.
     */
    public function encapsulatedContent(): ?string
    {
        $eContent = $this->signedData->child(2)->contextChild(0);
        if ($eContent === null) {
            return null;
        }
        $octets = $eContent->child(0);
        if (! $octets->isConstructed()) {
            return $octets->expect(Der::TAG_OCTET_STRING)->content;
        }

        return implode('', array_map(static fn (Asn1Node $n): string => $n->content, $octets->children));
    }

    public function digestAlgorithm(): DigestAlgorithm
    {
        return DigestAlgorithm::fromOid($this->signerInfo->child(2)->child(0)->oid());
    }

    public function hasSignedAttributes(): bool
    {
        return $this->signerInfo->contextChild(0) !== null;
    }

    /** The signed attributes as signed — re-tagged as a universal SET. */
    public function signedAttributes(): string
    {
        $tagged = $this->signerInfo->contextChild(0) ?? throw new CmsException('The CMS has no signed attributes');

        return chr(Der::TAG_SET).substr($tagged->der, 1);
    }

    public function messageDigest(): ?string
    {
        foreach ($this->signerInfo->contextChild(0)->children ?? [] as $attr) {
            if ($attr->child(0)->oid() === Oid::MESSAGE_DIGEST) {
                return $attr->child(1)->child(0)->expect(Der::TAG_OCTET_STRING)->content;
            }
        }

        return null;
    }

    /**
     * DER of each value of an unsigned attribute (a timestamp token, say).
     *
     * @return list<string>
     */
    public function unsignedAttributeValues(string $oid): array
    {
        $out = [];
        foreach ($this->signerInfo->contextChild(1)->children ?? [] as $attr) {
            if ($attr->child(0)->oid() === $oid) {
                foreach ($attr->child(1)->children as $value) {
                    $out[] = $value->der;
                }
            }
        }

        return $out;
    }

    public function signatureValue(): string
    {
        foreach ($this->signerInfo->children as $i => $child) {
            if ($child->tag === Der::TAG_OCTET_STRING && $i >= 4) {
                return $child->content;
            }
        }
        throw new CmsException('The SignerInfo has no signature value');
    }

    /** @return list<Certificate> */
    public function certificates(): array
    {
        $out = [];
        foreach ($this->signedData->contextChild(0)->children ?? [] as $node) {
            if ($node->tag === Der::TAG_SEQUENCE) {
                $out[] = Certificate::fromDer($node->der);
            }
        }

        return $out;
    }

    /**
     * The certificate the SignerInfo names, from the CMS or from
     * `$candidates`.
     *
     * @param  list<Certificate>  $candidates
     */
    public function signerCertificate(array $candidates = []): ?Certificate
    {
        $sid = $this->signerInfo->child(1);
        foreach ([...$this->certificates(), ...$candidates] as $cert) {
            if ($sid->tag === Der::TAG_SEQUENCE) {
                if ($sid->child(0)->der === $cert->issuerDer() && $sid->child(1)->der === $cert->serialDer()) {
                    return $cert;
                }
            } elseif ($sid->contextTag() === 0 && $cert->subjectKeyIdentifier() === $sid->content) {
                return $cert;
            }
        }

        return null;
    }

    /**
     * Checks the signature over the signed attributes with the
     * certificate's public key.
     */
    public function verifySignature(Certificate $certificate, ?string $content = null): bool
    {
        return openssl_verify(
            // Without signed attributes the signature covers the content itself.
            $this->hasSignedAttributes() ? $this->signedAttributes() : ($content ?? throw new CmsException('The CMS has no signed attributes')),
            $this->signatureValue(),
            $certificate->publicKey(),
            $this->digestAlgorithm()->opensslName(),
        ) === 1;
    }

    /**
     * The same CMS, detached, with certificates added (if missing) and
     * unsigned attributes appended. What was signed — the signed attributes,
     * which carry the content digest — is untouched, so the signature stays
     * valid; an attached copy of the content is dropped.
     *
     * @param  list<Certificate>  $certificates
     * @param  list<string>  $unsignedAttributes  Encoded `Attribute`s.
     */
    public function amend(array $certificates, array $unsignedAttributes): string
    {
        $info = $this->signerInfo->children;
        if ($unsignedAttributes !== []) {
            $existing = $this->signerInfo->contextChild(1);
            $attrs = [...array_map(static fn (Asn1Node $n): string => $n->der, $existing->children ?? []), ...$unsignedAttributes];
            $info = array_values(array_filter($info, static fn (Asn1Node $n): bool => $n->contextTag() !== 1));
            $info = array_map(static fn (Asn1Node $n): string => $n->der, $info);
            $info[] = Der::implicitConstructed(1, implode('', $attrs));
        } else {
            $info = array_map(static fn (Asn1Node $n): string => $n->der, $info);
        }
        $signerInfo = Der::sequence(...$info);

        $present = array_map(static fn (Certificate $c): string => $c->der, $this->certificates());
        foreach ($certificates as $cert) {
            if (! in_array($cert->der, $present, true)) {
                $present[] = $cert->der;
            }
        }

        // SignedData: version, digestAlgorithms, encapContentInfo,
        // [0] certificates?, [1] crls?, signerInfos.
        $children = $this->signedData->children;
        $encapType = $children[2]->child(0)->der;
        $fields = [$children[0]->der, $children[1]->der, Der::sequence($encapType)];
        if ($present !== []) {
            $fields[] = Der::implicitConstructed(0, implode('', $present));
        }
        foreach (array_slice($children, 3, -1) as $child) {
            if ($child->contextTag() !== 0) {
                $fields[] = $child->der;
            }
        }
        $fields[] = Der::set($signerInfo);

        return Der::sequence(Der::oid(Oid::SIGNED_DATA), Der::explicit(0, Der::sequence(...$fields)));
    }

    private function signerInfos(): Asn1Node
    {
        $children = $this->signedData->children;

        return $children[count($children) - 1]->expect(Der::TAG_SET);
    }
}
