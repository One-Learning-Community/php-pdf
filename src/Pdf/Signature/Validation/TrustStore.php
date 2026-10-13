<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;

/**
 * The certificates a validator trusts as roots (trust anchors).
 */
final class TrustStore
{
    /** @var list<Certificate> */
    private array $anchors = [];

    /**
     * @param  list<Certificate>  $anchors
     */
    public function __construct(array $anchors = [])
    {
        foreach ($anchors as $anchor) {
            $this->add($anchor);
        }
    }

    /** From PEM bundles or files. */
    public static function fromPem(string ...$pemOrPaths): self
    {
        $store = new self;
        foreach ($pemOrPaths as $item) {
            $pem = str_contains($item, '-----BEGIN') ? $item : (string) @file_get_contents($item);
            foreach (Certificate::allFromPem($pem) as $cert) {
                $store->add($cert);
            }
        }

        return $store;
    }

    /**
     * The roots OpenSSL trusts on this machine (its default CA file), for
     * certificates from public CAs.
     */
    public static function system(): self
    {
        $locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
        $file = ($locations['default_cert_file'] ?? '') ?: '';

        return is_readable($file) ? self::fromPem($file) : new self;
    }

    public function add(Certificate $certificate): self
    {
        foreach ($this->anchors as $known) {
            if ($known->der === $certificate->der) {
                return $this;
            }
        }
        $this->anchors[] = $certificate;

        return $this;
    }

    public function isAnchor(Certificate $certificate): bool
    {
        foreach ($this->anchors as $anchor) {
            if ($anchor->der === $certificate->der) {
                return true;
            }
        }

        return false;
    }

    /**
     * Anchors that could have issued `$certificate`.
     *
     * @return list<Certificate>
     */
    public function issuersOf(Certificate $certificate): array
    {
        return array_values(array_filter(
            $this->anchors,
            static fn (Certificate $a): bool => $a->subjectDer() === $certificate->issuerDer(),
        ));
    }

    /** @return list<Certificate> */
    public function all(): array
    {
        return $this->anchors;
    }
}
