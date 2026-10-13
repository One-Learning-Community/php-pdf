<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

/**
 * One layer of a signature stamp: {@see TextBlock}, {@see ImageBlock} or
 * {@see QrBlock}. Blocks are drawn in list order, later ones on top.
 */
interface AppearanceBlock
{
    /** Where the block sits inside the stamp. */
    public function box(): Box;
}
