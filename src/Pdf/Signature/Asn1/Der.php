<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Asn1;

/**
 * DER encoder (ITU-T X.690) — just the types CMS, X.509 and RFC 3161 need.
 *
 * Every method returns the complete TLV as a binary string; constructed
 * values take already-encoded children.
 *
 * @internal
 */
final class Der
{
    public const TAG_BOOLEAN = 0x01;

    public const TAG_INTEGER = 0x02;

    public const TAG_BIT_STRING = 0x03;

    public const TAG_OCTET_STRING = 0x04;

    public const TAG_NULL = 0x05;

    public const TAG_OID = 0x06;

    public const TAG_UTF8_STRING = 0x0C;

    public const TAG_PRINTABLE_STRING = 0x13;

    public const TAG_IA5_STRING = 0x16;

    public const TAG_UTC_TIME = 0x17;

    public const TAG_GENERALIZED_TIME = 0x18;

    public const TAG_SEQUENCE = 0x30;

    public const TAG_SET = 0x31;

    public static function tlv(int $tag, string $content): string
    {
        return chr($tag).self::length(strlen($content)).$content;
    }

    public static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    public static function sequence(string ...$children): string
    {
        return self::tlv(self::TAG_SEQUENCE, implode('', $children));
    }

    /**
     * SET OF — DER requires the encodings sorted as octet strings.
     */
    public static function set(string ...$children): string
    {
        sort($children, SORT_STRING);

        return self::tlv(self::TAG_SET, implode('', $children));
    }

    public static function integer(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Negative INTEGER is not needed here');
        }
        $bytes = '';
        do {
            $bytes = chr($value & 0xFF).$bytes;
            $value >>= 8;
        } while ($value > 0);

        return self::integerFromBytes($bytes);
    }

    /**
     * A non-negative INTEGER from big-endian magnitude bytes (serials,
     * nonces) — a leading zero is added when the top bit is set.
     */
    public static function integerFromBytes(string $magnitude): string
    {
        $magnitude = ltrim($magnitude, "\x00");
        if ($magnitude === '' || (ord($magnitude[0]) & 0x80) !== 0) {
            $magnitude = "\x00".$magnitude;
        }

        return self::tlv(self::TAG_INTEGER, $magnitude);
    }

    public static function boolean(bool $value): string
    {
        return self::tlv(self::TAG_BOOLEAN, $value ? "\xFF" : "\x00");
    }

    public static function null(): string
    {
        return "\x05\x00";
    }

    public static function octetString(string $bytes): string
    {
        return self::tlv(self::TAG_OCTET_STRING, $bytes);
    }

    public static function bitString(string $bytes, int $unusedBits = 0): string
    {
        return self::tlv(self::TAG_BIT_STRING, chr($unusedBits).$bytes);
    }

    public static function utf8String(string $value): string
    {
        return self::tlv(self::TAG_UTF8_STRING, $value);
    }

    public static function oid(string $dotted): string
    {
        $arcs = explode('.', $dotted);
        if (count($arcs) < 2 || ! ctype_digit(implode('', $arcs))) {
            throw new \InvalidArgumentException("Not an OID: $dotted");
        }
        $first = (int) array_shift($arcs);
        $second = (int) array_shift($arcs);
        $body = self::base128($first * 40 + $second);
        foreach ($arcs as $arc) {
            $body .= self::base128((int) $arc);
        }

        return self::tlv(self::TAG_OID, $body);
    }

    /** UTCTime for years 1950–2049, GeneralizedTime otherwise (RFC 5280 §4.1.2.5). */
    public static function time(\DateTimeInterface $at): string
    {
        $utc = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'));
        $year = (int) $utc->format('Y');
        if ($year >= 1950 && $year < 2050) {
            return self::tlv(self::TAG_UTC_TIME, $utc->format('ymdHis').'Z');
        }

        return self::generalizedTime($utc);
    }

    public static function generalizedTime(\DateTimeInterface $at): string
    {
        $utc = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'));

        return self::tlv(self::TAG_GENERALIZED_TIME, $utc->format('YmdHis').'Z');
    }

    /** `[n]` EXPLICIT — wraps an encoded value. */
    public static function explicit(int $tagNumber, string $encoded): string
    {
        return self::tlv(0xA0 | $tagNumber, $encoded);
    }

    /**
     * `[n]` IMPLICIT constructed — replaces the tag of a SEQUENCE / SET with a
     * context tag, keeping its content.
     */
    public static function implicitConstructed(int $tagNumber, string $content): string
    {
        return self::tlv(0xA0 | $tagNumber, $content);
    }

    /** AlgorithmIdentifier with absent parameters, or NULL ones. */
    public static function algorithm(string $oid, bool $nullParameters = false): string
    {
        return $nullParameters
            ? self::sequence(self::oid($oid), self::null())
            : self::sequence(self::oid($oid));
    }

    private static function base128(int $value): string
    {
        $out = chr($value & 0x7F);
        $value >>= 7;
        while ($value > 0) {
            $out = chr(0x80 | ($value & 0x7F)).$out;
            $value >>= 7;
        }

        return $out;
    }
}
