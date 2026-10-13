<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

use Dskripchenko\PhpPdf\Barcode\QrEncoder;
use Dskripchenko\PhpPdf\Pdf\Forms\Appearance\WinAnsi;
use Dskripchenko\PhpPdf\Pdf\PdfExtGState;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\VerticalAlignment;

/**
 * Draws a {@see SignatureAppearance} into content-stream operators for a
 * stamp of the given size, origin at its lower-left corner.
 *
 * @internal
 */
final class AppearanceRenderer
{
    /** Font size decrement while shrinking text to fit. */
    private const SHRINK_STEP_PT = 0.25;

    /** Rough ascent share of the font size, for placing the first baseline. */
    private const ASCENT = 0.78;

    public function __construct(
        private readonly AppearanceResources $resources,
        private readonly ?PdfFont $fallbackFont = null,
        private readonly ?PdfFont $fallbackBoldFont = null,
        private readonly bool $requireEmbeddedFonts = false,
    ) {}

    /**
     * @param  array<string, string>  $values  Placeholder values, without braces.
     */
    public function render(SignatureAppearance $appearance, array $values, float $width, float $height): string
    {
        $values = [...$values, ...$appearance->variables];
        $ops = "q\n";

        $bw = $appearance->borderColor !== null ? $appearance->borderWidthPt : 0.0;
        $inset = $bw / 2;
        $frame = $this->framePath($inset, $inset, $width - $bw, $height - $bw, $appearance->cornerRadiusPt);
        if ($appearance->backgroundColor !== null) {
            $ops .= $this->fillColor($appearance->backgroundColor).$frame."f\n";
        }
        if ($appearance->borderColor !== null && $bw > 0.0) {
            $ops .= $this->strokeColor($appearance->borderColor)
                .sprintf("%s w\n", self::num($bw)).$frame."S\n";
        }

        $pad = $bw + $appearance->paddingPt;
        $areaW = $width - 2 * $pad;
        $areaH = $height - 2 * $pad;
        if ($areaW > 0.0 && $areaH > 0.0) {
            foreach ($appearance->blocks as $block) {
                [$x, $y, $w, $h] = $block->box()->resolve($pad, $pad, $areaW, $areaH);
                $body = match (true) {
                    $block instanceof TextBlock => $this->text($block, $appearance, $values, $x, $y, $w, $h),
                    $block instanceof ImageBlock => $this->image($block, $x, $y, $w, $h),
                    $block instanceof QrBlock => $this->qr($block, $values, $x, $y, $w, $h),
                    default => throw new \LogicException('Unknown appearance block '.$block::class),
                };
                if ($body !== '') {
                    $ops .= sprintf("q\n%s %s %s %s re W n\n", self::num($x), self::num($y), self::num($w), self::num($h))
                        .$body."Q\n";
                }
            }
        }

        return $ops."Q\n";
    }

