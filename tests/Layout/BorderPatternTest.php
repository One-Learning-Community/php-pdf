<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Layout;

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Border;
use Dskripchenko\PhpPdf\Style\BorderSet;
use Dskripchenko\PhpPdf\Style\BorderStyle;
use Dskripchenko\PhpPdf\Style\ParagraphStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dashed and dotted borders are drawn as patterns, not as solid lines.
 */
final class BorderPatternTest extends TestCase
{
    private static function framed(BorderStyle $style, float $sizeEighths = 8): string
    {
        $border = new Border($style, (int) $sizeEighths, 'ff0000');
        $paragraph = new Paragraph([new Run('Framed')], new ParagraphStyle(
            borders: new BorderSet(top: $border, bottom: $border, left: $border, right: $border),
            paddingTopPt: 4, paddingRightPt: 4, paddingBottomPt: 4, paddingLeftPt: 4,
        ));

        return (new Document(new Section([$paragraph])))->toBytes(new Engine(compressStreams: false));
    }

    /**
     * @return list<array{pattern: list<float>, cap: int, length: float}>
     */
    private static function patternedLines(string $pdf): array
    {
        preg_match_all(
            '/1 0 0 RG\n\[([\d. ]*)\] ([\d.]+) d\n(\d) J\n([\d.-]+) ([\d.-]+) m\n([\d.-]+) ([\d.-]+) l\nS/',
            $pdf,
            $all,
            PREG_SET_ORDER,
        );

        return array_map(static fn (array $m): array => [
            'pattern' => array_map('floatval', explode(' ', $m[1])),
            'cap' => (int) $m[3],
            'length' => hypot((float) $m[6] - (float) $m[4], (float) $m[7] - (float) $m[5]),
        ], $all);
    }

    #[Test]
    public function a_dashed_border_is_drawn_with_dashes_that_end_on_the_corners(): void
    {
        $lines = self::patternedLines(self::framed(BorderStyle::Dashed));

        self::assertCount(4, $lines);
        foreach ($lines as $line) {
            [$dash, $gap] = $line['pattern'];
            self::assertEqualsWithDelta(3.0, $dash, 0.01, 'A dash is three line widths long');
            self::assertGreaterThan(0, $gap);
            self::assertSame(0, $line['cap']);
            // A whole number of dash+gap periods plus a closing dash.
            $periods = ($line['length'] - $dash) / ($dash + $gap);
            self::assertEqualsWithDelta(round($periods), $periods, 0.01);
        }
    }

    #[Test]
    public function a_dotted_border_is_drawn_with_round_dots(): void
    {
        $lines = self::patternedLines(self::framed(BorderStyle::Dotted));

        self::assertCount(4, $lines);
        foreach ($lines as $line) {
            [$on, $off] = $line['pattern'];
            self::assertSame(0.0, $on);
            self::assertSame(1, $line['cap']);
            // Dots two widths apart, with one on each end of the line.
            self::assertEqualsWithDelta(2.0, $off, 0.5);
            $steps = $line['length'] / $off;
            self::assertEqualsWithDelta(round($steps), $steps, 0.01);
        }
    }

    #[Test]
    public function a_solid_border_has_no_dash_pattern(): void
    {
        $pdf = self::framed(BorderStyle::Single);

        self::assertSame([], self::patternedLines($pdf));
        self::assertStringNotContainsString(' d'."\n", $pdf);
    }
}
