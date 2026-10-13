<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\External;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Signer;

/**
 * Stands in for a key that is not in this process. Used with
 * `prepareSignature()`: the document is emitted with an empty signature,
 * signed elsewhere, and finished with {@see PreparedSignature::complete()}.
 *
 * The certificate is optional for {@see ExternalMode::Cms} — without it the
 * stamp shows no certificate details and the signature space is a generous
 * estimate; it is required for {@see ExternalMode::RawSignature}.
 */
final class ExternalSigner implements Signer
{
    private readonly ?Certificate $certificate;

    /** @var list<Certificate> */
    private readonly array $chain;

    private readonly DigestAlgorithm $digest;

    /**
     * @param  list<string>|string  $chainPem
     * @param  ?DigestAlgorithm  $digest  Defaults to the certificate's customary hash, else SHA-256.
     */
    public function __construct(
        ?string $certificatePem = null,
        array|string $chainPem = [],
        ?DigestAlgorithm $digest = null,
    ) {
        $this->certificate = $certificatePem !== null ? Certificate::fromPem($certificatePem) : null;
        $chain = [];
        foreach (is_string($chainPem) ? [$chainPem] : $chainPem as $bundle) {
            array_push($chain, ...Certificate::allFromPem($bundle));
        }
        $this->chain = $chain;
        $this->digest = $digest ?? ($this->certificate !== null ? DigestAlgorithm::defaultFor($this->certificate) : DigestAlgorithm::Sha256);
    }

    public function hasCertificate(): bool
    {
        return $this->certificate !== null;
    }

    public function certificate(): Certificate
    {
        return $this->certificate ?? throw new \LogicException('The external signer was set up without a certificate');
    }

    public function chain(): array
    {
        return $this->chain;
    }

    public function digestAlgorithm(): DigestAlgorithm
    {
        return $this->digest;
    }

    public function sign(string $data): string
    {
        throw new \LogicException('An external signer does not sign in-process: use prepareSignature() and PreparedSignature::complete()');
    }
}