    /**
     * Fills `{name}` placeholders. Returns null for a line that had
     * placeholders and all of them came out empty — such a line is dropped.
     *
     * @param  array<string, string>  $values
     */
    public static function substitute(string $template, array $values): ?string
    {
        $seen = 0;
        $filled = 0;
        $result = (string) preg_replace_callback(
            '/\{([A-Za-z][A-Za-z0-9_]*)\}/',
            static function (array $m) use ($values, &$seen, &$filled): string {
                $seen++;
                $value = $values[$m[1]] ?? '';
                if ($value !== '') {
                    $filled++;
                }

                return $value;
            },
            $template,
        );

        return $seen > 0 && $filled === 0 ? null : $result;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function text(
        TextBlock $block,
        SignatureAppearance $appearance,
        array $values,
        float $x,
        float $y,
        float $w,
        float $h,
    ): string {
        $lines = [];
        foreach ($block->lines as $template) {
            $line = self::substitute($template, $values);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            return '';
        }

        $face = $this->face($appearance, $block->bold, implode('', $lines));

        // Shrink until the wrapped text fits; at the minimum size it is
        // drawn anyway and clipped by the box.
        $size = $block->sizePt;
        while (true) {
            $wrapped = [];
            foreach ($lines as $line) {
                array_push($wrapped, ...$this->wrap($line, $face, $size, $w));
            }
            $blockHeight = (count($wrapped) - 1) * $size * $block->lineHeight + $size;
            $widest = 0.0;
            foreach ($wrapped as $line) {
                $widest = max($widest, $face->width($line, $size));
            }
            if (($blockHeight <= $h && $widest <= $w + 1e-6) || $size <= $block->minSizePt) {
                break;
            }
            $size = max($block->minSizePt, $size - self::SHRINK_STEP_PT);
        }

        $top = match ($block->verticalAlign) {
            VerticalAlignment::Center => $y + ($h + $blockHeight) / 2,
            VerticalAlignment::Bottom => $y + $blockHeight,
            default => $y + $h,
        };
        // The first baseline sits an ascent below the top, keeping capitals
        // inside the box.
        $baseline = $top - $size * self::ASCENT;

        $ops = $this->fillColor($block->color);
        if ($face->fauxBold) {
            $ops .= $this->strokeColor($block->color).sprintf("2 Tr %s w\n", self::num($size * 0.035));
        }
        foreach ($wrapped as $i => $line) {
            if ($line === '') {
                continue;
            }
            $lineWidth = $face->width($line, $size);
            $lx = match ($block->align) {
                Alignment::Center => $x + ($w - $lineWidth) / 2,
                Alignment::End => $x + $w - $lineWidth,
                default => $x,
            };
            $ly = $baseline - $i * $size * $block->lineHeight;
            $ops .= sprintf(
                "BT\n/%s %s Tf\n%s %s Td\n%s Tj\nET\n",
                $face->resource, self::num($size), self::num($lx), self::num($ly), $face->encode($line),
            );
        }

        return $ops;
    }

    /**
     * Breaks a line at spaces so each piece fits `$maxWidth`; a single word
     * wider than that is broken between characters.
     *
     * @return list<string>
     */
    private function wrap(string $line, AppearanceFace $face, float $size, float $maxWidth): array
    {
        if ($face->width($line, $size) <= $maxWidth) {
            return [$line];
        }
        $out = [];
        $current = '';
        foreach (explode(' ', $line) as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($face->width($candidate, $size) <= $maxWidth) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $out[] = $current;
                $current = '';
            }
            // The word alone: split it if it still does not fit.
            foreach (mb_str_split($word, 1, 'UTF-8') as $ch) {
                if ($current !== '' && $face->width($current.$ch, $size) > $maxWidth) {
                    $out[] = $current;
                    $current = '';
                }
                $current .= $ch;
            }
        }
        if ($current !== '') {
            $out[] = $current;
        }

        return $out;
    }

    private function face(SignatureAppearance $appearance, bool $bold, string $sample): AppearanceFace
    {
        $regular = $appearance->font ?? $this->fallbackFont;
        $boldFont = $appearance->font !== null ? $appearance->boldFont : ($appearance->boldFont ?? $this->fallbackBoldFont);

        $font = $bold ? ($boldFont ?? $regular) : $regular;
        if ($font !== null) {
            return AppearanceFace::embedded(
                $font,
                $this->resources->fontResource($font),
                fauxBold: $bold && $boldFont === null,
            );
        }

        if ($this->requireEmbeddedFonts) {
            throw new \LogicException('Signature stamp text needs an embedded font here (PDF/A): set SignatureAppearance::$font');
        }
        if (WinAnsi::encode($sample) === null) {
            throw new \LogicException(
                'Signature stamp text has characters outside WinAnsi (Latin): set SignatureAppearance::$font to an embedded TTF font',
            );
        }
        $standard = $bold ? StandardFont::HelveticaBold : StandardFont::Helvetica;

        return AppearanceFace::standard($standard, $this->resources->standardFontResource($standard));
    }

