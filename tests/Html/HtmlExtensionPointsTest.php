<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Footnote;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\SignatureField;
use Dskripchenko\PhpPdf\Html\ElementHandler;
use Dskripchenko\PhpPdf\Html\HtmlContext;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Tests\Support\PdfGeometry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What an application plugs into the HTML parser: element handlers, the
 * image source, and the paged-media page counters.
 */
final class HtmlExtensionPointsTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private static function text(string $pdf): string
    {
        $words = PdfGeometry::words($pdf) ?? self::markTestSkipped('pdftotext is not available');

        return implode(' ', array_column($words, 'text'));
    }

    #[Test]
    public function page_counters_in_generated_content_number_the_pages(): void
    {
        $body = '';
        for ($i = 1; $i <= 70; $i++) {
            $body .= "<p>Line$i</p>";
        }
        $pdf = Document::fromHtml('<style>.n::after { content: "Page " counter(page) " of " counter(pages) }</style>'
            .'<p class="n"></p>'.$body.'<p class="n"></p>')->toBytes(new Engine(compressStreams: false));

        $text = self::text($pdf);
        self::assertStringContainsString('Page 1 of 2', $text);
        self::assertStringContainsString('Page 2 of 2', $text);
    }

    #[Test]
    public function a_handler_turns_a_block_into_a_signature_field(): void
    {
        $handler = new class implements ElementHandler
        {
            public function handle(\DOMElement $element, HtmlContext $context): ?array
            {
                return $element->hasAttribute('data-signature')
                    ? [new SignatureField($element->getAttribute('data-signature'), widthPt: 200, heightPt: 60)]
                    : null;
            }
        };
        $blocks = (new HtmlParser(elementHandlers: [$handler]))->parse('<p>Before</p><div data-signature="approver">ignored</div><p>After</p>');

        self::assertCount(3, $blocks);
        self::assertInstanceOf(SignatureField::class, $blocks[1]);
        self::assertSame('approver', $blocks[1]->name);
        self::assertInstanceOf(Paragraph::class, $blocks[2], 'Elements the handler leaves are the parser\'s');
    }

    #[Test]
    public function a_handler_turns_an_inline_element_into_a_footnote(): void
    {
        $handler = new class implements ElementHandler
        {
            public function handle(\DOMElement $element, HtmlContext $context): ?array
            {
                return str_contains($element->getAttribute('class'), 'footnote') && $context->inline
                    ? [new Footnote(trim($element->textContent))]
                    : null;
            }
        };
        $blocks = (new HtmlParser(elementHandlers: [$handler]))->parse('<p>Claim<span class="footnote">Clause 3 applies.</span> made.</p>');

        $children = $blocks[0]->children;
        self::assertContainsOnlyInstancesOf(\Dskripchenko\PhpPdf\Element\InlineElement::class, $children);
        self::assertCount(1, array_filter($children, static fn (object $c): bool => $c instanceof Footnote));

        $pdf = Document::fromHtml('<p>Claim<span class="footnote">Clause 3 applies.</span> made.</p>', elementHandlers: [$handler])
            ->toBytes(new Engine(compressStreams: false));
        self::assertStringContainsString('Clause 3 applies.', self::text($pdf));
    }

    #[Test]
    public function the_context_reads_the_content_with_the_elements_style(): void
    {
        // A custom tag whose content the handler shouts.
        $handler = new class implements ElementHandler
        {
            public function handle(\DOMElement $element, HtmlContext $context): ?array
            {
                if ($element->nodeName !== 'shout') {
                    return null;
                }

                return array_map(
                    static fn (object $i): object => $i instanceof Run ? new Run(mb_strtoupper($i->text), $i->style) : $i,
                    $context->inlines($element),
                );
            }
        };
        $blocks = (new HtmlParser(elementHandlers: [$handler]))->parse('<p>say <shout style="font-weight: bold">hello <i>you</i></shout></p>');

        $runs = array_values(array_filter($blocks[0]->children, static fn (object $c): bool => $c instanceof Run && trim($c->text) !== ''));
        self::assertSame(['say', 'HELLO', 'YOU'], array_map(static fn (Run $r): string => trim($r->text), $runs));
        self::assertTrue($runs[1]->style->bold, 'The element\'s own CSS reaches its content');
        self::assertTrue($runs[2]->style->italic);
    }

    #[Test]
    public function the_image_source_resolves_src(): void
    {
        $asked = [];
        $source = static function (string $src) use (&$asked): ?string {
            $asked[] = $src;

            return $src === 'storage://logo.png' ? (string) base64_decode(self::PNG) : null;
        };
        $pdf = Document::fromHtml('<img src="storage://logo.png" style="width: 40pt"><img src="data:image/png;base64,'.self::PNG.'" style="width: 20pt">', imageSource: $source)
            ->toBytes(new Engine(compressStreams: false));

        self::assertSame(['storage://logo.png', 'data:image/png;base64,'.self::PNG], $asked);
        // Both images are drawn: one from the source, one from its data: URL.
        self::assertSame(2, preg_match_all('#/Im\d+ Do#', $pdf));
    }
}
