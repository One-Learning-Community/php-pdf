<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Html;

use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\InlineElement;

/**
 * Blocks an {@see ElementHandler} put in a line of text. The paragraph around
 * them splits there; the marker never leaves the parser.
 *
 * @internal
 */
final readonly class FlowBlocks implements InlineElement
{
    /**
     * @param  list<BlockElement>  $blocks
     */
    public function __construct(public array $blocks) {}
}
