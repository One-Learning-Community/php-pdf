<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Signer;

/**
 * A {@see CmsSigner} in the place of a {@see Signer} inside `SignatureConfig`:
 * certificates and the digest come from it, while the signature is its CMS,
 * never a bare value.
 *
 * @internal Made by `SignatureConfig` from the `CmsSigner` it is given.
 */
final readonly class CmsSignerKey implements Signer
{
    public function __construct(public CmsSigner $cmsSigner) {}

    public function certificate(): Certificate
    {
        return $this->cmsSigner->certificate();
    }

    public function chain(): array
    {
        return $this->cmsSigner->chain();
    }

    public function digestAlgorithm(): DigestAlgorithm
    {
        return $this->cmsSigner->digestAlgorithm();
    }

    public function sign(string $data): string
    {
        throw new \LogicException('A CmsSigner returns a whole CMS, not a signature value');
    }
}
