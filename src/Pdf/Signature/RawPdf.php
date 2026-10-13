<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature;

/**
 * A value already in PDF syntax, inserted as is next to parsed values.
 *
 * @internal
 */
final readonly class RawPdf
{
    public function __construct(public string $syntax) {}
}
