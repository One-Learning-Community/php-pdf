<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Svg;

use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Svg\SvgRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a group passes to its children: gradients it can refer to, its
 * transform composed with theirs, and the inherited fill and stroke.
 *
 * Each SVG is drawn into a 100 x 100 pt box at the page origin, so a user
 * unit is a point and SVG (x, y) lands on PDF (x, 100 - y).
 */
final class SvgGroupsTest extends TestCase
{
    private const GRADIENT = '<defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient></defs>';

    #[Test]
    public function gradient_fills_a_rect_inside_a_group(): void
    {
        $stream = self::stream(self::GRADIENT.'<g><rect width="100" height="100" fill="url(#g)"/></g>');

        self::assertStringContainsString('/Pattern cs', $stream);
        self::assertStringNotContainsString('0 0 0 rg', $stream);
    }

    #[Test]
    public function gradient_fills_a_rect_in_nested_groups(): void
    {
        $stream = self::stream(self::GRADIENT.'<g><g><rect width="100" height="100" fill="url(#g)"/></g></g>');

        self::assertStringContainsString('/Pattern cs', $stream);
    }

    private static function stream(string $body): string
    {
        $page = PdfDocument::new()->addPage(customDimensionsPt: [100, 100]);
        SvgRenderer::render('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'.$body.'</svg>', $page, 0, 0, 100, 100);

        return $page->rawContentStream()->toString();
    }
}
