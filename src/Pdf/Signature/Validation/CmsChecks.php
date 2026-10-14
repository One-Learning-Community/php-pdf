<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\SignedDataReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\LtvException;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\RevocationSource;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;

/**
 * The checks of a CMS signature that do not depend on where it lives: the
 * message digest and the signature value, the signing-certificate binding,
 * a signature timestamp, the path to a trust anchor and the revocation of
 * that path. Shared by the PDF validator and the detached-signature one.
 *
 * @internal
 */
final class CmsChecks
{
    /**
     * @param  list<Certificate>  $pool  Certificates available to build paths (the CMS, a DSS).
     * @param  list<string>  $ocsps  DER OCSP responses at hand (a DSS).
     * @param  list<string>  $crls  DER CRLs at hand (a DSS).
     */
    public function __construct(
        private readonly TrustStore $trust,
        private readonly array $pool,
        private readonly \DateTimeImmutable $now,
        private readonly array $ocsps = [],
        private readonly array $crls = [],
    ) {}

    /**
     * The signature over `$signedBytes`: digest, value, certificate binding,
     * timestamp and chain. Returns false when the signature itself fails —
     * the report then says why and nothing further is checked.
     */
    public function signature(SignatureReport $report, SignedDataReader $cms, string $signedBytes, bool $essExpected): bool
    {
        $signer = $cms->signerCertificate();
        if ($signer === null) {
            $report->error('The signer certificate is not in the signature');

            return false;
        }
        $report->signer = $signer;
        try {
            $digest = $cms->digestAlgorithm();
            // Without signed attributes there is no separate digest: the
            // signature value covers the bytes directly.
            $digestOk = ! $cms->hasSignedAttributes() || $cms->messageDigest() === $digest->digest($signedBytes);
            $signatureOk = $digestOk && $cms->verifySignature($signer, $signedBytes);
        } catch (\Throwable $e) {
            $report->error('The signature cannot be checked here: '.$e->getMessage());

            return false;
        }
        if (! $digestOk) {
            $report->error('The document was changed: its digest does not match the signed one');

            return false;
        }
        if (! $signatureOk) {
            $report->error('The signature value does not verify against the signer certificate');

            return false;
        }
        if ($cms->hasSignedAttributes()) {
            $this->ess($report, $cms, $signer, $essExpected);
        } else {
            $report->warn('No signed attributes: neither the signing time nor the signing certificate is bound to the signature');
        }
        $report->cryptographicallyValid = true;

        // A signature timestamp: the time is vouched for by the TSA.
        $tokens = $cms->unsignedAttributeValues(Oid::SIGNATURE_TIMESTAMP_TOKEN);
        if ($tokens !== []) {
            try {
                $this->timestampToken($report, SignedDataReader::parse($tokens[0]), $cms->signatureValue(), isDocument: false);
            } catch (\Throwable $e) {
                $report->warn('The signature timestamp is unreadable: '.$e->getMessage());
            }
        }

        $chain = (new ChainBuilder($this->trust))->build($signer, $this->pool, $report->timestampTime ?? $this->now);
        $report->chain = $chain['chain'];
        $report->trusted = $chain['trusted'];
        foreach ($chain['problems'] as $problem) {
            $report->error($problem);
        }

        return true;
    }

    /**
     * A timestamp token: its TSTInfo imprint covers `$data`, its own
     * signature verifies, its TSA chains to a trusted root. A document
     * timestamp is the signature itself, so its failures are errors; a
     * signature timestamp only vouches for the time, so they are warnings.
     */
    public function timestampToken(SignatureReport $report, SignedDataReader $token, string $data, bool $isDocument): void
    {
        $fail = static function (string $message) use ($report, $isDocument): void {
            $isDocument ? $report->error($message) : $report->warn($message.' — the signing time is not vouched for');
        };
        try {
            $tstDer = $token->encapsulatedContent();
            if ($tstDer === null) {
                $fail('The timestamp token carries no TSTInfo');

                return;
            }
            $tst = DerReader::parse($tstDer);
            $imprint = $tst->child(2);
            $alg = DigestAlgorithm::fromOid($imprint->child(0)->child(0)->oid());
            if ($imprint->child(1)->content !== $alg->digest($data)) {
                $fail($isDocument ? 'The document was changed: the timestamp covers other bytes' : 'The signature timestamp covers another signature');

                return;
            }
            $tsa = $token->signerCertificate($this->pool);
            if ($tsa === null || $token->messageDigest() !== $token->digestAlgorithm()->digest($tstDer) || ! $token->verifySignature($tsa)) {
                $fail('The timestamp token signature does not verify');

                return;
            }
            $genTime = $tst->child(4)->time();
        } catch (\Throwable $e) {
            $fail('The timestamp token cannot be checked: '.$e->getMessage());

            return;
        }

        $chain = (new ChainBuilder($this->trust))->build($tsa, [...$this->pool, ...$token->certificates()], $genTime);
        if ($isDocument) {
            $report->signer = $tsa;
            $report->chain = $chain['chain'];
            $report->trusted = $chain['trusted'];
            $report->cryptographicallyValid = true;
            foreach ($chain['problems'] as $problem) {
                $report->error($problem);
            }
        } elseif (! $chain['trusted']) {
            $report->warn('The timestamp authority is not trusted: '.implode('; ', $chain['problems']));
            $report->untrustedTimestampTime = $genTime;

            return;
        }
        $report->timestampTime = $genTime;
    }

