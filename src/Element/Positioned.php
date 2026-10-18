<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Blocks placed at a position of their own, out of the flow — CSS
 * `position: absolute` and `fixed`. They take no room: the content around
 * them flows as if they were not there.
 *
 * The offsets are in points from the edges of the page area (the page
 * inside its margins), or — with `inFlow` — from where the element stands
 * in the flow, which is how an element absolutely placed inside a
 * positioned box is approximated. `fixed` repeats it on every page from
 * this one on. Without `widthPt` it spans the area between `leftPt` and
 * `rightPt`.
 */
final readonly class Positioned implements BlockElement
{
    /**
     * @param  list<BlockElement>  $children
     */
    public function __construct(
        public array $children,
        public ?float $topPt = null,
        public ?float $rightPt = null,
        public ?float $bottomPt = null,
        public ?float $leftPt = null,
        public ?float $widthPt = null,
        public bool $fixed = false,
        public bool $inFlow = false,
    ) {}
}
