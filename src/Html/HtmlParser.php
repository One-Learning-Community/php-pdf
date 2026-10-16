<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Html;

use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\HorizontalRule;
use Dskripchenko\PhpPdf\Element\Hyperlink;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Element\InlineElement;
use Dskripchenko\PhpPdf\Element\LineBreak;
use Dskripchenko\PhpPdf\Element\ListItem;
use Dskripchenko\PhpPdf\Element\ListNode;
use Dskripchenko\PhpPdf\Element\PageBreak;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Html\Css\StyleSheet;
use Dskripchenko\PhpPdf\Html\Css\Values;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\CellStyle;
use Dskripchenko\PhpPdf\Style\ListFormat;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use Dskripchenko\PhpPdf\Style\RunStyle;
use Dskripchenko\PhpPdf\Style\TableStyle;
use Dskripchenko\PhpPdf\Style\VerticalAlignment;

/**
 * HTML5 → AST parser. Backs `Document::fromHtml()`.
 *
 * Supports:
 *  - Block tags: p, div, section, article, h1-h6, hr, ul/ol/li,
 *    table/tr/td/th (incl. thead/tbody/tfoot wrappers, caption,
 *    colgroup/col), blockquote, pre, header, footer, nav, aside, main,
 *    figure, figcaption, address, details/summary, dl/dt/dd, center,
 *    html/body
 *  - Inline tags: span, b/strong, i/em, u, s/strike/del, sup, sub, br,
 *    wbr, img, picture, a, font, svg, code, kbd, samp, tt, var, mark,
 *    small, big, ins, cite, dfn, q, abbr
 *  - CSS from `<style>` blocks and `style` attributes, with the cascade:
 *    type, `.class`, `#id`, `*`, attribute selectors, `:first-child`,
 *    `:last-child`, `:nth-child()`, `:not()`, descendant / `>` / `+` / `~`
 *    combinators, specificity, `!important`; `@media print` applies,
 *    `@media screen` is skipped; `@page` gives the page (see pageSetup()).
 *  - Inherited, as in CSS: color, font-size, font-family, font-weight,
 *    font-style, text-decoration, text-transform, letter-spacing,
 *    text-align, line-height, text-indent, white-space — from body, div,
 *    td or any other element down to the text.
 *  - Text: color, background-color, font-size (pt/px/mm/em/rem/%/keywords),
 *    font-family, font-weight, font-style, text-decoration,
 *    text-transform, letter-spacing, vertical-align: super/sub,
 *    white-space (normal/nowrap/pre/pre-wrap/pre-line)
 *  - Blocks: text-align, margin, padding, border (shorthand, per-side,
 *    width/style/color), background-color, line-height (multiplier, %,
 *    length), text-indent, page-break-before/after (break-before/after),
 *    display: none / block / flex
 *  - Containers: a div (section, …) with block children keeps them as
 *    blocks; its margin and padding space and indent them, and a
 *    background or border puts them in a box. `display: flex` lays its
 *    children out side by side.
 *  - Tables: width, table-layout, border-collapse, border-spacing, border,
 *    margin (auto centres); `border`, `cellpadding`, `cellspacing`, `width`,
 *    `align` attributes; `<col>` widths; cells: border, background-color,
 *    padding, vertical-align, width, height, colspan, rowspan; rows:
 *    height, break-inside: avoid; thead rows repeat on every page.
 *  - Images: width/height (attributes or CSS, `%` of the line),
 *    display: block with margin: auto centres.
 *
 * Not supported: external stylesheets, `position`, floats, grid,
 * `::before`/`::after`, `:hover` and other dynamic pseudo-classes, forms,
 * JavaScript.
 */
final class HtmlParser
{
    /** Elements that are never rendered. */
    private const SKIP = ['head', 'title', 'script', 'style', 'meta', 'link', 'noscript', 'template', 'base'];


    /**
     * Inline style stack — pushed on entering an element, popped on exit.
     * Styles cascade down the tree.
     *
     * @var list<RunStyle>
     */
    private array $styleStack = [];

    /** Href of the currently-open `<a>` context, or null. */
    private ?string $linkHref = null;

    /**
     * Text-transform stack: 'none' / 'uppercase' / 'lowercase' /
     * 'capitalize'. Applied to text content as it is collected into Runs.
     *
     * @var list<string>
     */
    private array $textTransformStack = [];

    /**
     * Inherited block properties, one entry per open element.
     *
     * @var list<array{align: Alignment, lineHeightMult: ?float, lineHeightPt: ?float, indent: float, whiteSpace: string, vAlign: ?VerticalAlignment}>
     */
    private array $blockStack = [];

    private StyleSheet $css;

    /**
     * @param  float  $baseFontSizePt  The font size `em`, `rem` and `%` refer to
     *                                 outside any element that sets one — the
     *                                 engine's default size.
     */
    public function __construct(private readonly float $baseFontSizePt = 12.0)
    {
        $this->css = new StyleSheet;
    }

    /**
     * Parse HTML string → list of BlockElements.
     *
     * @return list<BlockElement>
     */
    public function parse(string $html): array
    {
        $this->css = new StyleSheet;
        if (trim($html) === '') {
            return [];
        }
        $dom = $this->loadDom($html);
        $root = $dom->getElementById('_phppdf_root');
        if (! $root instanceof \DOMNode) {
            $root = $dom->documentElement;
        }
        if (! $root instanceof \DOMNode) {
            return [];
        }
        foreach (iterator_to_array($dom->getElementsByTagName('style')) as $style) {
            $media = strtolower($style->getAttribute('media'));
            if ($media === '' || str_contains($media, 'print') || str_contains($media, 'all')) {
                $this->css->add($style->textContent);
            }
        }
        $this->styleStack = [new RunStyle];
        $this->textTransformStack = ['none'];
        $this->blockStack = [[
            'align' => Alignment::Start, 'lineHeightMult' => null, 'lineHeightPt' => null,
            'indent' => 0.0, 'whiteSpace' => 'normal', 'vAlign' => null,
        ]];

        $blocks = $this->walkBlocks($root);
        // A break after the last block would leave an empty page.
        while ($blocks !== [] && end($blocks) instanceof PageBreak) {
            array_pop($blocks);
        }
        while ($blocks !== [] && $blocks[0] instanceof PageBreak) {
            array_shift($blocks);
        }

        return $blocks;
    }

    /**
     * The page from the last parsed document's `@page` rule — size and
     * margins — or null when it has none.
     */
    public function pageSetup(): ?PageSetup
    {
        return $this->css->pageSetup();
    }

    /**
     * Convenience: parse HTML, wrap in default Section, return Document.
     */
    public static function fromHtml(string $html): \Dskripchenko\PhpPdf\Document
    {
        $parser = new self;
        $blocks = $parser->parse($html);
        $setup = $parser->pageSetup();

        return new \Dskripchenko\PhpPdf\Document(
            $setup !== null ? new \Dskripchenko\PhpPdf\Section($blocks, $setup) : new \Dskripchenko\PhpPdf\Section($blocks),
        );
    }

    private function loadDom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument;
        // libxml errors swallowed (HTML imperfect — non-strict parse).
        $prevErrors = libxml_use_internal_errors(true);
        try {
            // Wrap input in a synthetic root so that top-level traversal
            // consistently walks through childNodes even when the input is
            // a single element or bare text. A full document's doctype and
            // html/head/body tags are dropped first: inside the wrapper
            // libxml would scatter them.
            $html = (string) preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
            // UTF-8 BOM prefix helps libxml detect encoding.
            $dom->loadHTML(
                "\xEF\xBB\xBF<div id=\"_phppdf_root\">$html</div>",
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevErrors);
        }

