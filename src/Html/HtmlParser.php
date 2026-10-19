<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Html;

use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Field;
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
use Dskripchenko\PhpPdf\Element\Positioned;
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
    /** A `counter(page)` piece of `content`. */
    private const PAGE_COUNTER = "\0page";

    /** A `counter(pages)` piece of `content`. */
    private const PAGES_COUNTER = "\0pages";

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

    /** @var list<int> The numbers of the list items being read, outermost first. */
    private array $listNumbers = [];

    /**
     * @param  float  $baseFontSizePt  The font size `em`, `rem` and `%` refer to
     *                                 outside any element that sets one — the
     *                                 engine's default size.
     * @param  list<ElementHandler>  $elementHandlers  Asked first about every element (see {@see ElementHandler}).
     * @param  ?\Closure(string): ?string  $imageSource  The bytes of an image by its `src` — from storage,
     *                                                    a database, an allow-listed host; null falls back
     *                                                    to `data:` URLs and readable local files.
     * @param  bool  $localFiles  Whether `<img src>` and `<link href>` may name local files. Turn it off
     *                            for HTML from untrusted authors: only `data:` URLs and the image
     *                            source are read then.
     */
    public function __construct(
        private readonly float $baseFontSizePt = 12.0,
        private readonly array $elementHandlers = [],
        private readonly ?\Closure $imageSource = null,
        private readonly bool $localFiles = true,
    )
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
        // <style> blocks and <link rel="stylesheet"> in document order, as a
        // browser applies them; screen-only sheets are skipped.
        foreach ((new \DOMXPath($dom))->query('//style | //link') ?: [] as $sheet) {
            if (! $sheet instanceof \DOMElement) {
                continue;
            }
            $media = strtolower($sheet->getAttribute('media'));
            if ($media !== '' && ! str_contains($media, 'print') && ! str_contains($media, 'all')) {
                continue;
            }
            $css = strtolower($sheet->nodeName) === 'style' ? $sheet->textContent : $this->linkedStyleSheet($sheet);
            if ($css !== null) {
                $this->css->add($css);
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

    /**
     * The CSS a `<link rel="stylesheet">` points at: a `data:` URL or a
     * readable local file, like `<img src>`. Remote sheets are not fetched —
     * the parser makes no network requests.
     */
    private function linkedStyleSheet(\DOMElement $link): ?string
    {
        $rel = ' '.strtolower(preg_replace('/\s+/', ' ', $link->getAttribute('rel')) ?? '').' ';
        $href = trim($link->getAttribute('href'));
        if (! str_contains($rel, ' stylesheet ') || str_contains($rel, ' alternate ') || $href === '') {
            return null;
        }
        if (preg_match('#^data:text/css(;charset=[^;,]+)?(;base64)?,(.*)$#is', $href, $m) === 1) {
            $css = $m[2] !== '' ? base64_decode($m[3], true) : rawurldecode($m[3]);

            return $css === false ? null : $css;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href) === 1 && ! str_starts_with(strtolower($href), 'file://')) {
            return null;
        }
        if (! $this->localFiles) {
            return null;
        }
        $path = str_starts_with(strtolower($href), 'file://') ? substr($href, 7) : $href;

        return is_file($path) && is_readable($path) ? (string) file_get_contents($path) : null;
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
        } elseif ($tag === 'th') {
            // A header cell is centred unless told otherwise, as in a browser.
            $b['align'] = Alignment::Center;
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
        return $this->walkNodes(iterator_to_array($parent->childNodes), $parent instanceof \DOMElement ? $parent : null);
    }

    /**
     * The blocks of a run of sibling nodes. `$owner` is the element they
     * belong to (for its `::before` / `::after`), when they are all of it.
     *
     * @param  list<\DOMNode>  $nodes
     * @return list<BlockElement>
     */
    private function walkNodes(array $nodes, ?\DOMElement $owner): array
    {
        $blocks = [];
        // Absolutely placed children go first: their offsets count from
        // where their box starts.
        $placed = [];
        $pendingInlines = $owner !== null ? $this->generated($owner, 'before') : [];

        $count = count($nodes);
        for ($i = 0; $i < $count; $i++) {
            $node = $nodes[$i];
            if ($this->isHidden($node)) {
                continue;
            }
            if ($node instanceof \DOMElement && ($this->isBlockTag($node) || $this->isBlockImage($node))
                && ($handled = $this->handled($node, inline: false)) !== null) {
                foreach ($handled as $element) {
                    if ($element instanceof InlineElement && ! $element instanceof BlockElement) {
                        $pendingInlines[] = $element;

                        continue;
                    }
                    if ($pendingInlines !== []) {
                        $this->pushParagraph($blocks, $pendingInlines);
                        $pendingInlines = [];
                    }
                    $blocks[] = $element;
                }

                continue;
            }
            if ($node instanceof \DOMElement && ($position = $this->positioned($node)) !== null) {
                if ($position->fixed || ! $position->inFlow) {
                    $blocks[] = $position;
                } else {
                    $placed[] = $position;
                }

                continue;
            }
            if ($node instanceof \DOMElement && ($side = $this->floatSide($node)) !== null) {
                if ($pendingInlines !== []) {
                    $this->pushParagraph($blocks, $pendingInlines);
                    $pendingInlines = [];
                }
                // The float and what follows it, up to an element that clears.
                $rest = [];
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($nodes[$j] instanceof \DOMElement && $this->clears($nodes[$j])) {
                        break;
                    }
                    $rest[] = $nodes[$j];
                }
                foreach ($this->floated($node, $side, $rest) as $b) {
                    $blocks[] = $b;
                }
                $i = $j - 1;

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

        if ($owner !== null) {
            $pendingInlines = array_merge($pendingInlines, $this->generated($owner, 'after'));
        }
        if ($pendingInlines !== []) {
            $this->pushParagraph($blocks, $pendingInlines);
        }
        $blocks = array_merge($placed, $blocks);

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
     * What the element handlers make of an element; null when none of them
     * takes it.
     *
     * @return list<BlockElement|InlineElement>|null
     */
    private function handled(\DOMElement $node, bool $inline): ?array
    {
        if ($this->elementHandlers === []) {
            return null;
        }
        $context = new HtmlContext(
            $this->applyInlineCssStyle($this->currentStyle(), $node, ! $inline),
            $inline,
            function (\DOMElement $element): array {
                $this->enter($element, $this->styleForTag($this->currentStyle(), strtolower($element->nodeName)), false);
                try {
                    $out = [];
                    foreach ($element->childNodes as $child) {
                        $out = array_merge($out, self::inlineOnly($this->walkInlines($child)));
                    }

                    return $out;
                } finally {
                    $this->leave();
                }
            },
            function (\DOMElement $element): array {
                $this->enter($element, $this->currentStyle(), true);
                try {
                    return $this->walkBlocks($element);
                } finally {
                    $this->leave();
                }
            },
            ($inline ? null : $this->alignment($this->decl($node)['text-align'] ?? '')) ?? $this->block()['align'],
        );
        foreach ($this->elementHandlers as $handler) {
            $result = $handler->handle($node, $context);
            if ($result !== null) {
                return array_values($result);
            }
        }

        return null;
    }

    /**
     * `position: absolute | fixed` as an out-of-flow block; null for other
     * positions. An absolute element inside a positioned ancestor is placed
     * from where that ancestor's content starts; otherwise from the page
     * area. `fixed` repeats on every page.
     */
    private function positioned(\DOMElement $node): ?Positioned
    {
        $decl = $this->decl($node);
        $position = strtolower(trim($decl['position'] ?? ''));
        if ($position !== 'absolute' && $position !== 'fixed') {
            return null;
        }
        $inFlow = false;
        // Offsets count from the padding edge of the positioned ancestor;
        // the element is placed where that ancestor's content starts, so
        // its padding comes off.
        $padding = [0.0, 0.0, 0.0, 0.0];
        if ($position === 'absolute') {
            for ($a = $node->parentNode; $a instanceof \DOMElement && $a->getAttribute('id') !== '_phppdf_root'; $a = $a->parentNode) {
                $ancestor = $this->decl($a);
                if (in_array(strtolower(trim($ancestor['position'] ?? '')), ['relative', 'absolute', 'fixed', 'sticky'], true)) {
                    $inFlow = true;
                    $padding = $this->sides($ancestor, 'padding');
                    break;
                }
            }
        }
        $offset = function (string $side, int $edge) use ($decl, $padding): ?float {
            if (! isset($decl[$side]) || strtolower(trim($decl[$side])) === 'auto') {
                return null;
            }
            $value = $this->length($decl[$side], $this->contentWidth());

            return $value === null ? null : $value - $padding[$edge];
        };
        [$widthPt, $widthPercent] = $this->widthOf($decl['width'] ?? null);
        $tag = strtolower($node->nodeName);
        if ($tag === 'img') {
            $this->enter($node, $this->currentStyle(), true);
            try {
                $image = $this->parseImage($node, block: true);
            } finally {
                $this->leave();
            }
            $children = $image !== null ? [$image] : [];
            $widthPt ??= $image?->effectiveSizePt(null)[0];
        } else {
            $children = $this->blockElements($node);
        }
        if ($children === []) {
            return null;
        }

        return new Positioned(
            $children,
            topPt: $offset('top', 0),
            rightPt: $offset('right', 1),
            bottomPt: $offset('bottom', 2),
            leftPt: $offset('left', 3),
            widthPt: $widthPt ?? ($widthPercent !== null ? $this->contentWidth() * $widthPercent / 100 : null),
            fixed: $position === 'fixed',
            inFlow: $inFlow,
        );
    }

    /** 'left' or 'right' for a floated element. */
    private function floatSide(\DOMElement $node): ?string
    {
        $float = strtolower(trim($this->decl($node)['float'] ?? ''));

        return in_array($float, ['left', 'right'], true) ? $float : null;
    }

    private function clears(\DOMElement $node): bool
    {
        return in_array(strtolower(trim($this->decl($node)['clear'] ?? '')), ['left', 'right', 'both'], true);
    }

    /**
     * A float and the content after it, side by side in a borderless row:
     * the float in a column of its own width, the rest beside it. Text does
     * not run on under a float that ends early — the one approximation.
     *
     * @param  'left'|'right'  $side
     * @param  list<\DOMNode>  $rest
     * @return list<BlockElement>
     */
    private function floated(\DOMElement $node, string $side, array $rest): array
    {
        $decl = $this->decl($node);
        [$mt, $mr, $mb, $ml] = $this->sides($decl, 'margin');
        [$widthPt, $widthPercent] = $this->widthOf($decl['width'] ?? null);
        if (strtolower($node->nodeName) === 'img') {
            $this->enter($node, $this->currentStyle(), true);
            try {
                $image = $this->parseImage($node, block: true);
            } finally {
                $this->leave();
            }
            $floatBlocks = $image !== null ? [$image] : [];
            if ($widthPt === null && $widthPercent === null && $image !== null) {
                $widthPt = $image->effectiveSizePt(null)[0];
            }
        } else {
            $floatBlocks = $this->blockElements($node);
        }
        $restBlocks = $this->walkNodes($rest, null);
        if ($floatBlocks === []) {
            return $restBlocks;
        }

        $zero = static fn (float $top, float $right, float $bottom, float $left, ?float $w = null, ?float $wp = null): CellStyle => new CellStyle(
            widthPt: $w, widthPercent: $wp,
            paddingTopPt: $top, paddingRightPt: $right, paddingBottomPt: $bottom, paddingLeftPt: $left,
        );
        $floatCell = new Cell($floatBlocks, $side === 'left'
            ? $zero($mt, $mr, $mb, $ml, $widthPt !== null ? $widthPt + $ml + $mr : null, $widthPercent)
            : $zero($mt, $mr, $mb, $ml, $widthPt !== null ? $widthPt + $ml + $mr : null, $widthPercent));
        $restCell = new Cell($restBlocks === [] ? [new Paragraph([])] : $restBlocks, $zero(0, 0, 0, 0));

        return [new Table(
            [new Row($side === 'left' ? [$floatCell, $restCell] : [$restCell, $floatCell])],
            new TableStyle(widthPercent: 100, spaceAfterPt: 0, borderCollapse: true),
        )];
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
        foreach ($this->flowParagraphs($inlines, $this->inheritedParagraphStyle()) as $block) {
            $blocks[] = $block;
        }
    }

    /**
     * A line of text as paragraphs: one, or several split around the blocks
     * a handler placed in it. The first part keeps the space before, the
     * last the space after; parts that are only whitespace are dropped.
     *
     * @param  list<InlineElement>  $inlines
     * @return list<BlockElement>
     */
    private function flowParagraphs(array $inlines, ParagraphStyle $style, bool $keepEmpty = false): array
    {
        $parts = [[]];
        foreach ($inlines as $inline) {
            if ($inline instanceof FlowBlocks) {
                $parts[] = $inline;
                $parts[] = [];
            } else {
                $parts[count($parts) - 1][] = $inline;
            }
        }
        if (count($parts) === 1) {
            $text = $this->trimInlines($parts[0]);

            return $text !== [] || $keepEmpty ? [new Paragraph($text, $style, defaultRunStyle: $this->currentStyle())] : [];
        }

        $out = [];
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($part instanceof FlowBlocks) {
                foreach ($part->blocks as $block) {
                    $out[] = $block;
                }

                continue;
            }
            $text = $this->trimInlines($part);
            if ($text === []) {
                continue;
            }
            $out[] = new Paragraph($text, $this->styleCopy(
                $style,
                spaceBefore: $i === 0 ? null : 0.0,
                spaceAfter: $i === $last ? null : 0.0,
            ), defaultRunStyle: $this->currentStyle());
        }

        return $out;
    }

    /**
     * Inline content where it cannot split: blocks a handler placed in it
     * are left out.
     *
     * @param  list<InlineElement>  $inlines
     * @return list<InlineElement>
     */
    private static function inlineOnly(array $inlines): array
    {
        return array_values(array_filter($inlines, static fn (InlineElement $i): bool => ! $i instanceof FlowBlocks));
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
            $built = $this->buildBlock($node, $decl);
        } finally {
            $this->leave();
        }
        $avoid = strtolower(trim($decl['break-inside'] ?? ($decl['page-break-inside'] ?? '')));
        if (in_array($avoid, ['avoid', 'avoid-page'], true) && $built !== []) {
            $built = [$this->keptTogether($built)];
        }
        foreach ($built as $b) {
            $out[] = $b;
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

        if (in_array($display, ['flex', 'inline-flex'], true) && $this->elementChildren($node) !== []) {
            return $this->flexBox($node, $decl);
        }
        if (in_array($display, ['grid', 'inline-grid'], true) && $this->elementChildren($node) !== []) {
            return $this->gridBox($node, $decl);
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
        $inlines = $this->paragraphInlines($node);
        foreach ($inlines as $inline) {
            if ($inline instanceof FlowBlocks) {
                return $this->flowParagraphs($inlines, $this->parseBlockCssStyle($node));
            }
        }
        $p = new Paragraph($this->trimInlines($inlines), style: $this->parseBlockCssStyle($node), defaultRunStyle: $this->currentStyle());
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
     * `display: flex`. A row (the default) puts the children side by side
     * in a one-row table: each keeps its own box, `gap` goes between them,
     * `width` / `flex-basis` set a column, the rest share equally
     * (`flex: 1`). With `flex-wrap: wrap` the children go into as many rows
     * as their widths need. `flex-direction: column` stacks them.
     *
     * @param  array<string, string>  $decl
     * @return list<BlockElement>
     */
    private function flexBox(\DOMElement $node, array $decl): array
    {
        $direction = strtolower(trim($decl['flex-direction'] ?? ''));
        if (str_starts_with($direction, 'column')) {
            return $this->container($node, $decl);
        }
        $gap = $this->length($decl['column-gap'] ?? ($decl['gap'] ?? '')) ?? 0.0;
        $rowGap = $this->length($decl['row-gap'] ?? ($decl['gap'] ?? '')) ?? 0.0;
        $vAlign = match (strtolower(trim($decl['align-items'] ?? ''))) {
            'center' => VerticalAlignment::Center,
            'flex-end', 'end' => VerticalAlignment::Bottom,
            default => VerticalAlignment::Top,
        };
        $children = $this->elementChildren($node);
        $wrap = in_array(strtolower(trim($decl['flex-wrap'] ?? '')), ['wrap', 'wrap-reverse'], true);
        $lines = $wrap ? $this->wrapLines($children, $node, $decl, $gap) : [$children];

        $rows = [];
        $lineCount = count($lines);
        foreach ($lines as $l => $line) {
            $count = count($line);
            $cells = [];
            foreach ($line as $i => $child) {
                $cells[] = $this->itemCell($child, $vAlign, [
                    'left' => $i > 0 ? $gap / 2 : 0.0,
                    'right' => $i < $count - 1 ? $gap / 2 : 0.0,
                    'top' => $l > 0 ? $rowGap / 2 : 0.0,
                    'bottom' => $l < $lineCount - 1 ? $rowGap / 2 : 0.0,
                ]);
            }
            $rest = $this->leftOver($line, $gap);
            if ($rest !== null) {
                // Items with widths that do not fill the line keep their
                // widths; an empty cell takes the rest.
                $cells[] = new Cell([new Paragraph([])], new CellStyle(
                    widthPt: $rest[0], widthPercent: $rest[1],
                    paddingTopPt: 0, paddingRightPt: 0, paddingBottomPt: 0, paddingLeftPt: 0,
                ));
            }
            $rows[] = $cells;
        }

        // Each line its own table: the lines of a wrapped flex box do not
        // share columns.
        $tables = array_map(fn (array $cells): Table => new Table([new Row($cells)], new TableStyle(spaceAfterPt: 0, borderCollapse: true)), $rows);

        return $this->boxed($tables, $decl);
    }

    /**
     * `display: grid` — a table with the columns of `grid-template-columns`
     * (lengths, percentages, `fr`, `auto`, `repeat()`, `minmax()`); the
     * children fill it row by row, `grid-column: span n` (or `a / b`)
     * spans columns, `gap` goes between the cells.
     *
     * @param  array<string, string>  $decl
     * @return list<BlockElement>
     */
    private function gridBox(\DOMElement $node, array $decl): array
    {
        $tracks = $this->gridTracks($decl['grid-template-columns'] ?? '');
        $columns = max(1, count($tracks));
        $gap = $this->length($decl['column-gap'] ?? ($decl['gap'] ?? '')) ?? 0.0;
        $rowGap = $this->length($decl['row-gap'] ?? ($decl['gap'] ?? '')) ?? 0.0;
        $vAlign = match (strtolower(trim($decl['align-items'] ?? ''))) {
            'center' => VerticalAlignment::Center,
            'end', 'flex-end' => VerticalAlignment::Bottom,
            default => VerticalAlignment::Top,
        };

        // Place the children: rows of `$columns` slots.
        $placed = [];
        $row = [];
        $used = 0;
        foreach ($this->elementChildren($node) as $child) {
            $span = min($columns, $this->gridSpan($this->decl($child)['grid-column'] ?? ''));
            if ($used + $span > $columns) {
                $placed[] = $row;
                $row = [];
                $used = 0;
            }
            $row[] = [$child, $span, $used];
            $used += $span;
        }
        if ($row !== []) {
            $placed[] = $row;
        }

        $rows = [];
        $rowCount = count($placed);
        foreach ($placed as $r => $items) {
            $cells = [];
            $end = 0;
            foreach ($items as [$child, $span, $start]) {
                $end = $start + $span;
                $cell = $this->itemCell($child, $vAlign, [
                    'left' => $start > 0 ? $gap / 2 : 0.0,
                    'right' => $end < $columns ? $gap / 2 : 0.0,
                    'top' => $r > 0 ? $rowGap / 2 : 0.0,
                    'bottom' => $r < $rowCount - 1 ? $rowGap / 2 : 0.0,
                ], $span === 1 ? $tracks[$start] ?? null : null);
                $cells[] = new Cell($cell->children, $cell->style, columnSpan: $span);
            }
            if ($end < $columns) {
                // An incomplete last row: the rest of it stays empty.
                $cells[] = new Cell([new Paragraph([])], new CellStyle(paddingTopPt: 0, paddingRightPt: 0, paddingBottomPt: 0, paddingLeftPt: 0), columnSpan: $columns - $end);
            }
            $rows[] = new Row($cells);
        }
        if ($rows === []) {
            return [];
        }

        // Fixed tracks keep their width, `fr` share the percentage of the
        // rest; with only fractions, the columns are their share of 100%.
        $percents = null;
        $frTotal = array_sum(array_map(static fn (array $t): float => $t['fr'] ?? 0.0, $tracks));
        $hasFixed = array_filter($tracks, static fn (array $t): bool => isset($t['pt'])) !== [];
        if ($tracks !== [] && ! $hasFixed) {
            $fixedPercent = array_sum(array_map(static fn (array $t): float => $t['percent'] ?? 0.0, $tracks));
            $percents = array_map(static fn (array $t): ?float => $t['percent'] ?? ($frTotal > 0 && isset($t['fr']) ? $t['fr'] / $frTotal * max(0.0, 100 - $fixedPercent) : null), $tracks);
        }
        if ($percents !== null) {
            foreach ($rows as $ri => $r) {
                $start = 0;
                $cells = [];
                foreach ($r->cells as $cell) {
                    $share = array_sum(array_slice(array_map(static fn (?float $p): float => $p ?? 0.0, $percents), $start, $cell->columnSpan));
                    $cells[] = new Cell($cell->children, $this->styleWithPercent($cell->style, $share > 0 ? $share : null), $cell->columnSpan);
                    $start += $cell->columnSpan;
                }
                $rows[$ri] = new Row($cells);
            }
        }

        return $this->boxed([new Table($rows, new TableStyle(widthPercent: 100, spaceAfterPt: 0, borderCollapse: true))], $decl);
    }

    /**
     * The tracks of `grid-template-columns`: `pt`, `percent` or `fr` each.
     *
     * @return list<array{pt?: float, percent?: float, fr?: float}>
     */
    private function gridTracks(string $css): array
    {
        $css = trim($css);
        // repeat(n, tracks) → the tracks n times.
        $css = (string) preg_replace_callback(
            '/repeat\(\s*(\d+)\s*,\s*((?:[^()]|\([^()]*\))*)\)/i',
            static fn (array $m): string => trim(str_repeat($m[2].' ', (int) $m[1])),
            $css,
        );
        // minmax(min, max) → max (or min when max is a fraction of nothing).
        $css = (string) preg_replace('/minmax\(\s*[^,]+,\s*([^)]+)\)/i', '$1', $css);
        $tracks = [];
        foreach (preg_split('/\s+/', $css) ?: [] as $token) {
            $token = strtolower(trim($token));
            if ($token === '' || $token === 'none') {
                continue;
            }
            if (preg_match('/^([\d.]+)fr$/', $token, $m) === 1) {
                $tracks[] = ['fr' => (float) $m[1]];
            } elseif (preg_match('/^([\d.]+)%$/', $token, $m) === 1) {
                $tracks[] = ['percent' => (float) $m[1]];
            } elseif (($pt = $this->length($token)) !== null) {
                $tracks[] = ['pt' => $pt];
            } else {
                // auto, min-content, max-content: an equal share.
                $tracks[] = ['fr' => 1.0];
            }
        }

        return $tracks;
    }

    /** Columns a grid item spans: `span n`, or `a / b` with line numbers. */
    private function gridSpan(string $css): int
    {
        $css = strtolower(trim($css));
        if (preg_match('/span\s+(\d+)/', $css, $m) === 1) {
            return max(1, (int) $m[1]);
        }
        if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $css, $m) === 1) {
            return max(1, (int) $m[2] - (int) $m[1]);
        }

        return 1;
    }

    /**
     * The lines of a wrapping flex box: children by their declared widths
     * (points, or percentages of the box), as many to a line as fit.
     * Children without a width take a line each when the line already holds
     * one, and share the line otherwise.
     *
     * @param  list<\DOMElement>  $children
     * @param  array<string, string>  $decl
     * @return list<list<\DOMElement>>
     */
    private function wrapLines(array $children, \DOMElement $node, array $decl, float $gap): array
    {
        [, $mr, , $ml] = $this->sides($decl, 'margin');
        [, $pr, , $pl] = $this->sides($decl, 'padding');
        $available = max(1.0, $this->contentWidth() - $ml - $mr - $pl - $pr);
        $lines = [];
        $line = [];
        $used = 0.0;
        foreach ($children as $child) {
            $childDecl = $this->decl($child);
            [$widthPt, $widthPercent] = $this->widthOf($childDecl['width'] ?? ($childDecl['flex-basis'] ?? null));
            $width = $widthPt ?? ($widthPercent !== null ? $available * $widthPercent / 100 : null);
            $needed = ($width ?? $available) + ($line !== [] ? $gap : 0.0);
            if ($line !== [] && $used + $needed > $available + 0.5) {
                $lines[] = $line;
                $line = [];
                $used = 0.0;
                $needed = $width ?? $available;
            }
            $line[] = $child;
            $used += $needed;
        }
        if ($line !== []) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * What is left of a flex line whose items all have a width: [pt, null]
     * or [null, percent]; null when an item has none or nothing is left.
     *
     * @param  list<\DOMElement>  $line
     * @return ?array{0: ?float, 1: ?float}
     */
    private function leftOver(array $line, float $gap): ?array
    {
        $points = 0.0;
        $percent = 0.0;
        foreach ($line as $child) {
            $childDecl = $this->decl($child);
            [$widthPt, $widthPercent] = $this->widthOf($childDecl['width'] ?? ($childDecl['flex-basis'] ?? null));
            if ($widthPt === null && $widthPercent === null) {
                return null;
            }
            $points += $widthPt ?? 0.0;
            $percent += $widthPercent ?? 0.0;
        }
        $points += $gap * (count($line) - 1);
        if ($percent > 0 && $points === $gap * (count($line) - 1)) {
            $left = 100 - $percent - $points / $this->contentWidth() * 100;

            return $left > 0.5 ? [null, $left] : null;
        }
        $left = $this->contentWidth() - $points - $this->contentWidth() * $percent / 100;

        return $left > 1 ? [$left, null] : null;
    }

    /** The width of the page content area: `@page`, or A4 with the default margins. */
    private function contentWidth(): float
    {
        $setup = $this->css->pageSetup() ?? new \Dskripchenko\PhpPdf\Style\PageSetup;

        return $setup->contentWidthPt();
    }

    /**
     * One flex or grid item as a cell: its blocks (or image), its own
     * padding, background and border, plus the gaps around it.
     *
     * @param  array{left: float, right: float, top: float, bottom: float}  $gaps
     * @param  ?array{pt?: float, percent?: float, fr?: float}  $track  The grid column it sits in.
     */
    private function itemCell(\DOMElement $child, VerticalAlignment $vAlign, array $gaps, ?array $track = null): Cell
    {
        $this->enter($child, $this->currentStyle(), true);
        try {
            $childDecl = $this->decl($child);
            $tag = strtolower($child->nodeName);
            if ($tag === 'img' || $tag === 'svg') {
                $image = $tag === 'img' ? $this->parseImage($child, block: true) : $this->parseInlineSvg($child);
                $blocks = $image !== null ? [$image] : [];
            } elseif ($this->hasBlockChild($child)) {
                $blocks = $this->walkBlocks($child);
            } else {
                // The item's box is drawn by the cell; its text goes bare.
                $p = $this->parseParagraph($child);
                $blocks = [new Paragraph($p->children, self::withoutBox($p->style), $p->headingLevel, $p->defaultRunStyle)];
            }
            if ($blocks === []) {
                $blocks = [new Paragraph([])];
            }
            [$pt, $pr, $pb, $pl] = $this->sides($childDecl, 'padding');
            [$widthPt, $widthPercent] = $this->widthOf($childDecl['width'] ?? ($childDecl['flex-basis'] ?? null));
            $widthPt ??= $track['pt'] ?? null;
            $bg = $this->backgroundColor($childDecl);
            $borders = $this->borders($childDecl);
            $hasGap = $gaps['left'] + $gaps['right'] + $gaps['top'] + $gaps['bottom'] > 0;

            if ($hasGap && ($bg !== null || $borders !== null)) {
                // The gap lies outside the item's box: the box is an inner
                // cell, the gap the outer cell's padding.
                $blocks = [new Table(
                    [new Row([new Cell($blocks, new CellStyle(
                        paddingTopPt: $pt, paddingRightPt: $pr, paddingBottomPt: $pb, paddingLeftPt: $pl,
                        verticalAlign: $vAlign, backgroundColor: $bg, borders: $borders,
                    ))])],
                    new TableStyle(widthPercent: 100, spaceAfterPt: 0, borderCollapse: true),
                )];
                [$pt, $pr, $pb, $pl] = [0.0, 0.0, 0.0, 0.0];
                $bg = null;
                $borders = null;
            }

            return new Cell($blocks, new CellStyle(
                widthPt: $widthPt,
                widthPercent: $widthPercent,
                paddingTopPt: $pt + $gaps['top'],
                paddingRightPt: $pr + $gaps['right'],
                paddingBottomPt: $pb + $gaps['bottom'],
                paddingLeftPt: $pl + $gaps['left'],
                verticalAlign: $vAlign,
                backgroundColor: $bg,
                borders: $borders,
            ));
        } finally {
            $this->leave();
        }
    }

    /** A paragraph style without its own box — background, border, padding, outer space. */
    private static function withoutBox(ParagraphStyle $s): ParagraphStyle
    {
        return new ParagraphStyle(
            alignment: $s->alignment,
            indentFirstLinePt: $s->indentFirstLinePt,
            lineHeightMult: $s->lineHeightMult,
            lineHeightPt: $s->lineHeightPt,
            keepWithNext: $s->keepWithNext,
        );
    }

    private function styleWithPercent(CellStyle $style, ?float $percent): CellStyle
    {
        return new CellStyle(
            widthPt: $style->widthPt, widthPercent: $style->widthPercent ?? $percent,
            paddingTopPt: $style->paddingTopPt, paddingRightPt: $style->paddingRightPt,
            paddingBottomPt: $style->paddingBottomPt, paddingLeftPt: $style->paddingLeftPt,
            verticalAlign: $style->verticalAlign, backgroundColor: $style->backgroundColor,
            borders: $style->borders, cornerRadiusPt: $style->cornerRadiusPt, minHeightPt: $style->minHeightPt,
        );
    }

    /**
     * A flex or grid box's own margin and padding around its tables; with a
     * background or border, a one-cell box around them.
     *
     * @param  list<BlockElement>  $blocks
     * @param  array<string, string>  $decl
     * @return list<BlockElement>
     */
    private function boxed(array $blocks, array $decl): array
    {
        if ($blocks === []) {
            return [];
        }
        [$mt, $mr, $mb, $ml] = $this->sides($decl, 'margin');
        [$pt, $pr, $pb, $pl] = $this->sides($decl, 'padding');
        $bg = $this->backgroundColor($decl);
        $borders = $this->borders($decl);
        if ($bg !== null || $borders !== null) {
            return [new Table(
                [new Row([new Cell($blocks, new CellStyle(
                    paddingTopPt: $pt, paddingRightPt: $pr, paddingBottomPt: $pb, paddingLeftPt: $pl,
                    backgroundColor: $bg, borders: $borders,
                ))])],
                new TableStyle(spaceBeforePt: $mt, spaceAfterPt: $mb, borderCollapse: true, indentLeftPt: $ml, indentRightPt: $mr),
            )];
        }
        $left = $ml + $pl;
        $right = $mr + $pr;
        if ($left > 0 || $right > 0) {
            $blocks = array_map(fn (BlockElement $b): BlockElement => $this->indentBy($b, $left, $right), $blocks);
        }
        if ($mt + $pt > 0) {
            $blocks[0] = $this->addSpace($blocks[0], $mt + $pt, 0.0);
        }
        if ($mb + $pb > 0) {
            $last = count($blocks) - 1;
            $blocks[$last] = $this->addSpace($blocks[$last], 0.0, $mb + $pb);
        }

        return $blocks;
    }

    /**
     * `break-inside: avoid` — the blocks in one borderless row that does not
     * split across pages (unless it is taller than a page).
     *
     * @param  list<BlockElement>  $blocks
     */
    private function keptTogether(array $blocks): Table
    {
        if (count($blocks) === 1 && $blocks[0] instanceof Table && count($blocks[0]->rows) === 1) {
            $t = $blocks[0];
            $r = $t->rows[0];

            return new Table([new Row($r->cells, $r->isHeader, $r->heightPt, $r->minHeightPt, cantSplit: true)], $t->style, $t->columnWidthsPt, $t->caption);
        }

        return new Table(
            [new Row([new Cell($blocks, new CellStyle(paddingTopPt: 0, paddingRightPt: 0, paddingBottomPt: 0, paddingLeftPt: 0))], cantSplit: true)],
            new TableStyle(widthPercent: 100, spaceAfterPt: 0, borderCollapse: true),
        );
    }

    private function indentBy(BlockElement $block, float $left, float $right): BlockElement
    {
        return match (true) {
            $block instanceof Paragraph => new Paragraph($block->children, $this->styleCopy($block->style, indentLeft: $block->style->indentLeftPt + $left, indentRight: $block->style->indentRightPt + $right), $block->headingLevel, $block->defaultRunStyle),
            $block instanceof Heading => new Heading($block->level, $block->children, $this->styleCopy($block->style ?? new ParagraphStyle, indentLeft: ($block->style ?? new ParagraphStyle)->indentLeftPt + $left, indentRight: ($block->style ?? new ParagraphStyle)->indentRightPt + $right), $block->anchor),
            $block instanceof Table => new Table($block->rows, $block->style->copy(indentLeftPt: $block->style->indentLeftPt + $left, indentRightPt: $block->style->indentRightPt + $right), $block->columnWidthsPt, $block->caption),
            $block instanceof Image => $this->image($block, indentLeft: $block->indentLeftPt + $left, indentRight: $block->indentRightPt + $right),
            default => $block,
        };
    }

    /** A copy of an image with some of its placement changed. */
    private function image(Image $i, ?float $spaceBefore = null, ?float $spaceAfter = null, ?float $indentLeft = null, ?float $indentRight = null): Image
    {
        return new Image(
            $i->source, $i->widthPt, $i->heightPt, $i->alignment,
            $spaceBefore ?? $i->spaceBeforePt, $spaceAfter ?? $i->spaceAfterPt,
            $i->altText, $i->outOfFlow, $i->widthPercent,
            $indentLeft ?? $i->indentLeftPt, $indentRight ?? $i->indentRightPt,
        );
    }

    private function addSpace(BlockElement $block, float $before, float $after): BlockElement
    {
        return match (true) {
            $block instanceof Paragraph => new Paragraph($block->children, $this->styleCopy($block->style, spaceBefore: $block->style->spaceBeforePt + $before, spaceAfter: $block->style->spaceAfterPt + $after), $block->headingLevel, $block->defaultRunStyle),
            $block instanceof Heading => new Heading($block->level, $block->children, $this->styleCopy($block->style ?? new ParagraphStyle, spaceBefore: ($block->style ?? new ParagraphStyle)->spaceBeforePt + $before, spaceAfter: ($block->style ?? new ParagraphStyle)->spaceAfterPt + $after), $block->anchor),
            $block instanceof Table => new Table($block->rows, $block->style->copy(spaceBeforePt: $block->style->spaceBeforePt + $before, spaceAfterPt: $block->style->spaceAfterPt + $after), $block->columnWidthsPt, $block->caption),
            $block instanceof ListNode => new ListNode($block->items, $block->format, $block->startAt, $block->spaceBeforePt + $before, $block->spaceAfterPt + $after),
            $block instanceof Image => $this->image($block, spaceBefore: $block->spaceBeforePt + $before, spaceAfter: $block->spaceAfterPt + $after),
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
                    $inlines = array_merge($inlines, self::inlineOnly($this->walkInlines($inner)));
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
                    $inlines = array_merge($inlines, self::inlineOnly($this->walkInlines($inner)));
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
        return new Paragraph($this->trimInlines(self::inlineOnly($this->paragraphInlines($node))), style: $this->parseBlockCssStyle($node), defaultRunStyle: $this->currentStyle());
    }

    /**
     * @return list<InlineElement>
     */
    private function paragraphInlines(\DOMElement $node): array
    {
        $inlines = $this->generated($node, 'before');
        foreach ($node->childNodes as $child) {
            $inlines = array_merge($inlines, $this->walkInlines($child));
        }

        return array_merge($inlines, $this->generated($node, 'after'));
    }

    private function parseHeading(\DOMElement $node, int $level): Heading
    {
        $inlines = $this->generated($node, 'before');
        foreach ($node->childNodes as $child) {
            $inlines = array_merge($inlines, self::inlineOnly($this->walkInlines($child)));
        }
        $inlines = array_merge($inlines, $this->generated($node, 'after'));

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
        $listDecl = $this->decl($node);
        $type = strtolower(trim($listDecl['list-style-type'] ?? ''));
        $format = self::listFormat($type)
            ?? ($ordered ? (self::listFormat($node->getAttribute('type')) ?? ListFormat::Decimal) : ListFormat::Bullet);
        $startAt = $ordered && is_numeric($node->getAttribute('start')) ? (int) $node->getAttribute('start') : 1;

        $items = [];
        $number = $startAt - 1;
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement || $this->isHidden($child)) {
                continue;
            }
            if (strtolower($child->nodeName) !== 'li') {
                continue;
            }
            $value = is_numeric($child->getAttribute('value')) ? (int) $child->getAttribute('value') : null;
            $number = $value ?? $number + 1;
            $marker = $this->listMarker($child, $number, $type === 'none');
            $this->enter($child, $this->currentStyle(), true);
            // The numbers of the enclosing items, for counters(list-item).
            $this->listNumbers[] = $number;
            try {
                $item = $this->parseListItem($child);
            } finally {
                array_pop($this->listNumbers);
                $this->leave();
            }
            $itemIndent = isset($this->decl($child)['margin-left']) ? $this->sides($this->decl($child), 'margin')[3] : 0.0;
            $items[] = new ListItem($item->children, $item->nestedList, $marker, $value, $itemIndent);
        }

        // A list that says where its items stand (padding, margin) is placed
        // there; otherwise by the usual indent per level.
        $indent = isset($listDecl['padding-left']) || isset($listDecl['margin-left'])
            ? $this->sides($listDecl, 'padding')[3] + $this->sides($listDecl, 'margin')[3]
            : null;

        return new ListNode(
            items: $items,
            format: $format,
            startAt: $startAt,
            indentPt: $indent,
        );
    }

    /** A CSS list-style-type or an HTML `type` as a format; null for others. */
    private static function listFormat(string $type): ?ListFormat
    {
        return match ($type) {
            'decimal', '1' => ListFormat::Decimal,
            'lower-alpha', 'lower-latin', 'a' => ListFormat::LowerLetter,
            'upper-alpha', 'upper-latin', 'A' => ListFormat::UpperLetter,
            'lower-roman', 'i' => ListFormat::LowerRoman,
            'upper-roman', 'I' => ListFormat::UpperRoman,
            'disc', 'circle', 'square' => ListFormat::Bullet,
            default => null,
        };
    }

    /**
     * An item's label when it is not the list's plain one: '' for
     * `list-style-type: none`, the text of a `::marker` rule's content —
     * strings, `counter(list-item)`, `counters(list-item, ".")` — else
     * null.
     */
    private function listMarker(\DOMElement $li, int $number, bool $listHasNone): ?string
    {
        $own = strtolower(trim($this->decl($li)['list-style-type'] ?? ''));
        if ($own === 'none' || ($own === '' && $listHasNone)) {
            return '';
        }
        $content = trim($this->css->pseudoDeclarationsFor($li, 'marker')['content'] ?? '');
        if ($content === '' || in_array(strtolower($content), ['normal', 'none'], true)) {
            return strtolower($content) === 'none' ? '' : null;
        }

        $text = '';
        $rest = $content;
        $styleOf = static fn (?string $style): ListFormat => self::listFormat(strtolower(trim((string) $style))) ?? ListFormat::Decimal;
        while ($rest !== '') {
            if (preg_match('/^(["\'])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*/', $rest, $m) === 1) {
                $text .= stripcslashes($m[2]);
            } elseif (preg_match('/^counters\(\s*list-item\s*,\s*(["\'])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*(?:,\s*([\w-]+)\s*)?\)\s*/', $rest, $m) === 1) {
                $format = $styleOf($m[3] ?? null);
                $text .= implode(stripcslashes($m[2]), array_map(static fn (int $n): string => $format->number($n), [...$this->listNumbers, $number]));
            } elseif (preg_match('/^counter\(\s*list-item\s*(?:,\s*([\w-]+)\s*)?\)\s*/', $rest, $m) === 1) {
                $text .= $styleOf($m[1] ?? null)->number($number);
            } else {
                return null; // a content the parser cannot say: the list's own label
            }
            $rest = substr($rest, strlen($m[0]));
        }

        return $text;
    }

    private function parseListItem(\DOMElement $node): ListItem
    {
        // Separate inline content from nested list.
        $blocks = [];
        $pendingInlines = $this->generated($node, 'before');
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
        $pendingInlines = array_merge($pendingInlines, $this->generated($node, 'after'));
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
                    $inlines = array_merge($inlines, self::inlineOnly($this->walkInlines($inner)));
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
        if (($handled = $this->handled($node, inline: true)) !== null) {
            $out = [];
            $blocks = [];
            foreach ($handled as $element) {
                if ($element instanceof InlineElement) {
                    if ($blocks !== []) {
                        $out[] = new FlowBlocks($blocks);
                        $blocks = [];
                    }
                    $out[] = $element;
                } else {
                    $blocks[] = $element;
                }
            }
            if ($blocks !== []) {
                $out[] = new FlowBlocks($blocks);
            }

            return $out;
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
        // An <svg> in a line of text is drawn as a block of its own: the
        // paragraph splits around it, as around a handler's block.
        if ($tag === 'svg') {
            $svg = $this->parseInlineSvg($node);

            return $svg !== null ? [new FlowBlocks([$svg])] : [];
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
            $result = $this->generated($node, 'before');
            foreach ($node->childNodes as $child) {
                $result = array_merge($result, $this->walkInlines($child));
            }
            $result = array_merge($result, $this->generated($node, 'after'));
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
            $fontFamily = self::fontFamilies($attrFace);
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
                $result = $this->generated($node, 'before');
                foreach ($node->childNodes as $child) {
                    $result = array_merge($result, $this->walkInlines($child));
                }

                return array_merge($result, $this->generated($node, 'after'));
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
                $children = array_merge($children, self::inlineOnly($this->walkInlines($child)));
            }
            if ($children === []) {
                $children = [new Run($href, $this->currentStyle())];
            }
            $children = array_merge($this->generated($node, 'before'), $children, $this->generated($node, 'after'));
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
        // CSS width/height win over the attributes.
        $decl = $this->decl($node);
        $css = static fn (string $v): string => str_contains($v, '%') || strtolower(trim($v)) === 'auto' ? '' : $v;
        $widthPt = $this->parseSvgDimension($css($decl['width'] ?? '')) ?? $this->parseSvgDimension($node->getAttribute('width')) ?? 100.0;
        $heightPt = $this->parseSvgDimension($css($decl['height'] ?? '')) ?? $this->parseSvgDimension($node->getAttribute('height')) ?? 100.0;

        try {
            return new \Dskripchenko\PhpPdf\Element\SvgElement(
                svgXml: $svgXml,
                widthPt: $widthPt,
                heightPt: $heightPt,
                // The text-align around it, as for an image.
                alignment: $this->block()['align'],
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
            $bytes = $this->imageSource !== null ? ($this->imageSource)($src) : null;
            // Data URL: data:image/png;base64,...
            if ($bytes !== null && $bytes !== '') {
                $pdfImg = PdfImage::fromBytes($bytes);
            } elseif (str_starts_with($src, 'data:')) {
                $pdfImg = $this->parseDataUrl($src);
            } elseif ($this->localFiles && is_readable($src)) {
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
    /**
     * @param  ?array<string, string>  $decl  Declarations to apply instead of the element's own (a pseudo-element's).
     */
    private function applyInlineCssStyle(RunStyle $current, \DOMElement $node, bool $isBlock = false, ?array $decl = null): RunStyle
    {
        $decl ??= $this->decl($node);
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
            $fontFamily = self::fontFamilies($decl['font-family']);
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

    /**
     * The text a `::before` or `::after` rule generates for an element, as a
     * run in its style; nothing without a `content` that yields text.
     *
     * @param  'before'|'after'  $which
     * @return list<InlineElement>
     */
    private function generated(\DOMElement $node, string $which): array
    {
        if ($node->getAttribute('id') === '_phppdf_root') {
            return [];
        }
        $decl = $this->css->pseudoDeclarationsFor($node, $which);
        if (! isset($decl['content']) || strtolower(trim($decl['display'] ?? '')) === 'none') {
            return [];
        }
        $pieces = self::contentPieces($decl['content'], $node);
        if ($pieces === []) {
            return [];
        }
        $style = $this->applyInlineCssStyle($this->currentStyle(), $node, false, $decl);
        $out = [];
        foreach ($pieces as $piece) {
            $out[] = match ($piece) {
                self::PAGE_COUNTER => Field::page($style),
                self::PAGES_COUNTER => Field::totalPages($style),
                default => new Run($piece, $style),
            };
        }

        return $out;
    }

    /**
     * A CSS `content` value as text pieces and page counters: strings (with
     * CSS escapes), `attr(name)`, `open-quote` / `close-quote`, and the
     * paged-media counters `counter(page)` and `counter(pages)`, which become
     * page-number fields. `none`, `normal` and other counters give nothing.
     *
     * @return list<string>  Text, or {@see self::PAGE_COUNTER} / {@see self::PAGES_COUNTER}.
     */
    private static function contentPieces(string $value, \DOMElement $node): array
    {
        $value = trim($value);
        if (in_array(strtolower($value), ['none', 'normal', ''], true)) {
            return [];
        }
        $pieces = [];
        $text = '';
        $offset = 0;
        $pattern = '/\G\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|attr\(\s*([\w-]+)\s*\)|(open-quote|close-quote|no-open-quote|no-close-quote)|counters?\(\s*([\w-]+)[^)]*\)|[a-z-]+\([^)]*\))/i';
        while ($offset < strlen($value) && preg_match($pattern, $value, $m, 0, $offset) === 1) {
            $offset += strlen($m[0]);
            if (($m[1] ?? '') !== '' || (isset($m[1]) && str_starts_with(ltrim($m[0]), '"'))) {
                $text .= self::unescape($m[1]);
            } elseif (($m[2] ?? '') !== '' || (isset($m[2]) && str_starts_with(ltrim($m[0]), "'"))) {
                $text .= self::unescape($m[2]);
            } elseif (($m[3] ?? '') !== '') {
                $text .= $node->getAttribute($m[3]);
            } elseif (($m[4] ?? '') !== '') {
                $text .= match (strtolower($m[4])) {
                    'open-quote' => "\u{201C}",
                    'close-quote' => "\u{201D}",
                    default => '',
                };
            } elseif (in_array(strtolower($m[5] ?? ''), ['page', 'pages'], true)) {
                if ($text !== '') {
                    $pieces[] = $text;
                    $text = '';
                }
                $pieces[] = strtolower($m[5]) === 'page' ? self::PAGE_COUNTER : self::PAGES_COUNTER;
            }
        }
        if ($text !== '') {
            $pieces[] = $text;
        }

        return $pieces;
    }

    /** CSS string escapes: `\201C`, `\"`, `\A`. */
    private static function unescape(string $text): string
    {
        return (string) preg_replace_callback(
            '/\\\\([0-9a-f]{1,6})\s?|\\\\(.)/i',
            static function (array $m): string {
                if ($m[1] !== '') {
                    $cp = (int) hexdec($m[1]);

                    return $cp === 0x0A ? "\n" : (mb_chr($cp, 'UTF-8') ?: '');
                }

                return $m[2];
            },
            $text,
        );
    }

    /**
     * A `font-family` list as php-pdf keeps it: the families in order,
     * unquoted, comma-separated — the engine takes the first it can use.
     */
    private static function fontFamilies(string $css): ?string
    {
        $families = array_values(array_filter(array_map(
            static fn (string $f): string => trim($f, " \t\n\"'"),
            explode(',', $css),
        ), static fn (string $f): bool => $f !== ''));

        return $families === [] ? null : implode(', ', $families);
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
