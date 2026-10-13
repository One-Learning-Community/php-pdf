<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\VerticalAlignment;

/**
 * Lines of text in a signature stamp.
 *
 * Lines may hold placeholders in braces — `{cn}`, `{serial}`, `{signedAt}`
 * and the rest listed in {@see SignatureAppearance}. A line whose
 * placeholders all come out empty, and that has no other text, is dropped:
 * "INN: {inn}" disappears for a certificate without an INN.
 *
 * Text that does not fit is wrapped at spaces and shrunk step by step, down
 * to `$minSizePt`; whatever still does not fit is clipped at the box edge.
 */
final readonly class TextBlock implements AppearanceBlock
{
    /** @var list<string> */
    public array $lines;

    /**
     * @param  list<string>|string  $lines  A list, or one string split at "\n".
     * @param  string  $color  Hex colour, `#RGB` or `#RRGGBB`.
     */
    public function __construct(
        array|string $lines,
        public Box $box = new Box,
        public float $sizePt = 8.0,
        public float $minSizePt = 4.0,
        public string $color = '#000000',
        public Alignment $align = Alignment::Start,
        public VerticalAlignment $verticalAlign = VerticalAlignment::Top,
        public bool $bold = false,
        public float $lineHeight = 1.2,
    ) {
        $this->lines = is_string($lines) ? explode("\n", $lines) : array_values($lines);
        if ($sizePt <= 0.0 || $minSizePt <= 0.0 || $minSizePt > $sizePt) {
            throw new \InvalidArgumentException('TextBlock needs 0 < minSizePt <= sizePt');
        }
        if ($lineHeight <= 0.0) {
            throw new \InvalidArgumentException('TextBlock lineHeight must be positive');
        }
        Color::parse($color);
    }

    public function box(): Box
    {
        return $this->box;
    }
}
