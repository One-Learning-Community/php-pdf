<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Font\Ttf;

/**
 * Minimal reader of the OpenType `CFF ` table (Compact Font Format 1).
 *
 * Reads only what embedding needs: whether the font is CID-keyed and, if so,
 * the charset that maps glyph indices to CIDs. The outlines themselves are
 * embedded verbatim as a /FontFile3 /CIDFontType0C stream.
 *
 * Why the charset matters (ISO 32000-1 §9.7.4.2): in a CIDFontType0 font a
 * PDF reader takes the CID straight as the glyph index when the CFF Top DICT
 * has no CIDFont operators (name-keyed font), but looks the CID up in the CFF
 * charset when it has them (CID-keyed font). Text is therefore written with
 * CID = GID for name-keyed fonts and with CID = charset[GID] for CID-keyed ones.
 *
 * Reference: Adobe Technical Note #5176 (The Compact Font Format Specification).
 */
final class CffTable
{
    /** Top DICT operators (12 xx encoded as 1200 + xx). */
    private const OP_CHARSET = 15;

    private const OP_CHARSTRINGS = 17;

    private const OP_CHARSTRING_TYPE = 1206;

    private const OP_ROS = 1230;

    /**
     * @param  list<int>|null  $cids  glyph index → CID; null for a name-keyed font
     */
    private function __construct(
        private readonly int $glyphCount,
        private readonly ?array $cids,
    ) {}

    public function glyphCount(): int
    {
        return $this->glyphCount;
    }

    public function isCidKeyed(): bool
    {
        return $this->cids !== null;
    }

    /** CID under which a PDF reader finds the glyph (see the class docblock). */
    public function cidForGlyph(int $glyphId): int
    {
        if ($this->cids === null) {
            return $glyphId;
        }

        return $this->cids[$glyphId] ?? 0;
    }

    /** @throws \RuntimeException on a malformed or unsupported table */
    public static function parse(string $cff): self
    {
        if (strlen($cff) < 4) {
            throw new \RuntimeException('Malformed CFF table: shorter than its header.');
        }
        $major = ord($cff[0]);
        if ($major !== 1) {
            throw new \RuntimeException("Unsupported CFF major version $major.");
        }
        $hdrSize = ord($cff[2]);

        // Header → Name INDEX → Top DICT INDEX.
        [, $nameEnd] = self::readIndex($cff, $hdrSize);
        [$topDicts] = self::readIndex($cff, $nameEnd);
        if ($topDicts === []) {
            throw new \RuntimeException('Malformed CFF table: empty Top DICT INDEX.');
        }
        if (count($topDicts) > 1) {
            throw new \RuntimeException('CFF FontSets with more than one font are not supported.');
        }
        $top = self::parseDict($topDicts[0]);

        $charstringType = $top[self::OP_CHARSTRING_TYPE][0] ?? 2;
        if ((int) $charstringType !== 2) {
            throw new \RuntimeException("Unsupported CFF CharstringType $charstringType.");
        }
        if (! isset($top[self::OP_CHARSTRINGS][0])) {
            throw new \RuntimeException('Malformed CFF table: Top DICT has no CharStrings.');
        }
        $glyphCount = self::uint($cff, (int) $top[self::OP_CHARSTRINGS][0], 2);

        if (! isset($top[self::OP_ROS])) {
            return new self($glyphCount, null);
        }

        // CID-keyed: the charset is always a custom one (offsets 0..2 name the
        // predefined charsets of name-keyed fonts only).
        $charsetOffset = (int) ($top[self::OP_CHARSET][0] ?? 0);
        if ($charsetOffset <= 2) {
            throw new \RuntimeException('Malformed CFF table: CID-keyed font without a charset.');
        }

        return new self($glyphCount, self::readCharset($cff, $charsetOffset, $glyphCount));
    }

    /**
     * Charset formats 0, 1 and 2. Glyph 0 (.notdef) is implicit and has CID 0.
     *
     * @return list<int>
     */
    private static function readCharset(string $cff, int $offset, int $glyphCount): array
    {
        $format = self::uint($cff, $offset, 1);
        $pos = $offset + 1;
        $cids = [0];
        if ($format === 0) {
            for ($gid = 1; $gid < $glyphCount; $gid++, $pos += 2) {
                $cids[] = self::uint($cff, $pos, 2);
            }

            return $cids;
        }
        if ($format !== 1 && $format !== 2) {
            throw new \RuntimeException("Malformed CFF table: unknown charset format $format.");
        }
        $nLeftSize = $format === 1 ? 1 : 2;
        while (count($cids) < $glyphCount) {
            $first = self::uint($cff, $pos, 2);
            $nLeft = self::uint($cff, $pos + 2, $nLeftSize);
            $pos += 2 + $nLeftSize;
            for ($i = 0; $i <= $nLeft && count($cids) < $glyphCount; $i++) {
                $cids[] = $first + $i;
            }
        }

        return $cids;
    }

