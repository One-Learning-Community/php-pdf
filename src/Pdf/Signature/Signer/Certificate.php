<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Signer;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Exception;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Node;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;

/**
 * An X.509 certificate as CMS needs it: the exact DER, the issuer Name and
 * serial for `IssuerAndSerialNumber`, and the public-key algorithm.
 */
final class Certificate
{
    private readonly Asn1Node $tbs;

    private function __construct(public readonly string $der)
    {
        try {
            $root = DerReader::parse($der)->expect(Der::TAG_SEQUENCE);
            $this->tbs = $root->child(0)->expect(Der::TAG_SEQUENCE);
            // Touch the fields once so a malformed certificate fails here.
            $this->serialDer();
            $this->issuerDer();
            $this->publicKeyAlgorithm();
        } catch (Asn1Exception $e) {
            throw new \InvalidArgumentException('Not a readable X.509 certificate: '.$e->getMessage(), previous: $e);
        }
    }

    public static function fromDer(string $der): self
    {
        return new self($der);
    }

    /** One certificate from PEM (the first block if there are several). */
    public static function fromPem(string $pem): self
    {
        $all = self::allFromPem($pem);
        if ($all === []) {
            throw new \InvalidArgumentException('No PEM certificate found');
        }

        return $all[0];
    }

    /**
     * Every `BEGIN CERTIFICATE` block of a PEM bundle, in order.
     *
     * @return list<self>
     */
    public static function allFromPem(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----\s+([A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----/', $pem, $m);
        $out = [];
        foreach ($m[1] as $b64) {
            $der = base64_decode((string) preg_replace('/\s+/', '', $b64), true);
            if ($der === false || $der === '') {
                throw new \InvalidArgumentException('Broken base64 in a PEM certificate');
            }
            $out[] = new self($der);
        }

        return $out;
    }

    public function pem(): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_encode($this->der), 64, "\n")
            ."-----END CERTIFICATE-----\n";
    }

    /** The serialNumber INTEGER, as encoded. */
    public function serialDer(): string
    {
        return $this->tbs->child($this->fieldOffset())->expect(Der::TAG_INTEGER)->der;
    }

    /** The issuer Name, as encoded. */
    public function issuerDer(): string
    {
        return $this->tbs->child($this->fieldOffset() + 2)->expect(Der::TAG_SEQUENCE)->der;
    }

    /** The subject Name, as encoded. */
    public function subjectDer(): string
    {
        return $this->tbs->child($this->fieldOffset() + 4)->expect(Der::TAG_SEQUENCE)->der;
    }

    /** OID of the subject public key algorithm. */
    public function publicKeyAlgorithm(): string
    {
        return $this->spki()->child(0)->child(0)->oid();
    }

    /** Named-curve OID of an EC key, if the key is EC. */
    public function ecCurve(): ?string
    {
        if ($this->publicKeyAlgorithm() !== Oid::EC_PUBLIC_KEY) {
            return null;
        }
        $params = $this->spki()->child(0)->children[1] ?? null;

        return $params !== null && $params->tag === Der::TAG_OID ? $params->oid() : null;
    }

    public function keyType(): KeyType
    {
        return match ($this->publicKeyAlgorithm()) {
            Oid::RSA_ENCRYPTION => KeyType::Rsa,
            Oid::EC_PUBLIC_KEY => KeyType::Ec,
            Oid::GOST_3410_2012_256 => KeyType::Gost256,
            Oid::GOST_3410_2012_512 => KeyType::Gost512,
            default => throw new \InvalidArgumentException(
                'Unsupported certificate key algorithm '.$this->publicKeyAlgorithm(),
            ),
        };
    }

    /** Public key size in bits: the RSA modulus, the EC field; 256/512 for GOST. */
    public function keyBits(): int
    {
        $key = $this->spki()->child(1)->bits();

        return match ($this->keyType()) {
            KeyType::Rsa => (strlen(ltrim(DerReader::parse($key)->child(0)->content, "\x00"))) * 8,
            // Uncompressed point 04 || X || Y.
            KeyType::Ec => intdiv(strlen($key) - 1, 2) * 8,
            KeyType::Gost256 => 256,
            KeyType::Gost512 => 512,
        };
    }

    /** The subjectKeyIdentifier extension value, if present. */
    public function subjectKeyIdentifier(): ?string
    {
        foreach ($this->tbs->children as $field) {
            if ($field->contextTag() !== 3) {
                continue;
            }
            foreach ($field->child(0)->children as $extension) {
                if ($extension->child(0)->oid() === '2.5.29.14') {
                    $value = $extension->children[count($extension->children) - 1]->expect(Der::TAG_OCTET_STRING);

                    return DerReader::parse($value->content)->expect(Der::TAG_OCTET_STRING)->content;
                }
            }
        }

        return null;
    }

    /** OpenSSL's PEM public key, for `openssl_verify()`. */
    public function publicKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_public($this->pem());
        if ($key === false) {
            throw new \RuntimeException('Cannot read the certificate public key'
                .($this->keyType() === KeyType::Gost256 || $this->keyType() === KeyType::Gost512 ? ' — is the OpenSSL gost engine loaded?' : ''));
        }

        return $key;
    }

    private function spki(): Asn1Node
    {
        return $this->tbs->child($this->fieldOffset() + 5)->expect(Der::TAG_SEQUENCE);
    }

    /** 1 when the optional `[0] version` is present, else 0. */
    private function fieldOffset(): int
    {
        return $this->tbs->child(0)->contextTag() === 0 ? 1 : 0;
    }
}
