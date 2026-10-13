<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Ltv;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;

/**
 * Revocation data from the network: OCSP first, the CRL when the
 * certificate names no responder or the responder fails.
 */
final class OnlineRevocationSource implements RevocationSource
{
    private readonly OcspClient $ocsp;

    private readonly CrlFetcher $crl;

    public function __construct(?OcspClient $ocsp = null, ?CrlFetcher $crl = null)
    {
        $this->ocsp = $ocsp ?? new OcspClient;
        $this->crl = $crl ?? new CrlFetcher;
    }

    public function revocationData(Certificate $certificate, Certificate $issuer): ValidationData
    {
        $errors = [];
        if ($certificate->ocspUrls() !== []) {
            try {
                return new ValidationData(ocspResponses: [$this->ocsp->fetch($certificate, $issuer)]);
            } catch (LtvException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($certificate->crlUrls() !== []) {
            try {
                return new ValidationData(crls: [$this->crl->fetch($certificate)]);
            } catch (LtvException $e) {
                $errors[] = $e->getMessage();
            }
        }
        throw new LtvException(sprintf(
            'No revocation data for "%s"%s',
            $certificate->subjectCommonName(),
            $errors === [] ? ': it names neither an OCSP responder nor a CRL' : ': '.implode('; ', $errors),
        ));
    }
}
