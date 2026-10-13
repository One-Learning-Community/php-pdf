<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature;

/**
 * What a certification signature allows after it (DocMDP, ISO 32000-1
 * §12.8.2.2). Only the first signature of a document can certify it.
 */
enum DocMdpPermission: int
{
    /** No changes at all; further signatures invalidate it. */
    case NoChanges = 1;

    /** Filling in forms, instantiating page templates, signing. */
    case FormFilling = 2;

    /** As FormFilling, plus adding, editing and deleting annotations. */
    case FormFillingAndAnnotations = 3;
}
