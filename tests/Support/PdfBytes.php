<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Support;

/**
 * A PDF without what changes from one render to the next — the creation
 * and modification dates (Info and XMP) and the file ID derived from them —
 * for tests that compare two renders. Two renders a second apart differ
 * there alone: an equality check fails at random, an inequality check
 * passes for no reason.
 */
final class PdfBytes
{
    public static function stable(string $pdf): string
    {
        return (string) preg_replace(
            [
                '/\/(CreationDate|ModDate) \((?:[^()\\\\]|\\\\.)*\)/',
                '/\/ID \[<[0-9a-fA-F]*> ?<[0-9a-fA-F]*>\]/',
                '/<(xmp:CreateDate|xmp:ModifyDate|xmp:MetadataDate)>[^<]*<\/\1>/',
            ],
            ['/$1 (D:0)', '/ID [<0> <0>]', '<$1>0</$1>'],
            $pdf,
        );
    }
}
