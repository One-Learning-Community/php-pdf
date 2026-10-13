<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;

/**
 * Builds and checks the path from a certificate to a trust anchor: every link
 * verified with its issuer's key, every certificate valid at the given time.
 *
 * @internal
 */
final class ChainBuilder
{
    private const MAX_DEPTH = 10;

    public function __construct(private readonly TrustStore $trust) {}

    /**
     * @param  list<Certificate>  $pool  Certificates available to build the path (CMS, DSS).
     * @return array{chain: list<Certificate>, trusted: bool, problems: list<string>}
     */
    public function build(Certificate $leaf, array $pool, \DateTimeImmutable $at): array
    {
        $chain = [$leaf];
        $problems = [];
        $current = $leaf;
        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            if (! self::validAt($current, $at)) {
                $problems[] = sprintf('Certificate "%s" is not valid at %s', $current->subjectCommonName(), $at->format('c'));
            }
            if ($this->trust->isAnchor($current)) {
                return ['chain' => $chain, 'trusted' => $problems === [], 'problems' => $problems];
            }
            $issuer = null;
            foreach ([...$this->trust->issuersOf($current), ...$pool] as $candidate) {
                if ($candidate->subjectDer() !== $current->issuerDer() || ($candidate->der === $current->der && ! $current->isSelfIssued())) {
                    continue;
                }
                if (self::signedBy($current, $candidate)) {
                    $issuer = $candidate;

                    break;
                }
            }
            if ($issuer === null || $issuer->der === $current->der) {
                $problems[] = $current->isSelfIssued()
                    ? sprintf('The root "%s" is not trusted', $current->subjectCommonName())
                    : sprintf('No issuer found for "%s"', $current->subjectCommonName());

                return ['chain' => $chain, 'trusted' => false, 'problems' => $problems];
            }
            $chain[] = $issuer;
            $current = $issuer;
        }
        $problems[] = 'The certificate path is too long';

        return ['chain' => $chain, 'trusted' => false, 'problems' => $problems];
    }

    public static function signedBy(Certificate $certificate, Certificate $issuer): bool
    {
        try {
            return openssl_x509_verify($certificate->pem(), $issuer->publicKey()) === 1;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public static function validAt(Certificate $certificate, \DateTimeImmutable $at): bool
    {
        $parsed = openssl_x509_parse($certificate->pem());
        if (! is_array($parsed)) {
            return false;
        }
        $from = (int) ($parsed['validFrom_time_t'] ?? 0);
        $to = (int) ($parsed['validTo_time_t'] ?? 0);

        return $at->getTimestamp() >= $from && $at->getTimestamp() <= $to;
    }
}
