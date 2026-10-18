<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Html;

use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\InlineElement;

/**
 * Lets an application decide what an HTML element becomes — a signature
 * field for `<div data-signature="…">`, a footnote for `<span
 * class="footnote">`, a barcode for a custom tag — instead of the parser's
 * own reading.
 *
 * The parser asks every handler, in order, about each element before it
 * handles the element itself: block-level elements in a block context,
 * the others where text flows. The first non-null answer wins; null leaves
 * the element to the parser.
 *
 * ```php
 * $doc = Document::fromHtml($html, elementHandlers: [new class implements ElementHandler {
 *     public function handle(\DOMElement $element, HtmlContext $context): ?array
 *     {
 *         return $element->hasAttribute('data-signature')
 *             ? [new SignatureField($element->getAttribute('data-signature'))]
 *             : null;
 *     }
 * }]);
 * ```
 */
interface ElementHandler
{
    /**
     * @return list<BlockElement|InlineElement>|null  What the element becomes; null to leave it to the parser.
     *                                                Blocks returned in a text flow (`$context->inline`) split
     *                                                the paragraph around them.
     */
    public function handle(\DOMElement $element, HtmlContext $context): ?array;
}
