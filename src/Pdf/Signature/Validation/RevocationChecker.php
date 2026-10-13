<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Node;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;

/**
 * Revocation status of a certificate from OCSP responses and CRLs (DER),
 * accepting only those whose own signature verifies: an OCSP response signed
 * by the issuer or by a responder it delegated to (id-kp-OCSPSigning), a CRL
 * signed by the issuer.
 *
 * @internal
 */
final class RevocationChecker
{
    private const OCSP_BASIC = '1.3.6.1.5.5.7.48.1.1';

    private const SHA1 = '1.3.14.3.2.26';

    private const SHA256 = '2.16.840.1.101.3.4.2.1';

    /**
     * @param  list<string>  $ocsps
     * @param  list<string>  $crls
     */
    public function __construct(
        private readonly array $ocsps,
        private readonly array $crls,
    ) {}

    /**
     * @return array{status: RevocationStatus, revokedAt: ?\DateTimeImmutable}
     */
    public function status(Certificate $certificate, Certificate $issuer, \DateTimeImmutable $signingTime): array
    {
        $serial = DerReader::parse($certificate->serialDer())->content;
        $found = null;

        foreach ($this->ocsps as $der) {
            $answer = $this->fromOcsp($der, $serial, $issuer);
            if ($answer !== null) {
                $found = $answer;
                if ($answer['status'] !== RevocationStatus::Good) {
                    break;
                }
            }
        }
        if ($found === null || $found['status'] === RevocationStatus::Good) {
            foreach ($this->crls as $der) {
                $answer = $this->fromCrl($der, $serial, $issuer);
                if ($answer !== null && ($found === null || $answer['status'] !== RevocationStatus::Good)) {
                    $found = $answer;
                }
            }
        }
        if ($found === null) {
            return ['status' => RevocationStatus::Unknown, 'revokedAt' => null];
        }
        if ($found['status'] === RevocationStatus::Revoked && $found['revokedAt'] !== null && $found['revokedAt'] > $signingTime) {
            return ['status' => RevocationStatus::RevokedAfterSigning, 'revokedAt' => $found['revokedAt']];
        }

        return $found;
    }

    /**
     * @return array{status: RevocationStatus, revokedAt: ?\DateTimeImmutable}|null
     */
    private function fromOcsp(string $der, string $serial, Certificate $issuer): ?array
    {
        try {
            $resp = DerReader::parse($der);
            if ($resp->child(0)->content !== "\x00") {
                return null;
            }
            $bytes = $resp->contextChild(0)?->child(0);
            if ($bytes === null || $bytes->child(0)->oid() !== self::OCSP_BASIC) {
                return null;
            }
            $basic = DerReader::parse($bytes->child(1)->content);
            $tbs = $basic->child(0);
            if (! $this->ocspSignatureValid($basic, $issuer)) {
                return null;
            }
            $fields = array_values(array_filter($tbs->children, static fn (Asn1Node $n): bool => $n->contextTag() !== 0));
            // responderID, producedAt, responses, …
            $responses = $fields[2];
            foreach ($responses->children as $single) {
                $certId = $single->child(0);
                if (DerReader::parse($certId->child(3)->der)->content !== $serial) {
                    continue;
                }
                $hashAlg = $certId->child(0)->child(0)->oid();
                $hash = match ($hashAlg) {
                    self::SHA1 => sha1($issuer->subjectDer(), true),
                    self::SHA256 => hash('sha256', $issuer->subjectDer(), true),
                    default => null,
                };
                if ($hash !== $certId->child(1)->content) {
                    continue;
                }
                $status = $single->child(1);

                return match ($status->tag) {
                    0x80 => ['status' => RevocationStatus::Good, 'revokedAt' => null],
                    0xA1 => ['status' => RevocationStatus::Revoked, 'revokedAt' => $status->child(0)->time()],
                    default => null,
                };
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function ocspSignatureValid(Asn1Node $basic, Certificate $issuer): bool
    {
        $digest = Algorithms::digestFor($basic->child(1)->child(0)->oid());
        if ($digest === null) {
            return false;
        }
        $candidates = [$issuer];
        foreach ($basic->contextChild(0)?->child(0)->children ?? [] as $cert) {
            try {
                $candidates[] = Certificate::fromDer($cert->der);
            } catch (\InvalidArgumentException) {
            }
        }
        foreach ($candidates as $responder) {
            $delegated = $responder->der !== $issuer->der;
            if ($delegated && (! ChainBuilder::signedBy($responder, $issuer) || ! self::isOcspSigner($responder))) {
                continue;
            }
            try {
                if (openssl_verify($basic->child(0)->der, $basic->child(2)->bits(), $responder->publicKey(), $digest) === 1) {
                    return true;
                }
            } catch (\RuntimeException) {
            }
        }

        return false;
    }

    private static function isOcspSigner(Certificate $certificate): bool
    {
        $parsed = openssl_x509_parse($certificate->pem());

        return is_array($parsed) && str_contains((string) ($parsed['extensions']['extendedKeyUsage'] ?? ''), 'OCSP Signing');
    }

    /**
     * @return array{status: RevocationStatus, revokedAt: ?\DateTimeImmutable}|null
     */
    private function fromCrl(string $der, string $serial, Certificate $issuer): ?array
    {
        try {
            $crl = DerReader::parse($der);
            $tbs = $crl->child(0);
            $digest = Algorithms::digestFor($crl->child(1)->child(0)->oid());
            if ($digest === null || openssl_verify($tbs->der, $crl->child(2)->bits(), $issuer->publicKey(), $digest) !== 1) {
                return null;
            }
            $fields = $tbs->children;
            $i = $fields[0]->tag === Der::TAG_INTEGER ? 1 : 0;
            if ($fields[$i + 1]->der !== $issuer->subjectDer()) {
                return null;
            }
            foreach (array_slice($fields, $i + 3) as $field) {
                if ($field->tag !== Der::TAG_SEQUENCE) {
                    continue;
                }
                foreach ($field->children as $entry) {
                    if ($entry->tag === Der::TAG_SEQUENCE && ($entry->children[0] ?? null)?->tag === Der::TAG_INTEGER
                        && $entry->child(0)->content === $serial) {
                        return ['status' => RevocationStatus::Revoked, 'revokedAt' => $entry->child(1)->time()];
                    }
                }
            }

            return ['status' => RevocationStatus::Good, 'revokedAt' => null];
        } catch (\Throwable) {
            return null;
        }
    }
}
