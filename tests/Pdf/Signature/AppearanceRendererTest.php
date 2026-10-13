<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\PdfExtGState;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\AppearanceRenderer;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\AppearanceResources;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\Box;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\Color;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\ImageBlock;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\QrBlock;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\TextBlock;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\Alignment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppearanceRendererTest extends TestCase
{
    private const FONT = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

    /** @var list<string> */
    private array $registered = [];

    private function resources(): AppearanceResources
    {
        $log = &$this->registered;

        return new class($log) implements AppearanceResources
        {
            /** @param list<string> $log */
            public function __construct(private array &$log) {}

            public function fontResource(PdfFont $font): string
            {
                $this->log[] = 'font';

                return 'F9';
            }

            public function standardFontResource(StandardFont $font): string
            {
                $this->log[] = 'std:'.$font->value;

                return 'F1';
            }

            public function imageResource(PdfImage $image): string
            {
                $this->log[] = 'image';

                return 'Im1';
            }

            public function extGStateResource(PdfExtGState $state): string
            {
                $this->log[] = 'gs';

                return 'Gs1';
            }
        };
    }

    private function font(): PdfFont
    {
        if (! is_readable(self::FONT)) {
            self::markTestSkipped('Liberation fonts not fetched (scripts/fetch-fonts.sh)');
        }

        return new PdfFont(TtfFile::fromFile(self::FONT));
    }

    #[Test]
    public function placeholders_are_filled_and_empty_lines_dropped(): void
    {
        $values = ['cn' => 'Jane Doe', 'inn' => ''];

        self::assertSame('Owner: Jane Doe', AppearanceRenderer::substitute('Owner: {cn}', $values));
        self::assertNull(AppearanceRenderer::substitute('INN: {inn}', $values));
        self::assertNull(AppearanceRenderer::substitute('{unknown}', $values));
        self::assertSame('INN: , Jane Doe', AppearanceRenderer::substitute('INN: {inn}, {cn}', $values));
        self::assertSame('Plain text', AppearanceRenderer::substitute('Plain text', $values));
    }

    #[Test]
    public function box_resolves_from_the_top_left(): void
    {
        // Right half, lower 40% of a 200 × 100 area at (10, 20).
        [$x, $y, $w, $h] = (new Box(0.5, 0.6, 0.5, 0.4))->resolve(10, 20, 200, 100);

        self::assertEqualsWithDelta(110.0, $x, 1e-9);
        self::assertEqualsWithDelta(20.0, $y, 1e-9);
        self::assertEqualsWithDelta(100.0, $w, 1e-9);
        self::assertEqualsWithDelta(40.0, $h, 1e-9);
    }

    #[Test]
    public function box_must_lie_within_the_stamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Box(0.6, 0.0, 0.5, 1.0);
    }

    #[Test]
    public function hex_colours_parse(): void
    {
        self::assertSame([1.0, 0.0, 0.0], Color::parse('#f00'));
        self::assertEqualsWithDelta([31 / 255, 58 / 255, 147 / 255], Color::parse('#1F3A93'), 1e-9);

        $this->expectException(\InvalidArgumentException::class);
        Color::parse('blue');
    }

    #[Test]
    public function latin_text_falls_back_to_helvetica(): void
    {
        $appearance = new SignatureAppearance([new TextBlock('Signed by {cn}')]);
        $ops = (new AppearanceRenderer($this->resources()))->render($appearance, ['cn' => 'Jane'], 200, 50);

        self::assertSame(['std:Helvetica'], $this->registered);
        // "Signed by Jane" as WinAnsi hex.
        self::assertStringContainsString('<'.strtoupper(bin2hex('Signed by Jane')).'> Tj', $ops);
        // The default 0.5 pt border, inset by half its width.
        self::assertStringContainsString("0.25 0.25 199.5 49.5 re\nS\n", $ops);
    }

    #[Test]
    public function bold_text_without_an_embedded_font_uses_helvetica_bold(): void
    {
        $appearance = new SignatureAppearance([new TextBlock('TITLE', bold: true)]);
        (new AppearanceRenderer($this->resources()))->render($appearance, [], 200, 50);

        self::assertSame(['std:Helvetica-Bold'], $this->registered);
    }

    #[Test]
    public function non_latin_text_without_a_font_is_refused(): void
    {
        $appearance = new SignatureAppearance([new TextBlock('Подписано')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/WinAnsi/');
        (new AppearanceRenderer($this->resources()))->render($appearance, [], 200, 50);
    }

    #[Test]
    public function pdfa_requires_an_embedded_font(): void
    {
        $appearance = new SignatureAppearance([new TextBlock('Signed')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/PDF\/A/');
        (new AppearanceRenderer($this->resources(), requireEmbeddedFonts: true))->render($appearance, [], 200, 50);
    }

    #[Test]
    public function embedded_font_draws_cyrillic(): void
    {
        $font = $this->font();
        $appearance = new SignatureAppearance([new TextBlock('Владелец: {cn}')], font: $font);
        $ops = (new AppearanceRenderer($this->resources()))->render($appearance, ['cn' => 'Иванов'], 200, 50);

        self::assertSame(['font'], $this->registered);
        self::assertStringContainsString('/F9 8 Tf', $ops);
        self::assertStringContainsString($font->encodeText('Владелец: Иванов').' Tj', $ops);
    }

    #[Test]
    public function bold_without_a_bold_font_is_emboldened_by_stroking(): void
    {
        $appearance = new SignatureAppearance([new TextBlock('Title', bold: true)], font: $this->font());
        $ops = (new AppearanceRenderer($this->resources()))->render($appearance, [], 200, 50);

        self::assertMatchesRegularExpression('/2 Tr [0-9.]+ w/', $ops);
    }

    #[Test]
    public function the_document_font_is_used_when_the_stamp_has_none(): void
    {
        $appearance = new SignatureAppearance([new TextBlock('Подпись')]);
        (new AppearanceRenderer($this->resources(), fallbackFont: $this->font()))->render($appearance, [], 200, 50);

        self::assertSame(['font'], $this->registered);
    }

    #[Test]
    public function long_text_is_wrapped_and_shrunk_to_fit(): void
    {
        $long = str_repeat('Limited Liability Company ', 6);
        $appearance = new SignatureAppearance(
            [new TextBlock($long, sizePt: 12, minSizePt: 5)],
            borderColor: null,
            paddingPt: 0,
        );
        $ops = (new AppearanceRenderer($this->resources()))->render($appearance, [], 150, 40);

        preg_match_all('/\/F1 ([0-9.]+) Tf/', $ops, $sizes);
        self::assertGreaterThan(1, count($sizes[1]), 'Text must wrap onto several lines');
        $size = (float) $sizes[1][0];
        self::assertLessThan(12.0, $size);
        self::assertGreaterThanOrEqual(5.0, $size);
        // Every line fits the 150 pt box.
        preg_match_all('/([0-9.]+) [0-9.-]+ Td/', $ops, $xs);
        foreach ($xs[1] as $x) {
            self::assertGreaterThanOrEqual(0.0, (float) $x);
        }
    }

    #[Test]
    public function centred_text_is_offset_by_half_the_slack(): void
    {
        $appearance = new SignatureAppearance(
            [new TextBlock('A', sizePt: 10, align: Alignment::Center)],
            borderColor: null,
            paddingPt: 0,
        );
        $ops = (new AppearanceRenderer($this->resources()))->render($appearance, [], 100, 20);

        // Helvetica "A" is 667/1000 em → 6.67 pt; centred in 100 pt.
        self::assertMatchesRegularExpression('/^46\.665 [0-9.]+ Td$/m', $ops);
    }

    #[Test]
    public function qr_block_draws_modules_on_a_white_ground(): void
    {
        $appearance = new SignatureAppearance([new QrBlock('https://example.com/v?s={serial}')], borderColor: null);
        $ops = (new AppearanceRenderer($this->resources()))->render($appearance, ['serial' => 'AB12'], 60, 60);

        self::assertStringContainsString("1 1 1 rg\n", $ops);
        self::assertGreaterThan(20, substr_count($ops, ' re'));
    }

    #[Test]
    public function qr_block_with_empty_data_draws_nothing(): void
    {
        $appearance = new SignatureAppearance([new QrBlock('{serial}')], borderColor: null);
        $ops = (new AppearanceRenderer($this->resources()))->render($appearance, [], 60, 60);

        self::assertStringNotContainsString(' re', $ops);
    }

    #[Test]
    public function image_is_fitted_keeping_proportions(): void
    {
        $png = PdfImage::fromPath(__DIR__.'/../../fixtures/sample.png');
        $appearance = new SignatureAppearance([new ImageBlock($png, opacity: 0.5)], borderColor: null, paddingPt: 0);
        $ops = (new AppearanceRenderer($this->resources()))->render($appearance, [], 300, 100);

        self::assertContains('image', $this->registered);
        self::assertContains('gs', $this->registered);
        self::assertStringContainsString('/Gs1 gs', $ops);
        self::assertSame(1, preg_match('/([0-9.]+) 0 0 ([0-9.]+) [0-9.]+ [0-9.]+ cm\n\/Im1 Do/', $ops, $m));
        self::assertEqualsWithDelta($png->widthPx / $png->heightPx, (float) $m[1] / (float) $m[2], 1e-3);
        self::assertLessThanOrEqual(300.0, (float) $m[1]);
        self::assertLessThanOrEqual(100.0, (float) $m[2]);
    }

    #[Test]
    public function rounded_frame_with_background(): void
    {
        $appearance = new SignatureAppearance([], borderColor: '#1F3A93', borderWidthPt: 2, cornerRadiusPt: 6, backgroundColor: '#FFFFFF');
        $ops = (new AppearanceRenderer($this->resources()))->render($appearance, [], 200, 60);

        self::assertStringContainsString("h\nf\n", $ops);
        self::assertStringContainsString("h\nS\n", $ops);
        self::assertStringContainsString("2 w\n", $ops);
        self::assertGreaterThanOrEqual(8, substr_count($ops, ' c'));
    }

    #[Test]
    public function presets_build(): void
    {
        $font = $this->font();
        $ru = SignatureAppearance::electronicSignatureStamp('ru', $font);
        $en = SignatureAppearance::electronicSignatureStamp('en');
        $simple = SignatureAppearance::simple();
        $image = SignatureAppearance::imageOnly(PdfImage::fromPath(__DIR__.'/../../fixtures/sample.png'));

        self::assertCount(2, $ru->blocks);
        self::assertSame('d.m.Y', $ru->dateFormat);
        self::assertCount(2, $en->blocks);
        self::assertCount(1, $simple->blocks);
        self::assertNull($image->borderColor);

        $this->expectException(\InvalidArgumentException::class);
        SignatureAppearance::electronicSignatureStamp('de');
    }

    #[Test]
    public function with_helpers_keep_the_rest(): void
    {
        $base = SignatureAppearance::simple(color: '#112233');
        $font = $this->font();
        $changed = $base->withFonts($font)->withVariables(['dept' => 'Legal'])->withBlocks(new QrBlock('x'));

        self::assertSame($font, $changed->font);
        self::assertSame(['dept' => 'Legal'], $changed->variables);
        self::assertCount(2, $changed->blocks);
        self::assertSame('#112233', $changed->borderColor);
    }
}