    /**
     * INDEX at $offset → its objects and the offset right after it.
     *
     * @return array{0: list<string>, 1: int}
     */
    private static function readIndex(string $cff, int $offset): array
    {
        $count = self::uint($cff, $offset, 2);
        if ($count === 0) {
            return [[], $offset + 2];
        }
        $offSize = self::uint($cff, $offset + 2, 1);
        if ($offSize < 1 || $offSize > 4) {
            throw new \RuntimeException("Malformed CFF table: INDEX offSize $offSize.");
        }
        $offsets = [];
        for ($i = 0; $i <= $count; $i++) {
            $offsets[] = self::uint($cff, $offset + 3 + $i * $offSize, $offSize);
        }
        // Offsets are 1-based, relative to the byte before the object data.
        $base = $offset + 2 + ($count + 1) * $offSize;
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $length = $offsets[$i + 1] - $offsets[$i];
            if ($length < 0) {
                throw new \RuntimeException('Malformed CFF table: INDEX offsets out of order.');
            }
            $items[] = self::bytesAt($cff, $base + $offsets[$i], $length);
        }

        return [$items, $base + $offsets[$count]];
    }

    /** Big-endian unsigned integer of $n bytes at $offset. */
    private static function uint(string $data, int $offset, int $n): int
    {
        $v = 0;
        foreach (str_split(self::bytesAt($data, $offset, $n)) as $byte) {
            $v = ($v << 8) | ord($byte);
        }

        return $v;
    }

    /**
     * DICT data → operator => operands. Two-byte operators are keyed 1200 + b1.
     *
     * @return array<int, list<int|float>>
     */
    private static function parseDict(string $data): array
    {
        $dict = [];
        $operands = [];
        $len = strlen($data);
        $i = 0;
        while ($i < $len) {
            $b0 = ord($data[$i]);
            if ($b0 <= 21) {
                $op = $b0;
                $i++;
                if ($b0 === 12) {
                    if ($i >= $len) {
                        throw new \RuntimeException('Malformed CFF DICT: truncated operator.');
                    }
                    $op = 1200 + ord($data[$i]);
                    $i++;
                }
                $dict[$op] = $operands;
                $operands = [];
            } elseif ($b0 === 28) {
                $operands[] = self::int16(self::bytesAt($data, $i + 1, 2));
                $i += 3;
            } elseif ($b0 === 29) {
                $v = unpack('N', self::bytesAt($data, $i + 1, 4));
                $u = $v === false ? 0 : (int) $v[1];
                $operands[] = $u >= 0x80000000 ? $u - 0x100000000 : $u;
                $i += 5;
            } elseif ($b0 === 30) {
                [$operands[], $i] = self::readReal($data, $i + 1);
            } elseif ($b0 >= 32 && $b0 <= 246) {
                $operands[] = $b0 - 139;
                $i++;
            } elseif ($b0 >= 247 && $b0 <= 250) {
                $operands[] = ($b0 - 247) * 256 + ord(self::bytesAt($data, $i + 1, 1)) + 108;
                $i += 2;
            } elseif ($b0 >= 251 && $b0 <= 254) {
                $operands[] = -($b0 - 251) * 256 - ord(self::bytesAt($data, $i + 1, 1)) - 108;
                $i += 2;
            } else {
                throw new \RuntimeException("Malformed CFF DICT: reserved byte $b0.");
            }
        }

        return $dict;
    }

    /** @return array{0: float, 1: int}  value and the offset after it */
    private static function readReal(string $data, int $i): array
    {
        $map = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', 'E', 'E-', '', '-'];
        $s = '';
        $len = strlen($data);
        while ($i < $len) {
            $b = ord($data[$i++]);
            foreach ([$b >> 4, $b & 0x0F] as $nibble) {
                if ($nibble === 0x0F) {
                    return [(float) $s, $i];
                }
                $s .= $map[$nibble];
            }
        }
        throw new \RuntimeException('Malformed CFF DICT: unterminated real number.');
    }

    private static function bytesAt(string $data, int $offset, int $n): string
    {
        if ($offset < 0 || $n < 0 || $offset + $n > strlen($data)) {
            throw new \RuntimeException('Malformed CFF data: read past the end.');
        }

        return substr($data, $offset, $n);
    }

    private static function int16(string $two): int
    {
        $v = (ord($two[0]) << 8) | ord($two[1]);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }
}
