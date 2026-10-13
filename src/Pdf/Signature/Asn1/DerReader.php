<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Asn1;

/**
 * DER parser into an {@see Asn1Node} tree.
 *
 * Constructed elements are parsed recursively; OCTET STRINGs are left as
 * bytes (their content is often DER again — parse it with another call when
 * needed). Indefinite lengths are refused: DER does not allow them.
 *
 * @internal
 */
final class DerReader
{
    private const MAX_DEPTH = 64;

    public static function parse(string $der): Asn1Node
    {
        $offset = 0;
        $node = self::element($der, $offset, 0);
        if ($offset !== strlen($der)) {
            throw new Asn1Exception(sprintf('%d trailing bytes after the DER element', strlen($der) - $offset));
        }

        return $node;
    }

    /**
     * The first element of a buffer, ignoring what follows — for a DER blob
     * padded with zeros (a PDF /Contents value).
     */
    public static function parseFirst(string $bytes): Asn1Node
    {
        $offset = 0;

        return self::element($bytes, $offset, 0);
    }

    private static function element(string $data, int &$offset, int $depth): Asn1Node
    {
        if ($depth > self::MAX_DEPTH) {
            throw new Asn1Exception('ASN.1 nesting too deep');
        }
        $start = $offset;
        $size = strlen($data);
        if ($offset + 2 > $size) {
            throw new Asn1Exception('Truncated ASN.1 element');
        }
        $tag = ord($data[$offset++]);
        if (($tag & 0x1F) === 0x1F) {
            throw new Asn1Exception('High tag numbers are not supported');
        }
        $len = ord($data[$offset++]);
        if ($len === 0x80) {
            throw new Asn1Exception('Indefinite length is not DER');
        }
        if ($len > 0x80) {
            $n = $len & 0x7F;
            if ($n > 4 || $offset + $n > $size) {
                throw new Asn1Exception('Bad ASN.1 length');
            }
            $len = 0;
            for ($i = 0; $i < $n; $i++) {
                $len = ($len << 8) | ord($data[$offset++]);
            }
        }
        if ($offset + $len > $size) {
            throw new Asn1Exception('ASN.1 length runs past the end of the data');
        }
        $content = substr($data, $offset, $len);
        $offset += $len;

        $children = [];
        if (($tag & 0x20) !== 0) {
            $inner = 0;
            while ($inner < $len) {
                $children[] = self::element($content, $inner, $depth + 1);
            }
        }

        return new Asn1Node($tag, substr($data, $start, $offset - $start), $content, $children);
    }
}
