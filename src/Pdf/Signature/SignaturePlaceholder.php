<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature;

/**
 * The /ByteRange and /Contents placeholders of a signature dictionary in
 * emitted PDF bytes.
 *
 * {@see locate()} finds them and fills /ByteRange with the real offsets;
 * {@see signedContent()} is then exactly what the signature covers, and
 * {@see embed()} writes the DER signature into /Contents.
 *
 * @internal
 */
final readonly class SignaturePlaceholder
{
    /**
     * @param  array{0: int, 1: int, 2: int, 3: int}  $byteRange
     */
    public function __construct(
        public array $byteRange,
        public int $hexOffset,
        public int $hexLength,
    ) {}

    /**
     * Finds the placeholders and patches /ByteRange.
     *
     * @return array{0: string, 1: self} the patched bytes and the placeholder
     */
    public static function locate(string $bytes): array
    {
        // /ByteRange [<digits> <digits> <digits> <digits>] — zeros padded
        // with spaces to 10 digits each, room for offsets of ~10 GB files.
        if (! preg_match('@/ByteRange \[(\d+ +\d+ +\d+ +\d+) +\]@', $bytes, $brMatch, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException('/ByteRange placeholder not found in PDF stream');
        }
        $brStart = (int) $brMatch[0][1];
        $brLen = strlen((string) $brMatch[0][0]);

        if (! preg_match('@/Contents <0+>@', $bytes, $cMatch, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException('/Contents placeholder not found');
        }
        $cStart = (int) $cMatch[0][1];
        $cLen = strlen((string) $cMatch[0][0]);

        // Covered: [0, '<') and (after '>', EOF).
        $gapStart = $cStart + strlen('/Contents ');
        $gapEnd = $cStart + $cLen;
        $byteRange = [0, $gapStart, $gapEnd, strlen($bytes) - $gapEnd];

        $brContent = sprintf('/ByteRange [%d %d %d %d]', ...$byteRange);
        if (strlen($brContent) > $brLen) {
            throw new \RuntimeException('/ByteRange replacement exceeds placeholder');
        }
        $bytes = substr_replace($bytes, str_pad($brContent, $brLen, ' ', STR_PAD_RIGHT), $brStart, $brLen);

        return [$bytes, new self($byteRange, $cStart + strlen('/Contents <'), $cLen - strlen('/Contents <') - 1)];
    }

    /** The bytes the signature covers. */
    public function signedContent(string $bytes): string
    {
        [$a, $b, $c, $d] = $this->byteRange;

        return substr($bytes, $a, $b).substr($bytes, $c, $d);
    }

    /** Bytes available for the DER signature. */
    public function capacity(): int
    {
        return intdiv($this->hexLength, 2);
    }

    /** Writes the DER signature into /Contents, zero-padded. */
    public function embed(string $bytes, string $der): string
    {
        $hex = strtoupper(bin2hex($der));
        if (strlen($hex) > $this->hexLength) {
            throw new \RuntimeException(sprintf(
                'The signature takes %d bytes but %d were reserved — set SignatureConfig::$contentsReserveBytes to at least %d',
                strlen($der), $this->capacity(), strlen($der) + 1024,
            ));
        }

        return substr_replace($bytes, str_pad($hex, $this->hexLength, '0', STR_PAD_RIGHT), $this->hexOffset, $this->hexLength);
    }
}
