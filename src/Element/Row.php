<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Table row — ordered list of cells.
 *
 * Header rows (`isHeader: true`) repeat at the top of each page when the
 * table breaks across pages — analogous to HTML `<thead>`. Explicit row
 * height in `heightPt` overrides the auto-fit height of the tallest cell.
 */
final readonly class Row
{
    /**
     * @param  list<Cell>  $cells
     */
    public function __construct(
        public array $cells,
        public bool $isHeader = false,
        public ?float $heightPt = null,
        /** At least this tall (CSS `height` on a row or cell); `heightPt` is exact. */
        public ?float $minHeightPt = null,
        /** Never split across pages (CSS `break-inside: avoid`). */
        public bool $cantSplit = false,
    ) {}
}
