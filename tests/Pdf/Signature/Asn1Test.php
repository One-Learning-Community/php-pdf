<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Asn1Exception;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Asn1Test extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function oids(): iterable
    {
        yield 'sha256' => ['2.16.840.1.101.3.4.2.1', '0609608648016503040201'];
        yield 'rsaEncryption' => ['1.2.840.113549.1.1.1', '06092A864886F70D010101'];
        yield 'gost 2012-256' => ['1.2.643.7.1.1.1.1', '06082A85030701010101'];
        yield 'small first arcs' => ['0.9.2342', '0603099226'];
    }

    #[Test]
    #[DataProvider('oids')]
    public function oids_encode_and_decode(string $dotted, string $hex): void
    {
        self::assertSame($hex, strtoupper(bin2hex(Der::oid($dotted))));
        self::assertSame($dotted, DerReader::parse((string) hex2bin($hex))->oid());
    }

    #[Test]
    public function lengths_use_short_and_long_forms(): void
    {
        self::assertSame("\x05", Der::length(5));
        self::assertSame("\x7F", Der::length(127));
        self::assertSame("\x81\x80", Der::length(128));
        self::assertSame("\x82\x01\x00", Der::length(256));

        $long = Der::octetString(str_repeat('x', 300));
        self::assertSame(300, strlen(DerReader::parse($long)->content));
    }

    #[Test]
    public function integers_keep_the_sign_bit_clear(): void
    {
        self::assertSame('020100', bin2hex(Der::integer(0)));
        self::assertSame('02017F', strtoupper(bin2hex(Der::integer(127))));
        self::assertSame('02020080', bin2hex(Der::integer(128)));
        self::assertSame('020300FF01', strtoupper(bin2hex(Der::integerFromBytes("\x00\xFF\x01"))));
        self::assertSame(65537, DerReader::parse(Der::integer(65537))->int());
    }

    #[Test]
    public function set_of_is_sorted(): void
    {
        $b = Der::integer(2);
        $a = Der::integer(1);
        $set = DerReader::parse(Der::set($b, $a));

        self::assertSame([1, 2], array_map(static fn ($n) => $n->int(), $set->children));
    }

    #[Test]
    public function times_round_trip_in_utc(): void
    {
        $at = new \DateTimeImmutable('2026-10-13 12:34:56+03:00');

        self::assertSame(Der::TAG_UTC_TIME, ord(Der::time($at)[0]));
        self::assertSame('2026-10-13T09:34:56+00:00', DerReader::parse(Der::time($at))->time()->format('c'));
        self::assertSame(Der::TAG_GENERALIZED_TIME, ord(Der::time(new \DateTimeImmutable('2051-01-01'))[0]));
        self::assertSame('2026-10-13T09:34:56+00:00', DerReader::parse(Der::generalizedTime($at))->time()->format('c'));
    }

    #[Test]
    public function nodes_keep_their_exact_encoding(): void
    {
        $inner = Der::sequence(Der::oid('1.2.3'), Der::utf8String('Иванов'));
        $outer = Der::sequence(Der::integer(1), Der::explicit(0, $inner));
        $node = DerReader::parse($outer);

        self::assertSame($inner, $node->child(1)->child(0)->der);
        self::assertSame(0, $node->child(1)->contextTag());
        self::assertSame($inner, $node->contextChild(0)?->child(0)->der);
        self::assertSame('Иванов', $node->child(1)->child(0)->child(1)->content);
    }

    #[Test]
    public function parse_first_ignores_trailing_padding(): void
    {
        $seq = Der::sequence(Der::integer(7));

        self::assertSame($seq, DerReader::parseFirst($seq."\x00\x00\x00")->der);
        $this->expectException(Asn1Exception::class);
        DerReader::parse($seq."\x00\x00");
    }

    #[Test]
    public function truncated_and_indefinite_lengths_are_refused(): void
    {
        foreach (["\x30\x05\x02\x01", "\x30\x80\x00\x00", "\x30"] as $bad) {
            try {
                DerReader::parse($bad);
                self::fail('Accepted '.bin2hex($bad));
            } catch (Asn1Exception) {
                self::addToAssertionCount(1);
            }
        }
    }
}
