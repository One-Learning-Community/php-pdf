<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature;

/** A signature field of an existing document. */
final readonly class SignatureFieldInfo
{
    /**
     * @param  ?int  $page  Zero-based page index of its widget, if found.
     * @param  ?list<float>  $rect  [x1 y1 x2 y2]; all zeros for an invisible signature.
     */
    public function __construct(
        public string $name,
        public ?int $page,
        public ?array $rect,
        public bool $signed,
    ) {}
}
