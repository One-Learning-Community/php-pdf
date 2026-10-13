<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

/**
 * What changed in a document after a signature, from the least to the most.
 */
enum ModificationLevel: int
{
    /** The signature covers the whole file. */
    case None = 0;

    /** Signatures, document timestamps, validation data (DSS) added. */
    case Signing = 1;

    /** Form field values filled in (plus the above). */
    case FormFilling = 2;

    /** Annotations added or changed (plus the above). */
    case Annotations = 3;

    /** Anything else — page content, resources, structure. Never allowed. */
    case Other = 4;

    /** Whether a certification with DocMDP level `$p` allows this. */
    public function allowedBy(int $p): bool
    {
        return match ($p) {
            1 => $this->value <= self::Signing->value,
            2 => $this->value <= self::FormFilling->value,
            default => $this->value <= self::Annotations->value,
        };
    }
}
