<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

use Dskripchenko\PhpPdf\Pdf\SignatureConfig;

/**
 * Placeholder values for the stamp of the field being signed.
 *
 * @internal
 */
final class StampValues
{
    /**
     * @return array<string, string>
     */
    public static function of(SignatureConfig $signature, SignatureAppearance $appearance): array
    {
        $certificate = $signature->certificate();
        $info = $certificate !== null ? CertificateInfo::fromPem($certificate->pem()) : new CertificateInfo;

        return [
            ...$info->placeholders($appearance->dateFormat),
            'signer' => $signature->signerName ?? $info->commonName,
            'signedAt' => $signature->effectiveSignedAt()->format($appearance->dateTimeFormat),
            'reason' => $signature->reason ?? '',
            'location' => $signature->location ?? '',
            'contact' => $signature->contactInfo ?? '',
        ];
    }
}
