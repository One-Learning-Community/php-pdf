<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Style\Alignment;

/**
 * A digital-signature field placed in the flow — the signature stamp.
 *
 * Takes its room like an image: `widthPt` × `heightPt`, aligned within the
 * line. The field the document is signed into (`SignatureConfig::$fieldName`,
 * else the first) shows the stamp filled with the certificate and signature
 * details; the others stay empty for later signers. Stamp text without a font
 * of its own uses the document's default font.
 *
 * `repeatOnOtherPages` also draws the stamp, as plain non-interactive
 * content, at the same spot on every other page.
 */
final readonly class SignatureField implements BlockElement
{
    public function __construct(
        public string $name,
        public float $widthPt = 250.0,
        public float $heightPt = 75.0,
        public ?SignatureAppearance $appearance = null,
        public Alignment $alignment = Alignment::Start,
        public float $spaceBeforePt = 4.0,
        public float $spaceAfterPt = 4.0,
        public ?string $tooltip = null,
        public bool $repeatOnOtherPages = false,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('SignatureField name must not be empty');
        }
        if ($widthPt <= 0.0 || $heightPt <= 0.0) {
            throw new \InvalidArgumentException('SignatureField needs a positive size');
        }
    }
}