    /** Revocation of every link of the report's chain, from the data at hand or a source. */
    public function revocation(SignatureReport $report, ?RevocationSource $source): void
    {
        $signingTime = $report->timestampTime ?? $report->claimedSigningTime ?? new \DateTimeImmutable;
        $worst = RevocationStatus::Good;
        $fetched = false;
        $chain = $report->chain;
        for ($i = 0; $i < count($chain) - 1; $i++) {
            [$cert, $issuer] = [$chain[$i], $chain[$i + 1]];
            $checker = new RevocationChecker($this->ocsps, $this->crls);
            $result = $checker->status($cert, $issuer, $signingTime);
            if ($result['status'] === RevocationStatus::Unknown && $source !== null) {
                try {
                    $data = $source->revocationData($cert, $issuer);
                    $result = (new RevocationChecker($data->ocspResponses, $data->crls))->status($cert, $issuer, $signingTime);
                    $fetched = $fetched || $result['status'] !== RevocationStatus::Unknown;
                } catch (LtvException $e) {
                    $report->warn($e->getMessage());
                }
            }
            $name = $cert->subjectCommonName();
            match ($result['status']) {
                RevocationStatus::Revoked => $report->error(sprintf('"%s" was revoked at %s, before signing', $name, $result['revokedAt']?->format('c') ?? '?')),
                RevocationStatus::RevokedAfterSigning => $report->warn(sprintf('"%s" was revoked at %s, after signing', $name, $result['revokedAt']?->format('c') ?? '?')),
                RevocationStatus::Unknown => $report->warn(sprintf('No revocation data for "%s"', $name)),
                RevocationStatus::Good => null,
            };
            $rank = [RevocationStatus::Good->name => 0, RevocationStatus::Unknown->name => 1, RevocationStatus::RevokedAfterSigning->name => 2, RevocationStatus::Revoked->name => 3];
            if ($rank[$result['status']->name] > $rank[$worst->name]) {
                $worst = $result['status'];
            }
        }
        $report->revocation = $worst;
        $report->revocationSource = $worst === RevocationStatus::Unknown ? null : ($fetched ? 'fetched' : 'dss');
    }

    /** The signing time a CMS claims in its `signing-time` attribute. */
    public static function claimedSigningTime(SignedDataReader $cms): ?\DateTimeImmutable
    {
        if (! $cms->hasSignedAttributes()) {
            return null;
        }
        foreach (DerReader::parse($cms->signedAttributes())->children as $attr) {
            if ($attr->child(0)->oid() === Oid::SIGNING_TIME) {
                return $attr->child(1)->child(0)->time();
            }
        }

        return null;
    }

    private function ess(SignatureReport $report, SignedDataReader $cms, Certificate $signer, bool $expected): void
    {
        $attributes = DerReader::parse($cms->signedAttributes());
        foreach ($attributes->children as $attr) {
            if ($attr->child(0)->oid() !== Oid::SIGNING_CERTIFICATE_V2) {
                continue;
            }
            $certId = $attr->child(1)->child(0)->child(0)->child(0);
            $first = $certId->child(0);
            $hashAlg = $first->tag === Der::TAG_SEQUENCE ? DigestAlgorithm::fromOid($first->child(0)->oid()) : DigestAlgorithm::Sha256;
            $hash = $first->tag === Der::TAG_SEQUENCE ? $certId->child(1)->content : $first->content;
            if ($hash !== $hashAlg->digest($signer->der)) {
                $report->error('The signature names a different signing certificate (signing-certificate-v2)');
            }

            return;
        }
        if ($expected) {
            $report->warn(($report->subFilter === 'ETSI.CAdES.detached' ? 'PAdES' : 'CAdES').' signature without signing-certificate-v2');
        }
    }
}
