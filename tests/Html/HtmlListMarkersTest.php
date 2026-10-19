<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\ListNode;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Style\ListFormat;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * List numbering from HTML and CSS: `start`, `type` / `list-style-type`,
 * `<li value>`, `list-style-type: none`, and `::marker` labels with the
 * `list-item` counter — the way Word's multilevel numbering (`3.1.`)
 * arrives from an import.
 */
final class HtmlListMarkersTest extends TestCase
{
    private static function text(string $html): string
    {
        $words = PdfGeometry::words(Document::fromHtml($html)->toBytes(new Engine(compressStreams: false)))
            ?? self::markTestSkipped('pdftotext is not available');

        return implode(' ', array_column($words, 'text'));
    }

    #[Test]
    public function start_type_and_value_number_the_items(): void
    {
        $list = (new HtmlParser)->parse('<ol start="3" type="A"><li>a</li><li value="7">b</li><li>c</li></ol>')[0];

        self::assertInstanceOf(ListNode::class, $list);
        self::assertSame(ListFormat::UpperLetter, $list->format);
        self::assertSame(3, $list->startAt);
        self::assertSame('C. a G. b H. c', self::text('<ol start="3" type="A"><li>a</li><li value="7">b</li><li>c</li></ol>'));
        self::assertSame('ii. x', self::text('<ol style="list-style-type: lower-roman" start="2"><li>x</li></ol>'));
    }

    #[Test]
    public function marker_rules_label_the_items_with_the_list_item_counter(): void
    {
        $html = '<style>.l1::marker { content: counters(list-item, ".") ". " } .l0::marker { content: "4." counter(list-item) ". " }</style>'
            .'<ol start="3"><li style="list-style-type:none"><ol><li class="l1">first</li><li class="l1">second</li></ol></li></ol>'
            .'<ol><li class="l0">prefixed</li></ol>';

        self::assertSame('3.1. first 3.2. second 4.1. prefixed', self::text($html));
    }

    #[Test]
    public function an_item_without_a_label_and_content_draws_only_its_nested_list(): void
    {
        $list = (new HtmlParser)->parse('<ol><li style="list-style-type:none"><ol><li>inner</li></ol></li></ol>')[0];

        self::assertInstanceOf(ListNode::class, $list);
        self::assertSame('', $list->items[0]->marker);
        self::assertSame('1. inner', self::text('<ol><li style="list-style-type:none"><ol><li>inner</li></ol></li></ol>'));
    }

    #[Test]
    public function list_format_numbers(): void
    {
        self::assertSame('xiv', ListFormat::LowerRoman->number(14));
        self::assertSame('AB', ListFormat::UpperLetter->number(28));
        self::assertSame('c', ListFormat::LowerLetter->number(3));
    }
}
