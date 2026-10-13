<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Asn1;

/**
 * One parsed DER element. Keeps its exact encoding (`$der`), so a piece of a
 * certificate — the issuer Name, say — can be copied byte for byte.
 *
 * @internal
 */
final class Asn1Node
{
    /**
     * @param  list<Asn1Node>  $children  Parsed content of a constructed element.
     */
    public function __construct(
        public readonly int $tag,
        public readonly string $der,
        public readonly string $content,
        public readonly array $children = [],
    ) {}

    public function isConstructed(): bool
    {
        return ($this->tag & 0x20) !== 0;
    }

    /** Context-specific tag number, or null for a universal tag. */
    public function contextTag(): ?int
    {
        return ($this->tag & 0xC0) === 0x80 ? $this->tag & 0x1F : null;
    }

    public function child(int $index): self
    {
        if (! isset($this->children[$index])) {
            throw new Asn1Exception(sprintf('ASN.1 element 0x%02X has no child #%d', $this->tag, $index));
        }

        return $this->children[$index];
    }

    /** First child carrying the context tag `[n]`, if any. */
    public function contextChild(int $tagNumber): ?self
    {
        foreach ($this->children as $child) {
            if ($child->contextTag() === $tagNumber) {
                return $child;
            }
        }

        return null;
    }

    public function expect(int $tag): self
    {
        if ($this->tag !== $tag) {
            throw new Asn1Exception(sprintf('Expected ASN.1 tag 0x%02X, got 0x%02X', $tag, $this->tag));
        }

        return $this;
    }

    public function oid(): string
    {
        $this->expect(Der::TAG_OID);
        $bytes = $this->content;
        if ($bytes === '') {
            throw new Asn1Exception('Empty OID');
        }
        $arcs = [];
        $value = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $b = ord($bytes[$i]);
            $value = ($value << 7) | ($b & 0x7F);
            if (($b & 0x80) === 0) {
                $arcs[] = $value;
                $value = 0;
            }
        }
        $first = array_shift($arcs);
        $head = $first < 80 ? [intdiv($first, 40), $first % 40] : [2, $first - 80];

        return implode('.', [...$head, ...$arcs]);
    }

    /** INTEGER as an int; for values that fit. */
    public function int(): int
    {
        $this->expect(Der::TAG_INTEGER);
        $value = 0;
        foreach (str_split($this->content) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $value;
    }

    /** BIT STRING content without the unused-bits octet. */
    public function bits(): string
    {
        $this->expect(Der::TAG_BIT_STRING);

        return substr($this->content, 1);
    }

    /** UTCTime / GeneralizedTime. */
    public function time(): \DateTimeImmutable
    {
        $format = match ($this->tag) {
            Der::TAG_UTC_TIME => 'ymdHis',
            Der::TAG_GENERALIZED_TIME => 'YmdHis',
            default => throw new Asn1Exception(sprintf('Expected a time, got tag 0x%02X', $this->tag)),
        };
        // Fractions of a second are dropped; the zone is always Z here.
        $raw = (string) preg_replace('/(\.\d+)?Z$/', '', $this->content);
        $at = \DateTimeImmutable::createFromFormat('!'.$format, $raw, new \DateTimeZone('UTC'));
        if ($at === false) {
            throw new Asn1Exception("Unreadable time '{$this->content}'");
        }

        return $at;
    }
}