        return $dom;
    }

    // ── The cascade ──────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function decl(\DOMElement $node): array
    {
        return $this->css->declarationsFor($node);
    }

    private function isHidden(\DOMNode $node): bool
    {
        if (! $node instanceof \DOMElement) {
            return false;
        }
        if (in_array(strtolower($node->nodeName), self::SKIP, true)) {
            return true;
        }

        return strtolower(trim($this->decl($node)['display'] ?? '')) === 'none';
    }

    /**
     * Opens an element: its text style, text-transform and inherited block
     * properties go on the stacks for its content.
     */
    private function enter(\DOMElement $node, RunStyle $base, bool $isBlock): void
    {
        $this->styleStack[] = $this->applyInlineCssStyle($base, $node, $isBlock);
        $this->textTransformStack[] = $this->cssTextTransform($node) ?? $this->currentTransform();
        $this->blockStack[] = $this->inheritBlock($node);
    }

    private function leave(): void
    {
        array_pop($this->styleStack);
        array_pop($this->textTransformStack);
        array_pop($this->blockStack);
    }

    /**
     * @return array{align: Alignment, lineHeightMult: ?float, lineHeightPt: ?float, indent: float, whiteSpace: string, vAlign: ?VerticalAlignment}
     */
    private function block(): array
    {
        return end($this->blockStack) ?: [
            'align' => Alignment::Start, 'lineHeightMult' => null, 'lineHeightPt' => null,
            'indent' => 0.0, 'whiteSpace' => 'normal', 'vAlign' => null,
        ];
    }

    /**
     * The inherited block properties of an element: its parent's, changed by
     * its own declarations.
     *
     * @return array{align: Alignment, lineHeightMult: ?float, lineHeightPt: ?float, indent: float, whiteSpace: string, vAlign: ?VerticalAlignment}
     */
    private function inheritBlock(\DOMElement $node): array
    {
        $b = $this->block();
        $decl = $this->decl($node);
        $tag = strtolower($node->nodeName);

        if (isset($decl['text-align'])) {
            $b['align'] = $this->alignment($decl['text-align']) ?? $b['align'];
        } elseif ($tag === 'center' || strtolower($node->getAttribute('align')) === 'center') {
            $b['align'] = Alignment::Center;
        } elseif (in_array(strtolower($node->getAttribute('align')), ['right', 'left', 'justify'], true)) {
            $b['align'] = $this->alignment($node->getAttribute('align')) ?? $b['align'];
        }
        if (isset($decl['line-height'])) {
            $lh = strtolower(trim($decl['line-height']));
            if ($lh === 'normal') {
                $b['lineHeightMult'] = null;
                $b['lineHeightPt'] = null;
            } elseif (preg_match('/^[\d.]+$/', $lh) === 1) {
                $b['lineHeightMult'] = (float) $lh; // unitless = multiplier
                $b['lineHeightPt'] = null;
            } elseif (preg_match('/^([\d.]+)%$/', $lh, $m) === 1) {
                $b['lineHeightMult'] = (float) $m[1] / 100.0;
                $b['lineHeightPt'] = null;
            } elseif (($pt = $this->length($lh)) !== null) {
                $b['lineHeightPt'] = $pt;
                $b['lineHeightMult'] = null;
            }
        }
        if (isset($decl['text-indent'])) {
            $b['indent'] = $this->length($decl['text-indent']) ?? $b['indent'];
        }
        if (isset($decl['white-space'])) {
            $b['whiteSpace'] = strtolower(trim($decl['white-space']));
        } elseif ($tag === 'pre' || $tag === 'textarea') {
            $b['whiteSpace'] = 'pre';
        }
        if ($tag === 'table') {
            $b['vAlign'] = null; // vertical-align does not reach into a nested table
        }
        $va = $decl['vertical-align'] ?? $node->getAttribute('valign');
        if ($va !== '' && in_array($tag, ['tbody', 'thead', 'tfoot', 'tr', 'td', 'th'], true)) {
            $b['vAlign'] = match (strtolower(trim($va))) {
                'top', 'text-top' => VerticalAlignment::Top,
                'middle', 'center' => VerticalAlignment::Center,
                'bottom', 'text-bottom' => VerticalAlignment::Bottom,
                default => $b['vAlign'],
            };
        }

        return $b;
    }

    private function alignment(string $css): ?Alignment
    {
        return match (strtolower(trim($css))) {
            'left', 'start' => Alignment::Start,
            'right', 'end' => Alignment::End,
            'center' => Alignment::Center,
            'justify' => Alignment::Both,
            default => null,
        };
    }

    /** The font size `em` refers to here. */
    private function em(): float
    {
        return $this->currentStyle()->sizePt ?? $this->baseFontSizePt;
    }

    private function length(string $css, ?float $percentOf = null): ?float
    {
        return Values::length($css, $this->em(), $this->baseFontSizePt, $percentOf);
    }

    // ── Blocks ───────────────────────────────────────────────────────────

    /**
     * @return list<BlockElement>
     */
    private function walkBlocks(\DOMNode $parent): array
    {
        $blocks = [];
        $pendingInlines = [];

        foreach ($parent->childNodes as $node) {
            if ($this->isHidden($node)) {
                continue;
            }
            if ($node instanceof \DOMElement && $this->isBlockTag($node)) {
                if ($pendingInlines !== []) {
                    $this->pushParagraph($blocks, $pendingInlines);
                    $pendingInlines = [];
                }
                foreach ($this->blockElements($node) as $b) {
                    $blocks[] = $b;
                }
            } elseif ($node instanceof \DOMElement && $this->isBlockImage($node)) {
                // <img> or <svg> with explicit block-context. Wrap pending inlines +
                // emit as block.
                if ($pendingInlines !== []) {
                    $this->pushParagraph($blocks, $pendingInlines);
                    $pendingInlines = [];
                }
                $tag = strtolower($node->nodeName);
                if ($tag === 'svg') {
                    $svg = $this->parseInlineSvg($node);
                    if ($svg !== null) {
                        $blocks[] = $svg;
                    }
                } else {
                    $img = $this->parseImage($node, block: true);
                    if ($img !== null) {
                        $blocks[] = $img;
                    }
                }
            } else {
                $inlines = $this->walkInlines($node);
                $pendingInlines = array_merge($pendingInlines, $inlines);
            }
        }

        if ($pendingInlines !== []) {
            $this->pushParagraph($blocks, $pendingInlines);
        }

        // Breaks that meet (after one block, before the next) are one break.
        $out = [];
        foreach ($blocks as $b) {
            if ($b instanceof PageBreak && $out !== [] && end($out) instanceof PageBreak) {
                continue;
            }
            $out[] = $b;
        }

        return $out;
    }

    /**
     * Inline content between blocks becomes a paragraph of the enclosing
     * element's inherited style — unless it is only whitespace.
     *
     * @param  list<BlockElement>  $blocks
     * @param  list<InlineElement>  $inlines
     */
    private function pushParagraph(array &$blocks, array $inlines): void
    {
        $inlines = $this->trimInlines($inlines);
        if ($inlines === []) {
            return;
        }
        $blocks[] = new Paragraph($inlines, $this->inheritedParagraphStyle(), defaultRunStyle: $this->currentStyle());
    }

    private function inheritedParagraphStyle(): ParagraphStyle
    {
        $b = $this->block();

        return new ParagraphStyle(
            alignment: $b['align'],
            indentFirstLinePt: $b['indent'],
            lineHeightMult: $b['lineHeightMult'],
            lineHeightPt: $b['lineHeightPt'],
        );
    }

    /**
     * Detect if container has any block-level child.
     */
    private function hasBlockChild(\DOMNode $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && ! $this->isHidden($child) && $this->isBlockTag($child)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockTag(\DOMNode $node): bool
    {
        if (! $node instanceof \DOMElement) {
            return false;
        }
        $tag = strtolower($node->nodeName);
        if (in_array($tag, [
            'p', 'div', 'section', 'article',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'hr', 'ul', 'ol', 'dl', 'table',
            'blockquote', 'pre',
            // HTML5 semantic block groupings.
            'header', 'footer', 'nav', 'aside', 'main',
            'figure', 'figcaption',
            // Legacy block.
            'center',
            // address (semantic block), details (collapsible).
            'address', 'details',
            // A whole document.
            'html', 'body', 'form', 'fieldset',
        ], true)) {
            return true;
        }
        if (in_array($tag, ['img', 'svg', 'br', 'picture'], true)) {
            return false;
        }
        $display = strtolower(trim($this->decl($node)['display'] ?? ''));

        return in_array($display, ['block', 'flex', 'list-item', 'table'], true);
    }

    private function isBlockImage(\DOMNode $node): bool
    {
        if (! $node instanceof \DOMElement) {
            return false;
        }
        $tag = strtolower($node->nodeName);

        return $tag === 'img' || $tag === 'svg';
    }

    /**
     * A block element with its page breaks, its styles opened for its
     * content.
     *
     * @return list<BlockElement>
     */
    private function blockElements(\DOMElement $node): array
    {
        $decl = $this->decl($node);
        $out = [];
        if ($this->isPageBreak($decl['page-break-before'] ?? null)) {
            $out[] = new PageBreak;
        }

        $this->enter($node, $this->headingBase($node, $decl), true);
        try {
            foreach ($this->buildBlock($node, $decl) as $b) {
                $out[] = $b;
            }
        } finally {
            $this->leave();
        }

        if ($this->isPageBreak($decl['page-break-after'] ?? null)) {
            $out[] = new PageBreak;
        }

        return $out;
    }

    /**
     * A heading under a known font size is that size times the browser's
     * factor (h1 = 2em … h6 = 0.67em); with none known the engine's heading
     * sizes apply.
     *
     * @param  array<string, string>  $decl
     */
    private function headingBase(\DOMElement $node, array $decl): RunStyle
    {
        $current = $this->currentStyle();
        $factor = [
            'h1' => 2.0, 'h2' => 1.5, 'h3' => 1.17, 'h4' => 1.0, 'h5' => 0.83, 'h6' => 0.67,
        ][strtolower($node->nodeName)] ?? null;
        if ($factor === null || isset($decl['font-size']) || $current->sizePt === null) {
            return $current;
        }

        return $current->withSizePt($current->sizePt * $factor);
    }

    private function isPageBreak(?string $value): bool
    {
        return $value !== null && in_array(strtolower(trim($value)), ['always', 'page', 'left', 'right', 'recto', 'verso'], true);
    }

    /**
     * @param  array<string, string>  $decl
     * @return list<BlockElement>
     */
    private function buildBlock(\DOMElement $node, array $decl): array
    {
        $tag = strtolower($node->nodeName);
        $display = strtolower(trim($decl['display'] ?? ''));

        if ($display === 'flex' && $this->elementChildren($node) !== []) {
            return [$this->flexRow($node, $decl)];
        }

        return match (true) {
            $tag === 'dl' => $this->parseDefinitionListItems($node),
            $tag === 'table' => $this->tableWithCaption($node),
            $tag === 'details' => $this->parseDetails($node),
            in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) => [$this->parseHeading($node, (int) $tag[1])],
            $tag === 'hr' => [new HorizontalRule],
            $tag === 'ul' => [$this->parseList($node, ordered: false)],
            $tag === 'ol' => [$this->parseList($node, ordered: true)],
            // <address> — italic paragraph (HTML5 spec semantics).
            $tag === 'address' => [$this->parseAddressBlock($node)],
            $this->hasBlockChild($node) => $this->container($node, $decl),
            default => $this->paragraphOrNothing($node),
        };
    }

    /**
     * @return list<BlockElement>
     */
    private function paragraphOrNothing(\DOMElement $node): array
    {
        $p = $this->parseParagraph($node);
        $box = $p->style->backgroundColor !== null || $p->style->borders !== null
            || $p->style->spaceBeforePt > 0 || $p->style->spaceAfterPt > 0
            || $p->style->paddingTopPt > 0 || $p->style->paddingBottomPt > 0;
        // An element with nothing in it and no box of its own leaves no trace
        // (an empty <div></div>), as in a browser.
        if ($p->children === [] && ! $box && ! in_array(strtolower($node->nodeName), ['p', 'pre'], true)) {
            return [];
        }

        return [$p];
    }

    /**
     * @return list<\DOMElement>
     */
    private function elementChildren(\DOMElement $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && ! $this->isHidden($child)) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * A block holding blocks. With a background or a border it becomes a box
     * (a one-cell table, which can still break between its blocks); without,
     * its margin and padding space and indent its children.
     *
     * @param  array<string, string>  $decl
     * @return list<BlockElement>
     */
    private function container(\DOMElement $node, array $decl): array
    {
        $blocks = $this->walkBlocks($node);
        [$mt, $mr, $mb, $ml] = $this->sides($decl, 'margin');
        [$pt, $pr, $pb, $pl] = $this->sides($decl, 'padding');
        $borders = $this->borders($decl);
        $bg = $this->backgroundColor($decl);
        // The background of html and body is the page itself, not a box.
        if (in_array(strtolower($node->nodeName), ['html', 'body'], true)) {
            $bg = null;
        }

        if ($bg !== null || $borders !== null) {
            if ($blocks === []) {
                $blocks = [new Paragraph([])];
            }
            [$widthPt, $widthPercent] = $this->widthOf($decl['width'] ?? null);
            $centered = strtolower(trim($decl['margin-left'] ?? '')) === 'auto' && strtolower(trim($decl['margin-right'] ?? '')) === 'auto';

            return [new Table(
                [new Row([new Cell($blocks, new CellStyle(
                    paddingTopPt: $pt, paddingRightPt: $pr, paddingBottomPt: $pb, paddingLeftPt: $pl,
                    backgroundColor: $bg, borders: $borders,
                ))])],
                new TableStyle(
                    widthPt: $widthPt,
                    widthPercent: $widthPercent,
                    alignment: $centered ? Alignment::Center : Alignment::Start,
                    spaceBeforePt: $mt,
                    spaceAfterPt: $mb,
                    borderCollapse: true,
                    indentLeftPt: $ml,
                    indentRightPt: $mr,
                ),
            )];
        }

        if ($blocks === []) {
            return [];
        }
        $before = $mt + $pt;
        $after = $mb + $pb;
        $left = $ml + $pl;
        $right = $mr + $pr;
        if ($left > 0 || $right > 0) {
            $blocks = array_map(fn (BlockElement $b): BlockElement => $this->indentBy($b, $left, $right), $blocks);
        }
        if ($before > 0) {
            $blocks[0] = $this->addSpace($blocks[0], $before, 0.0);
        }
        if ($after > 0) {
            $last = count($blocks) - 1;
            $blocks[$last] = $this->addSpace($blocks[$last], 0.0, $after);
        }

        return $blocks;
    }

    /**
     * `display: flex` — the children side by side in a one-row table; each
     * keeps its own box, `gap` goes between them, `width`/`flex-basis` set a
     * column, the rest share equally (`flex: 1`).
     *
     * @param  array<string, string>  $decl
     */
    private function flexRow(\DOMElement $node, array $decl): Table
    {
        $gap = $this->length($decl['column-gap'] ?? ($decl['gap'] ?? '')) ?? 0.0;
        $vAlign = match (strtolower(trim($decl['align-items'] ?? ''))) {
            'center' => VerticalAlignment::Center,
            'flex-end', 'end' => VerticalAlignment::Bottom,
            default => VerticalAlignment::Top,
        };
        $children = $this->elementChildren($node);
        $count = count($children);
        $cells = [];
        foreach ($children as $i => $child) {
            $this->enter($child, $this->currentStyle(), true);
            try {
                $childDecl = $this->decl($child);
                $tag = strtolower($child->nodeName);
                if ($tag === 'img' || $tag === 'svg') {
                    $image = $tag === 'img' ? $this->parseImage($child, block: true) : $this->parseInlineSvg($child);
                    $blocks = $image !== null ? [$image] : [];
                } else {
                    $blocks = $this->hasBlockChild($child) ? $this->walkBlocks($child) : [$this->parseParagraph($child)];
                }
                [$pt, $pr, $pb, $pl] = $this->sides($childDecl, 'padding');
                [$widthPt, $widthPercent] = $this->widthOf($childDecl['width'] ?? ($childDecl['flex-basis'] ?? null));
                $cells[] = new Cell($blocks === [] ? [new Paragraph([])] : $blocks, new CellStyle(
                    widthPt: $widthPt,
                    widthPercent: $widthPercent,
                    paddingTopPt: $pt,
                    paddingRightPt: $pr + ($i < $count - 1 ? $gap / 2 : 0.0),
                    paddingBottomPt: $pb,
                    paddingLeftPt: $pl + ($i > 0 ? $gap / 2 : 0.0),
                    verticalAlign: $vAlign,
                    backgroundColor: $this->backgroundColor($childDecl),
                    borders: $this->borders($childDecl),
                ));
            } finally {
                $this->leave();
            }
        }
        [$mt, $mr, $mb, $ml] = $this->sides($decl, 'margin');
        [$pt, $pr, $pb, $pl] = $this->sides($decl, 'padding');

        return new Table([new Row($cells)], new TableStyle(
            spaceBeforePt: $mt + $pt,
            spaceAfterPt: $mb + $pb,
            borderCollapse: true,
            indentLeftPt: $ml + $pl,
            indentRightPt: $mr + $pr,
        ));
    }

    private function indentBy(BlockElement $block, float $left, float $right): BlockElement
    {
        return match (true) {
            $block instanceof Paragraph => new Paragraph($block->children, $this->styleCopy($block->style, indentLeft: $block->style->indentLeftPt + $left, indentRight: $block->style->indentRightPt + $right), $block->headingLevel, $block->defaultRunStyle),
            $block instanceof Heading => new Heading($block->level, $block->children, $this->styleCopy($block->style ?? new ParagraphStyle, indentLeft: ($block->style ?? new ParagraphStyle)->indentLeftPt + $left, indentRight: ($block->style ?? new ParagraphStyle)->indentRightPt + $right), $block->anchor),
            $block instanceof Table => new Table($block->rows, $block->style->copy(indentLeftPt: $block->style->indentLeftPt + $left, indentRightPt: $block->style->indentRightPt + $right), $block->columnWidthsPt, $block->caption),
            default => $block,
        };
    }

    private function addSpace(BlockElement $block, float $before, float $after): BlockElement
    {
        return match (true) {
            $block instanceof Paragraph => new Paragraph($block->children, $this->styleCopy($block->style, spaceBefore: $block->style->spaceBeforePt + $before, spaceAfter: $block->style->spaceAfterPt + $after), $block->headingLevel, $block->defaultRunStyle),
            $block instanceof Heading => new Heading($block->level, $block->children, $this->styleCopy($block->style ?? new ParagraphStyle, spaceBefore: ($block->style ?? new ParagraphStyle)->spaceBeforePt + $before, spaceAfter: ($block->style ?? new ParagraphStyle)->spaceAfterPt + $after), $block->anchor),
            $block instanceof Table => new Table($block->rows, $block->style->copy(spaceBeforePt: $block->style->spaceBeforePt + $before, spaceAfterPt: $block->style->spaceAfterPt + $after), $block->columnWidthsPt, $block->caption),
            $block instanceof ListNode => new ListNode($block->items, $block->format, $block->startAt, $block->spaceBeforePt + $before, $block->spaceAfterPt + $after),
            $block instanceof Image => new Image($block->source, $block->widthPt, $block->heightPt, $block->alignment, $block->spaceBeforePt + $before, $block->spaceAfterPt + $after, $block->altText, $block->outOfFlow, $block->widthPercent),
            default => $block,
        };
    }

    private function styleCopy(
        ParagraphStyle $s,
        ?float $indentLeft = null,
        ?float $indentRight = null,
        ?float $spaceBefore = null,
        ?float $spaceAfter = null,
    ): ParagraphStyle {
        return new ParagraphStyle(
            alignment: $s->alignment,
            spaceBeforePt: $spaceBefore ?? $s->spaceBeforePt,
            spaceAfterPt: $spaceAfter ?? $s->spaceAfterPt,
            indentLeftPt: $indentLeft ?? $s->indentLeftPt,
            indentRightPt: $indentRight ?? $s->indentRightPt,
            indentFirstLinePt: $s->indentFirstLinePt,
            lineHeightMult: $s->lineHeightMult,
            lineHeightPt: $s->lineHeightPt,
            pageBreakBefore: $s->pageBreakBefore,
            keepWithNext: $s->keepWithNext,
            borders: $s->borders,
            paddingTopPt: $s->paddingTopPt,
            paddingRightPt: $s->paddingRightPt,
            paddingBottomPt: $s->paddingBottomPt,
            paddingLeftPt: $s->paddingLeftPt,
            backgroundColor: $s->backgroundColor,
        );
    }

    /**
     * <address> — italic paragraph for contact info.
     */
    private function parseAddressBlock(\DOMElement $node): Paragraph
    {
        $p = $this->parseParagraph($node);
        $italicized = array_map(
            fn ($i) => $i instanceof Run
                ? new Run($i->text, $i->style->withItalic())
                : $i,
            $p->children,
        );

        return new Paragraph($italicized, $p->style, defaultRunStyle: $p->defaultRunStyle);
    }

    /**
     * <details> with optional <summary> — render summary as bold
     * paragraph, then content as indented block sequence.
     *
     * Note: collapsibility is not reproducible in static PDF — output always
     * shows fully expanded content.
     *
     * @return list<BlockElement>
     */
    private function parseDetails(\DOMElement $node): array
    {
        $blocks = [];
        $summaryFound = false;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'summary') {
                $summaryFound = true;
                $inlines = [];
                foreach ($child->childNodes as $inner) {
                    $inlines = array_merge($inlines, $this->walkInlines($inner));
                }
                $bolded = array_map(
                    fn ($i) => $i instanceof Run
                        ? new Run($i->text, $i->style->withBold())
                        : $i,
                    $this->trimInlines($inlines),
                );
                $blocks[] = new Paragraph(
                    $bolded,
                    style: new ParagraphStyle(
                        spaceBeforePt: 6.0,
                        spaceAfterPt: 4.0,
                    ),
                );
            } elseif ($child instanceof \DOMElement && ! $this->isHidden($child) && $this->isBlockTag($child)) {
                foreach ($this->blockElements($child) as $childBlock) {
                    $blocks[] = $this->indentBlock($childBlock, 16.0);
                }
            }
            // Inline text children of details — skipped for now
            // (details usually contains block content).
        }
        if (! $summaryFound) {
            // Fallback "Details" prefix.
            $blocks = array_merge(
                [new Paragraph([new Run('Details', new RunStyle(bold: true))])],
                $blocks,
            );
        }

        return $blocks;
    }

    /**
     * Add left indent to an existing paragraph block (for details content).
     */
    private function indentBlock(BlockElement $block, float $indentPt): BlockElement
    {
        if (! $block instanceof Paragraph) {
            return $block; // Heading/Table — no indent helper
        }
        $newStyle = $block->style->copy(
            indentLeftPt: $block->style->indentLeftPt + $indentPt,
        );

        return new Paragraph($block->children, $newStyle, $block->headingLevel, $block->defaultRunStyle);
    }

    /**
     * Definition list — DT (term) bold paragraphs, DD (definition)
     * indented paragraphs. Returns a flattened sequence for inclusion in
     * the parent's block list.
     *
     * @return list<BlockElement>
     */
    private function parseDefinitionListItems(\DOMElement $node): array
    {
        $blocks = [];
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement || $this->isHidden($child)) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if ($tag !== 'dt' && $tag !== 'dd') {
                continue;
            }
            $this->enter($child, $this->currentStyle(), true);
            try {
                $inlines = [];
                foreach ($child->childNodes as $inner) {
                    $inlines = array_merge($inlines, $this->walkInlines($inner));
                }
                $inlines = $this->trimInlines($inlines);
                if ($tag === 'dt') {
                    $blocks[] = new Paragraph(array_map(
                        fn ($i) => $i instanceof Run ? new Run($i->text, $i->style->withBold()) : $i,
                        $inlines,
                    ));
                } else {
                    $blocks[] = new Paragraph($inlines, style: new ParagraphStyle(indentLeftPt: 24.0));
                }
            } finally {
                $this->leave();
            }
        }

        return $blocks;
    }

    /** Inline content of a block element, with the element's box. */
    private function parseParagraph(\DOMElement $node): Paragraph
    {
        $inlines = [];
        foreach ($node->childNodes as $child) {
            $inlines = array_merge($inlines, $this->walkInlines($child));
        }

        return new Paragraph($this->trimInlines($inlines), style: $this->parseBlockCssStyle($node), defaultRunStyle: $this->currentStyle());
    }

    private function parseHeading(\DOMElement $node, int $level): Heading
    {
        $inlines = [];
        foreach ($node->childNodes as $child) {
            $inlines = array_merge($inlines, $this->walkInlines($child));
        }

        // Heading anchor from id attribute if set
        // (for linkability via <a href="#anchor-id">).
        $anchor = $node->getAttribute('id') !== '' ? $node->getAttribute('id') : null;

        return new Heading($level, $this->trimInlines($inlines), $this->parseBlockCssStyle($node), $anchor);
    }

    /**
     * An element's paragraph style: the inherited text properties, its own
     * margin, padding, border and background.
     */
    private function parseBlockCssStyle(\DOMElement $node): ParagraphStyle
    {
        $decl = $this->decl($node);
        $b = $this->block();
        [$mt, $mr, $mb, $ml] = $this->sides($decl, 'margin');
        [$pt, $pr, $pb, $pl] = $this->sides($decl, 'padding');
        $avoidAfter = strtolower(trim($decl['page-break-after'] ?? '')) === 'avoid';

        return new ParagraphStyle(
            alignment: $b['align'],
            spaceBeforePt: $mt,
            spaceAfterPt: $mb,
            indentLeftPt: $ml,
            indentRightPt: $mr,
            indentFirstLinePt: $b['indent'],
            lineHeightMult: $b['lineHeightMult'],
            lineHeightPt: $b['lineHeightPt'],
            keepWithNext: $avoidAfter,
            borders: $this->borders($decl),
            paddingTopPt: $pt,
            paddingRightPt: $pr,
            paddingBottomPt: $pb,
            paddingLeftPt: $pl,
            backgroundColor: $this->backgroundColor($decl),
        );
    }

    /**
     * Margin or padding on the four sides, in points (`auto`, `%` → 0).
     *
     * @param  array<string, string>  $decl
     * @return array{0: float, 1: float, 2: float, 3: float} top, right, bottom, left
     */
    private function sides(array $decl, string $property): array
    {
        $out = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $out[] = max($property === 'padding' ? 0.0 : -INF, $this->length($decl["$property-$side"] ?? '') ?? 0.0);
        }

        return [$out[0], $out[1], $out[2], $out[3]];
    }

    /**
     * @param  array<string, string>  $decl
     */
    private function backgroundColor(array $decl): ?string
    {
        return isset($decl['background-color']) ? Values::color($decl['background-color']) : null;
    }

    /**
     * The borders an element declares, or null.
     *
     * @param  array<string, string>  $decl
     */
    private function borders(array $decl): ?BorderSet
    {
        $sides = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $sides[$side] = $this->border(
                $decl["border-$side-style"] ?? null,
                $decl["border-$side-width"] ?? null,
                $decl["border-$side-color"] ?? null,
            );
        }
        if (array_filter($sides) === []) {
            return null;
        }

        return new BorderSet(top: $sides['top'], right: $sides['right'], bottom: $sides['bottom'], left: $sides['left']);
    }

    private function border(?string $style, ?string $width, ?string $color): ?Border
    {
        $style = strtolower(trim((string) $style));
        if ($style === '' || $style === 'none' || $style === 'hidden') {
            return null;
        }
        $widthPt = Values::borderWidth($width ?? 'medium', $this->em()) ?? 2.25;
        if ($widthPt <= 0) {
            return null;
        }
        $colorHex = null;
        if ($color !== null && ! in_array(strtolower(trim($color)), ['currentcolor', 'inherit'], true)) {
            $colorHex = Values::color($color);
        }

        return new Border(
            style: match ($style) {
                'double' => BorderStyle::Double,
                'dashed' => BorderStyle::Dashed,
                'dotted' => BorderStyle::Dotted,
                default => BorderStyle::Single,
            },
            sizeEighthsOfPoint: max(1, (int) round($widthPt * 8)),
            color: $colorHex ?? $this->currentStyle()->color ?? '000000',
        );
    }

    /**
     * A width as points or a percentage.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function widthOf(?string $css): array
    {
        if ($css === null) {
            return [null, null];
        }
        $css = trim($css);
        if (preg_match('/^([\d.]+)%$/', $css, $m) === 1) {
            return [null, (float) $m[1]];
        }

        return [$this->length($css), null];
    }

    /**
     * An HTML attribute width: a bare number is pixels, `%` a percentage.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function attributeWidth(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [null, null];
        }
        if (preg_match('/^[\d.]+$/', $value) === 1) {
            return [(float) $value * 0.75, null];
        }

        return $this->widthOf($value);
    }

    // ── Lists ────────────────────────────────────────────────────────────

    private function parseList(\DOMElement $node, bool $ordered): ListNode
    {
        $items = [];
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement || $this->isHidden($child)) {
                continue;
            }
            if (strtolower($child->nodeName) !== 'li') {
                continue;
            }
            $this->enter($child, $this->currentStyle(), true);
            try {
                $items[] = $this->parseListItem($child);
            } finally {
                $this->leave();
            }
        }

        return new ListNode(
            items: $items,
            format: $ordered ? ListFormat::Decimal : ListFormat::Bullet,
        );
    }

    private function parseListItem(\DOMElement $node): ListItem
    {
        // Separate inline content from nested list.
        $blocks = [];
        $pendingInlines = [];
        $nestedList = null;
        foreach ($node->childNodes as $child) {
            if ($this->isHidden($child)) {
                continue;
            }
            if ($child instanceof \DOMElement) {
                $name = strtolower($child->nodeName);
                if ($name === 'ul' || $name === 'ol') {
                    $nestedList = $this->parseList($child, ordered: $name === 'ol');

                    continue;
                }
                if ($this->isBlockTag($child)) {
                    if ($pendingInlines !== []) {
                        $this->pushParagraph($blocks, $pendingInlines);
                        $pendingInlines = [];
                    }
                    foreach ($this->blockElements($child) as $b) {
                        $blocks[] = $b;
                    }

                    continue;
                }
            }
            $inlines = $this->walkInlines($child);
            $pendingInlines = array_merge($pendingInlines, $inlines);
        }
        if ($pendingInlines !== []) {
            $this->pushParagraph($blocks, $pendingInlines);
        }
        if ($blocks === []) {
            $blocks[] = new Paragraph([new Run('')]);
        }

        return new ListItem(children: $blocks, nestedList: $nestedList);
    }

    // ── Tables ───────────────────────────────────────────────────────────

    /**
     * `<caption>` first, as a centred bold paragraph, then the table.
     *
     * @return list<BlockElement>
     */
    private function tableWithCaption(\DOMElement $node): array
    {
        $caption = $this->extractTableCaption($node);

        return $caption !== null ? [$caption, $this->parseTable($node)] : [$this->parseTable($node)];
    }

    private function parseTable(\DOMElement $node): Table
    {
        $decl = $this->decl($node);
        [$widthPt, $widthPercent] = isset($decl['width']) ? $this->widthOf($decl['width']) : $this->attributeWidth($node->getAttribute('width'));
        $marginAuto = strtolower(trim($decl['margin-left'] ?? '')) === 'auto' && strtolower(trim($decl['margin-right'] ?? '')) === 'auto';
        $align = strtolower($node->getAttribute('align'));
        $alignment = $marginAuto || $align === 'center' ? Alignment::Center : ($align === 'right' ? Alignment::End : Alignment::Start);

        $borderAttr = trim($node->getAttribute('border'));
        $cellBorder = null;
        if ($borderAttr !== '' && (float) $borderAttr > 0) {
            $cellBorder = new Border(BorderStyle::Single, 6, '000000'); // 1px, as browsers draw cell borders
        }
        $spacingAttr = $node->getAttribute('cellspacing');
        $spacing = isset($decl['border-spacing'])
            ? ($this->length(explode(' ', trim($decl['border-spacing']))[0]) ?? 0.0)
            : ($spacingAttr !== '' ? (float) $spacingAttr * 0.75 : 0.0);
        $paddingAttr = $node->getAttribute('cellpadding');
        $cellPadding = $paddingAttr !== '' ? (float) $paddingAttr * 0.75 : null;

        [$mt, $mr, $mb, $ml] = $this->sides($decl, 'margin');
        $style = new TableStyle(
            widthPt: $widthPt,
            widthPercent: $widthPercent,
            alignment: $alignment,
            borders: $this->borders($decl),
            defaultCellBorder: $cellBorder,
            spaceBeforePt: $mt,
            // As in a browser: no room after a table unless the CSS asks.
            spaceAfterPt: $mb,
            borderCollapse: strtolower(trim($decl['border-collapse'] ?? '')) === 'collapse',
            borderSpacingPt: $spacing,
            autoLayout: strtolower(trim($decl['table-layout'] ?? '')) !== 'fixed',
            indentLeftPt: $marginAuto ? 0.0 : $ml,
            indentRightPt: $marginAuto ? 0.0 : $mr,
        );

        $rows = [];
        $columns = [];
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement || $this->isHidden($child)) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if ($name === 'tr') {
                $rows[] = $this->tableRow($child, false, $cellPadding);
            } elseif ($name === 'thead' || $name === 'tbody' || $name === 'tfoot') {
                // Walk into wrapper, find <tr>s. Header rows repeat on
                // every page, as browsers print them.
                $this->enter($child, $this->currentStyle(), true);
                try {
                    foreach ($child->childNodes as $tr) {
                        if ($tr instanceof \DOMElement && ! $this->isHidden($tr) && strtolower($tr->nodeName) === 'tr') {
                            $rows[] = $this->tableRow($tr, $name === 'thead', $cellPadding);
                        }
                    }
                } finally {
                    $this->leave();
                }
            } elseif ($name === 'colgroup' || $name === 'col') {
                foreach ($this->columnWidths($child) as $w) {
                    $columns[] = $w;
                }
            }
            // <caption> extracted separately (in tableWithCaption).
        }

        $columnWidthsPt = $this->applyColumnWidths($rows, $columns);

        return new Table($rows, $style, $columnWidthsPt);
    }

    /**
     * Widths of `<col>`s — `[pt, percent]` per column, `[null, null]` when
     * one has none.
     *
     * @return list<array{0: ?float, 1: ?float}>
     */
    private function columnWidths(\DOMElement $node): array
    {
        $cols = strtolower($node->nodeName) === 'col' ? [$node] : [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'col') {
                $cols[] = $child;
            }
        }
        $groupSpan = max(1, (int) $node->getAttribute('span'));
        if ($cols === []) {
            // <colgroup span="3" style="width: …">
            $w = $this->colWidth($node);

            return array_fill(0, $groupSpan, $w);
        }
        $out = [];
        foreach ($cols as $col) {
            $w = $this->colWidth($col);
            for ($i = 0, $n = max(1, (int) $col->getAttribute('span')); $i < $n; $i++) {
                $out[] = $w;
            }
        }

        return $out;
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function colWidth(\DOMElement $col): array
    {
        $decl = $this->decl($col);

        return isset($decl['width']) ? $this->widthOf($decl['width']) : $this->attributeWidth($col->getAttribute('width'));
    }

    /**
     * `<col>` widths: all in points or all in percent → the table's column
     * widths (the engine scales them to the table); otherwise each one goes
     * to the first cell that starts in its column.
     *
     * @param  list<Row>  $rows
     * @param  list<array{0: ?float, 1: ?float}>  $columns
     * @return list<float>|null
     */
    private function applyColumnWidths(array &$rows, array $columns): ?array
    {
        if ($columns === []) {
            return null;
        }
        $points = array_filter(array_column($columns, 0), static fn (?float $w): bool => $w !== null);
        $percents = array_filter(array_column($columns, 1), static fn (?float $w): bool => $w !== null);
        $complete = count($points) === count($columns) || count($percents) === count($columns);
        $columnCount = 0;
        foreach ($rows as $row) {
            $columnCount = max($columnCount, array_sum(array_map(static fn (Cell $c): int => $c->columnSpan, $row->cells)));
        }
        if ($complete && count($columns) === $columnCount) {
            return count($points) === count($columns) ? array_values($points) : array_values($percents);
        }

        // Partial: give each declared column to a cell starting in it.
        $held = [];
        foreach ($rows as $r => $row) {
            $col = 0;
            $cells = $row->cells;
            foreach ($cells as $ci => $cell) {
                while (($held[$col] ?? 0) > 0) {
                    $col++;
                }
                [$pt, $pct] = $columns[$col] ?? [null, null];
                if ($cell->columnSpan === 1 && ($pt !== null || $pct !== null) && $cell->style->widthPt === null && $cell->style->widthPercent === null) {
                    $s = $cell->style;
                    $cells[$ci] = new Cell($cell->children, $s->copy(widthPt: $pt, widthPercent: $pct), $cell->columnSpan, $cell->rowSpan);
                    $columns[$col] = [null, null];
                }
                if ($cell->rowSpan > 1) {
                    for ($k = 0; $k < $cell->columnSpan; $k++) {
                        $held[$col + $k] = $cell->rowSpan;
                    }
                }
                $col += $cell->columnSpan;
            }
            foreach ($held as $c => $left) {
                $held[$c] = max(0, $left - 1);
            }
            $rows[$r] = new Row($cells, $row->isHeader, $row->heightPt, $row->minHeightPt, $row->cantSplit);
        }

        return null;
    }

    /**
     * Extract `<caption>` from `<table>` if present, render as
     * centered bold paragraph (rendered before table). Returns null if
     * no caption.
     */
    private function extractTableCaption(\DOMElement $tableNode): ?Paragraph
    {
        foreach ($tableNode->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'caption') {
                $inlines = [];
                foreach ($child->childNodes as $inner) {
                    $inlines = array_merge($inlines, $this->walkInlines($inner));
                }
                // Bold + centered.
                $boldedInlines = array_map(
                    fn ($i) => $i instanceof Run
                        ? new Run($i->text, $i->style->withBold())
                        : $i,
                    $this->trimInlines($inlines),
                );

                return new Paragraph(
                    $boldedInlines,
                    style: new ParagraphStyle(
                        alignment: Alignment::Center,
                        spaceAfterPt: 4.0,
                    ),
                );
            }
        }

        return null;
    }

    private function tableRow(\DOMElement $tr, bool $isHeader, ?float $cellPadding): Row
    {
        $this->enter($tr, $this->currentStyle(), true);
        try {
            $decl = $this->decl($tr);
            $minHeight = isset($decl['height']) ? $this->length($decl['height']) : null;
            $cells = [];
            foreach ($tr->childNodes as $td) {
                if (! $td instanceof \DOMElement || $this->isHidden($td)) {
                    continue;
                }
                $name = strtolower($td->nodeName);
                if ($name !== 'td' && $name !== 'th') {
                    continue;
                }
                $this->enter($td, $this->currentStyle(), true);
                try {
                    [$cell, $cellHeight] = $this->parseTableCell($td, $cellPadding);
                } finally {
                    $this->leave();
                }
                $cells[] = $cell;
                // A merged cell's height is spread over its rows (CellStyle).
                if ($cellHeight !== null && $cell->rowSpan === 1) {
                    $minHeight = max($minHeight ?? 0.0, $cellHeight);
                }
            }
            $cantSplit = strtolower(trim($decl['page-break-inside'] ?? '')) === 'avoid';

            return new Row($cells, $isHeader, minHeightPt: $minHeight, cantSplit: $cantSplit);
        } finally {
            $this->leave();
        }
    }

    /**
     * @return array{0: Cell, 1: ?float} the cell, and the height it asks for
     */
    private function parseTableCell(\DOMElement $node, ?float $cellPadding): array
    {
        $colSpan = max(1, (int) $node->getAttribute('colspan'));
        $rowSpan = max(1, (int) $node->getAttribute('rowspan'));
        $decl = $this->decl($node);
        $defaults = new CellStyle;

        $pad = function (string $side, float $default) use ($decl, $cellPadding): float {
            $v = $decl["padding-$side"] ?? null;
            if ($v !== null) {
                return max(0.0, $this->length($v) ?? $default);
            }

            return $cellPadding ?? $default;
        };
        [$widthPt, $widthPercent] = isset($decl['width']) ? $this->widthOf($decl['width']) : $this->attributeWidth($node->getAttribute('width'));
        $bg = $this->backgroundColor($decl) ?? ($node->getAttribute('bgcolor') !== '' ? Values::color($node->getAttribute('bgcolor')) : null);
        $height = isset($decl['height']) ? $this->length($decl['height']) : ($node->getAttribute('height') !== '' ? $this->attributeWidth($node->getAttribute('height'))[0] : null);

        $style = new CellStyle(
            widthPt: $widthPt,
            widthPercent: $widthPercent,
            paddingTopPt: $pad('top', $defaults->paddingTopPt),
            paddingRightPt: $pad('right', $defaults->paddingRightPt),
            paddingBottomPt: $pad('bottom', $defaults->paddingBottomPt),
            paddingLeftPt: $pad('left', $defaults->paddingLeftPt),
            verticalAlign: $this->block()['vAlign'] ?? $defaults->verticalAlign,
            backgroundColor: $bg,
            borders: $this->borders($decl),
            minHeightPt: $height,
        );

        $children = $this->walkBlocks($node);
        // An empty cell holds an empty line — unless a height is set for it,
        // as for a spacer row: then it is as tall as asked.
        if ($children === [] && $height === null) {
            $children = [new Paragraph([new Run('')], $this->inheritedParagraphStyle(), defaultRunStyle: $this->currentStyle())];
        }

        return [new Cell(children: $children, style: $style, columnSpan: $colSpan, rowSpan: $rowSpan), $height];
    }

    // ── Inline content ───────────────────────────────────────────────────

    /**
     * @return list<InlineElement>
     */
    private function walkInlines(\DOMNode $node): array
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return $this->textInlines($node);
        }

        if (! $node instanceof \DOMElement || $this->isHidden($node)) {
            return [];
        }

        $tag = strtolower($node->nodeName);

        // Self-closing inline tags.
        if ($tag === 'br') {
            return [new LineBreak];
        }
        // <wbr> — word break opportunity (soft hyphen-like marker).
        // Render as U+00AD soft hyphen char.
        if ($tag === 'wbr') {
            return [new Run("\u{00AD}", $this->currentStyle())];
        }
        if ($tag === 'img') {
            $img = $this->parseImage($node, block: false);

            return $img !== null ? [$img] : [];
        }
        // <picture> — emit first descendant <img> as fallback.
        // libxml may nest <img> inside <source> (since <source> isn't
        // void-closed without </source>), so search recursively.
        if ($tag === 'picture') {
            $img = $this->findFirstImg($node);

            return $img !== null ? [$img] : [];
        }
        // Inline <svg> — extract outerHTML and wrap in SvgElement.
        if ($tag === 'svg') {
            $svg = $this->parseInlineSvg($node);

            return $svg !== null ? [$svg] : [];
        }

        // Anchor — wraps inner inlines.
        if ($tag === 'a') {
            return $this->parseInlineAnchor($node);
        }

        // <font> legacy tag — color/face/size attributes.
        if ($tag === 'font') {
            return $this->parseFontTag($node);
        }

        // A block met inside inline content (a <p> in a <span>) still
        // breaks the line.
        $this->enter($node, $this->styleForTag($this->currentStyle(), $tag), false);
        try {
            $result = [];
            foreach ($node->childNodes as $child) {
                $result = array_merge($result, $this->walkInlines($child));
            }
            if ($this->isBlockTag($node) && $result !== []) {
                $result[] = new LineBreak;
            }

            return $result;
        } finally {
            $this->leave();
        }
    }

    /**
     * A text node by the current `white-space`: collapsed (normal, nowrap),
     * line breaks kept (pre-line), everything kept (pre, pre-wrap).
     *
     * @return list<InlineElement>
     */
    private function textInlines(\DOMNode $node): array
    {
        $raw = $node->nodeValue ?? '';
        $mode = $this->block()['whiteSpace'];
        $style = $this->currentStyle();

        if (! in_array($mode, ['pre', 'pre-wrap', 'pre-line', 'break-spaces'], true)) {
            $text = $this->normalizeWhitespace($raw);
            if ($text === '') {
                return [];
            }

            return [new Run($this->applyTextTransform($text), $style)];
        }

        // The newline right after <pre> belongs to the markup.
        if ($node->previousSibling === null && $node->parentNode !== null && strtolower($node->parentNode->nodeName) === 'pre') {
            $raw = (string) preg_replace('/^\r?\n/', '', $raw);
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $out[] = new LineBreak;
            }
            if ($mode === 'pre-line') {
                $line = (string) preg_replace('/[ \t\f]+/', ' ', $line);
            } else {
                // Spaces kept: every space after the first of a run is a
                // no-break space, which the line breaker does not merge.
                $line = str_replace("\t", '    ', $line);
                $line = (string) preg_replace_callback('/ {2,}/', static fn (array $m): string => ' '.str_repeat("\u{00A0}", strlen($m[0]) - 1), $line);
                if (str_starts_with($line, ' ')) {
                    $line = "\u{00A0}".substr($line, 1);
                }
            }
            if ($line !== '') {
                $out[] = new Run($this->applyTextTransform($line), $style);
            }
        }

        return $out;
    }

    /**
     * Whitespace at the start and end of a block, and around a line break,
     * is not shown (unless white-space keeps it).
     *
     * @param  list<InlineElement>  $inlines
     * @return list<InlineElement>
     */
    private function trimInlines(array $inlines): array
    {
        if (in_array($this->block()['whiteSpace'], ['pre', 'pre-wrap', 'break-spaces'], true)) {
            return $inlines;
        }
        $count = count($inlines);
        for ($i = 0; $i < $count; $i++) {
            $item = $inlines[$i];
            if (! $item instanceof Run) {
                continue;
            }
            $text = $item->text;
            $atStart = $i === 0 || $inlines[$i - 1] instanceof LineBreak || ($this->onlyEmptyRunsBefore($inlines, $i));
            $atEnd = $i === $count - 1 || $inlines[$i + 1] instanceof LineBreak;
            if ($atStart) {
                $text = ltrim($text, ' ');
            }
            if ($atEnd) {
                $text = rtrim($text, ' ');
            }
            // A space after a space is shown once.
            if ($i > 0 && str_starts_with($text, ' ') && $inlines[$i - 1] instanceof Run && str_ends_with($inlines[$i - 1]->text, ' ')) {
                $text = ltrim($text, ' ');
            }
            $inlines[$i] = new Run($text, $item->style);
        }
        // The last runs before the end of the block may still end in a space
        // when the very last item is not a run (an image).
        $out = [];
        foreach ($inlines as $item) {
            if ($item instanceof Run && $item->text === '') {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  list<InlineElement>  $inlines
     */
    private function onlyEmptyRunsBefore(array $inlines, int $i): bool
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            $prev = $inlines[$k];
            if ($prev instanceof LineBreak) {
                return true;
            }
            if (! $prev instanceof Run || trim($prev->text, ' ') !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Detect text-transform CSS on element. Returns 'uppercase'/
     * 'lowercase'/'capitalize'/'none' or null if not set.
     */
    private function cssTextTransform(\DOMElement $node): ?string
    {
        $decl = $this->decl($node);
        if (! isset($decl['text-transform'])) {
            return null;
        }

        return match (strtolower(trim($decl['text-transform']))) {
            'uppercase' => 'uppercase',
            'lowercase' => 'lowercase',
            'capitalize' => 'capitalize',
            'none' => 'none',
            default => null,
        };
    }

    private function currentTransform(): string
    {
        return end($this->textTransformStack) ?: 'none';
    }

    private function applyTextTransform(string $text): string
    {
        return match ($this->currentTransform()) {
            'uppercase' => mb_strtoupper($text, 'UTF-8'),
            'lowercase' => mb_strtolower($text, 'UTF-8'),
            'capitalize' => mb_convert_case($text, MB_CASE_TITLE_SIMPLE, 'UTF-8'),
            default => $text,
        };
    }

    /**
     * Legacy <font color="..." face="..." size="..."> tag.
     *
     * HTML `size` attribute is 1-7 mapping to relative sizes; we map to
     * absolute pt sizes:
     *   1 → 8pt, 2 → 10pt, 3 → 12pt (default), 4 → 14pt,
     *   5 → 18pt, 6 → 24pt, 7 → 36pt.
     *
     * @return list<InlineElement>
     */
    private function parseFontTag(\DOMElement $node): array
    {
        $current = $this->currentStyle();
        $color = $current->color;
        $fontFamily = $current->fontFamily;
        $size = $current->sizePt;

        $attrColor = $node->getAttribute('color');
        if ($attrColor !== '') {
            $parsed = Values::color($attrColor);
            if ($parsed !== null) {
                $color = $parsed;
            }
        }

        $attrFace = $node->getAttribute('face');
        if ($attrFace !== '') {
            $fontFamily = trim(explode(',', $attrFace)[0], " \"'");
        }

        $attrSize = $node->getAttribute('size');
        if ($attrSize !== '') {
            $sizeMap = [1 => 8.0, 2 => 10.0, 3 => 12.0, 4 => 14.0, 5 => 18.0, 6 => 24.0, 7 => 36.0];
            $intSize = (int) $attrSize;
            if (isset($sizeMap[$intSize])) {
                $size = $sizeMap[$intSize];
            }
        }

        $base = new RunStyle(
            sizePt: $size,
            color: $color,
            backgroundColor: $current->backgroundColor,
            fontFamily: $fontFamily,
            bold: $current->bold,
            italic: $current->italic,
            underline: $current->underline,
            strikethrough: $current->strikethrough,
            superscript: $current->superscript,
            subscript: $current->subscript,
            highlight: $current->highlight,
            letterSpacingPt: $current->letterSpacingPt,
        );

        $this->enter($node, $base, false);
        try {
            $result = [];
            foreach ($node->childNodes as $child) {
                $result = array_merge($result, $this->walkInlines($child));
            }

            return $result;
        } finally {
            $this->leave();
        }
    }

    /**
     * A link keeps the text style around it — size, font, weight — and is
     * blue and underlined unless its own CSS says otherwise (`color:
     * inherit`, `text-decoration: none`).
     *
     * @return list<InlineElement>
     */
    private function parseInlineAnchor(\DOMElement $node): array
    {
        $href = $node->getAttribute('href');
        if ($href === '') {
            // Empty <a> — treat as span.
            $this->enter($node, $this->currentStyle(), false);
            try {
                $result = [];
                foreach ($node->childNodes as $child) {
                    $result = array_merge($result, $this->walkInlines($child));
                }

                return $result;
            } finally {
                $this->leave();
            }
        }

        $parent = $this->currentStyle();
        $decl = $this->decl($node);
        $ownColor = strtolower(trim($decl['color'] ?? ''));
        $base = new RunStyle(
            sizePt: $parent->sizePt,
            color: in_array($ownColor, ['inherit', 'currentcolor'], true) ? $parent->color : ($parent->color ?? '0000ee'),
            backgroundColor: $parent->backgroundColor,
            fontFamily: $parent->fontFamily,
            bold: $parent->bold,
            italic: $parent->italic,
            underline: true,
            strikethrough: $parent->strikethrough,
            superscript: $parent->superscript,
            subscript: $parent->subscript,
            highlight: $parent->highlight,
            letterSpacingPt: $parent->letterSpacingPt,
        );

        $previous = $this->linkHref;
        $this->linkHref = $href;
        $this->enter($node, $base, false);
        try {
            $children = [];
            foreach ($node->childNodes as $child) {
                $children = array_merge($children, $this->walkInlines($child));
            }
            if ($children === []) {
                $children = [new Run($href, $this->currentStyle())];
            }
        } finally {
            $this->leave();
            $this->linkHref = $previous;
        }

        if (str_starts_with($href, '#')) {
            return [Hyperlink::internal(substr($href, 1), $children)];
        }

        return [Hyperlink::external($href, $children)];
    }

    /**
     * Parse inline <svg> element — extract outerHTML via DOM
     * serialization, wrap in SvgElement.
     */
    private function parseInlineSvg(\DOMElement $node): ?\Dskripchenko\PhpPdf\Element\SvgElement
    {
        $svgXml = $node->ownerDocument?->saveXML($node);
        if ($svgXml === false || $svgXml === null) {
            return null;
        }
        // Width/height from attributes. Per SVG-in-HTML spec, unitless
        // values treated as px (we convert to pt: 1px = 0.75pt @ 96 DPI).
        $widthPt = $this->parseSvgDimension($node->getAttribute('width')) ?? 100.0;
        $heightPt = $this->parseSvgDimension($node->getAttribute('height')) ?? 100.0;

        try {
            return new \Dskripchenko\PhpPdf\Element\SvgElement(
                svgXml: $svgXml,
                widthPt: $widthPt,
                heightPt: $heightPt,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse SVG dimension attribute. Unitless = px (per SVG spec
     * in HTML context), explicit units honored.
     */
    private function parseSvgDimension(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^([\d.]+)$/', $value, $m) === 1) {
            return (float) $m[1] * 0.75; // px → pt
        }

        return $this->length($value);
    }

    /**
     * Recursive search for the first <img> descendant.
     */
    private function findFirstImg(\DOMNode $node): ?Image
    {
        if ($node instanceof \DOMElement && strtolower($node->nodeName) === 'img') {
            return $this->parseImage($node, block: false);
        }
        foreach ($node->childNodes as $child) {
            $found = $this->findFirstImg($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * An image: size from CSS or the attributes (a bare attribute number in
     * points, as before), `width: N%` of the line; a block image is placed
     * by `margin: auto` or the text-align around it.
     */
    private function parseImage(\DOMElement $node, bool $block): ?Image
    {
        $src = $node->getAttribute('src');
        if ($src === '') {
            return null;
        }
        $alt = $node->getAttribute('alt') ?: null;
        $decl = $this->decl($node);

        $width = $this->length($node->getAttribute('width'));
        $height = $this->length($node->getAttribute('height'));
        $widthPercent = null;
        if (isset($decl['width']) && strtolower(trim($decl['width'])) !== 'auto') {
            [$width, $widthPercent] = $this->widthOf($decl['width']);
        }
        if (isset($decl['height'])) {
            $height = strtolower(trim($decl['height'])) === 'auto' ? null : $this->length($decl['height']);
        }
        if ($widthPercent !== null && ! isset($decl['height'])) {
            $height = null; // the aspect ratio follows the width
        }

        $alignment = Alignment::Start;
        $before = 0.0;
        $after = 0.0;
        if ($block) {
            $marginAuto = strtolower(trim($decl['margin-left'] ?? '')) === 'auto' && strtolower(trim($decl['margin-right'] ?? '')) === 'auto';
            $inherited = $this->block()['align'];
            $alignment = $marginAuto ? Alignment::Center : (in_array($inherited, [Alignment::Center, Alignment::End], true) ? $inherited : Alignment::Start);
            [$before, , $after] = $this->sides($decl, 'margin');
        }

        try {
            // Data URL: data:image/png;base64,...
            if (str_starts_with($src, 'data:')) {
                $pdfImg = $this->parseDataUrl($src);
            } elseif (is_readable($src)) {
                $pdfImg = PdfImage::fromPath($src);
            } else {
                return null; // Cannot load image — skip.
            }
        } catch (\Throwable) {
            return null;
        }
        if ($pdfImg === null) {
            return null;
        }

        return new Image(
            source: $pdfImg,
            widthPt: $width,
            heightPt: $height,
            alignment: $alignment,
            spaceBeforePt: max(0.0, $before),
            spaceAfterPt: max(0.0, $after),
            altText: $alt,
            widthPercent: $widthPercent,
        );
    }

    private function parseDataUrl(string $url): ?PdfImage
    {
        if (! preg_match('@^data:image/[^;]+;base64,(.+)$@', $url, $m)) {
            return null;
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false) {
            return null;
        }

        return PdfImage::fromBytes($bytes);
    }

    private function currentStyle(): RunStyle
    {
        return end($this->styleStack) ?: new RunStyle;
    }

    private function styleForTag(RunStyle $current, string $tag): RunStyle
    {
        $with = fn (array $changes): RunStyle => new RunStyle(
            sizePt: $changes['sizePt'] ?? $current->sizePt,
            color: $current->color,
            backgroundColor: $changes['backgroundColor'] ?? $current->backgroundColor,
            fontFamily: $changes['fontFamily'] ?? $current->fontFamily,
            bold: $current->bold,
            italic: $changes['italic'] ?? $current->italic,
            underline: $current->underline,
            strikethrough: $current->strikethrough,
            superscript: $current->superscript,
            subscript: $current->subscript,
            highlight: $current->highlight,
            letterSpacingPt: $current->letterSpacingPt,
        );

        return match ($tag) {
            // Basic semantic styling.
            'b', 'strong' => $current->withBold(),
            'i', 'em' => $current->withItalic(),
            'u' => $current->withUnderline(),
            's', 'strike', 'del' => $current->withStrikethrough(),
            'sup' => $current->withSuperscript(),
            'sub' => $current->withSubscript(),
            // Extended semantic inline tags.
            'code', 'kbd', 'samp', 'tt' => $with(['fontFamily' => 'Courier']),
            'var' => $with(['fontFamily' => 'Courier', 'italic' => true]),
            'mark' => $with(['backgroundColor' => 'ffff00']), // yellow highlight per HTML5 spec
            'small' => $with(['sizePt' => ($current->sizePt ?? $this->baseFontSizePt) * 0.83]),
            'big' => $with(['sizePt' => ($current->sizePt ?? $this->baseFontSizePt) * 1.2]),
            'ins' => $current->withUnderline(), // inserted text — underlined
            'cite', 'dfn', 'q' => $current->withItalic(), // citations/definitions/inline-quotes
            'abbr' => $current->withUnderline(), // abbreviation — dotted underline (we use plain)
            default => $current,
        };
    }

    /**
     * The text style of an element from its CSS. A block's background is
     * its box, not the background of its text.
     */
    private function applyInlineCssStyle(RunStyle $current, \DOMElement $node, bool $isBlock = false): RunStyle
    {
        $decl = $this->decl($node);
        if ($decl === []) {
            return $current;
        }
        $parentSize = $current->sizePt ?? $this->baseFontSizePt;

        $size = $current->sizePt;
        if (isset($decl['font-size'])) {
            $size = Values::fontSize($decl['font-size'], $parentSize, $this->baseFontSizePt) ?? $size;
        }

        $color = $current->color;
        if (isset($decl['color'])) {
            $color = Values::color($decl['color']) ?? $color;
        }

        $bgColor = $current->backgroundColor;
        if (! $isBlock && isset($decl['background-color'])) {
            $bgColor = Values::color($decl['background-color']);
        }

        $fontFamily = $current->fontFamily;
        if (isset($decl['font-family'])) {
            $fontFamily = trim(explode(',', $decl['font-family'])[0], " \"'");
        }

        $bold = $current->bold;
        if (isset($decl['font-weight'])) {
            $w = strtolower(trim($decl['font-weight']));
            if (in_array($w, ['bold', 'bolder', '600', '700', '800', '900'], true)) {
                $bold = true;
            } elseif (in_array($w, ['normal', 'lighter', '100', '200', '300', '400', '500'], true)) {
                $bold = false;
            }
        }

        $italic = $current->italic;
        if (isset($decl['font-style'])) {
            $fs = strtolower(trim($decl['font-style']));
            $italic = $fs === 'italic' || str_starts_with($fs, 'oblique') ? true : ($fs === 'normal' ? false : $italic);
        }

        $underline = $current->underline;
        $strikethrough = $current->strikethrough;
        if (isset($decl['text-decoration']) || isset($decl['text-decoration-line'])) {
            $td = strtolower($decl['text-decoration-line'] ?? $decl['text-decoration']);
            if (str_contains($td, 'none')) {
                $underline = false;
                $strikethrough = false;
            }
            if (str_contains($td, 'underline')) {
                $underline = true;
            }
            if (str_contains($td, 'line-through') || str_contains($td, 'strike')) {
                $strikethrough = true;
            }
        }

        $letterSpacing = $current->letterSpacingPt;
        if (isset($decl['letter-spacing'])) {
            $letterSpacing = strtolower(trim($decl['letter-spacing'])) === 'normal'
                ? null
                : (Values::length($decl['letter-spacing'], $size ?? $parentSize, $this->baseFontSizePt) ?? $letterSpacing);
        }

        $superscript = $current->superscript;
        $subscript = $current->subscript;
        if (! $isBlock && isset($decl['vertical-align'])) {
            $va = strtolower(trim($decl['vertical-align']));
            $superscript = $va === 'super' ? true : ($va === 'baseline' ? false : $superscript);
            $subscript = $va === 'sub' ? true : ($va === 'baseline' ? false : $subscript);
        }

        return new RunStyle(
            sizePt: $size,
            color: $color,
            backgroundColor: $bgColor,
            fontFamily: $fontFamily,
            bold: $bold,
            italic: $italic,
            underline: $underline,
            strikethrough: $strikethrough,
            superscript: $superscript,
            subscript: $subscript,
            highlight: $current->highlight,
            letterSpacingPt: $letterSpacing,
        );
    }

    private function normalizeWhitespace(string $text): string
    {
        // Collapse runs of CSS whitespace (not the no-break space) to a
        // single space; leading/trailing whitespace of a block is trimmed
        // later, in trimInlines().
        $text = (string) preg_replace('/[ \t\n\r\f]+/', ' ', $text);

        return $text === ' ' ? ' ' : $text;
    }
}
