<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Tsa;

/** An RFC 3161 timestamp token: the DER `ContentInfo` and its time. */
final readonly class TimeStampToken
{
    public function __construct(
        public string $token,
        public \DateTimeImmutable $genTime,
    ) {}
}