    private function image(ImageBlock $block, float $x, float $y, float $w, float $h): string
    {
        $img = $block->image;
        $scale = min($w / $img->widthPx, $h / $img->heightPx);
        $iw = $img->widthPx * $scale;
        $ih = $img->heightPx * $scale;
        $ix = $x + ($w - $iw) / 2;
        $iy = $y + ($h - $ih) / 2;

        $ops = '';
        if ($block->opacity < 1.0) {
            $gs = $this->resources->extGStateResource(new PdfExtGState(fillOpacity: $block->opacity));
            $ops .= "/$gs gs\n";
        }

        return $ops.sprintf(
            "%s 0 0 %s %s %s cm\n/%s Do\n",
            self::num($iw), self::num($ih), self::num($ix), self::num($iy),
            $this->resources->imageResource($img),
        );
    }

    /**
     * @param  array<string, string>  $values
     */
    private function qr(QrBlock $block, array $values, float $x, float $y, float $w, float $h): string
    {
        $data = self::substitute($block->data, $values);
        if ($data === null || $data === '') {
            return '';
        }
        $enc = new QrEncoder($data, $block->eccLevel);
        $n = $enc->size();
        $matrix = $enc->modules();
        $side = min($w, $h);
        $module = $side / ($n + 2 * $block->quietZoneModules);
        $qx = $x + ($w - $side) / 2;
        $qy = $y + ($h - $side) / 2;

        $ops = "1 1 1 rg\n".sprintf("%s %s %s %s re f\n", self::num($qx), self::num($qy), self::num($side), self::num($side));
        $ops .= $this->fillColor($block->color);
        $origin = $block->quietZoneModules * $module;
        for ($row = 0; $row < $n; $row++) {
            $ry = $qy + $side - $origin - ($row + 1) * $module;
            $run = null;
            for ($col = 0; $col <= $n; $col++) {
                $dark = $col < $n && $matrix[$row][$col];
                if ($dark && $run === null) {
                    $run = $col;
                } elseif (! $dark && $run !== null) {
                    $ops .= sprintf(
                        "%s %s %s %s re\n",
                        self::num($qx + $origin + $run * $module), self::num($ry),
                        self::num(($col - $run) * $module), self::num($module),
                    );
                    $run = null;
                }
            }
        }

        return $ops."f\n";
    }

    /** A closed rectangle path, with rounded corners when `$r` > 0. */
    private function framePath(float $x, float $y, float $w, float $h, float $r): string
    {
        $r = max(0.0, min($r, $w / 2, $h / 2));
        if ($r === 0.0) {
            return sprintf("%s %s %s %s re\n", self::num($x), self::num($y), self::num($w), self::num($h));
        }
        $k = $r * 0.55228474983;
        $x2 = $x + $w;
        $y2 = $y + $h;
        $p = static fn (float ...$v): string => implode(' ', array_map([self::class, 'num'], $v));

        return $p($x + $r, $y)." m\n"
            .$p($x2 - $r, $y)." l\n"
            .$p($x2 - $r + $k, $y, $x2, $y + $r - $k, $x2, $y + $r)." c\n"
            .$p($x2, $y2 - $r)." l\n"
            .$p($x2, $y2 - $r + $k, $x2 - $r + $k, $y2, $x2 - $r, $y2)." c\n"
            .$p($x + $r, $y2)." l\n"
            .$p($x + $r - $k, $y2, $x, $y2 - $r + $k, $x, $y2 - $r)." c\n"
            .$p($x, $y + $r)." l\n"
            .$p($x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y)." c\n"
            ."h\n";
    }

    private function fillColor(string $hex): string
    {
        [$r, $g, $b] = Color::parse($hex);

        return sprintf("%s %s %s rg\n", self::num($r), self::num($g), self::num($b));
    }

    private function strokeColor(string $hex): string
    {
        [$r, $g, $b] = Color::parse($hex);

        return sprintf("%s %s %s RG\n", self::num($r), self::num($g), self::num($b));
    }

    public static function num(float $v): string
    {
        if (abs($v - round($v)) < 1e-9) {
            return (string) (int) round($v);
        }

        return rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
    }
}
