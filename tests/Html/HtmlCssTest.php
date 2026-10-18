<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Html;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Element\LineBreak;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PaperSize;
use Dskripchenko\PhpPdf\Style\VerticalAlignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HTML with CSS as a browser reads it: `<style>` blocks and the cascade,
 * inheritance, table and cell styles, containers, page breaks.
 */
final class HtmlCssTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @return list<BlockElement> */
    private static function parse(string $html): array
    {
        return (new HtmlParser)->parse($html);
    }

    private static function firstRun(BlockElement $block): Run
    {
        self::assertInstanceOf(Paragraph::class, $block);
        foreach ($block->children as $child) {
            if ($child instanceof Run) {
                return $child;
            }
        }
        self::fail('No run');
    }

    #[Test]
    public function style_blocks_apply_by_selector_and_specificity(): void
    {
        $blocks = self::parse('<style>
            p { color: #111111; font-size: 9pt }
            .lead { color: #222222 }
            div.box > p.lead { color: #333333 }
            #main .lead { font-weight: bold }
            p:first-child { font-style: italic }
            @media screen { p { color: #ff0000 } }
            @media print { .print { text-decoration: underline } }
        </style>
        <div id="main" class="box"><p class="lead print">Lead</p><p>Plain</p></div>');

        $lead = self::firstRun($blocks[0])->style;
        $plain = self::firstRun($blocks[1])->style;
        self::assertSame('333333', $lead->color);   // the most specific rule
        self::assertTrue($lead->bold);
        self::assertTrue($lead->italic);             // :first-child
        self::assertTrue($lead->underline);          // @media print applies
        self::assertSame(9.0, $lead->sizePt);
        self::assertSame('111111', $plain->color);   // @media screen is skipped
        self::assertFalse($plain->italic);
    }

    #[Test]
    public function inline_style_beats_rules_and_important_beats_inline(): void
    {
        $blocks = self::parse('<style>p { color: #111111 !important; font-size: 10pt }</style>
            <p style="color: #222222; font-size: 7pt">Text</p>');

        $style = self::firstRun($blocks[0])->style;
        self::assertSame('111111', $style->color);
        self::assertSame(7.0, $style->sizePt);
    }

    #[Test]
    public function a_shorthand_and_a_later_longhand_combine(): void
    {
        $blocks = self::parse('<style>p { padding: 4pt } p.x { padding-left: 10pt }</style><p class="x">T</p>');

        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertSame(4.0, $blocks[0]->style->paddingTopPt);
        self::assertSame(10.0, $blocks[0]->style->paddingLeftPt);
    }

    #[Test]
    public function text_properties_are_inherited_from_blocks(): void
    {
        $blocks = self::parse('<div style="font-size: 8pt; color: #445566; font-family: Arial; text-align: center; line-height: 10pt">
            <p>Inherited</p>
            <p style="font-weight: bold; font-size: 1.5em">Bigger</p>
        </div>');

        $first = self::firstRun($blocks[0])->style;
        self::assertSame(8.0, $first->sizePt);
        self::assertSame('445566', $first->color);
        self::assertSame('Arial', $first->fontFamily);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertSame(Alignment::Center, $blocks[0]->style->alignment);
        self::assertSame(10.0, $blocks[0]->style->lineHeightPt);

        $second = self::firstRun($blocks[1])->style;
        self::assertSame(12.0, $second->sizePt);   // em of the inherited 8pt
        self::assertTrue($second->bold);
    }

    #[Test]
    public function normal_weight_and_style_switch_off_what_was_inherited(): void
    {
        $blocks = self::parse('<div style="font-weight: bold; font-style: italic; text-decoration: underline">
            <p><span style="font-weight: normal; font-style: normal; text-decoration: none">plain</span></p></div>');

        $style = self::firstRun($blocks[0])->style;
        self::assertFalse($style->bold);
        self::assertFalse($style->italic);
        self::assertFalse($style->underline);
    }

    #[Test]
    public function a_div_with_blocks_keeps_them_as_blocks(): void
    {
        $blocks = self::parse('<div style="margin-top: 6pt; padding: 0 10pt 4pt"><p>One</p><p>Two</p></div>');

        self::assertCount(2, $blocks);
        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertInstanceOf(Paragraph::class, $blocks[1]);
        self::assertSame(6.0, $blocks[0]->style->spaceBeforePt);
        self::assertSame(4.0, $blocks[1]->style->spaceAfterPt);
        self::assertSame(10.0, $blocks[0]->style->indentLeftPt);
        self::assertSame(10.0, $blocks[1]->style->indentRightPt);
    }

    #[Test]
    public function a_div_with_a_background_or_border_becomes_a_box(): void
    {
        $blocks = self::parse('<div style="background: #eeeeee; border: 1pt solid #000; padding: 5pt"><p>One</p><p>Two</p></div>');

        self::assertCount(1, $blocks);
        self::assertInstanceOf(Table::class, $blocks[0]);
        $cell = $blocks[0]->rows[0]->cells[0];
        self::assertCount(2, $cell->children);
        self::assertSame('eeeeee', $cell->style->backgroundColor);
        self::assertSame(5.0, $cell->style->paddingLeftPt);
        self::assertNotNull($cell->style->borders?->top);
    }

    #[Test]
    public function flex_lays_children_out_side_by_side(): void
    {
        $blocks = self::parse('<div style="display: flex; gap: 10pt"><div><p>Left</p></div><div style="width: 30%"><p>Right</p></div></div>');

        self::assertInstanceOf(Table::class, $blocks[0]);
        $cells = $blocks[0]->rows[0]->cells;
        self::assertCount(2, $cells);
        self::assertSame(5.0, $cells[0]->style->paddingRightPt);
        self::assertSame(5.0, $cells[1]->style->paddingLeftPt);
        self::assertSame(30.0, $cells[1]->style->widthPercent);
    }

    #[Test]
    public function cell_styles_come_from_css(): void
    {
        $blocks = self::parse('<style>td { border: 1px solid #000; padding: 2pt 3pt } .fill { background-color: #d5e8f7 }</style>
            <table><tr style="vertical-align: middle"><td class="fill" style="width: 40%; height: 20pt; text-align: right; font-size: 7pt">A</td><td>B</td></tr></table>');

        self::assertInstanceOf(Table::class, $blocks[0]);
        $row = $blocks[0]->rows[0];
        $a = $row->cells[0];
        self::assertSame('d5e8f7', $a->style->backgroundColor);
        self::assertSame(2.0, $a->style->paddingTopPt);
        self::assertSame(3.0, $a->style->paddingLeftPt);
        self::assertSame(40.0, $a->style->widthPercent);
        self::assertSame(VerticalAlignment::Center, $a->style->verticalAlign);
        self::assertSame(BorderStyle::Single, $a->style->borders?->left?->style);
        self::assertSame(20.0, $row->minHeightPt);
        // text-align and font-size reach the cell's text.
        self::assertSame(Alignment::End, $a->children[0] instanceof Paragraph ? $a->children[0]->style->alignment : null);
        self::assertSame(7.0, self::firstRun($a->children[0])->style->sizePt);
    }

    #[Test]
    public function col_widths_become_column_widths(): void
    {
        $blocks = self::parse('<table><colgroup><col style="width: 25%"><col style="width: 75%"></colgroup><tr><td>A</td><td>B</td></tr></table>');

        self::assertInstanceOf(Table::class, $blocks[0]);
        self::assertSame([25.0, 75.0], $blocks[0]->columnWidthsPt);
    }

    #[Test]
    public function first_row_cell_widths_set_the_columns_without_colgroup(): void
    {
        $blocks = self::parse('<table><tr><td width="120">A</td><td>B</td></tr></table>');

        self::assertInstanceOf(Table::class, $blocks[0]);
        self::assertSame(90.0, $blocks[0]->rows[0]->cells[0]->style->widthPt);   // 120px
    }

    #[Test]
    public function table_style_and_attributes(): void
    {
        $blocks = self::parse('<table style="width: 50%; border-collapse: collapse; margin: 4pt auto 8pt; table-layout: fixed" cellpadding="4" border="1"><tr><td>A</td></tr></table>');

        self::assertInstanceOf(Table::class, $blocks[0]);
        $style = $blocks[0]->style;
        self::assertSame(50.0, $style->widthPercent);
        self::assertTrue($style->borderCollapse);
        self::assertSame(Alignment::Center, $style->alignment);
        self::assertSame(4.0, $style->spaceBeforePt);
        self::assertSame(8.0, $style->spaceAfterPt);
        self::assertFalse($style->autoLayout);
        self::assertNotNull($style->defaultCellBorder);
        self::assertSame(3.0, $blocks[0]->rows[0]->cells[0]->style->paddingLeftPt);   // cellpadding 4px
    }

    #[Test]
    public function an_html_table_has_no_margin_unless_asked(): void
    {
        $blocks = self::parse('<table><tr><td>A</td></tr></table>');

        self::assertInstanceOf(Table::class, $blocks[0]);
        self::assertSame(0.0, $blocks[0]->style->spaceAfterPt);
        self::assertTrue($blocks[0]->style->autoLayout);
    }

    #[Test]
    public function thead_rows_repeat_and_rows_can_refuse_to_split(): void
    {
        $blocks = self::parse('<table><thead><tr><th>H</th></tr></thead><tbody><tr style="break-inside: avoid"><td>A</td></tr></tbody></table>');

        self::assertInstanceOf(Table::class, $blocks[0]);
        self::assertTrue($blocks[0]->rows[0]->isHeader);
        self::assertTrue($blocks[0]->rows[1]->cantSplit);
    }

    #[Test]
    public function page_breaks_from_css(): void
    {
        $blocks = self::parse('<style>.page { page-break-after: always } .page:last-child { page-break-after: auto }</style>
            <section class="page"><p>One</p></section><section class="page"><p>Two</p></section>
            <p style="break-before: page">Three</p>');

        $kinds = array_map(static fn (BlockElement $b): string => (new \ReflectionClass($b))->getShortName(), $blocks);
        self::assertSame(['Paragraph', 'PageBreak', 'Paragraph', 'PageBreak', 'Paragraph'], $kinds);
    }

    #[Test]
    public function no_break_leaves_an_empty_first_or_last_page(): void
    {
        $blocks = self::parse('<p style="page-break-before: always">One</p><p style="page-break-after: always">Two</p>');

        self::assertNotInstanceOf(PageBreak::class, $blocks[0]);
        self::assertNotInstanceOf(PageBreak::class, end($blocks));
    }

    #[Test]
    public function at_page_sets_the_page(): void
    {
        $parser = new HtmlParser;
        $parser->parse('<style>@page { size: A5 landscape; margin: 10mm 15mm }</style><p>x</p>');
        $setup = $parser->pageSetup();

        self::assertNotNull($setup);
        self::assertSame(PaperSize::A5, $setup->paperSize);
        self::assertSame(Orientation::Landscape, $setup->orientation);
        self::assertEqualsWithDelta(28.35, $setup->margins->topPt, 0.01);
        self::assertEqualsWithDelta(42.52, $setup->margins->leftPt, 0.01);

        $doc = Document::fromHtml('<style>@page { margin: 0 }</style><p>x</p>');
        self::assertSame(0.0, $doc->section->pageSetup->margins->topPt);
    }

    #[Test]
    public function display_none_and_head_are_not_rendered(): void
    {
        $blocks = self::parse('<!DOCTYPE html><html><head><title>T</title><style>.x{display:none}</style></head>
            <body><p class="x">Hidden</p><p>Shown</p></body></html>');

        self::assertCount(1, $blocks);
        self::assertSame('Shown', self::firstRun($blocks[0])->text);
    }

    #[Test]
    public function white_space_pre_line_keeps_line_breaks(): void
    {
        $blocks = self::parse("<p style=\"white-space: pre-line\">one\n   two</p>");

        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        $children = $blocks[0]->children;
        self::assertInstanceOf(LineBreak::class, $children[1]);
        self::assertSame('two', $children[2] instanceof Run ? $children[2]->text : null);
    }

    #[Test]
    public function pre_keeps_spaces(): void
    {
        $blocks = self::parse("<pre>a  b\n  c</pre>");

        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        $texts = array_map(static fn ($c): string => $c instanceof Run ? $c->text : '|', $blocks[0]->children);
        self::assertSame(["a \u{00A0}b", '|', "\u{00A0}\u{00A0}c"], $texts);
    }

    #[Test]
    public function no_break_spaces_are_not_collapsed(): void
    {
        $blocks = self::parse('<p>a&nbsp;&nbsp;b</p>');

        self::assertSame("a\u{00A0}\u{00A0}b", self::firstRun($blocks[0])->text);
    }

    #[Test]
    public function whitespace_at_block_edges_is_trimmed(): void
    {
        $blocks = self::parse("<p>\n   Text <b>bold</b>  \n</p>");

        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        $texts = array_map(static fn ($c): string => $c instanceof Run ? $c->text : '', $blocks[0]->children);
        self::assertSame(['Text ', 'bold'], $texts);
    }

    #[Test]
    public function a_link_keeps_the_text_style_around_it(): void
    {
        $blocks = self::parse('<style>.c a { color: inherit; text-decoration: none }</style>
            <p style="font-size: 7pt; font-weight: bold; color: #123456">See <a href="https://example.com">site</a></p>
            <p class="c" style="color: #654321"><a href="https://example.com">plain</a></p>');

        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        $link = $blocks[0]->children[1];
        self::assertInstanceOf(Hyperlink::class, $link);
        $style = $link->children[0] instanceof Run ? $link->children[0]->style : null;
        self::assertSame(7.0, $style?->sizePt);
        self::assertTrue($style?->bold);
        self::assertTrue($style?->underline);
        self::assertSame('123456', $style?->color);

        self::assertInstanceOf(Paragraph::class, $blocks[1]);
        $plain = $blocks[1]->children[0];
        self::assertInstanceOf(Hyperlink::class, $plain);
        $plainStyle = $plain->children[0] instanceof Run ? $plain->children[0]->style : null;
        self::assertFalse($plainStyle?->underline);
        self::assertSame('654321', $plainStyle?->color);
    }

    #[Test]
    public function an_image_takes_its_size_and_place_from_css(): void
    {
        $blocks = self::parse('<img src="'.self::PNG.'" style="width: 100%; display: block; margin: 0 auto 4pt">'
            .'<img src="'.self::PNG.'" style="width: 20mm; height: 10mm">');

        self::assertInstanceOf(Image::class, $blocks[0]);
        self::assertSame(100.0, $blocks[0]->widthPercent);
        self::assertSame(Alignment::Center, $blocks[0]->alignment);
        self::assertSame(4.0, $blocks[0]->spaceAfterPt);
        self::assertInstanceOf(Image::class, $blocks[1]);
        self::assertEqualsWithDelta(56.69, $blocks[1]->widthPt, 0.01);
        self::assertEqualsWithDelta(28.35, $blocks[1]->heightPt, 0.01);
    }

    #[Test]
    public function headings_take_css_sizes_or_the_browser_factor(): void
    {
        $blocks = self::parse('<style>body { font-size: 10pt } h2 { font-size: 8pt }</style><body><h1>Big</h1><h2>Small</h2></body>');

        self::assertInstanceOf(Heading::class, $blocks[0]);
        self::assertSame(20.0, $blocks[0]->children[0] instanceof Run ? $blocks[0]->children[0]->style->sizePt : null);
        self::assertInstanceOf(Heading::class, $blocks[1]);
        self::assertSame(8.0, $blocks[1]->children[0] instanceof Run ? $blocks[1]->children[0]->style->sizePt : null);
    }

    #[Test]
    public function line_height_in_lengths_and_em_from_the_current_size(): void
    {
        $blocks = self::parse('<div style="font-size: 10pt"><p style="line-height: 1.5em; padding-left: 2em">x</p></div>');

        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertSame(15.0, $blocks[0]->style->lineHeightPt);
        self::assertSame(20.0, $blocks[0]->style->paddingLeftPt);
    }

    #[Test]
    public function span_background_and_vertical_align(): void
    {
        $blocks = self::parse('<p><span style="background-color: #ffcc00">bg</span><span style="vertical-align: super">2</span></p>');

        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        $runs = array_values(array_filter($blocks[0]->children, static fn ($c): bool => $c instanceof Run));
        self::assertSame('ffcc00', $runs[0]->style->backgroundColor);
        self::assertTrue($runs[1]->style->superscript);
    }

    #[Test]
    public function a_cell_merged_down_keeps_its_rowspan(): void
    {
        $blocks = self::parse('<table><tr><td rowspan="2">A</td><td>B</td></tr><tr><td>C</td></tr></table>');

        self::assertInstanceOf(Table::class, $blocks[0]);
        self::assertSame(2, $blocks[0]->rows[0]->cells[0]->rowSpan);
        self::assertCount(1, $blocks[0]->rows[1]->cells);
    }

    #[Test]
    public function custom_properties_are_inherited_and_substituted(): void
    {
        $blocks = self::parse('<style>
            :root { --brand: #123456; --size: 9pt; --pad: 3pt }
            .box { --brand: #654321 }
            p { color: var(--brand); font-size: var(--size) }
            .padded { padding: var(--pad) 6pt }
            .fallback { color: var(--missing, #0000aa) }
            .broken { color: var(--missing); font-size: 8pt }
            .chain { --a: var(--brand); color: var(--a) }
        </style>
        <p>Root</p><div class="box"><p>Box</p></div><p class="padded">Pad</p>
        <p class="fallback">F</p><p class="broken">B</p><p class="chain">C</p>');

        self::assertSame('123456', self::firstRun($blocks[0])->style->color);
        self::assertSame(9.0, self::firstRun($blocks[0])->style->sizePt);
        self::assertSame('654321', self::firstRun($blocks[1])->style->color);   // the nearer declaration wins
        self::assertInstanceOf(Paragraph::class, $blocks[2]);
        self::assertSame(3.0, $blocks[2]->style->paddingTopPt);                  // a shorthand expanded after substitution
        self::assertSame(6.0, $blocks[2]->style->paddingLeftPt);
        self::assertSame('0000aa', self::firstRun($blocks[3])->style->color);
        self::assertNotSame('0000aa', self::firstRun($blocks[4])->style->color); // unresolved: the property is dropped
        self::assertSame(8.0, self::firstRun($blocks[4])->style->sizePt);
        self::assertSame('123456', self::firstRun($blocks[5])->style->color);
    }

    #[Test]
    public function a_variable_cycle_drops_the_property(): void
    {
        $blocks = self::parse('<style>p { --a: var(--b); --b: var(--a); color: var(--a); font-size: 7pt }</style><p>T</p>');

        self::assertSame(7.0, self::firstRun($blocks[0])->style->sizePt);
    }
}
