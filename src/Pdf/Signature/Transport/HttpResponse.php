<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Transport;

final readonly class HttpResponse
{
    /**
     * @param  array<string, string>  $headers  Lower-cased names.
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
