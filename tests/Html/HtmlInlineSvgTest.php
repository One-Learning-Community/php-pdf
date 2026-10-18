<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 236: inline <svg> element parsing.
 */
final class HtmlInlineSvgTest extends TestCase
{
    private function parse(string $html): array
    {
        return (new HtmlParser)->parse($html);
    }

    #[Test]
    public function inline_svg_as_block(): void
    {
        $blocks = $this->parse(
            '<svg width="100" height="50"><circle cx="50" cy="25" r="20" fill="red"/></svg>'
        );
        self::assertCount(1, $blocks);
        self::assertInstanceOf(SvgElement::class, $blocks[0]);
    }

    #[Test]
    public function inline_svg_width_height_parsed(): void
    {
        $blocks = $this->parse('<svg width="200" height="150"></svg>');
        $svg = $blocks[0];
        self::assertInstanceOf(SvgElement::class, $svg);
        self::assertEqualsWithDelta(150.0, $svg->widthPt, 0.1); // 200px = 150pt
        self::assertEqualsWithDelta(112.5, $svg->heightPt, 0.1); // 150px = 112.5pt
    }

    #[Test]
    public function inline_svg_default_size_when_missing(): void
    {
        $blocks = $this->parse('<svg></svg>');
        $svg = $blocks[0];
        self::assertSame(100.0, $svg->widthPt);
        self::assertSame(100.0, $svg->heightPt);
    }

    #[Test]
    public function inline_svg_preserves_content(): void
    {
        $blocks = $this->parse(
            '<svg width="100" height="100"><rect width="50" height="50" fill="blue"/></svg>'
        );
        $svg = $blocks[0];
        self::assertStringContainsString('<rect', $svg->svgXml);
        self::assertStringContainsString('fill="blue"', $svg->svgXml);
    }

    #[Test]
    public function inline_svg_within_paragraph(): void
    {
        // An SVG is a block to the engine: inside <p> it splits the paragraph,
        // taking the paragraph's alignment. Kept as a child of the paragraph,
        // it was never drawn.
        $blocks = $this->parse(
            '<p style="text-align:center">Before <svg width="20" height="20"></svg> after</p>'
        );
        self::assertCount(3, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertInstanceOf(SvgElement::class, $blocks[1]);
        self::assertSame(\Dskripchenko\PhpPdf\Style\Alignment::Center, $blocks[1]->alignment);
        self::assertSame(15.0, $blocks[1]->widthPt);
        self::assertInstanceOf(Paragraph::class, $blocks[2]);
    }

    #[Test]
    public function an_svg_in_a_paragraph_is_drawn(): void
    {
        $svg = '<svg width="20" height="20"><rect x="0" y="0" width="5" height="5"/><rect x="10" y="10" width="5" height="5"/></svg>';
        $pdf = \Dskripchenko\PhpPdf\Document::fromHtml('<p>'.$svg.'</p>')->toBytes(new \Dskripchenko\PhpPdf\Layout\Engine(compressStreams: false));

        self::assertGreaterThanOrEqual(2, substr_count($pdf, ' re'));
    }

    #[Test]
    public function css_size_wins_over_the_attributes(): void
    {
        $blocks = $this->parse('<svg width="20" height="20" style="width: 40pt; height: 30pt"></svg>');

        self::assertInstanceOf(SvgElement::class, $blocks[0]);
        self::assertSame(40.0, $blocks[0]->widthPt);
        self::assertSame(30.0, $blocks[0]->heightPt);
    }

    #[Test]
    public function multiple_svgs(): void
    {
        $blocks = $this->parse(
            '<svg width="50" height="50"></svg>
             <svg width="60" height="60"></svg>'
        );
        $svgs = array_filter($blocks, fn ($b) => $b instanceof SvgElement);
        self::assertCount(2, $svgs);
    }

    #[Test]
    public function svg_in_table_cell(): void
    {
        $blocks = $this->parse(
            '<table><tr><td><svg width="30" height="30"></svg></td></tr></table>'
        );
        $cell = $blocks[0]->rows[0]->cells[0];
        // Cell content should contain SVG.
        $hasSvg = false;
        foreach ($cell->children as $b) {
            if ($b instanceof SvgElement) {
                $hasSvg = true;
                break;
            }
            // Or it might be wrapped in a Paragraph when treated as inline-ish.
            if ($b instanceof Paragraph) {
                foreach ($b->children as $inner) {
                    if ($inner instanceof SvgElement) {
                        $hasSvg = true;
                    }
                }
            }
        }
        self::assertTrue($hasSvg, 'SVG should appear в cell content');
    }
}
