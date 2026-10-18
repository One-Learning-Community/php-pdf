<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Html;

use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\InlineElement;
use Dskripchenko\PhpPdf\Style\RunStyle;

/**
 * What an {@see ElementHandler} knows about the element it is asked about,
 * and how to have the parser read the element's content.
 */
final class HtmlContext
{
    /**
     * @param  \Closure(\DOMElement): list<InlineElement>  $inlines
     * @param  \Closure(\DOMElement): list<BlockElement>  $blocks
     */
    public function __construct(
        /** The text style at the element: inherited, with its own CSS applied. */
        public readonly RunStyle $style,
        /** True where text flows (inside a paragraph), false between blocks. */
        public readonly bool $inline,
        private readonly \Closure $inlines,
        private readonly \Closure $blocks,
    ) {}

    /**
     * The element's content as the parser reads it in a line of text —
     * styled, with the element's own CSS inherited.
     *
     * @return list<InlineElement>
     */
    public function inlines(\DOMElement $element): array
    {
        return ($this->inlines)($element);
    }

    /**
     * The element's content as the parser reads it as blocks.
     *
     * @return list<BlockElement>
     */
    public function blocks(\DOMElement $element): array
    {
        return ($this->blocks)($element);
    }
}
