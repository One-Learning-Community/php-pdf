<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Element;

/**
 * Footnote / endnote inline element.
 *
 * Inserts an auto-numbered superscript marker into the text flow at the
 * footnote position. Content is collected per section and rendered either
 * as an endnotes block at the end of the section body, or at each page
 * bottom when `Section::$footnoteBottomReservedPt` is set.
 *
 * Footnotes are numbered sequentially (1..N) within a section in the
 * order encountered during layout.
 */
final readonly class Footnote implements InlineElement
{
    public function __construct(
        public string $content,
        /**
         * How the note's text is set where it is printed — notes are usually
         * smaller than the body. Null for the engine's default.
         */
        public ?\Dskripchenko\PhpPdf\Style\RunStyle $style = null,
    ) {}
}
