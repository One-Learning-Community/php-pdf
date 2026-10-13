<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Ltv;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;

/**
 * What a validator needs to check signatures years later, without the
 * network: certificates, OCSP responses and CRLs (all DER) — the contents of
 * the document security store (DSS, ETSI EN 319 142-1 §5.4).
 */
final readonly class ValidationData
{
    /**
     * @param  list<Certificate>  $certificates
     * @param  list<string>  $ocspResponses
     * @param  list<string>  $crls
     */
    public function __construct(
        public array $certificates = [],
        public array $ocspResponses = [],
        public array $crls = [],
    ) {}

    public function merge(self $other): self
    {
        $certs = $this->certificates;
        foreach ($other->certificates as $cert) {
            if (! in_array($cert->der, array_map(static fn (Certificate $c): string => $c->der, $certs), true)) {
                $certs[] = $cert;
            }
        }

        return new self(
            $certs,
            array_values(array_unique([...$this->ocspResponses, ...$other->ocspResponses])),
            array_values(array_unique([...$this->crls, ...$other->crls])),
        );
    }
}
