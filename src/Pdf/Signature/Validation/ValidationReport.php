<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

/** The verdicts on every signature and document timestamp of a PDF. */
final readonly class ValidationReport
{
    /**
     * @param  list<SignatureReport>  $signatures  In file order.
     */
    public function __construct(public array $signatures) {}

    /** Whether the document has signatures and all of them are valid. */
    public function allValid(): bool
    {
        if ($this->signatures === []) {
            return false;
        }
        foreach ($this->signatures as $signature) {
            if (! $signature->isValid()) {
                return false;
            }
        }

        return true;
    }

    public function signature(string $fieldName): ?SignatureReport
    {
        foreach ($this->signatures as $signature) {
            if ($signature->fieldName === $fieldName) {
                return $signature;
            }
        }

        return null;
    }
}
